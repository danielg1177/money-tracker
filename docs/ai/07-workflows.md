# 07 — Key Workflows

Detailed step-by-step flows for the most complex operations in the app.

---

## Workflow 1: Creating a Split Expense Transaction

1. User opens `TransactionForm` (via FAB in `AppNav`) and sets type=`expense`, amount, date, category
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
6. `TransactionController::store` calls `TransactionService::createTransaction`
7. `SplitCalculator::validate` checks percentages sum to 100 (epsilon 0.01)
8. `DB::transaction` begins:
   a. Creates `Transaction` record with `is_split=true`, stores `split_data` snapshot
   b. `SplitCalculator::allocate` computes per-user dollar amounts (last user absorbs rounding)
   c. For each split user: creates `TransactionSplit` record
   d. For each split user who is NOT the transaction owner: creates `Debt` record (`debtor_id` split user, `creditor_id` owner, etc.)
   e. Fund rules still apply on **month hard-close**, not at transaction save time
9. Returns transaction with `splits.user` eager-loaded (HTTP 201)

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
     "split_with_user_id": null,
     "split_percentage": null
   }
   ```
   Optional split fields allow the payer to split the payment expense with another family member (creates a pending `Debt` for the split portion).
4. `PayDebtRequest` validates
5. `DebtController::payDebt` loads the debt, calls `DebtService::payDebt`
6. `DebtService::payDebt` validates:
   - For family debts (`is_family_debt=true`): payer must be a family member
   - For personal debts: payer must be the debtor
   - Amount > 0
   - Amount ≤ debt balance
7. `DB::transaction` begins:
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
8. Returns HTTP 200 `{ "message": "Debt payment recorded" }`

**Note:** Debt records with `balance = 0` remain in the database — there is no auto-deletion or "paid" status flag.

Newly added fields track:
- **`paid_by_user_id`:** Which user initiated the payment (important for multi-user families)
- **`is_closeout_initiated`:** Whether the payment came from a manual entry (`false`) or a month closeout rule (`true`)

The payment history modal in `Debts.vue` displays:
- Date of payment
- Amount paid
- Who made the payment (payer's name)
- Whether it was initiated from a closeout (shown as a "Closeout" badge if applicable)

---

## Workflow 3: Borrowing from a Fund

1. User is on `Funds.vue`, chooses a fund and enters a borrow amount
2. Vue submits `POST /funds/{fund}/borrow` with:
   ```json
   { "amount": 200.00, "description": "Emergency" }
   ```
3. `FundController::borrow` authorizes via `FundPolicy::update` (must own the fund)
4. Calls `FundService::borrowFromFund`
5. Validates fund balance ≥ amount
6. `DB::transaction` begins:
   a. Creates an `income` transaction tagged `is_borrow=true`
   b. Decrements `fund.balance` by amount
   c. Creates `FundMovement` (type=`borrow`)
   d. Creates `Debt` with `debtor_id = user`, `fund_id = fund`, `creditor_id = null`, `balance = amount`
7. Returns the created transaction (HTTP 201)

---

## Workflow 4: Repaying a Fund Debt

1. User is on `Funds.vue`, sees a fund debt, enters repayment amount
2. Vue submits `POST /debts/{debt}/repay-fund` with:
   ```json
   { "amount": 100.00 }
   ```
3. `FundController::repayFund` checks `auth()->user()->id === $debt->debtor_id`
4. Calls `FundService::repayFund`
5. Validates:
   - Debt has a `fund_id` (not a person-to-person debt)
   - User is the debtor
   - Amount > 0 and ≤ debt balance
6. `DB::transaction` begins:
   a. Creates an `expense` transaction tagged `is_debt_payment=true`
   b. Increments `fund.balance` by amount
   c. Creates `FundMovement` (type=`repayment`)
   d. Decrements `debt.balance` by amount
7. Returns HTTP 200

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
      - `percentage`: `round(remainingPool * rule.amount / 100, 2)`
      - `fixed`: `min(rule.amount, $remainingPool)`
   b. Applies rule to destination — returns **actual** allocated amount
   c. Subtracts **actual** allocated amount from `$remainingPool`
   d. If `$remainingPool ≤ 0`: stops

**Important:** If a debt rule allocates $500 but the debt balance is only $200, only $200 is allocated, and the remaining $300 stays available for subsequent rules (as of 2026-05-04 fix).

**Debt allocation details:** When a rule's destination is a debt (`destination_type = 'debt'`):
- An `expense` transaction is created for the allocating user with `is_debt_payment=true` and `is_closeout_initiated=true`
- The closeout payment transaction date is context-aware:
  - If closing the current calendar month, the transaction date is "today"
  - If closing a past month, the transaction date is the last day of that closed month
- The `paid_by_user_id` field is set to the user executing the rule, allowing multi-user families to track who contributed to debt paydown
- The debt's balance is decremented by the payment amount
- This allows fund rules to automatically pay down debts during month closeout, and the payment history properly attributes the payment to the user whose rule triggered it

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
   - Creates `MonthHardClose` record
8. Returns JSON with:
   - `message`: "Month closed successfully" (if auto-hard-closed) or "Month soft-closed successfully"
   - `data`: the `MonthSoftClose` record
   - `hard_close`: the `MonthHardClose` record (if auto-hard-closed)
   - `auto_hard_closed`: true (if auto-hard-closed)
9. Vue updates UI to show month as fully closed (amber lock icon)

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
     "income_new_creditor_name": "Bank of Example"
   }
   ```
4. `StoreTransactionRequest` still strips expense-only fields (`is_split`, `split_data`, `advance_fund_id`, `debt_id`) for income, then validates income debt mode
5. `TransactionService::createTransaction` runs in `DB::transaction`:
   - `existing`: locks debt and increments both `amount` and `balance`
   - `new`: creates debt with `amount=income amount`, `balance=income amount`
   - stores resulting debt id on `transactions.debt_id`
6. Transaction remains a regular `income` row (`is_debt_payment=false`)
7. At month hard-close, this row is still treated as normal gross income for closeout rules
