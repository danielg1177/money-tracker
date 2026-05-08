# 07 — Key Workflows

Detailed step-by-step flows for the most complex operations in the app.

---

## Workflow 1: Creating a Split Expense Transaction

1. User opens `TransactionForm` (via FAB in `AppNav`) and sets type=`expense`, amount, date, category
   - If the chosen date falls in a month that is hard-closed or soft-closed for the user, the form keeps that date selected and shows a top warning banner. Save is blocked only when submit is attempted.
2. User enables "Split between family members" → `SplitEditor` appears (income transactions do **not** show this toggle)
3. User assigns percentages to family members (must sum to 100)
4. Vue submits `POST /transactions` with body:
   ```json
   {
     "type": "expense",
     "amount": 100,
     "transaction_date": "2026-05-03",
     "category_id": 1,
     "is_split": true,
     "split_data": [
       {"user_id": 1, "share_percentage": 60},
       {"user_id": 2, "share_percentage": 40}
     ]
   }
   ```
5. `StoreTransactionRequest` validates the payload (`type=income` would strip `split_data`, `is_split`, and `advance_fund_id` before validation)
6. `TransactionController::store` calls `ClosedMonthGuard`; the save is rejected if the family month is hard-closed or if the owner / any split participant has soft-closed that month
7. `TransactionController::store` calls `TransactionService::createTransaction`
8. `SplitCalculator::validate` checks percentages sum to 100 (epsilon 0.01)
9. `DB::transaction` begins:
   a. Creates `Transaction` record with `is_split=true`, stores `split_data` snapshot
   b. `SplitCalculator::allocate` computes per-user dollar amounts (last user absorbs rounding)
   c. For each split user: creates `TransactionSplit` record
   d. For each split user who is NOT the transaction owner: creates `Debt` record (`debtor_id` split user, `creditor_id` owner, etc.)
   e. Fund rules still apply on **month hard-close**, not at transaction save time
10. Returns transaction with `splits.user` eager-loaded (HTTP 201)

---

## Workflow 2: Paying a Debt Between Family Members

1. User is on `Debts.vue`, sees an entry in "You Owe"
2. User clicks "Pay" and enters an amount
3. Vue submits `POST /debts/pay` with:
   ```json
   {
     "debt_id": 5,
     "amount": 50.00,
     "description": "Partial payment",
     "transaction_date": "2026-05-03",
     "split_with_user_id": null,
     "split_percentage": null
   }
   ```
   Optional split fields allow the payer to split the payment expense with another family member (creates a pending `Debt` for the split portion). `transaction_date` is optional; if omitted, backend uses today's date.
4. `PayDebtRequest` validates
5. `DebtController::payDebt` loads the debt and calls `ClosedMonthGuard`; the save is rejected if the family month is hard-closed or if the payer, optional split participant, or creditor has soft-closed that month
6. `DebtController::payDebt` calls `DebtService::payDebt`
7. `DebtService::payDebt` validates:
   - For family debts (`is_family_debt=true`): payer must be a family member
   - For personal debts: payer must be the debtor
   - Amount > 0
   - Amount ≤ debt balance
8. `DB::transaction` begins:
   a. Creates an `expense` transaction for the debtor (tagged `is_debt_payment=true`)
      - Sets `debt_id` to the debt being paid
      - Sets `paid_by_user_id` to the payer
      - Sets `is_closeout_initiated=false` (manual payment)
      - If split: sets `is_split=true`, `split_data`, and creates `TransactionSplit` rows plus a pending `Debt` for the split participant
   b. If `creditor_id` is not null: creates an `income` transaction for the creditor (also `is_debt_payment=true`)
      - Sets `debt_id` to the same debt
      - Sets `paid_by_user_id` to the payer
      - Sets `is_closeout_initiated=false` (manual payment)
   c. Decrements `debt.balance` by payment amount
9. Returns HTTP 200 `{ "message": "Debt payment recorded" }`

**Note:** Debt records with `balance = 0` remain in the database — there is no auto-deletion or "paid" status flag.

Newly added fields track:
- **`paid_by_user_id`:** Which user initiated the payment (important for multi-user families)
- **`is_closeout_initiated`:** Whether the payment came from a manual entry (`false`) or a month closeout rule (`true`)

