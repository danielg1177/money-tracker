# 02 — Backend (Laravel)

## Entry points

- `bootstrap/app.php` — Laravel 13 bootstrap; registers `AppServiceProvider` and `FortifyServiceProvider`; CSRF exclusion for `plaid/webhook`
- `routes/web.php` — all routes (no `api.php`)
- `app/Providers/AppServiceProvider.php` — defines Gates: `admin`, `head_of_household`, `manage_family`
- `app/Providers/FortifyServiceProvider.php` — wires Fortify actions + rate limiters

## Models

### User (`app/Models/User.php`)
- Fields: `name`, `email`, `password`, `family_id` (nullable FK), `role`, `is_admin` (boolean), `bank_balance_enabled` (boolean), `bank_balance` (decimal nullable), `bank_balance_set_at` (date nullable), `view_family_expenses` (boolean, default false)
- Role values (strings): `head_of_household`, `member` (admin is now a separate boolean)
- System admin: Boolean `is_admin` column; when true, grants admin permissions independent of family role
- Appended computed attributes (serialized in JSON): `is_admin`, `is_head_of_household`, `can_manage_family`, `closeout_mode` (from `family.closeout_mode`, default `classic`)
- Uses PHP 8 attribute annotations `#[Fillable]`, `#[Hidden]`
- Relations: `belongsTo(Family)`, `hasMany(Transaction)`, `hasMany(Fund)`, `hasMany(FundMovement)` as `fundMovements`, `hasMany(Debt, 'debtor_id')` as `debtsOwed`, `hasMany(Debt, 'creditor_id')` as `debtsOwedTo`, `hasMany(MonthSoftClose)` as `monthSoftCloses`, `hasMany(PlaidItem)` as `plaidItems`, `hasMany(CategoryUserDefault)` as `categoryDefaults`

### Family (`app/Models/Family.php`)
- Fields: `name`, `description`, `closeout_mode` (`classic` default | `family_pooled`)
- Relations: `hasMany(User)`, `hasMany(Category)`, `hasMany(Transaction)`, `hasMany(Debt)`, `hasMany(Fund)`, `hasMany(FamilyCloseoutRule)`, `hasMany(MonthSoftClose)`, `hasMany(MonthHardClose)`

### Category (`app/Models/Category.php`)
- Fields: `family_id`, `name`, `icon`, `is_income` (bool), `is_expense` (bool), `is_split_default` (bool), `split_default` (JSON array), `is_necessity_default` (bool, expense categories; family-shared)
- **Type constraint:** exactly one of `is_income` / `is_expense` must be true (not both, not neither); validated in `StoreCategoryRequest::withValidator`
- `split_default` is meaningful only when `is_expense` is true; `StoreCategoryRequest` clears split defaults when saving an income-only category
- Relations: `belongsTo(Family)`, `hasMany(Transaction)`, `hasMany(CategoryUserDefault)` as `userDefaults`

### CategoryUserDefault (`app/Models/CategoryUserDefault.php`)
- Fields: `category_id`, `user_id`, `advance_fund_id` (nullable), `exclude_from_expense_basis_default` (bool)
- Purpose: user-specific category defaults for expense transactions (advance + remaining-exclusion); one row per (`category_id`, `user_id`). Necessity defaults live on the shared `categories` row.
- Relations: `belongsTo(Category)`, `belongsTo(User)`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`

### Transaction (`app/Models/Transaction.php`)
- Fields: `family_id`, `user_id`, `category_id` (nullable), `type` (`income`|`expense`), `amount` (decimal:2), `description`, `transaction_date` (date), `is_split` (bool), `split_data` (JSON array), `fund_id` (nullable), `advance_fund_id` (nullable), `is_borrow` (bool), `is_debt_payment` (bool), `is_debt_payment_benefit` (bool), `debt_id` (nullable FK → debts), `mirror_transaction_id` (nullable FK → transactions), `debt_payment_income_id` (nullable FK → transactions), `plaid_transaction_id` (nullable), `import_source` (nullable string, 32), `plaid_pending_import_id` (nullable FK → plaid_pending_imports), `paid_by_user_id` (nullable FK → users), `is_closeout_initiated` (bool), `closeout_scope` (`user` \| `family` \| null), `is_repayment` (bool), `is_repaid` (bool), `is_repayment_mirror` (bool) — DB columns `plaid_transaction_id` / `import_source` are mass-assignable; unique per `family_id` when Plaid id set; `PlaidTransactionSyncService` may set them when auto-importing from Plaid
- `split_data` is a snapshot of split percentages stored on the transaction itself
- `debt_id` links a payment transaction to the debt it settles
- `paid_by_user_id` tracks which user initiated the payment (may differ from `user_id` for creditor income rows)
- `is_closeout_initiated` distinguishes manual rows (`false`) from backend-generated closeout movement rows (`true`) across debt payments, fund allocations, and title-completion expenses
- `closeout_scope` (`user` | `family` | null) tags closeout-initiated rows; family-pooled family-rule allocations use `family` and are attributed to the user who hard-closed
- `is_debt_payment_benefit` / `debt_payment_income_id` link an optional creditor expense to debt-payment income (same amount/date; normal expense for totals)
- `is_repayment` / `is_repaid` / `is_repayment_mirror` flag expense-repayment linking rows (income repayment, repaid expense, and mirror income for another member)
- Scopes: `whereNotRepaymentIncome()` / `whereNotRepaidExpense()` — used by Month Summary category totals so reimbursement income and reimbursed expenses drop out when **either** the flag is set **or** a `transaction_repayment_links` row exists (covers older rows that were linked without flags)
- Relations: `belongsTo(Family)`, `belongsTo(User)`, `belongsTo(User, 'paid_by_user_id')` as `paidByUser`, `belongsTo(Category)`, `belongsTo(Fund)`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`, `belongsTo(Debt)` via `debt_id`, `belongsTo(Transaction, 'mirror_transaction_id')` as `mirrorTransaction`, `hasOne(Transaction, 'debt_payment_income_id')` as `debtPaymentBenefitExpense`, `belongsTo(Transaction, 'debt_payment_income_id')` as `debtPaymentIncome`, `hasMany(TransactionSplit)` as `splits`, `hasMany(Debt)` as `debts` (split-linked debts), `hasMany(FundMovement)` as `fundMovements`, `hasOne(PlaidPendingImport, 'transaction_id')` as `plaidPendingImport`, `hasMany(TransactionRepaymentLink, 'repayment_transaction_id')` as `repaymentLinks`, `hasOne(TransactionRepaymentLink, 'repaid_transaction_id')` as `repaidByLink`, `hasOne(TransactionRepaymentLink, 'mirror_transaction_id')` as `mirrorRepaymentLink`

### TransactionRepaymentLink (`app/Models/TransactionRepaymentLink.php`)
- Table `transaction_repayment_links`: links an income **repayment** transaction to a repaid **expense** (and optional mirror income for another family member).
- Fields: `repayment_transaction_id` (FK → transactions, `cascadeOnDelete`), `repaid_transaction_id` (FK → transactions, `cascadeOnDelete`), `mirror_transaction_id` (nullable FK → transactions, `nullOnDelete`), `repaid_user_id` (FK → users, `cascadeOnDelete`), `amount` (decimal:14,2)
- Relations: `belongsTo(Transaction, 'repayment_transaction_id')` as `repaymentTransaction`, `belongsTo(Transaction, 'repaid_transaction_id')` as `repaidTransaction`, `belongsTo(Transaction, 'mirror_transaction_id')` as `mirrorTransaction`, `belongsTo(User, 'repaid_user_id')` as `repaidUser`

### PlaidItem (`app/Models/PlaidItem.php`)
- Fields: `user_id`, `item_id` (Plaid), `access_token` (encrypted at rest), `institution_id`, `institution_name`, `transactions_cursor` (for `/transactions/sync`)
- Relations: `belongsTo(User)`, `hasMany(PlaidPendingImport)` as `pendingImports`; custom `resolveRouteBinding` restricts `{plaidItem}` routes to the authenticated owner

### PlaidPendingImport (`app/Models/PlaidPendingImport.php`)
- Staging row for a Plaid transaction before ledger confirm; table `plaid_pending_imports` (see migration `2026_05_11_210000_add_plaid_import_infrastructure.php`).
- `resolveRouteBinding` scopes `{pendingImport}` routes to `user_id` = authenticated user.
- Casts: `amount` decimal:2, `date` date, `raw_payload` array, `suggested_exclude_from_expense_basis` bool, `suggested_is_necessity` bool, `suggested_is_debt_payment` bool, `suggested_split_data` array, `confidence_score` decimal:4, `ledger_match_score` decimal:4, `sweep_match_score` decimal:4, `reviewed_at` datetime.
- `suggested_description` / `suggested_is_debt_payment` / `suggested_debt_id` / `suggested_split_data` — learned fields populated from `PlaidMerchantRule` at sync time; advisory (`suggested_debt_id` has no FK constraint).
- `dismiss_source` — nullable varchar(16); `'auto'` when dismissed by a merchant rule during sync, `'manual'` when dismissed by the user via the UI (dismiss / always-ignore). Null on non-dismissed rows.
- `reviewed_at` — nullable timestamp; set when a user reviews/audits an auto-dismissed entry so it stops appearing in the review queue.
- `suggested_ledger_match_id` / `ledger_match_score` — populated during Plaid sync when a ledger auto-match is found; advisory until the user confirms or links.
- `suggested_sweep_match_id` / `sweep_match_score` — populated during Plaid sync when an unlinked `savings_sweep` `FundMovement` is a near match; advisory only (no auto-link). `fund_movement_id` set when the user confirms via `link-to-sweep`.
- Relations: `belongsTo(User)`, `belongsTo(PlaidItem)` as `plaidItem`, `belongsTo(Transaction)` as `transaction`, `belongsTo(Transaction, 'suggested_ledger_match_id')` as `suggestedLedgerMatch`, `belongsTo(FundMovement, 'suggested_sweep_match_id')` as `suggestedSweepMatch`, `belongsTo(FundMovement, 'fund_movement_id')` as `fundMovement`.
- `scopePending` — `status = pending`. `isAutoCreateEligible()` always `false` (merchant rules gate auto-create).

