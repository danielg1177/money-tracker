# 02 — Backend (Laravel)

## Entry points

- `bootstrap/app.php` — Laravel 13 bootstrap; registers `AppServiceProvider` and `FortifyServiceProvider`; CSRF exclusion for `plaid/webhook`
- `routes/web.php` — all routes (no `api.php`)
- `app/Providers/AppServiceProvider.php` — defines Gates: `admin`, `head_of_household`, `manage_family`
- `app/Providers/FortifyServiceProvider.php` — wires Fortify actions + rate limiters

## Models

### User (`app/Models/User.php`)
- Fields: `name`, `email`, `password`, `family_id` (nullable FK), `role`, `is_admin` (boolean), `bank_balance_enabled` (boolean), `bank_balance` (decimal nullable), `bank_balance_set_at` (date nullable)
- Role values (strings): `head_of_household`, `member` (admin is now a separate boolean)
- System admin: Boolean `is_admin` column; when true, grants admin permissions independent of family role
- Appended computed attributes (serialized in JSON): `is_admin`, `is_head_of_household`, `can_manage_family`
- Uses PHP 8 attribute annotations `#[Fillable]`, `#[Hidden]`
- Relations: `belongsTo(Family)`, `hasMany(Transaction)`, `hasMany(Fund)`, `hasMany(FundMovement)` as `fundMovements`, `hasMany(Debt, 'debtor_id')` as `debtsOwed`, `hasMany(Debt, 'creditor_id')` as `debtsOwedTo`, `hasMany(MonthSoftClose)` as `monthSoftCloses`, `hasMany(PlaidItem)` as `plaidItems`

### Family (`app/Models/Family.php`)
- Fields: `name`, `description`
- Relations: `hasMany(User)`, `hasMany(Category)`, `hasMany(Transaction)`, `hasMany(Debt)`

### Category (`app/Models/Category.php`)
- Fields: `family_id`, `name`, `icon`, `is_income` (bool), `is_expense` (bool), `is_split_default` (bool), `split_default` (JSON array)
- **Type constraint:** exactly one of `is_income` / `is_expense` must be true (not both, not neither); validated in `StoreCategoryRequest::withValidator`
- `split_default` is meaningful only when `is_expense` is true; `StoreCategoryRequest` clears split defaults when saving an income-only category
- Relations: `belongsTo(Family)`, `hasMany(Transaction)`, `hasMany(CategoryUserDefault)` as `userDefaults`

### CategoryUserDefault (`app/Models/CategoryUserDefault.php`)
- Fields: `category_id`, `user_id`, `advance_fund_id` (nullable), `is_non_necessity_default` (bool)
- Purpose: user-specific category defaults for expense transactions; one row per (`category_id`, `user_id`)
- Relations: `belongsTo(Category)`, `belongsTo(User)`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`

### Transaction (`app/Models/Transaction.php`)
- Fields: `family_id`, `user_id`, `category_id` (nullable), `type` (`income`|`expense`), `amount` (decimal:2), `description`, `transaction_date` (date), `is_split` (bool), `split_data` (JSON array), `fund_id` (nullable), `advance_fund_id` (nullable), `is_borrow` (bool), `is_debt_payment` (bool), `debt_id` (nullable FK → debts), `mirror_transaction_id` (nullable FK → transactions), `plaid_transaction_id` (nullable), `import_source` (nullable string, 32), `paid_by_user_id` (nullable FK → users), `is_closeout_initiated` (bool) — DB columns `plaid_transaction_id` / `import_source` are mass-assignable; unique per `family_id` when Plaid id set; `PlaidTransactionSyncService` may set them when auto-importing from Plaid
- `split_data` is a snapshot of split percentages stored on the transaction itself
- `debt_id` links a payment transaction to the debt it settles
- `paid_by_user_id` tracks which user initiated the payment (may differ from `user_id` for creditor income rows)
- `is_closeout_initiated` distinguishes manual rows (`false`) from backend-generated closeout movement rows (`true`) across debt payments, fund allocations, and title-completion expenses
- Relations: `belongsTo(Family)`, `belongsTo(User)`, `belongsTo(User, 'paid_by_user_id')` as `paidByUser`, `belongsTo(Category)`, `belongsTo(Fund)`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`, `belongsTo(Debt)` via `debt_id`, `belongsTo(Transaction, 'mirror_transaction_id')` as `mirrorTransaction`, `hasMany(TransactionSplit)` as `splits`, `hasMany(Debt)` as `debts` (split-linked debts), `hasOne(PlaidPendingImport, 'transaction_id')` as `plaidPendingImport`

### PlaidItem (`app/Models/PlaidItem.php`)
- Fields: `user_id`, `item_id` (Plaid), `access_token` (encrypted at rest), `institution_id`, `institution_name`, `transactions_cursor` (for `/transactions/sync`)
- Relations: `belongsTo(User)`, `hasMany(PlaidPendingImport)` as `pendingImports`; custom `resolveRouteBinding` restricts `{plaidItem}` routes to the authenticated owner

### PlaidPendingImport (`app/Models/PlaidPendingImport.php`)
- Staging row for a Plaid transaction before ledger confirm; table `plaid_pending_imports` (see migration `2026_05_11_210000_add_plaid_import_infrastructure.php`).
- `resolveRouteBinding` scopes `{pendingImport}` routes to `user_id` = authenticated user.
- Casts: `amount` decimal:2, `date` date, `raw_payload` array, `suggested_is_non_necessity` bool, `suggested_is_debt_payment` bool, `suggested_split_data` array, `confidence_score` decimal:4, `reviewed_at` datetime.
- `suggested_description` / `suggested_is_debt_payment` / `suggested_debt_id` / `suggested_split_data` — learned fields populated from `PlaidMerchantRule` at sync time; advisory (`suggested_debt_id` has no FK constraint).
- `dismiss_source` — nullable varchar(16); `'auto'` when dismissed by a merchant rule during sync, `'manual'` when dismissed by the user via the UI (dismiss / always-ignore). Null on non-dismissed rows.
- `reviewed_at` — nullable timestamp; set when a user reviews/audits an auto-dismissed entry so it stops appearing in the review queue.
- Relations: `belongsTo(User)`, `belongsTo(PlaidItem)` as `plaidItem`, `belongsTo(Transaction)` as `transaction`.
- `scopePending` — `status = pending`. `isAutoCreateEligible()` always `false` (merchant rules gate auto-create).

