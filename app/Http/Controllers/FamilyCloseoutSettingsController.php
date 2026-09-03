<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFamilyCloseoutSettingsRequest;
use App\Models\Family;
use App\Models\User;
use App\Services\Closeout\CloseoutMode;
use Illuminate\Http\JsonResponse;

class FamilyCloseoutSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        return response()->json($this->payload($user->family, $user));
    }

    public function update(UpdateFamilyCloseoutSettingsRequest $request): JsonResponse
    {
        $user = auth()->user();
        $family = $user->family;
        $family->update([
            'closeout_mode' => $request->validated('closeout_mode'),
        ]);

        return response()->json($this->payload($family, $user));
    }

    /**
     * @return array{closeout_mode: string, can_manage: bool, family_rules: mixed}
     */
    private function payload(Family $family, User $user): array
    {
        return [
            'closeout_mode' => CloseoutMode::normalize($family->closeout_mode),
            'can_manage' => (bool) $user->can_manage_family,
            'family_rules' => $family->familyCloseoutRules()
                ->orderBy('order')
                ->get(),
        ];
    }
}