### PlaidMerchantRule (`app/Models/PlaidMerchantRule.php`)
- Per-user merchant-key defaults; table `plaid_merchant_rules`.
- Casts: `exclude_from_expense_basis`, `is_necessity`, `is_split`, `is_debt_payment` bool; `split_data` array; `confirmation_count`, `total_seen_count` integer. `confidenceScore()` is computed as `confirmation_count / total_seen_count` (or `0.0` when `total_seen_count` is 0); not a DB column.
- New learned fields (added 2026-05-15): `description` (string|null), `is_debt_payment` (bool), `debt_id` (unsignedBigInteger|null, no FK — advisory reference, survives debt deletion), `split_data` (json|null — `[{user_id, share_percentage}]`).
- `isAutoCreateEligible()` — `confirmation_count >= 3` and `confidenceScore() >= 0.80`.
- `normalizeKey(string)` — lowercase, strip non-alphanumeric except spaces, collapse whitespace, trim.
- Relations: `belongsTo(User)`, `belongsTo(Category)`, `belongsTo(Fund)` as `fund`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`.

### TransactionSplit (`app/Models/TransactionSplit.php`)
- Fields: `transaction_id`, `user_id`, `share_percentage` (decimal:2), `amount` (decimal:2)
- Represents each user's computed dollar share of a split transaction
- Relations: `belongsTo(Transaction)`, `belongsTo(User)`

### Fund (`app/Models/Fund.php`)
- Fields: `user_id`, `family_id` (nullable — null = personal), `name`, `description`, `balance` (decimal:2, starts at 0)
- Personal savings bucket (`family_id` null) or family-shared bucket (`family_id` set; `user_id` is the creator)
- Relations: `belongsTo(User)`, `belongsTo(Family)`, `hasMany(FundRule)`, `hasMany(FundMovement)`, `hasMany(Debt)`
- **Delete:** `FundController::destroy` authorizes via `FundPolicy` then calls `$fund->delete()` with no detach of child rows. Transactions that still point at the fund via `advance_fund_id` cause a MySQL 1451 on Railway (see `docs/ai/09-known-decisions.md`).

### FamilyCloseoutRule (`app/Models/FamilyCloseoutRule.php`)
- Fields: `family_id`, `name`, `order`, `is_active`, `stage` (`surplus` | `remaining_after_charity`), `allocation_type`, `amount`, `destination_type` / `destination_id` / `destination_title`, `closeout_expense_category_id`
- Family-pooled closeout stages only; CRUD requires `can_manage_family`
- Relations: `belongsTo(Family)`

### FundRule (`app/Models/FundRule.php`)
- Fields: `user_id`, `fund_id`, `name`, `order` (int), `allocation_type` (`percentage`|`fixed`), `amount` (decimal:2), `allocation_base` (`gross_income`|`net_income`|`remaining`), `is_active` (bool), `destination_type` (`fund`|`debt`|`title`), `destination_id` (nullable), `destination_title` (nullable), `closeout_expense_category_id` (nullable expense-category FK)
- Rules are processed in `order` ASC during month hard-close processing; inactive rules are skipped
- `net_income` base is tracked but **not independently reduced** by deductions — it equals `gross` unless manually managed (Needs verification: whether net differs from gross in current implementation)
- Relations: `belongsTo(User)`, `belongsTo(Fund)`

### FundMovement (`app/Models/FundMovement.php`)
- Fields: `fund_id`, `user_id`, `type` (`allocation`|`borrow`|`repayment`|`initial_value`|`closeout_allocation`|`advance_settlement`|`savings_sweep`|`manual_override`), `amount`, `transaction_id` (nullable), `plaid_pending_import_id` (nullable), `plaid_transaction_id` (nullable), `description` (nullable)
- Audit ledger for every fund balance change
- Relations: `belongsTo(Fund)`, `belongsTo(User)`, `belongsTo(Transaction)`, `belongsTo(PlaidPendingImport)` as `plaidPendingImport`

| `type` | Balance effect | `Transaction` | Notes |
|---|---|---|---|
| `allocation` | Fund balance incremented | Yes (income) | Legacy/rule path; not used on income save today |
| `borrow` | Fund balance decremented | Yes (income, `is_borrow`) | Creates linked `Debt` |
| `repayment` | Fund balance incremented | Yes (expense, `is_debt_payment`) | Fund debt repayment |
| `initial_value` | Fund balance incremented | No | Starting balance at fund creation |
| `closeout_allocation` | Fund balance incremented | Yes (closeout expense) | Month hard-close rule payout |
| `advance_settlement` | Fund balance decremented | No | Month close settles `advance_fund_id` expenses |
| `savings_sweep` | Fund balance decremented | No | User sweeps fund balance to external savings account |
| `manual_override` | Fund balance set to an explicit value | No | Signed `amount` is the delta (positive = increase). Fund history only; Month Summary Fund In/Out excludes this type |

### Debt (`app/Models/Debt.php`)
- Fields: `family_id`, `debtor_id` (FK → users), `creditor_id` (nullable FK → users), `fund_id` (nullable FK → funds), `transaction_id` (nullable FK → transactions, `cascadeOnDelete` for split-linked rows), `amount` (original amount), `balance` (remaining), `description`, `is_family_debt` (bool), `is_pending_closeout` (bool — true during month hard-close split processing; pending debts are excluded from `GET /debts` and cannot be manually paid), `creditor_name` (nullable string for external creditors), `contributions` (JSON array nullable), `income_additions` (JSON array nullable — income attached to an existing debt via `income_debt_mode=existing`), `interest_enabled` (bool), `interest_rate` (APR decimal), `interest_last_applied_at` (date nullable), `loan_received_date` (date nullable), `interest_accruals` (JSON array nullable), `reversed_from_debt_id` (nullable self-FK), `direction_reversals` (JSON array nullable)
- `creditor_id` is null when the debt is to a fund (borrow scenario) or to an external party
- `creditor_name` stores plain text creditor names (e.g., "Bank of America") when `creditor_id` is null and `is_family_debt=false`
- `is_family_debt` controls visibility: false = personal debt (debtor + creditor only); true = visible to all family members
- `balance` decrements as payments are made; a debt with `balance = 0` is fully paid
- `contributions` records closeout contributions as `[{month, year, amount}]` tuples, used by the debt history modal to show "Closeout Additions" separate from manual payments
- Interest accrues only during month hard-close when `interest_enabled=true`, `interest_rate` is set, and `balance > 0`
- Interest accrual uses a daily-rate model (`APR / 365`) over the closed month window, reducing accrual after any in-month payment (`transactions.type='expense'`, `is_debt_payment=true`) and respecting `loan_received_date`
- Interest increases `balance` only (principal `amount` remains the original loan value) and appends a ledger entry to `interest_accruals`
- Relations: `belongsTo(Family)`, `belongsTo(User, 'debtor_id')` as `debtor`, `belongsTo(User, 'creditor_id')` as `creditor`, `belongsTo(Fund)`, `belongsTo(Transaction)`, `belongsTo(self, 'reversed_from_debt_id')` as `reversedFrom`

### CloseoutTitleSaving (`app/Models/CloseoutTitleSaving.php`)
- Fields: `family_id`, `user_id`, `year`, `month`, `title`, `amount`, `rule_id`, `is_completed`, `completed_at`, `completion_transaction_id`
- Casts: `amount` decimal:2, `year` integer, `month` integer, `is_completed` bool, `completed_at` datetime
- Relations: `belongsTo(Family)`, `belongsTo(User)`

### MonthSoftClose (`app/Models/MonthSoftClose.php`)
- Fields: `family_id`, `user_id`, `year`, `month`, `closed_at`
- Unique: (`family_id`, `user_id`, `year`, `month`)
- Relations: `belongsTo(Family)`, `belongsTo(User)`

### MonthHardClose (`app/Models/MonthHardClose.php`)
- Fields: `family_id`, `year`, `month`, `closed_at`, `closed_by_user_id` (nullable), `closeout_mode`, `settings_snapshot` (JSON), `results_snapshot` (JSON)
- Unique: (`family_id`, `year`, `month`)
- Relations: `belongsTo(Family)`, `belongsTo(User, 'closed_by_user_id')` as `closedBy`
- Hard-closed Month Summary reads `results_snapshot` when present; undo deletes the row (snapshot included)

## Controllers

All controllers extend `app/Http/Controllers/Controller.php` (uses `AuthorizesRequests`).

### TransactionController
- `repayableExpenses(Request)` — `GET /transactions/repayable-expenses`; auth user's own expenses eligible for expense-repayment linking (`is_repaid=false`, not `is_repayment_mirror` or `is_closeout_initiated`); optional date filters; `category` eager-loaded
- `index(Request)` — default: viewer-scoped family transactions (`user_id` or `transaction_splits` participation). Optional `view=family` returns all family rows in range (splits still unique by id). Filtered by `start_date`/`end_date`, eager-loads `user`, `category`, `splits.user`, `debt` (+ nested relations), `advanceFund`, `debtPaymentBenefitExpense`, `debtPaymentIncome`, `plaidPendingImport.plaidItem` (null for non-Plaid rows; provides `institution_name`); excludes split debt-payment expenses for the creditor when they duplicate that creditor’s repayment income row
- `store(StoreTransactionRequest)` — validates closed-month status via `ClosedMonthGuard`, then delegates to `TransactionService::createTransaction`
- `update(StoreTransactionRequest, Transaction)` — 403 unless the auth user owns the row **or** shares its `family_id`; validates both the existing row month and target payload month via `ClosedMonthGuard` (soft-close is the **owner’s** month, not the editor’s); then delegates to `TransactionService::updateTransaction`. The Transactions UI only offers this for the viewer’s own rows, plus **`can_manage_family`** users editing other members’ rows.
- `destroy(Transaction)` — same family-or-owner check as update; validates closed-month status via `ClosedMonthGuard`; delegates `TransactionService::deleteTransaction()` (paired debt-payment cleanup + mirror rows + benefit expense). UI delete follows the same family-manager exception as update.
- `storeDebtPaymentBenefit` / `updateDebtPaymentBenefit` / `destroyDebtPaymentBenefit` — creditor-owned debt-payment income only; create/update/delete linked benefit expense via `TransactionService`

### FundController
- `index()` — personal funds: `auth()->user()->funds()->whereNull('family_id')`; family funds: `Fund::where('family_id', $user->family_id)` when set; merged JSON with `scope` per row; each row also includes `has_remaining_percentage_rule` (true when the auth user has an active `destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'` rule targeting that fund id); `fundRules` and `movements.user` eager-loaded
- `store(Request)` — inline validation, creates fund for auth user
- `update(Request, Fund)` — authorizes via `FundPolicy`, inline validation
- `showRules()` — returns all `FundRule` rows for the auth user ordered by `order`; takes no parameters and performs no policy check; also mounted at `GET /funds/{fund}/rules` for backward compatibility (the `{fund}` parameter is ignored)
- `storeRule(Request)` — inline validation (+ duplicate check), creates `FundRule` for `auth()->id()`. For **`destination_type='title'`** rules that are **active**, **`destination_title`** must be **unique** among that user’s other **`destination_type='title'`** + **`is_active=true`** rows (avoids ambiguous **`CloseoutTitleSaving.rule_id`** when completing a title)
- `updateRule(FundRule, Request)` — `403` if `fundRule.user_id !== auth()->id()`; same validation as `storeRule`, ignoring the current rule when checking title uniqueness
- `destroy(Fund)` — authorizes via `FundPolicy`, then `$fund->delete()` with no child-row cleanup. Returns `{message: 'Fund deleted'}` on success. **500** on Railway when any `transactions.advance_fund_id` still points at the fund (FK `transactions_advance_fund_id_foreign` is restrict in production; see `docs/ai/09-known-decisions.md`)
- `borrow(Fund, Request)` — authorizes via `FundPolicy`, rejects if the current month is closed for the user via `ClosedMonthGuard`, then delegates to `FundService::borrowFromFund`
- `sweep(Fund, SweepFundRequest)` — authorizes via `FundPolicy`; **no** `ClosedMonthGuard`; delegates to `FundService::sweepToSavings`; returns `201` with the `FundMovement` (includes `user`)
- `overrideBalance(Fund, OverrideFundRequest)` — authorizes via `FundPolicy`; **no** `ClosedMonthGuard`; delegates to `FundService::overrideBalance`; returns `201` with the `FundMovement` (includes `user`); `422` when the new balance matches the current balance
- `repayFund(Debt, Request)` — checks `debtor_id === auth()->id()`, rejects if the current month is closed for the user via `ClosedMonthGuard`, then delegates to `FundService::repayFund`

