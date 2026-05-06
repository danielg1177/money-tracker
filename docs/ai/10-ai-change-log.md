# 10 — AI Change Log

This file records all significant changes made by AI agents. Add an entry at the top after each session that modifies the codebase.

Format:
```
## YYYY-MM-DD — [Feature/Change Summary]
- Files touched: ...
- Behavioral impact: ...
```

---

## 2026-05-06 — Add family icon option to category icon picker

- Files touched: `resources/js/components/IconPicker.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Category icon picker now includes a family emoji (`👨‍👩‍👧‍👦`) option under the Other icon group.

## 2026-05-06 — Add dog icon option to category icon picker

- Files touched: `resources/js/components/IconPicker.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Category icon picker now includes a dog emoji (`🐶`) option under the Other icon group.

## 2026-05-06 — Fix month-summary fund movements leaking prior closeout month

- Files touched: `app/Http/Controllers/MonthSummaryController.php`, `app/Services/MonthCloseoutService.php`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `GET /month-summary` fund movement filtering now scopes closeout movement types (`closeout_allocation`, `advance_settlement`) by the tagged closeout month instead of movement `created_at`, preventing prior-month closeouts run later from showing in the wrong month (for example, April closeout movements appearing in May). New closeout movement descriptions are normalized to `YYYY-MM` tags, and regression coverage verifies the month separation.

## 2026-05-06 — Fix remaining-percentage closeout rules to use shared basis

- Files touched: `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/MonthSummaryController.php`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Remaining-base percentage closeout rules now compute from one shared remaining-after-expenses basis (instead of cascading on a shrinking percentage pool). This applies consistently to both hard-close execution and `GET /month-summary` rule preview, so scenarios like two 50% remaining rules now project/apply equal amounts from the same base.

## 2026-05-06 — Closeout-generated movement transactions + rule default expense category

- Files touched: `database/migrations/2026_05_06_161500_add_closeout_transaction_fields.php`, `app/Models/FundRule.php`, `app/Models/CloseoutTitleSaving.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/FundController.php`, `app/Http/Controllers/BankBalanceController.php`, `app/Http/Controllers/MonthSummaryController.php`, `resources/js/pages/CloseoutRules.vue`, `resources/js/pages/Transactions.vue`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Hard-close rule allocations to **funds** now also create closeout-tagged expense transactions in the ledger; debt-allocation closeout payments can now carry a default category from the rule; closeout rules now support optional `closeout_expense_category_id`. Title savings completion now creates a closeout-tagged expense transaction (and undo completion deletes it). Transactions UI now visually distinguishes closeout-generated rows with a dedicated badge/tint and blocks manual edit/delete for those system-generated entries. Closeout expense-basis math excludes closeout-tagged rows so generated movement entries do not recursively alter same-run allocation results.

## 2026-05-06 — Transactions month dropdown now includes next two months

- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Month quick-select now starts two months ahead of the current month and continues in descending order, so users can preselect upcoming months as well as current/past months without losing correct cross-year ordering.

## 2026-05-06 — Fix Transactions month dropdown order across new year

- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Transactions month quick-select now renders as a single descending month timeline (latest to oldest) instead of separate current-year/prior-year blocks, so month ordering remains correct around year boundaries (e.g., Jan follows Dec in the expected chronological sequence).

## 2026-05-06 — Fix transaction month-list refresh, live category options, and isolate test DB

- Files touched: `resources/js/pages/Transactions.vue`, `resources/js/components/AppNav.vue`, `resources/js/pages/Categories.vue`, `.env.testing`, `docs/ai/01-architecture.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Transactions page now refetches the active filter after create events (including global FAB events), so transactions dated in other months no longer appear in the current month until refresh. Category create/update/delete now broadcasts `categories-changed`, and `AppNav` listens/reloads FAB dependencies so new categories appear immediately in transaction category options. Added repository `.env.testing` using in-memory SQLite so PHPUnit/`RefreshDatabase` does not wipe local MySQL app data by default.

## 2026-05-06 — Monthly debt interest applied at family hard-close month-end

- Files touched: `database/migrations/2026_05_06_133500_add_interest_fields_to_debts_table.php`, `app/Models/Debt.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/DebtController.php`, `resources/js/pages/Debts.vue`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Debts now support optional interest configuration (`interest_enabled`, `interest_rate`). During `POST /closeout/hard-close`, the service applies one monthly interest accrual to all eligible debts in the family and records `interest_last_applied_at` as the closed month’s last day, so accrual timing is tied to the month being closed (not the day closeout is run). Debts UI now allows enabling/editing APR on create/edit and displays APR on debt cards.

## 2026-05-06 — Add loan received date + daily closeout interest accrual with payment-date impact

- Files touched: `database/migrations/2026_05_06_134200_add_loan_received_date_to_debts_table.php`, `app/Models/Debt.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/DebtController.php`, `resources/js/pages/Debts.vue`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Debts now support optional `loan_received_date`. Closeout debt interest now uses daily accrual (`APR / 365`) across the closed month window (rather than flat APR/12), and uses debt-payment expense transaction dates to reduce accrual after payments made mid-month. This keeps month-close interest closer to standard lender behavior while still applying accrual through the closed month-end date regardless of when users run closeout.

## 2026-05-06 — Interest accrual now appears in debt history and no longer changes principal

- Files touched: `database/migrations/2026_05_06_140000_add_interest_accruals_to_debts_table.php`, `app/Models/Debt.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/DebtController.php`, `resources/js/pages/Debts.vue`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Hard-close interest accrual now increases only `debts.balance`; `debts.amount` remains the original principal. Each accrual is appended to `debts.interest_accruals`, and `GET /debts/{debt}/payments` now includes `type='interest_accrual'` entries so the Debts History modal shows monthly interest events in the timeline.

## 2026-05-06 — Income transaction “New Debt” supports debt settings

- Files touched: `resources/js/components/TransactionForm.vue`, `app/Http/Requests/StoreTransactionRequest.php`, `app/Services/TransactionService.php`, `tests/Feature/TransactionTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The income form path (`income_debt_mode='new'`) now accepts debt settings directly when creating a debt from income: `income_new_interest_enabled` and `income_new_interest_rate`. `loan_received_date` for this path is automatically derived from `transaction_date` instead of being manually entered.

## 2026-05-06 — Income transactions can create or expand debt

- Files touched: `app/Http/Requests/StoreTransactionRequest.php`, `app/Services/TransactionService.php`, `resources/js/components/TransactionForm.vue`, `tests/Feature/TransactionTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Income entries now support optional debt association in the transaction form. Users can mark income as debt-backed by either linking an existing debt they owe or creating a new debt inline (external creditor or family member). The income still behaves as normal month income for closeout/allocation math. When selected, debt amount and balance are increased/created at transaction save time and linked via `transactions.debt_id`. Transaction updates/deletes roll back this linked debt amount so debt totals stay consistent.

## 2026-05-06 — Align fund allocation tests with closeout behavior

- Files touched: `tests/Feature/FundAllocationTest.php`, `docs/ai/00-repo-overview.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `FundAllocationTest` no longer expects immediate `FundRule` allocation when creating income transactions. The two income-path tests now assert no fund balance changes or `fund_movements` rows are created at transaction create time, matching current behavior where allocation happens at month hard-close.

## 2026-05-06 — Bank account balance tracking

- Files touched: `database/migrations/..._add_bank_balance_to_users_table.php`, `database/migrations/..._add_completion_to_closeout_title_savings_table.php`, `app/Models/User.php`, `app/Models/CloseoutTitleSaving.php`, `app/Http/Requests/UpdateBankBalanceRequest.php`, `app/Http/Controllers/BankBalanceController.php`, `app/Http/Controllers/MonthSummaryController.php`, `routes/web.php`, `resources/js/pages/Dashboard.vue`, `resources/js/pages/MonthSummary.vue`
- Behavioral impact: Added opt-in per-user feature to track real bank account balance. Users set an anchor balance from the Dashboard; subsequent transactions are applied in real time as debits/credits. Split expense payers are debited the full fronted amount. After month hard-close, MonthSummary shows title savings records with a "Mark Done" toggle; completion subtracts the amount from the computed bank balance. No existing behavior changed. `GET /month-summary` now always includes a `title_savings` key (empty array when month is not hard-closed or user has no title rules). Four new API routes added. Dashboard shows a new bank balance section to users who opt in; users who do not enable the feature see only a small "Enable" prompt card.

## 2026-05-06 — Month summary adds Title Savings completion UI

- Files touched: `resources/js/pages/MonthSummary.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Month Summary now renders a **Title Savings** section for hard-closed months when `title_savings` is non-empty. Users can mark each row complete (`POST /title-savings/{id}/complete`) or undo completion (`DELETE /title-savings/{id}/complete`) directly from the page, with in-place row updates for `is_completed` / `completed_at`.

## 2026-05-06 — Dashboard adds bank account balance card

- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Dashboard now fetches `/bank-balance` and renders a mobile-first Bank Account card for family users. Users can enable tracking, set current balance (baseline set to today), and disable tracking from the card via `PUT /bank-balance`.

## 2026-05-06 — Month summary adds title savings payload

- Files touched: `app/Http/Controllers/MonthSummaryController.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `GET /month-summary` now includes `title_savings` for the authenticated user when the selected month is hard-closed. Each row returns `id`, `title`, `amount`, `is_completed`, and `completed_at`. Non-hard-closed months return an empty `title_savings` array.

## 2026-05-06 — Add bank balance tracking API and title-saving completion endpoints

- Files touched: `app/Models/User.php`, `app/Models/CloseoutTitleSaving.php`, `app/Http/Requests/UpdateBankBalanceRequest.php`, `app/Http/Controllers/BankBalanceController.php`, `routes/web.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Added authenticated bank balance tracking endpoints (`GET/PUT /bank-balance`) and title-savings completion toggles (`POST/DELETE /title-savings/{id}/complete`). Users can enable/disable bank-balance mode, set a balance baseline date (today when a balance is submitted), and compute a live balance delta from transactions and completed title savings since that date.

## 2026-05-06 — Add bank balance and closeout completion migration columns

- Files touched: `database/migrations/2026_05_06_154936_add_bank_balance_to_users_table.php`, `database/migrations/2026_05_06_154936_add_completion_to_closeout_title_savings_table.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Added user-level bank balance tracking fields (`bank_balance_enabled`, `bank_balance`, `bank_balance_set_at`) and closeout title saving completion fields (`is_completed`, `completed_at`). Both migrations were applied successfully via `php artisan migrate`; no runtime errors during migration or formatting.

