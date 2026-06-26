<?php

namespace App\Notifications;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FormSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly FormSubmission $submission) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formName = $this->submission->currentVersion?->form_name ?? 'a form';

        return (new MailMessage)
            ->subject('New Form Submission: '.$formName)
            ->line("A new form submission \"{$formName}\" has been submitted and requires review.")
            ->action('View Submission', url("/api/v1/form-submissions/{$this->submission->id}"));
    }
}
