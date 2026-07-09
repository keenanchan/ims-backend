<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(RefreshDatabase::class);

test('login is rate limited after 10 attempts per minute', function () {
    /** @var TestCase $this */
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertStatus(429);
});

test('login succeeds while under the rate limit', function () {
    /** @var TestCase $this */
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertStatus(200)
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
});
