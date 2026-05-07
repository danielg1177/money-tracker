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

**What it does:** Shows current-month income/expense totals and displays the same **viewer-scoped** transaction list as the Transactions page (see section 3). Also displays **This Month's Split Expenses** (per-counterpart net and a View Details sheet); there is no separate aggregate “split balance” card. For users in a family, it also shows a **Bank Account** card that reads/writes `/bank-balance` for enabling tracking and setting a baseline account balance.

| Layer | Files |
|---|---|
| Backend | `GET /transactions` → `TransactionController::index`; `GET /dashboard/monthly-totals` → `DashboardController::monthlyTotals`; `GET /bank-balance` + `PUT /bank-balance` → `BankBalanceController` |
| Frontend | `resources/js/pages/Dashboard.vue` |

**Monthly totals:** `DashboardController::monthlyTotals` calculates income and expense sums for the auth user for the current calendar month (excluding debt-payment transactions). Values are displayed in a two-column card above the stat cards. The **Transactions** stat card shows the count of loaded transactions whose `transaction_date` falls in the **current calendar month** (not lifetime total); the full unfiltered list is still used for family closeout month detection.

---

## 3. Transactions

**What it does:** Record income and expense transactions for a family. Support date filtering, category assignment, split between members, edit, and delete. The index list is **scoped to the signed-in user**: their own transactions plus any family transaction where they appear in `transaction_splits` (shared splits created by someone else). **All transactions are included**, including debt payment rows (`is_debt_payment=true`), except the mirrored **expense** leg for a **creditor** who is also on that expense’s splits (same payment as their `debt_id` income row). The month picker state is persisted in the route query as `month=YYYY-MM`, so refresh/back-forward navigation restores the selected month.

| Layer | Files |
|---|---|
| Backend | `TransactionController` (index, store, update, destroy) |
| Service | `app/Services/TransactionService.php` |
| Model | `app/Models/Transaction.php`, `app/Models/TransactionSplit.php` |
| Request | `app/Http/Requests/StoreTransactionRequest.php` |
| Frontend | `resources/js/pages/Transactions.vue` (calendar-month filter triggers **`GET /month-summary`** for **`member_balances`** only alongside the usual transaction fetches; **Split balances (this month)** card when applicable), `resources/js/components/TransactionForm.vue`, `resources/js/components/SplitEditor.vue` |

**Split sub-feature:** Only for **expense** transactions. When `is_split = true` and `type = expense`, the transaction form shows `SplitEditor`. On save, `TransactionService` creates `TransactionSplit` records and `Debt` records (one per non-owner split participant). Income transactions never carry splits; payloads are normalized server-side.

**Debt repayment sub-feature (expense):** Optional `debt_id` on `POST/PUT`-validated payloads supports create and update for payer-side debt-payment expenses. When set, creates/updates a categorized **expense** for the payer, mirrors an **`is_debt_payment` income** for an in-family **creditor** (with `mirror_transaction_id` linkage), and keeps `debts.balance` in sync by rolling back the old payment amount and applying the edited amount. Split is supported on these debt-payment expenses (creating/recreating `transaction_splits` and pending split debts for non-payer participants); advance fund remains disabled for debt-payment expenses. Creditor repayment income remains excluded from `MonthCloseoutService` gross income (same rule as existing `get debts`/`payDebt` flows). **`GET /month-summary`** exposes `debt_repayments.{paid,received}` for viewer-scoped repayment lines (creditor **received** lines stay out of income **category** totals). Payer-side amounts also flow into **`category_totals`** under the expense’s **category** when set, else **Uncategorized Debt Payments**: **`paid` includes any family member listed on payer-side splits**, and **`amount`** is each viewer's **split share** (`transaction_splits.amount`) for split repayments while **`received`** stays the creditor mirror **`transactions.amount`** (full cash-in to that leg).

**Debt association sub-feature (income):** Optional `income_debt_mode` on income payloads:
- `none`: regular income
- `existing`: links income to a debt the user owes and increments that debt's `amount` + `balance`
- `new`: creates a debt inline (external creditor name or family-member creditor) and links the income row
These rows remain regular income (`is_debt_payment=false`) and continue to count toward closeout gross-income calculations.

