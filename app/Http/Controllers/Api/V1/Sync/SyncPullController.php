<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPullRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Http\Resources\Form\FormTemplateResource;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Services\Form\FormPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class SyncPullController extends Controller
{
    public function __construct(private readonly FormPermissionService $permissions) {}

    /**
     * Delta pull for offline sync.
     *
     * Returns templates and submissions changed since the client's cursor
     * (with a 5-second overlap window — clients upsert by id, so re-received
     * rows are harmless), plus the user's complete permission snapshot.
     * Permissions are never a delta: grant rows are hard-deleted, so a delta
     * would miss revocations. No cursor means a full bootstrap.
     */
    public function __invoke(SyncPullRequest $request): JsonResponse
    {
        $serverTime = now();
        $user = $request->user('api');

        $since = $request->filled('cursor')
            ? Carbon::parse($request->validated('cursor'))->subSeconds(5)
            : null;

        $scopedTemplates = FormTemplate::query()
            ->with('currentVersion')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('is_active', true))
            ->get();

        $permissionSnapshot = $scopedTemplates
            ->map(fn (FormTemplate $template): array => [
                'form_template_id' => $template->id,
                'actions' => $this->permissions->resolvedPermissions($user, $template),
            ])
            ->values();

        $actionsByTemplateId = $permissionSnapshot->pluck('actions', 'form_template_id');

        $visibleTemplates = $scopedTemplates
            ->when(! $user->isAdmin(), fn ($templates) => $templates->filter(
                fn (FormTemplate $template): bool => $actionsByTemplateId[$template->id]['view']
            ))
            ->when($since, fn ($templates) => $templates->filter(
                fn (FormTemplate $template): bool => $template->updated_at >= $since
            ))
            ->values();

        $submissions = FormSubmission::query()
            ->with(['template', 'creator', 'currentVersion.user'])
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->get();

        return response()->json([
            'form_templates' => FormTemplateResource::collection($visibleTemplates),
            'form_submissions' => FormSubmissionResource::collection($submissions),
            'permissions' => $permissionSnapshot,
            'cursor' => $serverTime->toIso8601String(),
        ]);
    }
}