## 2026-05-06 — Documentation audit and correction

- Files touched: `docs/ai/00-repo-overview.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Documentation-only. No code changes. Key corrections:
  - **00**: Migration count corrected (25 → 30); feature test list updated to include `AdminUserManagementTest`, `CategoryTest`, and `DebtRepaymentTransactionTest`.
  - **02**: User model relations named correctly (`debtsOwed`, `debtsOwedTo`, `monthSoftCloses`); Transaction model adds `advanceFund()` and `mirrorTransaction()` named relations; FundMovement adds `description` field and all 6 type values (`allocation`, `borrow`, `repayment`, `initial_value`, `closeout_allocation`, `advance_settlement`) plus its relations; Debt model adds `is_pending_closeout` to field list; DebtController adds missing `update()` and `splitDebtSummary()` methods, corrects `destroy()` as hard delete (not soft), corrects `paymentHistory()` to describe role-based filtering, corrects `paymentHistory()` initial_value entry; FundController `showRules()` corrected (no Fund parameter, no FundPolicy); DebtService `payDebt` signature updated with all 7 parameters.
  - **04**: Added 7 missing migrations (5 from 2026-05-03 closeout system, 2 from 2026-05-05 advance fund); fund_movements table adds `description` column and complete `type` enum values.
  - **05**: Route middleware `auth` exception list adds `/debts` and `/month-summary/{yearMonth}`.
  - **06**: Feature numbers 13/14 corrected (Month Summary is 13, Fund rules/closeout is 14).
  - **08**: `PUT /admin/users/{user}` notes updated to reflect optional `password` and `is_admin` fields.

---

## 2026-05-05 — Transactions: repay debt + creditor mirror + closeout exclusions

- Files touched: `database/migrations/2026_05_05_214052_add_mirror_transaction_id_to_transactions_table.php`, `app/Models/Transaction.php`, `app/Http/Requests/StoreTransactionRequest.php`, `app/Services/TransactionService.php`, `app/Services/DebtService.php`, `app/Http/Controllers/TransactionController.php`, `app/Http/Controllers/MonthSummaryController.php`, `resources/js/components/TransactionForm.vue`, `resources/js/components/AppNav.vue`, `resources/js/pages/Transactions.vue`, `resources/js/pages/MonthSummary.vue`, `resources/js/support/debtPaymentLabel.js`, `tests/Feature/DebtRepaymentTransactionTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Debtors may include optional **`debt_id`** when posting an **expense**, creating a categorized payment that **reduces `debts.balance` immediately**, creates the usual creditor **`is_debt_payment` income** when `creditor_id` is set, stores **`mirror_transaction_id`** linking the paired rows, and **blocks split + advance fund** together with this mode. Creditor repayment income stays **outside `MonthCloseoutService` gross income** (`is_debt_payment` exclusion — unchanged principle). **`GET /month-summary`** gains **`debt_repayments`** (`paid` / `received`). **Deletes** routed through **`TransactionService::deleteTransaction`**: reverses mirrored payments (either leg) restoring balance). **Debt repayments cannot be edited** (`updateTransaction` rejects). **Transactions.vue** period income totals omit creditor repayment amounts; creditor rows show a **Repayment** chip and sky-tint amounts. **`debtPaymentLabel.js`** distinguishes pay vs repayment received wording. **`DebtService::payDebt`** now sets **`mirror_transaction_id`** on simple (non-split) in-member creditor payments.

## 2026-05-05 — Categories: income XOR expense (no dual-type)

- Files touched: `database/migrations/2026_05_05_212303_ensure_categories_are_income_xor_expense.php`, `app/Http/Requests/StoreCategoryRequest.php`, `database/factories/CategoryFactory.php`, `resources/js/pages/Categories.vue`, `resources/js/pages/admin/Categories.vue`, `tests/Feature/CategoryTest.php`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: A category must be **either** income **or** expense. `StoreCategoryRequest` rejects payloads where both booleans are true or both false. A migration normalizes existing rows (both-false → expense; both-true → expense if split-default/advance fund are set, otherwise income). The family **Categories** page uses radio buttons instead of dual checkboxes; **admin/Categories** uses a `categoryType` radio with the same XOR semantics when posting. `CategoryFactory` no longer creates random dual-type categories.

## 2026-05-05 — Restrict advance fund & split defaults to expense-only

- Files touched: `app/Http/Requests/StoreTransactionRequest.php`, `app/Http/Requests/StoreCategoryRequest.php`, `app/Services/TransactionService.php`, `resources/js/components/TransactionForm.vue`, `resources/js/pages/Categories.vue`, `tests/Feature/TransactionTest.php`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Transactions:** Income rows no longer accept `advance_fund_id`, `is_split=true`, or `split_data`; the form request and `TransactionService` coerce those fields off for `type=income`, and the Vue form hides split controls for income. **Categories:** Default advance fund and split-default settings are only available in the UI when the category has **Expense** enabled; saving a category with `is_expense=false` clears `advance_fund_id`, `is_split_default`, and `split_default` server-side. Category split/advance defaults only apply when creating/editing **expense** transactions (category watcher guarded by transaction type). **Tests:** `test_debt_payment_history_returns_one_row_per_inter_family_payment` now accounts for the appended `initial_value` row on `GET /debts/{debt}/payments`.

## 2026-05-05 — Dashboard: Transactions card shows this month’s count

- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The Dashboard **Transactions** stat card now displays the number of viewer-scoped transactions with a `transaction_date` in the **current calendar month**, not the total number of loaded rows. Subtitle text reads **This month · View all**. The page still fetches the full `GET /transactions` list so **Family close progress** (earliest open month) is unchanged.

## 2026-05-05 — Month summary now includes all monthly fund in/out activity

- Files touched: `app/Http/Controllers/MonthSummaryController.php`, `resources/js/pages/MonthSummary.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The month summary API now returns `fund_movements` with grouped in/out/net totals and movement rows per visible fund. Matching includes non-rule and rule-driven activity for the selected month (borrow, repayment, initial value, closeout allocation, advance settlement) using transaction date, movement created month, and closeout month tags in movement descriptions. `MonthSummary.vue` now renders a new **Fund In/Out** section so the View page shows fund money moving in or out regardless of whether it came from closeout rules.

## 2026-05-05 — Fix FAB transaction form crash when toggling advance-against-fund

- Files touched: `resources/js/components/AppNav.vue`, `resources/js/components/TransactionForm.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Fixed a frontend runtime error (`Cannot read properties of undefined (reading 'length')`) in `TransactionForm.toggleAdvanceFund()` when opened from the global FAB. `AppNav.vue` now fetches funds (`GET /funds`) alongside categories and family users, and passes `:funds="funds"` into `TransactionForm`. `TransactionForm.vue` now defaults the `funds` prop to an empty array for defensive safety, preventing crashes if funds are not yet loaded.

## 2026-05-05 — Advance against fund feature + Fund starting balance + Debt initial value history

- Files touched: `database/migrations/2026_05_05_201653_add_advance_fund_id_to_transactions_table.php`, `database/migrations/2026_05_05_201653_add_advance_fund_id_to_categories_table.php`, `app/Models/Transaction.php`, `app/Models/Category.php`, `app/Http/Controllers/FundController.php`, `app/Http/Controllers/DebtController.php`, `app/Http/Requests/StoreTransactionRequest.php`, `app/Http/Requests/StoreCategoryRequest.php`, `app/Services/TransactionService.php`, `app/Services/MonthCloseoutService.php`, `resources/js/pages/Funds.vue`, `resources/js/pages/Debts.vue`, `resources/js/components/TransactionForm.vue`, `resources/js/pages/Transactions.vue`, `resources/js/pages/Categories.vue`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`

- Behavioral impact: Added three interconnected features:

  **1. Fund Starting Balance:** When creating a fund via `POST /funds`, an optional `starting_balance` parameter (nullable numeric, min 0) can be provided. If > 0, the fund is created with that balance and a `FundMovement` record of type `'initial_value'` is automatically created to track the initialization in the fund's history. The `Funds.vue` page includes a new optional "Starting Balance" number input when creating a fund.

  **2. Advance Against Fund (Expense Feature):** New `advance_fund_id` field on `transactions` table (nullable FK → `funds.id`, `nullOnDelete`). Expense transactions can be marked as advancing against a fund. The fund balance is NOT affected at transaction creation. During month hard-close, `MonthCloseoutService::applyFundAdvances()` sums all advance transactions per fund per user per month and creates a `FundMovement` of type `'advance_settlement'` that decrements the fund balance. This settlement happens even if the user has no closeout rules or zero gross income.
    - `StoreTransactionRequest` and `StoreCategoryRequest` now validate the optional `advance_fund_id` field
    - `TransactionService` includes `advance_fund_id` in transaction data
    - `TransactionForm.vue` now accepts a `funds` prop and shows an "Advance against fund" toggle (amber, expense-only) with fund selection dropdown
    - `Transactions.vue` fetches `GET /funds` and passes the list to `TransactionForm`

  **3. Category Default Advance Fund:** New `advance_fund_id` field on `categories` table (nullable FK → `funds.id`, `nullOnDelete`). Categories can specify a default advance fund, similar to `split_default`. When a transaction is created with a category that has `advance_fund_id` set, the form pre-populates the advance fund selection. `Categories.vue` includes a new "Default Advance Fund" optional select field when creating/editing a category.

  **4. Debt Initial Value in History:** `DebtController::paymentHistory()` now appends a synthetic `'initial_value'` entry to the payment history array, displayed at the end (oldest chronologically). This entry shows the debt's original amount with type `'initial_value'`, description "Initial Value Set At", and the debt's creation date. The entry has no transaction ID and represents the debt's origin point in its timeline. `Debts.vue` renders `'initial_value'` entries distinctively: blue-tinted card, blue badge, blue amount text, no paid-by or action buttons.

  **5. New FundMovement Types:** Backend now supports movement types `'initial_value'` (positive, green in UI) and `'advance_settlement'` (negative, amber in UI). `Funds.vue` movement history modal displays these with appropriate labels and color coding.

  **6. Month Closeout Restructuring:** `MonthCloseoutService::processUserCloseoutRules()` was restructured so that `applyFundAdvances()` is always called at the end, regardless of whether `grossIncome <= 0`. This allows advance settlement to occur even for users with no income or active rules in that month.

  All endpoints return the new fields in their JSON responses; no breaking changes to existing features.

## 2026-05-05 — Categories.vue: add "Default Advance Fund" field for category defaults

- Files touched: `resources/js/pages/Categories.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **Frontend only.** `Categories.vue` now supports optional `advance_fund_id` field on categories, mirroring the split_default pattern. (1) Added `funds` ref and `fetchFunds()` function that fetches from `GET /funds`, called in onMounted alongside other data loads. (2) Added `advance_fund_id: null` to the form object. (3) New "Default Advance Fund" select field in the create/edit modal, positioned after the split distribution section, shows fund names with scope labels (Family/Personal). (4) When editing a category, `advance_fund_id` is populated from the existing category data. (5) Form reset clears `advance_fund_id` back to null. (6) POST and PUT payloads include `advance_fund_id || null` so transactions in this category can default to advancing against the selected fund.

