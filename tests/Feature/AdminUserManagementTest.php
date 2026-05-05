<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Admin can optionally reset a user's password from the user edit endpoint.
     */
    public function test_admin_can_update_user_with_new_password(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'role' => 'member',
        ]);
        $managedUser = User::factory()->create([
            'password' => Hash::make('old-password'),
            'role' => 'member',
        ]);

        $this->actingAs($admin)->putJson("/admin/users/{$managedUser->id}", [
            'name' => $managedUser->name,
            'email' => $managedUser->email,
            'family_id' => $managedUser->family_id,
            'role' => $managedUser->role,
            'is_admin' => false,
            'password' => 'new-password',
        ])->assertOk();

        $managedUser->refresh();

        $this->assertTrue(Hash::check('new-password', $managedUser->password));
    }

    public function test_admin_update_keeps_existing_password_when_password_is_blank(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'role' => 'member',
        ]);
        $managedUser = User::factory()->create([
            'password' => Hash::make('existing-password'),
            'role' => 'member',
        ]);

        $this->actingAs($admin)->putJson("/admin/users/{$managedUser->id}", [
            'name' => $managedUser->name,
            'email' => $managedUser->email,
            'family_id' => $managedUser->family_id,
            'role' => $managedUser->role,
            'is_admin' => false,
            'password' => '',
        ])->assertOk();

        $managedUser->refresh();

        $this->assertTrue(Hash::check('existing-password', $managedUser->password));
    }
}
