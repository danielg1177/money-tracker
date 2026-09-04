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
6. `TransactionController::store` calls `ClosedMonthGuard`; the save is rejected if the family month is hard-closed or if the **owner** has soft-closed that month (split co-participants’ soft closes do not block)
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
5. `DebtController::payDebt` loads the debt and calls `ClosedMonthGuard`; the save is rejected if the family month is hard-closed or if the **payer** has soft-closed that month (creditor / split co-participant soft closes do not block)
6. `DebtController::payDebt` calls `DebtService::payDebt`
7. `DebtService::payDebt` validates:
   - For family debts (`is_family_debt=true`): payer must be a family member
   - For personal debts: payer must be the debtor
   - Amount > 0
   - Amount ≤ debt balance **only for external debts** (`creditor_id` null); in-family overpayment is allowed
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
   c. Updates balance: decrements by payment amount, **or** for in-family overpayment zeros the debt and applies the excess via `DebtService::applyInterFamilyPairNet` (swapped direction; nets/merges with existing pair debts)
9. Returns HTTP 200 `{ "message": "Debt payment recorded" }`

**Note:** Debt records with `balance = 0` remain in the database — there is no auto-deletion or "paid" status flag.

**From the create-expense form (or Plaid import review):** instead of picking a tracked debt, the payer can mark **Sent to a family member** and choose the recipient (`transfer_to_user_id`). That is create-only. The server finds an open personal in-family debt the payer already owes that person (`balance > 0`) or creates one for the payment amount, then runs the same `createDebtRepaymentExpense` pair as step 7 onward. Advance fund is cleared; family split is still allowed. Plaid confirm does not learn `is_debt_payment` / `debt_id` from this path.

Newly added fields track:
- **`paid_by_user_id`:** Which user initiated the payment (important for multi-user families)
- **`is_closeout_initiated`:** Whether the payment came from a manual entry (`false`) or a month closeout rule (`true`)

The payment history modal in `Debts.vue` displays:
- Unified timeline rows (loan starts, direction reversals, payments) with `From → To` money-flow labels
- For inter-family running debts, history from **overpayment reversal lineage** (not every independent loan between the same pair)
- Remaining balance and closeout additions from the lineage envelope (so History on a settled original still shows the open reverse remaining)
- Date of each entry and signed amount (`+` loan / `-` payment)
- Split contribution breakdown (when payment was split), showing each participant's amount and percentage
- Whether a payment was initiated from a closeout (shown as a "Closeout" badge if applicable)
- Initial/loan rows based on pre-closeout principal (`debt.amount - sum(contributions) - incoming direction_reversals`) so closeout additions and merged reversal amounts do not overwrite historical debt start

---

## Workflow 2b: Creditor records a benefit expense for a debt repayment

Use case: B owes A money; B covers A's rent and marks the bank charge as paying toward that debt. A keeps the mirrored debt-repayment income and also records rent as an expense.

1. Debt-payment pair already exists (Workflow 2 or transaction-form debt payment): B expense + A income (`is_debt_payment`, linked via `mirror_transaction_id`)
2. A opens Transactions, taps **Record as expense** on the repayment income (or opens the Repayment pill → Record as expense)
3. `DebtPaymentBenefitForm` collects category (required), optional description, split, advance fund, remaining-exclusion, necessity
4. Vue submits `POST /transactions/{incomeId}/debt-payment-benefit`
5. `ClosedMonthGuard` checks A's month is open
6. `TransactionService::createDebtPaymentBenefit`:
   - Validates income is A's in-family debt-payment income and no benefit exists yet
   - Creates expense with `is_debt_payment_benefit=true`, `debt_payment_income_id=income.id`, same amount/date as income
   - Optional splits create `transaction_splits` + pending split debts (same as a normal expense)
7. Returns the benefit expense (HTTP 201)
8. Editing uses `PUT` on the same path; removing uses `DELETE` (debt-payment pair unchanged)
9. If the debt-payment pair amount changes or is deleted, the benefit amount syncs or the benefit is cascade-deleted

---

## Workflow 3: Borrowing from a Fund

1. User is on `Funds.vue`, chooses a fund and enters a borrow amount
2. Vue submits `POST /funds/{fund}/borrow` with:
   ```json
   { "amount": 200.00, "description": "Emergency" }
   ```
3. `FundController::borrow` authorizes via `FundPolicy::update` (owner, or any same-family member for a family-scoped fund)
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

