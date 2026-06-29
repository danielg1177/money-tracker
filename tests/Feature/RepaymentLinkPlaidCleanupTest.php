<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\PlaidItem;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\TransactionRepaymentLink;
use App\Models\User;
use App\Services\TransactionRepaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepaymentLinkPlaidCleanupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     family: Family,
     *     userA: User,
     *     userB: User,
     *     incomeTransaction: Transaction,
     *     userAConfirmedImport: PlaidPendingImport,
     *     userBLinkedImport: PlaidPendingImport,
     * }
     */
    private function setUpRepaymentWithSiblingPlaidImport(): array
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        $expense100 = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 100,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $expense50 = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $incomeTransaction = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $incomeCategory->id,
            'type' => 'income',
            'amount' => 150,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        app(TransactionRepaymentService::class)->createRepaymentLinks(
            $incomeTransaction,
            [
                ['transaction_id' => $expense100->id, 'amount' => 100],
                ['transaction_id' => $expense50->id, 'amount' => 50],
            ],
            $userB,
        );

        $mirrorFor100 = TransactionRepaymentLink::query()
            ->where('repayment_transaction_id', $incomeTransaction->id)
            ->where('repaid_transaction_id', $expense100->id)
            ->value('mirror_transaction_id');

        $plaidItemA = PlaidItem::query()->create([
            'user_id' => $userA->id,
            'item_id' => 'item-user-a-repay',
            'access_token' => 'access-sandbox-test',
            'institution_id' => 'ins_test',
            'institution_name' => 'Test Bank',
            'transactions_cursor' => null,
        ]);

        $userAConfirmedImport = PlaidPendingImport::query()->create([
            'user_id' => $userA->id,
            'plaid_item_id' => $plaidItemA->id,
            'plaid_transaction_id' => 'txn-user-a-repay-income',
            'plaid_account_id' => 'acc-a',
            'amount' => 150,
            'date' => '2026-05-10',
            'merchant_name' => 'Repayment income',
            'raw_name' => 'REPAYMENT INCOME',
            'suggested_category_id' => $incomeCategory->id,
            'suggested_type' => 'income',
            'status' => 'confirmed',
            'transaction_id' => $incomeTransaction->id,
            'raw_payload' => [],
        ]);

        $plaidItemB = PlaidItem::query()->create([
            'user_id' => $userB->id,
            'item_id' => 'item-user-b-mirror',
            'access_token' => 'access-sandbox-test',
            'institution_id' => 'ins_test',
            'institution_name' => 'Test Bank',
            'transactions_cursor' => null,
        ]);

        $userBLinkedImport = PlaidPendingImport::query()->create([
            'user_id' => $userB->id,
            'plaid_item_id' => $plaidItemB->id,
            'plaid_transaction_id' => 'txn-user-b-mirror-expense',
            'plaid_account_id' => 'acc-b',
            'amount' => 100,
            'date' => '2026-05-10',
            'merchant_name' => 'Mirror expense',
            'raw_name' => 'MIRROR EXPENSE',
            'suggested_category_id' => $expenseCategory->id,
            'suggested_type' => 'expense',
            'status' => 'auto_linked',
            'transaction_id' => $mirrorFor100,
            'raw_payload' => [],
        ]);

        return [
            'family' => $family,
            'userA' => $userA,
            'userB' => $userB,
            'incomeTransaction' => $incomeTransaction,
            'userAConfirmedImport' => $userAConfirmedImport,
            'userBLinkedImport' => $userBLinkedImport,
        ];
    }

    public function test_undo_confirm_resets_sibling_pending_import_to_pending(): void
    {
        [
            'userA' => $userA,
            'userAConfirmedImport' => $userAConfirmedImport,
            'userBLinkedImport' => $userBLinkedImport,
        ] = $this->setUpRepaymentWithSiblingPlaidImport();

        $this->actingAs($userA)
            ->postJson("/plaid/pending-imports/{$userAConfirmedImport->id}/undo-confirm", [])
            ->assertOk();

        $this->assertDatabaseHas('plaid_pending_imports', [
            'id' => $userBLinkedImport->id,
            'status' => 'pending',
            'transaction_id' => null,
        ]);
    }

    public function test_deleting_repayment_income_transaction_resets_sibling_pending_import(): void
    {
        [
            'userA' => $userA,
            'incomeTransaction' => $incomeTransaction,
            'userBLinkedImport' => $userBLinkedImport,
        ] = $this->setUpRepaymentWithSiblingPlaidImport();

        $this->actingAs($userA)
            ->deleteJson("/transactions/{$incomeTransaction->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('plaid_pending_imports', [
            'id' => $userBLinkedImport->id,
            'status' => 'pending',
            'transaction_id' => null,
        ]);
    }
}
