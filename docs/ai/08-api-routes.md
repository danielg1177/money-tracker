# 08 — API Routes

All routes are defined in `routes/web.php`. There is no `routes/api.php`.

GET routes use the hybrid pattern: browser requests get the SPA shell (`view('app')`); Axios JSON requests (`Accept: application/json`) get JSON data.

Fortify routes (login, logout, password reset, 2FA) are auto-registered by the `FortifyServiceProvider` and not listed in `web.php`.

---

## Public SPA shell routes (no auth required)

These routes exist purely so Laravel doesn't 404 when the Vue router navigates directly:

| Method | Path | Returns |
|---|---|---|
| GET | `/` | `view('app')` |
| GET | `/login` | `view('app')` — route name `login` (required for `auth` middleware redirect when session expires) |
| GET | `/dashboard` | `view('app')` |
| GET | `/categories` | `view('app')` (SPA shell — JSON requires auth) |
| GET | `/admin/categories` | `view('app')` (SPA shell — no JSON endpoint exists) |
| GET | `/my-family` | `view('app')` (SPA shell — JSON requires auth + `manage_family`) |
| GET | `/debts` | `view('app')` (SPA shell — JSON requires auth) |
| GET | `/bank-connections` | `view('app')` (SPA shell — JSON requires auth for `/plaid/*`) |
| GET | `/plaid/import-review` | `view('app')` (SPA shell — pending import review) |
| GET | `/plaid/calibrate/{itemId}` | `view('app')` (SPA shell — Plaid calibration) |
| GET | `/month-summary/{yearMonth}` | `view('app')` (SPA shell — JSON requires auth) |

---

## Webhooks (no auth; CSRF excluded)

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/plaid/webhook` | `PlaidWebhookController` | Plaid server-to-server callbacks (`TRANSACTIONS` triggers `syncItem`, which advances the cursor and runs `processSyncedTransactions`). **Not verified cryptographically in-app** — keep the URL private or terminate TLS only on your network |

---

## Fortify routes (auto-registered)

| Method | Path | Description |
|---|---|---|
| POST | `/login` | Authenticate user |
| POST | `/logout` | Log out |
| POST | `/forgot-password` | Send reset link |
| POST | `/reset-password` | Reset password |
| GET/POST | `/two-factor-*` | 2FA endpoints (not used in UI) |

---

## Authenticated routes (`auth` middleware)

### User

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/user` | inline closure | Returns `auth()->user()` as JSON; or SPA shell |

