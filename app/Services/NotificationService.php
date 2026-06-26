<?php

namespace App\Services;

use App\Models\FormSubmission;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\FormApprovedNotification;
use App\Notifications\FormAssignedNotification;
use App\Notifications\FormRejectedNotification;
use App\Notifications\FormSubmittedNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationService
{
    public function notifySubmitted(FormSubmission $submission): void
    {
        $admins = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->get();

        $data = [
            'submission_id' => $submission->id,
            'form_name' => $submission->currentVersion?->form_name ?? '',
        ];

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'form_submitted',
                'notifiable_type' => FormSubmission::class,
                'notifiable_id' => $submission->id,
                'data' => $data,
            ]);
        }

        if ($admins->isNotEmpty()) {
            NotificationFacade::send($admins, new FormSubmittedNotification($submission));
        }
    }

    public function notifyApproved(FormSubmission $submission): void
    {
        $creator = $submission->creator;
        if (! $creator) {
            return;
        }

        $data = [
            'submission_id' => $submission->id,
            'form_name' => $submission->currentVersion?->form_name ?? '',
        ];

        Notification::create([
            'user_id' => $creator->id,
            'type' => 'form_approved',
            'notifiable_type' => FormSubmission::class,
            'notifiable_id' => $submission->id,
            'data' => $data,
        ]);

        NotificationFacade::send($creator, new FormApprovedNotification($submission));
    }

    public function notifyRejected(FormSubmission $submission): void
    {
        $creator = $submission->creator;
        if (! $creator) {
            return;
        }

        $data = [
            'submission_id' => $submission->id,
            'form_name' => $submission->currentVersion?->form_name ?? '',
        ];

        Notification::create([
            'user_id' => $creator->id,
            'type' => 'form_rejected',
            'notifiable_type' => FormSubmission::class,
            'notifiable_id' => $submission->id,
            'data' => $data,
        ]);

        NotificationFacade::send($creator, new FormRejectedNotification($submission));
    }

    public function notifyAssigned(FormSubmission $submission, User $assignee): void
    {
        $data = [
            'submission_id' => $submission->id,
            'form_name' => $submission->currentVersion?->form_name ?? '',
        ];

        Notification::create([
            'user_id' => $assignee->id,
            'type' => 'form_assigned',
            'notifiable_type' => FormSubmission::class,
            'notifiable_id' => $submission->id,
            'data' => $data,
        ]);

        NotificationFacade::send($assignee, new FormAssignedNotification($submission));
    }

    public function notifyConflict(FormSubmission $submission, User $user): void
    {
        Notification::create([
            'user_id' => $user->id,
            'type' => 'version_conflict',
            'notifiable_type' => FormSubmission::class,
            'notifiable_id' => $submission->id,
            'data' => [
                'submission_id' => $submission->id,
                'form_name' => $submission->currentVersion?->form_name ?? '',
                'current_version_number' => $submission->currentVersion?->version_number,
            ],
        ]);
    }
}