## 2026-05-05 — Transactions.vue: fetch funds and pass to TransactionForm component

- Files touched: `resources/js/pages/Transactions.vue`
- Behavioral impact: **Frontend only.** `Transactions.vue` now fetches funds from `GET /funds` alongside categories and family users. Added `funds` ref to reactive state, integrated `get('/funds')` into the existing `fetchData()` Promise.all() block, and passed `:funds="funds"` prop to the `TransactionForm` component in the edit modal. This enables TransactionForm to render the "Advance against fund" toggle and fund selection dropdown for expense transactions (required prop for the newly added fund-advance feature).

## 2026-05-05 — TransactionForm: add "Advance against fund" toggle with fund selection

- Files touched: `resources/js/components/TransactionForm.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **Frontend only.** TransactionForm component now supports marking expense transactions as advancing against a specific fund (settled at month close, not immediately affecting fund balance). (1) New `funds` prop (Array, required) accepts list of available funds. (2) Form reactive object now includes `advance_fund_id: null` field. (3) New toggle "Advance against fund" appears after the "Split between family members" toggle, only when `form.type === 'expense'`, using amber color scheme (matches Split toggle's blue pattern). (4) When enabled, toggle shows a fund selection dropdown with fund names and scope labels (Family or Personal). (5) New watcher clears `advance_fund_id` when type changes to 'income' so advance is only available for expenses. (6) Category watcher auto-populates `advance_fund_id` when selected category has `advance_fund_id` set. (7) Edit mode loads and preserves existing `advance_fund_id` from transaction. (8) Submit payload includes `advance_fund_id` only for expense-type transactions (null for income). (9) New `toggleAdvanceFund()` function handles toggle clicks: enables with first fund selected, disables sets to null.

## 2026-05-05 — Debts payment history: render initial_value entries distinctively in modal

- Files touched: `resources/js/pages/Debts.vue`
- Behavioral impact: **Frontend only.** The payment history modal now conditionally renders payment entries based on their `type`. For entries with `type === 'initial_value'` (appended by the backend `GET /debts/{id}/payments` endpoint to show the debt's origin): (1) the entry uses a blue-tinted card background (`bg-blue-900/20`) with blue border (`border-blue-700/50`) instead of the standard gray; (2) displays a blue badge label "Initial Value Set At" instead of the regular payment description; (3) shows the amount in blue text (`text-blue-400`) without a +/- sign prefix (unlike regular income/expense entries); (4) displays the transaction date; (5) does **not** show the `paid_by_user` line or any action buttons. Regular payment entries (income/expense) continue to display as before with their normal colors and action affordances.

## 2026-05-05 — Funds: support starting balance; fund movement labels for initial_value and advance_settlement

- Files touched: `resources/js/pages/Funds.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **Frontend only.** (1) New Fund form now includes optional "Starting Balance" number input (currency field with $ prefix). When creating a fund, `starting_balance` is sent to the backend (defaults to 0 if not provided). Form input is reset to `null` after successful creation. (2) `movementTypeLabel()` function now recognizes two new movement types: `initial_value` (displays as "Initial Value Set At") and `advance_settlement` (displays as "Advance Settlement"). (3) Fund History modal badge color logic updated: green for positive movements (allocation, closeout_allocation, repayment, initial_value); amber for negative/outbound (borrow, advance_settlement). (4) Fund History amount display logic updated: green + prefix for positive movements, amber - prefix for negative (borrow and advance_settlement now both show as negative amber amounts).

## 2026-05-05 — Month closeout: settle advance-against-fund expenses

- Files touched: `app/Services/MonthCloseoutService.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: During month hard-close, after all user closeout allocation rules execute, a new `applyFundAdvances()` step runs for each user. This method finds all expense-type transactions with `advance_fund_id` set (non-null) in that month, sums them by fund, and deducts the total from each fund's balance. A `FundMovement` of type `'advance_settlement'` is created for each fund to track the settlement. This allows advance expense transactions to automatically settle against their linked funds at closeout, potentially causing fund balances to go negative. The `processUserCloseoutRules()` method was refactored to only skip rules processing when `grossIncome <= 0`, but always calls `applyFundAdvances()` regardless of income.

## 2026-05-05 — Thread advance_fund_id through validation and TransactionService

- Files touched: `app/Http/Requests/StoreTransactionRequest.php`, `app/Http/Requests/StoreCategoryRequest.php`, `app/Services/TransactionService.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Added optional `advance_fund_id` field (nullable, must exist in funds table) to both `StoreTransactionRequest` and `StoreCategoryRequest` validation rules with appropriate error messages. `TransactionService::createTransaction()` and `updateTransaction()` now include `advance_fund_id` in transaction data when creating/updating transactions. This allows transactions and categories to optionally track an associated advance fund, enabling future application logic to use this relationship.

## 2026-05-05 — Debt payment history now shows initial value as oldest event

- Files touched: `app/Http/Controllers/DebtController.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `DebtController::paymentHistory()` now appends a synthetic `'initial_value'` entry to the payment history collection. This entry appears at the end of the ordered payments (making it the oldest event chronologically) and displays the debt's original amount with description "Initial Value Set At" and the debt's creation date. The entry has no transaction ID (`id: null`), represents the debt's origin point, and helps the frontend render a complete timeline of a debt from creation through all payments.

## 2026-05-05 — Allow optional starting balance when creating a fund

- Files touched: `app/Http/Controllers/FundController.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `FundController::store()` now accepts optional `starting_balance` parameter (nullable numeric, min 0). When provided and > 0, the fund is created with that balance and a `FundMovement` record of type `'initial_value'` is automatically created to track the initial value in the fund's movement history. The endpoint returns the fund with eager-loaded `movements.user` relationship so the initial movement is visible in the response.

## 2026-05-05 — Add advance_fund_id foreign key to transactions and categories

- Files touched: `database/migrations/2026_05_05_201653_add_advance_fund_id_to_transactions_table.php`, `database/migrations/2026_05_05_201653_add_advance_fund_id_to_categories_table.php`, `app/Models/Transaction.php`, `app/Models/Category.php`
- Behavioral impact: Added optional `advance_fund_id` foreign key to `transactions` and `categories` tables, both referencing `funds.id` with `nullOnDelete()`. Updated `Transaction` model with new `advance_fund_id` fillable field, cast to integer, and new `advanceFund()` BelongsTo relationship. Updated `Category` model with new `advance_fund_id` fillable field and integer cast. Two migrations created and executed successfully. No user-facing feature yet — establishes schema foundation for future advance fund tracking.

## 2026-05-05 — Admin user edit can now update password

- Files touched: `resources/js/pages/admin/Users.vue`, `app/Http/Controllers/AdminController.php`, `tests/Feature/AdminUserManagementTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Admins can set a new password when editing a user from the Users page. The password field is optional in the edit form (blank keeps the current password); when provided, backend validation enforces minimum length and stores a hashed value.

## 2026-05-05 — Seeder now creates one admin user + family without factories

- Files touched: `database/seeders/DatabaseSeeder.php`, `docs/ai/00-repo-overview.md`, `docs/ai/04-database.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Removed factory/fake-based seeding from `DatabaseSeeder` and replaced it with deterministic records (`Household` family and `admin@example.com` user). Seeder is now compatible with production deployments that install Composer dependencies with `--no-dev`.

## 2026-05-05 — Railway 502 fix: explicit FrankenPHP Caddy config

- Files touched: `Caddyfile`, `Dockerfile`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Added project-level Caddy config for FrankenPHP to bind to Railway port (`:{$PORT:8080}`) and serve Laravel from `/app/public`. Docker runtime now uses `/app/Caddyfile` and exposes `8080`, aligning container HTTP behavior with Railway ingress to prevent 502 responses.

## 2026-05-05 — PHPUnit DB config now environment-driven

