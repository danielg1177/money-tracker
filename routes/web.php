<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BankBalanceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\FundController;
use App\Http\Controllers\MonthCloseoutController;
use App\Http\Controllers\MonthSummaryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

Route::view('/login', 'app')->name('login');

Route::view('/dashboard', 'app');

Route::view('/categories', 'app');

Route::view('/admin/categories', 'app');

Route::view('/my-family', 'app');

Route::view('/debts', 'app');

Route::view('/month-summary/{yearMonth}', 'app');

Route::middleware(['auth'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->expectsJson()
            ? auth()->user()
            : view('app');
    });

    Route::get('/transactions', function (Request $request) {
        return $request->expectsJson()
            ? app(TransactionController::class)->index($request)
            : view('app');
    });
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);

    Route::get('/family/users', function (Request $request) {
        return $request->expectsJson()
            ? (function () {
                $user = auth()->user();
                if (! $user->family) {
                    return [];
                }

                return $user->family->users;
            })()
            : view('app');
    });

    Route::get('/funds', function (Request $request) {
        return $request->expectsJson()
            ? app(FundController::class)->index()
            : view('app');
    });
    Route::post('/funds', [FundController::class, 'store']);
    Route::put('/funds/{fund}', [FundController::class, 'update']);
    Route::delete('/funds/{fund}', [FundController::class, 'destroy']);

    // Closeout rules (replaces fund-specific rule routes)
    Route::get('/closeout-rules', function (Request $request) {
        return $request->expectsJson()
            ? app(FundController::class)->showRules()
            : view('app');
    });
    Route::post('/closeout-rules', [FundController::class, 'storeRule']);
    Route::put('/closeout-rules/{fundRule}', [FundController::class, 'updateRule']);
    Route::delete('/closeout-rules/{fundRule}', [FundController::class, 'destroyRule']);

    // Backward compatibility
    Route::get('/funds/{fund}/rules', function (Request $request) {
        return $request->expectsJson()
            ? app(FundController::class)->showRules()
            : view('app');
    });

    Route::post('/funds/{fund}/borrow', [FundController::class, 'borrow']);

    Route::get('/debts', function (Request $request) {
        return $request->expectsJson()
            ? app(DebtController::class)->index()
            : view('app');
    });
    Route::post('/debts', [DebtController::class, 'store']);
    Route::put('/debts/{debt}', [DebtController::class, 'update']);
    Route::delete('/debts/{debt}', [DebtController::class, 'destroy']);
    Route::post('/debts/pay', [DebtController::class, 'payDebt']);
    Route::get('/debts/{debt}/payments', [DebtController::class, 'paymentHistory']);
    Route::get('/split-debt-summary', fn (Request $r) => $r->expectsJson() ? app(DebtController::class)->splitDebtSummary($r) : view('app'));
    Route::post('/debts/{debt}/repay-fund', [FundController::class, 'repayFund']);

    Route::get('/month-summary', function (Request $request) {
        return $request->expectsJson()
            ? app(MonthSummaryController::class)->show($request)
            : view('app');
    });

    Route::get('/categories', function (Request $request) {
        return $request->expectsJson()
            ? app(CategoryController::class)->index()
            : view('app');
    });
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('/dashboard/monthly-totals', [DashboardController::class, 'monthlyTotals']);
    Route::get('/bank-balance', [BankBalanceController::class, 'show']);
    Route::put('/bank-balance', [BankBalanceController::class, 'update']);
    Route::post('/title-savings/{id}/complete', [BankBalanceController::class, 'completeTitleSaving']);
    Route::delete('/title-savings/{id}/complete', [BankBalanceController::class, 'incompleteTitleSaving']);

    Route::post('/closeout/status', [MonthCloseoutController::class, 'status']);
    Route::post('/closeout/soft-close', [MonthCloseoutController::class, 'softClose']);
    Route::post('/closeout/undo-soft-close', [MonthCloseoutController::class, 'undoSoftClose']);
    Route::post('/closeout/hard-close', [MonthCloseoutController::class, 'hardClose']);
    Route::get('/closeout/closed-months', fn (Request $r) => $r->expectsJson() ? app(MonthCloseoutController::class)->closedMonths($r) : view('app'));

    Route::middleware(['can:admin'])->group(function () {
        Route::get('/admin/users', function (Request $request) {
            return $request->expectsJson()
                ? app(AdminController::class)->users()
                : view('app');
        });
        Route::post('/admin/users', [AdminController::class, 'createUser']);
        Route::put('/admin/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/admin/users/{user}', [AdminController::class, 'deleteUser']);

        Route::get('/admin/families', function (Request $request) {
            return $request->expectsJson()
                ? app(AdminController::class)->families()
                : view('app');
        });
        Route::post('/admin/families', [AdminController::class, 'createFamily']);
    });

    Route::middleware(['can:manage_family'])->group(function () {
        Route::put('/admin/families/{family}', [AdminController::class, 'updateFamily']);
        Route::delete('/admin/families/{family}', [AdminController::class, 'deleteFamily']);
        Route::post('/admin/families/{family}/users', [AdminController::class, 'addFamilyMember']);
        Route::delete('/admin/families/{family}/users/{user}', [AdminController::class, 'removeFamilyMember']);

        Route::get('/my-family', function (Request $request) {
            return $request->expectsJson()
                ? app(AdminController::class)->myFamily()
                : view('app');
        });
    });
});
