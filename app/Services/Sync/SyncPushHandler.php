<?php

namespace App\Services\Sync;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use App\Enums\SyncConflictStatus;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\SyncConflict;
use App\Models\User;
use App\Rules\ContentMatchesTemplateSchema;
use App\Services\Form\SubmissionSyncService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

/**
 * Orchestrates an ordered batch of offline operations for POST /sync/push.
 *
 * Each operation is processed independently (its own transaction inside
 * SubmissionSyncService) so one failing op never poisons its neighbors.
 * Refs by client_uuid resolve against submissions created earlier in the
 * same batch as well as against the database.
 */
class SyncPushHandler
{
    public function __construct(
        private readonly SubmissionSyncService $sync,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Process the batch in order and build per-op results plus uuid mappings.
     *
     * @param  array<int, mixed>  $operations
     * @return array{results: array<int, array<string, mixed>>, mappings: array<string, int>}
     */
    public function handle(User $user, array $operations): array
    {
        $results = [];
        $mappings = [];
        $batch = [];

        foreach (array_values($operations) as $index => $operation) {
            $op = is_array($operation) ? ($operation['op'] ?? null) : null;

            $result = match ($op) {
                'create' => $this->handleCreate($user, $operation, $batch, $mappings),
                'update' => $this->handleUpdate($user, $operation, $batch),
                'submit' => $this->handleSubmit($user, $operation, $batch),
                'approve', 'reject', 'assign' => $this->invalid([
                    'op' => ["The '{$op}' operation is not supported offline."],
                ]),
                default => $this->invalid([
                    'op' => ['Each operation must be an object with an op of create, update, or submit.'],
                ]),
            };

            $results[] = array_merge(
                ['index' => $index, 'op' => is_string($op) ? $op : null],
                $result,
            );
        }

        return ['results' => $results, 'mappings' => $mappings];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, FormSubmission>  $batch
     * @param  array<string, int>  $mappings
     * @return array<string, mixed>
     */
    private function handleCreate(User $user, array $operation, array &$batch, array &$mappings): array
    {
        $contentRules = ['required', 'array'];
        $templateVersion = FormTemplateVersion::find($operation['form_template_version_id'] ?? null);
        if ($templateVersion) {
            $contentRules[] = new ContentMatchesTemplateSchema($templateVersion->json_schema ?? []);
        }

        $validator = Validator::make($operation, [
            'client_uuid' => ['required', 'uuid'],
            'version_client_uuid' => ['nullable', 'uuid'],
            'form_template_id' => ['required', 'exists:form_templates,id'],
            'form_template_version_id' => [
                'required',
                'exists:form_template_versions,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($operation): void {
                    $version = FormTemplateVersion::find($value);
                    if ($version && (int) $version->template_id !== (int) ($operation['form_template_id'] ?? 0)) {
                        $fail('The selected template version does not belong to the given form template.');
                    }
                },
            ],
            'form_name' => ['required', 'string', 'max:255'],
            'content' => $contentRules,
            'priority' => ['nullable', new Enum(SubmissionPriority::class)],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $template = FormTemplate::findOrFail($operation['form_template_id']);

        try {
            Gate::forUser($user)->authorize('create', [FormSubmission::class, $template]);
        } catch (AuthorizationException) {
            return $this->forbidden();
        }

        $result = $this->sync->createSubmission($user, [
            'form_template_id' => $operation['form_template_id'],
            'form_template_version_id' => $operation['form_template_version_id'],
            'form_name' => $operation['form_name'],
            'content' => $operation['content'],
            'priority' => $operation['priority'] ?? null,
            'client_uuid' => $operation['client_uuid'],
            'version_client_uuid' => $operation['version_client_uuid'] ?? null,
        ]);

        $submission = $result->submission->load('currentVersion');

        if ($result->isApplied()) {
            $this->notifications->notifySubmitted($submission);
        }

        $batch[$operation['client_uuid']] = $submission;
        $mappings[$operation['client_uuid']] = $submission->id;

        return $this->written($result->status->value, $submission);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, FormSubmission>  $batch
     * @return array<string, mixed>
     */
    private function handleUpdate(User $user, array $operation, array &$batch): array
    {
        $submission = $this->resolveRef($operation['ref'] ?? null, $batch);
        if (! $submission) {
            return $this->invalid(['ref' => ['Could not resolve the referenced submission.']]);
        }

        $contentRules = ['required', 'array'];
        $templateVersion = $submission->templateVersion;
        if ($templateVersion) {
            $contentRules[] = new ContentMatchesTemplateSchema($templateVersion->json_schema ?? []);
        }

        $validator = Validator::make($operation, [
            'version_client_uuid' => ['required', 'uuid'],
            'base_version_number' => ['required', 'integer', 'min:1'],
            'form_name' => ['required', 'string', 'max:255'],
            'content' => $contentRules,
            'priority' => ['nullable', new Enum(SubmissionPriority::class)],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        if ($submission->fresh()->status === SubmissionStatus::Approved) {
            return $this->forbidden('submission_locked');
        }

        try {
            Gate::forUser($user)->authorize('update', $submission);
        } catch (AuthorizationException) {
            return $this->forbidden();
        }

        $data = [
            'form_name' => $operation['form_name'],
            'content' => $operation['content'],
            'version_number' => $operation['base_version_number'],
            'client_uuid' => $operation['version_client_uuid'],
        ];
        if (array_key_exists('priority', $operation)) {
            $data['priority'] = $operation['priority'];
        }

        $result = $this->sync->updateSubmission($submission, $user, $data);

        if ($result->isConflict()) {
            $current = $result->submission->currentVersion;
            $conflict = $this->persistConflict($user, $result->submission, $operation);

            return [
                'status' => 'conflict',
                'id' => $result->submission->id,
                'client_uuid' => $result->submission->client_uuid,
                'conflict_id' => $conflict->id,
                'current_version' => [
                    'id' => $current->id,
                    'version_number' => $current->version_number,
                    'form_name' => $current->form_name,
                    'content' => $current->content,
                ],
            ];
        }

        return $this->written($result->status->value, $result->submission->load('currentVersion'));
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, FormSubmission>  $batch
     * @return array<string, mixed>
     */
    private function handleSubmit(User $user, array $operation, array &$batch): array
    {
        $submission = $this->resolveRef($operation['ref'] ?? null, $batch);
        if (! $submission) {
            return $this->invalid(['ref' => ['Could not resolve the referenced submission.']]);
        }

        try {
            Gate::forUser($user)->authorize('submit', $submission);
        } catch (AuthorizationException) {
            return $this->forbidden();
        }

        $submission->refresh();

        return match ($submission->status) {
            SubmissionStatus::Draft => $this->applySubmit($submission),
            SubmissionStatus::PendingApproval,
            SubmissionStatus::Approved => $this->written('duplicate', $submission->load('currentVersion')),
            SubmissionStatus::Rejected => $this->invalid([
                'status' => ['A rejected submission cannot be re-submitted for approval.'],
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function applySubmit(FormSubmission $submission): array
    {
        $submission->update(['status' => SubmissionStatus::PendingApproval]);

        return $this->written('applied', $submission->load('currentVersion'));
    }

    /**
     * Persist the losing payload as an open SyncConflict, reusing an identical
     * open conflict for the same submission, user, and base version so a
     * replayed conflicting op stays idempotent.
     *
     * @param  array<string, mixed>  $operation
     */
    private function persistConflict(User $user, FormSubmission $submission, array $operation): SyncConflict
    {
        $existing = SyncConflict::query()
            ->where('form_submission_id', $submission->id)
            ->where('user_id', $user->id)
            ->where('base_version_number', $operation['base_version_number'])
            ->where('status', SyncConflictStatus::Open)
            ->get()
            ->first(fn (SyncConflict $conflict): bool => $conflict->submitted_form_name === $operation['form_name']
                && $conflict->submitted_content == $operation['content']);

        if ($existing) {
            return $existing;
        }

        $conflict = SyncConflict::create([
            'form_submission_id' => $submission->id,
            'user_id' => $user->id,
            'base_version_number' => $operation['base_version_number'],
            'submitted_form_name' => $operation['form_name'],
            'submitted_content' => $operation['content'],
            'status' => SyncConflictStatus::Open,
        ]);

        $this->notifications->notifyConflict($submission, $user);

        return $conflict;
    }

    /**
     * Resolve a {client_uuid} or {id} ref against the current batch, then the database.
     *
     * @param  array<string, FormSubmission>  $batch
     */
    private function resolveRef(mixed $ref, array $batch): ?FormSubmission
    {
        if (! is_array($ref)) {
            return null;
        }

        if (! empty($ref['client_uuid']) && is_string($ref['client_uuid'])) {
            return $batch[$ref['client_uuid']]
                ?? FormSubmission::query()->where('client_uuid', $ref['client_uuid'])->first();
        }

        if (! empty($ref['id']) && (is_int($ref['id']) || ctype_digit((string) $ref['id']))) {
            return FormSubmission::find($ref['id']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function written(string $status, FormSubmission $submission): array
    {
        return [
            'status' => $status,
            'id' => $submission->id,
            'client_uuid' => $submission->client_uuid,
            'version_number' => $submission->currentVersion?->version_number,
            'submission_status' => $submission->status?->value,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, mixed>
     */
    private function invalid(array $errors): array
    {
        return ['status' => 'invalid', 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function forbidden(string $error = 'forbidden'): array
    {
        return ['status' => 'forbidden', 'error' => $error];
    }
}