---

## 4. Categories

**What it does:** Manage income and expense categories per family; each category is **either** income **or** expense (not both). **Expense** categories can optionally define a default split template and default advance fund. On **Categories**, tap a row to open the edit sheet; **Add Category** creates a new one. Delete remains a separate control per row so it does not trigger edit.

| Layer | Files |
|---|---|
| Backend | `CategoryController` (index, store, update, destroy) |
| Model | `app/Models/Category.php` |
| Request | `app/Http/Requests/StoreCategoryRequest.php` |
| Frontend | `resources/js/pages/Categories.vue`, `resources/js/components/IconPicker.vue` |

**`split_default` / `advance_fund_id`:** Only honored when `is_expense` is true. Stored as JSON FK respectively; excluded when saving an income-only category. The transaction form applies these defaults only when the active transaction **type is expense**. If the category enables split (`is_split_default` with `split_default`), the form turns **split** on and fills **equal shares across the current family** (`familyUsers`); the stored `split_default` JSON documents the category for reference but does not pre-fill those percentages in the transaction form.

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

**Starting Balance:** Funds can be created with an optional `starting_balance`. If provided and > 0, a `FundMovement` of type `'initial_value'` is created to track the initial funding, and the fund balance is set to that value.

**Advance Fund Settlement:** Funds can be targeted by expense transactions via the `advance_fund_id` field. During month hard-close, `MonthCloseoutService::applyFundAdvances()` sums all advances and decrements the fund balance with an `'advance_settlement'` movement. This happens independently of normal fund rules.

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

**Monthly interest at closeout:** Debts can opt into interest with `interest_enabled` + `interest_rate` (APR) and optional `loan_received_date`. During hard-close, `MonthCloseoutService` applies interest to eligible debts across the family using a daily-rate model (`APR / 365`) over the closed month, factoring in in-month debt-payment expense transactions so mid-month payments reduce subsequent accrual. `interest_last_applied_at` is stamped to the closed month-end date regardless of when closeout is run. Interest increases `balance` only (not original `amount`) and writes a history entry into `interest_accruals`.

**Split debts & hard-close:** When a split transaction is created, temporary `is_pending_closeout=true` debts are created. These are hidden from the Debts page (GET /debts filters them out). On hard-close, `MonthCloseoutService::consolidatePendingSplitDebts` includes pending rows whose linked transaction is in the closed month **or** whose `transaction_id` is null (orphans left when a split transaction was deleted under the old `nullOnDelete` FK). Those rows are **netted per person-pair** (if A owes B $10 and B owes A $5, only one $5 debt remains from B to A). Netting results are consolidated into single running debts per pair—either updating an existing confirmed debt or creating a new one. All included pending split rows are then deleted. Deleting a split transaction now cascades to remove its linked split-debt rows (`debts.transaction_id` → `cascadeOnDelete`).

**Payment guard:** `DebtService::payDebt` rejects attempts to pay `is_pending_closeout=true` debts, directing users to wait for the month's hard-close.

|| Layer | Files |
||---|---|
|| Backend | `DebtController` (index, store, payDebt, `paymentHistory`), `DebtController::splitDebtSummary` |
|| Service | `app/Services/DebtService.php`, `app/Services/MonthCloseoutService.php::consolidatePendingSplitDebts`, `app/Services/MonthCloseoutService.php::applyMonthlyDebtInterest` |
|| Model | `app/Models/Debt.php` |
|| Request | `app/Http/Requests/PayDebtRequest.php` |
|| Frontend | `resources/js/pages/Debts.vue` |
|| Policy | `app/Policies/DebtPolicy.php` (not actively invoked) |

**Debt creation sources:**
1. Automatic (split transaction, `is_pending_closeout=true`): via `TransactionService`
2. Automatic (fund borrow): via `FundService`
3. Manual: `POST /debts`

