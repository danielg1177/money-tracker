<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\PlaidItem;
use App\Models\PlaidPendingImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaidSweepMatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     user: User,
     *     import: PlaidPendingImport,
     *     fund: Fund,
     *     fundMovement: FundMovement,
     *     plaidItem: PlaidItem
     * }
     */
    private function createSweepMatchScenario(float $amount = 100.00): array
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $plaidItem = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-'.uniqid('', true),
            'access_token' => 'access-sandbox-test',
            'institution_id' => 'ins_test',
            'institution_name' => 'Test Bank',
            'transactions_cursor' => null,
        ]);

        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => $family->id,
            'name' => 'Family Savings',
        ]);

        $fundMovement = FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'savings_sweep',
            'amount' => $amount,
            'description' => 'Sweep to external savings',
            'plaid_pending_import_id' => null,
        ]);

        $import = PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $plaidItem->id,
            'plaid_transaction_id' => 'plaid-txn-'.uniqid('', true),
            'plaid_account_id' => 'acc1',
            'amount' => $amount,
            'date' => now()->toDateString(),
            'merchant_name' => 'External Transfer',
            'raw_name' => 'EXTERNAL TRANSFER',
            'suggested_category_id' => null,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.0,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        return compact('user', 'import', 'fund', 'fundMovement', 'plaidItem');
    }

    public function test_sweep_candidates_returns_matching_movements(): void
    {
        ['user' => $user, 'import' => $import, 'fund' => $fund, 'fundMovement' => $fundMovement] = $this->createSweepMatchScenario();

        $response = $this->actingAs($user)->getJson("/plaid/pending-imports/{$import->id}/sweep-candidates");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $fundMovement->id,
            'fund_name' => $fund->name,
        ]);
    }

    public function test_sweep_candidates_excludes_already_linked_movements(): void
    {
        ['user' => $user, 'import' => $import, 'plaidItem' => $plaidItem, 'fundMovement' => $fundMovement] = $this->createSweepMatchScenario();

        $otherImport = PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $plaidItem->id,
            'plaid_transaction_id' => 'plaid-txn-other-'.uniqid('', true),
            'plaid_account_id' => 'acc1',
            'amount' => 100.00,
            'date' => now()->toDateString(),
            'merchant_name' => 'Other',
            'raw_name' => 'OTHER',
            'suggested_category_id' => null,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.0,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        $fundMovement->update(['plaid_pending_import_id' => $otherImport->id]);

        $response = $this->actingAs($user)->getJson("/plaid/pending-imports/{$import->id}/sweep-candidates");

        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_link_to_sweep_confirms_import_and_links_movement(): void
    {
        ['user' => $user, 'import' => $import, 'fundMovement' => $fundMovement] = $this->createSweepMatchScenario();

        $response = $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link-to-sweep", [
            'fund_movement_id' => $fundMovement->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'fund_movement_id' => $fundMovement->id,
        ]);

        $import->refresh();
        $fundMovement->refresh();

        $this->assertSame('confirmed', $import->status);
        $this->assertSame($fundMovement->id, $import->fund_movement_id);
        $this->assertSame($import->id, $fundMovement->plaid_pending_import_id);
        $this->assertSame($import->plaid_transaction_id, $fundMovement->plaid_transaction_id);
    }

    public function test_link_to_sweep_rejects_wrong_family_movement(): void
    {
        ['user' => $user, 'import' => $import] = $this->createSweepMatchScenario();

        $otherFamily = Family::factory()->create();
        $otherUser = User::factory()->create(['family_id' => $otherFamily->id]);
        $otherFund = Fund::factory()->create([
            'user_id' => $otherUser->id,
            'family_id' => $otherFamily->id,
        ]);
        $otherMovement = FundMovement::query()->create([
            'fund_id' => $otherFund->id,
            'user_id' => $otherUser->id,
            'type' => 'savings_sweep',
            'amount' => 100.00,
            'plaid_pending_import_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link-to-sweep", [
            'fund_movement_id' => $otherMovement->id,
        ]);

        $response->assertForbidden();
    }

    public function test_link_to_sweep_rejects_already_linked_movement(): void
    {
        ['user' => $user, 'import' => $import, 'fundMovement' => $fundMovement] = $this->createSweepMatchScenario();

        $fundMovement->update(['plaid_pending_import_id' => $import->id]);

        $response = $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link-to-sweep", [
            'fund_movement_id' => $fundMovement->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_link_to_sweep_requires_authentication(): void
    {
        ['import' => $import, 'fundMovement' => $fundMovement] = $this->createSweepMatchScenario();

        $response = $this->post("/plaid/pending-imports/{$import->id}/link-to-sweep", [
            'fund_movement_id' => $fundMovement->id,
        ]);

        $response->assertRedirect();
    }
}
