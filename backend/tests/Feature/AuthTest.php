<?php

namespace Tests\Feature;

use App\Models\CaptureJob;
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

    public function test_protected_endpoints_require_a_token(): void
    {
        foreach (['/api/egg/status', '/api/egg/history', '/api/egg/job/1', '/api/candling/settings', '/api/incubator/latest', '/api/incubator/history', '/api/incubator/photo/test.jpg', '/api/incubator/control'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        foreach (['/api/egg/capture', '/api/candling/settings', '/api/incubator/control'] as $url) {
            $this->postJson($url)->assertUnauthorized();
        }
    }

    public function test_shared_candling_settings_are_validated_and_used_for_captures(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];
        $defaults = ['exposure' => 500, 'gain' => 0, 'quality' => 4, 'settle_ms' => 350];

        $this->getJson('/api/candling/settings', $headers)->assertOk()->assertExactJson($defaults);
        $this->postJson('/api/candling/settings', ['quality' => 3], $headers)->assertUnprocessable()->assertJsonValidationErrors('quality');
        $this->postJson('/api/candling/settings', ['exposure' => 600, 'gain' => 5], $headers)->assertOk()
            ->assertJson(['exposure' => 600, 'gain' => 5, 'quality' => 4, 'settle_ms' => 350]);
        $this->getJson('/api/candling/settings', $headers)->assertJsonPath('exposure', 600);

        $id = $this->postJson('/api/egg/capture', ['gain' => 7], $headers)->assertOk()->json('job');
        $job = CaptureJob::findOrFail($id);
        $this->assertEquals([600, 7, 4, 350], [$job->exposure, $job->gain, $job->quality, $job->settle_ms]);
        $this->getJson('/api/egg/job/'.$id, $headers)->assertOk();
        $this->getJson('/api/egg/status', $headers)->assertOk();
        $this->getJson('/api/egg/history', $headers)->assertOk();
    }
}
