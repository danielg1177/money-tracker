<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyFamilyCloseoutRuleRequest;
use App\Http\Requests\StoreFamilyCloseoutRuleRequest;
use App\Http\Requests\UpdateFamilyCloseoutRuleRequest;
use App\Models\FamilyCloseoutRule;
use Illuminate\Http\JsonResponse;

class FamilyCloseoutRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        $rules = FamilyCloseoutRule::query()
            ->where('family_id', $user->family_id)
            ->orderBy('order')
            ->get();

        return response()->json($rules);
    }

    public function store(StoreFamilyCloseoutRuleRequest $request): JsonResponse
    {
        $rule = FamilyCloseoutRule::query()->create(
            $request->validated() + ['family_id' => auth()->user()->family_id]
        );

        return response()->json($rule, 201);
    }

    public function update(UpdateFamilyCloseoutRuleRequest $request, FamilyCloseoutRule $familyCloseoutRule): JsonResponse
    {
        $familyCloseoutRule->update($request->validated());

        return response()->json($familyCloseoutRule);
    }

    public function destroy(DestroyFamilyCloseoutRuleRequest $request, FamilyCloseoutRule $familyCloseoutRule): JsonResponse
    {
        $familyCloseoutRule->delete();

        return response()->json(['message' => 'Rule deleted']);
    }
}