- Files touched: `phpunit.xml`, `docs/ai/01-architecture.md`, `docs/ai/04-database.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Removed hardcoded DB_* entries from `phpunit.xml`; PHPUnit now uses DB settings provided by runtime environment (typically `.env.testing`), reducing credential drift between local and deployed environments.

## 2026-05-05 — Enforce MySQL-only defaults

- Files touched: `config/database.php`, `config/queue.php`, `phpunit.xml`, `composer.json`, `database/.gitignore`, `database/migrations/2026_05_03_160512_update_fund_rules_for_closeout_system.php`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/04-database.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Application and queue DB fallbacks now default to MySQL, PHPUnit defaults were switched to MySQL test settings, and legacy file-database bootstrap/config references were removed from project scripts/docs to prevent accidental use of the wrong driver in non-explicit environments.

## 2026-05-04 — Documentation audit and update

- Files touched: `docs/ai/00-repo-overview.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Documentation-only. No code changes. Key corrections:
  - **00**: Controller count updated (8 + base), model count (12), page count (9 user + 3 admin), added `MonthCloseoutService` to services list, migration count corrected (25), added `debtPaymentLabel.js` and all feature-test files to structure listing.
  - **02**: Added `debt_id`, `paid_by_user_id`, `is_closeout_initiated` to Transaction model fields; added `paidByUser` and `debt` relationships; added `contributions` to Debt model; added `DashboardController`, `MonthCloseoutController`, and `MonthSummaryController` sections.
  - **03**: Added `/closeout-rules` route to the router table; added `debtPaymentLabel.js` to Support utilities section.
  - **04**: Added `is_admin` column to `users` table; corrected `role` values (no more `admin`); added `destination_type`, `destination_id`, `destination_title` to `fund_rules`; added `debt_id`, `paid_by_user_id`, `is_closeout_initiated` to `transactions`; added `contributions` to `debts`; added 5 missing migrations.
  - **05**: Removed `admin` role from roles table; updated `is_admin` computed attribute to reflect boolean column (not `role === 'admin'`); corrected Gates table annotation.
  - **06**: Added feature 14 (Month Summary page + `GET /month-summary` endpoint).
  - **07**: Updated Workflow 2 to document optional split payment fields in `PayDebtRequest`.
  - **08**: Fixed `POST /closeout/status` (was incorrectly documented as `GET`); added `/debts` and `/month-summary/{yearMonth}` to public SPA shell routes; added `PUT /debts/{debt}`; added `GET /month-summary` endpoint section; updated `PayDebtRequest` body with `split_with_user_id` and `split_percentage` fields; fixed Month Closeout table formatting.

## 2026-05-04 — Fund movement history: show who made each change

- Files touched: `app/Http/Controllers/FundController.php`, `resources/js/pages/Funds.vue`, `tests/Feature/FundIndexTest.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `GET /funds` eager-loads `movements.user`. The Funds page History modal lists each movement with a **By {name}** line using `fund_movements.user_id` (borrow, repayment, allocations, closeout allocations). When the movement’s `user_id` matches the signed-in user, the label reads **By You** instead of their display name.

## 2026-05-04 — `GET /funds`: family fund no longer listed twice for creator

- Files touched: `app/Http/Controllers/FundController.php`, `tests/Feature/FundIndexTest.php`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Family funds are stored with both `user_id` (creator) and `family_id`. The index previously loaded all `$user->funds()` plus all family funds by `family_id`, so the creator saw the same fund twice. Personal branch now uses `whereNull('family_id')` so each fund appears once on the Funds page.

## 2026-05-04 — Debt history: clickable closeout rows navigate to transactions for that month

- Files touched: `resources/js/pages/Debts.vue`
- Behavioral impact: Closeout contribution rows in the debt payment history modal are now clickable. Clicking a "May 2026 Closeout" row navigates to `/transactions?month=2026-05` to show the transactions for that month. Added `useRouter` import and `navigateToMonthSummary()` function that formats year/month into a query parameter. Added hover styling (lighter background, darker border) to closeout rows with `cursor-pointer` to indicate they're interactive. This allows users to quickly jump to the transaction list for the month during which closeout contributions were applied.

## 2026-05-04 — Debt card: creditors now see debtor's name, not their own

- Files touched: `resources/js/pages/Debts.vue`
- Behavioral impact: Fixed the personal debt card display so the prominent name field shows the correct counterparty. When the authenticated user is the **debtor**: displays who they owe (creditor name or external creditor_name). When the user is the **creditor**: displays who owes them (the debtor's name). Previously, creditors always saw their own name in this field (confusing even though the label "X owes you" was correct). The name field now always shows the **other person** in the debt relationship, making the card's visual hierarchy consistent and intuitive.

## 2026-05-04 — Payment history modal: income/expense type-aware display

- Files touched: `resources/js/pages/Debts.vue`
- Behavioral impact: The payment history modal (Debts.vue) now displays payment transactions contextually based on their type. **Income payments** (received by the creditor) show with a **green** amount, a **+** sign, and "**From:**" label. **Expense payments** (paid by the debtor) show with a **red** amount, a **-** sign, and "**Paid by:**" label. This provides visual and semantic clarity for creditors viewing income they received vs. debtors/managers viewing expenses paid.

## 2026-05-04 — Fix debt payment history: creditor now sees their own income transactions

- Files touched: `app/Http/Controllers/DebtController.php`, `tests/Feature/TransactionTest.php`
- Behavioral impact: When a debt payment is made between family members, two transaction rows are created: an expense for the payer and an income for the creditor. Previously, `paymentHistory()` returned expense rows for both viewers (using a complex `whereNot` subquery to hide the creditor's income). Now, the method uses role-aware filtering: if the viewer is the debt creditor, they see income rows with their user_id; otherwise (debtor or family manager), they see expense rows. This is simpler, more correct, and gives each viewer the appropriate transaction type for their role. The unused `Builder` import was removed from the controller.

## 2026-05-04 — Debt payment history: one list row per inter-family pay (`GET /debts/{debt}/payments`)

- Files touched: `app/Http/Controllers/DebtController.php`, `tests/Feature/TransactionTest.php`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `DebtService::payDebt` writes two `transactions` with the same `debt_id` (debtor **expense** + creditor **income**). `paymentHistory` previously returned both, so the Debts History modal showed two “payments.” The index query now drops the mirror **income** row when a paired **expense** exists (matched on `debt_id`, `transaction_date`, `amount`, `paid_by_user_id`, and `created_at`) and the debt has `creditor_id` set. Closeout / external-creditor flows unchanged (no paired mirror or no member creditor).

## 2026-05-04 — `GET /transactions`: avoid duplicate rows for split inter-family debt payments

- Files touched: `app/Http/Controllers/TransactionController.php`, `database/migrations/2026_05_04_211754_repair_missing_april_2026_split_debt.php`, `tests/Feature/TransactionTest.php`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `DebtService::payDebt` creates a payer **expense** (optionally with splits) plus creditor **income** for the same `debt_id`. If the payer splits that expense with the **creditor**, the creditor matched both the expense (via splits) and the income (via `user_id`), so the payment appeared twice. `TransactionController::index` now excludes that mirrored expense when the viewer is the debt’s `creditor_id` and is only on the row via splits. Repair migration `repair_missing_april_2026_split_debt` skips when family `id=1` or users `id` 1/2 are absent (fixes PHPUnit empty DB FK failures).

## 2026-05-04 — Data repair: missing April 2026 inter-family split debt (transaction 8 gap)

- Files touched: `database/migrations/2026_05_04_211754_repair_missing_april_2026_split_debt.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: One-time migration inserts a confirmed `debts` row (family 1, debtor 2, creditor 1, amount/balance 28.00, `contributions` for April 2026) when no matching row exists; `down()` is a no-op. No application code changes. Migration no-ops when `families.id=1` or `users.id` 1/2 are missing so automated test databases without that seed data do not hit FK errors.

## 2026-05-04 — Debts.vue: fix `onMounted` redeclaration (Vite compile error)

- Files touched: `resources/js/pages/Debts.vue`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Corrected `const onMounted(() => { ... })` to `onMounted(() => { ... })` so the imported Vue lifecycle hook is used instead of declaring a const with the same name as the import (which caused `[vue/compiler-sfc] Identifier 'onMounted' has already been declared`).

## 2026-05-04 — Fix three bugs in Debts feature for inter-family debts

- Files touched: `resources/js/pages/Debts.vue`, `app/Models/Debt.php`, `app/Http/Controllers/DebtController.php`, `app/Services/MonthCloseoutService.php`, `database/migrations/2026_05_04_205520_add_contributions_to_debts_table.php`
- Behavioral impact:
  - **Bug 1 (Pay button hidden):** Replaced async `fetchAuthUser()` call to `GET /user` with synchronous `useAuth()` composable that reads from `localStorage`. Now `authUser.id` is available immediately on render, fixing Pay button visibility on initial load.
  - **Bug 2 (History empty for closeout debts):** Added `contributions` JSON array column to `debts` table to track which month closeouts contributed to debt balance. Updated `consolidatePendingSplitDebts()` to record `[month, year, amount]` tuples for each closeout addition. Updated history modal to display both contributions (amber "Closeout Additions" section) and manual payments (red "Payments" section) separately with full transaction audit trail.
  - **Bug 3 (History modal broken from creditor):** Fixed `DebtController::index` to eager-load both `creditor` and `debtor` relations on all personal debt queries (was only loading one relation per query, causing null references when creditor opened history).

## 2026-05-04 — Orphan pending split debts: consolidate on hard-close; cascade FK; data repair

- Files touched: `app/Services/MonthCloseoutService.php`, `database/migrations/2026_05_04_204012_fix_debts_transaction_id_cascade_and_repair_orphans.php`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/04-database.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Pending split debts with `transaction_id` null (previously excluded by `whereHas('transaction')`) are included in `consolidatePendingSplitDebts` on the next applicable hard-close, alongside pending rows tied to transactions in that month. Migration sets `is_pending_closeout=false` for any remaining pending rows with null `transaction_id`, and changes `debts.transaction_id` from `nullOnDelete` to `cascadeOnDelete` so deleting a split transaction removes linked split-debt rows instead of orphaning them. Feature test covers hard-close consolidation for a null-`transaction_id` pending debt.

## 2026-05-04 — Fix 500 on GET /funds when merging personal + family fund rows

