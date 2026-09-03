<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\MonthHardClose;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CloseoutDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_open_month_and_two_closed_months(): void
    {
        $this->seed(CloseoutDemoSeeder::class);

        $alex = User::query()->where('email', 'alex@demo.test')->first();
        $jordan = User::query()->where('email', 'jordan@demo.test')->first();
        $this->assertNotNull($alex);
        $this->assertNotNull($jordan);
        $this->assertTrue($alex->is_admin);
        $this->assertTrue($alex->can_manage_family);
        $this->assertSame('family_pooled', $alex->family->closeout_mode);

        $now = now();
        $hardCloses = MonthHardClose::query()
            ->where('family_id', $alex->family_id)
            ->orderBy('year')
            ->orderBy('month')
            ->get();
        $this->assertCount(2, $hardCloses);
        $this->assertSame('classic', $hardCloses[0]->closeout_mode);
        $this->assertNotEmpty($hardCloses[0]->results_snapshot);
        $this->assertSame('family_pooled', $hardCloses[1]->closeout_mode);
        $this->assertNotEmpty($hardCloses[1]->results_snapshot);

        $this->assertFalse(
            MonthHardClose::query()
                ->where('family_id', $alex->family_id)
                ->where('year', $now->year)
                ->where('month', $now->month)
                ->exists()
        );

        $openCount = Transaction::query()
            ->where('family_id', $alex->family_id)
            ->whereYear('transaction_date', $now->year)
            ->whereMonth('transaction_date', $now->month)
            ->where('is_closeout_initiated', false)
            ->count();
        $this->assertGreaterThan(8, $openCount);

        $summary = $this->actingAs($alex)
            ->getJson('/month-summary?year='.$now->year.'&month='.$now->month)
            ->assertOk();
        $this->assertSame('family_pooled', $summary->json('closeout_preview.mode'));
        $this->assertSame('live', $summary->json('closeout_preview.source'));
        $this->assertNotEmpty($summary->json('closeout_preview.family.surplus_rules'));

        $classicSummary = $this->actingAs($alex)
            ->getJson('/month-summary?year='.$hardCloses[0]->year.'&month='.$hardCloses[0]->month)
            ->assertOk();
        $this->assertTrue($classicSummary->json('is_hard_closed'));
        $this->assertSame('snapshot', $classicSummary->json('closeout_preview.source'));
        $this->assertSame('classic', $classicSummary->json('closeout_preview.mode'));
    }

    public function test_demo_seeder_is_rerunnable(): void
    {
        $this->seed(CloseoutDemoSeeder::class);
        $this->seed(CloseoutDemoSeeder::class);

        $this->assertSame(1, Family::query()->where('name', 'Closeout Demo')->count());
        $this->assertSame(1, User::query()->where('email', 'alex@demo.test')->count());
    }
}
