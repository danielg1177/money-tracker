<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetBidirectionalInterFamilyDebtsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_nets_bidirectional_open_debts_into_one_direction(): void
    {
        $family = Family::factory()->create();
        $memberA = User::factory()->create(['family_id' => $family->id]);
        $memberB = User::factory()->create(['family_id' => $family->id]);

        $forward = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberA->id,
            'creditor_id' => $memberB->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $reverse = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberB->id,
            'creditor_id' => $memberA->id,
            'amount' => 60.00,
            'balance' => 60.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $this->runRepairMigration();

        $forward->refresh();
        $reverse->refresh();

        $this->assertEqualsWithDelta(40.00, (float) $forward->balance, 0.01);
        $this->assertSame('0.00', $reverse->balance);
        $this->assertSame(
            1,
            Debt::query()->where('family_id', $family->id)->where('balance', '>', 0)->count()
        );
    }

    public function test_migration_consolidates_duplicate_same_direction_open_debts(): void
    {
        $family = Family::factory()->create();
        $memberA = User::factory()->create(['family_id' => $family->id]);
        $memberB = User::factory()->create(['family_id' => $family->id]);

        $first = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberA->id,
            'creditor_id' => $memberB->id,
            'amount' => 50.00,
            'balance' => 50.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $second = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberA->id,
            'creditor_id' => $memberB->id,
            'amount' => 30.00,
            'balance' => 30.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $this->runRepairMigration();

        $first->refresh();
        $second->refresh();

        $this->assertEqualsWithDelta(80.00, (float) $first->balance, 0.01);
        $this->assertEqualsWithDelta(80.00, (float) $first->amount, 0.01);
        $this->assertSame('0.00', $second->balance);
    }

    public function test_migration_leaves_single_open_debt_and_pending_splits_alone(): void
    {
        $family = Family::factory()->create();
        $memberA = User::factory()->create(['family_id' => $family->id]);
        $memberB = User::factory()->create(['family_id' => $family->id]);

        $single = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberA->id,
            'creditor_id' => $memberB->id,
            'amount' => 25.00,
            'balance' => 25.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $pending = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberB->id,
            'creditor_id' => $memberA->id,
            'amount' => 10.00,
            'balance' => 10.00,
            'is_pending_closeout' => true,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $this->runRepairMigration();

        $single->refresh();
        $pending->refresh();

        $this->assertEqualsWithDelta(25.00, (float) $single->balance, 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $pending->balance, 0.01);
        $this->assertTrue($pending->is_pending_closeout);
    }

    public function test_migration_fully_cancels_equal_opposite_balances(): void
    {
        $family = Family::factory()->create();
        $memberA = User::factory()->create(['family_id' => $family->id]);
        $memberB = User::factory()->create(['family_id' => $family->id]);

        $forward = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberA->id,
            'creditor_id' => $memberB->id,
            'amount' => 40.00,
            'balance' => 40.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $reverse = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $memberB->id,
            'creditor_id' => $memberA->id,
            'amount' => 40.00,
            'balance' => 40.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
        ]);

        $this->runRepairMigration();

        $forward->refresh();
        $reverse->refresh();

        $this->assertSame('0.00', $forward->balance);
        $this->assertSame('0.00', $reverse->balance);
        $this->assertSame(
            0,
            Debt::query()->where('family_id', $family->id)->where('balance', '>', 0)->count()
        );
    }

    private function runRepairMigration(): void
    {
        $migration = require database_path('migrations/2026_07_14_193432_net_bidirectional_inter_family_debts.php');
        $migration->up();
    }
}