### Transactions

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/transactions` | `TransactionController::index` | Accepts `?start_date=&end_date=` filters; JSON includes `debt` (with `creditor`, `debtor`, `fund` when present) for debt-payment rows, `advanceFund` when `advance_fund_id` is set; omits split debt-payment **expense** for the creditor when that row duplicates their repayment **income** for the same debt |
| POST | `/transactions` | `TransactionController::store` | Body: see `StoreTransactionRequest`; rejects `422` when the family month is hard-closed or any affected user has soft-closed the month |
| PUT | `/transactions/{transaction}` | `TransactionController::update` | Same body as store; debt-payment **expense** rows can be edited (recalculates debt balance + mirrored income), debt-payment **income** mirror rows are rejected; rejects `422` when the existing row month or target payload month is closed |
| DELETE | `/transactions/{transaction}` | `TransactionController::destroy` | Rejects `422` when the transaction month is closed |

### Plaid (bank connections)

Requires `PLAID_CLIENT_ID` + `PLAID_SECRET` in the environment. Link tokens use product `transactions` and `country_codes` `US`. Sync writes `plaid_pending_imports` (and may auto-create `transactions` when merchant rules qualify); see `docs/ai/02-backend-laravel.md` (`PlaidTransactionSyncService`).

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/plaid/link-token` | `PlaidController::linkToken` | JSON `{link_token}`; Plaid `/link/token/create` includes `financekit_supported` when `PLAID_FINANCEKIT_SUPPORTED` / `config('plaid.financekit_supported')` is true (default); `503` when Plaid env incomplete |
| POST | `/plaid/exchange` | `PlaidController::exchange` | Body `{public_token}` from Link `onSuccess`; stores encrypted access token on `plaid_items`, hydrates institution metadata, runs initial `/transactions/sync` pull; `201` with `{item, pull}` where `pull` contains `counts`, `added`, `modified`, `removed`, `accounts` |
| GET | `/plaid/items` | `PlaidController::items` | Lists auth user’s linked items (no secrets) |
| GET | `/plaid/pending-imports` | `PlaidImportController::index` | Default JSON `{ pending, transfers, recently_auto_created }` (`pending` / `transfers` split by `is_transfer` among `status=pending`; both include `suggestedCategory` and `plaidItem` for institution name). With `?count_only=1` (or `count_only=true`), JSON `{ count }` only — all pending rows for the auth user (lightweight for nav badge) |
| GET | `/plaid/pending-imports/{pendingImport}/ledger-candidates` | `PlaidImportController::ledgerLinkCandidates` | JSON `{ candidates: [...] }` — ledger rows for the **same user** as the pending import (`transactions.user_id` = importer), same `family_id`, no `plaid_transaction_id`, same type and amount (±0.01) within ±45 days, scored by name/description similarity; non-transfer pending only; empty when user has no `family_id` |
| POST | `/plaid/pending-imports/{pendingImport}/link` | `PlaidImportController::linkToLedger` | Body: `LinkPlaidPendingImportRequest` (`transaction_id` must exist in the user’s **family** and be **owned by the authenticated user**); links `plaid_transaction_id` on the chosen `Transaction`, `learnFromConfirmation` from pending merchant + ledger category/type/funds, marks pending `confirmed` + `transaction_id`; does **not** run `ClosedMonthGuard` (metadata-only link); `422` on mismatch (amount/type/60-day window / duplicate Plaid id / transfer row / wrong owner) |
| POST | `/plaid/pending-imports/{pendingImport}/confirm` | `PlaidImportController::confirm` | Body: `StoreImportConfirmRequest` — same transaction-shaped fields as `POST /transactions` where applicable: `category_id`, `type`, optional `fund_id`, `description`, `is_split` + `split_data`, expense `debt_id` (pay toward debt), `advance_fund_id` + `is_non_necessity`, income `income_debt_mode` / `income_existing_debt_id` / new-debt fields; amount and date are taken from the pending row server-side. Creates via `TransactionService::createTransaction`, sets `plaid_transaction_id` + `import_source=plaid`, `learnFromConfirmation` (includes `is_split`); `422` if not pending / closed month / validation |
| POST | `/plaid/pending-imports/{pendingImport}/dismiss` | `PlaidImportController::dismiss` | Sets `status=dismissed`, `recordSeen` on merchant rule when present; `204`; `{pendingImport}` scoped to auth user |
| POST | `/plaid/pending-imports/{pendingImport}/dismiss-as-transfer` | `PlaidImportController::dismissAsTransfer` | Sets `status=dismissed`; optional `?learn=true` runs `learnDismissRule` (`plaid_merchant_rules.action=dismiss`, `total_seen_count` only); `204`; owner-only |
| GET | `/plaid/items/{plaidItem}/calibrate` | `PlaidImportController::calibrationData` | JSON from `PlaidCalibrationService::buildCalibrationMatches`; ledger sides are slim `{id, date, amount, description, type, fund_id, category}` |
| POST | `/plaid/items/{plaidItem}/calibrate` | `PlaidImportController::applyCalibration` | Body: `ApplyPlaidCalibrationRequest` (`confirmed_pairs[]`, `import_as_new[]`); `PlaidCalibrationService::applyCalibrationResults`; JSON `{ confirmed_linked, imported_pending }` |
| POST | `/plaid/items/{plaidItem}/sync-month` | `PlaidImportController::syncMonth` | Current calendar month; `fetchByDateRange` + `ingestPlaidRowsAsPending` (same path as sync `added`); JSON `{ pending_created, auto_created }` or `502` on Plaid failure |
| POST | `/plaid/items/{plaidItem}/sync` | `PlaidController::sync` | Same as exchange pull; JSON `{pull: …}` |
| DELETE | `/plaid/items/{plaidItem}` | `PlaidController::destroy` | Calls Plaid `/item/remove`, deletes local `plaid_items` row |

