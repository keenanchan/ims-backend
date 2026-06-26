<?php

namespace App\Notifications;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FormApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly FormSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formName = $this->submission->currentVersion?->form_name ?? 'Your form';

        return (new MailMessage)
            ->subject('Form Approved: '.$formName)
            ->line("\"{$formName}\" has been approved.")
            ->action('View Submission', url("/api/v1/form-submissions/{$this->submission->id}"));
    }
}
