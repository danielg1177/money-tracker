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
| GET | `/month-summary/{yearMonth}` | `view('app')` (SPA shell — JSON requires auth) |

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
| GET | `/transactions` | `TransactionController::index` | Accepts `?start_date=&end_date=` filters; JSON includes `debt` (with `creditor`, `debtor`, `fund` when present) for debt-payment rows; omits split debt-payment **expense** for the creditor when that row duplicates their repayment **income** for the same debt |
| POST | `/transactions` | `TransactionController::store` | Body: see `StoreTransactionRequest` |
| PUT | `/transactions/{transaction}` | `TransactionController::update` | Same body as store |
| DELETE | `/transactions/{transaction}` | `TransactionController::destroy` | — |

### Funds

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/funds` | `FundController::index` | Personal funds: auth user’s funds with `family_id` null. Family funds: all funds with `family_id` = user’s family. Merged with `scope` each (`personal` \| `family`); family rows are omitted from the personal query so the creator does not see duplicates. Each `movements[]` row includes nested `user` (`name`, etc.) for who recorded the movement |
| POST | `/funds` | `FundController::store` | `{name, description?, is_family_fund?}`; if `is_family_fund=true` and user has `family_id`, fund is family-scoped |
| PUT | `/funds/{fund}` | `FundController::update` | `{name, description?}`; requires fund ownership or family membership with `can_manage_family` for editing |
| DELETE | `/funds/{fund}` | `FundController::destroy` | Requires fund ownership (personal) or family membership with `can_manage_family` (family-scoped) |
| GET | `/funds/{fund}/rules` | `FundController::showRules` | **Backward compatibility:** returns the same JSON as `GET /closeout-rules` (all of the auth user’s rules, not scoped to `{fund}`) |
| POST | `/funds/{fund}/borrow` | `FundController::borrow` | `{amount, description?}`; requires fund ownership or family membership |

### Closeout rules (`FundRule` — month hard-close allocations)

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/closeout-rules` | `FundController::showRules` | JSON: auth user’s `FundRule` rows ordered by `order` |
| POST | `/closeout-rules` | `FundController::storeRule` | `{name, order, allocation_type, amount, allocation_base?, is_active?, destination_type, destination_id?, destination_title?, fund_id?}` |
| PUT | `/closeout-rules/{fundRule}` | `FundController::updateRule` | Same body as POST; `{fundRule}` must belong to auth user |
| DELETE | `/closeout-rules/{fundRule}` | `FundController::destroyRule` | — |

### Debts

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/debts` | `DebtController::index` | Returns `{owed: [...], owing: [...], family_debts: [...]}` |
| GET | `/split-debt-summary` | `DebtController::splitDebtSummary` | Query: `year`, `month` (1–12). JSON: pending closeout split debts grouped by counterpart; each nested `transaction` includes `category` and, when applicable, `debt` with `creditor`, `debtor`, `fund` for debt-payment rows |
| POST | `/debts` | `DebtController::store` | `{is_family_debt?, is_interfamily?, creditor_id?, creditor_name?, amount, description?, interest_enabled?, interest_rate?, loan_received_date?}` |
| PUT | `/debts/{debt}` | `DebtController::update` | Updates debt fields (`description`, `creditor_name`, `interest_enabled`, `interest_rate`, `loan_received_date`) |
| POST | `/debts/pay` | `DebtController::payDebt` | `{debt_id, amount, description?, split_with_user_id?, split_percentage?}` |
| GET | `/debts/{debt}/payments` | `DebtController::paymentHistory` | Debtor, creditor, or `can_manage_family`; JSON array of payment rows (creditor sees income rows, others see expense rows) |
| DELETE | `/debts/{debt}` | `DebtController::destroy` | Only debtor or `can_manage_family` user can delete |
| POST | `/debts/{debt}/repay-fund` | `FundController::repayFund` | `{amount}`; only for fund debts |


### Month Closeout

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/closeout/status` | `MonthCloseoutController::status` | Body: `{year, month}`; JSON: `{soft_closes, hard_close, all_soft_closed, family_user_count}` |
| POST | `/closeout/soft-close` | `MonthCloseoutController::softClose` | `{year, month}`; auto-hard-closes if family has only one member; returns `{message, data (soft_close), hard_close?, auto_hard_closed?}` |
| POST | `/closeout/undo-soft-close` | `MonthCloseoutController::undoSoftClose` | `{year, month}`; undoes soft close (must have no hard close) |
| POST | `/closeout/hard-close` | `MonthCloseoutController::hardClose` | `{year, month}`; requires `can_manage_family`; processes all members' closeout rules, consolidates pending split debts, and applies eligible debt interest through the closed month-end date (daily accrual with in-month payment impact) |
| GET | `/closeout/closed-months` | `MonthCloseoutController::closedMonths` | JSON: array of hard-closed months for family as `{year, month}` |

### Family members

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/family/users` | inline closure | Returns users in auth user's family |

### Categories

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/categories` | `CategoryController::index` | Returns family categories (JSON) |
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
| GET | `/month-summary` | `MonthSummaryController::show` | Query: `year`, `month`. Returns `{year, month, is_hard_closed, close_status, category_totals, member_balances, rule_preview, fund_movements, debt_repayments, title_savings}`; `title_savings` is populated only for hard-closed months and includes `{id, title, amount, is_completed, completed_at}` rows for the authenticated user. Requires `family_id` (403 if unset). All read-only. |

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

```json
{
  "type": "income|expense",
  "amount": 100.00,
  "transaction_date": "2026-05-03",
  "category_id": 1,
  "description": "optional",
  "is_split": false,
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

```json
{
  "name": "Groceries",
  "icon": "🛒",
  "is_income": false,
  "is_expense": true,
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
  "split_with_user_id": null,
  "split_percentage": null
}
```
`split_with_user_id` and `split_percentage` are optional. When provided, the payer's expense transaction is split with the specified family member (creates a pending `Debt` for their share).

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
