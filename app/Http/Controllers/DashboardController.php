<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function monthlyTotals(): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['total_income' => 0.0, 'total_expenses' => 0.0]);
        }

        $year = now()->year;
        $month = now()->month;

        $totalIncome = (float) Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $totalExpenses = (float) Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        return response()->json([
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
        ]);
    }
}