### PlaidMerchantRule (`app/Models/PlaidMerchantRule.php`)
- Per-user merchant-key defaults; table `plaid_merchant_rules`.
- Casts: `is_non_necessity`, `is_split`, `is_debt_payment` bool; `split_data` array; `confirmation_count`, `total_seen_count` integer. `confidenceScore()` is computed as `confirmation_count / total_seen_count` (or `0.0` when `total_seen_count` is 0); not a DB column.
- New learned fields (added 2026-05-15): `description` (string|null), `is_debt_payment` (bool), `debt_id` (unsignedBigInteger|null, no FK — advisory reference, survives debt deletion), `split_data` (json|null — `[{user_id, share_percentage}]`).
- `isAutoCreateEligible()` — `confirmation_count >= 3` and `confidenceScore() >= 0.80`.
- `normalizeKey(string)` — lowercase, strip non-alphanumeric except spaces, collapse whitespace, trim.
- Relations: `belongsTo(User)`, `belongsTo(Category)`, `belongsTo(Fund)` as `fund`, `belongsTo(Fund, 'advance_fund_id')` as `advanceFund`.

### TransactionSplit (`app/Models/TransactionSplit.php`)
- Fields: `transaction_id`, `user_id`, `share_percentage` (decimal:2), `amount` (decimal:2)
- Represents each user's computed dollar share of a split transaction
- Relations: `belongsTo(Transaction)`, `belongsTo(User)`

### Fund (`app/Models/Fund.php`)
- Fields: `user_id`, `name`, `description`, `balance` (decimal:2, starts at 0)
- Personal savings bucket; scoped to one user
- Relations: `belongsTo(User)`, `hasMany(FundRule)`, `hasMany(FundMovement)`, `hasMany(Debt)`

### FundRule (`app/Models/FundRule.php`)
- Fields: `user_id`, `fund_id`, `name`, `order` (int), `allocation_type` (`percentage`|`fixed`), `amount` (decimal:2), `allocation_base` (`gross_income`|`net_income`|`remaining`), `is_active` (bool), `destination_type` (`fund`|`debt`|`title`), `destination_id` (nullable), `destination_title` (nullable), `closeout_expense_category_id` (nullable expense-category FK)
- Rules are processed in `order` ASC during month hard-close processing; inactive rules are skipped
- `net_income` base is tracked but **not independently reduced** by deductions — it equals `gross` unless manually managed (Needs verification: whether net differs from gross in current implementation)
- Relations: `belongsTo(User)`, `belongsTo(Fund)`

### FundMovement (`app/Models/FundMovement.php`)
- Fields: `fund_id`, `user_id`, `type` (`allocation`|`borrow`|`repayment`|`initial_value`|`closeout_allocation`|`advance_settlement`|`savings_sweep`), `amount`, `transaction_id` (nullable), `description` (nullable)
- Audit ledger for every fund balance change
- Relations: `belongsTo(Fund)`, `belongsTo(User)`, `belongsTo(Transaction)`

| `type` | Balance effect | `Transaction` | Notes |
|---|---|---|---|
| `allocation` | Fund balance incremented | Yes (income) | Legacy/rule path; not used on income save today |
| `borrow` | Fund balance decremented | Yes (income, `is_borrow`) | Creates linked `Debt` |
| `repayment` | Fund balance incremented | Yes (expense, `is_debt_payment`) | Fund debt repayment |
| `initial_value` | Fund balance incremented | No | Starting balance at fund creation |
| `closeout_allocation` | Fund balance incremented | Yes (closeout expense) | Month hard-close rule payout |
| `advance_settlement` | Fund balance decremented | No | Month close settles `advance_fund_id` expenses |
| `savings_sweep` | Fund balance decremented | No | User sweeps fund balance to external savings account |

### Debt (`app/Models/Debt.php`)
- Fields: `family_id`, `debtor_id` (FK → users), `creditor_id` (nullable FK → users), `fund_id` (nullable FK → funds), `transaction_id` (nullable FK → transactions, `cascadeOnDelete` for split-linked rows), `amount` (original amount), `balance` (remaining), `description`, `is_family_debt` (bool), `is_pending_closeout` (bool — true during month hard-close split processing; pending debts are excluded from `GET /debts` and cannot be manually paid), `creditor_name` (nullable string for external creditors), `contributions` (JSON array nullable), `interest_enabled` (bool), `interest_rate` (APR decimal), `interest_last_applied_at` (date nullable), `loan_received_date` (date nullable), `interest_accruals` (JSON array nullable)
- `creditor_id` is null when the debt is to a fund (borrow scenario) or to an external party
- `creditor_name` stores plain text creditor names (e.g., "Bank of America") when `creditor_id` is null and `is_family_debt=false`
- `is_family_debt` controls visibility: false = personal debt (debtor + creditor only); true = visible to all family members
- `balance` decrements as payments are made; a debt with `balance = 0` is fully paid
- `contributions` records closeout contributions as `[{month, year, amount}]` tuples, used by the debt history modal to show "Closeout Additions" separate from manual payments
- Interest accrues only during month hard-close when `interest_enabled=true`, `interest_rate` is set, and `balance > 0`
- Interest accrual uses a daily-rate model (`APR / 365`) over the closed month window, reducing accrual after any in-month payment (`transactions.type='expense'`, `is_debt_payment=true`) and respecting `loan_received_date`
- Interest increases `balance` only (principal `amount` remains the original loan value) and appends a ledger entry to `interest_accruals`
- Relations: `belongsTo(Family)`, `belongsTo(User, 'debtor_id')`, `belongsTo(User, 'creditor_id')`, `belongsTo(Fund)`, `belongsTo(Transaction)`

