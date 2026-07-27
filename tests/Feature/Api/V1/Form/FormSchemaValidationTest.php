<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A valid JSON Schema with an object type, two typed properties, and one required.
 *
 * @return array<string, mixed>
 */
function typedSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ],
        'required' => ['name'],
    ];
}

// ---------------------------------------------------------------------------
// Template: json_schema must be a valid JSON Schema (store)
// ---------------------------------------------------------------------------

test('template store rejects json_schema with invalid type keyword', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Bad Schema Form',
        'json_schema' => ['type' => 'not_a_valid_type'],
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['json_schema']);
});

test('template store rejects json_schema where properties is not an object', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Bad Properties Form',
        'json_schema' => ['type' => 'object', 'properties' => 'not_an_object'],
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['json_schema']);
});

test('template store rejects json_schema where required is not an array', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Bad Required Form',
        'json_schema' => ['type' => 'object', 'required' => 'name'],
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['json_schema']);
});

test('template store accepts a valid json_schema with type and properties', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Valid Schema Form',
        'json_schema' => typedSchema(),
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

test('template store accepts an empty json_schema (matches anything)', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Empty Schema Form',
        'json_schema' => [],
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

test('template store accepts nested properties in json_schema', function () {
    $response = $this->postJson('/api/v1/form-templates', [
        'name' => 'Nested Schema Form',
        'json_schema' => [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['street'],
                ],
            ],
        ],
        'ui_schema' => [],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

// ---------------------------------------------------------------------------
// Template: json_schema must be a valid JSON Schema (update)
// ---------------------------------------------------------------------------

test('template update rejects invalid json_schema', function () {
    $template = FormTemplate::factory()->create();
    $v1 = $template->versions()->create([
        'user_id' => $this->user->id,
        'name' => $template->name,
        'json_schema' => $template->json_schema,
        'ui_schema' => $template->ui_schema,
        'is_active' => $template->is_active,
        'version_number' => 1,
    ]);
    $template->update(['current_version_id' => $v1->id]);

    $response = $this->putJson("/api/v1/form-templates/{$template->id}", [
        'json_schema' => ['type' => 'not_valid'],
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['json_schema']);
});

test('template update accepts a valid json_schema', function () {
    $template = FormTemplate::factory()->create();
    $v1 = $template->versions()->create([
        'user_id' => $this->user->id,
        'name' => $template->name,
        'json_schema' => $template->json_schema,
        'ui_schema' => $template->ui_schema,
        'is_active' => $template->is_active,
        'version_number' => 1,
    ]);
    $template->update(['current_version_id' => $v1->id]);

    $response = $this->putJson("/api/v1/form-templates/{$template->id}", [
        'json_schema' => typedSchema(),
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Submission: content must match the template's json_schema (store)
// ---------------------------------------------------------------------------

test('submission store rejects content that does not match the template schema', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['name' => 123], // name should be a string
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['content.name']);
});

test('submission store rejects content missing a required field', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['age' => 30], // missing required 'name'
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

test('submission store accepts content matching the template schema', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['name' => 'John', 'age' => 30],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

test('submission store accepts content when template has an empty schema', function () {
    $template = FormTemplate::factory()->create(['json_schema' => []]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => []]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['anything' => 'goes'],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

test('submission store reports field-level errors for nested schema violations', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'address' => [
                'type' => 'object',
                'properties' => [
                    'street' => ['type' => 'string'],
                ],
                'required' => ['street'],
            ],
        ],
        'required' => ['address'],
    ];
    $template = FormTemplate::factory()->create(['json_schema' => $schema]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => $schema]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['address' => ['street' => 99]], // street should be string
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['content.address.street']);
});

test('submission store validates additional optional fields pass when present and correct', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);

    $response = $this->postJson('/api/v1/form-submissions', [
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
        'form_name' => $template->name,
        'content' => ['name' => 'Alice', 'age' => 25],
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertCreated();
});

// ---------------------------------------------------------------------------
// Submission: content must match the template's json_schema (update)
// ---------------------------------------------------------------------------

test('submission update rejects content that does not match the template schema', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
    ]);
    $v1 = $submission->versions()->create([
        'user_id' => $this->user->id,
        'form_name' => $template->name,
        'content' => ['name' => 'John'],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $v1->id]);

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $template->name,
        'content' => ['name' => 456], // name should be a string
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['content.name']);
});

test('submission update accepts content matching the template schema', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
    ]);
    $v1 = $submission->versions()->create([
        'user_id' => $this->user->id,
        'form_name' => $template->name,
        'content' => ['name' => 'John'],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $v1->id]);

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $template->name,
        'content' => ['name' => 'Jane', 'age' => 28],
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertSuccessful();
});

test('submission update rejects content missing required field', function () {
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
    ]);
    $v1 = $submission->versions()->create([
        'user_id' => $this->user->id,
        'form_name' => $template->name,
        'content' => ['name' => 'John'],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $v1->id]);

    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $template->name,
        'content' => ['age' => 30], // missing required 'name'
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

test('submission update validates against the pinned version schema, not the live template', function () {
    // Submission is pinned to v1's schema (name + optional age, name required).
    $template = FormTemplate::factory()->create(['json_schema' => typedSchema()]);
    $version = FormTemplateVersion::factory()->create(['template_id' => $template->id, 'json_schema' => typedSchema()]);
    $submission = FormSubmission::create([
        'form_template_id' => $template->id,
        'form_template_version_id' => $version->id,
    ]);
    $v1 = $submission->versions()->create([
        'user_id' => $this->user->id,
        'form_name' => $template->name,
        'content' => ['name' => 'John'],
        'version_number' => 1,
    ]);
    $submission->update(['current_version_id' => $v1->id]);

    // The live template schema later diverges to require a different field.
    $template->update(['json_schema' => [
        'type' => 'object',
        'properties' => ['email' => ['type' => 'string']],
        'required' => ['email'],
    ]]);

    // Content valid under the pinned v1 schema (but invalid under the live one)
    // must be accepted, because the submission is validated against its pin.
    $response = $this->putJson("/api/v1/form-submissions/{$submission->id}", [
        'form_name' => $template->name,
        'content' => ['name' => 'Jane'],
        'version_number' => 1,
    ], ['Authorization' => "Bearer $this->token"]);

    $response->assertSuccessful();
});