## Workflow 4b: Manual Fund Balance Override

1. User is on `Funds.vue`, expands a fund, taps **Override**
2. Bottom sheet shows current balance and a **New Balance** field (pre-filled with the current amount)
3. Vue submits `POST /funds/{fund}/override` with:
   ```json
   { "balance": 250.00, "description": "optional note" }
   ```
4. `FundController::overrideBalance` authorizes via `FundPolicy::update` (owner, or any same-family member for a family-scoped fund)
5. **No** `ClosedMonthGuard` (same as sweep — no transaction is created)
6. `FundService::overrideBalance` rejects when the new balance matches the current balance (`422`)
7. `DB::transaction` begins:
   a. Sets `fund.balance` to the new value
   b. Creates `FundMovement` (`type=manual_override`, `amount` = signed delta, description `Set to $X.XX` plus optional note)
8. Returns the movement (HTTP 201)
9. Funds page history shows the row as **Manual Override**. Month Summary Fund In/Out and Transactions do not include it.

---

## Workflow 5: Income Allocation via Fund Rules

Triggered during `MonthCloseoutService::hardClose()`, not on individual income transactions.

`hardClose` picks `ClassicCloseoutEngine` or `FamilyPooledCloseoutEngine` from `families.closeout_mode`. Classic math is unchanged (below). After either engine, `applyFundAdvances` (per user), `consolidatePendingSplitDebts`, and `applyMonthlyDebtInterest` run, then `MonthHardClose` stores `closeout_mode`, `settings_snapshot`, and `results_snapshot`. Viewing that month later (Month Summary **Closeout Results**, fund balances, closeout ledger rows) uses the snapshot — or classic reconstruction if a snapshot is missing — even if the family later switches modes. Undo hard close deletes that row (snapshot included); re-closing then uses the **current** mode.

### Classic engine

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

### Family pooled engine

1. **Stage A — surplus (charity):** `charity_base = family_earned_income − family_necessary_expenses` (expenses with `is_necessity=true`). Active `FamilyCloseoutRule` rows with `stage=surplus` allocate from that base (shared-percentage behavior). If `charity_base <= 0`, surplus rules allocate 0.
2. **Stage B — remaining after charity:** `remaining_after_charity = family_earned_income − family_all_expenses − surplus_allocations` (**all** closeout-eligible expenses, including `is_necessity=false`; **`exclude_from_expense_basis` is ignored**). Inter-member debt payments are omitted from family income and family expenses. If `remaining_after_charity <= 0`, skip remaining family rules and leftover split.
3. **Stage C — family remaining % rules:** `stage=remaining_after_charity` rules share the same remaining-after-charity basis.
4. **Stage D — leftover split:** `leftover = remaining_after_charity − stage_C_actuals`. For each member, `burden = split_spend / own_earned_income` (split shares only; solo bills ignored), `weight = max(0, 1 − burden)`, `share = weight / sum(weights)`, `member_pool = leftover * share`. Zero income or split > income → weight 0. All weights 0 → leftover unallocated.
5. **Stage E — personal leftover rules:** each member’s remaining-base `FundRule`s run against `member_pool`. Personal gross/net rules are skipped. Family-rule fund allocations are attributed to the user who hard-closed with `closeout_scope=family`. **`exclude_from_expense_basis` is ignored** (classic remaining-exclusion only).

**After rule processing:** `hardClose` runs `consolidatePendingSplitDebts`, which nets pending split debts for the closed month—including pending rows with a null `transaction_id` so they are not skipped—then applies each pair net via `DebtService::applyInterFamilyPairNet` (also nets against opposite-direction confirmed debts) before deleting the pending rows.

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
   - confirmed debts with month/year entries in `contributions` remove those entries and subtract that amount from `amount` + `balance` (negative contribution amounts from opposite-direction netting restore the reduced debt)
   - debts whose contributions become empty are deleted only when that month's contributions are marked `created_by_closeout_debt=true` (closeout-created debt rows)
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
   - `existing`: locks debt, increments `balance`, appends `income_additions` (principal `amount` unchanged)
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
   f. Reverses confirmed split debts that have a `contributions` entry for this month — debt rows are deleted only when reverted month contributions are marked `created_by_closeout_debt=true`; augmented existing debts have the contribution removed and `amount`/`balance` decremented
   g. Recreates `is_pending_closeout=true` debts from all split transactions (`is_split=true`, `is_closeout_initiated=false`) in the family/month that no longer have pending debt records
   h. Reverses interest: finds debts with `interest_accruals` entries for this year/month, subtracts accrual amounts from `debt.balance`, removes the entry from `interest_accruals`, restores `interest_last_applied_at`
   i. Deletes all `MonthSoftClose` records for this family/year/month
   j. Deletes the `MonthHardClose` record