### CloseoutTitleSaving (`app/Models/CloseoutTitleSaving.php`)
- Fields: `family_id`, `user_id`, `year`, `month`, `title`, `amount`, `rule_id`, `is_completed`, `completed_at`, `completion_transaction_id`
- Casts: `amount` decimal:2, `year` integer, `month` integer, `is_completed` bool, `completed_at` datetime
- Relations: `belongsTo(Family)`, `belongsTo(User)`

## Controllers

All controllers extend `app/Http/Controllers/Controller.php` (uses `AuthorizesRequests`).

### TransactionController
- `index(Request)` — returns viewer-scoped family transactions (`user_id` or `transaction_splits` participation), filtered by `start_date`/`end_date`, eager-loads `user`, `category`, `splits.user`, `debt` (+ nested relations), `advanceFund`, `plaidPendingImport.plaidItem` (null for non-Plaid rows; provides `institution_name`); excludes split debt-payment expenses for the creditor when they duplicate that creditor’s repayment income row
- `store(StoreTransactionRequest)` — validates closed-month status via `ClosedMonthGuard`, then delegates to `TransactionService::createTransaction`
- `update(StoreTransactionRequest, Transaction)` — checks ownership or same family, validates both the existing row month and target payload month via `ClosedMonthGuard`, then delegates to `TransactionService::updateTransaction`
- `destroy(Transaction)` — checks ownership or same family; validates closed-month status via `ClosedMonthGuard`; delegates `TransactionService::deleteTransaction()` (paired debt-payment cleanup + mirror rows)

### FundController
- `index()` — personal funds: `auth()->user()->funds()->whereNull('family_id')`; family funds: `Fund::where('family_id', $user->family_id)` when set; merged JSON with `scope` per row; each row also includes `has_non_necessity_rule` (true when the auth user has an active `destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'` rule targeting that fund id); `fundRules` and `movements.user` eager-loaded
- `store(Request)` — inline validation, creates fund for auth user
- `update(Request, Fund)` — authorizes via `FundPolicy`, inline validation
- `showRules()` — returns all `FundRule` rows for the auth user ordered by `order`; takes no parameters and performs no policy check; also mounted at `GET /funds/{fund}/rules` for backward compatibility (the `{fund}` parameter is ignored)
- `storeRule(Request)` — inline validation (+ duplicate check), creates `FundRule` for `auth()->id()`. For **`destination_type='title'`** rules that are **active**, **`destination_title`** must be **unique** among that user’s other **`destination_type='title'`** + **`is_active=true`** rows (avoids ambiguous **`CloseoutTitleSaving.rule_id`** when completing a title)
- `updateRule(FundRule, Request)` — `403` if `fundRule.user_id !== auth()->id()`; same validation as `storeRule`, ignoring the current rule when checking title uniqueness
- `destroy(Fund)` — authorizes via `FundPolicy`
- `borrow(Fund, Request)` — authorizes via `FundPolicy`, rejects if the current month is closed for the user via `ClosedMonthGuard`, then delegates to `FundService::borrowFromFund`
- `sweep(Fund, SweepFundRequest)` — authorizes via `FundPolicy`; **no** `ClosedMonthGuard`; delegates to `FundService::sweepToSavings`; returns `201` with the `FundMovement` (includes `user`)
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
- `payDebt(PayDebtRequest)` — validates closed-month status for the payer, optional split participant, and creditor via `ClosedMonthGuard`, then delegates to `DebtService::payDebt`; accepts optional `transaction_date` to backdate/explicitly date debt-payment transactions
- `paymentHistory(Debt)` — role-based filtering: creditors see **income** rows with their `user_id`; all others (debtor, family manager) see **expense** rows; includes optional `split_breakdown` per payment (`[{user_id, user_name, amount, share_percentage}]`) when the debt payment was split; appends a synthetic `initial_value` entry showing the debt's base principal (`debt.amount - sum(contributions)`) and creation date so closeout additions do not mutate the historical starting value; debtor/creditor/`can_manage_family` required to access
- `paymentHistory(Debt)` also appends `interest_accrual` entries from `debt.interest_accruals` so debt history includes interest events
- `splitDebtSummary(Request)` — `GET /split-debt-summary?year=&month=`; returns pending split debts for the current user's family grouped by counterpart user with `you_owe`, `they_owe`, and nested `transactions`

### CategoryController
- `index()` — returns family categories with `advance_fund_id` + `is_non_necessity_default` hydrated from the authenticated user's `category_user_defaults` row for each category
- `store(StoreCategoryRequest)` — creates shared category for auth user's family, then stores auth-user defaults (`advance_fund_id`, `is_non_necessity_default`) in `category_user_defaults`
- `update(StoreCategoryRequest, Category)` — updates shared family category fields; updates only the authenticated user's `category_user_defaults` row for per-user defaults
- `destroy(Category)` — deletes (no explicit authorization policy — Needs verification)

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
- `monthlyTotals()` — returns `{total_income, total_expenses}` for the current calendar month for the auth user; excludes `is_debt_payment=true` transactions; returns zeros if user has no `family_id`

### BankBalanceController
- `show()` — returns bank balance tracking state for auth user: disabled/null state when feature is off, baseline-not-set state when no baseline date exists, or computed balance state (`bank_balance + income - expense - completed title savings` since `bank_balance_set_at`)
- `update(UpdateBankBalanceRequest)` — updates `bank_balance_enabled` and/or baseline `bank_balance`; when a balance is provided it also sets `bank_balance_set_at` to today and forces enabled state
- `completeTitleSaving(int $id)` — marks one user-owned `CloseoutTitleSaving` row as completed, stamps `completed_at`, and creates a closeout-tagged expense transaction (`is_closeout_initiated=true`) using the rule’s optional `closeout_expense_category_id`
- `incompleteTitleSaving(int $id)` — clears completion state/timestamp and deletes the generated completion transaction when present

