<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Enums\SubmissionStatus;
use App\Enums\SyncConflictStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\ResolveConflictRequest;
use App\Http\Resources\Sync\SyncConflictResource;
use App\Models\SyncConflict;
use App\Services\Form\SubmissionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SyncConflictController extends Controller
{
    public function __construct(private readonly SubmissionSyncService $sync) {}

    /**
     * List the current user's sync conflicts (admins see all), newest first.
     *
     * Defaults to open conflicts; ?status= filters by any conflict status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user('api');

        $query = SyncConflict::query()
            ->with(['submission.currentVersion.user', 'submission.templateVersion'])
            ->latest('id');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $query->where('status', $request->query('status', SyncConflictStatus::Open->value));

        return SyncConflictResource::collection($query->paginate(15));
    }

    /**
     * Display a single conflict with the current server version for diffing.
     */
    public function show(SyncConflict $syncConflict): SyncConflictResource
    {
        $this->authorize('view', $syncConflict);

        return new SyncConflictResource($syncConflict->load(['submission.currentVersion.user', 'submission.templateVersion']));
    }

    /**
     * Resolve an open conflict by discarding it or applying a merged payload.
     *
     * A merge lands as a normal new version through SubmissionSyncService
     * against the submission's current version_number, keeping the audit
     * trail intact. Approved submissions are locked: discard is the only exit.
     */
    public function resolve(ResolveConflictRequest $request, SyncConflict $syncConflict): SyncConflictResource|JsonResponse
    {
        $this->authorize('resolve', $syncConflict);

        if ($syncConflict->status !== SyncConflictStatus::Open) {
            return response()->json([
                'message' => 'Only open conflicts can be resolved.',
                'error' => 'conflict_not_open',
            ], 422);
        }

        if ($request->input('action') === 'discard') {
            $syncConflict->update(['status' => SyncConflictStatus::Discarded]);

            return new SyncConflictResource($syncConflict->load('submission.currentVersion.user'));
        }

        $submission = $syncConflict->submission()->with('currentVersion')->firstOrFail();

        if ($submission->status === SubmissionStatus::Approved) {
            return response()->json([
                'message' => 'Submission is approved and locked.',
                'error' => 'submission_locked',
            ], 403);
        }

        $user = $request->user('api');
        $data = [
            'form_name' => $request->input('form_name'),
            'content' => $request->input('content'),
        ];

        $result = $this->sync->updateSubmission($submission, $user, $data + [
            'version_number' => $submission->currentVersion->version_number,
        ]);

        if ($result->isConflict()) {
            $fresh = $submission->fresh(['currentVersion']);
            $result = $this->sync->updateSubmission($fresh, $user, $data + [
                'version_number' => $fresh->currentVersion->version_number,
            ]);
        }

        if ($result->isConflict()) {
            return response()->json([
                'message' => 'Version conflict while applying the merged resolution.',
                'error' => 'version_conflict',
            ], 409);
        }

        $syncConflict->update([
            'status' => SyncConflictStatus::Resolved,
            'resolved_version_id' => $result->submission->current_version_id,
        ]);

        return new SyncConflictResource($syncConflict->load('submission.currentVersion.user'));
    }
}