### Funds

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/funds` | `FundController::index` | Personal funds: auth user’s funds with `family_id` null. Family funds: all funds with `family_id` = user’s family. Merged with `scope` each (`personal` \| `family`); family rows are omitted from the personal query so the creator does not see duplicates. Each row also includes `has_non_necessity_rule` (auth user has active `destination_type='fund'` + `allocation_type='percentage'` + `allocation_base='remaining'` rule for that fund id). Each `movements[]` row includes nested `user` (`name`, etc.) for who recorded the movement |
| POST | `/funds` | `FundController::store` | `{name, description?, is_family_fund?}`; if `is_family_fund=true` and user has `family_id`, fund is family-scoped |
| PUT | `/funds/{fund}` | `FundController::update` | `{name, description?}`; requires fund ownership or family membership with `can_manage_family` for editing |
| DELETE | `/funds/{fund}` | `FundController::destroy` | Requires fund ownership (personal) or family membership with `can_manage_family` (family-scoped) |
| GET | `/funds/{fund}/rules` | `FundController::showRules` | **Backward compatibility:** returns the same JSON as `GET /closeout-rules` (all of the auth user’s rules, not scoped to `{fund}`) |
| POST | `/funds/{fund}/borrow` | `FundController::borrow` | `{amount, description?}`; requires fund ownership or family membership; rejects `422` when the current month is closed for the user |
| POST | `/funds/{fund}/sweep` | `FundController::sweep` | **Body:** `{ amount: number` (required, min 0.01, max = fund balance), `description?: string }`. **Auth:** required (`FundPolicy::update` — fund owner or family member with access). **Effect:** decrements `fund.balance` by `amount`, creates `FundMovement` (`type=savings_sweep`). No `Transaction` created. No month-close guard. **Response:** `201` — `FundMovement` with `user` relationship. **Errors:** `422` if amount > balance or validation fails; `403` if not authorized for the fund |

### Closeout rules (`FundRule` — month hard-close allocations)

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/closeout-rules` | `FundController::showRules` | JSON: auth user’s `FundRule` rows ordered by `order` |
| POST | `/closeout-rules` | `FundController::storeRule` | `{name, order, allocation_type, amount, allocation_base?, is_active?, destination_type, destination_id?, destination_title?, fund_id?, closeout_expense_category_id?}` (`closeout_expense_category_id` must be an expense category in the user’s family). **Title** rules (`destination_type=title`): **`destination_title`** must be unique per user among **active** title rules (`422` on duplicate). |
| PUT | `/closeout-rules/{fundRule}` | `FundController::updateRule` | Same body as POST; `{fundRule}` must belong to auth user (same **`destination_title`** uniqueness among active title rules, excluding this row) |
| DELETE | `/closeout-rules/{fundRule}` | `FundController::destroyRule` | — |

