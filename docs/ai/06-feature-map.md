# 06 — Feature Map

This document maps each user-visible feature to the backend and frontend files that implement it.

---

## 1. Authentication

**What it does:** Email/password login and logout. Session-based.

| Layer | Files |
|---|---|
| Backend | Fortify routes (auto-registered), `app/Actions/Fortify/CreateNewUser.php`, `app/Providers/FortifyServiceProvider.php` |
| Frontend | `resources/js/pages/Login.vue`, `resources/js/composables/useAuth.js`, `resources/js/support/authUser.js` |
| Config | `config/fortify.php` |

---

## 2. Dashboard / Summary

**What it does:** Shows current-month income/expense totals and displays the same **viewer-scoped** transaction list as the Transactions page (see section 3). Also displays **This Month's Split Expenses** (per-counterpart net and a View Details sheet); there is no separate aggregate “split balance” card.

| Layer | Files |
|---|---|
| Backend | `GET /transactions` → `TransactionController::index`; `GET /dashboard/monthly-totals` → `DashboardController::monthlyTotals` |
| Frontend | `resources/js/pages/Dashboard.vue` |

**Monthly totals:** `DashboardController::monthlyTotals` calculates income and expense sums for the auth user for the current calendar month (excluding debt-payment transactions). Values are displayed in a two-column card above the transaction count cards.

---

## 3. Transactions

**What it does:** Record income and expense transactions for a family. Support date filtering, category assignment, split between members, edit, and delete. The index list is **scoped to the signed-in user**: their own transactions plus any family transaction where they appear in `transaction_splits` (shared splits created by someone else). **All transactions are included**, including debt payment rows (`is_debt_payment=true`), except the mirrored **expense** leg for a **creditor** who is also on that expense’s splits (same payment as their `debt_id` income row). The month picker state is persisted in the route query as `month=YYYY-MM`, so refresh/back-forward navigation restores the selected month.

| Layer | Files |
|---|---|
| Backend | `TransactionController` (index, store, update, destroy) |
| Service | `app/Services/TransactionService.php` |
| Model | `app/Models/Transaction.php`, `app/Models/TransactionSplit.php` |
| Request | `app/Http/Requests/StoreTransactionRequest.php` |
| Frontend | `resources/js/pages/Transactions.vue`, `resources/js/components/TransactionForm.vue`, `resources/js/components/SplitEditor.vue` |

**Split sub-feature:** When `is_split = true`, the transaction form shows `SplitEditor`. On save, `TransactionService` creates `TransactionSplit` records and `Debt` records (one per non-owner split participant). The transaction owner is the creditor; each other participant is a debtor.

---

## 4. Categories

**What it does:** Manage income/expense categories per family. Categories can have a default split template.

| Layer | Files |
|---|---|
| Backend | `CategoryController` (index, store, update, destroy) |
| Model | `app/Models/Category.php` |
| Request | `app/Http/Requests/StoreCategoryRequest.php` |
| Frontend | `resources/js/pages/Categories.vue`, `resources/js/components/IconPicker.vue` |

**`split_default`:** A JSON field storing a default split configuration `[{user_id, share_percentage}]`. When a transaction is created with this category, the frontend can pre-populate the split editor. Server-side, this field is stored and returned but not automatically applied.

---

## 5. Funds (Personal Savings Buckets)

**What it does:** Each user has personal "funds" — named savings buckets with a running balance. Households can also have **family** funds (`family_id` on the row). `GET /funds` merges the signed-in user’s **personal** funds (`family_id` null) with all **family** funds for their family so a fund the user created as family-scoped appears once (`scope: 'family'`), not again as personal.

