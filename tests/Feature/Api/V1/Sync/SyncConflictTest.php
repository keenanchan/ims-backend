<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\Role;
use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function () {
    NotificationFacade::fake();

    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);
    $this->headers = ['Authorization' => "Bearer $this->token"];

    $this->template = FormTemplate::factory()->create();
    $this->templateVersion = FormTemplateVersion::factory()->create([
        'template_id' => $this->template->id,
        'version_number' => 1,
    ]);
});

function syncConflictAdmin(): array
{
    $role = Role::factory()->create(['name' => 'admin']);
    $admin = User::factory()->create(['role_id' => $role->id]);
    $token = Auth::guard('api')->tokenById($admin->id);

    return [$admin, ['Authorization' => "Bearer $token"]];
}

function syncConflictSubmission(User $user, FormTemplate $template, FormTemplateVersion $templateVersion, int $versions = 2): FormSubmission
{
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $templateVersion->id,
        'created_by' => $user->id,
    ]);

    for ($i = 1; $i <= $versions; $i++) {
        $version = $submission->versions()->create([
            'user_id' => $user->id,
            'form_name' => $template->name,
            'content' => ['first_name' => "v{$i}"],
            'version_number' => $i,
        ]);
        $submission->update(['current_version_id' => $version->id]);
    }

    return $submission;
}

function syncConflictFor(User $user, FormSubmission $submission): SyncConflict
{
    return SyncConflict::factory()->create([
        'form_submission_id' => $submission->id,
        'user_id' => $user->id,
        'base_version_number' => 1,
        'submitted_form_name' => 'Offline Edit',
        'submitted_content' => ['first_name' => 'offline'],
    ]);
}

// ── Index ────────────────────────────────────────────────────────────────────

test('index shows only the current user\'s open conflicts by default', function () {
    $mine = syncConflictFor($this->user, syncConflictSubmission($this->user, $this->template, $this->templateVersion));
    syncConflictFor(User::factory()->create(), syncConflictSubmission($this->user, $this->template, $this->templateVersion));
    SyncConflict::factory()->resolved()->create([
        'form_submission_id' => $mine->form_submission_id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson('/api/v1/sync/conflicts', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.submitted_content.first_name', 'offline')
        ->assertJsonPath('data.0.submission.current_version.version_number', 2);
});

test('admin sees all conflicts', function () {
    syncConflictFor($this->user, syncConflictSubmission($this->user, $this->template, $this->templateVersion));
    syncConflictFor(User::factory()->create(), syncConflictSubmission($this->user, $this->template, $this->templateVersion));
    [, $adminHeaders] = syncConflictAdmin();

    $this->getJson('/api/v1/sync/conflicts', $adminHeaders)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('index supports a status filter', function () {
    $submission = syncConflictSubmission($this->user, $this->template, $this->templateVersion);
    syncConflictFor($this->user, $submission);
    $resolved = SyncConflict::factory()->resolved()->create([
        'form_submission_id' => $submission->id,
        'user_id' => $this->user->id,
    ]);

    $this->getJson('/api/v1/sync/conflicts?status=resolved', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resolved->id);
});

// ── Show ─────────────────────────────────────────────────────────────────────

test('show is forbidden for another user\'s conflict', function () {
    $conflict = syncConflictFor(User::factory()->create(), syncConflictSubmission($this->user, $this->template, $this->templateVersion));

    $this->getJson("/api/v1/sync/conflicts/{$conflict->id}", $this->headers)
        ->assertForbidden();
});

test('show returns the conflict with the current server version', function () {
    $conflict = syncConflictFor($this->user, syncConflictSubmission($this->user, $this->template, $this->templateVersion));

    $this->getJson("/api/v1/sync/conflicts/{$conflict->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.id', $conflict->id)
        ->assertJsonPath('data.base_version_number', 1)
        ->assertJsonPath('data.submission.current_version.version_number', 2)
        ->assertJsonStructure(['data' => ['id', 'form_submission_id', 'submitted_form_name', 'submitted_content', 'status', 'submission' => ['current_version' => ['id', 'form_name', 'content', 'version_number']]]]);
});

// ── Resolve: merge ───────────────────────────────────────────────────────────

test('resolve with merged payload creates a new version, keeps the audit trail and links resolved_version_id', function () {
    $submission = syncConflictSubmission($this->user, $this->template, $this->templateVersion);
    $conflict = syncConflictFor($this->user, $submission);

    $response = $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'form_name' => 'Merged Form',
        'content' => ['first_name' => 'merged'],
    ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.submission.current_version.version_number', 3)
        ->assertJsonPath('data.submission.current_version.form_name', 'Merged Form')
        ->assertJsonPath('data.submission.current_version.content.first_name', 'merged');

    $submission->refresh();
    expect($submission->versions()->count())->toBe(3)
        ->and($conflict->fresh()->resolved_version_id)->toBe($submission->current_version_id);
    $this->assertDatabaseHas('form_submission_versions', ['submission_id' => $submission->id, 'version_number' => 1]);
    $this->assertDatabaseHas('form_submission_versions', ['submission_id' => $submission->id, 'version_number' => 2]);
});

test('resolve on a non-open conflict returns conflict_not_open', function () {
    $submission = syncConflictSubmission($this->user, $this->template, $this->templateVersion);
    $conflict = SyncConflict::factory()->discarded()->create([
        'form_submission_id' => $submission->id,
        'user_id' => $this->user->id,
    ]);

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'form_name' => 'Merged Form',
        'content' => ['first_name' => 'merged'],
    ], $this->headers)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'conflict_not_open');
});

test('resolve with content violating the pinned template schema is rejected with 422', function () {
    $strictVersion = FormTemplateVersion::factory()->create([
        'template_id' => $this->template->id,
        'version_number' => 2,
        'json_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name'],
        ],
    ]);
    $submission = syncConflictSubmission($this->user, $this->template, $strictVersion);
    $conflict = syncConflictFor($this->user, $submission);

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'form_name' => 'Merged Form',
        'content' => ['age' => 'not-an-integer'],
    ], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');

    expect($conflict->fresh()->status->value)->toBe('open');
});

test('resolve-merge on an approved submission returns submission_locked but discard still works', function () {
    $submission = syncConflictSubmission($this->user, $this->template, $this->templateVersion);
    $submission->update(['status' => 'approved']);
    $conflict = syncConflictFor($this->user, $submission);

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'form_name' => 'Merged Form',
        'content' => ['first_name' => 'merged'],
    ], $this->headers)
        ->assertForbidden()
        ->assertJsonPath('error', 'submission_locked');

    expect($conflict->fresh()->status->value)->toBe('open');

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'action' => 'discard',
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');
});

// ── Resolve: discard ─────────────────────────────────────────────────────────

test('discard marks the conflict discarded and creates no version', function () {
    $submission = syncConflictSubmission($this->user, $this->template, $this->templateVersion);
    $conflict = syncConflictFor($this->user, $submission);

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'action' => 'discard',
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded')
        ->assertJsonPath('data.resolved_version_id', null);

    expect($submission->versions()->count())->toBe(2);
});

test('resolve is forbidden for another user\'s conflict', function () {
    $conflict = syncConflictFor(User::factory()->create(), syncConflictSubmission($this->user, $this->template, $this->templateVersion));

    $this->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
        'action' => 'discard',
    ], $this->headers)
        ->assertForbidden();
});