**Debt payment (`DebtService::payDebt`):** Creates two transactions — an expense for the debtor, and an income for the creditor (if `creditor_id` is not null / not a fund debt). Rejects pending split debts. Accepts optional `transaction_date` so debt payments use the user-selected date instead of always defaulting to today. **`GET /debts/{debt}/payments`** lists one entry per pay action for those debts by excluding the mirror **income** row when a matching **expense** exists (paired on `debt_id`, date, amount, `paid_by_user_id`, and `created_at`). Payment rows now include `split_breakdown` when applicable so history can show each participant’s paid share for split repayments. A synthetic `'initial_value'` entry is appended at the end showing the debt's original amount and creation date.

**Initial value history:** The debt's origin is displayed in the payment history modal as an `'initial_value'` entry, showing the debt's original amount and the date it was created. This entry is appended to the `GET /debts/{debt}/payments` response.

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

**What it does:** Global admin can view all users, create, update, delete. Update supports optional password reset for the edited user (blank password keeps existing hash).

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

## 13. Month Summary

**What it does:** Financial overview for a specific past or current month. Shows close status, spending by category (month summary UI lists the top four expense and income categories by amount with an expand control and section totals), **split balances this month** (**`member_balances`** — shared bill splits only), monthly fund in/out activity, projected closeout allocations, and (for hard-closed months) title savings with completion toggles. Accessible from the Dashboard (and any deep link). The **Hard Close** action is gated in the UI by **`user.can_manage_family`** (same as the hard-close endpoint), not `is_admin` alone.

| Layer | Files |
|---|---|
| Backend | `GET /month-summary?year=&month=` → `MonthSummaryController::show` |
| Service | `app/Services/MonthCloseoutService` (read-only `isHardClosed`, `getMonthStatus`) |
| Models | `Transaction`, `TransactionSplit`, `FundRule`, `Debt`, `Fund` (all read-only) |
| Frontend | `resources/js/pages/MonthSummary.vue` (route: `/month-summary/:yearMonth`); **`resources/js/pages/Transactions.vue`** shows the same **`member_balances`** as **Split balances (this month)** when a calendar month (not **Custom Range**) is selected |
| Tests | **`tests/Feature/MonthSummaryViewerCategoryTotalsTest.php`** guards **`category_totals`** (**solo expenses exclude `is_closeout_initiated`; viewer income excludes `is_borrow`**) stays aligned with **`rule_preview.basis.total_expenses`** / **`gross_income`**, plus **`member_balances`** (split-bill net only; omits debt-payment and closeout split parents; nets to zero). **`tests/Feature/MonthCloseoutTransactionDateTest.php`**, **`PreviewHardCloseConsistencyTest.php`** add further month-summary / closeout coverage |

**Response shape:** `{year, month, is_hard_closed, close_status, category_totals, category_transactions, member_balances, rule_preview, fund_movements, debt_repayments, title_savings}`

