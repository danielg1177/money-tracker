<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_update_user_settings(): void
    {
        $this->putJson('/user/settings', [
            'view_family_expenses' => true,
        ])->assertUnauthorized();
    }

    public function test_user_can_toggle_view_family_expenses(): void
    {
        $user = User::factory()->create([
            'view_family_expenses' => false,
        ]);

        $this->actingAs($user)->putJson('/user/settings', [
            'view_family_expenses' => true,
        ])->assertOk()
            ->assertJsonPath('view_family_expenses', true);

        $this->assertTrue($user->fresh()->view_family_expenses);

        $this->actingAs($user)->getJson('/user')
            ->assertOk()
            ->assertJsonPath('view_family_expenses', true);
    }

    public function test_view_family_expenses_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/user/settings', [
            'view_family_expenses' => 'sometimes',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['view_family_expenses']);
    }

    public function test_user_can_update_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(
            password_verify('new-password-123', $user->fresh()->password)
        );
    }

    public function test_password_update_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
