<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $family = $user->family;

        if (! $family) {
            return [];
        }

        return $family->categories()
            ->with(['userDefaults' => function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            }])
            ->get()
            ->map(function (Category $category): array {
                $defaults = $category->userDefaults->first();

                return [
                    ...$category->toArray(),
                    'advance_fund_id' => $category->is_expense ? $defaults?->advance_fund_id : null,
                    'is_non_necessity_default' => $category->is_expense ? (bool) ($defaults?->is_non_necessity_default ?? false) : false,
                ];
            });
    }

    public function store(StoreCategoryRequest $request)
    {
        $validated = $request->validated();
        $category = Category::create([
            ...$this->categoryPayload($validated),
            'family_id' => Auth::user()->family_id,
        ]);
        $this->upsertCategoryUserDefaults($category, $validated);

        return $this->categoryResponse($category->fresh());
    }

    public function update(StoreCategoryRequest $request, Category $category)
    {
        if ($category->family_id !== Auth::user()->family_id) {
            abort(403);
        }

        $validated = $request->validated();
        $category->update($this->categoryPayload($validated));
        $this->upsertCategoryUserDefaults($category, $validated);

        return $this->categoryResponse($category->fresh());
    }

    public function destroy(Category $category)
    {
        if ($category->family_id !== Auth::user()->family_id) {
            abort(403);
        }

        $category->delete();

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function categoryPayload(array $validated): array
    {
        return collect($validated)->except(['advance_fund_id', 'is_non_necessity_default'])->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function upsertCategoryUserDefaults(Category $category, array $validated): void
    {
        $advanceFundId = $category->is_expense ? ($validated['advance_fund_id'] ?? null) : null;
        $isNonNecessityDefault = $category->is_expense
            && $advanceFundId !== null
            && ! empty($validated['is_non_necessity_default']);

        $category->userDefaults()->updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'advance_fund_id' => $advanceFundId,
                'is_non_necessity_default' => $isNonNecessityDefault,
            ]
        );
    }

    private function categoryResponse(Category $category): array
    {
        $category->load(['userDefaults' => function ($query): void {
            $query->where('user_id', Auth::id());
        }]);

        $defaults = $category->userDefaults->first();

        return [
            ...$category->toArray(),
            'advance_fund_id' => $category->is_expense ? $defaults?->advance_fund_id : null,
            'is_non_necessity_default' => $category->is_expense ? (bool) ($defaults?->is_non_necessity_default ?? false) : false,
        ];
    }
}
