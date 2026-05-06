# 00 — Repository Overview

## What this app is

**Money Tracker** is a family-oriented personal finance web application. It allows household members to:

- Record income and expense transactions shared across a family group
- Split costs between family members (auto-generating inter-member debt records)
- Manage personal savings "funds" with closeout-driven allocation rules and optional starting balances
- Borrow from personal funds and track repayment
- Mark expense transactions as advancing against (settling at month close against) a specific fund
- Track debts owed between family members and record payments, including the initial value of the debt in history
- Organize transactions by category (per family), with optional default advance fund and split template on **expense** categories only
- Administrate users and families (admin/head-of-household roles)

## Tech stack

| Layer | Technology | Version |
|---|---|---|
| Backend | Laravel | v13 |
| Auth | Laravel Fortify | ^1.36 |
| PHP | PHP | 8.4 |
| Frontend | Vue 3 (Composition API, `<script setup>`) | ^3.5 |
| Routing (FE) | Vue Router 4 | ^4.6 |
| Styling | Tailwind CSS (v4, via `@tailwindcss/vite`) | ^4.0 |
| Build tool | Vite | ^8.0 |
| Database | MySQL | — |
| Testing | PHPUnit | v12 |
| Code style | Laravel Pint | ^1.27 |

## Repository root structure

```
money-tracker/
├── app/
│   ├── Actions/Fortify/       # Fortify action overrides (user creation, password update)
│   ├── Http/
│   │   ├── Controllers/       # 8 controllers (Admin, Category, Dashboard, Debt, Fund, MonthCloseout, MonthSummary, Transaction) + base Controller
│   │   └── Requests/          # 8 Form Request classes (several partially unused)
│   ├── Models/                # 12 Eloquent models
│   ├── Policies/              # FundPolicy, DebtPolicy
│   ├── Providers/             # AppServiceProvider (Gates), FortifyServiceProvider
│   └── Services/              # DebtService, FundService, MonthCloseoutService, SplitCalculator, TransactionService
├── database/
│   ├── factories/             # 7 factories
│   ├── migrations/            # 30 migrations (initial set 2026-04-30; ongoing additions 2026-05-03/04/05)
│   └── seeders/               # DatabaseSeeder (creates one admin user + family, no factories)
├── resources/
│   ├── css/app.css            # Tailwind v4 entry
│   ├── js/                    # Vue SPA source
│   │   ├── app.js             # Entry point — mounts AppShell
│   │   ├── AppShell.vue       # Root layout (nav wrapper)
│   │   ├── components/        # AppNav, TransactionForm, SplitEditor, IconPicker, App.vue (legacy)
│   │   ├── composables/       # useApi.js, useAuth.js
│   │   ├── pages/             # 9 user pages + 3 admin pages
│   │   ├── router/index.js    # Vue Router config + beforeEach guards
│   │   └── support/           # authUser.js (normalizeAuthUser), debtPaymentLabel.js (debt label helper)
│   └── views/
│       ├── app.blade.php      # SPA shell (single <div id="app">)
│       └── welcome.blade.php  # Default Laravel welcome (not used in app flow)
├── routes/
│   ├── web.php                # All application routes (SPA views + JSON endpoints)
│   └── console.php            # Artisan `inspire` command only
├── tests/
│   ├── Feature/               # AdminUserManagementTest, CategoryTest, CloseoutRulesApiTest, DebtRepaymentTransactionTest, ExampleTest, FundAllocationTest, FundIndexTest, MonthCloseoutTransactionDateTest, SplitDebtSummaryTest, TransactionTest
│   └── Unit/                  # ExampleTest stub
├── config/                    # Standard Laravel config files + fortify.php
├── Caddyfile                  # FrankenPHP/Caddy runtime config (binds to `$PORT`, serves `/app/public`)
├── docs/ai/                   # This documentation folder
├── AGENTS.md / CLAUDE.md      # AI agent rules (identical content)
└── vite.config.js             # Vite + laravel-vite-plugin + tailwindcss + vue
```

## Key constraints for AI agents

- **Mobile-first UI:** Users are expected to use the app mainly on **phones and other mobile devices**. Treat narrow viewports and touch interaction as the default when planning or changing any UI (Vue pages, components, Blade shell). See `docs/ai/03-frontend-vue.md` § Mobile-first UI.
- All PHP files must pass `vendor/bin/pint --dirty --format agent` after edits.
- Tests use PHPUnit (not Pest). Run `php artisan test --compact`.
- No Enums exist yet. Roles and allocation types are plain strings.
- No API versioning — routes are in `web.php`, not `api.php`.
- No Eloquent API Resources — controllers return models/collections directly.
- Funds: **personal** funds are per-user (`user_id`, `family_id` null). **Family** funds share `family_id` with the household but still store `user_id` as the creator; **`GET /funds`** lists personal funds and family funds separately so each fund row appears once (family rows are not duplicated under the creator’s personal list). Categories and transactions are **per-family** (stored); **`GET /transactions` lists only rows relevant to the signed-in user** (their own plus split co-participations). See `docs/ai/01-architecture.md` (data scoping).
- A user without a `family_id` is essentially unusable (most endpoints 403 or return empty).
