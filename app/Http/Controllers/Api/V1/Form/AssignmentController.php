<?php

namespace App\Http\Controllers\Api\V1\Form;

use App\Http\Controllers\Controller;
use App\Http\Requests\Form\AssignFormSubmissionRequest;
use App\Http\Resources\Form\FormSubmissionResource;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\NotificationService;

class AssignmentController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Assign (or unassign) a form submission to a user, team, or department.
     */
    public function assign(AssignFormSubmissionRequest $request, FormSubmission $formSubmission): FormSubmissionResource
    {
        $this->authorize('assign', $formSubmission);

        $formSubmission->update([
            'assignee_type' => $request->input('assignee_type'),
            'assignee_id' => $request->input('assignee_id'),
        ]);

        $formSubmission->load(['template', 'creator', 'currentVersion', 'assignee']);

        if ($request->input('assignee_type') === 'user' && $request->filled('assignee_id')) {
            $assignee = User::find($request->input('assignee_id'));
            if ($assignee) {
                $this->notifications->notifyAssigned($formSubmission, $assignee);
            }
        }

        return new FormSubmissionResource($formSubmission);
    }
}
