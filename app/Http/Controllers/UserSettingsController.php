<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserSettingsRequest;
use Illuminate\Http\JsonResponse;

class UserSettingsController extends Controller
{
    /**
     * Update the authenticated user's settings preferences.
     */
    public function update(UpdateUserSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'view_family_expenses' => $request->boolean('view_family_expenses'),
        ])->save();

        $user->refresh();

        return response()->json($user);
    }
}
