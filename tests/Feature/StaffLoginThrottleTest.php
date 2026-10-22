<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function activeSuperAdmin(): User
    {
        return User::factory()->create([
            'password' => Hash::make('correct-password'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_repeated_failed_staff_logins_are_throttled_even_with_the_right_password(): void
    {
        $user = $this->activeSuperAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_throttling_is_per_email_and_a_successful_login_resets_the_count(): void
    {
        $user = $this->activeSuperAdmin();
        $other = $this->activeSuperAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => $other->email, 'password' => 'correct-password']);
        $this->assertAuthenticatedAs($other);
        $this->post(route('logout'));

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('login'), ['email' => $other->email, 'password' => 'wrong-password']);
        }
        $this->post(route('login'), ['email' => $other->email, 'password' => 'correct-password']);
        $this->assertAuthenticatedAs($other);
    }
}