| Layer | Files |
|---|---|
| Backend | `FundController` (index, store, update, destroy, showRules, storeRule, updateRule, borrow, repayFund) |
| Service | `app/Services/FundService.php` |
| Models | `app/Models/Fund.php`, `app/Models/FundRule.php`, `app/Models/FundMovement.php` |
| Requests | `app/Http/Requests/StoreFundRequest.php` (unused), `app/Http/Requests/StoreFundRuleRequest.php` (unused) |
| Frontend | `resources/js/pages/Funds.vue` (fund History modal shows **By {name}** per movement; `GET /funds` eager-loads `movements.user`) |
| Policy | `app/Policies/FundPolicy.php` |

**Fund Rules:** Define how income is automatically allocated. Each rule has:
- `allocation_type`: `percentage` or `fixed`
- `allocation_base`: `gross_income`, `net_income`, or `remaining`
- `order`: processing priority
- `is_active`: whether the rule runs

---

## 6. Fund Borrowing

**What it does:** A user can borrow money from their fund. This creates an income transaction (tagged `is_borrow=true`), decrements the fund balance, and creates a debt record linking the user to their fund.

| Layer | Files |
|---|---|
| Backend | `POST /funds/{fund}/borrow` → `FundController::borrow` |
| Service | `app/Services/FundService.php::borrowFromFund` |
| Models | `Fund`, `FundMovement`, `Debt`, `Transaction` |
| Frontend | `resources/js/pages/Funds.vue` (borrow form inline) |

---

## 7. Fund Repayment

**What it does:** A user repays a fund debt. This creates an expense transaction (`is_debt_payment=true`), increments the fund balance, creates a `FundMovement` (type=`repayment`), and decrements the debt balance.

| Layer | Files |
|---|---|
| Backend | `POST /debts/{debt}/repay-fund` → `FundController::repayFund` |
| Service | `app/Services/FundService.php::repayFund` |
| Frontend | `resources/js/pages/Funds.vue` (repay form inline) |

---

## 8. Debts

**What it does:** Track money owed between family members. Debts are created automatically from split transactions. They can also be created manually. Payments reduce the `balance` field.

**Split debts & hard-close:** When a split transaction is created, temporary `is_pending_closeout=true` debts are created. These are hidden from the Debts page (GET /debts filters them out). On hard-close, `MonthCloseoutService::consolidatePendingSplitDebts` includes pending rows whose linked transaction is in the closed month **or** whose `transaction_id` is null (orphans left when a split transaction was deleted under the old `nullOnDelete` FK). Those rows are **netted per person-pair** (if A owes B $10 and B owes A $5, only one $5 debt remains from B to A). Netting results are consolidated into single running debts per pair—either updating an existing confirmed debt or creating a new one. All included pending split rows are then deleted. Deleting a split transaction now cascades to remove its linked split-debt rows (`debts.transaction_id` → `cascadeOnDelete`).

**Payment guard:** `DebtService::payDebt` rejects attempts to pay `is_pending_closeout=true` debts, directing users to wait for the month's hard-close.

|| Layer | Files |
||---|---|
|| Backend | `DebtController` (index, store, payDebt, `paymentHistory`), `DebtController::splitDebtSummary` |
|| Service | `app/Services/DebtService.php`, `app/Services/MonthCloseoutService.php::consolidatePendingSplitDebts` |
|| Model | `app/Models/Debt.php` |
|| Request | `app/Http/Requests/PayDebtRequest.php` |
|| Frontend | `resources/js/pages/Debts.vue` |
|| Policy | `app/Policies/DebtPolicy.php` (not actively invoked) |

**Debt creation sources:**
1. Automatic (split transaction, `is_pending_closeout=true`): via `TransactionService`
2. Automatic (fund borrow): via `FundService`
3. Manual: `POST /debts`

**Debt payment (`DebtService::payDebt`):** Creates two transactions — an expense for the debtor, and an income for the creditor (if `creditor_id` is not null / not a fund debt). Rejects pending split debts. **`GET /debts/{debt}/payments`** lists one entry per pay action for those debts by excluding the mirror **income** row when a matching **expense** exists (paired on `debt_id`, date, amount, `paid_by_user_id`, and `created_at`).