- Files touched: `app/Http/Controllers/FundController.php`, `tests/Feature/FundIndexTest.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `FundController::index` mapped funds to plain arrays but left the result as an `Illuminate\Database\Eloquent\Collection`, whose `merge()` implementation expects models and called `getKey()` on array rows when a family member loaded another user’s family fund. Mapped collections are converted with `toBase()` before `merge()`, so the JSON index succeeds for all family members.

## 2026-05-04 — Dashboard: remove duplicate split balance card; split details include debt for payment labels

- Files touched: `resources/js/pages/Dashboard.vue`, `app/Http/Controllers/DebtController.php`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Removed the **Split Balance This Month** card (duplicate of per-counterpart split totals). Under **This Month's Split Expenses**, the footnote now states that View Details shows **Debt Payment: …** with the paid debt name when applicable. `splitDebtSummary` eager-loads `transaction.debt` (+ `creditor`, `debtor`, `fund`) so the bottom sheet can render the same debt payment labels as the main transaction list.

## 2026-05-04 — Debt payment list label shows payee / counterparty (`Debt Payment: …`)

- Files touched: `app/Http/Controllers/TransactionController.php`, `app/Services/DebtService.php`, `resources/js/support/debtPaymentLabel.js`, `resources/js/pages/Transactions.vue`, `resources/js/pages/Dashboard.vue`, `tests/Feature/TransactionTest.php`, `docs/ai/03-frontend-vue.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `GET /transactions` (and create/update transaction JSON) now eager-loads `debt` with `creditor`, `debtor`, and `fund`. Manual `POST /debts/pay` creditor **income** rows now set `debt_id` like the debtor expense. The Transactions list and related UI use **`Debt Payment: {name}`** where the name is the payment destination for expenses (external creditor name, member creditor, or fund) and the **debtor** for creditor-side income. Fallback remains description prefix or plain **Debt Payment** if the debt payload is absent.

## 2026-05-04 — Manual debt payments show “Debt Payment” instead of Uncategorized

- Files touched: `resources/js/pages/Transactions.vue`, `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `getTransactionCategoryLabel()` now treats any row with `is_debt_payment` as a debt line: **Debt Payment** unless the description uses the closeout prefix `Debt Payment: {name}` (then **💳 Debt Payment: {name}**). Dashboard split-detail modal uses the same logic via `splitTransactionCategoryLabel()`. Manual pay-debt rows (e.g. default “Debt payment” / “Debt received” descriptions) no longer fall through to **Uncategorized**.

## 2026-05-04 — Use contextual date for closeout-created debt payments

- Files touched: `app/Services/MonthCloseoutService.php`, `tests/Feature/MonthCloseoutTransactionDateTest.php`, `docs/ai/07-workflows.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Debt-payment transactions generated by closeout rules now use today's date when the closeout month is the current month, and use the closed month's last day when hard-closing a non-current month. Added feature tests covering both date paths.

## 2026-05-04 — Include family debts in Dashboard debt count

- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The Dashboard debt stat card now counts all debt groups returned by `GET /debts`: personal `owed`, personal `owing`, and `family_debts`. Family-shared debts now contribute to the displayed debt total.

## 2026-05-04 — Persist Transactions month filter in URL query

- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Transactions page month selection now syncs with `?month=YYYY-MM`. Refreshing the page, navigating away and using browser back/forward, or opening a copied URL keeps the selected month instead of resetting to the current month. Invalid or missing month query values fall back to the current month and normalize the URL.

## 2026-05-04 — Exclude debt-payment rows from Transactions list

- Files touched: `app/Http/Controllers/TransactionController.php`, `tests/Feature/TransactionTest.php`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `GET /transactions` now filters out rows where `is_debt_payment=true`. Debt payments still exist in the database and remain visible in debt payment history, but they no longer appear on the Transactions page or inflate transaction list totals. Added test coverage to ensure debt-payment transactions are excluded from the index response.

## 2026-05-04 — Fix debt payment history serialization error

- Files touched: `app/Http/Controllers/DebtController.php`
- Behavioral impact: **Fixed 500 error when viewing debt payment history.** The `paymentHistory()` endpoint was experiencing a serialization error when eager-loading the `paidByUser` relationship due to circular references in the Eloquent model serialization. Changed the implementation to explicitly map transaction data into arrays before JSON encoding, including only necessary fields and a minimal user object (id, name). This avoids circular reference issues while maintaining all required information for the UI display.

## 2026-05-04 — Track debt payment source and payer in payment history

- Files touched: `database/migrations/2026_05_04_183714_add_payment_details_to_transactions_table.php`, `app/Models/Transaction.php`, `app/Services/DebtService.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/DebtController.php`, `resources/js/pages/Debts.vue`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Enhanced debt history to show who made the payment and whether it was initiated from a month closeout.** Added two new fields to the `transactions` table:
  - `paid_by_user_id`: tracks which user made the debt payment (for multi-user families)
  - `is_closeout_initiated`: boolean flag indicating if the payment was triggered by a month closeout rule
  - When `DebtService::payDebt` is called, both fields are populated on the generated expense and income transactions
  - When `MonthCloseoutService::allocateToDebt` processes a debt payment during closeout, `is_closeout_initiated` is set to `true`
  - `DebtController::paymentHistory` now eagerly loads the `paidByUser` relationship and includes both new fields in the response
  - **Debts.vue Payment History modal** now displays:
    - Payment date
    - Amount
    - **Who made the payment** (e.g., "Paid by: Alice")
    - **Payment source badge** (purple "Closeout" badge if `is_closeout_initiated=true`, otherwise no badge for manual payments)
  - Mobile-friendly layout with improved spacing and visual hierarchy

## 2026-05-04 — Fix shared family debt allocation during month closeout

- Files touched: `app/Services/MonthCloseoutService.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Fixed bug where only the head of household's debt payment was applied during month closeout when multiple family members had rules contributing to the same shared debt.** Changed `allocateToDebt()` to check `family_id` instead of `debtor_id`, allowing any family member to contribute to paying down family debts through their closeout rules. Now when multiple users have allocation rules targeting the same debt:
  - Both users' allocations execute correctly
  - Each user generates their own expense transaction for their payment
  - The debt balance decreases by the total of all contributions
  - This aligns with the principle that family debts are shared resources that family members can collectively pay down during closeout

## 2026-05-04 — Create MonthSummary.vue page component

- Files touched: `resources/js/pages/MonthSummary.vue`, `resources/js/router/index.js`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **New month summary page at `/month-summary/:yearMonth` displays comprehensive financial overview.** The page shows:
  - Sticky header with back button, month label, and close status lock icon (amber for hard-closed, blue for all soft-closed, gray for open)
  - Spending by Category section: lists all transactions grouped by category, expenses in red, income in green
  - Family Balances section: shows inter-member debts from split transactions (visible only if balances exist)
  - Projected Closeout / Closeout Results section: displays basis (gross income, expenses, remaining) and preview of fund allocation rules with projected amounts
  - All data is read-only; includes loading, error, and empty states
  - Route params: `yearMonth` format (e.g., "2026-05") is split into year and month integers and passed to `GET /month-summary?year={year}&month={month}` API call
  - Mobile-first design with dark theme (gray-900 background, gray-800 cards)

## 2026-05-04 — Create MonthSummaryController for read-only month overview API

- Files touched: `app/Http/Controllers/MonthSummaryController.php`, `routes/web.php`, `docs/ai/01-architecture.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **New `GET /month-summary` endpoint provides monthly financial summary for authenticated users in a family.** Accepts `year` and `month` query parameters. Returns a comprehensive JSON response including:
  - Close status (soft/hard close records)
  - Category totals (expenses grouped by category with transaction counts)
  - Member balances (inter-member debt from split transactions)
  - Rule preview (dry-run of closeout rule allocations with projected amounts)
  All operations are read-only; no database writes occur. Requires user authentication and family membership (returns 403 if not in a family).

## 2026-05-04 — Scope `GET /transactions` to the signed-in user (own + split participation)

- Files touched: `app/Http/Controllers/TransactionController.php`, `tests/Feature/TransactionTest.php`, `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **`TransactionController::index` no longer returns every row in the family.** It returns transactions where `user_id` is the current user **or** the user has a `TransactionSplit` on that transaction. Other members’ non-split transactions are omitted. **`Dashboard.vue` uses the same endpoint**, so transaction count and “earliest open month” for closeout are derived from this scoped list only.

## 2026-05-04 — Fix bottom nav missing until refresh after login

- Files touched: `resources/js/composables/useAuth.js`, `resources/js/AppShell.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **`useAuth().user` is now one shared module-level ref**, initialized from `localStorage` when the composable module loads. `AppShell.vue` uses `useAuth()` instead of a separate `user` ref that only synced on mount. After sign-in, `login()` / `fetchUser()` update that same ref, so `AppNav` wraps the app immediately without requiring a manual refresh.

## 2026-05-04 — Restructure bottom navigation bar: 4 primary links + user menu sheet

- Files touched: `resources/js/components/AppNav.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **Bottom navigation simplified from 6 links to 4 primary links plus Account button.** Previously showed Dashboard, Transactions, Funds, Closeout Rules, Debts, and Categories as nav items, plus a user strip (name + admin dropdown + logout). Now:
  - Bottom nav displays only: Dashboard, Transactions, Funds, Debts, Account (icon button)
  - Removed from bottom nav: Closeout Rules, Categories, user strip
  - Account button (user icon) opens a bottom-sheet menu containing: Categories, Closeout Rules, My Family (if applicable), Admin: Users (if admin), Admin: Families (if admin), Logout
  - Menu header shows "Signed in as" + user name
  - Menu styled as mobile-first bottom sheet with backdrop, rounded top, smooth transitions
  - Logout now closes the menu after triggering
  - Script cleaned up: removed `showAdminMenu` ref, added `showUserMenu` ref
  - Mobile-first design: all nav buttons use same height/spacing; Account menu is fully accessible on small screens

## 2026-05-04 — Remove "Add Rule" functionality from Funds page

- Files touched: `resources/js/pages/Funds.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **"Add Rule" button and modal removed from Funds page.** Users can no longer create new allocation rules via the UI. All "Add Rule" template code (button and modal), script items (`showAddRuleModal`, `newRule` ref, `openAddRuleModal` function, `addRule` async function) have been removed. "Edit Rule" functionality remains fully intact. The action button row now shows only "Borrow" and "History" buttons.

## 2026-05-04 — Add fund movement history modal

- Files touched: `resources/js/pages/Funds.vue`, `docs/ai/03-frontend-vue.md`
- Behavioral impact: **Fund history modal is now accessible from expanded fund card.** Users can click a new "History" button (gray) in the Fund Actions area to view a bottom-sheet modal displaying all movements for that fund. The modal shows:
  - Fund name and "Movement History" subtitle
  - Current balance display (blue highlighted)
  - Sorted list of movements (newest first) with type badges (green for allocation/repayment, amber for borrow), optional description, date, and signed amount
  - Empty state message if no movements exist
  - Follows existing modal transition/styling patterns

## 2026-05-04 — Enable debt payment history tracking

