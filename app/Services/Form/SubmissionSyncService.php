<?php

namespace App\Services\Form;

use App\Models\FormSubmission;
use App\Models\FormSubmissionVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transactional write path for form submissions, shared by the online CRUD
 * endpoints and the offline sync push endpoint.
 *
 * Invariants preserved here:
 *  - versions are append-only, never mutated
 *  - current_version_id advances atomically in the same transaction
 *  - optimistic locking on version_number under a pessimistic row lock
 *  - client_uuid makes both creates and version writes replay-safe
 */
class SubmissionSyncService
{
    /**
     * Create a submission with its initial version.
     *
     * @param  array{form_template_id: int, form_template_version_id: int, form_name: string, content: array<string, mixed>, priority?: string|null, client_uuid?: string|null, version_client_uuid?: string|null}  $data
     */
    public function createSubmission(User $user, array $data): SyncWriteResult
    {
        if (! empty($data['client_uuid'])) {
            $existing = FormSubmission::query()->where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return SyncWriteResult::duplicate($existing);
            }
        }

        try {
            return DB::transaction(function () use ($user, $data) {
                $submission = FormSubmission::create([
                    'form_template_id' => $data['form_template_id'],
                    'form_template_version_id' => $data['form_template_version_id'],
                    'created_by' => $user->id,
                    'priority' => $data['priority'] ?? null,
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);

                $version = $submission->versions()->create([
                    'user_id' => $user->id,
                    'form_name' => $data['form_name'],
                    'content' => $data['content'],
                    'version_number' => 1,
                    'client_uuid' => $data['version_client_uuid'] ?? null,
                ]);

                $submission->update(['current_version_id' => $version->id]);

                return SyncWriteResult::applied($submission);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = ! empty($data['client_uuid'])
                ? FormSubmission::query()->where('client_uuid', $data['client_uuid'])->first()
                : null;

            if ($existing) {
                return SyncWriteResult::duplicate($existing);
            }

            throw $e;
        }
    }

    /**
     * Append a new version to a submission under optimistic + pessimistic locking.
     *
     * @param  array{form_name: string, content: array<string, mixed>, version_number: int, priority?: string|null, client_uuid?: string|null}  $data
     */
    public function updateSubmission(FormSubmission $submission, User $user, array $data): SyncWriteResult
    {
        if (! empty($data['client_uuid'])) {
            $existingVersion = FormSubmissionVersion::query()
                ->where('client_uuid', $data['client_uuid'])
                ->where('submission_id', $submission->id)
                ->first();

            if ($existingVersion) {
                return SyncWriteResult::duplicate($submission->fresh(['currentVersion.user']));
            }
        }

        try {
            return DB::transaction(function () use ($submission, $user, $data) {
                $lockedSubmission = FormSubmission::with('currentVersion')
                    ->lockForUpdate()
                    ->findOrFail($submission->id);
                $currentVersion = $lockedSubmission->currentVersion;

                if ($currentVersion->version_number !== (int) $data['version_number']) {
                    return SyncWriteResult::conflict($lockedSubmission);
                }

                $newVersion = $lockedSubmission->versions()->create([
                    'user_id' => $user->id,
                    'form_name' => $data['form_name'],
                    'content' => $data['content'],
                    'version_number' => $currentVersion->version_number + 1,
                    'base_version_number' => (int) $data['version_number'],
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);

                $updateData = ['current_version_id' => $newVersion->id];
                if (array_key_exists('priority', $data)) {
                    $updateData['priority'] = $data['priority'];
                }

                $lockedSubmission->update($updateData);

                return SyncWriteResult::applied($lockedSubmission);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existingVersion = ! empty($data['client_uuid'])
                ? FormSubmissionVersion::query()
                    ->where('client_uuid', $data['client_uuid'])
                    ->where('submission_id', $submission->id)
                    ->first()
                : null;

            if ($existingVersion) {
                return SyncWriteResult::duplicate($submission->fresh(['currentVersion.user']));
            }

            throw $e;
        }
    }
}