- `category_totals`: **viewer-scoped** category totals for the authenticated user only (solo incomes **excluding `is_borrow`** so fund borrows align with **`rule_preview.basis.gross_income`**; solo expenses **excluding `is_closeout_initiated`** so hard-close ledger lines align with **`rule_preview.basis.total_expenses`**; viewer’s shares of split expenses; **tracked debt repayments** you pay merge into their **transaction category** when `category_id` is set, otherwise into a synthetic **Uncategorized Debt Payments** row with `category_id=-1`), sorted expenses then income by total descending
- `category_transactions`: per-category detail rows for month-summary category buckets, keyed as `{type}_{categoryId}` (uncategorized key uses `null`; synthetic uncategorized debt repayments use `expense_-1`). Each row includes `{id, transaction_date, description, amount, is_split, split_breakdown[]}` and follows the same viewer-scoped inclusion rules as `category_totals` (split rows use viewer share amounts)
- `member_balances`: net split-**bill** IOUs between the auth user and each other family member for **`is_split` expenses dated in that month (**excludes** **`is_debt_payment`** and **`is_closeout_initiated`** parents, matching viewer split-share **`category_totals`** rules); only members with a non-zero net appear
- `fund_movements`: monthly fund movement summary for funds visible to the auth user, grouped by fund with in/out/net totals and movement lines
- `rule_preview`: `{basis: {gross_income, total_expenses, gross_allocations_total, remaining_after_expenses}, expense_closeout_basis: {lines: string[]}, rules: [...]}` — dry-run projection; no writes occur. **Gross-base rule iteration stops once the gross pool is exhausted** (same **`$grossRemaining <= 0`** stop as **`MonthCloseoutService::processUserCloseoutRules`**), so oversized **percentage-of-gross-income** projections do not run after earlier rules consumed the pool; **subsequent gross rules still appear with `projected_amount` 0** for ordering parity with saved rules. **`destination_type=debt`**: **`projected_amount`** is the **nominal** rule allocation (before cap); **`net_after_advances`** is the **capped** payoff using a running preview debt balance across rules (**matches `MonthCloseoutService::allocateToDebt`**); **`gross_allocations_total`** uses the capped amount. **`remaining_after_expenses`** is **signed** (can be negative when expenses and gross-base allocations exceed gross income). **`gross_allocations_total`** is the amount **subtracted from gross income when computing remaining** for active rules whose **`allocation_base`** is not `remaining`: normally the sum of those rule outputs, but for **`destination_type=fund`** each rule’s contribution is **max(0, projected − outstanding advances to that fund before the rule in order)** so advance-tagged expenses already in **`total_expenses`** are not double-counted against remaining (matches hard-close remaining-pool logic). **`expense_closeout_basis.lines`** documents which transactions count toward **`basis.total_expenses`** (same rules as **`MonthCloseoutService::expenseTotalTowardRemainingBasis`**). Fund-destination rows include **`net_after_advances`** (subtracts month **`advance_fund_id`** totals against that destination fund, applied in rule order across multiple rules targeting the same fund; **negative net allowed**) plus **`fund_advance_outstanding_before`** for captioning **projected − advances**
- `debt_repayments`: `{paid, received}` arrays for repayment rows in the selected month (shown in a dedicated section in `MonthSummary.vue`); **`paid[].amount`** is the viewer's share for split payer-side repayments (**`received[].amount`** remains the creditor income line total)
- `title_savings`: closeout title allocations for the authenticated user in hard-closed months, each with `{id, title, amount, is_completed, completed_at}`; UI can mark complete/incomplete via `/title-savings/{id}/complete`

---

## 14. Fund rules and closeout allocation

**What it does:** Users define `FundRule` rows (percentage/fixed, gross vs remaining, destination fund/debt/title) on the **Closeout Rules** page (`GET`/`POST`/`PUT`/`DELETE /closeout-rules`). **`POST`/`PUT`** reject a duplicate **`destination_title`** when **`destination_type=title`** and **`is_active`**, scoped to that user — one active title bucket name per closeout-rule set (UI + **`MonthCloseoutService::allocateToTitle`** both avoid ambiguous **`CloseoutTitleSaving.rule_id`**). Rules can also set optional `closeout_expense_category_id` for generated closeout movement rows. Rules are applied when a month is **hard-closed** (`MonthCloseoutService`), not when each income transaction is posted.

| Layer | Files |
|---|---|
| Trigger | `MonthCloseoutService` during hard-close (not `TransactionService::createTransaction`) |
| Per-income helper | `app/Services/FundService.php::processIncome` — **not invoked** from `TransactionService` today |
| Models | `FundRule`, `Fund`, `FundMovement`, `Debt`, `CloseoutTitleSaving` (as applicable) |
| Tests | `tests/Feature/FundAllocationTest.php` — asserts **no** immediate allocation on income create (no `fund_movements`), plus fund **borrow** success and insufficient-balance failure (see `docs/ai/09-known-decisions.md`, heading *Fund rules are not applied on income save*). **`tests/Feature/PreviewHardCloseConsistencyTest.php`** — **`GET /month-summary` `rule_preview`** debt-rule basis (`gross_allocations_total`, **`remaining_after_expenses`**) aligns with capped paydown vs **`MonthCloseoutService`** hard-close. **`tests/Feature/MonthCloseoutTransactionDateTest.php`** covers closeout dates and broader preview parity |