- Files touched: `database/migrations/2026_05_04_164628_add_debt_id_to_transactions_table.php`, `app/Models/Transaction.php`, `app/Services/DebtService.php`, `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/DebtController.php`, `routes/web.php`
- Behavioral impact: **Debt payments are now linked to their originating debt.** Previously, payment transactions created via `DebtService::payDebt()` and `MonthCloseoutService::allocateToDebt()` were standalone expense records with no connection to the debt record. Now:
  - New nullable foreign key `transactions.debt_id` references `debts.id` with `nullOnDelete()`
  - `Transaction` model includes `debt_id` in fillable array and new `debt()` BelongsTo relationship
  - `DebtService::payDebt()` sets `debt_id => $debt->id` when creating the expense transaction
  - `MonthCloseoutService::allocateToDebt()` sets `debt_id => $debt->id` when creating closeout allocation expense
  - New endpoint `GET /debts/{debt}/payments` returns all transactions linked to a specific debt, ordered by `transaction_date` (desc) then `created_at` (desc), returning fields: id, amount, description, transaction_date, type, created_at
  - Authorization: user must be debtor, creditor, or family manager to view payment history
  - Enables frontend UI to display full payment history for a debt without parsing transaction descriptions or scanning all transactions

## 2026-05-04 — Document mobile-first UI as default for agents

- Files touched: `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`, `.cursor/rules/001-project-overview.mdc`, `.cursor/rules/003-vue-frontend.mdc`, `AGENTS.md`, `CLAUDE.md`
- Behavioral impact: **No runtime change.** AI-facing docs and Cursor rules now state that the app is **mainly used on mobile** and that UI work should be **mobile-first** (narrow viewports, touch, Tailwind defaults). Agents are pointed to `docs/ai/03-frontend-vue.md` for concrete guidance.

## 2026-05-04 — Simplify Debts page layout: remove separators, use family icon only

- Files touched: `resources/js/pages/Debts.vue`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Debts page now has a unified list with no section dividers.** Previously, personal and family debts were in separate sections with headers ("Personal Debts", "Family Debts"), a divider line, and a description "(shared with your family)". Now:
  - All debts (personal and family) are displayed in a **single continuous list** without separators
  - **Family debts are distinguished only by the 👥 icon** in the top-right corner (purple badge with family icon)
  - Section titles "Personal Debts" and "Family Debts" removed
  - Border separator line removed
  - Description "(shared with your family)" removed
  - Cleaner, more streamlined UI while maintaining visual distinction via the family icon
  - All debt information, amounts, and action buttons remain unchanged

## 2026-05-04 — Debt payment transactions now display creditor name instead of "Uncategorized"

- Files touched: `app/Services/MonthCloseoutService.php`, `resources/js/pages/Transactions.vue`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Debt payment transactions created during month closeout now display clearly.** Previously, when a closeout rule paid down a debt (e.g., $200 to a debt), the resulting expense transaction appeared as "Uncategorized" on the Transactions page. Now:
  - `MonthCloseoutService::allocateToDebt()` sets transaction description to format `"Debt Payment: {creditor_name}"` (e.g., "Debt Payment: John Smith")
  - New helper function `getTransactionCategoryLabel()` in Transactions.vue detects debt payments via `is_debt_payment` flag and `description` prefix
  - Debt payment transactions display with a **💳 prefix** and the creditor name (e.g., "💳 Debt Payment: John Smith") instead of blank category
  - Example: Income $1000, close month → Debt rule creates expense "Debt Payment: John Smith" → Displays as "💳 Debt Payment: John Smith" on transaction list
  - Category icon is suppressed for debt payments (only creditor name shown)

## 2026-05-04 — Fix: Month closeout allocation shortfall when debt rules don't fully allocate

- Files touched: `app/Services/MonthCloseoutService.php`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: **Bug Fix: Rules now correctly allocate remaining pool to subsequent rules.** When a fixed-amount debt allocation rule (e.g., "pay $500 toward debt") is applied during month hard-close but the debt only has a $200 balance (so only $200 is actually paid), the system was incorrectly treating the full $500 as "spent", leaving less money for subsequent "remaining" base rules (e.g., "10% of remaining to fund"). Now:
  - **Each allocation function returns the actual amount allocated** (not the attempted amount), distinguishing between requested vs. actual.
  - **`$grossRemaining` and `$remainingPool` are decremented by actual allocations**, not requested allocations.
  - **Subsequent rules see the correct available balance**, so if debt allocation only partially succeeded, the next rule gets the full unspent balance.
  - Example scenario now works: Income $1000, Debt rule $500 (but debt balance is $200), Fund rule 10% of remaining → Debt gets $200, Fund correctly gets 10% of $800 = $80 (was getting nothing).
  - Technical change: `applyRuleAllocation()`, `allocateToFund()`, `allocateToDebt()`, `allocateToTitle()` now return `float` (actual allocated amount) instead of `void`; allocation loops updated to use returned values for pool decrement.

## 2026-05-04 — Improve Closeout Rules debt selection and allocation UX

- Files touched: `resources/js/pages/CloseoutRules.vue`, `resources/js/pages/Funds.vue`
- Behavioral impact: (1) **Closeout Rules debt selector now shows better debt labels**: if description exists, use it; otherwise show family debt labels (`Family Debt: Debtor → Creditor`), or external debt labels (`Debt to X`), or member debt labels; fall back to `Debt #ID` only if all else fails. (2) **All debt types now appear in selector**: personal debts where user is owed, personal debts where user owes, and family-shared debts (previously only showed "owed" debts). (3) **Fixed amount allocations no longer show "Applied To" field**: the `allocation_base` select (Gross Income, Remaining) now only displays when `allocation_type === 'percentage'`. When fixed amount is selected, the field is hidden (no "applied to" concept for fixed amounts). Applied in both CloseoutRules and Funds rule modals (Add Rule and Edit Rule).




## 2026-05-04 — Separate admin role from family roles system
- Files touched: `database/migrations/2026_05_04_013436_add_is_admin_to_users_table.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/AdminController.php`, `resources/js/pages/admin/Users.vue`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) **User roles now: `member` | `head_of_household` only** — no more `admin` role. (2) **New `is_admin` boolean column** on `users` table (default false); existing `admin` role users migrated to `head_of_household` + `is_admin=true`. (3) `User.isAdmin` accessor now reads the DB column instead of checking `role === 'admin'`. (4) `User.canManageFamily` accessor checks `role === 'head_of_household' OR is_admin`. (5) Gate `admin` now checks `user->is_admin` (boolean). Gate `manage_family` checks `role === 'head_of_household' OR is_admin`. (6) `AdminController::createUser` and `::updateUser` validation now only allow `member` and `head_of_household` roles; added `is_admin` boolean validation and handling. (7) Vue component `resources/js/pages/admin/Users.vue` now shows **separate role select** (member/head_of_household) and **separate admin checkbox** with clear label "System Admin — Can manage all users and families"; user display badges show role and admin status separately. (8) API responses continue to include `is_admin` appended attribute for frontend.

## 2026-05-04 — Auto-hard-close for single-member families on soft close
- Files touched: `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/MonthCloseoutController.php`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) `MonthCloseoutService::softClose()` now returns `array{soft_close: MonthSoftClose, hard_close: MonthHardClose|null}` instead of `MonthSoftClose`. (2) When a family has only one member, `softClose()` automatically triggers `hardClose()` immediately (no one else to wait for). (3) `MonthCloseoutController::softClose()` handles the array return and includes `hard_close` and `auto_hard_closed=true` in the response JSON when auto-hard-close occurs. (4) API route `POST /closeout/soft-close` now returns `{message, data (soft_close), hard_close?, auto_hard_closed?}` instead of just `{message, data (soft_close)}`. (5) Single-member families see their month as fully closed (hard-close) immediately after clicking "Close Out", skipping the multi-member approval workflow.

## 2026-05-04 — Debt system overhaul: Three debt types (personal/external, in-family, family-shared)
- Files touched: `database/migrations/2026_05_04_010914_add_debt_scope_fields_to_debts_table.php`, `app/Models/Debt.php`, `app/Http/Controllers/DebtController.php`, `app/Services/DebtService.php`, `routes/web.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) New migration adds `is_family_debt` (bool, default false) and `creditor_name` (varchar nullable) to `debts` table. (2) `DebtController::store` now supports three debt types: **personal to external** (`creditor_name` provided, no `creditor_id`), **in-family** (`creditor_id` = different family member, `is_interfamily=true`), and **family-shared** (`is_family_debt=true`, visible to all family members). Auth user is always the debtor. (3) `DebtController::index` now returns `{owed, owing, family_debts}` with `family_debts` showing debts visible to all. (4) New `DebtController::destroy` route allows debtor or family manager to delete debts. (5) `DebtService::payDebt` updated to allow any family member to pay family debts, vs. only debtor for personal debts. (6) Routes: added `DELETE /debts/{debt}` route, added `Route::view('/debts', 'app')` for SPA shell.

## 2026-05-03 — Closeout rules PUT/POST: fix 500 (`Request::validated` on plain Request)
- Files touched: `app/Http/Controllers/FundController.php`, `database/migrations/2026_05_03_160512_update_fund_rules_for_closeout_system.php`, `tests/Feature/CloseoutRulesApiTest.php`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `storeRule` / `updateRule` now use the array returned by `$request->validate(...)` instead of `$request->validated()`, which is not available on `Illuminate\Http\Request` in this Laravel version and caused HTTP 500 on `POST`/`PUT /closeout-rules`. The `fund_rules` closeout migration is split into two schema steps (add destination columns, then nullable `fund_id`) for better cross-driver behavior. API docs updated for `/closeout-rules`. AI docs corrected: fund rules run on hard-close, not on each income transaction; `FundAllocationTest` noted as out of sync.

## 2026-05-03 — Debts: Filter pending split debts, consolidate on hard-close
- Files touched: `app/Http/Controllers/DebtController.php`, `app/Services/MonthCloseoutService.php`, `app/Services/DebtService.php`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) Debts page (`GET /debts`) now filters out `is_pending_closeout=true` debts, showing only manually-added debts and confirmed debts from past months; (2) On hard-close, pending split debts are netted per person-pair, consolidated into single running debts, and replace previous period entries (or create new ones if no existing debt); dozens of small split records become one summary debt per pair; (3) `DebtService::payDebt` now rejects attempts to pay pending split debts with an error message directing users to wait for month hard-close.

## 2026-05-03 — useApi: expose `delete` alias for HTTP DELETE
- Files touched: `resources/js/composables/useApi.js`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Pages that destructure `delete: del` from `useApi()` (e.g. admin Users, Families, MyFamily, Categories) receive a real DELETE helper instead of `undefined`; user/family/category deletes work again.

## 2026-05-03 — Transactions: Close Out header button copy and visibility
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Soft-close control labels are **Close Out** and **Undo**; the control is hidden for a selected calendar month that is **hard-closed** or has **no loaded transactions** (unless you already **soft-closed**, so **Undo** remains). Lock icon tooltips updated to match.

## 2026-05-03 — Transactions list: mobile layout keeps amount + split beside category
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Transaction cards no longer use a stacked `flex-col` row on small screens; amount and split pill stay in a **right column** next to the category so split rows do not jump under the title; right column width is capped on mobile for balance

## 2026-05-03 — Dashboard: Family close progress targets earliest open transaction month
- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Closeout status for **Family close progress** uses the **first calendar month (by date) that has at least one transaction and is not hard-closed** (`GET /closeout/closed-months` + transaction dates); the UI shows that month’s label and is **omitted** when there is no such month. Per-member lock icons no longer sit in a bordered/shaded box.

## 2026-05-03 — Dashboard: per-member lock icons on Family close progress
- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Each family member row shows the same **open vs closed** lock **SVGs** as **Transactions** (hard-closed month → amber closed lock for all; else soft-closed → blue closed lock; else gray open lock) in a small bordered cell, with per-member titles

## 2026-05-03 — Family close progress moved from Transactions to Dashboard
- Files touched: `resources/js/pages/Transactions.vue`, `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The **Family close progress** UI (member soft-close checklist + **Hard close month** for eligible managers) is removed from the Transactions filter area; Dashboard shows it for the **current calendar month** using the same card/section styling as the rest of that page (uppercase gray section title, `rounded-xl` gray card, helper footnote). Transactions still supports soft close / lock icon / closeout status for the **selected** month via existing header and fetches.

