<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\Fund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyCloseoutModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_of_household_can_switch_closeout_mode(): void
    {
        $family = Family::factory()->create(['closeout_mode' => 'classic']);
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);

        $this->actingAs($user)
            ->getJson('/family/closeout-settings')
            ->assertOk()
            ->assertJson([
                'closeout_mode' => 'classic',
                'can_manage' => true,
            ]);

        $this->actingAs($user)
            ->getJson('/user')
            ->assertOk()
            ->assertJsonPath('closeout_mode', 'classic');

        $this->actingAs($user)
            ->putJson('/family/closeout-settings', ['closeout_mode' => 'family_pooled'])
            ->assertOk()
            ->assertJson(['closeout_mode' => 'family_pooled']);

        $this->assertSame('family_pooled', $family->fresh()->closeout_mode);

        $user->unsetRelation('family');

        $this->actingAs($user)
            ->getJson('/user')
            ->assertOk()
            ->assertJsonPath('closeout_mode', 'family_pooled');
    }

    public function test_member_cannot_switch_closeout_mode(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        $this->actingAs($user)
            ->putJson('/family/closeout-settings', ['closeout_mode' => 'family_pooled'])
            ->assertForbidden();
    }

    public function test_family_closeout_rules_crud_requires_can_manage_family(): void
    {
        $family = Family::factory()->create();
        $head = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $head->id,
            'family_id' => $family->id,
        ]);

        $this->actingAs($member)->postJson('/family/closeout-rules', [
            'name' => 'Charity',
            'order' => 1,
            'stage' => 'surplus',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
        ])->assertForbidden();

        $created = $this->actingAs($head)->postJson('/family/closeout-rules', [
            'name' => 'Charity',
            'order' => 1,
            'stage' => 'surplus',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
        ])->assertCreated();

        $ruleId = $created->json('id');
        $this->assertNotNull($ruleId);

        $this->actingAs($head)->putJson("/family/closeout-rules/{$ruleId}", [
            'name' => 'Charity 10',
            'order' => 1,
            'stage' => 'surplus',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
        ])->assertOk();

        $this->assertSame('Charity 10', FamilyCloseoutRule::query()->find($ruleId)?->name);

        $this->actingAs($member)->deleteJson("/family/closeout-rules/{$ruleId}")->assertForbidden();
        $this->actingAs($head)->deleteJson("/family/closeout-rules/{$ruleId}")->assertOk();
        $this->assertNull(FamilyCloseoutRule::query()->find($ruleId));
    }

    public function test_cannot_mutate_another_familys_closeout_rule(): void
    {
        $familyA = Family::factory()->create();
        $headA = User::factory()->create([
            'family_id' => $familyA->id,
            'role' => 'head_of_household',
        ]);
        $familyB = Family::factory()->create();
        $headB = User::factory()->create([
            'family_id' => $familyB->id,
            'role' => 'head_of_household',
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $headA->id,
            'family_id' => $familyA->id,
        ]);

        $rule = FamilyCloseoutRule::factory()->create([
            'family_id' => $familyA->id,
            'name' => 'Original',
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        $payload = [
            'name' => 'Hijacked',
            'order' => 1,
            'stage' => FamilyCloseoutRule::StageSurplus,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
        ];

        $this->actingAs($headB)
            ->putJson("/family/closeout-rules/{$rule->id}", $payload)
            ->assertNotFound();

        $this->actingAs($headB)
            ->deleteJson("/family/closeout-rules/{$rule->id}")
            ->assertNotFound();

        $this->assertSame('Original', $rule->fresh()->name);
    }
}