### Debts

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/debts` | `DebtController::index` | Returns `{owed: [...], owing: [...], family_debts: [...]}` |
| GET | `/split-debt-summary` | `DebtController::splitDebtSummary` | Query: `year`, `month` (1–12). JSON: pending closeout split debts grouped by counterpart; each nested `transaction` includes `category` and, when applicable, `debt` with `creditor`, `debtor`, `fund` for debt-payment rows |
| POST | `/debts` | `DebtController::store` | `{is_family_debt?, is_interfamily?, creditor_id?, creditor_name?, amount, description?, interest_enabled?, interest_rate?, loan_received_date?}` |
| PUT | `/debts/{debt}` | `DebtController::update` | Updates debt fields (`description`, `creditor_name`, `interest_enabled`, `interest_rate`, `loan_received_date`) |
| POST | `/debts/pay` | `DebtController::payDebt` | `{debt_id, amount, description?, transaction_date?, split_with_user_id?, split_percentage?}`; rejects `422` when the payment month is closed for the payer, optional split participant, or creditor |
| GET | `/debts/{debt}/payments` | `DebtController::paymentHistory` | Debtor, creditor, or `can_manage_family`; JSON array of payment rows (creditor sees income rows, others see expense rows), includes `split_breakdown` when a payment was split |
| DELETE | `/debts/{debt}` | `DebtController::destroy` | Only debtor or `can_manage_family` user can delete |
| POST | `/debts/{debt}/repay-fund` | `FundController::repayFund` | `{amount}`; only for fund debts; rejects `422` when the current month is closed for the user |


### Month Closeout

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/closeout/status` | `MonthCloseoutController::status` | Body: `{year, month}`; JSON: `{soft_closes, hard_close, all_soft_closed, family_user_count}` |
| POST | `/closeout/soft-close` | `MonthCloseoutController::softClose` | `{year, month}`; auto-hard-closes if family has only one member; returns `{message, data (soft_close), hard_close?, auto_hard_closed?}` |
| POST | `/closeout/undo-soft-close` | `MonthCloseoutController::undoSoftClose` | `{year, month}`; undoes soft close (must have no hard close) |
| POST | `/closeout/hard-close` | `MonthCloseoutController::hardClose` | `{year, month}`; requires `can_manage_family`; processes all members' closeout rules, consolidates pending split debts, and applies eligible debt interest through the closed month-end date (daily accrual with in-month payment impact) |
| POST | `/closeout/undo-hard-close` | `MonthCloseoutController::undoHardClose` | Undo a hard close; reverts all closeout artifacts. Requires auth + `can_manage_family`. Body: `{year, month}`. Returns `{message}`. |
| GET | `/closeout/closed-months` | `MonthCloseoutController::closedMonths` | JSON: array of hard-closed months for family as `{year, month}` |

### Family members

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/family/users` | inline closure | Returns users in auth user's family |

### Categories

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/categories` | `CategoryController::index` | Returns family categories with caller-specific `advance_fund_id` + `is_non_necessity_default` |
| POST | `/categories` | `CategoryController::store` | See `StoreCategoryRequest` (exactly one of `is_income` / `is_expense` must be true) |
| PUT | `/categories/{category}` | `CategoryController::update` | See `StoreCategoryRequest` (same XOR rule) |
| DELETE | `/categories/{category}` | `CategoryController::destroy` | — |

### Dashboard

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/dashboard/monthly-totals` | `DashboardController::monthlyTotals` | Returns `{total_income, total_expenses}` for current month, auth user only |

### Bank balance & title savings completion

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/bank-balance` | `BankBalanceController::show` | Returns `{enabled, bank_balance, bank_balance_set_at, computed_balance, delta}` for auth user |
| PUT | `/bank-balance` | `BankBalanceController::update` | Body: `{bank_balance_enabled?, bank_balance?}`; when `bank_balance` is provided, sets baseline date to today |
| POST | `/title-savings/{id}/complete` | `BankBalanceController::completeTitleSaving` | Marks one auth-user title saving row as completed |
| DELETE | `/title-savings/{id}/complete` | `BankBalanceController::incompleteTitleSaving` | Reverses completion for one auth-user title saving row |

