# 01 — Architecture

## High-level pattern

**Hybrid SPA / JSON API on a single Laravel app.**

There is no separate API server. The frontend Vue SPA and the backend Laravel app are served from the same process. Routes in `routes/web.php` serve either:

1. The `app` Blade view (SPA shell with `<div id="app">`) for browser navigation, OR
2. A JSON response when the request includes `Accept: application/json` (which Axios sends automatically).

This pattern is used for every GET endpoint:

```php
Route::get('/transactions', function (Request $request) {
    return $request->expectsJson()
        ? app(TransactionController::class)->index($request)
        : view('app');
});
```

POST/PUT/DELETE routes always return JSON directly.

## Authentication

Session-based authentication via **Laravel Fortify**. The SPA uses Axios with CSRF token from the `<meta name="csrf-token">` tag in `app.blade.php`. There are no API tokens or JWT.

On login, Fortify sets a session cookie. The Vue app calls `GET /user` (JSON) to fetch the user and stores it in **localStorage** for UI decisions (auth guards, admin menu). The actual auth enforcement is always server-side via the `auth` middleware.

## Request lifecycle (authenticated JSON)

```
Browser (Vue) → Axios (w/ CSRF + session cookie)
  → Laravel web.php route
    → auth middleware (session check)
    → Controller → Service → Eloquent
    → JSON response
  → Vue composable (useApi) updates reactive state
```

## Frontend architecture

**Primary client:** The SPA is intended to be used **mostly on mobile** (phones, small touch screens). Layout, navigation (e.g. bottom bar + FAB in `AppNav.vue`), and Tailwind breakpoints should assume mobile-first; larger screens are progressive enhancement.

```
app.js
  └── createApp(AppShell)
        ├── router (vue-router, createWebHistory)
        └── AppShell.vue
              ├── AppNav.vue (if user is logged in)
              │     ├── bottom nav links
              │     ├── admin menu (if isAdmin)
              │     └── FAB → TransactionForm modal
              └── <router-view> (page components)
```

The SPA is entirely rendered client-side after the initial `app.blade.php` shell loads. Vue Router uses HTML5 history mode — all paths that should render the SPA are explicitly registered as `Route::view(..., 'app')` in `web.php` so Laravel doesn't 404 on direct navigation or page refresh.

## Backend layer separation

```
routes/web.php
  └── Controllers (thin — validate, call service, return JSON)
        └── Services (business logic — DB transactions, cross-model writes)
              └── Eloquent Models (data access + relationships)
```

**Service classes:**
- `TransactionService` — create/update transactions with splits and debt creation
- `FundService` — borrow from fund, repay fund debt; includes `processIncome` (not called from `TransactionService` today)
- `MonthCloseoutService` — month hard-close workflow; applies user `FundRule` allocations and related debt/title moves; also provides `getMonthStatus` for month summaries
- `DebtService` — pay a debt (creates expense + income transactions, reduces balance)
- `SplitCalculator` — pure static utility for percentage validation and amount distribution

**Read-only controllers:**
- `MonthSummaryController::show` — returns comprehensive month overview (close status, category totals including optional synthetic **Uncategorized Debt Payments** for repayments without a category, member balances, rule preview) without modifying data; **`rule_preview.basis.total_expenses`** aligns with **`MonthCloseoutService::expenseTotalTowardRemainingBasis`** (tracked repayments included; non-necessity advance expenses excluded from the basis and settled via fund advances). **`rule_preview.basis.remaining_after_expenses`** is the **signed** post-allocation figure; **`rule_preview.expense_closeout_basis.lines`** documents the expense basis for the preview.

## Data scoping summary

| Entity | Scope |
|---|---|
| Family | Global (admin creates) |
| User | Global; assigned to one `family_id` |
| Category | Per `family_id`; each row is **either** income **or** expense (`is_income` XOR `is_expense`, enforced in `StoreCategoryRequest`); `split_default` remains family-shared on the category while `advance_fund_id` + `is_non_necessity_default` are stored per-user-per-category in `category_user_defaults` and only applied when `is_expense` is true |
| Transaction | Per `family_id`, owned by one `user_id`; optionally linked to `debt_id` if a debt payment; optional `advance_fund_id` marks an expense as advancing against a fund |
| TransactionSplit | Per transaction |

**Transaction list (`GET /transactions`):** responses include only rows **relevant to the authenticated user**: `user_id` matches them, or they have a `TransactionSplit` on the row (so co-participants see others’ split expenses/income they share, but not unrelated family members’ solo transactions). For **split** inter-family debt payments, the payer’s debt-payment **expense** is omitted for the **creditor** when they are both the repayment recipient (`debt.creditor_id`) and a split participant on that expense, so they only see the matching **income** leg (one line per payment).
| Fund | Personal: `user_id` + `family_id` null. Family-shared: `family_id` set (still has `user_id` creator). `GET /funds` returns personal funds (`whereNull('family_id')` on the user’s relation) plus all funds for the user’s `family_id`, each with `scope` so family rows are not listed twice for the creator |
| FundRule | Per `user_id` + `fund_id` |
| FundMovement | Per `fund_id` + `user_id`; types include `initial_value` (fund start), `allocation`, `borrow`, `repayment`, `closeout_allocation`, `advance_settlement` (advance fund payout at close) |
| Debt | Per `family_id`; links `debtor_id` ↔ `creditor_id` (or `fund_id` for fund borrows); may have linked payment transactions; supports optional closeout interest (`interest_enabled`, `interest_rate`, `interest_last_applied_at`, `loan_received_date`) that accrues at hard-close month-end using daily-rate logic and in-month payment dates; the debt's original amount and creation date are appended to payment history as an `initial_value` entry |

**Advance fund settlement:** When a month is hard-closed, `MonthCloseoutService::applyFundAdvances()` sums all expense transactions with `advance_fund_id` set (per fund, per user, per month) and decrements the fund balance by the total. A `FundMovement` of type `'advance_settlement'` is created to track this. This happens regardless of whether the user has closeout rules or income for that month.

## Build pipeline

- `npm run dev` / `npm run build` — Vite compiles `resources/js/app.js` + `resources/css/app.css` to `public/build/`
- `composer run dev` — concurrently runs `php artisan serve`, `npm run dev`, and `php artisan pail`
- Tailwind v4 has no config file; it's wired entirely via `@tailwindcss/vite` plugin and `@import 'tailwindcss'` in `app.css`
- Container deploys use FrankenPHP with repo `Caddyfile`, binding to `:{$PORT:8080}` and serving Laravel from `/app/public`

## Testing environment

- PHPUnit with `RefreshDatabase` trait; test DB values are sourced from environment (`.env.testing` / process env), not hardcoded in `phpunit.xml`
- Repository includes a dedicated `.env.testing` with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` so test runs do not target the local app MySQL database by default
- MySQL instance also present at `.local/mysql/` for manual testing (`money_tracker_test` database)
- No browser/E2E tests
