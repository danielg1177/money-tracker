<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Closeout\LegacyCloseoutDataBackfill;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutLegacyDataBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_freezes_legacy_hard_closes_from_artifacts_not_live_rules(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 125]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 125,
            'description' => 'Closeout transfer to fund: Old save',
            'transaction_date' => '2026-08-31',
            'is_split' => false,
            'is_closeout_initiated' => true,
            'closeout_scope' => null,
        ]);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => 125,
            'description' => 'Closeout rule: Old save (2026-08)',
        ]);

        $hardClose = MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 8,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'closeout_mode' => null,
            'settings_snapshot' => null,
            'results_snapshot' => null,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'New rule',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 90,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        app(LegacyCloseoutDataBackfill::class)->run();

        $hardClose->refresh();
        $this->assertSame('classic', $hardClose->closeout_mode);
        $this->assertTrue($hardClose->settings_snapshot['reconstructed']);
        $this->assertSame([], $hardClose->settings_snapshot['personal_rules']);
        $this->assertEqualsWithDelta(
            125.0,
            (float) $hardClose->results_snapshot['members'][(string) $user->id]['rules'][0]['projected_amount'],
            0.01
        );

        $closeoutTransaction = Transaction::query()
            ->where('is_closeout_initiated', true)
            ->first();
        $this->assertSame('user', $closeoutTransaction->closeout_scope);

        $summary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('snapshot', $summary->json('closeout_preview.source'));
        $this->assertEqualsWithDelta(125.0, (float) $summary->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertNotEquals(900.0, (float) $summary->json('rule_preview.rules.0.projected_amount'));
    }

    public function test_backfill_does_not_overwrite_existing_snapshots(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $existing = [
            'mode' => 'classic',
            'family' => null,
            'members' => [
                (string) $user->id => [
                    'rules' => [
                        ['projected_amount' => 42],
                    ],
                ],
            ],
        ];

        $hardClose = MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 7,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'closeout_mode' => 'classic',
            'settings_snapshot' => ['closeout_mode' => 'classic'],
            'results_snapshot' => $existing,
        ]);

        app(LegacyCloseoutDataBackfill::class)->run();

        $hardClose->refresh();
        $this->assertEqualsWithDelta(
            42.0,
            (float) $hardClose->results_snapshot['members'][(string) $user->id]['rules'][0]['projected_amount'],
            0.01
        );
        $this->assertArrayNotHasKey('reconstructed', $hardClose->settings_snapshot);
    }

    public function test_modes_and_scopes_backfill_does_not_write_snapshots(): void
    {
        $family = Family::factory()->create(['closeout_mode' => '']);
        $user = User::factory()->create(['family_id' => $family->id]);

        $transaction = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 10,
            'transaction_date' => '2026-08-31',
            'is_split' => false,
            'is_closeout_initiated' => true,
            'closeout_scope' => null,
        ]);

        $hardClose = MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 8,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'closeout_mode' => null,
            'settings_snapshot' => null,
            'results_snapshot' => null,
        ]);

        app(LegacyCloseoutDataBackfill::class)->backfillModesAndScopes();

        $this->assertSame('classic', $family->fresh()->closeout_mode);
        $this->assertSame('user', $transaction->fresh()->closeout_scope);
        $this->assertNull($hardClose->fresh()->results_snapshot);
    }
}
