<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BankBalanceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\FamilyCloseoutRuleController;
use App\Http\Controllers\FamilyCloseoutSettingsController;
use App\Http\Controllers\FundController;
use App\Http\Controllers\MonthCloseoutController;
use App\Http\Controllers\MonthSummaryController;
use App\Http\Controllers\PlaidController;
use App\Http\Controllers\PlaidImportController;
use App\Http\Controllers\PlaidWebhookController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

Route::view('/login', 'app')->name('login');

Route::post('/plaid/webhook', PlaidWebhookController::class);

Route::view('/dashboard', 'app');

Route::view('/categories', 'app');

Route::view('/admin/categories', 'app');

Route::view('/my-family', 'app');

Route::view('/debts', 'app');

Route::view('/bank-connections', 'app');

// SPA shells (Plaid import & calibration)
Route::view('/plaid/import-review', 'app');
Route::view('/plaid/calibrate/{itemId}', 'app');

Route::view('/month-summary/{yearMonth}', 'app');

Route::view('/settings', 'app');

Route::middleware(['auth'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->expectsJson()
            ? $request->user()
            : view('app');
    });
    Route::put('/user/settings', [UserSettingsController::class, 'update']);

    Route::get('/transactions/repayable-expenses', [TransactionController::class, 'repayableExpenses']);

    Route::get('/transactions', function (Request $request) {
        return $request->expectsJson()
            ? app(TransactionController::class)->index($request)
            : view('app');
    });
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);
    Route::post('/transactions/{transaction}/debt-payment-benefit', [TransactionController::class, 'storeDebtPaymentBenefit']);
    Route::put('/transactions/{transaction}/debt-payment-benefit', [TransactionController::class, 'updateDebtPaymentBenefit']);
    Route::delete('/transactions/{transaction}/debt-payment-benefit', [TransactionController::class, 'destroyDebtPaymentBenefit']);

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

    Route::get('/family/closeout-settings', function (Request $request) {
        return $request->expectsJson()
            ? app(FamilyCloseoutSettingsController::class)->show()
            : view('app');
    });
    Route::put('/family/closeout-settings', [FamilyCloseoutSettingsController::class, 'update']);
    Route::get('/family/closeout-rules', function (Request $request) {
        return $request->expectsJson()
            ? app(FamilyCloseoutRuleController::class)->index()
            : view('app');
    });
    Route::post('/family/closeout-rules', [FamilyCloseoutRuleController::class, 'store']);
    Route::put('/family/closeout-rules/{familyCloseoutRule}', [FamilyCloseoutRuleController::class, 'update']);
    Route::delete('/family/closeout-rules/{familyCloseoutRule}', [FamilyCloseoutRuleController::class, 'destroy']);

    // Backward compatibility
    Route::get('/funds/{fund}/rules', function (Request $request) {
        return $request->expectsJson()
            ? app(FundController::class)->showRules()
            : view('app');
    });

    Route::post('/funds/{fund}/borrow', [FundController::class, 'borrow']);
    Route::post('/funds/{fund}/sweep', [FundController::class, 'sweep']);
    Route::post('/funds/{fund}/override', [FundController::class, 'overrideBalance']);

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
    Route::post('/categories/sync-plaid-rules', [CategoryController::class, 'syncPlaidMerchantRules']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('/dashboard/monthly-totals', [DashboardController::class, 'monthlyTotals']);
    Route::get('/bank-balance', [BankBalanceController::class, 'show']);
    Route::put('/bank-balance', [BankBalanceController::class, 'update']);
    Route::post('/title-savings/{id}/complete', [BankBalanceController::class, 'completeTitleSaving']);
    Route::delete('/title-savings/{id}/complete', [BankBalanceController::class, 'incompleteTitleSaving']);

    Route::prefix('plaid')->group(function (): void {
        // Link, items, sync, import review, calibration (auth JSON under /plaid/*)
        Route::get('/link-token', [PlaidController::class, 'linkToken']);
        Route::post('/exchange', [PlaidController::class, 'exchange']);
        Route::get('/items', [PlaidController::class, 'items']);
        Route::post('/sync-month', [PlaidImportController::class, 'syncAllMonths']);
        Route::post('/sync-last-month', [PlaidImportController::class, 'syncAllLastMonth']);
        Route::get('/pending-imports', [PlaidImportController::class, 'index']);
        Route::get('/pending-imports/{pendingImport}/ledger-candidates', [PlaidImportController::class, 'ledgerLinkCandidates']);
        Route::get('/pending-imports/{pendingImport}/linked-transactions', [PlaidImportController::class, 'linkedTransactions']);
        Route::get('/pending-imports/{pendingImport}/split-link-candidates', [PlaidImportController::class, 'splitLinkCandidates']);
        Route::post('/pending-imports/{pendingImport}/link', [PlaidImportController::class, 'linkToLedger']);
        Route::get('/pending-imports/{pendingImport}/sweep-candidates', [PlaidImportController::class, 'sweepCandidates']);
        Route::post('/pending-imports/{pendingImport}/link-to-sweep', [PlaidImportController::class, 'linkToSweep']);
        Route::post('/pending-imports/{pendingImport}/confirm', [PlaidImportController::class, 'confirm']);
        Route::post('/pending-imports/{pendingImport}/confirm-split', [PlaidImportController::class, 'confirmSplit']);
        Route::post('/pending-imports/{pendingImport}/dismiss', [PlaidImportController::class, 'dismiss']);
        Route::post('/pending-imports/{pendingImport}/dismiss-as-transfer', [PlaidImportController::class, 'dismissAsTransfer']);
        Route::post('/pending-imports/{pendingImport}/undo-dismiss', [PlaidImportController::class, 'undoDismiss']);
        Route::post('/pending-imports/{pendingImport}/undo-confirm', [PlaidImportController::class, 'undoConfirm']);
        Route::post('/pending-imports/{pendingImport}/approve-auto-created', [PlaidImportController::class, 'approveAutoCreated']);
        Route::post('/pending-imports/{pendingImport}/approve-auto-linked', [PlaidImportController::class, 'approveAutoLinked']);
        Route::post('/pending-imports/{pendingImport}/reject-auto-linked', [PlaidImportController::class, 'rejectAutoLinked']);
        Route::post('/pending-imports/{pendingImport}/correct-auto-created', [PlaidImportController::class, 'correctAutoCreated']);
        Route::post('/pending-imports/{pendingImport}/acknowledge-auto-dismiss', [PlaidImportController::class, 'acknowledgeAutoDismiss']);
        Route::post('/pending-imports/{pendingImport}/restore-from-dismiss', [PlaidImportController::class, 'restoreFromDismiss']);
        Route::get('/items/{plaidItem}/calibrate', [PlaidImportController::class, 'calibrationData']);
        Route::post('/items/{plaidItem}/calibrate', [PlaidImportController::class, 'applyCalibration']);
        Route::post('/items/{plaidItem}/sync-month', [PlaidImportController::class, 'syncMonth']);
        Route::post('/items/{plaidItem}/sync-last-month', [PlaidImportController::class, 'syncLastMonth']);
        Route::post('/items/{plaidItem}/sync', [PlaidController::class, 'sync']);
        Route::delete('/items/{plaidItem}', [PlaidController::class, 'destroy']);
    });

    Route::post('/closeout/status', [MonthCloseoutController::class, 'status']);
    Route::post('/closeout/soft-close', [MonthCloseoutController::class, 'softClose']);
    Route::post('/closeout/undo-soft-close', [MonthCloseoutController::class, 'undoSoftClose']);
    Route::post('/closeout/hard-close', [MonthCloseoutController::class, 'hardClose']);
    Route::post('/closeout/undo-hard-close', [MonthCloseoutController::class, 'undoHardClose']);
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
