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
- `MonthCloseoutService` — month hard-close workflow; applies user `FundRule` allocations and related debt/title moves
- `DebtService` — pay a debt (creates expense + income transactions, reduces balance)
- `SplitCalculator` — pure static utility for percentage validation and amount distribution

## Data scoping summary

| Entity | Scope |
|---|---|
| Family | Global (admin creates) |
| User | Global; assigned to one `family_id` |
| Category | Per `family_id` |
| Transaction | Per `family_id`, owned by one `user_id` |
| TransactionSplit | Per transaction |
| Fund | Per `user_id` (private to each user) |
| FundRule | Per `user_id` + `fund_id` |
| FundMovement | Per `fund_id` + `user_id` |
| Debt | Per `family_id`; links `debtor_id` ↔ `creditor_id` (or `fund_id` for fund borrows) |

## Build pipeline

- `npm run dev` / `npm run build` — Vite compiles `resources/js/app.js` + `resources/css/app.css` to `public/build/`
- `composer run dev` — concurrently runs `php artisan serve`, `npm run dev`, and `php artisan pail`
- Tailwind v4 has no config file; it's wired entirely via `@tailwindcss/vite` plugin and `@import 'tailwindcss'` in `app.css`

## Testing environment

- PHPUnit with `RefreshDatabase` trait; SQLite in-memory (configured in `phpunit.xml`)
- MySQL instance also present at `.local/mysql/` for manual testing (`money_tracker_test` database)
- No browser/E2E tests
