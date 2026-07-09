<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormSubmissionRequest;
use App\Http\Requests\Form\UpdateFormSubmissionRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Http\Resources\Form\FormSubmissionVersionResource;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Services\Form\SubmissionSyncService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class FormSubmissionController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SubmissionSyncService $sync,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FormSubmission::query()
            ->with(['template', 'creator', 'currentVersion.user']);

        if ($request->filled('form_template_id')) {
            $query->where('form_template_id', $request->form_template_id);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->integer('per_page', 15);
        $perPage = min(max($perPage, 1), 100);

        return FormSubmissionResource::collection($query->paginate($perPage));
    }

    /**
     * Store a newly created resource in storage.
     *
     * Idempotent when a client_uuid is supplied: replaying the same create
     * returns the existing submission (200) instead of creating a duplicate.
     */
    public function store(StoreFormSubmissionRequest $request): FormSubmissionResource
    {
        $template = FormTemplate::findOrFail($request->form_template_id);
        $this->authorize('create', [FormSubmission::class, $template]);

        $result = $this->sync->createSubmission($request->user('api'), [
            'form_template_id' => $request->form_template_id,
            'form_template_version_id' => $request->form_template_version_id,
            'form_name' => $request->form_name,
            'content' => $request->content,
            'priority' => $request->priority,
            'client_uuid' => $request->client_uuid,
        ]);

        $submission = $result->submission->load(['template', 'templateVersion', 'creator', 'currentVersion.user']);

        if ($result->isApplied()) {
            $this->notifications->notifySubmitted($submission);
        }

        return new FormSubmissionResource($submission);
    }

    /**
     * Display the specified resource.
     */
    public function show(FormSubmission $formSubmission): FormSubmissionResource
    {
        return new FormSubmissionResource($formSubmission->load(['template', 'templateVersion', 'creator', 'currentVersion.user', 'versions']));
    }

    /**
     * Update the specified resource in storage.
     *
     * Optimistic locking: a stale version_number yields a structured 409 that
     * carries the current server version so clients can merge without a
     * follow-up GET. Idempotent when a client_uuid is supplied for the new
     * version: a replayed update returns current state instead of
     * double-incrementing or falsely conflicting.
     */
    public function update(UpdateFormSubmissionRequest $request, FormSubmission $formSubmission): FormSubmissionResource|JsonResponse
    {
        if ($formSubmission->status === SubmissionStatus::Approved) {
            return response()->json([
                'message' => 'Submission is approved and locked.',
                'error' => 'submission_locked',
            ], 403);
        }

        $this->authorize('update', $formSubmission);

        $data = [
            'form_name' => $request->form_name,
            'content' => $request->content,
            'version_number' => $request->version_number,
            'client_uuid' => $request->client_uuid,
        ];
        if ($request->has('priority')) {
            $data['priority'] = $request->priority;
        }

        $result = $this->sync->updateSubmission($formSubmission, $request->user('api'), $data);

        if ($result->isConflict()) {
            $this->notifications->notifyConflict($result->submission, Auth::guard('api')->user());

            return response()->json([
                'message' => 'Version conflict',
                'error' => 'version_conflict',
                'current_version' => new FormSubmissionVersionResource($result->submission->currentVersion->load('user')),
                'your_base_version_number' => (int) $request->version_number,
            ], 409);
        }

        return new FormSubmissionResource($result->submission->load(['template', 'creator', 'currentVersion.user']));
    }
}
