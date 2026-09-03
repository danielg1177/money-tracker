<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\SyncPlaidMerchantRulesFromCategoriesRequest;
use App\Models\Category;
use App\Services\Closeout\CloseoutMode;
use App\Services\PlaidMerchantRuleCategorySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function __construct(private PlaidMerchantRuleCategorySync $plaidMerchantRuleCategorySync) {}

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
                    'exclude_from_expense_basis_default' => $category->is_expense ? (bool) ($defaults?->exclude_from_expense_basis_default ?? false) : false,
                    'is_necessity_default' => $category->is_expense ? (bool) $category->is_necessity_default : true,
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
        $this->plaidMerchantRuleCategorySync->syncFamilyCategory($category, Auth::user());

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
        $this->plaidMerchantRuleCategorySync->syncFamilyCategory($category, Auth::user());

        return $this->categoryResponse($category->fresh());
    }

    public function syncPlaidMerchantRules(SyncPlaidMerchantRulesFromCategoriesRequest $request): JsonResponse
    {
        return response()->json(
            $this->plaidMerchantRuleCategorySync->syncUser($request->user())
        );
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
        $payload = collect($validated)->except(['advance_fund_id', 'exclude_from_expense_basis_default'])->all();
        $isExpense = (bool) ($payload['is_expense'] ?? false);
        $payload['is_necessity_default'] = $isExpense
            ? (! array_key_exists('is_necessity_default', $validated)
                || filter_var($validated['is_necessity_default'], FILTER_VALIDATE_BOOLEAN))
            : true;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function upsertCategoryUserDefaults(Category $category, array $validated): void
    {
        $advanceFundId = $category->is_expense ? ($validated['advance_fund_id'] ?? null) : null;
        $user = Auth::user();
        $excludeFromExpenseBasisDefault = $category->is_expense
            && $advanceFundId !== null
            && ! empty($validated['exclude_from_expense_basis_default']);

        if (
            $category->is_expense
            && $advanceFundId !== null
            && CloseoutMode::isFamilyPooled($user?->closeout_mode)
        ) {
            $existing = $category->userDefaults()
                ->where('user_id', $user->id)
                ->first();
            $excludeFromExpenseBasisDefault = (bool) ($existing?->exclude_from_expense_basis_default);
        }

        $category->userDefaults()->updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'advance_fund_id' => $advanceFundId,
                'exclude_from_expense_basis_default' => $excludeFromExpenseBasisDefault,
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
            'exclude_from_expense_basis_default' => $category->is_expense ? (bool) ($defaults?->exclude_from_expense_basis_default ?? false) : false,
            'is_necessity_default' => $category->is_expense ? (bool) $category->is_necessity_default : true,
        ];
    }
}