### DebtController
- `index()` — returns `{ owed: [...], owing: [...], family_debts: [...] }` where:
  - `owed` = personal debts where auth user is **debtor** (non-family)
  - `owing` = personal debts where auth user is **creditor** (non-family)
  - `family_debts` = family-shared debts visible to all family members
- `store(Request)` — creates debts supporting three types:
  - **Personal to external parties:** `creditor_name` provided, `creditor_id` null, `is_interfamily=false`
  - **In-family:** `creditor_id` provided, user is a different family member, `is_interfamily=true`
  - **Family-shared:** `is_family_debt=true`, visible to all family members
- `store(Request)` also accepts optional loan/interest fields (`interest_enabled`, `interest_rate`, `loan_received_date`)
- `update(Request, Debt)` — updates `description`, `creditor_name`, and optional loan/interest settings (`interest_enabled`, `interest_rate`, `loan_received_date`); only debtor or `can_manage_family` user may update; rejects pending closeout debts
- `destroy(Debt)` — hard delete (`$debt->delete()`); only debtor or `can_manage_family` user can delete; cannot delete pending closeout debts
- `payDebt(PayDebtRequest)` — validates closed-month status for the **payer** via `ClosedMonthGuard` (creditor / split co-participant soft closes do not block), then delegates to `DebtService::payDebt`; accepts optional `transaction_date` to backdate/explicitly date debt-payment transactions
- `paymentHistory(Debt)` — JSON envelope `{ entries, contributions, remaining, remaining_debtor_id, remaining_creditor_id, remaining_debtor_name, remaining_creditor_name }`. For confirmed personal **inter-family** running debts, `entries` aggregate the **overpayment reversal lineage** (`reversed_from_debt_id`, walked both ways) rather than every independent loan between the same pair; per related debt, role-based filtering still applies (creditor sees **income** rows with their `user_id`; others see **expense** rows); includes optional `split_breakdown` per payment; appends synthetic `income_addition`, `loan_receipt`, `interest_accrual`, `direction_reversals`, and `initial_value` (principal-only: `debt.amount - sum(contributions) - incoming direction_reversals`) entries; each row includes `debt_id`, `flow_kind` (`loan`/`payment`/…), `flow_from_*` / `flow_to_*`, and `is_direction_reversal` when the debt/row came from overpayment; `contributions` and `remaining` are lineage-scoped; debtor/creditor/`can_manage_family` required to access
- `splitDebtSummary(Request)` — `GET /split-debt-summary?year=&month=`; returns pending split debts for the current user's family grouped by counterpart user with `you_owe`, `they_owe`, and nested `transactions`

### CategoryController
- `index()` — returns family categories with shared `is_necessity_default` plus `advance_fund_id` + `exclude_from_expense_basis_default` hydrated from the authenticated user's `category_user_defaults` row for each category
- `store(StoreCategoryRequest)` — creates shared category for auth user's family (including family `is_necessity_default`), then stores auth-user defaults (`advance_fund_id`, `exclude_from_expense_basis_default`) in `category_user_defaults`, then `PlaidMerchantRuleCategorySync::syncFamilyCategory` copies **family necessity** onto every member's merchant rules for that category and copies **the saver's** personal advance / remaining-exclusion onto the saver's rules only
- `update(StoreCategoryRequest, Category)` — same-family check then updates shared family category fields (including `is_necessity_default`); updates only the authenticated user's `category_user_defaults` row for per-user advance / remaining-exclusion; then the same family Plaid sync as store
- `syncPlaidMerchantRules(SyncPlaidMerchantRulesFromCategoriesRequest)` — `POST /categories/sync-plaid-rules`; requires `family_id`; copies **all** of the auth user's expense-category defaults onto their bank merchant rules (and matching open pending/auto-created rows)
- `destroy(Category)` — same-family check (`$category->family_id === auth user family_id`); no `CategoryPolicy`. Any member of that family can delete.

### AdminController
- `users()` — all users with `family`
- `createUser(Request)` — creates user with hashed password; role must be `member` or `head_of_household` (admin is now a separate checkbox); `is_admin` boolean field
- `updateUser(Request, User)` — updates user profile fields and supports optional password reset when `password` is provided (`min:8`); includes `is_admin` in allowed updates
- `deleteUser(User)` — cannot delete self
- `families()` — all families with `users` and `categories`
- `createFamily(Request)` — creates family
- `updateFamily(Request, Family)` — `head_of_household` can only update own family; `is_admin` can update any
- `deleteFamily(Family)` — nullifies `family_id` on all members before deleting
- `addFamilyMember(Request, Family)` — sets `family_id` on target user
- `removeFamilyMember(Family, User)` — nullifies `family_id` on target user
- `myFamily()` — returns auth user's family with `users` and `categories`

### DashboardController
- `monthlyTotals()` — returns `{total_income, total_expenses}` for the current calendar month for the auth user; excludes `is_debt_payment=true` and closeout-generated (`is_closeout_initiated`) expenses; returns zeros if user has no `family_id`

### BankBalanceController
- `show()` — returns bank balance tracking state for auth user: disabled/null state when feature is off, baseline-not-set state when no baseline date exists, or computed balance state (`bank_balance + income - expense - completed title savings` since `bank_balance_set_at`)
- `update(UpdateBankBalanceRequest)` — updates `bank_balance_enabled` and/or baseline `bank_balance`; when a balance is provided it also sets `bank_balance_set_at` to today and forces enabled state
- `completeTitleSaving(int $id)` — marks one user-owned `CloseoutTitleSaving` row as completed, stamps `completed_at`, and creates a closeout-tagged expense transaction (`is_closeout_initiated=true`) using the rule’s optional `closeout_expense_category_id`
- `incompleteTitleSaving(int $id)` — clears completion state/timestamp and deletes the generated completion transaction when present

### UserSettingsController
- `update(UpdateUserSettingsRequest)` — `PUT /user/settings`; persists `view_family_expenses` on the authenticated user and returns the refreshed user JSON

### PlaidController
- `linkToken(Request)` — `GET /plaid/link-token`; calls Plaid `/link/token/create` with `products` `['transactions']`, `country_codes` `['US']`, `transactions.days_requested` from config, and **`financekit_supported: true`** when `config('plaid.financekit_supported')` is true (env `PLAID_FINANCEKIT_SUPPORTED`, default true) so Link can offer FinanceKit / Apple Card where supported; returns `{link_token}`; `503` when credentials missing
- `exchange(ExchangePlaidTokenRequest)` — `POST /plaid/exchange`; exchanges `public_token`, persists `PlaidItem` with encrypted access token, hydrates institution metadata, runs initial `PlaidTransactionSyncService::syncItem` (returns JSON `pull` including raw Plaid rows; creates `plaid_pending_imports` for new `added` transactions and may auto-create ledger rows when `PlaidMerchantRule` qualifies)
- `items(Request)` — lists auth user’s linked items (no secrets)
- `sync(Request, PlaidItem)` — `POST /plaid/items/{plaidItem}/sync`; same as exchange pull; route binding scopes `{plaidItem}` to the owner
- `destroy(Request, PlaidItem)` — calls Plaid `/item/remove`, deletes local row