7. Vue reloads the month summary — the month appears open again

Known limitations:

- If a confirmed split debt was partially paid down by a user after closeout, undoing the closeout reduces the debt balance but cannot restore it below zero. Balance is clamped at `max(0, balance - contribution_amount)`.
- The system does not guard against undoing a month when a subsequent month is already hard-closed; this can introduce inconsistencies in multi-month interest calculations or fund balances.

---

## Workflow 11: Plaid sync, confirm, and pending → posted id change

1. User links a bank or taps **Sync** (`POST /plaid/items/{id}/sync` or webhook) → `PlaidTransactionSyncService::syncItem` (`/transactions/sync` + cursor). **Sync this month / last month** uses `/transactions/get` + `ingestPlaidRowsAsPending` (same **added** path, **no** `modified`/`removed`).
2. For each new Plaid payload, `processAddedRow` **returns immediately when `pending` is `true`** (wait until the bank posts). Otherwise it extracts `transaction_id` and skips only if that **exact string** already exists on `plaid_pending_imports` or `transactions.plaid_transaction_id` for the family.
3. Otherwise a `plaid_pending_imports` row is created (`status=pending`, or dismissed/auto-created/auto-linked per merchant rule / ledger score).
4. User confirms on Import Review (`POST …/confirm`): `TransactionService::createTransaction`, then copies `pendingImport.plaid_transaction_id` onto the ledger row and sets import `status=confirmed`. Description edits live on the ledger row only. Expense confirm may include `debt_id` or create-only `transfer_to_user_id` (same in-family debt-payment pair; merchant learning skips `is_debt_payment` / `debt_id` for family transfers).
5. **Same Plaid id later:** `modified` updates amount/date on leftover still-pending imports and on ledger rows keyed by that id. If a **posted** `modified` arrives and nothing was ingested yet (pending was skipped), `processAddedRow` runs. `removed` deletes only still-`pending` imports.
6. **Pending → posted (most US institutions):** Plaid **removes** the pending `transaction_id` and **adds** a new posted id. Because pending `added` rows are no longer stored, the posted `added` row is the first Review item. **Leftover** confirms from before this skip can still duplicate: `pending_transaction_id` is unused, ledger auto-match ignores already-linked rows, and confirming the new Review item creates a second ledger transaction.
7. Institutions that keep the same id and send `modified` with `pending: false` ingest at modification time.

See `docs/ai/09-known-decisions.md` (known bug) and `PlaidTransactionSyncService` / `PlaidMatchingService`.

**Category defaults vs merchant rules:** Saving an expense category copies family necessity onto every member's existing `plaid_merchant_rules` for that category (and open pending suggestions / unreviewed auto-created rows). The saver's personal advance / remaining-exclusion are applied only to the saver's rules. **Apply defaults to bank learning** applies the signed-in user's personal defaults plus family necessity onto their own rules. Confirmed history and closed-month auto-created rows are left alone.

---

## Workflow 12: Deleting a fund (current behavior)

1. User is on `Funds.vue`, taps the trash icon on a fund card, then taps **Confirm?**
2. Vue sends `DELETE /funds/{id}`
3. `FundController::destroy` authorizes via `FundPolicy::delete` (owner, or family-scoped + `can_manage_family`)
4. Controller calls `$fund->delete()` with **no** detach of related rows
5. **Success path:** fund row is removed. `fund_movements.fund_id` and `fund_rules.fund_id` are `cascadeOnDelete`. `debts.fund_id`, `transactions.fund_id`, and `category_user_defaults.advance_fund_id` are `nullOnDelete`.
6. **Failure path (production Railway):** if any `transactions.advance_fund_id` still points at the fund, MySQL raises SQLSTATE 23000 / 1451 on `transactions_advance_fund_id_foreign`. Laravel returns **500**. `Funds.vue` only `console.error`s; the card stays.
7. Workaround today: retarget or clear **Advance against fund** on those expenses (or delete those transactions) before deleting the fund.

See `docs/ai/09-known-decisions.md`.