### PlaidController
- `linkToken(Request)` — `GET /plaid/link-token`; calls Plaid `/link/token/create` with `products` `['transactions']`, `country_codes` `['US']`, `transactions.days_requested` from config, and **`financekit_supported: true`** when `config('plaid.financekit_supported')` is true (env `PLAID_FINANCEKIT_SUPPORTED`, default true) so Link can offer FinanceKit / Apple Card where supported; returns `{link_token}`; `503` when credentials missing
- `exchange(ExchangePlaidTokenRequest)` — `POST /plaid/exchange`; exchanges `public_token`, persists `PlaidItem` with encrypted access token, hydrates institution metadata, runs initial `PlaidTransactionSyncService::syncItem` (returns JSON `pull` including raw Plaid rows; creates `plaid_pending_imports` for new `added` transactions and may auto-create ledger rows when `PlaidMerchantRule` qualifies)
- `items(Request)` — lists auth user’s linked items (no secrets)
- `sync(Request, PlaidItem)` — `POST /plaid/items/{plaidItem}/sync`; same as exchange pull; route binding scopes `{plaidItem}` to the owner
- `destroy(Request, PlaidItem)` — calls Plaid `/item/remove`, deletes local row

### PlaidImportController
- `index(Request)` — `GET /plaid/pending-imports`; JSON `{ pending, transfers, auto_created, dismissed }` (`pending` / `transfers` = `status=pending` by `is_transfer`; **`auto_created`** = `status=auto_created` **and** `reviewed_at` null, eager-loads `suggestedCategory`, `plaidItem`, and **`transaction`** with `user`, `category`, `splits.user`, `debt.*`, `advanceFund`, `fund`, `paidByUser` for import-review **Auto** tab detail; **`dismissed`** = `status=dismissed`, `dismiss_source=auto`, `reviewed_at` null). `?count_only=1` → `{ count, auto_created_count, dismissed_count }` (nav badge).
- `approveAutoCreated` — `POST …/approve-auto-created`; reinforces rule via `learnFromConfirmation` from linked transaction; sets **`reviewed_at`** on the pending row so it leaves the **auto_created** queue.
- `correctAutoCreated` — `POST …/correct-auto-created`; updates linked transaction + `learnFromConfirmation`; sets **`reviewed_at`** on the pending row.
- `confirm(StoreImportConfirmRequest, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/confirm`; owner-only; validates with the same shared transaction payload rules as manual creates (splits, pay-toward-debt, advance fund + non-necessity, income-debt modes); server merges pending **amount** and **date** into the request for validation; `TransactionService::createTransaction` + `plaid_transaction_id` / `import_source=plaid`; `transactions.fund_id` is set from request `fund_id` when present, otherwise from **`advance_fund_id`** for qualifying expenses (merchant rule `fund_id`/`advance_fund_id` learned the same way); `learnFromConfirmation` (passes `is_split`), pending `confirmed` + `transaction_id`; `ClosedMonthGuard` on payload; `403` without `family_id`.
- `dismiss(Request, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/dismiss`; `status=dismissed`, `recordSeen` on matching merchant rule; `204`.
- `dismissAsTransfer(Request, PlaidPendingImport)` — `POST /plaid/pending-imports/{pendingImport}/dismiss-as-transfer`; owner-only (`auth()->id()`); pending only; sets `status=dismissed`; optional `?learn=true` calls `learnDismissRule` from `merchant_name` / `raw_name`; `204`. Works for **any** pending row (transfer-flagged or not) so users can dismiss card payments from **To Review** as well as the **Transfers** tab.
- `ledgerLinkCandidates(Request, PlaidPendingImport)` — `GET …/ledger-candidates`; owner-only; non-transfer pending only; JSON candidate ledger rows for manual linking — **same `user_id` as the pending import** (see `PlaidMatchingService::findLedgerLinkCandidatesForPendingImport`).
- `linkToLedger(LinkPlaidPendingImportRequest, PlaidPendingImport)` — `POST …/link`; owner-only; non-transfer pending; validates family `Transaction`, `canLinkPendingImportToLedger`, no duplicate `plaid_transaction_id` on another row; `learnFromConfirmation` + sets `plaid_transaction_id` / `import_source` on ledger; pending `confirmed`; **no** `ClosedMonthGuard` (allows linking Plaid ids onto historical closed-month rows).
- `calibrationData(Request, PlaidItem)` — `GET /plaid/items/{plaidItem}/calibrate`; owner-only; `PlaidCalibrationService::buildCalibrationMatches` with ledger rows serialized to `{id, date, amount, description, type, fund_id, category}`.
- `applyCalibration(ApplyPlaidCalibrationRequest, PlaidItem)` — `POST /plaid/items/{plaidItem}/calibrate`; `applyCalibrationResults`; JSON counts `{ confirmed_linked, imported_pending }`.
- `syncMonth(Request, PlaidItem)` — `POST /plaid/items/{plaidItem}/sync-month`; current month `fetchByDateRange` + `ingestPlaidRowsAsPending`; JSON `{ pending_created, auto_created }` or `502` on Plaid errors.

### PlaidWebhookController
- `__invoke(Request)` — `POST /plaid/webhook` (CSRF-excluded); on `webhook_type=TRANSACTIONS`, loads `PlaidItem` by `item_id` and runs `PlaidTransactionSyncService::syncItem` to advance the sync cursor and process `added` / `modified` / `removed` into pending imports (and optional auto-ledger creates)