### PlaidImportController
- `index(Request)` — `GET /plaid/pending-imports`; JSON `{ pending, transfers, auto_created, dismissed, manually_dismissed, recently_confirmed }` (`pending` / `transfers` = `status=pending` by `is_transfer`, with `suggestedLedgerMatch` for match UI; **`auto_created`** = `status` in `auto_created` \| `auto_linked` **and** `reviewed_at` null, eager-loads `transaction` bundle + `suggestedLedgerMatch.category`; **`dismissed`** = `dismiss_source=auto`, unreviewed; **`manually_dismissed`** = `dismiss_source=manual`, unreviewed, `updated_at` desc, each row gets **`has_learned_dismiss_rule`** for UI labels; **`recently_confirmed`** = `status=confirmed` with `transaction_id`, `updated_at` within last 30 days). `?count_only=1` → `{ count, auto_created_count, dismissed_count }` (`auto_created_count` includes unreviewed `auto_linked`).
- `approveAutoCreated` — `POST …/approve-auto-created`; reinforces rule via `learnFromConfirmation` from linked transaction; sets **`reviewed_at`** on the pending row so it leaves the **auto_created** queue.
- `approveAutoLinked` — `POST …/approve-auto-linked`; same learning pattern for `auto_linked` rows; sets **`reviewed_at`**.
- `rejectAutoLinked` — `POST …/reject-auto-linked`; clears Plaid metadata on the linked ledger row and returns the pending import to `status=pending` for manual review.
- `correctAutoCreated` — `POST …/correct-auto-created`; updates linked transaction + `learnFromConfirmation`; sets **`reviewed_at`** on the pending row.
- `confirm(StoreImportConfirmRequest, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/confirm`; owner-only; validates with the same shared transaction payload rules as manual creates (splits, pay-toward-debt, advance fund + non-necessity, income-debt modes); server merges pending **amount** and **date** into the request for validation; `TransactionService::createTransaction` + `plaid_transaction_id` / `import_source=plaid`; `transactions.fund_id` is set from request `fund_id` when present, otherwise from **`advance_fund_id`** for qualifying expenses (merchant rule `fund_id`/`advance_fund_id` learned the same way); `learnFromConfirmation` (passes `is_split`), pending `confirmed` + `transaction_id`; `ClosedMonthGuard` on payload; `403` without `family_id`.
- `dismiss(Request, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/dismiss`; `status=dismissed`, `dismiss_source=manual`, `recordSeen` on matching merchant rule; `204`.
- `dismissAsTransfer(Request, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/dismiss-as-transfer`; owner-only (`auth()->id()`); pending only; sets `status=dismissed`, `dismiss_source=manual`; optional `?learn=true` calls `learnDismissRule` from `merchant_name` / `raw_name`; `204`. Works for **any** pending row (transfer-flagged or not) so users can dismiss card payments from **To Review** as well as the **Transfers** tab.
- `undoDismiss(Request, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/undo-dismiss`; owner-only; requires manually dismissed row; restores `status=pending`, clears `dismiss_source`, `deleteDismissRule` for merchant label; JSON `{ success, pending_import }`; `422` if not manually dismissed.
- `undoConfirm(Request, PlaidPendingImport)` — `POST …/undo-confirm`; owner-only; requires `status=confirmed` with linked `transaction`; `ClosedMonthGuard` on the primary row and any other ledger rows with the same `plaid_pending_import_id`; deletes rows created with the import and unlinks pre-existing secondaries; resets import to `status=pending`; JSON `{ success, pending_import }`.
- `ledgerLinkCandidates(Request, PlaidPendingImport)` — `GET …/ledger-candidates`; owner-only; non-transfer pending only; JSON candidate ledger rows for manual linking — **same `user_id` as the pending import** (see `PlaidMatchingService::findLedgerLinkCandidatesForPendingImport`).
- `linkedTransactions(Request, PlaidPendingImport)` — `GET …/linked-transactions`; owner-only; returns import metadata plus all ledger rows with `plaid_pending_import_id` set (for split-confirmed imports and bank pill UI).
- `splitLinkCandidates(Request, PlaidPendingImport)` — `GET …/split-link-candidates?amount=`; owner-only; pending only; requires positive `amount` query param; returns up to 30 unlinked ledger rows for the import owner (same `family_id`, no `plaid_transaction_id` / `plaid_pending_import_id`, not closeout-initiated, amount ±$0.01, date ±45 days) for per-line split linking UI.
- `confirmSplit(ConfirmSplitImportRequest, PlaidPendingImport)` — `POST …/confirm-split`; owner-only; min 2 `lines` summing to import amount; each line creates via `TransactionService` **or** links an existing row when `link_to_transaction_id` is set (validates family + owner + not already Plaid-linked); first created/linked row gets `plaid_transaction_id`; pending `confirmed` + `transaction_id` = first line; `ClosedMonthGuard` on create payloads and linked rows.
- `linkToLedger(LinkPlaidPendingImportRequest, PlaidPendingImport)` — `POST …/link`; owner-only; non-transfer pending; validates family `Transaction`, `canLinkPendingImportToLedger`, no duplicate `plaid_transaction_id` on another row; `learnFromConfirmation` + sets `plaid_transaction_id` / `import_source` on ledger; pending `confirmed`; **no** `ClosedMonthGuard` (allows linking Plaid ids onto historical closed-month rows).
- `sweepCandidates(Request, PlaidPendingImport)` — `GET …/sweep-candidates`; owner-only; JSON array of up to 30 unlinked `savings_sweep` `FundMovement` rows scoped to the import owner’s family (`funds.family_id` = user `family_id` **or** `fund_movements.user_id` in that family — covers legacy funds without `family_id`); no date filter; ordered by `created_at` proximity to the import date; each item `{ id, amount, description, date, fund_name }`; empty array when user has no `family_id`.
- `linkToSweep(Request, PlaidPendingImport)` — `POST …/link-to-sweep`; owner-only; `status` must be `pending` or `auto_linked`; body `fund_movement_id` (required, exists); validates `savings_sweep` type, movement not already linked, movement in same family as import owner (`funds.family_id` or `fund_movements.user.family_id` when fund `family_id` is null); sets `FundMovement.plaid_transaction_id` + `plaid_pending_import_id`, pending `status=confirmed` + `fund_movement_id`; returns `{ success, fund_movement_id }`.
- `calibrationData(Request, PlaidItem)` — `GET /plaid/items/{plaidItem}/calibrate`; owner-only; `PlaidCalibrationService::buildCalibrationMatches` with ledger rows serialized to `{id, date, amount, description, type, fund_id, category}`.
- `applyCalibration(ApplyPlaidCalibrationRequest, PlaidItem)` — `POST /plaid/items/{plaidItem}/calibrate`; `applyCalibrationResults`; JSON counts `{ confirmed_linked, imported_pending }`.
- `syncAllMonths(Request)` — `POST /plaid/sync-month`; runs current-month sync for each auth user `PlaidItem`; aggregates `{ items_synced, pending_created, auto_created, failed_items }`; `502` when the user has linked items but every item failed.
- `syncAllLastMonth(Request)` — `POST /plaid/sync-last-month`; same as `syncAllMonths` for the previous calendar month.
- `syncMonth(Request, PlaidItem)` — `POST /plaid/items/{plaidItem}/sync-month`; current month `fetchByDateRange` + `ingestPlaidRowsAsPending`; JSON `{ pending_created, auto_created }` or `502` on Plaid errors.
- `syncLastMonth(Request, PlaidItem)` — `POST /plaid/items/{plaidItem}/sync-last-month`; previous calendar month; same behavior as `syncMonth`.

### PlaidWebhookController
- `__invoke(Request)` — `POST /plaid/webhook` (CSRF-excluded); on `webhook_type=TRANSACTIONS`, loads `PlaidItem` by `item_id` and runs `PlaidTransactionSyncService::syncItem` to advance the sync cursor and process `added` / `modified` / `removed` into pending imports (and optional auto-ledger creates)

### MonthCloseoutController
- `status(Request)` — `POST /closeout/status`; accepts `{year, month}`; returns `{soft_closes, hard_close, all_soft_closed, family_user_count}` via `MonthCloseoutService::getMonthStatus`
- `softClose(Request)` — `POST /closeout/soft-close`; creates a `MonthSoftClose` record; auto-triggers `hardClose` for single-member families; returns `{message, data, hard_close?, auto_hard_closed?}`
- `undoSoftClose(Request)` — `POST /closeout/undo-soft-close`; removes the user's soft-close record (only if no hard close exists)
- `hardClose(Request)` — `POST /closeout/hard-close`; requires `can:manage_family`; runs `MonthCloseoutService::hardClose` (current `closeout_mode` engine, fund advances, consolidates split debts, applies monthly debt interest through the closed month-end date, creates `MonthHardClose` with settings/results snapshots)
- `undoHardClose(Request)` — `POST /closeout/undo-hard-close`; requires `can_manage_family`; runs `MonthCloseoutService::undoHardClose` and returns `422` for closeout-state validation errors (e.g., no hard close for month)
- `closedMonths(Request)` — `GET /closeout/closed-months`; returns array of `{year, month, closeout_mode}` hard-closed months for the auth user's family (`closeout_mode` is the mode stored at hard close; null/blank is treated as classic)

