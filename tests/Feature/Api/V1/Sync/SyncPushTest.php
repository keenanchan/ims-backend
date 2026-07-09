<?php

use App\Enums\FormPermissionAction;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplatePermission;
use App\Models\FormTemplateVersion;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

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

function syncPush($test, array $operations)
{
    return $test->postJson('/api/v1/sync/push', ['operations' => $operations], $test->headers);
}

function syncPushSubmission(User $user, FormTemplate $template, FormTemplateVersion $templateVersion, int $versions = 1, ?string $clientUuid = null): FormSubmission
{
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $templateVersion->id,
        'created_by' => $user->id,
        'client_uuid' => $clientUuid,
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

/**
 * A full offline batch: create, two updates, then submit — all one client_uuid.
 */
function syncPushFullBatch(FormTemplate $template, FormTemplateVersion $templateVersion, string $uuid, array $versionUuids): array
{
    return [
        [
            'op' => 'create',
            'client_uuid' => $uuid,
            'version_client_uuid' => $versionUuids[0],
            'form_template_id' => $template->id,
            'form_template_version_id' => $templateVersion->id,
            'form_name' => 'Offline Form',
            'content' => ['first_name' => 'one'],
        ],
        [
            'op' => 'update',
            'ref' => ['client_uuid' => $uuid],
            'version_client_uuid' => $versionUuids[1],
            'base_version_number' => 1,
            'form_name' => 'Offline Form',
            'content' => ['first_name' => 'two'],
        ],
        [
            'op' => 'update',
            'ref' => ['client_uuid' => $uuid],
            'version_client_uuid' => $versionUuids[2],
            'base_version_number' => 2,
            'form_name' => 'Offline Form',
            'content' => ['first_name' => 'three'],
        ],
        [
            'op' => 'submit',
            'ref' => ['client_uuid' => $uuid],
        ],
    ];
}

// ── Happy path batch ─────────────────────────────────────────────────────────

test('batch create, update, update, submit lands at version 3 pending approval with mappings', function () {
    $uuid = (string) Str::uuid();
    $versionUuids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

    $response = syncPush($this, syncPushFullBatch($this->template, $this->templateVersion, $uuid, $versionUuids));

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.1.status', 'applied')
        ->assertJsonPath('results.2.status', 'applied')
        ->assertJsonPath('results.3.status', 'applied')
        ->assertJsonPath('results.2.version_number', 3)
        ->assertJsonPath('results.3.submission_status', 'pending_approval');

    $serverId = $response->json("mappings.$uuid");
    $submission = FormSubmission::find($serverId);

    expect($submission)->not->toBeNull()
        ->and($submission->client_uuid)->toBe($uuid)
        ->and($submission->status->value)->toBe('pending_approval')
        ->and($submission->currentVersion->version_number)->toBe(3);
    $this->assertDatabaseCount('form_submission_versions', 3);
});

test('replaying the entire batch yields all duplicates and creates no new rows', function () {
    $uuid = (string) Str::uuid();
    $versionUuids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $batch = syncPushFullBatch($this->template, $this->templateVersion, $uuid, $versionUuids);

    syncPush($this, $batch)->assertOk();
    $replay = syncPush($this, $batch);

    $replay->assertOk()
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.1.status', 'duplicate')
        ->assertJsonPath('results.2.status', 'duplicate')
        ->assertJsonPath('results.3.status', 'duplicate')
        ->assertJsonPath("mappings.$uuid", FormSubmission::sole()->id);

    $this->assertDatabaseCount('form_submissions', 1);
    $this->assertDatabaseCount('form_submission_versions', 3);
    $this->assertDatabaseCount('sync_conflicts', 0);
});

// ── Refs ─────────────────────────────────────────────────────────────────────

test('update resolves ref by server id', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion);

    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['id' => $submission->id],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Renamed',
        'content' => ['first_name' => 'updated'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.id', $submission->id)
        ->assertJsonPath('results.0.version_number', 2);
});

test('update resolves ref by client_uuid stored in the database', function () {
    $uuid = (string) Str::uuid();
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion, 1, $uuid);

    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['client_uuid' => $uuid],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Renamed',
        'content' => ['first_name' => 'updated'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.0.id', $submission->id)
        ->assertJsonPath('results.0.client_uuid', $uuid);
});

test('unresolvable ref yields an invalid result', function () {
    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['client_uuid' => (string) Str::uuid()],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Ghost',
        'content' => ['first_name' => 'x'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'invalid')
        ->assertJsonStructure(['results' => [['errors' => ['ref']]]]);
});

// ── Conflicts ────────────────────────────────────────────────────────────────

test('stale base_version_number produces a conflict result, sync_conflicts row and notification', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion, 2);

    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['id' => $submission->id],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Offline Edit',
        'content' => ['first_name' => 'offline'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'conflict')
        ->assertJsonPath('results.0.id', $submission->id)
        ->assertJsonPath('results.0.current_version.version_number', 2)
        ->assertJsonPath('results.0.current_version.content.first_name', 'v2')
        ->assertJsonStructure(['results' => [['conflict_id', 'current_version' => ['id', 'version_number', 'form_name', 'content']]]]);

    $this->assertDatabaseHas('sync_conflicts', [
        'form_submission_id' => $submission->id,
        'user_id' => $this->user->id,
        'base_version_number' => 1,
        'submitted_form_name' => 'Offline Edit',
        'status' => 'open',
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'type' => 'version_conflict',
    ]);
    $this->assertDatabaseCount('form_submission_versions', 2);
});

