<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Form\RejectFormSubmissionRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Models\FormSubmission;
use App\Services\NotificationService;

class WorkflowController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Transition a draft submission to pending_approval.
     */
    public function submit(FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('submit', $formSubmission);

        if ($formSubmission->status !== SubmissionStatus::Draft) {
            abort(422, 'Only draft submissions can be submitted for approval.');
        }

        $formSubmission->update(['status' => SubmissionStatus::PendingApproval]);

        return new FormSubmissionResource($formSubmission->load(['template', 'creator', 'currentVersion.user']));
    }

    /**
     * Transition a pending_approval submission to approved.
     */
    public function approve(FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('approve', $formSubmission);

        if ($formSubmission->status !== SubmissionStatus::PendingApproval) {
            abort(422, 'Only submissions pending approval can be approved.');
        }

        $formSubmission->update(['status' => SubmissionStatus::Approved]);
        $formSubmission->load(['template', 'creator', 'currentVersion']);
        $this->notifications->notifyApproved($formSubmission);

        return new FormSubmissionResource($formSubmission);
    }

    /**
     * Transition a pending_approval submission to rejected.
     */
    public function reject(RejectFormSubmissionRequest $request, FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('reject', $formSubmission);

        if ($formSubmission->status !== SubmissionStatus::PendingApproval) {
            abort(422, 'Only submissions pending approval can be rejected.');
        }

        $formSubmission->update(['status' => SubmissionStatus::Rejected]);
        $formSubmission->load(['template', 'creator', 'currentVersion']);
        $this->notifications->notifyRejected($formSubmission);

        return new FormSubmissionResource($formSubmission);
    }
}
