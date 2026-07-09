<?php

use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FormApprovedNotification;
use App\Notifications\FormAssignedNotification;
use App\Notifications\FormRejectedNotification;
use App\Notifications\FormSubmittedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

test('mail notification classes implement ShouldQueue', function (string $class) {
    expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue();
})->with([
    FormSubmittedNotification::class,
    FormApprovedNotification::class,
    FormRejectedNotification::class,
    FormAssignedNotification::class,
]);

test('submission creation sends a queueable mail notification', function () {
    NotificationFacade::fake();

    $adminRole = Role::factory()->create(['name' => 'admin', 'is_active' => true]);
    $admin = User::factory()->create(['role_id' => $adminRole->id]);

    $user = User::factory()->create();
    $token = Auth::guard('api')->tokenById($user->id);

    $template = FormTemplate::factory()->create();
    $templateVersion = FormTemplateVersion::factory()->create([
        'template_id' => $template->id,
        'version_number' => 1,
    ]);

    $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $templateVersion->id,
        'form_name' => 'Test Form',
        'content' => ['field' => 'value'],
    ], ['Authorization' => "Bearer {$token}"]);

    NotificationFacade::assertSentTo(
        $admin,
        FormSubmittedNotification::class,
        fn (FormSubmittedNotification $notification): bool => $notification instanceof ShouldQueue
    );
});