## 2026-05-03 — Transactions: split pill spacing and vertical alignment
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Removed top margin above the purple split summary control; pill content uses flex vertical centering (`items-center`, `leading-tight`) so label text sits evenly in the chip

## 2026-05-03 — Named `login` route for auth redirects
- Files touched: `routes/web.php`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Unauthenticated or expired-session requests to `auth` routes (e.g. GET `/transactions`) redirect to `/login` without `RouteNotFoundException`

## 2026-05-03 — Transactions: split strip shows “You” when you paid
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The purple **“Split: Total … by …”** line uses **You** instead of your name when the logged-in user is the transaction owner (`user_id`)

## 2026-05-03 — Transactions: purple “Split” strip + split breakdown modal
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Removed the inline **Split** pill next to the category name
  - Split summary uses the **purple** chip color scheme and copy **“Split: Total … by {payer name}”**; clicking it opens a modal (not the edit form) with each participant’s **amount** and **percentage**, **you first**, then alphabetical; **(You)** on the signed-in user’s row

## 2026-05-03 — Transactions: split subline panel, baseline alignment, expense totals use your share
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Split “who paid” line sits in a **lighter** bordered panel; amount, separator, and name use **one baseline** (`inline-flex items-baseline`) so the name matches the description line height
  - **Expense** period total and each day’s **expense** subtotal count **your portion** for split expenses; **income** totals and daily income sums still use full transaction amounts
  - Short footnote under the period totals explains split expense behavior

## 2026-05-03 — Transactions list: split rows show your share + total paid by whom
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Rows with `splits` show the **current user’s portion** as the main amount (green/red by type)
  - A secondary line shows the full transaction amount and the transaction owner’s name; expenses use “Total paid … · {name}”, income uses “Total … · {name}”
  - Non-split rows unchanged
  - Initial mount awaits `GET /user` before loading transactions so the viewer’s split share is known when the list first renders

## 2026-05-03 — Dashboard split details: category + optional description
- Files touched: `app/Http/Controllers/DebtController.php`, `resources/js/pages/Dashboard.vue`, `tests/Feature/SplitDebtSummaryTest.php`, `docs/ai/03-frontend-vue.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - `GET /split-debt-summary` eager-loads `transaction.category` so each pending split row includes category data
  - **View Details** modal shows the transaction category as the main label (falls back to `Uncategorized` if missing); a non-empty description appears in smaller text immediately to the right of the category (same row when space allows); empty descriptions no longer show “No description”

## 2026-05-03 — Transactions: lock on month filter, header close-out, top totals
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Month filter row shows a lock affordance (open vs closed) tied to your soft-close and family hard-close state; dropdown options no longer append lock emoji
  - **Mark my month closed out** / **Undo my close** moved to the top-right of the sticky Transactions header (hidden when the month is hard-closed)
  - Income and expense totals for the active filter range render below the filters whenever the page is not loading/errored, including when there are zero transactions

## 2026-05-03 — Transactions month status panel defaults to collapsed
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - The Transactions page "Month Status" panel now starts closed on initial render
  - Users can still expand/collapse it manually via the existing header toggle


## 2026-05-03 — Auto-logout on session timeout in SPA
- Files touched: `resources/js/app.js`, `docs/ai/03-frontend-vue.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/07-workflows.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Added a global Axios response interceptor in the Vue entrypoint
  - Any `401` or `419` response from authenticated requests now clears local auth state and redirects to `/login`
  - Prevents the app from remaining in a stale "logged in" UI state after the backend session expires

## 2026-05-03 — Family-scoped funds support added
- Files touched: `app/Http/Controllers/FundController.php`, `app/Policies/FundPolicy.php`, `resources/js/pages/Funds.vue`, `resources/js/pages/CloseoutRules.vue`, `docs/ai/08-api-routes.md`
- Behavioral impact:
  - **Backend:**
    - `FundController::index()` now returns both personal funds (user_id only) and family-scoped funds (family_id set) with `scope` appended to each (`scope: 'personal'` or `scope: 'family'`)
    - `FundController::store()` validates optional `is_family_fund` boolean; if true and user has `family_id`, creates fund with both `user_id` and `family_id`
    - `FundPolicy` updated: `view()` and `update()` allow access to user's personal funds OR family-scoped funds in same family; `delete()` requires fund ownership (personal) OR family membership + `can_manage_family` (family-scoped)
  - **Frontend:**
    - Funds.vue imports `useAuth()` to check `user.family_id`
    - Fund card displays scope badge: "Personal" (gray) or "Family" (purple) next to fund name
    - New Fund modal shows "Family Fund" checkbox only if user has `family_id`
    - `createFund()` includes `is_family_fund` in POST payload and resets to `false` after creation
    - CloseoutRules.vue groups fund options into optgroups: "Personal Funds" and "Family Funds" (latter only if any exist)
    - New computed properties `personalFunds` and `familyFunds` filter funds by scope

## 2026-05-03 — Split debt summary cards added to Dashboard.vue
- Files touched: `resources/js/pages/Dashboard.vue`
- Behavioral impact:
  - Dashboard now fetches `/split-debt-summary?year={year}&month={month}` on mount for current month
  - New "This Month's Split Expenses" section displays above stats grid (only when splitDebtSummary.length > 0)
  - Each split debt shows person name, net amount ("you owe X", "they owe you X", or "Settled" with appropriate colors: red, green, gray)
  - "View Details" button opens bottom-sheet modal showing all transactions for that split pair
  - Modal displays date, description, amount with direction label, and formatted currency for each transaction
  - New helper functions: `formatCurrency()`, `formatDate()`, `getNetAmountText()`, `getNetAmountClass()`, `getTransactionAmountClass()`, `openDetailsModal()`
  - Modal uses Transition component with slide-up animation (matches Funds.vue pattern)
  - Note displayed below section: "These splits will be applied to your debt balance when your family closes the month."
  - Stats grid moved below split debt section