### MonthCloseoutController
- `status(Request)` — `POST /closeout/status`; accepts `{year, month}`; returns `{soft_closes, hard_close, all_soft_closed, family_user_count}` via `MonthCloseoutService::getMonthStatus`
- `softClose(Request)` — `POST /closeout/soft-close`; creates a `MonthSoftClose` record; auto-triggers `hardClose` for single-member families; returns `{message, data, hard_close?, auto_hard_closed?}`
- `undoSoftClose(Request)` — `POST /closeout/undo-soft-close`; removes the user's soft-close record (only if no hard close exists)
- `hardClose(Request)` — `POST /closeout/hard-close`; requires `can:manage_family`; runs `MonthCloseoutService::hardClose` (processes all members' closeout rules, consolidates split debts, applies monthly debt interest through the closed month-end date, creates `MonthHardClose`)
- `undoHardClose(Request)` — `POST /closeout/undo-hard-close`; requires `can_manage_family`; runs `MonthCloseoutService::undoHardClose` and returns `422` for closeout-state validation errors (e.g., no hard close for month)
- `closedMonths(Request)` — `GET /closeout/closed-months`; returns array of `{year, month}` hard-closed months for the auth user's family

### MonthSummaryController
- `show(Request)` — `GET /month-summary?year=&month=`; read-only overview for a specific month; requires family membership; returns `{year, month, is_hard_closed, close_status, category_totals, category_transactions, member_balances, rule_preview, fund_advance_transactions, fund_movements, debt_repayments, title_savings}`
  - `category_totals`: **authenticated user only** — income rows with **`user_id` = viewer** (**non–debt-payment**, **`is_borrow` false** — fund borrows align with **`rule_preview.basis.gross_income`** and appear under **Fund In/Out**), non-split viewer expenses **excluding** `is_debt_payment` and **`is_closeout_initiated`** from the main expense loop (closeout ledger expenses match **`rule_preview`/closeout basis exclusions**; see Fund In/Out / debt repayment UI for those movements), plus **split expense** **`transaction_splits.amount`** rows for the viewer (excluding split lines on debt-payment parents). **Debt-payment expenses** are merged afterward: **with** `category_id` they add to that category’s expense total; **without** `category_id` (solo or split parent uncategorized) they aggregate to synthetic **Uncategorized Debt Payments** (`category_id = -1`); sorted expenses first then by total descending
  - `member_balances`: split-expense net IOUs dated in that month (**`is_split`, `type=expense`**, includes split debt repayments, excludes **`is_closeout_initiated`**), direction (`they_owe_you` / `you_owe_them`); only non-zero nets are returned. Each row also includes source breakdown by transaction creator: `from_you_created_amount`, `from_them_created_amount`, and two history arrays (`from_you_created_transactions`, `from_them_created_transactions`) with per-transaction `transaction_id`, `transaction_date`, `category_name`, `category_icon`, `description`, `total_amount`, and `balance_amount`.
  - `fund_advance_transactions`: map of fund id → viewer expense rows with `advance_fund_id` in that month (`getFundAdvanceTransactions`; same scope as **`MonthCloseoutService::fundAdvanceOutstandingByFundForUserMonth`**)
  - `rule_preview`: dry-run of the auth user's active closeout rules with projected allocation amounts; includes `basis` (gross income, total expenses, **`non_necessity_expenses`**, **`gross_allocations_total`**, **`remaining_after_expenses`**). `total_expenses` stays aligned with **`MonthCloseoutService::expenseTotalTowardRemainingBasis`** (necessity basis), while `non_necessity_expenses` reports month sums of `is_non_necessity=true` advance expenses. **Gross-base rules** (`allocation_base != 'remaining'`) **stop once the running gross pool hits zero or below**, matching **`MonthCloseoutService::processUserCloseoutRules`** (avoids percentage-of-gross rules continuing to show positive amounts after the pool is gone); **later gross rules still appear in `rules` with `projected_amount` 0** for stable ordering (skipped rules due to depleted gross pool; **not** debt-balance skips). **`destination_type=debt`**: **`projected_amount`** carries the **nominal** allocation from the rule (before debt balance cap); **`net_after_advances`** carries the **capped** payoff (preview simulates running debt balances across gross then remaining rules, matching **`allocateToDebt`**); **`gross_allocations_total`** uses capped payoffs toward the remaining pool. **`remaining_after_expenses`** is **not clamped at zero**—it matches `gross_income - gross_allocations_total - total_expenses` (same pool used internally with `max(0, …)` only for applying remaining-base rules). **`expense_closeout_basis.lines`** summarizes what counts toward **`basis.total_expenses`** (same definition as **`MonthCloseoutService::expenseTotalTowardRemainingBasis`**: solo non–closeout-initiated, non-borrow, non-non-necessity expenses including tracked debt repayments, plus split shares on transactions with the same filters). **Gross omits `is_debt_payment` income** (creditor repayment lines). Each rule row includes **`destination_id`**, **`fund_advance_outstanding_before`** / **`net_after_advances`**: **fund** rules use **`net_after_advances` = capped allocation − month advances tagged to that fund** (consumption in rule order—**may be negative**); **debt** rules use nominal **`projected_amount`** with **`net_after_advances` = capped paydown** (`0` advances); **title** rules echo **`projected_amount`** in **`net_after_advances`** with zero advance columns
  - `debt_repayments`: `{ paid: [...], received: [...] }` viewer-scoped `is_debt_payment` rows that month (`counterparty_label`, amounts, descriptions); **`paid`** includes payer-side repayments where the viewer is **`transaction.user_id`** or appears in **`transaction_splits`**, and **`paid[].amount`** uses the viewer's **`TransactionSplit`** share when `is_split` (otherwise full expense **`amount`** for solo repayments owned by them); **`received`** remains creditor mirror incomes at full **`transactions.amount`**
  - `title_savings`: auth-user `CloseoutTitleSaving` rows for the selected month, returned only when `is_hard_closed=true`; each row includes completion state (`is_completed`, `completed_at`)

## Services

### ClosedMonthGuard (`app/Services/ClosedMonthGuard.php`)
- Shared guard for transaction-producing write paths. A month is locked when the family has a `MonthHardClose` for that year/month or any affected user has a `MonthSoftClose`.
- Transaction creates/updates/deletes include affected users: transaction owner, split participants, and mirrored debt-payment creditor rows. Debt-payment writes include payer, optional split participant, and creditor. Fund borrow/repay checks the current month for the acting user.
- Throws `InvalidArgumentException`; controllers return `422` JSON with the guard message.

### PlaidClient (`app/Services/PlaidClient.php`)
- Registers as a singleton (`AppServiceProvider`) built from `config/plaid.php`; POSTs JSON to Plaid with `Plaid-Version` header (`config('plaid.api_version')`, default `2020-09-14` — must be a released date from Plaid's versioning docs, not an arbitrary ISO date) and injects `client_id` / `secret` into each body.

### PlaidTransactionSyncService (`app/Services/PlaidTransactionSyncService.php`)
- Constructor: `PlaidClient`, `PlaidMatchingService`, `TransactionService`.
- `hydrateInstitution(PlaidItem)` — `/item/get` plus `/institutions/get_by_id` for display name.
- `syncItem(PlaidItem)` — loops `/transactions/sync` using stored cursor; persists `transactions_cursor`; then `processSyncedTransactions` for the accumulated `added` / `modified` / `removed` arrays. Returns aggregated `counts`, `added`, `modified`, `removed`, and deduped `accounts` (raw Plaid shapes).
- `processSyncedTransactions(PlaidItem, added, modified, removed)` — **Added:** skip if `plaid_pending_imports` already holds `plaid_transaction_id` (any status) or the family already has a `transactions` row with that Plaid id; `getSuggestion`; if the matching `PlaidMerchantRule` has `action=dismiss`, insert a `PlaidPendingImport` with `status=dismissed`, `dismiss_source='auto'` — all `suggested_*` fields including `suggested_description`, `suggested_is_debt_payment`, `suggested_split_data` populated from suggestion, `recordSeen`, skip auto-create; otherwise create `PlaidPendingImport` (`status=pending`) with all suggested fields including new three; **auto-create gate:** `is_auto_eligible` + user has `family_id`; when `is_debt_payment=true` also validates `debt_id` resolves to an active debt (`debtor_id=user`, `is_pending_closeout=false`, `balance > 0`) — skips auto-create when not found; **`buildAutoCreateTransactionPayload`**: description prefers `rule->description` over merchant raw name; split resolution prefers `rule->split_data` (validated via `SplitCalculator::validate`) before falling back to equal family split in both category and non-category branches; expense payload includes `is_debt_payment=true` + `debt_id` when the rule is a debt-payment rule with a valid learned debt; then `TransactionService::createTransaction`, optional `fund_id` tag, `plaid_transaction_id` + `import_source=plaid`, mark pending `auto_created` (failures leave pending); `recordSeen` on matching `PlaidMerchantRule` when present. **Modified:** pending rows (`status=pending`) get `amount`/`date`/`raw_payload` refresh; **Removed:** deletes still-`pending` `plaid_pending_imports`.
- `fetchByDateRange(PlaidItem, startDate, endDate)` — paginated `POST /transactions/get` (`options.count` 500 + `offset`) until `total_transactions` is satisfied; returns merged `transactions` rows (calibration).
- `ingestPlaidRowsAsPending(PlaidItem, rows)` — for each Plaid row array, reuses the same skip + `processAddedRow` path as sync **added**; returns `{ pending_created, auto_created }` (counts by resulting `PlaidPendingImport.status`).

### PlaidDailySyncCommand (`app/Console/Commands/PlaidDailySyncCommand.php`)

- Signature: `plaid:daily-sync {--item=}` — when `--item` is set, syncs that `PlaidItem` id only; otherwise all rows. Each item: `PlaidTransactionSyncService::syncItem` (cursor + `processSyncedTransactions`); failures are `report`ed and the loop continues. Stdout line: `Synced {institution}: {n} added, {auto_created} auto-created, {pending} queued for review` where `added` is Plaid’s added count for the pull and the other two counts come from `PlaidPendingImport` rows for this `plaid_item_id` among the returned `added` transaction ids (`pending` vs `auto_created`). **Scheduler:** the daily 02:00 registration in `routes/console.php` is currently **commented out** until production bank accounts are finalized; run the command manually or uncomment + restore `Schedule` import when ready.

### PlaidMatchingService (`app/Services/PlaidMatchingService.php`)
- `findLedgerMatch(plaidRow, familyId)` — same as `findLedgerMatchWithScore` but returns only the `Transaction` or null.
- `findLedgerMatchWithScore(plaidRow, familyId)` — same matching rules and **≥ 0.3** threshold; returns `['transaction' => Transaction, 'score' => float]` or null (used by calibration). Candidate ledger rows: same `family_id`, `plaid_transaction_id` null, expected type from Plaid amount sign, `transaction_date` within ±1 day of the Plaid date, and `amount` within **±0.01** of the normalized ledger amount (`whereBetween`, equivalent to the prior `ABS(amount - x) < 0.01` intent; avoids SQLite/Laravel binding quirks with two adjacent `?` placeholders in `whereRaw`).
- `normalizeMerchantKey` — delegates to `PlaidMerchantRule::normalizeKey`.
- `getSuggestion(plaidRow, userId)` — loads `PlaidMerchantRule` by normalized merchant; returns `category_id`, `type`, fund fields, `is_non_necessity`, `confidence_score` (`PlaidMerchantRule::confidenceScore`), `is_auto_eligible` (`false` when `action=dismiss`, otherwise `isAutoCreateEligible`), plus new learned fields `description`, `is_debt_payment`, `debt_id`, `split_data`; without a rule, returns nulls / false with `type` from Plaid sign (`>= 0` → expense, negative → income).
- `recordConfirmation` / `recordSeen` — increment merchant-rule counters (`confirmation_count` + `total_seen_count`, or `total_seen_count` only).
- `learnFromConfirmation` — `firstOrNew` by `user_id` + normalized key, merges whitelisted settings (`category_id`, `type`, `fund_id`, `advance_fund_id`, `is_non_necessity`, `is_split`, `action`, `description`, `is_debt_payment`, `debt_id`, `split_data`), defaults `action` to `categorize` when not supplied, then increments both counters and saves.
- `learnDismissRule` — normalizes merchant key, `firstOrNew` by `user_id` + `merchant_key`, sets `action=dismiss`, increments `total_seen_count` only, saves, returns the rule.
- `findLedgerLinkCandidatesForPendingImport(PlaidPendingImport, familyId, dayRadius?, limit?)` — wider ±`dayRadius` date window (default 45) than calibration auto-match; same amount (±0.01) and `suggested_type`; **restricts to `transactions.user_id` = pending import’s `user_id`** (same Plaid-linked account owner), so other family members’ manual rows are not suggested; returns scored ledger rows for import-review linking UI.
- `canLinkPendingImportToLedger(PlaidPendingImport, Transaction, maxDateDriftDays?)` — validates unlinked ledger row, **`transactions.user_id` matches the pending import owner**, type/amount alignment, date within default **60** days of the pending row’s date.

### PlaidCalibrationService (`app/Services/PlaidCalibrationService.php`)
- Constructor: `PlaidTransactionSyncService`, `PlaidMatchingService`, `TransactionService`.
- `buildCalibrationMatches(PlaidItem)` — Uses `Carbon::now()` to compute **start** = first day of the calendar month **two months before** the current month, **end** = last day of the **previous** calendar month; calls `fetchByDateRange`; loads family `Transaction` rows in that inclusive date range with `plaid_transaction_id` null; for each Plaid row runs `findLedgerMatchWithScore` to populate `matched` (`plaid`, `ledger`, `score`) or `unmatched_plaid` (`plaid`, `suggestion` from `getSuggestion`). `unmatched_ledger` lists in-window ledger rows not paired to any Plaid row. Users without `family_id` get all Plaid rows in `unmatched_plaid` and empty matched / unmatched_ledger.
- `applyCalibrationResults(PlaidItem, confirmedPairs, importAsNew)` — DB transaction; loads a Plaid id → row map from `fetchByDateRange` over the calibration window. **Structured pairs** (`plaid_transaction_id`, `ledger_transaction_id`, `category_id`, `type`, optional funds / `is_non_necessity`): `TransactionService::updateTransaction` on the ledger row, optional `fund_id` `forceFill`, `learnFromConfirmation`, then `plaid_transaction_id` + `import_source=plaid`. **Legacy pairs** `['plaid' => array, 'ledger' => Transaction|int]`: `learnFromConfirmation` from Plaid merchant + ledger-mirrored settings, then link Plaid id on the transaction. **`import_as_new`**: each string id (resolved from the window map) or full row array runs `createPendingImportFromPlaidRow` when not already imported. Returns `{ confirmed_linked: int, imported_pending: int }`.

### TransactionService (`app/Services/TransactionService.php`)
- `createTransaction(array, User): Transaction` — wraps everything in `DB::transaction`; for `type=income`, forces `is_split=false`, clears `split_data` and `advance_fund_id`, and optionally links debt via `income_debt_mode`:
  - `none`: regular income
  - `existing`: increments selected debt `amount` + `balance` by the income amount and links `transactions.debt_id`
  - `new`: creates a new debt from the same amount (external or interfamily) and links `transactions.debt_id`; supports optional new-debt settings (`income_new_interest_enabled`, `income_new_interest_rate`) and sets `loan_received_date` from the income transaction date
  For **expense + `debt_id`**, runs `createDebtRepaymentExpense()` (categorized payer expense, mirrored creditor income when applicable, decrement balance, `mirror_transaction_id` linkage); split debt-payment expenses are supported and create `transaction_splits` plus pending split debts for non-payer participants; does **not** call `FundService::processIncome`. Non-debt create/update paths now persist `is_non_necessity` only when the payload is an expense with `advance_fund_id`, not split, and `is_non_necessity` truthy.
- `updateTransaction(Transaction, array): Transaction` — supports ordinary transactions and debt-payment **expense** rows. Debt-payment updates rebalance debt amounts (restore old payment, apply new payment), update/create/remove mirrored creditor income rows as needed, and recreate split + pending split-debt rows when repayment splits are edited. Debt-payment **income** rows remain non-editable directly.
- `deleteTransaction(Transaction): void` — used by `TransactionController::destroy`; reverses mirrored debt-payment pairs (+ debt balance increment) or deletes splits/linked debts for normal rows

### FundService (`app/Services/FundService.php`)
- `processIncome(Transaction, User): void` — loads active `FundRule`s ordered by `order`; iterates rules; calculates allocation amount from `gross`, `net`, or `remaining` base; increments fund balance + creates `FundMovement` — **not called** from `TransactionService` in the current app (reserved / legacy path)
- `borrowFromFund(Fund, float, string, User): Transaction` — validates balance; decrements fund, creates `is_borrow=true` income transaction, creates `FundMovement` (type=`borrow`), creates `Debt` (creditor_id=null, fund_id set)
- `repayFund(Debt, float, User): void` — validates fund association, debtor match, amount; increments fund balance, creates `FundMovement` (type=`repayment`), creates expense transaction with `is_debt_payment=true`, decrements debt balance
- `sweepToSavings(Fund, float, string, User): FundMovement` — validates amount ≤ fund balance; decrements fund, creates `FundMovement` (type=`savings_sweep`, optional `description`); **no** `Transaction`; does not affect closeout math

### DebtService (`app/Services/DebtService.php`)
- `payDebt(Debt, float, string, User, bool $isCloseoutInitiated = false, ?string $paymentDate = null, ?int $splitWithUserId = null, ?float $splitPercentage = null): void` — validates and records a debt payment:
  - For **family debts** (`is_family_debt=true`): payer must be a family member
  - For **personal debts**: payer must be the debtor
  - Uses `paymentDate` when provided, otherwise defaults transaction date to today
  - Creates expense transaction for payer; when `splitWithUserId` / `splitPercentage` are provided, splits that expense and creates a pending `Debt` for the co-payer's share
  - Creates income transaction for creditor if `creditor_id` is not null; sets `mirror_transaction_id` linking the expense ↔ income pair (including split debt payments)
  - Decrements `debt.balance`
  - Rejects `is_pending_closeout=true` debts with `InvalidArgumentException`

### SplitCalculator (`app/Services/SplitCalculator.php`)
- `validate(array): bool` — checks `share_percentage` sum ≈ 100 (epsilon 0.01)
- `allocate(float, array): array` — distributes amount proportionally; last split absorbs rounding remainder
- `sumAmounts(array): float` — utility to verify allocation totals
- `equalShareSplitData(array $userIds): array` — equal **percentage** rows for `split_data` payloads (rounding matches the Vue equal-split helper); used by Plaid auto-create when a category default requests split
- `distributeEqually(array $userIds, float): array` — equal split utility (used internally; not currently called from controllers)

### MonthCloseoutService (`app/Services/MonthCloseoutService.php`)
- `expenseTotalTowardRemainingBasis(User, int, int): float` — sums the viewer’s month expenses used for **remaining-after-expenses** math during hard close and for **`GET /month-summary` `rule_preview.basis.total_expenses`**: solo `expense` rows (`is_split=false`, `is_closeout_initiated=false`, `is_borrow=false`, `is_non_necessity=false`, same `family_id`) **including** tracked debt repayments, plus **`transaction_splits`** for that user on `expense` parents with the same closeout/borrow filters (split shares on debt repayments included). Non-necessity advance expenses are excluded from this basis and are deducted from fund balances via `applyFundAdvances()`.
- `fundAdvanceOutstandingByFundForUserMonth(User, int, int): array` — map of **`advance_fund_id` → SUM(amount)** for the user’s advance-tagged expenses in that calendar month (used for rule-preview netting and remaining-pool math)
- `processUserCloseoutRules(User, int, int): void` uses that expense total; still excludes `is_closeout_initiated=true` expenses from the basis so closeout-generated movement rows do not recursively affect the same closeout run. When building the **remaining** pool after gross rules, **gross-base fund** allocations count only **`max(0, allocated − advance outstanding to that fund before the rule in rule order)`** so advance expenses already in the expense total are not double-subtracted (nominal fund allocations from rules are unchanged; only the remaining-phase input is adjusted)
- Remaining-base percentage rules use a shared post-expense basis for the phase (not cascading percentage-on-percentage reduction); fixed remaining rules still consume the available remaining pool in order
- `allocateToFund(...)` creates both a `FundMovement` (`closeout_allocation`) and a closeout-tagged expense transaction for ledger visibility in Transactions
- `allocateToDebt(...)` applies `closeout_expense_category_id` to closeout-created debt-payment expense rows
- `allocateToTitle(...)` upserts **`CloseoutTitleSaving`** by `(family_id, user_id, year, month, title)`; **`rule_id` is set only when the row is first created** so a second title rule that shares the same title string still accumulates **`amount`** but does not overwrite **`rule_id`** (completion expenses use the first rule’s **`closeout_expense_category_id`**)
- `undoHardClose(Family, int, int): void` fully reverts hard-close artifacts inside one DB transaction (guarded by an existing `MonthHardClose` row): reverses closeout debt-payment impacts, reverses/deletes month-tagged closeout `FundMovement` rows (`closeout_allocation`, `advance_settlement`), deletes closeout-generated transactions, removes title savings and completion transactions, rolls back consolidated debt `contributions` for that month (deleting debts only when that month's contribution entries are marked `created_by_closeout_debt=true`), recreates pending split debts from month split transactions, removes month interest accrual entries from debts, then deletes month soft/hard close records

## Form Requests

Located in `app/Http/Requests/`. Several exist but not all are used uniformly:

| Request | Used by |
|---|---|
| `StoreTransactionRequest` | `TransactionController::store` + `update` |
| `StoreCategoryRequest` | `CategoryController::store` + `update` |
| `StoreFundRequest` | NOT used — `FundController` validates inline |
| `StoreFundRuleRequest` | NOT used — `FundController` validates inline |
| `UpdateFundRuleRequest` | NOT used — `FundController` validates inline |
| `PayDebtRequest` | `DebtController::payDebt` |
| `UpdateBankBalanceRequest` | `BankBalanceController::update` |
| `CreateFamilyRequest` | NOT used — `AdminController` validates inline |
| `CreateUserRequest` | NOT used — `AdminController` validates inline |

`StoreTransactionRequest` additionally enforces `is_non_necessity` as a guarded boolean: it is normalized to `false` unless the request is a non-split expense with `advance_fund_id` and no `debt_id`; when `true`, it is only valid if the auth user has an active `FundRule` for that same fund with `destination_type='fund'`, `allocation_type='percentage'`, and `allocation_base='remaining'`.

`StoreCategoryRequest` additionally enforces `is_non_necessity_default` as a guarded boolean for the authenticated user’s per-category defaults: it is normalized to `false` unless the category is expense-type and has `advance_fund_id`; when `true`, it is only valid if the auth user has an active `FundRule` targeting that same fund with `destination_type='fund'`, `allocation_type='percentage'`, and `allocation_base='remaining'`.

## Policies

- `FundPolicy` — `view`, `update`, `delete` all check `$user->id === $fund->user_id`
- `DebtPolicy` — `view` checks same family and user is debtor or creditor; **not actively invoked by `DebtController`** (Needs verification)

Auto-discovery by Laravel maps `Fund` → `FundPolicy`, `Debt` → `DebtPolicy`.

## Fortify configuration

- `config/fortify.php` `home` → `/home` (but the app uses `/dashboard` — Needs verification if this causes redirect issues)
- 2FA columns exist in migrations (from Fortify scaffold); 2FA UI is not present in the Vue app
- Registration via `CreateNewUser` action; no email verification enforced

## Known backend gaps

1. `CategoryController` has no authorization policy — any authenticated family member can edit/delete any family category
2. `TransactionController::update` does not re-run fund allocation (income amount changes are not re-allocated)
3. `DebtPolicy` exists but `DebtController` does not call `$this->authorize()`
4. `net_income` allocation base currently behaves identically to `gross_income` (no separate net calculation)