The payment history modal in `Debts.vue` displays:
- Date of payment
- Amount paid
- Who made the payment (payer's name)
- Split contribution breakdown (when payment was split), showing each participant's amount and percentage
- Whether it was initiated from a closeout (shown as a "Closeout" badge if applicable)

---

## Workflow 3: Borrowing from a Fund

1. User is on `Funds.vue`, chooses a fund and enters a borrow amount
2. Vue submits `POST /funds/{fund}/borrow` with:
   ```json
   { "amount": 200.00, "description": "Emergency" }
   ```
3. `FundController::borrow` authorizes via `FundPolicy::update` (must own the fund)
4. Calls `ClosedMonthGuard`; the borrow is rejected if the current month is hard-closed or soft-closed for the user
5. Calls `FundService::borrowFromFund`
6. Validates fund balance ≥ amount
7. `DB::transaction` begins:
   a. Creates an `income` transaction tagged `is_borrow=true`
   b. Decrements `fund.balance` by amount
   c. Creates `FundMovement` (type=`borrow`)
   d. Creates `Debt` with `debtor_id = user`, `fund_id = fund`, `creditor_id = null`, `balance = amount`
8. Returns the created transaction (HTTP 201)

---

## Workflow 4: Repaying a Fund Debt

1. User is on `Funds.vue`, sees a fund debt, enters repayment amount
2. Vue submits `POST /debts/{debt}/repay-fund` with:
   ```json
   { "amount": 100.00 }
   ```
3. `FundController::repayFund` checks `auth()->user()->id === $debt->debtor_id`
4. Calls `ClosedMonthGuard`; the repayment is rejected if the current month is hard-closed or soft-closed for the user
5. Calls `FundService::repayFund`
6. Validates:
   - Debt has a `fund_id` (not a person-to-person debt)
   - User is the debtor
   - Amount > 0 and ≤ debt balance
7. `DB::transaction` begins:
   a. Creates an `expense` transaction tagged `is_debt_payment=true`
   b. Increments `fund.balance` by amount
   c. Creates `FundMovement` (type=`repayment`)
   d. Decrements `debt.balance` by amount
8. Returns HTTP 200

---

## Workflow 5: Income Allocation via Fund Rules

Triggered during `MonthCloseoutService::hardClose()`, not on individual income transactions.

1. Loads active `FundRule`s for user, ordered by `order` ASC
2. Separates rules into two groups:
   - **Gross-based rules**: `allocation_base` = `gross_income` or `net_income` (processed first)
   - **Remaining-based rules**: `allocation_base` = `remaining` (processed after expenses)
3. **Gross-based rules loop:**
   a. Calculates allocation amount from gross income or net income:
      - `percentage`: `round(grossIncome * rule.amount / 100, 2)`
      - `fixed`: `min(rule.amount, $grossRemaining)`
   b. Applies rule to destination (fund, debt, or title) — returns **actual** allocated amount (may be less if debt was underfunded)
   c. Subtracts **actual** allocated amount from `$grossRemaining`
   d. If `$grossRemaining ≤ 0`: stops
4. **Calculate remaining pool:** `remainingPool = grossIncome - grossAllocations - totalExpenses`
5. **Remaining-based rules loop:**
   a. Calculates allocation amount from remaining pool:
      - `percentage`: `round(remainingPool * rule.amount / 100, 2)` using the same shared `remainingPool` basis for every percentage rule in this phase
      - `fixed`: `min(rule.amount, $remainingAvailablePool)` (fixed allocations consume the available pool)
   b. Applies rule to destination — returns **actual** allocated amount
   c. Subtracts **actual** allocated amount from `$remainingAvailablePool`
   d. If `$remainingAvailablePool ≤ 0`: stops

**Important:** If a debt rule allocates $500 but the debt balance is only $200, only $200 is allocated, and the remaining $300 stays available for subsequent rules (as of 2026-05-04 fix).

**Debt allocation details:** When a rule's destination is a debt (`destination_type = 'debt'`):
- An `expense` transaction is created for the allocating user with `is_debt_payment=true` and `is_closeout_initiated=true`
- The closeout payment transaction date is context-aware:
  - If closing the current calendar month, the transaction date is "today"
  - If closing a past month, the transaction date is the last day of that closed month
- The `paid_by_user_id` field is set to the user executing the rule, allowing multi-user families to track who contributed to debt paydown
- The debt's balance is decremented by the payment amount
- This allows fund rules to automatically pay down debts during month closeout, and the payment history properly attributes the payment to the user whose rule triggered it

**Fund allocation details:** When a rule's destination is a fund (`destination_type = 'fund'`):
- Fund balance is increased and a `FundMovement` of type `closeout_allocation` is recorded
- A matching closeout-tagged `expense` transaction is created for Transactions-page visibility (category defaults to `rule.closeout_expense_category_id` when present)

**Title completion details:** `destination_type='title'` creates `CloseoutTitleSaving` records at hard-close; when user later marks one complete (`POST /title-savings/{id}/complete`), backend creates a closeout-tagged expense transaction using the rule's default closeout category if configured. Undo completion deletes that generated transaction.

**After rule processing:** `hardClose` runs `consolidatePendingSplitDebts`, which nets pending split debts for the closed month—including pending rows with a null `transaction_id` so they are not skipped—and writes confirmed debts before deleting the pending rows.

---

## Workflow 6: User Login

1. Vue `Login.vue` submits `POST /login` with `{email, password}` (Fortify route)
2. Fortify validates, creates session, returns redirect (or 422 on failure)
3. `useAuth.login()` then calls `fetchUser()` → `GET /user`
4. Server returns `auth()->user()` with appended attributes (`is_admin`, etc.)
5. `normalizeAuthUser` converts to `{...user, isAdmin: Boolean(...)}`
6. Saved to `localStorage` as `user`
7. Vue Router navigates to `/dashboard`

---

## Workflow 6b: Session Timeout Recovery

1. User's server session expires (idle timeout / stale CSRF token)
2. Next authenticated Axios request returns `401` or `419`
3. Global Axios interceptor in `resources/js/app.js` runs
4. Interceptor removes `user` from `localStorage`
5. App performs hard redirect to `/login`
6. User sees login screen immediately instead of remaining in a broken authenticated UI state

---

## Workflow 7: Creating a Category with Default Split / Advance (expense-only)

1. Family member opens `Categories.vue`
2. Fills in name, icon, and selects **Income** or **Expense** (mutually exclusive)
3. If type is **Expense**: optionally enables "Use as split default" and/or "Default Advance Fund"
4. Submits `POST /categories`
5. `StoreCategoryRequest` rejects if both `is_income` and `is_expense` are true or both false; clears `split_default`, `is_split_default`, and `advance_fund_id` when `is_expense` is false; otherwise validates and saves
6. When `TransactionForm` uses an expense category with defaults, the Vue watcher pre-populates split and/or advance fund **only when transaction type is expense**

---

## Workflow 8: Soft Close with Auto-Hard-Close (Single-Member Families)

1. Single-member family user is on `Transactions.vue`, clicks "Close Out" button for a month
2. Vue submits `POST /closeout/soft-close` with `{year, month}`
3. `MonthCloseoutController::softClose` validates request
4. Calls `MonthCloseoutService::softClose($user, year, month)`
5. Service validates:
   - User does not already have a soft close for this month
   - No hard close exists for this month
6. Service creates `MonthSoftClose` record
7. Service checks family member count: if exactly 1, immediately calls `hardClose()`
   - `hardClose()` validates all members soft-closed (trivially true for 1 member)
   - Processes the single user's closeout rules (fund allocations, title savings, debt paydowns)
   - Consolidates any pending split debts (none in single-member family)
   - Applies debt interest for eligible family debts through the closed month-end date (not `now()`), using daily accrual and reducing interest after in-month payments
   - Creates `MonthHardClose` record
8. Returns JSON with:
   - `message`: "Month closed successfully" (if auto-hard-closed) or "Month soft-closed successfully"
   - `data`: the `MonthSoftClose` record
   - `hard_close`: the `MonthHardClose` record (if auto-hard-closed)
   - `auto_hard_closed`: true (if auto-hard-closed)
9. Vue updates UI to show month as fully closed (amber lock icon)

---

## Workflow 8b: Undo Hard Close (Service-Level Reversal)

1. Backend calls `MonthCloseoutService::undoHardClose($family, $year, $month)`
2. Service starts one `DB::transaction` and verifies a `MonthHardClose` exists; otherwise throws `InvalidArgumentException("No hard close found for this month.")`
3. Reverses closeout debt-payment transactions first (for month + family users): for each `is_closeout_initiated=true` + `is_debt_payment=true` row, increments linked debt `balance` by transaction amount
4. Reverses fund movements for that month tag (`YYYY-MM`):
   - `closeout_allocation`: decrement fund balance by movement amount
   - `advance_settlement`: increment fund balance by movement amount
   - then deletes both movement sets
5. Deletes closeout-generated month transactions (`is_closeout_initiated=true`) after debt balances are restored
6. Deletes `CloseoutTitleSaving` rows for that family/month and deletes each linked `completion_transaction_id` row when present
7. Reverses split-debt consolidation:
   - confirmed debts with month/year entries in `contributions` remove those entries and subtract that amount from `amount` + `balance`
   - debts whose contributions become empty are deleted (created entirely by the reverted closeout)
8. Recreates pending split debts from non-closeout split transactions in that month (`is_split=true`, `is_closeout_initiated=false`) when `(transaction_id, debtor_id)` debt does not already exist
9. Reverses monthly debt interest entries by removing matching month/year records from `interest_accruals`, subtracting accrued interest from `balance`, and recomputing `interest_last_applied_at` from the latest remaining accrual (or null)
10. Deletes month `MonthSoftClose` and `MonthHardClose` rows to return the month to pre-close state

---

## Workflow 9: Income From Debt (New or Existing)

1. User opens `TransactionForm`, sets `type=income`, amount/date/category
2. In "Is this income from taking debt?" user chooses:
   - `No` (plain income), or
   - `Existing` (attach to debt already owed), or
   - `New Debt` (create debt inline)
3. Vue submits `POST /transactions` with normal income payload plus income debt fields when selected:
   ```json
   {
     "type": "income",
     "amount": 500,
     "transaction_date": "2026-05-06",
     "category_id": 1,
     "income_debt_mode": "existing",
     "income_existing_debt_id": 12
   }
   ```
   or:
   ```json
   {
     "type": "income",
     "amount": 500,
     "transaction_date": "2026-05-06",
     "category_id": 1,
     "income_debt_mode": "new",
     "income_new_is_interfamily": false,
    "income_new_creditor_name": "Bank of Example",
    "income_new_interest_enabled": true,
    "income_new_interest_rate": 12.5
   }
   ```
4. `StoreTransactionRequest` still strips expense-only fields (`is_split`, `split_data`, `advance_fund_id`, `debt_id`) for income, then validates income debt mode
5. `TransactionService::createTransaction` runs in `DB::transaction`:
   - `existing`: locks debt and increments both `amount` and `balance`
   - `new`: creates debt with `amount=income amount`, `balance=income amount`, and `loan_received_date=transaction_date`
   - stores resulting debt id on `transactions.debt_id`
6. Transaction remains a regular `income` row (`is_debt_payment=false`)
7. At month hard-close, this row is still treated as normal gross income for closeout rules

---

## Workflow 10: Undo Hard Close

Only available to `head_of_household` (or `is_admin=true`) users.

1. Head of household navigates to `MonthSummary.vue` for a hard-closed month
2. An `Undo Hard Close` button (red) appears in the page header (only visible when `is_hard_closed=true` and `can_manage_family=true`)
3. User clicks it; a `window.confirm()` dialog warns about the destructive nature
4. On confirmation, Vue posts `POST /closeout/undo-hard-close` with `{year, month}`
5. `MonthCloseoutController::undoHardClose` validates, checks `can_manage_family`, and calls `MonthCloseoutService::undoHardClose`
6. Service runs entirely in `DB::transaction`:
   a. Guards: verifies hard close exists for this family/year/month
   b. Reverses closeout debt payment transactions: restores `debt.balance` for each
   c. Reverses fund balance changes from `FundMovement` rows (`type=closeout_allocation`, `advance_settlement`); deletes those `FundMovement` rows
   d. Deletes all transactions with `is_closeout_initiated=true` for family members dated in the closed month
   e. Deletes `CloseoutTitleSaving` records for this family/year/month; also deletes any `completion_transaction_id` transactions linked to them
   f. Reverses confirmed split debts that have a `contributions` entry for this month — newly-created debts (only this month's contribution) are deleted; augmented existing debts have the contribution removed and `amount`/`balance` decremented
   g. Recreates `is_pending_closeout=true` debts from all split transactions (`is_split=true`, `is_closeout_initiated=false`) in the family/month that no longer have pending debt records
   h. Reverses interest: finds debts with `interest_accruals` entries for this year/month, subtracts accrual amounts from `debt.balance`, removes the entry from `interest_accruals`, restores `interest_last_applied_at`
   i. Deletes all `MonthSoftClose` records for this family/year/month
   j. Deletes the `MonthHardClose` record
7. Vue reloads the month summary — the month appears open again

Known limitations:

- If a confirmed split debt was partially paid down by a user after closeout, undoing the closeout reduces the debt balance but cannot restore it below zero. Balance is clamped at `max(0, balance - contribution_amount)`.
- The system does not guard against undoing a month when a subsequent month is already hard-closed; this can introduce inconsistencies in multi-month interest calculations or fund balances.
