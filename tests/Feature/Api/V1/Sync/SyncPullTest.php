<?php

use App\Enums\FormPermissionAction;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplatePermission;
use App\Models\FormTemplateVersion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);
    $this->headers = ['Authorization' => "Bearer $this->token"];

    $this->template = makeSyncTemplate();
});

function makeSyncTemplate(array $attributes = []): FormTemplate
{
    $template = FormTemplate::factory()->create($attributes);
    $version = FormTemplateVersion::factory()->create([
        'template_id' => $template->id,
        'version_number' => 1,
    ]);
    $template->update(['current_version_id' => $version->id]);

    return $template->refresh();
}

function makeSyncSubmission(User $user, FormTemplate $template): FormSubmission
{
    $submission = FormSubmission::create(['form_template_id' => $template->id]);
    $version = $submission->versions()->create([
        'user_id' => $user->id,
        'form_name' => $template->name,
        'content' => ['v' => 1],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $version->id]);

    return $submission;
}

function makeSyncAdminHeaders(): array
{
    $adminRole = Role::factory()->create(['name' => 'admin', 'is_active' => true]);
    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $token = Auth::guard('api')->tokenById($admin->id);

    return ['Authorization' => "Bearer $token"];
}

// ── Bootstrap (no cursor) ────────────────────────────────────────────────────

test('bootstrap pull returns templates with current version, submissions, permissions, and cursor', function () {
    $submission = makeSyncSubmission($this->user, $this->template);

    $response = $this->getJson('/api/v1/sync', $this->headers);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'form_templates' => [['id', 'name', 'is_active', 'current_version' => ['id', 'json_schema', 'ui_schema', 'version_number']]],
            'form_submissions' => [['id', 'status', 'template', 'current_version' => ['id', 'form_name', 'content', 'version_number', 'user']]],
            'permissions' => [['form_template_id', 'actions' => ['view', 'create', 'edit']]],
            'cursor',
        ])
        ->assertJsonCount(1, 'form_templates')
        ->assertJsonPath('form_templates.0.id', $this->template->id)
        ->assertJsonPath('form_templates.0.current_version.version_number', 1)
        ->assertJsonPath('form_submissions.0.id', $submission->id)
        ->assertJsonPath('form_submissions.0.current_version.version_number', 1)
        ->assertJsonPath('permissions.0.form_template_id', $this->template->id)
        ->assertJsonPath('permissions.0.actions.view', true)
        ->assertJsonPath('permissions.0.actions.create', true)
        ->assertJsonPath('permissions.0.actions.edit', true);

    expect($response->json('cursor'))->toBeString();
    expect(fn () => Carbon::parse($response->json('cursor')))->not->toThrow(Exception::class);
});

// ── Cursor (delta) behavior ──────────────────────────────────────────────────

test('cursor filters out unchanged rows and includes changed ones', function () {
    $oldSubmission = makeSyncSubmission($this->user, $this->template);
    $oldSubmission->updated_at = now()->subDays(2);
    $oldSubmission->save();

    $this->template->updated_at = now()->subDays(2);
    $this->template->save();

    $changedTemplate = makeSyncTemplate();
    $newSubmission = makeSyncSubmission($this->user, $changedTemplate);

    $cursor = urlencode(now()->subHour()->toIso8601String());
    $response = $this->getJson("/api/v1/sync?cursor={$cursor}", $this->headers);

    $response->assertSuccessful()
        ->assertJsonCount(1, 'form_templates')
        ->assertJsonPath('form_templates.0.id', $changedTemplate->id)
        ->assertJsonCount(1, 'form_submissions')
        ->assertJsonPath('form_submissions.0.id', $newSubmission->id);
});

test('overlap window returns a row updated exactly at the cursor timestamp', function () {
    $cursorTime = now();

    $submission = makeSyncSubmission($this->user, $this->template);
    $submission->updated_at = $cursorTime;
    $submission->save();

    $cursor = urlencode($cursorTime->toIso8601String());
    $response = $this->getJson("/api/v1/sync?cursor={$cursor}", $this->headers);

    $response->assertSuccessful();
    expect(collect($response->json('form_submissions'))->pluck('id'))->toContain($submission->id);
});

test('permission snapshot is complete even when nothing changed since the cursor', function () {
    makeSyncSubmission($this->user, $this->template);

    $cursor = urlencode(now()->addHour()->toIso8601String());
    $response = $this->getJson("/api/v1/sync?cursor={$cursor}", $this->headers);

    $response->assertSuccessful()
        ->assertJsonCount(0, 'form_templates')
        ->assertJsonCount(0, 'form_submissions')
        ->assertJsonCount(1, 'permissions')
        ->assertJsonPath('permissions.0.form_template_id', $this->template->id)
        ->assertJsonPath('permissions.0.actions.view', true);
});

// ── Visibility scope ─────────────────────────────────────────────────────────

test('inactive templates are hidden from non-admins', function () {
    $inactive = makeSyncTemplate(['is_active' => false]);

    $response = $this->getJson('/api/v1/sync', $this->headers)->assertSuccessful();

    expect(collect($response->json('form_templates'))->pluck('id'))->not->toContain($inactive->id);
    expect(collect($response->json('permissions'))->pluck('form_template_id'))->not->toContain($inactive->id);
});

test('inactive templates are visible to admins and included in their permission snapshot', function () {
    $inactive = makeSyncTemplate(['is_active' => false]);

    $response = $this->getJson('/api/v1/sync', makeSyncAdminHeaders())->assertSuccessful();

    expect(collect($response->json('form_templates'))->pluck('id'))->toContain($inactive->id);
    expect(collect($response->json('permissions'))->pluck('form_template_id'))->toContain($inactive->id);
});

test('view-restricted templates are excluded from templates but appear in the permission snapshot with view false', function () {
    $otherRole = Role::factory()->create();
    FormTemplatePermission::create([
        'form_template_id' => $this->template->id,
        'action' => FormPermissionAction::View->value,
        'permissible_type' => 'role',
        'permissible_id' => $otherRole->id,
    ]);

    $response = $this->getJson('/api/v1/sync', $this->headers);

    $response->assertSuccessful()->assertJsonCount(0, 'form_templates');

    $entry = collect($response->json('permissions'))->firstWhere('form_template_id', $this->template->id);
    expect($entry)->not->toBeNull();
    expect($entry['actions']['view'])->toBeFalse();
});

// ── Validation & auth ────────────────────────────────────────────────────────

test('invalid cursor is rejected with 422', function () {
    $this->getJson('/api/v1/sync?cursor=not-a-date', $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

test('unauthenticated pull returns 401', function () {
    $this->getJson('/api/v1/sync')->assertUnauthorized();
});