### MonthSummaryController
- `show(Request)` — `GET /month-summary?year=&month=`; read-only overview for a specific month; requires family membership; returns `{year, month, is_hard_closed, close_status, category_totals, category_transactions, member_balances, rule_preview, closeout_preview, fund_advance_transactions, fund_movements, debt_repayments, title_savings}` plus **`family_category_totals`** / **`family_category_transactions`** when `users.view_family_expenses` is true (household category overlay; split expenses counted once at full amount; **inter-member debt payments** — one family member paying another, `debt.creditor_id` set — are omitted from family expense totals; viewer `category_totals` still include them; does not change member_balances / rule_preview / fund_movements). When that preference is on, **`debt_repayments.family_debt_paid`** is also added (per family-shared debt: `you_amount` vs `family_amount`). **`closeout_preview`**: `{mode, source: live|snapshot|reconstructed, family}` — open months dry-run the current family mode; hard-closed months read **`results_snapshot`** (or reconstruct amounts from ledger artifacts when the snapshot is missing).
  - `category_totals`: **authenticated user only** — income rows with **`user_id` = viewer** (**non–debt-payment**, **`is_borrow` false** — fund borrows align with **`rule_preview.basis.gross_income`** and appear under **Fund In/Out**; also excludes reimbursement income via `whereNotRepaymentIncome`), non-split viewer expenses **excluding** `is_debt_payment` and **`is_closeout_initiated`** from the main expense loop (closeout ledger expenses match **`rule_preview`/closeout basis exclusions**; see Fund In/Out / debt repayment UI for those movements), plus **split expense** **`transaction_splits.amount`** rows for the viewer (excluding split lines on debt-payment parents). Reimbursed expenses are excluded via `whereNotRepaidExpense` (flag **or** repayment link). **Debt-payment expenses** are merged afterward (also skip reimbursed rows): **with** `category_id` they add to that category’s expense total; **without** `category_id` (solo or split parent uncategorized) they aggregate to synthetic **Uncategorized Debt Payments** (`category_id = -1`); sorted expenses first then by total descending
  - `member_balances`: split-expense net IOUs dated in that month (**`is_split`, `type=expense`**, includes split debt repayments, excludes **`is_closeout_initiated`**), direction (`they_owe_you` / `you_owe_them`); only non-zero nets are returned. Each row also includes source breakdown by transaction creator: `from_you_created_amount`, `from_them_created_amount`, and two history arrays (`from_you_created_transactions`, `from_them_created_transactions`) with per-transaction `transaction_id`, `transaction_date`, `category_name`, `category_icon`, `description`, `total_amount`, and `balance_amount`.
  - `fund_advance_transactions`: map of fund id → viewer expense rows with `advance_fund_id` in that month (`getFundAdvanceTransactions`; same scope as **`MonthCloseoutService::fundAdvanceOutstandingByFundForUserMonth`**)
  - `rule_preview`: viewer slice of the closeout computation (live dry-run for open months; **`results_snapshot`** or artifact reconstruction when hard-closed). Includes `basis` (gross income, total expenses, **`expense_basis_exclusions`** / aliased **`non_necessity_expenses`**, **`gross_allocations_total`**, **`remaining_after_expenses`**). Classic `total_expenses` stays aligned with **`MonthCloseoutService::expenseTotalTowardRemainingBasis`** (remaining-after-expenses basis; omits `exclude_from_expense_basis=true` advances), while `expense_basis_exclusions` reports month sums of those remaining-exclusion advances. Family-pooled leftover `rule_preview.basis` includes remaining-exclusion advances in `total_expenses` and reports `expense_basis_exclusions` as **0**. Family-pooled **`closeout_preview.family.basis`** uses **`is_necessity`** for charity (`necessary_expenses` / `non_necessity_expenses`). **Gross-base rules** (`allocation_base != 'remaining'`) **stop once the running gross pool hits zero or below**, matching **`ClassicCloseoutEngine`** (avoids percentage-of-gross rules continuing to show positive amounts after the pool is gone); **later gross rules still appear in `rules` with `projected_amount` 0** for stable ordering (skipped rules due to depleted gross pool; **not** debt-balance skips). **`destination_type=debt`**: **`projected_amount`** carries the **nominal** allocation from the rule (before debt balance cap); **`net_after_advances`** carries the **capped** payoff (preview simulates running debt balances across gross then remaining rules, matching **`CloseoutAllocationWriter::allocateToDebt`**); **`gross_allocations_total`** uses capped payoffs toward the remaining pool. **`remaining_after_expenses`** is **not clamped at zero**—it matches `gross_income - gross_allocations_total - total_expenses` (same pool used internally with `max(0, …)` only for applying remaining-base rules). **`expense_closeout_basis.lines`** summarizes what counts toward **`basis.total_expenses`** (same definition as **`MonthCloseoutService::expenseTotalTowardRemainingBasis`**: solo non–closeout-initiated, non-borrow, non–remaining-exclusion expenses including tracked debt repayments, plus split shares on transactions with the same filters). **Gross omits `is_debt_payment` income** (creditor repayment lines). Each rule row includes **`destination_id`**, **`fund_advance_outstanding_before`** / **`net_after_advances`**: **fund** rules use **`net_after_advances` = capped allocation − month advances tagged to that fund** (consumption in rule order—**may be negative**); **debt** rules use nominal **`projected_amount`** with **`net_after_advances` = capped paydown** (`0` advances); **title** rules echo **`projected_amount`** in **`net_after_advances`** with zero advance columns
  - `debt_repayments`: `{ paid: [...], received: [...] }` viewer-scoped `is_debt_payment` rows that month (`counterparty_label`, amounts, descriptions, **`is_family_debt`**); **`paid`** includes payer-side repayments where the viewer is **`transaction.user_id`** or appears in **`transaction_splits`**, and **`paid[].amount`** uses the viewer's **`TransactionSplit`** share when `is_split` (otherwise full expense **`amount`** for solo repayments owned by them); **`received`** remains creditor mirror incomes at full **`transactions.amount`**. When **`view_family_expenses`** is on, also **`family_debt_paid`**: one row per `is_family_debt` debt with payments that month (`you_amount` = viewer share, `family_amount` = household total counted once per payment)
  - `title_savings`: auth-user `CloseoutTitleSaving` rows for the selected month, returned only when `is_hard_closed=true`; each row includes completion state (`is_completed`, `completed_at`)

### FamilyCloseoutSettingsController
- `show()` — `GET /family/closeout-settings`; family members; JSON `{closeout_mode, can_manage, family_rules}`
- `update(UpdateFamilyCloseoutSettingsRequest)` — `PUT /family/closeout-settings`; requires `can_manage_family` **and** `family_id`; body `{closeout_mode: classic|family_pooled}`; affects **open months only** (already hard-closed months keep their snapshot)

### FamilyCloseoutRuleController
- `index()` — `GET /family/closeout-rules`; family members; ordered `FamilyCloseoutRule` rows
- `store(StoreFamilyCloseoutRuleRequest)` / `update(UpdateFamilyCloseoutRuleRequest)` / `destroy(DestroyFamilyCloseoutRuleRequest)` — `POST`/`PUT`/`DELETE /family/closeout-rules`; requires `can_manage_family`; route binding scopes `{familyCloseoutRule}` to the auth user’s family (other families 404); stages `surplus` | `remaining_after_charity`

## Services

### ClosedMonthGuard (`app/Services/ClosedMonthGuard.php`)
- Shared guard for transaction-producing write paths. A month is locked when the family has a `MonthHardClose` for that year/month, or when the **initiating** user has a `MonthSoftClose` (transaction owner on create/update/delete; payer on debt payment; acting user on fund borrow/repay).
- Soft close means that user is done entering their **own** transactions. It does **not** block other open family members from including them on splits or from recording debt payments that create a mirrored row for them. Hard close still locks the whole family month.
- Throws `InvalidArgumentException`; controllers return `422` JSON with the guard message.