**Debt destination date behavior:** If a closeout rule allocates to a debt, the generated debt-payment transaction is dated:
- today's date when closing the current month
- month-end of the closed month when closing a non-current month

**Closeout movement transactions:** Hard-close now records user-visible closeout movement expenses in Transactions:
- `destination_type=fund`: creates closeout-tagged expense + `FundMovement(closeout_allocation)`
- `destination_type=debt`: creates closeout-tagged debt-payment expense (with optional rule category)
- `destination_type=title`: no transaction at hard-close; completion creates a closeout-tagged expense when marked done
- Closeout-tagged expense rows are excluded from closeout expense-basis math so they do not affect the same closeout run’s remaining-pool calculations

**Fund movement month scoping:** In `GET /month-summary`, closeout movement types (`closeout_allocation`, `advance_settlement`) are scoped by their tagged closeout month in movement descriptions, while non-closeout movement types still use transaction date or movement month as appropriate. This keeps late-run closeouts (e.g., April closeout executed in May) in the April summary.

**Remaining percentage basis:** During hard-close and month-summary preview, each `allocation_base='remaining'` + `allocation_type='percentage'` rule uses the same shared remaining basis (post-expense and post-gross-allocation) rather than a cascading percentage-on-percentage reduction.

---

## 15. Bank Account Balance Tracking

**What it does:** An opt-in per-user feature that tracks the user's real bank account balance in real time. The user sets an anchor balance from the Dashboard (their current bank statement amount). The app then computes a running expected balance by applying all subsequent transactions the user owns as debits or credits. Split expense payers are debited the FULL transaction amount (they fronted the whole bill — the split participants' portions come back when debts are paid). After a month is hard-closed, any title savings that were generated by closeout rules appear in the MonthSummary page with a "Mark Done" toggle; completing a title saving signals the user transferred/spent that reserved money and reduces the computed bank balance.

| Layer | Files |
|---|---|
| Backend controller | `app/Http/Controllers/BankBalanceController.php` |
| Form request | `app/Http/Requests/UpdateBankBalanceRequest.php` |
| Models updated | `app/Models/User.php` (3 new columns), `app/Models/CloseoutTitleSaving.php` (2 new columns) |
| Controller updated | `app/Http/Controllers/MonthSummaryController.php` (adds `title_savings` to response) |
| Frontend — Dashboard | `resources/js/pages/Dashboard.vue` (bank balance card with inline edit) |
| Frontend — MonthSummary | `resources/js/pages/MonthSummary.vue` (title savings completion section) |

**Computed balance formula:**

```
computed_balance = bank_balance (anchor)
+ SUM(income transactions WHERE user_id = user.id AND transaction_date >= bank_balance_set_at) − SUM(expense transactions WHERE user_id = user.id AND transaction_date >= bank_balance_set_at) − SUM(completed CloseoutTitleSavings WHERE user_id = user.id AND completed_at::date >= bank_balance_set_at)
```

**Key design rule — split expense payers:** Because split expense transactions have `user_id = payer`, the full `amount` field is included in the expense sum above. Non-paying participants' share creates a `Debt`; their bank balance is not affected until they make a debt payment expense (which IS on their `user_id`).

**API surface:**

| Method | Route | Purpose |
|---|---|---|
| GET | `/bank-balance` | Returns enabled state, anchor, set date, computed balance, and delta breakdown |
| PUT | `/bank-balance` | Sets/updates anchor balance (resets `bank_balance_set_at` = today) and/or toggles feature |
| POST | `/title-savings/{id}/complete` | Marks a CloseoutTitleSaving as completed |
| DELETE | `/title-savings/{id}/complete` | Reverses completion of a CloseoutTitleSaving |

**`GET /bank-balance` response shape:**
```json
{
  "enabled": true,
  "bank_balance": 5000.00,
  "bank_balance_set_at": "2026-05-06",
  "computed_balance": 4650.00,
  "delta": {
    "income": 200.00,
    "expense": 550.00,
    "title_savings_completed": 0.00
  }
}
```

When feature is disabled or no anchor set, `computed_balance` and `delta` are null.