### Month Summary

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/month-summary` | `MonthSummaryController::show` | Query: `year`, `month`. Returns `{year, month, is_hard_closed, close_status, category_totals, category_transactions, member_balances, rule_preview, fund_advance_transactions, fund_movements, debt_repayments, title_savings}`; **`category_totals`** is **scoped to the authenticated user** (not full-family). Viewer **income** rows **exclude `is_borrow`** (fund borrow withdrawals align with **`rule_preview.basis.gross_income`**; see **`fund_movements`**). Solo **non–debt-payment** expense rows **exclude `is_closeout_initiated`** (hard-close ledger lines do not inflate **Your expenses** totals; see **`fund_movements`** / **`debt_repayments`**). Categorized **debt repayment** expenses merge into that category; uncategorized repayments aggregate to synthetic **Uncategorized Debt Payments** (`category_id=-1`). **`member_balances`**: net split-expense IOUs for **`is_split` expenses in that month, including split debt repayments and excluding `is_closeout_initiated`**; only non-zero nets appear. Each row also includes creator-source breakdown and history arrays: `from_you_created_amount`, `from_them_created_amount`, `from_you_created_transactions[]`, `from_them_created_transactions[]` (each history row has `transaction_id`, `transaction_date`, `category_name`, `category_icon`, `description`, `total_amount`, `balance_amount`). **`rule_preview.basis.total_expenses`** matches **`MonthCloseoutService::expenseTotalTowardRemainingBasis`** (includes those repayments; excludes `is_closeout_initiated` / `is_borrow` legs and non-necessity advance expenses). **`rule_preview.basis.non_necessity_expenses`** separately reports month expense totals where `is_non_necessity=true` + `advance_fund_id` set. **`rule_preview.basis`** also includes **`gross_allocations_total`** (amount subtracted from gross for remaining; **fund**-target gross rules net month **advances** to that fund so they are not double-counted with **`total_expenses`**) and a **signed** **`remaining_after_expenses`**. **`rule_preview.expense_closeout_basis.lines`** is a short list describing that expense basis. `title_savings` is populated only for hard-closed months and includes `{id, title, amount, is_completed, completed_at}` rows for the authenticated user. **`fund_advance_transactions`** maps fund id → advance-tagged expense rows for the viewer in that month (same scope as **`MonthCloseoutService::fundAdvanceOutstandingByFundForUserMonth`**). **`rule_preview.rules[]`** includes **`destination_id`**, **`fund_advance_outstanding_before`**, and **`net_after_advances`** for fund allocations (subtracts month's advance-tagged expenses to that fund, rule-order; **`net_after_advances` may be negative**). **`destination_type=debt`** rows expose **nominal** **`projected_amount`** and **`net_after_advances`** equal to the **capped** paydown (**`MonthSummary`** shows **`rulePreviewNet()`**, which prefers **`net_after_advances`**—users see capped dollars). **`debt_repayments.paid`** uses each viewer's split share for split debt repayments (and lists co-payers on those expenses). Requires `family_id` (403 if unset). All read-only. |

---

## Admin routes (`can:admin` middleware)

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/admin/users` | `AdminController::users` | All users with family |
| POST | `/admin/users` | `AdminController::createUser` | `{name, email, password, family_id?, role}` |
| PUT | `/admin/users/{user}` | `AdminController::updateUser` | `{name, email, family_id?, role, is_admin?, password?}` — `password` is optional; when provided (`min:8`), the user's password is updated; blank/absent password keeps the existing hash |
| DELETE | `/admin/users/{user}` | `AdminController::deleteUser` | Cannot delete self |
| GET | `/admin/families` | `AdminController::families` | All families with users + categories |
| POST | `/admin/families` | `AdminController::createFamily` | `{name, description?}` |

---

## Family management routes (`can:manage_family` middleware)

| Method | Path | Controller | Notes |
|---|---|---|---|
| PUT | `/admin/families/{family}` | `AdminController::updateFamily` | head_of_household restricted to own family |
| DELETE | `/admin/families/{family}` | `AdminController::deleteFamily` | Nullifies members' family_id first |
| POST | `/admin/families/{family}/users` | `AdminController::addFamilyMember` | `{user_id}` |
| DELETE | `/admin/families/{family}/users/{user}` | `AdminController::removeFamilyMember` | — |
| GET | `/my-family` | `AdminController::myFamily` | Returns auth user's family with users + categories |

