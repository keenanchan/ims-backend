<?php

use App\Enums\SubmissionStatus;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Notifications\FormApprovedNotification;
use App\Notifications\FormAssignedNotification;
use App\Notifications\FormRejectedNotification;
use App\Notifications\FormSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    NotificationFacade::fake();

    $this->adminRole = Role::factory()->create(['name' => 'admin', 'is_active' => true]);
    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);
    $this->adminToken = Auth::guard('api')->tokenById($this->admin->id);

    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);

    $this->template = FormTemplate::factory()->create();
    $this->templateVersion = FormTemplateVersion::factory()->create([
        'template_id' => $this->template->id,
        'version_number' => 1,
    ]);
});

// ─── form_submitted ──────────────────────────────────────────────────────────

test('creating a submission sends in-app notification to admins', function (): void {
    $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Test Form',
        'content' => ['field' => 'value'],
    ], ['Authorization' => "Bearer {$this->token}"]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->admin->id,
        'type' => 'form_submitted',
    ]);
});

test('creating a submission sends email to admins', function (): void {
    $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Test Form',
        'content' => ['field' => 'value'],
    ], ['Authorization' => "Bearer {$this->token}"]);

    NotificationFacade::assertSentTo($this->admin, FormSubmittedNotification::class);
});

test('submission notification is not sent when no admins exist', function (): void {
    $this->admin->delete();

    $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Test Form',
        'content' => ['field' => 'value'],
    ], ['Authorization' => "Bearer {$this->token}"]);

    $this->assertDatabaseCount('notifications', 0);
});

// ─── form_approved ───────────────────────────────────────────────────────────

test('approving a submission sends in-app notification to creator', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
        'created_by' => $this->user->id,
        'status' => SubmissionStatus::PendingApproval,
    ]);

    $this->postJson("/api/v1/form-submissions/{$submission->id}/approve", [], [
        'Authorization' => "Bearer {$this->adminToken}",
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'form_approved',
        'notifiable_id' => $submission->id,
    ]);
});

test('approving a submission sends email to creator', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
        'created_by' => $this->user->id,
        'status' => SubmissionStatus::PendingApproval,
    ]);

    $this->postJson("/api/v1/form-submissions/{$submission->id}/approve", [], [
        'Authorization' => "Bearer {$this->adminToken}",
    ]);

    NotificationFacade::assertSentTo($this->user, FormApprovedNotification::class);
});

// ─── form_rejected ───────────────────────────────────────────────────────────

test('rejecting a submission sends in-app notification to creator', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
        'created_by' => $this->user->id,
        'status' => SubmissionStatus::PendingApproval,
    ]);

    $this->postJson("/api/v1/form-submissions/{$submission->id}/reject", [], [
        'Authorization' => "Bearer {$this->adminToken}",
    ]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'form_rejected',
        'notifiable_id' => $submission->id,
    ]);
});

test('rejecting a submission sends email to creator', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
        'created_by' => $this->user->id,
        'status' => SubmissionStatus::PendingApproval,
    ]);

    $this->postJson("/api/v1/form-submissions/{$submission->id}/reject", [], [
        'Authorization' => "Bearer {$this->adminToken}",
    ]);

    NotificationFacade::assertSentTo($this->user, FormRejectedNotification::class);
});

// ─── form_assigned ───────────────────────────────────────────────────────────

test('assigning a submission to a user sends in-app notification', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
    ]);
    $assignee = User::factory()->create();

    $this->postJson("/api/v1/form-submissions/{$submission->id}/assign", [
        'assignee_type' => 'user',
        'assignee_id' => $assignee->id,
    ], ['Authorization' => "Bearer {$this->adminToken}"]);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $assignee->id,
        'type' => 'form_assigned',
        'notifiable_id' => $submission->id,
    ]);
});

test('assigning a submission to a user sends email notification', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
    ]);
    $assignee = User::factory()->create();

    $this->postJson("/api/v1/form-submissions/{$submission->id}/assign", [
        'assignee_type' => 'user',
        'assignee_id' => $assignee->id,
    ], ['Authorization' => "Bearer {$this->adminToken}"]);

    NotificationFacade::assertSentTo($assignee, FormAssignedNotification::class);
});

test('assigning to a team does not send email notification', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
    ]);
    $team = Team::factory()->create();

    $this->postJson("/api/v1/form-submissions/{$submission->id}/assign", [
        'assignee_type' => 'team',
        'assignee_id' => $team->id,
    ], ['Authorization' => "Bearer {$this->adminToken}"]);

    NotificationFacade::assertNothingSent();
});

// ─── version_conflict ────────────────────────────────────────────────────────

test('version conflict sends in-app notification to the conflicting user', function (): void {
    $submission = FormSubmission::factory()->create([
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'created_by' => $this->user->id,
    ]);
    $version = $submission->versions()->create([
        'user_id' => $this->user->id,
        'form_name' => 'Test',
        'content' => [],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $version->id]);

    $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => 'Updated',
        'content' => ['field' => 'value'],
        'version_number' => 999,
    ], ['Authorization' => "Bearer {$this->token}"])->assertStatus(409);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'version_conflict',
        'notifiable_id' => $submission->id,
    ]);
});
