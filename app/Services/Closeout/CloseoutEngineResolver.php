<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\FundRule;

class CloseoutEngineResolver
{
    public function __construct(
        private ClassicCloseoutEngine $classic,
        private FamilyPooledCloseoutEngine $familyPooled,
    ) {}

    public function for(Family $family): CloseoutEngine
    {
        if (CloseoutMode::isFamilyPooled($family->closeout_mode)) {
            return $this->familyPooled;
        }

        return $this->classic;
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsSnapshot(Family $family): array
    {
        $family->loadMissing('users');
        $userIds = $family->users->pluck('id');

        $personalRules = FundRule::query()
            ->whereIn('user_id', $userIds)
            ->orderBy('order')
            ->get()
            ->groupBy(fn (FundRule $rule): string => (string) $rule->user_id);

        $personalRulesByUser = [];
        foreach ($family->users as $user) {
            $personalRulesByUser[(string) $user->id] = $personalRules
                ->get((string) $user->id, collect())
                ->values()
                ->map(fn (FundRule $rule): array => $rule->toArray())
                ->all();
        }

        return [
            'version' => CloseoutMode::SettingsSnapshotVersion,
            'closeout_mode' => CloseoutMode::normalize($family->closeout_mode),
            'family_rules' => $family->familyCloseoutRules()
                ->orderBy('order')
                ->get()
                ->toArray(),
            'personal_rules' => $personalRulesByUser,
        ];
    }
}
