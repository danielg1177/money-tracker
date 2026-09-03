<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\MonthHardClose;
use App\Models\Transaction;

class LegacyCloseoutDataBackfill
{
    public function __construct(
        private CloseoutArtifactReconstructor $artifactReconstructor,
    ) {}

    public function run(): void
    {
        $this->backfillFamilyCloseoutModes();
        $this->backfillCloseoutScopes();
        $this->backfillHardCloseSnapshots();
    }

    private function backfillFamilyCloseoutModes(): void
    {
        Family::query()
            ->where(function ($query): void {
                $query->whereNull('closeout_mode')
                    ->orWhere('closeout_mode', '');
            })
            ->update(['closeout_mode' => CloseoutMode::Classic]);
    }

    private function backfillCloseoutScopes(): void
    {
        Transaction::query()
            ->where('is_closeout_initiated', true)
            ->whereNull('closeout_scope')
            ->update(['closeout_scope' => CloseoutScope::User]);
    }

    private function backfillHardCloseSnapshots(): void
    {
        $hardCloses = MonthHardClose::query()
            ->with('family.users')
            ->orderBy('id')
            ->get();

        foreach ($hardCloses as $hardClose) {
            if (is_array($hardClose->results_snapshot) && $hardClose->results_snapshot !== []) {
                continue;
            }

            $family = $hardClose->family;
            if ($family === null) {
                continue;
            }

            $hardClose->update([
                'closeout_mode' => CloseoutMode::normalize($hardClose->closeout_mode),
                'settings_snapshot' => [
                    'version' => CloseoutMode::SettingsSnapshotVersion,
                    'closeout_mode' => CloseoutMode::Classic,
                    'reconstructed' => true,
                    'family_rules' => [],
                    'personal_rules' => [],
                ],
                'results_snapshot' => $this->artifactReconstructor->reconstructForFamily(
                    $family,
                    (int) $hardClose->year,
                    (int) $hardClose->month,
                ),
            ]);
        }
    }
}
