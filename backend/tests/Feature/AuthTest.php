<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alvia',
            'username' => 'alvia',
            'email' => 'alvia@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user' => ['id', 'name', 'username', 'email'], 'token']);

        $this->assertDatabaseHas('users', [
            'username' => 'alvia',
            'email' => 'alvia@test.com',
        ]);
    }

    public function test_register_rejects_taken_email(): void
    {
        User::factory()->create(['email' => 'alvia@test.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Alvia',
            'username' => 'alvia-lain',
            'email' => 'alvia@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_with_username(): void
    {
        User::factory()->create([
            'username' => 'alvia',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'login' => 'alvia',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_user_can_login_with_email(): void
    {
        User::factory()->create([
            'email' => 'alvia@test.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'login' => 'alvia@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'alvia',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'login' => 'alvia',
            'password' => 'password-salah',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_user_can_fetch_own_profile_and_logout(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
