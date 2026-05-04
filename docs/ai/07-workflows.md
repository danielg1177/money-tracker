# 07 — Key Workflows

Detailed step-by-step flows for the most complex operations in the app.

---

## Workflow 1: Creating a Split Income Transaction

1. User opens `TransactionForm` (via FAB in `AppNav`) and fills in type=`income`, amount, date, category
2. User enables "split" toggle → `SplitEditor` appears
3. User assigns percentages to family members (must sum to 100)
4. Vue submits `POST /transactions` with body:
   ```json
   {
     "type": "income",
     "amount": 1000,
     "transaction_date": "2026-05-03",
     "category_id": 1,
     "is_split": true,
     "split_data": [
       {"user_id": 1, "share_percentage": 60},
       {"user_id": 2, "share_percentage": 40}
     ]
   }
   ```
5. `StoreTransactionRequest` validates the payload
6. `TransactionController::store` calls `TransactionService::createTransaction`
7. `SplitCalculator::validate` checks percentages sum to 100 (epsilon 0.01)
8. `DB::transaction` begins:
   a. Creates `Transaction` record with `is_split=true`, stores `split_data` snapshot
   b. `SplitCalculator::allocate` computes per-user dollar amounts (last user absorbs rounding)
   c. For each split user: creates `TransactionSplit` record
   d. For each split user who is NOT the transaction owner: creates `Debt` record:
      - `debtor_id` = split user
      - `creditor_id` = transaction owner
      - `amount` = `balance` = split dollar amount
   e. No automatic fund allocation runs here — `FundRule` / fund balances are updated on **month hard-close**, not on each income save
9. Returns transaction with `splits.user` and `user`, `category` eager-loaded (HTTP 201)

---

## Workflow 2: Paying a Debt Between Family Members

1. User is on `Debts.vue`, sees an entry in "You Owe"
2. User clicks "Pay" and enters an amount
3. Vue submits `POST /debts/pay` with:
   ```json
   { "debt_id": 5, "amount": 50.00, "description": "Partial payment" }
   ```
4. `PayDebtRequest` validates
5. `DebtController::payDebt` loads the debt, calls `DebtService::payDebt`
6. `DebtService::payDebt` validates:
   - Payer is the debtor
   - Amount > 0
   - Amount ≤ debt balance
7. `DB::transaction` begins:
   a. Creates an `expense` transaction for the debtor (tagged `is_debt_payment=true`)
   b. If `creditor_id` is not null: creates an `income` transaction for the creditor (also `is_debt_payment=true`)
   c. Decrements `debt.balance` by payment amount
8. Returns HTTP 200 `{ "message": "Debt payment recorded" }`

**Note:** Debt records with `balance = 0` remain in the database — there is no auto-deletion or "paid" status flag.

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

Triggered automatically by `TransactionService::createTransaction` for non-borrow income:

1. Loads active `FundRule`s for user, ordered by `order` ASC
2. Initializes `$gross = $net = $remaining = income amount`
3. For each rule (in order):
   a. Selects the base amount: `gross_income` → `$gross`, `net_income` → `$net`, `remaining` → `$remaining`
   b. Calculates allocation:
      - `percentage`: `round(base * rule.amount / 100, 2)`
      - `fixed`: `min(rule.amount, remaining)`
   c. If allocation > 0: increments `fund.balance`, creates `FundMovement` (type=`allocation`)
   d. Subtracts allocation from `$remaining`
   e. If `$remaining ≤ 0`: stops
4. Note: `$net` is never independently modified in the current implementation — it always equals `$gross`. The `net_income` base effectively behaves like `gross_income`.

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

## Workflow 7: Creating a Category with Default Split

1. Admin/family member opens `Categories.vue`
2. Fills in name, icon, type flags, enables "split default", assigns member percentages
3. Submits `POST /categories`
4. `StoreCategoryRequest` validates; `CategoryController::store` saves with `split_default` JSON
5. When `TransactionForm` later loads categories: the frontend can pre-populate the split editor from `category.split_default` (Needs verification: whether the frontend currently reads `split_default` to auto-populate)

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
