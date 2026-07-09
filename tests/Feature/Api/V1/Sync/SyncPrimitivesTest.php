<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);
    $this->headers = ['Authorization' => "Bearer $this->token"];

    $this->template = FormTemplate::factory()->create();
    $this->templateVersion = FormTemplateVersion::factory()->create([
        'template_id' => $this->template->id,
        'version_number' => 1,
    ]);
});

function syncPrimitivesSubmission(User $user, FormTemplate $template, int $versionNumber = 1): FormSubmission
{
    $submission = FormSubmission::create(['form_template_id' => $template->id]);
    $version = $submission->versions()->create([
        'user_id' => $user->id,
        'form_name' => $template->name,
        'content' => ['v' => $versionNumber],
        'version_number' => $versionNumber,
    ]);
    $submission->update(['current_version_id' => $version->id]);

    return $submission;
}

// ── Idempotent create ────────────────────────────────────────────────────────

test('create with client_uuid stores and returns it', function () {
    $uuid = (string) Str::uuid();

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Offline Form',
        'content' => ['field' => 'value'],
        'client_uuid' => $uuid,
    ], $this->headers);

    $response->assertCreated()->assertJsonPath('data.client_uuid', $uuid);
    $this->assertDatabaseHas('form_submissions', ['client_uuid' => $uuid]);
});

test('replayed create with same client_uuid returns existing submission instead of duplicating', function () {
    $uuid = (string) Str::uuid();
    $payload = [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Offline Form',
        'content' => ['field' => 'value'],
        'client_uuid' => $uuid,
    ];

    $first = $this->postJson('/api/v1/form-submissions', $payload, $this->headers);
    $first->assertCreated();

    $second = $this->postJson('/api/v1/form-submissions', $payload, $this->headers);
    $second->assertSuccessful();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->assertDatabaseCount('form_submissions', 1);
    $this->assertDatabaseCount('form_submission_versions', 1);
});

test('create with invalid client_uuid is rejected', function () {
    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'Offline Form',
        'content' => ['field' => 'value'],
        'client_uuid' => 'not-a-uuid',
    ], $this->headers);

    $response->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
});

// ── Idempotent update ────────────────────────────────────────────────────────

test('update with client_uuid records base_version_number and client_uuid on the new version', function () {
    $submission = syncPrimitivesSubmission($this->user, $this->template);
    $uuid = (string) Str::uuid();

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 2],
        'version_number' => 1,
        'client_uuid' => $uuid,
    ], $this->headers);

    $response->assertSuccessful()
        ->assertJsonPath('data.current_version.version_number', 2)
        ->assertJsonPath('data.current_version.base_version_number', 1)
        ->assertJsonPath('data.current_version.client_uuid', $uuid);
});

test('replayed update with same client_uuid does not double-increment or falsely conflict', function () {
    $submission = syncPrimitivesSubmission($this->user, $this->template);
    $uuid = (string) Str::uuid();
    $payload = [
        'form_name' => $this->template->name,
        'content' => ['v' => 2],
        'version_number' => 1,
        'client_uuid' => $uuid,
    ];

    $this->putJson("/api/v1/form-submissions/{$submission->id}", $payload, $this->headers)
        ->assertSuccessful();

    // Replay: same payload again — would 409 without idempotency (version moved to 2).
    $replay = $this->putJson("/api/v1/form-submissions/{$submission->id}", $payload, $this->headers);

    $replay->assertSuccessful()
        ->assertJsonPath('data.current_version.version_number', 2);
    $this->assertDatabaseCount('form_submission_versions', 2);
});

test('update without client_uuid keeps plain optimistic-locking behavior', function () {
    $submission = syncPrimitivesSubmission($this->user, $this->template);

    $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 2],
        'version_number' => 1,
    ], $this->headers)->assertSuccessful();

    $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 3],
        'version_number' => 1,
    ], $this->headers)->assertConflict();
});

// ── Structured conflict & lock responses ─────────────────────────────────────

test('version conflict returns structured payload with the winning version', function () {
    $submission = syncPrimitivesSubmission($this->user, $this->template);

    $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 2, 'winner' => 'server'],
        'version_number' => 1,
    ], $this->headers)->assertSuccessful();

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 2, 'winner' => 'offline'],
        'version_number' => 1,
    ], $this->headers);

    $response->assertConflict()
        ->assertJsonPath('error', 'version_conflict')
        ->assertJsonPath('your_base_version_number', 1)
        ->assertJsonPath('current_version.version_number', 2)
        ->assertJsonPath('current_version.content.winner', 'server')
        ->assertJsonStructure(['message', 'error', 'current_version' => ['id', 'form_name', 'content', 'version_number'], 'your_base_version_number']);
});

test('updating an approved submission returns submission_locked', function () {
    $submission = syncPrimitivesSubmission($this->user, $this->template);
    $submission->update(['status' => 'approved']);

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $this->template->name,
        'content' => ['v' => 2],
        'version_number' => 1,
    ], $this->headers);

    $response->assertForbidden()->assertJsonPath('error', 'submission_locked');
    $this->assertDatabaseCount('form_submission_versions', 1);
});
