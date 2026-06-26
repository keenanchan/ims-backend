<?php

namespace App\Notifications;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FormAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly FormSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formName = $this->submission->currentVersion?->form_name ?? 'A form';

        return (new MailMessage)
            ->subject('Form Assigned to You: '.$formName)
            ->line("You have been assigned \"{$formName}\".")
            ->action('View Submission', url("/api/v1/form-submissions/{$this->submission->id}"));
    }
}