### PlaidClient (`app/Services/PlaidClient.php`)
- Registers as a singleton (`AppServiceProvider`) built from `config/plaid.php`; POSTs JSON to Plaid with `Plaid-Version` header (`config('plaid.api_version')`, default `2020-09-14` — must be a released date from Plaid's versioning docs, not an arbitrary ISO date) and injects `client_id` / `secret` into each body.

### PlaidTransactionSyncService (`app/Services/PlaidTransactionSyncService.php`)
- Constructor: `PlaidClient`, `PlaidMatchingService`, `TransactionService`.
- `hydrateInstitution(PlaidItem)` — `/item/get` plus `/institutions/get_by_id` for display name.
- `isPlaidTransactionPending(row)` — `true` only when Plaid `pending` is boolean `true`; used by ingest and calibration.
- `syncItem(PlaidItem)` — loops `/transactions/sync` using stored cursor; persists `transactions_cursor`; then `processSyncedTransactions` for the accumulated `added` / `modified` / `removed` arrays. Returns aggregated `counts`, `added`, `modified`, `removed`, and deduped `accounts` (raw Plaid shapes).
- `processSyncedTransactions(PlaidItem, added, modified, removed)` — **Added** (`processAddedRow`): skip when Plaid `pending` is `true` (unsettled authorizations wait until they post; missing `pending` is treated as posted). Then skip if `plaid_pending_imports` already holds this **exact** `plaid_transaction_id` (any status) or the family already has a `transactions` row with that same Plaid id. Does **not** rewrite ids via Plaid’s `pending_transaction_id`. Then `getSuggestion`; ledger match + auto-link (see **Ledger match auto-linking** below); if the matching `PlaidMerchantRule` has `action=dismiss`, insert `status=dismissed` + `dismiss_source='auto'`, `recordSeen`, return; otherwise create `PlaidPendingImport` (`status=pending` initially); merchant-rule **auto-create** when eligible and no ledger match; `recordSeen` on rule when present. **Modified:** skip still-pending payloads that were never ingested; if a posted `modified` row has no import/ledger yet (same id after skip-pending), run `processAddedRow`. Still-`pending` leftover imports get `amount`/`date`/`raw_payload` refresh (and linked ledger amount/date when `transaction_id` is set); family ledger rows with the same `plaid_transaction_id` also get amount/date. **Removed:** deletes still-`pending` `plaid_pending_imports` only — confirmed/auto-created/dismissed rows are left in place. **Pending→posted new id:** skipping `pending: true` avoids queuing the authorization; the posted `added` row is the first ingest. Leftover imports confirmed **before** this skip can still duplicate (see `docs/ai/09-known-decisions.md`).

#### Ledger match auto-linking

During `processAddedRow`, `PlaidMatchingService::findLedgerMatchWithScore` is called for every new Plaid transaction. Results:

- **Score ≥ 0.85** → status `auto_linked`; existing ledger row gets `plaid_transaction_id`, `import_source`, and `plaid_pending_import_id` set.
- **Score 0.3–0.84** → status `pending` + `suggested_ledger_match_id` / `ledger_match_score` stored; **Possible match** banner shown in Review tab (`PlaidImportReview.vue`).
- **No match** (below 0.3) → existing behavior (pending, or `auto_created` via merchant rule when eligible).

When any ledger match exists, `findRepaymentGroupMatch` is skipped and merchant-rule auto-create does not run.

Auto-linked items appear in the Auto tab (same queue as `auto_created`; `GET /plaid/pending-imports` uses `whereIn` on both statuses). New endpoints: `POST …/approve-auto-linked` (reinforce rule, `reviewed_at`), `POST …/reject-auto-linked` (clear Plaid fields on ledger, reset pending to Review).

#### Sweep match (savings_sweep reconciliation)

When **no** ledger match is found (`findLedgerMatchWithScore` returns null), `processAddedRow` calls `PlaidMatchingService::findSweepMatchWithScore` on the new `PlaidPendingImport` and stores `suggested_sweep_match_id` / `sweep_match_score` when an unlinked family-scoped `savings_sweep` movement matches (±$0.01 amount, ±7 days on `created_at`; family via `funds.family_id` or movement `user_id` in the family). Sweep matches are **never** auto-linked — the user confirms in **Import Review** via `GET …/sweep-candidates` and `POST …/link-to-sweep`, which sets pending `status=confirmed` and links `FundMovement.plaid_pending_import_id` / `plaid_transaction_id`. `ingestPlaidRowsAsPending` uses the same `processAddedRow` path.

- `fetchByDateRange(PlaidItem, startDate, endDate)` — paginated `POST /transactions/get` (`options.count` 500 + `offset`) until `total_transactions` is satisfied; returns merged `transactions` rows (calibration).
- `ingestPlaidRowsAsPending(PlaidItem, rows)` — for each Plaid row array, reuses the same skip + `processAddedRow` path as sync **added** (including skip `pending: true`); returns `{ pending_created, auto_created }` (counts by resulting `PlaidPendingImport.status`).

### PlaidDailySyncCommand (`app/Console/Commands/PlaidDailySyncCommand.php`)

- Signature: `plaid:daily-sync {--item=}` — when `--item` is set, syncs that `PlaidItem` id only; otherwise all rows. Each item: `PlaidTransactionSyncService::syncItem` (cursor + `processSyncedTransactions`); failures are `report`ed and the loop continues. Stdout line: `Synced {institution}: {n} added, {auto_created} auto-created, {pending} queued for review` where `added` is Plaid’s added count for the pull and the other two counts come from `PlaidPendingImport` rows for this `plaid_item_id` among the returned `added` transaction ids (`pending` vs `auto_created`). **Scheduler:** the daily 02:00 registration in `routes/console.php` is currently **commented out** until production bank accounts are finalized; run the command manually or uncomment + restore `Schedule` import when ready.
- Signature: `plaid:sync-merchant-rules-from-categories {--user=}` — copies current expense-category defaults onto merchant rules (optional single user; otherwise every user with `family_id`).

### PlaidMatchingService (`app/Services/PlaidMatchingService.php`)
- `findRepaymentGroupMatch(plaidRow, userId)` — for Plaid **expense** rows (positive amount), finds unlinked `is_repayment_mirror` expenses for that user within ±7 days whose per-`repayment_transaction_id` group sums to the Plaid amount (±$0.01); returns repayment id, mirror collection, and total.
- `findLedgerMatch(plaidRow, familyId)` — same as `findLedgerMatchWithScore` but returns only the `Transaction` or null.
- `findLedgerMatchWithScore(plaidRow, familyId)` — same matching rules and **≥ 0.3** threshold; returns `['transaction' => Transaction, 'score' => float]` or null (used by calibration and sync auto-link). Candidate ledger rows: same `family_id`, **`plaid_transaction_id` null** (already-linked bank rows are skipped — so a posted Plaid id cannot auto-link to the ledger row created from the earlier pending id), expected type from Plaid amount sign, `transaction_date` within ±1 day of the Plaid date, and `amount` within **±0.01** of the normalized ledger amount (`whereBetween`, equivalent to the prior `ABS(amount - x) < 0.01` intent; avoids SQLite/Laravel binding quirks with two adjacent `?` placeholders in `whereRaw`).
- `findSweepMatchWithScore(PlaidPendingImport)` — finds the first unlinked `savings_sweep` `FundMovement` for the import owner’s family (`funds.family_id` = user `family_id` **or** `user_id` in that family) with amount within ±$0.01 of `abs(import.amount)` and `created_at` within ±7 days of the import date; returns `['fund_movement' => FundMovement, 'score' => float]` or null. Score: base **0.80**, +**0.15** when amounts match to the cent, +**0.05** (same day) or +**0.02** (1–2 days) for date proximity, capped at **1.0**. Used during sync to populate sweep suggestions (advisory only; no auto-link).
- `normalizeMerchantKey` — delegates to `PlaidMerchantRule::normalizeKey`.
- `getSuggestion(plaidRow, userId)` — loads `PlaidMerchantRule` by normalized merchant; returns `category_id`, `type`, fund fields, `exclude_from_expense_basis`, `is_necessity` (family `categories.is_necessity_default` when the rule has an expense category, else the rule flag), `confidence_score` (`PlaidMerchantRule::confidenceScore`), `is_auto_eligible` (`false` when `action=dismiss`, otherwise `isAutoCreateEligible`), plus new learned fields `description`, `is_debt_payment`, `debt_id`, `split_data`; without a rule, returns nulls / false with `type` from Plaid sign (`>= 0` → expense, negative → income).
- `recordConfirmation` / `recordSeen` — increment merchant-rule counters (`confirmation_count` + `total_seen_count`, or `total_seen_count` only).
- `learnFromConfirmation` — `firstOrNew` by `user_id` + normalized key, merges whitelisted settings (`category_id`, `type`, `fund_id`, `advance_fund_id`, `exclude_from_expense_basis`, `is_necessity`, `is_split`, `action`, `description`, `is_debt_payment`, `debt_id`, `split_data`), defaults `action` to `categorize` when not supplied, then increments both counters and saves.
- `learnDismissRule` — normalizes merchant key, `firstOrNew` by `user_id` + `merchant_key`, sets `action=dismiss`, increments `total_seen_count` only, saves, returns the rule.
- `deleteDismissRule(userId, merchantLabel)` — normalizes merchant key; hard-deletes the rule for that user/key only when `action=dismiss` (used by undo manual dismiss).
- `findLedgerLinkCandidatesForPendingImport(PlaidPendingImport, familyId, dayRadius?, limit?)` — wider ±`dayRadius` date window (default 45) than calibration auto-match; same amount (±0.01) and `suggested_type`; **restricts to `transactions.user_id` = pending import’s `user_id`** (same Plaid-linked account owner), so other family members’ manual rows are not suggested; returns scored ledger rows for import-review linking UI.
- `canLinkPendingImportToLedger(PlaidPendingImport, Transaction, maxDateDriftDays?)` — validates unlinked ledger row, **`transactions.user_id` matches the pending import owner**, type/amount alignment, date within default **60** days of the pending row’s date.

### PlaidMerchantRuleCategorySync (`app/Services/PlaidMerchantRuleCategorySync.php`)
- `syncFamilyCategory(Category, User)` — copies family `categories.is_necessity_default` onto **every family member's** merchant rules / open pending suggestions / unreviewed auto-created rows for that category. Personal advance / remaining-exclusion are applied only for the saving user. `syncUserCategory(User, Category)` / `syncUser(User)` still copy that user's personal defaults plus family necessity (used by **Apply defaults to bank learning** and the artisan command). Does **not** change category, split, dismiss action, or confirmation counts.

### PlaidCalibrationService (`app/Services/PlaidCalibrationService.php`)
- Constructor: `PlaidTransactionSyncService`, `PlaidMatchingService`, `TransactionService`.
- `buildCalibrationMatches(PlaidItem)` — Uses `Carbon::now()` to compute **start** = first day of the calendar month **two months before** the current month, **end** = last day of the **previous** calendar month; calls `fetchByDateRange`; **skips Plaid rows with `pending: true`**; loads family `Transaction` rows in that inclusive date range with `plaid_transaction_id` null; for each remaining Plaid row runs `findLedgerMatchWithScore` to populate `matched` (`plaid`, `ledger`, `score`) or `unmatched_plaid` (`plaid`, `suggestion` from `getSuggestion`). `unmatched_ledger` lists in-window ledger rows not paired to any Plaid row. Users without `family_id` get posted Plaid rows in `unmatched_plaid` and empty matched / unmatched_ledger.
- `applyCalibrationResults(PlaidItem, confirmedPairs, importAsNew)` — DB transaction; loads a Plaid id → row map from `fetchByDateRange` over the calibration window. **Structured pairs** (`plaid_transaction_id`, `ledger_transaction_id`, `category_id`, `type`, optional funds / `exclude_from_expense_basis` / `is_necessity`): skipped when the mapped Plaid row is still pending; otherwise `TransactionService::updateTransaction` on the ledger row, optional `fund_id` `forceFill`, `learnFromConfirmation`, then `plaid_transaction_id` + `import_source=plaid`. **Legacy pairs** `['plaid' => array, 'ledger' => Transaction|int]`: skipped when `pending: true`; otherwise `learnFromConfirmation` from Plaid merchant + ledger-mirrored settings, then link Plaid id on the transaction. **`import_as_new`**: each string id (resolved from the window map) or full row array runs `createPendingImportFromPlaidRow` when not already imported (**pending Plaid rows are not imported**). Returns `{ confirmed_linked: int, imported_pending: int }`.

### TransactionRepaymentService (`app/Services/TransactionRepaymentService.php`)
- `createRepaymentLinks(Transaction $repaymentIncome, array $links, User $repaidUser): void` — marks income `is_repayment`; for each `{transaction_id, amount}` validates repaid expense (same family, `type=expense`, not already `is_repaid`), marks expense `is_repaid`, creates mirror expense for `$repaidUser` (`is_repayment_mirror`), inserts `TransactionRepaymentLink` (all in `DB::transaction`).
- `createExternalRepaymentLinks(Transaction $repaymentIncome, array $links): void` — external (non-family) variant: marks income `is_repayment` and each repaid expense `is_repaid`; inserts `TransactionRepaymentLink` with `is_external_repayment=true`, `mirror_transaction_id=null`, `repaid_user_id` = income owner. No mirror expense is created.
- `deleteRepaymentLinks(Transaction $repaymentIncome): void` — clears `is_repaid` on linked expenses, resets any `PlaidPendingImport` rows linked to mirror transactions (`status` → `pending`, `transaction_id` → null), hard-deletes mirror expenses, deletes link rows, clears income `is_repayment`.
- `handleRepaymentForTransaction(Transaction, array $validatedData): void` — when neither `is_repayment_mode` nor `is_external_repayment_mode` is true, calls `deleteRepaymentLinks` only; when `is_external_repayment_mode`, replaces links via delete + `createExternalRepaymentLinks`; when `is_repayment_mode`, validates `repayment_for_user_id` (same family), replaces links via delete + `createRepaymentLinks`. Called from `TransactionController::store` / `update`, `PlaidImportController::confirm` / `confirmSplit` (per line); `deleteRepaymentLinks` runs before `TransactionController::destroy`.

### TransactionService (`app/Services/TransactionService.php`)
- `createTransaction(array, User): Transaction` — wraps everything in `DB::transaction`; for `type=income`, forces `is_split=false`, clears `split_data` and `advance_fund_id`, and optionally links debt via `income_debt_mode`:
  - `none`: regular income
  - `existing`: increments selected debt `balance` only, appends `income_additions` (patched with `transaction_id` after create/update), links `transactions.debt_id`; rollback removes matching `income_additions` entry (balance only); pre-fix rows without `income_additions` still decrement `amount` + `balance` on rollback
  - `receipt`: links `transactions.debt_id` with `is_loan_receipt=true`; does not modify debt `amount` or `balance`; rollback is a no-op for debt fields
  - `new`: creates a new debt from the same amount (external or interfamily) and links `transactions.debt_id`; supports optional new-debt settings (`income_new_interest_enabled`, `income_new_interest_rate`) and sets `loan_received_date` from the income transaction date
  For **expense + `debt_id`**, or **expense + `transfer_to_user_id`** (create only; resolved to a payable in-family `debt_id` via `DebtService::findOrCreatePayableInterFamilyDebt`), runs `createDebtRepaymentExpense()` (categorized payer expense, mirrored creditor income when applicable, balance update with in-family overpayment via `DebtService::applyInterFamilyPairNet`, `mirror_transaction_id` linkage); split debt-payment expenses are supported and create `transaction_splits` plus pending split debts for non-payer participants; does **not** call `FundService::processIncome`. **Income + `is_debt_repayment_received`** uses `createDebtRepaymentReceivedIncome()` with the same overpayment path. External debts still cap payment at remaining balance. Non-debt create/update paths persist `exclude_from_expense_basis` only for **classic** families (family pooled creates store `false`; updates keep an existing qualifying flag so switching back to classic can use it). `is_necessity` is persisted on every expense (default true). Benefit expenses use `createDebtPaymentBenefit` / `updateDebtPaymentBenefit` / `deleteDebtPaymentBenefit` (synced/cascaded from debt-payment update/delete).
- `updateTransaction(Transaction, array): Transaction` — supports ordinary transactions and debt-payment **expense** rows. Debt-payment updates rebalance debt amounts (restore old payment, apply new payment), update/create/remove mirrored creditor income rows as needed, and recreate split + pending split-debt rows when repayment splits are edited. **`transfer_to_user_id` is create-only** (ignored on update; edit an existing family-transfer row with `debt_id`). Debt-payment **income** rows remain non-editable directly.
- `deleteTransaction(Transaction): void` — used by `TransactionController::destroy`; reverses mirrored debt-payment pairs (+ debt balance increment) or deletes splits/linked debts for normal rows

### FundService (`app/Services/FundService.php`)
- `processIncome(Transaction, User): void` — loads active `FundRule`s ordered by `order`; iterates rules; calculates allocation amount from `gross`, `net`, or `remaining` base; increments fund balance + creates `FundMovement` — **not called** from `TransactionService` in the current app (reserved / legacy path)
- `borrowFromFund(Fund, float, string, User): Transaction` — validates balance; decrements fund, creates `is_borrow=true` income transaction, creates `FundMovement` (type=`borrow`), creates `Debt` (creditor_id=null, fund_id set)
- `repayFund(Debt, float, User): void` — validates fund association, debtor match, amount; increments fund balance, creates `FundMovement` (type=`repayment`), creates expense transaction with `is_debt_payment=true`, decrements debt balance
- `sweepToSavings(Fund, float, string, User): FundMovement` — validates amount ≤ fund balance; decrements fund, creates `FundMovement` (type=`savings_sweep`, optional `description`); **no** `Transaction`; does not affect closeout math
- `overrideBalance(Fund, float, string, User): FundMovement` — sets `fund.balance` to the given value; writes `FundMovement` (`type=manual_override`, signed delta, description `Set to $X.XX` plus optional note); **no** `Transaction`; excluded from Month Summary Fund In/Out; `422` when the value is unchanged

### DebtService (`app/Services/DebtService.php`)
- `findOrCreatePayableInterFamilyDebt(User $payer, User $recipient, float $amount, ?string $description = null): Debt` — used by expense **`transfer_to_user_id`**. Finds an open personal in-family running debt (`debtor` = payer, `creditor` = recipient, `balance > 0`, not pending, not family-shared, `transaction_id` null). Does **not** reuse `$0` debts. If none, creates a debt for `$amount` so the existing debt-payment pair can run.
- `applyInterFamilyPairNet(int $familyId, int $debtorId, int $creditorId, float $amount, ?string $description = null, ?array $closeoutContribution = null, ?int $reversedFromDebtId = null, ?string $occurredOn = null): void` — applies a net “debtor owes creditor” amount against confirmed running in-family debts for that pair: reduces opposite-direction balances first, then increases/creates same-direction debt. Optional closeout contribution metadata writes undoable `contributions` entries (negative when reducing opposite debts). When `$reversedFromDebtId` is set (in-family overpayment), a **new** reverse debt stores `reversed_from_debt_id`; merging into an **existing** reverse debt appends `direction_reversals` on both the source and target (dated with `$occurredOn`).
- `payDebt(Debt, float, string, User, bool $isCloseoutInitiated = false, ?string $paymentDate = null, ?int $splitWithUserId = null, ?float $splitPercentage = null): void` — validates and records a debt payment:
  - For **family debts** (`is_family_debt=true`): payer must be a family member
  - For **personal debts**: payer must be the debtor
  - Rejects payment above remaining balance only for **external** debts (`creditor_id` null)
  - Uses `paymentDate` when provided, otherwise defaults transaction date to today
  - Creates expense transaction for payer; when `splitWithUserId` / `splitPercentage` are provided, splits that expense and creates a pending `Debt` for the co-payer's share
  - Creates income transaction for creditor if `creditor_id` is not null; sets `mirror_transaction_id` linking the expense ↔ income pair (including split debt payments)
  - **In-family overpayment:** zeros the paid debt and applies the excess via `applyInterFamilyPairNet` (swapped direction; merges into an existing reverse debt when present; passes the paid debt id so history can follow reversal lineage); otherwise decrements `debt.balance`
  - Rejects `is_pending_closeout=true` debts with `InvalidArgumentException`

### SplitCalculator (`app/Services/SplitCalculator.php`)
- `validate(array): bool` — checks `share_percentage` sum ≈ 100 (epsilon 0.01)
- `allocate(float, array): array` — distributes amount proportionally; last split absorbs rounding remainder
- `sumAmounts(array): float` — utility to verify allocation totals
- `equalShareSplitData(array $userIds): array` — equal **percentage** rows for `split_data` payloads (rounding matches the Vue equal-split helper); used by Plaid auto-create when a category default requests split
- `distributeEqually(array $userIds, float): array` — equal split utility (used internally; not currently called from controllers)

### MonthCloseoutService (`app/Services/MonthCloseoutService.php`)
- `expenseTotalTowardRemainingBasis(User, int, int): float` — delegates to `CloseoutTotals`; sums the viewer’s month expenses used for **remaining-after-expenses** math during hard close and for **`GET /month-summary` `rule_preview.basis.total_expenses`** on live classic previews: solo `expense` rows (`is_split=false`, `is_closeout_initiated=false`, `is_borrow=false`, `exclude_from_expense_basis=false` in **classic**, `is_repaid=false`, same `family_id`) **including** tracked debt repayments, plus **`transaction_splits`** for that user on `expense` parents with the same closeout/borrow/repaid filters (split shares on debt repayments included). Remaining-exclusion advances are omitted from this basis in **classic** only and are deducted from fund balances via `applyFundAdvances()`. Family pooled leftover preview calls the same total with remaining-exclusion **off** (`expense_basis_exclusions` is 0). Family-pooled charity uses **`is_necessity`**, not this remaining-exclusion flag. Closeout **gross income** excludes `is_repayment` income (with `is_borrow` / `is_debt_payment`).
- `fundAdvanceOutstandingByFundForUserMonth(User, int, int): array` — map of **`advance_fund_id` → SUM(amount)** for the user’s advance-tagged expenses in that calendar month (used for rule-preview netting and remaining-pool math)
- `hardClose(Family, User, int, int): MonthHardClose` — `CloseoutEngineResolver` picks `ClassicCloseoutEngine` or `FamilyPooledCloseoutEngine` from `families.closeout_mode`; preview is stored as `results_snapshot`; engine `apply` writes allocations; then per-user `applyFundAdvances`, `consolidatePendingSplitDebts`, `applyMonthlyDebtInterest`; persists `closeout_mode` + `settings_snapshot` + `results_snapshot` on `month_hard_closes`
- Classic remaining-pool math (in `ClassicCloseoutEngine`) still excludes `is_closeout_initiated=true` expenses from the basis so closeout-generated movement rows do not recursively affect the same closeout run. When building the **remaining** pool after gross rules, **gross-base fund** allocations count only **`max(0, allocated − advance outstanding to that fund before the rule in rule order)`** so advance expenses already in the expense total are not double-subtracted (nominal fund allocations from rules are unchanged; only the remaining-phase input is adjusted)
- Remaining-base percentage rules use a shared post-expense basis for the phase (not cascading percentage-on-percentage reduction); fixed remaining rules still consume the available remaining pool in order
- `CloseoutAllocationWriter` creates both a `FundMovement` (`closeout_allocation`, with `transaction_id` set) and a closeout-tagged expense transaction (`Closeout transfer to fund: {fund name}`) for a dedicated Transactions group; family-pooled family-rule rows set `transactions.closeout_scope=family` and are attributed to the user who hard-closed; those ledger rows are excluded from Dashboard monthly expense totals, bank-balance deltas, month-summary category/expense basis, and Transactions period/day totals
- Debt destinations apply `closeout_expense_category_id` to closeout-created debt-payment expense rows
- Title destinations upsert **`CloseoutTitleSaving`** by `(family_id, user_id, year, month, title)`; **`rule_id` is set only when the row is first created** so a second title rule that shares the same title string still accumulates **`amount`** but does not overwrite **`rule_id`** (completion expenses use the first rule’s **`closeout_expense_category_id`**)
- `undoHardClose(Family, int, int): void` fully reverts hard-close artifacts inside one DB transaction (guarded by an existing `MonthHardClose` row): reverses closeout debt-payment impacts, reverses/deletes month-tagged closeout `FundMovement` rows (`closeout_allocation`, `advance_settlement`), deletes closeout-generated transactions, removes title savings and completion transactions, rolls back consolidated debt `contributions` for that month (deleting debts only when that month's contribution entries are marked `created_by_closeout_debt=true`), recreates pending split debts from month split transactions, removes month interest accrual entries from debts, then deletes month soft/hard close records (snapshots go with the hard-close row)

### Closeout engines (`app/Services/Closeout/`)
- `CloseoutEngine` — `preview(Family, year, month)` / `apply(Family, User, year, month)` used by both Month Summary and hard close
- `ClassicCloseoutEngine` — per-user `FundRule` math (gross then remaining); fund advances are **not** inside the engine (still `MonthCloseoutService::applyFundAdvances` after apply)
- `FamilyPooledCloseoutEngine` — family charity (surplus % of income − necessary expenses), remaining-after-charity (income − **all** expenses including non-necessity − surplus allocations), family remaining-% rules, inverse-split leftover weights, then each member’s **remaining-base** personal rules on `member_pool` (gross/net personal rules skipped). **`exclude_from_expense_basis` has no effect** on family, leftover, or personal leftover math.
- `CloseoutEngineResolver` — picks engine from `family.closeout_mode`; `settingsSnapshot()` captures `version` (1), mode, family rules, and each user’s personal rules (personal rules loaded in one query)
- `CloseoutMode` / `CloseoutScope` — allowed-value helpers for `classic`/`family_pooled` and `user`/`family` closeout attribution
- `CloseoutTotals` — earned income, necessity vs all expenses, split spend, inter-member debt-payment exclusion from **family** totals
- `CloseoutArtifactReconstructor` — pre-snapshot hard closes rebuilt from `fund_movements`, closeout debt payments, and title savings (amounts, not original percentages). `LegacyCloseoutDataBackfill` persists those reconstructions onto existing `month_hard_closes` and sets `closeout_scope=user` on legacy closeout-initiated transactions. Modes/scopes run in `2026_08_24_154237`; snapshot reconstruction runs later in `2026_09_03_202551` after `exclude_from_expense_basis` exists.

## Form Requests

Located in `app/Http/Requests/`. Several exist but not all are used uniformly:

| Request | Used by |
|---|---|
| `StoreTransactionRequest` | `TransactionController::store` + `update` |
| `StoreDebtPaymentBenefitRequest` | `TransactionController` benefit create/update |
| `StoreCategoryRequest` | `CategoryController::store` + `update` |
| `SyncPlaidMerchantRulesFromCategoriesRequest` | `CategoryController::syncPlaidMerchantRules` |
| `SweepFundRequest` | `FundController::sweep` |
| `OverrideFundRequest` | `FundController::overrideBalance` |
| `StoreFundRequest` | NOT used — `FundController` validates inline |
| `StoreFundRuleRequest` | NOT used — `FundController` validates inline |
| `UpdateFundRuleRequest` | NOT used — `FundController` validates inline |
| `PayDebtRequest` | `DebtController::payDebt` |
| `UpdateBankBalanceRequest` | `BankBalanceController::update` |
| `UpdateUserSettingsRequest` | `UserSettingsController::update` |
| `ExchangePlaidTokenRequest` | `PlaidController::exchange` |
| `StoreImportConfirmRequest` | `PlaidImportController::confirm` |
| `ConfirmSplitImportRequest` | `PlaidImportController::confirmSplit` |
| `LinkPlaidPendingImportRequest` | `PlaidImportController::linkToLedger` |
| `ApplyPlaidCalibrationRequest` | `PlaidImportController::applyCalibration` |
| `CorrectAutoCreatedImportRequest` | `PlaidImportController::correctAutoCreated` |
| `RestoreDismissedImportRequest` | `PlaidImportController::restoreFromDismiss` |
| `CreateFamilyRequest` | NOT used — `AdminController` validates inline |
| `CreateUserRequest` | NOT used — `AdminController` validates inline |
| `UpdateFamilyCloseoutSettingsRequest` | `FamilyCloseoutSettingsController::update` |
| `StoreFamilyCloseoutRuleRequest` | `FamilyCloseoutRuleController::store` |
| `UpdateFamilyCloseoutRuleRequest` | `FamilyCloseoutRuleController::update` |
| `DestroyFamilyCloseoutRuleRequest` | `FamilyCloseoutRuleController::destroy` |

`StoreTransactionRequest` additionally enforces `exclude_from_expense_basis` as a guarded boolean: it is normalized to `false` unless the request is a non-split expense with `advance_fund_id` and no `debt_id` / `transfer_to_user_id`; when `true` **and the family is classic**, it is only valid if the auth user has an active `FundRule` for that same fund with `destination_type='fund'`, `allocation_type='percentage'`, and `allocation_base='remaining'`. Family pooled skips that remaining-rule check (the flag is stored `false` on create and preserved on update). `is_necessity` is a free boolean on expenses (forced `true` for income).

`StoreCategoryRequest` additionally enforces `exclude_from_expense_basis_default` as a guarded boolean for the authenticated user’s per-category defaults: it is normalized to `false` unless the category is expense-type and has `advance_fund_id`; when `true` **and the family is classic**, it is only valid if the auth user has an active `FundRule` targeting that same fund with `destination_type='fund'`, `allocation_type='percentage'`, and `allocation_base='remaining'`. Family pooled skips that check and **preserves** an existing remaining-exclusion default on update. `is_necessity_default` is a free boolean for expense categories (default true).

## Policies

- `FundPolicy` — `view` / `update`: owner **or** same-family member when `funds.family_id` is set. `delete`: owner, **or** same-family + `can_manage_family` for family-scoped funds. Used by `FundController` via `$this->authorize()`.
- `DebtPolicy` — `view` checks same family and user is debtor or creditor; **not actively invoked by `DebtController`** (Needs verification)

Auto-discovery by Laravel maps `Fund` → `FundPolicy`, `Debt` → `DebtPolicy`.

## Fortify configuration

- `config/fortify.php` `home` → `/home` (but the app uses `/dashboard` — Needs verification if this causes redirect issues)
- 2FA columns exist in migrations (from Fortify scaffold); 2FA UI is not present in the Vue app
- Registration via `CreateNewUser` action; no email verification enforced

## Known backend gaps

1. `CategoryController` has no `CategoryPolicy`; `update`/`destroy` do check same `family_id`. Any member of that family can edit/delete any family category
2. `TransactionController::update` does not re-run fund allocation (income amount changes are not re-allocated)
3. `DebtPolicy` exists but `DebtController` does not call `$this->authorize()`
4. `net_income` allocation base currently behaves identically to `gross_income` (no separate net calculation)
5. `FundController::destroy` does not detach `transactions.advance_fund_id` (or other children) before delete — production MySQL 1451 when the fund is used as an advance fund