---

## Request bodies (key Form Requests)

### `StoreTransactionRequest`
For `type=income`, `advance_fund_id`, `is_split`, `split_data`, and expense-side `debt_id` are cleared server-side before validation (income does not support expense split/advance/repayment flow). Income can still optionally link debt through `income_debt_mode`.

For `type=expense`, optional **`debt_id`** (existing `debts.id` for the payer’s family) records a categorized debt repayment: creates/expands the same flow as `DebtService::payDebt` for simple (non-split) payments — reduces `debts.balance`, emits mirrored **`is_debt_payment` creditor income** when `creditor_id` is set; **mutually exclusive** with split/advance (`prepareForValidation` clears those when `debt_id` is present).

`is_non_necessity` is a boolean transaction flag. It is force-normalized to `false` unless the request is an **expense**, has an **`advance_fund_id`**, is **not split**, and is **not a debt payment**. When sent as `true`, validation also requires an active auth-user closeout rule targeting that same advance fund (`destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'`).

```json
{
  "type": "income|expense",
  "amount": 100.00,
  "transaction_date": "2026-05-03",
  "category_id": 1,
  "description": "optional",
  "is_split": false,
  "is_non_necessity": false,
  "split_data": [
    {"user_id": 1, "share_percentage": 60},
    {"user_id": 2, "share_percentage": 40}
  ],
  "debt_id": null,
  "income_debt_mode": "none|existing|new",
  "income_existing_debt_id": null,
  "income_new_is_family_debt": false,
  "income_new_is_interfamily": false,
  "income_new_creditor_id": null,
  "income_new_creditor_name": null,
  "income_new_description": null,
  "income_new_interest_enabled": false,
  "income_new_interest_rate": null
}
```

`income_debt_mode` behavior (`type=income`):
- `none`: regular income (default)
- `existing`: increase selected debt amount/balance and link transaction to that debt (`transactions.debt_id`)
- `new`: create a new debt from this income amount (external name or interfamily creditor) and link it

### `StoreCategoryRequest`
When `is_expense` is false, `is_split_default`, `split_default`, and `advance_fund_id` are cleared server-side before validation.

`is_non_necessity_default` is a boolean category flag on the request, but persistence is per-user-per-category (`category_user_defaults`), not on the shared category row. It is force-normalized to `false` unless the category is an expense and has `advance_fund_id`. When sent as `true`, validation requires an active auth-user closeout rule targeting that same advance fund (`destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'`).

```json
{
  "name": "Groceries",
  "icon": "🛒",
  "is_income": false,
  "is_expense": true,
  "is_non_necessity_default": false,
  "is_split_default": true,
  "split_default": [{"user_id": 1, "share_percentage": 50}, {"user_id": 2, "share_percentage": 50}]
}
```

### `PayDebtRequest`
```json
{
  "debt_id": 5,
  "amount": 50.00,
  "description": "optional",
  "transaction_date": "2026-05-03",
  "split_with_user_id": null,
  "split_percentage": null
}
```
`split_with_user_id` and `split_percentage` are optional. When provided, the payer's expense transaction is split with the specified family member (creates a pending `Debt` for their share). `transaction_date` is optional; when omitted, backend uses today's date.

---

## Response notes

- GET endpoints return model JSON directly (no Eloquent API Resources)
- `User` JSON always includes appended attributes: `is_admin`, `is_head_of_household`, `can_manage_family`
- `Fund` JSON includes `fund_rules` and `movements` (eager-loaded on index)
- `Transaction` JSON includes `user`, `category`, `splits` (with `splits.user`)
- `Debt` JSON includes `creditor` (on `owed`) or `debtor` (on `owing`)

---

## Missing / broken routes

| Path | Issue |
|---|---|
| `POST /admin/categories` | Does not exist — `admin/Categories.vue` tries to POST here |
| `GET /admin/categories/{family_id}` | Does not exist — referenced in legacy `App.vue` |