---
## 9. Family Management (My Family)

**What it does:** Allows `head_of_household` or `admin` to view their family, add/remove members.

| Layer | Files |
|---|---|
| Backend | `GET /my-family`, `POST /admin/families/{family}/users`, `DELETE /admin/families/{family}/users/{user}` → `AdminController` |
| Middleware | `can:manage_family` |
| Frontend | `resources/js/pages/MyFamily.vue` |

---

## 10. Admin — Users

**What it does:** Global admin can view all users, create, update, delete.

| Layer | Files |
|---|---|
| Backend | `GET/POST/PUT/DELETE /admin/users` → `AdminController` |
| Middleware | `can:admin` |
| Frontend | `resources/js/pages/admin/Users.vue` |

---

## 11. Admin — Families

**What it does:** Global admin can view all families, create families.

| Layer | Files |
|---|---|
| Backend | `GET /admin/families`, `POST /admin/families` → `AdminController` |
| Middleware | `can:admin` |
| Frontend | `resources/js/pages/admin/Families.vue` |

---

## 12. Admin — Categories (Broken / Incomplete)

**What it does:** Intended to let admins manage categories. Route exists in the Vue router, but `POST /admin/categories` does not exist in `web.php`.

| Layer | Files |
|---|---|
| Backend | `Route::view('/admin/categories', 'app')` (SPA shell only — no JSON endpoint) |
| Frontend | `resources/js/pages/admin/Categories.vue` |
| Status | **Broken** — category writes go to the regular `/categories` endpoints, not admin-specific ones |

---

## 14. Month Summary

**What it does:** Read-only financial overview for a specific past or current month. Shows close status, spending by category, family member split balances, and a projected dry-run of the user's closeout rules. Accessible from the Dashboard (and any deep link).

| Layer | Files |
|---|---|
| Backend | `GET /month-summary?year=&month=` → `MonthSummaryController::show` |
| Service | `app/Services/MonthCloseoutService` (read-only `isHardClosed`, `getMonthStatus`) |
| Models | `Transaction`, `TransactionSplit`, `FundRule`, `Debt`, `Fund` (all read-only) |
| Frontend | `resources/js/pages/MonthSummary.vue` (route: `/month-summary/:yearMonth`) |

**Response shape:** `{year, month, is_hard_closed, close_status, category_totals, member_balances, rule_preview}`

- `category_totals`: family transactions grouped by category (expenses then income, sorted by total descending), excluding debt payments
- `member_balances`: net amount owed between the auth user and each other family member from split expenses; only shown when non-zero balances exist
- `rule_preview`: `{basis: {gross_income, total_expenses, remaining_after_expenses}, rules: [...]}` — dry-run projection; no writes occur

---

## 13. Fund rules and closeout allocation

**What it does:** Users define `FundRule` rows (percentage/fixed, gross vs remaining, destination fund/debt/title) on the **Closeout Rules** page (`GET`/`POST`/`PUT`/`DELETE /closeout-rules`). Those rules are applied when a month is **hard-closed** (`MonthCloseoutService`), not when each income transaction is posted.

| Layer | Files |
|---|---|
| Trigger | `MonthCloseoutService` during hard-close (not `TransactionService::createTransaction`) |
| Per-income helper | `app/Services/FundService.php::processIncome` — **not invoked** from `TransactionService` today |
| Models | `FundRule`, `Fund`, `FundMovement`, `Debt`, `CloseoutTitleSaving` (as applicable) |
| Tests | `tests/Feature/FundAllocationTest.php` — still asserts legacy per-income allocation; **out of sync** with current wiring (see `docs/ai/09-known-decisions.md`) |

**Debt destination date behavior:** If a closeout rule allocates to a debt, the generated debt-payment transaction is dated:
- today's date when closing the current month
- month-end of the closed month when closing a non-current month