test('replaying a conflicting op does not duplicate the SyncConflict row', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion, 2);
    $op = [
        'op' => 'update',
        'ref' => ['id' => $submission->id],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Offline Edit',
        'content' => ['first_name' => 'offline'],
    ];

    $first = syncPush($this, [$op]);
    $second = syncPush($this, [$op]);

    $second->assertOk()->assertJsonPath('results.0.status', 'conflict');
    expect($second->json('results.0.conflict_id'))->toBe($first->json('results.0.conflict_id'));
    $this->assertDatabaseCount('sync_conflicts', 1);
    expect(Notification::where('type', 'version_conflict')->count())->toBe(1);
});

// ── Authorization ────────────────────────────────────────────────────────────

test('create against a permission-restricted template is forbidden and writes nothing', function () {
    $otherRole = Role::factory()->create();
    FormTemplatePermission::create([
        'form_template_id' => $this->template->id,
        'action' => FormPermissionAction::Create->value,
        'permissible_type' => 'role',
        'permissible_id' => $otherRole->id,
    ]);

    $response = syncPush($this, [[
        'op' => 'create',
        'client_uuid' => (string) Str::uuid(),
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $this->templateVersion->id,
        'form_name' => 'No Access',
        'content' => ['first_name' => 'x'],
    ]]);

    $response->assertOk()->assertJsonPath('results.0.status', 'forbidden');
    $this->assertDatabaseCount('form_submissions', 0);
    $this->assertDatabaseCount('form_submission_versions', 0);
});

test('update without edit permission is forbidden and writes no version', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion);
    $otherRole = Role::factory()->create();
    FormTemplatePermission::create([
        'form_template_id' => $this->template->id,
        'action' => FormPermissionAction::Edit->value,
        'permissible_type' => 'role',
        'permissible_id' => $otherRole->id,
    ]);

    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['id' => $submission->id],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Blocked',
        'content' => ['first_name' => 'x'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'forbidden')
        ->assertJsonPath('results.0.error', 'forbidden');
    $this->assertDatabaseCount('form_submission_versions', 1);
});

test('updating an approved submission is forbidden with submission_locked', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion);
    $submission->update(['status' => 'approved']);

    $response = syncPush($this, [[
        'op' => 'update',
        'ref' => ['id' => $submission->id],
        'version_client_uuid' => (string) Str::uuid(),
        'base_version_number' => 1,
        'form_name' => 'Locked',
        'content' => ['first_name' => 'x'],
    ]]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'forbidden')
        ->assertJsonPath('results.0.error', 'submission_locked');
    $this->assertDatabaseCount('form_submission_versions', 1);
});

// ── Invalid ops ──────────────────────────────────────────────────────────────

test('approve, reject and assign ops are explicitly invalid', function () {
    $submission = syncPushSubmission($this->user, $this->template, $this->templateVersion);

    $response = syncPush($this, [
        ['op' => 'approve', 'ref' => ['id' => $submission->id]],
        ['op' => 'reject', 'ref' => ['id' => $submission->id]],
        ['op' => 'assign', 'ref' => ['id' => $submission->id]],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'invalid')
        ->assertJsonPath('results.1.status', 'invalid')
        ->assertJsonPath('results.2.status', 'invalid');
});

test('content violating the pinned template schema is invalid with errors', function () {
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

    $response = syncPush($this, [[
        'op' => 'create',
        'client_uuid' => (string) Str::uuid(),
        'form_template_id' => $this->template->id,
        'form_template_version_id' => $strictVersion->id,
        'form_name' => 'Bad Content',
        'content' => ['age' => 'not-an-integer'],
    ]]);

    $response->assertOk()->assertJsonPath('results.0.status', 'invalid');
    expect($response->json('results.0.errors'))->toHaveKey('content');
    $this->assertDatabaseCount('form_submissions', 0);
});

test('one bad op mid-batch does not poison its neighbors', function () {
    $uuid = (string) Str::uuid();

    $response = syncPush($this, [
        [
            'op' => 'create',
            'client_uuid' => $uuid,
            'form_template_id' => $this->template->id,
            'form_template_version_id' => $this->templateVersion->id,
            'form_name' => 'Good One',
            'content' => ['first_name' => 'one'],
        ],
        ['op' => 'approve', 'ref' => ['id' => 999]],
        [
            'op' => 'update',
            'ref' => ['client_uuid' => $uuid],
            'version_client_uuid' => (string) Str::uuid(),
            'base_version_number' => 1,
            'form_name' => 'Good One',
            'content' => ['first_name' => 'two'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'applied')
        ->assertJsonPath('results.1.status', 'invalid')
        ->assertJsonPath('results.2.status', 'applied')
        ->assertJsonPath('results.2.version_number', 2);
});

// ── Submit semantics ─────────────────────────────────────────────────────────

test('submit on an already pending submission is a duplicate, on a rejected one invalid', function () {
    $pending = syncPushSubmission($this->user, $this->template, $this->templateVersion);
    $pending->update(['status' => 'pending_approval']);
    $rejected = syncPushSubmission($this->user, $this->template, $this->templateVersion);
    $rejected->update(['status' => 'rejected']);

    $response = syncPush($this, [
        ['op' => 'submit', 'ref' => ['id' => $pending->id]],
        ['op' => 'submit', 'ref' => ['id' => $rejected->id]],
    ]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.1.status', 'invalid');
    expect($rejected->fresh()->status->value)->toBe('rejected');
});

// ── Envelope validation ──────────────────────────────────────────────────────

test('more than 100 operations is rejected with 422', function () {
    $operations = array_fill(0, 101, ['op' => 'submit', 'ref' => ['id' => 1]]);

    syncPush($this, $operations)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operations');
});

test('empty operations array is rejected with 422', function () {
    syncPush($this, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operations');
});

test('unauthenticated push is rejected with 401', function () {
    $this->postJson('/api/v1/sync/push', ['operations' => [['op' => 'submit']]])
        ->assertUnauthorized();
});
