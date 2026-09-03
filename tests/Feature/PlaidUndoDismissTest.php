<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\User;
use App\Services\PlaidMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaidUndoDismissTest extends TestCase
{
    use RefreshDatabase;

    private function familyUser(): User
    {
        $family = Family::factory()->create();

        return User::factory()->create(['family_id' => $family->id]);
    }

    private function createPlaidItem(User $user): PlaidItem
    {
        return PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-'.uniqid('', true),
            'access_token' => 'access-sandbox-test',
            'institution_id' => 'ins_test',
            'institution_name' => 'Test Bank',
            'transactions_cursor' => null,
        ]);
    }

    private function createPendingImportForUser(User $user, string $plaidTxnId = 'txn-pending-1'): PlaidPendingImport
    {
        $item = $this->createPlaidItem($user);
        $category = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        return PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => $plaidTxnId,
            'plaid_account_id' => 'acc1',
            'amount' => 42.5,
            'date' => now()->toDateString(),
            'merchant_name' => 'Corner Store',
            'raw_name' => 'CORNER STORE #1',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_exclude_from_expense_basis' => false,
            'confidence_score' => 0.5,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);
    }

    public function test_undo_dismiss_restores_pending_import_and_deletes_dismiss_rule(): void
    {
        $user = $this->familyUser();
        $import = $this->createPendingImportForUser($user, 'txn-undo-1');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/dismiss-as-transfer?learn=true")
            ->assertNoContent();

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'action' => 'dismiss',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/undo-dismiss");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pending_import.status', 'pending')
            ->assertJsonPath('pending_import.dismiss_source', null);

        $this->assertDatabaseHas('plaid_pending_imports', [
            'id' => $import->id,
            'status' => 'pending',
            'dismiss_source' => null,
        ]);

        $this->assertDatabaseMissing('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'action' => 'dismiss',
        ]);
    }

    public function test_undo_dismiss_returns_422_for_auto_dismissed_import(): void
    {
        $user = $this->familyUser();
        $import = $this->createPendingImportForUser($user, 'txn-undo-auto');

        $import->forceFill([
            'status' => 'dismissed',
            'dismiss_source' => 'auto',
        ])->save();

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/undo-dismiss")
            ->assertStatus(422);
    }

    public function test_undo_dismiss_returns_422_for_pending_import(): void
    {
        $user = $this->familyUser();
        $import = $this->createPendingImportForUser($user, 'txn-undo-pending');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/undo-dismiss")
            ->assertStatus(422);
    }

    public function test_pending_imports_index_includes_manually_dismissed_key(): void
    {
        $user = $this->familyUser();
        $manualImport = $this->createPendingImportForUser($user, 'txn-manual-dismissed');
        $manualImport->forceFill([
            'status' => 'dismissed',
            'dismiss_source' => 'manual',
        ])->save();

        $autoImport = $this->createPendingImportForUser($user, 'txn-auto-dismissed');
        $autoImport->forceFill([
            'status' => 'dismissed',
            'dismiss_source' => 'auto',
        ])->save();

        $pendingImport = $this->createPendingImportForUser($user, 'txn-still-pending');

        $response = $this->actingAs($user)->getJson('/plaid/pending-imports');

        $response->assertOk();

        $manualIds = collect($response->json('manually_dismissed'))->pluck('id')->all();
        $this->assertSame([$manualImport->id], $manualIds);

        $dismissedIds = collect($response->json('dismissed'))->pluck('id')->all();
        $this->assertSame([$autoImport->id], $dismissedIds);

        $pendingIds = collect($response->json('pending'))->pluck('id')->all();
        $this->assertContains($pendingImport->id, $pendingIds);
    }

    public function test_undo_dismiss_does_not_delete_non_dismiss_merchant_rules(): void
    {
        $user = $this->familyUser();
        $import = $this->createPendingImportForUser($user, 'txn-undo-categorize');

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => $key,
            'category_id' => null,
            'type' => 'expense',
            'action' => 'categorize',
            'confirmation_count' => 2,
            'total_seen_count' => 2,
        ]);

        $import->forceFill([
            'status' => 'dismissed',
            'dismiss_source' => 'manual',
        ])->save();

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/undo-dismiss")
            ->assertOk();

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'action' => 'categorize',
        ]);
    }
}