## 2026-05-03 — CloseoutRules.vue page created for independent rule management
- Files touched: `resources/js/pages/CloseoutRules.vue`, `resources/js/router/index.js`, `resources/js/components/AppNav.vue`
- Behavioral impact:
  - New dedicated page for managing closeout rules (replaces fund-specific rules UI)
  - Fetches rules from GET `/closeout-rules` on mount
  - Rules displayed as cards with order badge, name, allocation display, destination label, and status dot
  - Supports CRUD operations: Create (POST), Read (GET), Update (PUT), Delete (DELETE)
  - Add/Edit modal (bottom-sheet) supports all rule fields:
    - Name, Order, Allocation Type (percentage or fixed)
    - Amount, Applied To (gross_income or remaining)
    - Destination: Fund (with fund select), Debt (with user's owed debts), or Title (custom text input)
    - Active toggle
  - Deletes use confirm-on-first-click pattern (matches existing UI)
  - Rules sorted by order on display
  - Destination labels show fund names, debt descriptions, or custom titles
  - Routes to `/closeout-rules` and integrated into navigation between Funds and Debts
  - Mobile-first design with dark theme consistent with existing pages

## 2026-05-03 — Month closeout status panel added to Transactions page
- Files touched: `resources/js/pages/Transactions.vue`
- Behavioral impact:
  - New collapsible status panel displays below month filter (only for selected months, not custom range)
  - Panel shows "Month Status" header with dynamic status badge (Open, Ready to Close, or Closed)
  - Badge colors: gray for Open, blue for Ready to Close, amber for Closed
  - Lists all family members with checkmarks showing who has soft-closed
  - Action buttons appear at panel bottom:
    - "Mark My Month Done" button (blue) if user hasn't soft-closed and month isn't hard-closed
    - "Undo My Close" button (gray) if user has soft-closed and month isn't hard-closed
    - "Hard Close Month" button (amber, requires can_manage_family) if all members soft-closed and month not hard-closed
  - Hard close shows confirmation dialog before proceeding
  - Panel is collapsible with chevron icon in header that rotates on toggle
  - Status updates are fetched from `/closeout/status` endpoint on month change
  - Actions trigger appropriate closeout endpoints and reload status + transactions
  - Mobile-first design with dark theme consistent with existing page styling

## 2026-05-03 — Month closure UI indicators and transaction locking
- Files touched: `app/Http/Controllers/MonthCloseoutController.php`, `routes/web.php`, `resources/js/pages/Transactions.vue`
- Behavioral impact:
  - **Backend:** New `closedMonths()` method in MonthCloseoutController returns list of hard-closed months for family
  - **Backend:** New GET `/closeout/closed-months` route for fetching closed month list
  - **Frontend:** Transactions.vue now fetches closeout status on month change via POST to `/closeout/status`
  - **Frontend:** New `closeoutStatus` ref and `isCurrentMonthHardClosed` computed to track month closure state
  - **Frontend:** New `closedMonths` ref populated from `/closeout/closed-months` endpoint on component mount
  - **Frontend:** New `isMonthClosed(year, month)` helper checks if a specific month is hard-closed
  - **Frontend:** Month selector now displays 🔒 emoji next to closed months
  - **Frontend:** Visual badge appears below month filter when selected month is hard-closed with lock icon and message
  - **Frontend:** Lock icon (amber padlock SVG) displays on each transaction when month is hard-closed
  - **Frontend:** Transactions are visually disabled when month is closed (reduced opacity, cursor-not-allowed)
  - **Frontend:** Delete button is disabled with tooltip when month is hard-closed
  - **Frontend:** Transaction rows cannot be clicked to edit when month is hard-closed

## 2026-05-03 — Split debt summary API endpoint added
- Files touched: `app/Http/Controllers/DebtController.php`, `routes/web.php`
- Behavioral impact:
  - New `splitDebtSummary()` method in DebtController returns pending split debts for a given month grouped by counterpart user
  - Validates year and month parameters
  - Filters to pending split debts only (is_pending_closeout = true)
  - Returns array of summaries keyed by counterpart ID with: counterpart user object, you_owe total, they_owe total, and transaction details
  - New GET `/split-debt-summary` route (requires authentication and family_id)
  - Powers dashboard card showing split debt status for the month

## 2026-05-03 — Controllers and routes for month closeout system
- Files touched: `app/Http/Controllers/FundController.php`, `app/Http/Controllers/MonthCloseoutController.php`, `routes/web.php`
- Behavioral impact:
  - **FundController updates:** 
    - `showRules()` now returns all rules for auth user (removed Fund parameter)
    - `storeRule()` adds validation for `destination_type` (fund/debt/title), `destination_id`, `destination_title`; makes `fund_id` nullable; uses direct FundRule::create() instead of fund authorization
    - `updateRule()` validates new destination fields, checks rule belongs to auth user instead of checking fund ownership
    - New `destroyRule()` method deletes rules that belong to auth user
  - **MonthCloseoutController created (new):**
    - `status()` returns month status array with soft closes, hard close, flags
    - `softClose()` creates soft close record with validation and exception handling
    - `undoSoftClose()` removes soft close records
    - `hardClose()` requires `can_manage_family` role, executes full closeout workflow
  - **Routes updates:**
    - New `/closeout-rules` GET/POST/PUT/DELETE routes for rule management (replaces fund-specific routes)
    - `/closeout/status`, `/closeout/soft-close`, `/closeout/undo-soft-close`, `/closeout/hard-close` POST endpoints
    - `/closeout-rules` view route added
    - Backward compatibility: kept `/funds/{fund}/rules` GET route pointing to showRules()
    - Old `/fund-rules` routes removed in favor of `/closeout-rules`

## 2026-05-03 — Month closeout service created (full implementation)
- Files touched: `app/Services/MonthCloseoutService.php`
- Behavioral impact:
  - New service with 8 methods for soft/hard closing months
  - `softClose()` creates per-user soft close records with validation
  - `undoSoftClose()` removes soft closes with state checks
  - `allMembersSoftClosed()` verifies all family users have soft-closed
  - `isHardClosed()` checks if month is hard-closed
  - `getMonthStatus()` returns comprehensive month status array
  - `hardClose()` executes full month closeout: applies all user rules, confirms split debts, creates hard close record
  - Private `processUserCloseoutRules()` calculates gross income, processes rules (gross/fixed first, then remaining-based), and allocates amounts
  - Private `applyRuleAllocation()` routes allocations to funds, debts, or titled savings based on rule `destination_type`
  - Comprehensive allocation methods handle fund balance increments with movement records, debt payment creation, and title savings creation

## 2026-05-03 — Transaction service updated for month closeout (remove fund processing)
- Files touched: `app/Services/TransactionService.php`
- Behavioral impact:
  - Removed automatic fund income processing from `createTransaction()` and `updateTransaction()` — funds will now only be updated during month hard-closes
  - Removed `FundService` dependency from constructor (was only used for income processing)
  - Constructor is now empty and removed per project rules (no empty public zero-param constructors)
  - Split debts now created with `is_pending_closeout => true` flag in both `createTransaction()` and `updateTransaction()` methods
  - All existing transaction tests pass (6 tests, 11 assertions)

## 2026-05-06 — Financial integrity test suite (5 complex multi-month tests)
- Files touched: `tests/Feature/FinancialIntegrityTest.php` (new)
- Added `FinancialIntegrityTest` with 5 end-to-end scenario tests covering all major features
  across realistic multi-month time spans with real-world dollar amounts (107 assertions total):
  1. **Split expense → pending debt → hard-close → debt payment with bank balance accuracy**:
     Two-member family; Alice earns salary, pays a 50/50 split dinner, Bob cannot pay the
     pending debt before closeout, hard-close consolidates it, Bob pays in February; Alice's
     computed bank balance matches the exact transaction sum at every checkpoint.
  2. **Single-member fund allocation, advance settlement, and title savings bank balance**:
     Sarah earns $5,000, spends on medical (advance-tagged) and groceries, soft-closes
     (auto hard-close); Emergency Savings receives 10% rule allocation then is decremented
     by advance settlement; a title saving is created but only reduces the bank balance once
     marked complete — verifying fund allocations do NOT create phantom income/expense.
  3. **Three-member family split netting accumulates across two month closeouts**:
     Alice, Bob, and Carol each pay shared bills across February and March; the netting
     algorithm correctly combines opposing pending debts per person-pair; Carol's confirmed
     debt grows from $90 to $165 across two closes; Alice pays her net $10 to Bob.
  4. **Fund borrow and repayment bank balance integrity**:
     John borrows $500 from Emergency Fund (income +$500, fund −$500), spends $300,
     repays $200 (expense −$200, fund +$200); final bank balance returns exactly to
     the original anchor — no drift at any intermediate step.
  5. **Complete two-month household simulation (all major features)**:
     Sarah and Mike; Emergency Savings with advance-tagged groceries category; closeout
     rules for fund allocation and title saving; split expenses across electricity and
     internet bills; hard-close nets the split debts; Mike pays Sarah the $8 net debt
     in April; Sarah marks title saving complete; final computed balance $11,338 matches
     real-world arithmetic: $5,000 + $7,000 earned + $8 received − $370 spent − $300 transferred.
- Behavioral impact: Tests only — no production code changed. All 5 tests pass (107 assertions).

## 2026-05-03 — Month closeout system foundation (migrations & models)
- Files touched: 
  - Migrations: `database/migrations/2026_05_03_160512_add_family_id_to_funds_table.php`, `database/migrations/2026_05_03_160512_update_fund_rules_for_closeout_system.php`, `database/migrations/2026_05_03_160512_create_month_soft_closes_table.php`, `database/migrations/2026_05_03_160512_create_month_hard_closes_table.php`, `database/migrations/2026_05_03_160513_add_is_pending_closeout_to_debts_table.php`, `database/migrations/2026_05_03_160513_create_closeout_title_savings_table.php`
  - Models: `app/Models/MonthSoftClose.php`, `app/Models/MonthHardClose.php`, `app/Models/CloseoutTitleSaving.php`, `app/Models/Fund.php`, `app/Models/FundRule.php`, `app/Models/Debt.php`, `app/Models/Family.php`, `app/Models/User.php`
- Behavioral impact: 
  - New `month_soft_closes` table tracks per-user monthly soft closes (records when a user finalizes month data)
  - New `month_hard_closes` table tracks per-family monthly hard closes (records when family admin finalizes a month)
  - New `closeout_title_savings` table allows fund rules to save to titled records during closeout (e.g., "Medical Reserve")
  - `funds` table now links to `families` via optional `family_id` (family-level funds future-proofing)
  - `fund_rules` now support flexible routing: `destination_type` (fund/debt/title) + `destination_id` + `destination_title` allow rules to target debts or custom title-based savings instead of just funds
  - `debts` table now tracks `is_pending_closeout` flag (marks debts awaiting closeout processing)
  - All new models include proper relationships to Family and User; updated existing models with corresponding reverse relationships

## 2026-05-04 — Add monthly-totals endpoint and Dashboard summary card
- Files touched: `app/Http/Controllers/DashboardController.php`, `routes/web.php`, `resources/js/pages/Dashboard.vue`, `docs/ai/06-feature-map.md`, `docs/ai/08-api-routes.md`
- Created `DashboardController::monthlyTotals()` endpoint returning current-month income/expense totals for auth user (excludes debt-payment transactions)
- Added route `GET /dashboard/monthly-totals`
- Updated Dashboard Vue component to fetch monthly totals and render a two-column card displaying income (green) and expenses (red) above the transaction/funds/debts cards
- Updated documentation to reflect new endpoint and enhanced Dashboard feature

## 2026-05-04 — Include debt-payment transactions in Transactions API index
- Files touched: `app/Http/Controllers/TransactionController.php`, `tests/Feature/TransactionTest.php`, `docs/ai/06-feature-map.md`
- Removed the `.where('is_debt_payment', false)` filter from `TransactionController::index` so all transactions (including debt payments) are returned
- Renamed `test_transactions_index_excludes_debt_payment_rows` → `test_transactions_index_includes_debt_payment_rows` and updated assertions to confirm both normal and debt-payment transactions appear in the response

## 2026-05-03 — Initial AI documentation set created
- Files touched: `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Documentation only — no code changes made
