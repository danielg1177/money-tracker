<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Services\FundService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FundController extends Controller
{
    public function __construct(private readonly FundService $fundService) {}

    public function index()
    {
        $user = auth()->user();

        $personalFunds = $user->funds()
            ->whereNull('family_id')
            ->with(['fundRules', 'movements.user'])
            ->get()
            ->map(fn ($f) => array_merge($f->toArray(), ['scope' => 'personal']))
            ->toBase();

        $familyFunds = collect([]);
        if ($user->family_id) {
            $familyFunds = Fund::query()
                ->where('family_id', $user->family_id)
                ->with(['fundRules', 'movements.user'])
                ->get()
                ->map(fn ($f) => array_merge($f->toArray(), ['scope' => 'family']))
                ->toBase();
        }

        return $personalFunds->merge($familyFunds)->values();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_family_fund' => 'boolean',
            'starting_balance' => 'nullable|numeric|min:0',
        ]);

        $user = auth()->user();
        $data = $request->only(['name', 'description']);
        $startingBalance = (float) ($request->input('starting_balance') ?? 0);

        if ($request->boolean('is_family_fund') && $user->family_id) {
            $data['family_id'] = $user->family_id;
        }

        if ($startingBalance > 0) {
            $data['balance'] = $startingBalance;
        }

        $fund = $user->funds()->create($data);

        if ($startingBalance > 0) {
            FundMovement::query()->create([
                'fund_id' => $fund->id,
                'user_id' => $user->id,
                'type' => 'initial_value',
                'amount' => $startingBalance,
                'description' => 'Initial value set at fund creation',
            ]);
        }

        return $fund->load('movements.user');
    }

    public function update(Request $request, Fund $fund)
    {
        $this->authorize('update', $fund);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $fund->update($request->only(['name', 'description']));

        return $fund;
    }

    public function showRules()
    {
        return FundRule::query()->where('user_id', auth()->id())->orderBy('order')->get();
    }

    public function storeRule(Request $request)
    {
        $validated = $request->validate([
            'fund_id' => 'nullable|exists:funds,id',
            'name' => 'required|string|max:255',
            'order' => 'required|integer|min:1',
            'allocation_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'amount' => 'required|numeric|min:0',
            'allocation_base' => ['nullable', Rule::in(['gross_income', 'net_income', 'remaining'])],
            'is_active' => 'boolean',
            'destination_type' => ['required', Rule::in(['fund', 'debt', 'title'])],
            'destination_id' => 'nullable|integer',
            'destination_title' => 'nullable|string|max:255|required_if:destination_type,title',
        ]);

        return FundRule::create($validated + ['user_id' => auth()->id()]);
    }

    public function updateRule(FundRule $fundRule, Request $request)
    {
        if ($fundRule->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'fund_id' => 'nullable|exists:funds,id',
            'name' => 'required|string|max:255',
            'order' => 'required|integer|min:1',
            'allocation_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'amount' => 'required|numeric|min:0',
            'allocation_base' => ['nullable', Rule::in(['gross_income', 'net_income', 'remaining'])],
            'is_active' => 'boolean',
            'destination_type' => ['required', Rule::in(['fund', 'debt', 'title'])],
            'destination_id' => 'nullable|integer',
            'destination_title' => 'nullable|string|max:255|required_if:destination_type,title',
        ]);

        $fundRule->update($validated);

        return $fundRule;
    }

    public function destroyRule(FundRule $fundRule)
    {
        if ($fundRule->user_id !== auth()->id()) {
            abort(403);
        }

        $fundRule->delete();

        return response()->json(['message' => 'Rule deleted']);
    }

    public function destroy(Fund $fund)
    {
        $this->authorize('delete', $fund);
        $fund->delete();

        return response()->json(['message' => 'Fund deleted']);
    }

    public function borrow(Fund $fund, Request $request)
    {
        $this->authorize('update', $fund);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
        ]);

        try {
            $transaction = $this->fundService->borrowFromFund(
                $fund,
                (float) $request->amount,
                $request->description ?? '',
                auth()->user()
            );

            return response()->json($transaction, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function repayFund(Debt $debt, Request $request)
    {
        if (auth()->user()->id !== $debt->debtor_id) {
            abort(403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $this->fundService->repayFund($debt, (float) $request->amount, auth()->user());

            return response()->json(['message' => 'Fund repayment recorded'], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
