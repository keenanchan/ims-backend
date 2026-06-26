<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    NotificationFacade::fake();

    $this->user = User::factory()->create();
    $this->token = Auth::guard('api')->tokenById($this->user->id);

    $this->other = User::factory()->create();
    $this->otherToken = Auth::guard('api')->tokenById($this->other->id);

    $submission = FormSubmission::factory()->create([
        'form_template_id' => FormTemplate::factory()->create()->id,
    ]);

    $this->notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'form_submitted',
        'notifiable_type' => FormSubmission::class,
        'notifiable_id' => $submission->id,
        'data' => ['submission_id' => $submission->id, 'form_name' => 'Test'],
    ]);
});

test('user can list their notifications', function (): void {
    $response = $this->getJson('/api/v1/notifications', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->notification->id)
        ->assertJsonPath('data.0.type', 'form_submitted')
        ->assertJsonPath('data.0.read_at', null);
});

test('user only sees their own notifications', function (): void {
    $response = $this->getJson('/api/v1/notifications', [
        'Authorization' => "Bearer {$this->otherToken}",
    ]);

    $response->assertOk()->assertJsonCount(0, 'data');
});

test('user can filter unread notifications', function (): void {
    $this->notification->update(['read_at' => now()]);

    $response = $this->getJson('/api/v1/notifications?unread=1', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()->assertJsonCount(0, 'data');
});

test('user can mark a notification as read', function (): void {
    $response = $this->postJson("/api/v1/notifications/{$this->notification->id}/read", [], [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $this->notification->id);

    $this->assertDatabaseMissing('notifications', [
        'id' => $this->notification->id,
        'read_at' => null,
    ]);
});

test('user cannot mark another users notification as read', function (): void {
    $response = $this->postJson("/api/v1/notifications/{$this->notification->id}/read", [], [
        'Authorization' => "Bearer {$this->otherToken}",
    ]);

    $response->assertForbidden();
});

test('user can mark all notifications as read', function (): void {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'form_approved',
        'notifiable_type' => FormSubmission::class,
        'notifiable_id' => $this->notification->notifiable_id,
        'data' => [],
    ]);

    $this->postJson('/api/v1/notifications/read-all', [], [
        'Authorization' => "Bearer {$this->token}",
    ])->assertOk();

    $this->assertDatabaseCount(
        'notifications',
        Notification::where('user_id', $this->user->id)->whereNull('read_at')->count() === 0 ? 2 : 0
    );

    expect(
        Notification::where('user_id', $this->user->id)->whereNull('read_at')->count()
    )->toBe(0);
});

test('mark-all-read only affects the authenticated user', function (): void {
    $otherNotification = Notification::create([
        'user_id' => $this->other->id,
        'type' => 'form_submitted',
        'notifiable_type' => FormSubmission::class,
        'notifiable_id' => $this->notification->notifiable_id,
        'data' => [],
    ]);

    $this->postJson('/api/v1/notifications/read-all', [], [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $this->assertDatabaseHas('notifications', [
        'id' => $otherNotification->id,
        'read_at' => null,
    ]);
});

test('unauthenticated user cannot access notifications', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});
