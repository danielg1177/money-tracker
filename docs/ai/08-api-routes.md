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
| GET | `/settings` | `view('app')` (SPA shell — JSON requires auth) |

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
| GET | `/user` | inline closure | Returns `auth()->user()` as JSON (appends `closeout_mode` from the family, default `classic`); or SPA shell |
| PUT | `/user/settings` | `UserSettingsController::update` | Body `{ view_family_expenses: bool }`; returns refreshed user JSON |
| PUT | `/user/password` | Fortify | Body `{ current_password, password, password_confirmation }` |

### Transactions

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/transactions/repayable-expenses` | `TransactionController::repayableExpenses` | Auth user's unrepped expenses (`is_repaid=false`, not mirror/closeout-initiated/benefit); optional `?start_date=&end_date=`; includes `category`; empty array when no `family_id` |
| GET | `/transactions` | `TransactionController::index` | Accepts `?start_date=&end_date=` filters and optional `?view=family` (all family rows; default remains viewer-scoped for Dashboard). JSON includes `debt` (with `creditor`, `debtor`, `fund` when present) for debt-payment rows, `debt_payment_benefit_expense` on creditor repayment income, `debt_payment_income` on benefit expenses, `advanceFund` when `advance_fund_id` is set, `plaid_pending_import.plaid_item` (null for non-Plaid rows; `institution_name` used in Transactions page badge); omits split debt-payment **expense** for the creditor when that row duplicates their repayment **income** for the same debt |
| POST | `/transactions` | `TransactionController::store` | Body: see `StoreTransactionRequest`; rejects `422` when the family month is hard-closed or the **owner** has soft-closed the month (split co-participant soft closes do not block) |
| PUT | `/transactions/{transaction}` | `TransactionController::update` | Same body as store; 403 unless owner **or** same family; debt-payment **expense** rows can be edited (recalculates debt balance + mirrored income), debt-payment **income** mirror rows are rejected; benefit expenses (`is_debt_payment_benefit`) must use the benefit endpoints; rejects `422` when the existing row month or target payload month is closed (soft-close is the **owner’s** month). Transactions UI: own rows, plus **`can_manage_family`** on other members’ unlocked rows. |
| DELETE | `/transactions/{transaction}` | `TransactionController::destroy` | Same family-or-owner check as update; deletes transaction; debt-payment pairs cascade (including any creditor benefit expense); rejects `422` when month is closed |
| POST | `/transactions/{transaction}/debt-payment-benefit` | `TransactionController::storeDebtPaymentBenefit` | `{transaction}` = creditor debt-payment **income**; body: `StoreDebtPaymentBenefitRequest` (`category_id` required expense category, optional `description`, `is_split`/`split_data`, `advance_fund_id`, `exclude_from_expense_basis`, `is_necessity`); amount/date from income; `201` benefit expense; `403` if not owner; `422` if already exists / not debt-payment income / closed month |
| PUT | `/transactions/{transaction}/debt-payment-benefit` | `TransactionController::updateDebtPaymentBenefit` | Same body as store; updates existing benefit; `422` if missing |
| DELETE | `/transactions/{transaction}/debt-payment-benefit` | `TransactionController::destroyDebtPaymentBenefit` | Removes benefit only; debt-payment pair unchanged; `204` |

### Plaid (bank connections)

Requires `PLAID_CLIENT_ID` + `PLAID_SECRET` in the environment. Link tokens use product `transactions` and `country_codes` `US`. Sync writes `plaid_pending_imports` for **posted** Plaid rows only (`pending: true` is skipped until the bank settles) and may auto-create `transactions` when merchant rules qualify; see `docs/ai/02-backend-laravel.md` (`PlaidTransactionSyncService`). Deduping is by exact Plaid `transaction_id`; leftover confirms of still-pending charges can still re-queue when they post (see `docs/ai/09-known-decisions.md`).

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/plaid/link-token` | `PlaidController::linkToken` | JSON `{link_token}`; Plaid `/link/token/create` includes `financekit_supported` when `PLAID_FINANCEKIT_SUPPORTED` / `config('plaid.financekit_supported')` is true (default); `503` when Plaid env incomplete |
| POST | `/plaid/exchange` | `PlaidController::exchange` | Body `{public_token}` from Link `onSuccess`; stores encrypted access token on `plaid_items`, hydrates institution metadata, runs initial `/transactions/sync` pull; `201` with `{item, pull}` where `pull` contains `counts`, `added`, `modified`, `removed`, `accounts` |
| GET | `/plaid/items` | `PlaidController::items` | Lists auth user’s linked items (no secrets) |
| POST | `/plaid/sync-month` | `PlaidImportController::syncAllMonths` | Current calendar month for **every** auth user `PlaidItem`; same `fetchByDateRange` + `ingestPlaidRowsAsPending` as per-item sync; JSON `{ items_synced, pending_created, auto_created, failed_items[] }` (`failed_items` entries include `id`, `institution_name`, `message`); `502` when the user has items but every sync failed |
| POST | `/plaid/sync-last-month` | `PlaidImportController::syncAllLastMonth` | Previous calendar month for every auth user `PlaidItem`; same response shape as `/plaid/sync-month` |
| GET | `/plaid/pending-imports` | `PlaidImportController::index` | Default JSON `{ pending, transfers, auto_created, dismissed, manually_dismissed, recently_confirmed }` (`pending` / `transfers` split by `is_transfer` among `status=pending`; each row includes `suggested_sweep_match_id` and `sweep_match_score` when sync found a sweep candidate; **`auto_created`** = `status` in `auto_created` \| `auto_linked` **and** `reviewed_at` null — eager-loads `suggestedCategory`, `plaidItem`, **`transaction`** (full On your books bundle), and **`suggestedLedgerMatch.category`**; `pending` / `transfers` also load `suggestedLedgerMatch` for match banners; **`dismissed`** = `status=dismissed`, `dismiss_source=auto`, `reviewed_at` null; **`manually_dismissed`** = `status=dismissed`, `dismiss_source=manual`, `reviewed_at` null, ordered by `updated_at` desc, each row includes **`has_learned_dismiss_rule`** when a `plaid_merchant_rules` row exists with `action=dismiss` for that merchant key; **`recently_confirmed`** = `status=confirmed`, `transaction_id` not null, `updated_at` within last 30 days, ordered by `updated_at` desc, eager-loads `plaidItem`, `transaction.category`, `transaction.debt.creditor` / `debtor`). With `?count_only=1`, JSON `{ count, auto_created_count, dismissed_count }` — `auto_created_count` includes unreviewed `auto_linked` rows |
| GET | `/plaid/pending-imports/{pendingImport}/ledger-candidates` | `PlaidImportController::ledgerLinkCandidates` | JSON `{ candidates: [...] }` — ledger rows for the **same user** as the pending import (`transactions.user_id` = importer), same `family_id`, no `plaid_transaction_id`, same type and amount (±0.01) within ±45 days, scored by name/description similarity; non-transfer pending only; empty when user has no `family_id` |
| GET | `/plaid/pending-imports/{pendingImport}/linked-transactions` | `PlaidImportController::linkedTransactions` | Owner-only; JSON `{ import: { id, status, amount, date, merchant_name, raw_name }, transactions: [...] }` — all ledger rows with `plaid_pending_import_id` = this import (category + debt relations eager-loaded); used by Transactions page bank pill modal |
| GET | `/plaid/pending-imports/{pendingImport}/split-link-candidates` | `PlaidImportController::splitLinkCandidates` | Query `?amount=` (required positive number); owner-only; pending only; JSON `{ candidates: [...] }` — up to 30 ledger rows for the **same user** as the pending import, same `family_id`, no `plaid_transaction_id` / `plaid_pending_import_id`, not `is_closeout_initiated`, amount within ±$0.01 of query, `transaction_date` within ±45 days of import date; each item `{ id, transaction_date, amount, description, type, category, is_debt_payment, is_repayment_mirror }`; `[]` when user has no `family_id` |
| POST | `/plaid/pending-imports/{pendingImport}/link` | `PlaidImportController::linkToLedger` | Body: `LinkPlaidPendingImportRequest` (`transaction_id` must exist in the user’s **family** and be **owned by the authenticated user**); links `plaid_transaction_id` on the chosen `Transaction`, `learnFromConfirmation` from pending merchant + ledger category/type/funds, marks pending `confirmed` + `transaction_id`; does **not** run `ClosedMonthGuard` (metadata-only link); `422` on mismatch (amount/type/60-day window / duplicate Plaid id / transfer row / wrong owner) |
| GET | `/plaid/pending-imports/{pendingImport}/sweep-candidates` | `PlaidImportController::sweepCandidates` | JSON array (max 30) of unlinked `savings_sweep` `FundMovement` candidates for the import owner’s family (`funds.family_id` or movement `user_id` in family) — `{ id, amount, description, date, fund_name }`, no date filter, ordered by `created_at` proximity to import date; `[]` when user has no `family_id`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/link-to-sweep` | `PlaidImportController::linkToSweep` | Body: `{ fund_movement_id: int }` (required, exists); owner-only; `status` must be `pending` or `auto_linked`; links Plaid id on the sweep movement, pending `confirmed` + `fund_movement_id`; `422` if wrong type / already linked; `403` if movement family (fund `family_id` or movement user’s `family_id`) does not match import owner’s family |
| POST | `/plaid/pending-imports/{pendingImport}/confirm` | `PlaidImportController::confirm` | Body: `StoreImportConfirmRequest` — same transaction-shaped fields as `POST /transactions` where applicable: `category_id`, `type`, optional `fund_id` (still accepted for callers; SPA import review uses **advance only**), `description`, `is_split` + `split_data`, expense `debt_id` (pay toward debt) **or** `transfer_to_user_id` (send to a family member; mutually exclusive with `debt_id`), `advance_fund_id` + `exclude_from_expense_basis` + `is_necessity`, income `income_debt_mode` / `income_existing_debt_id` / new-debt fields; amount and date are taken from the pending row server-side. Creates via `TransactionService::createTransaction`, sets `plaid_transaction_id` + `import_source=plaid`. When `fund_id` is omitted, **`transactions.fund_id` and merchant-rule `fund_id` mirror `advance_fund_id`** for expenses (same tagging pattern as auto-created Plaid rows). `learnFromConfirmation` (includes `is_split`; family transfers do **not** learn `is_debt_payment` / `debt_id`); `422` if not pending / closed month / validation |
| POST | `/plaid/pending-imports/{pendingImport}/confirm-split` | `PlaidImportController::confirmSplit` | Body: `ConfirmSplitImportRequest` — `lines` (min 2) each with `amount`, `type`, and either full create fields (`category_id`, splits, debt, etc.) **or** `link_to_transaction_id` to attach an existing unlinked ledger row (same user, not already `plaid_pending_import_id`); line amounts must sum to import total; creates new rows via `TransactionService` and/or links existing rows with `plaid_pending_import_id` + `import_source=plaid` (first line/linked row gets `plaid_transaction_id`); pending `confirmed` + `transaction_id` = first line’s transaction; `ClosedMonthGuard` on create payloads and linked rows |
| POST | `/plaid/pending-imports/{pendingImport}/dismiss` | `PlaidImportController::dismiss` | Sets `status=dismissed`, `dismiss_source=manual`, `recordSeen` on merchant rule when present; `204`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/dismiss-as-transfer` | `PlaidImportController::dismissAsTransfer` | Sets `status=dismissed`, `dismiss_source=manual`; optional `?learn=true` runs `learnDismissRule` (`plaid_merchant_rules.action=dismiss`, `total_seen_count` only); `204`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/undo-dismiss` | `PlaidImportController::undoDismiss` | Owner-only; requires `status=dismissed` and `dismiss_source=manual`; sets `status=pending`, `dismiss_source=null`, calls `deleteDismissRule` for merchant key when `action=dismiss` rule exists; JSON `{ success: true, pending_import }`; `422` otherwise |
| POST | `/plaid/pending-imports/{pendingImport}/undo-confirm` | `PlaidImportController::undoConfirm` | Owner-only; requires `status=confirmed` with linked `transaction`; `ClosedMonthGuard` on primary and all other rows with same `plaid_pending_import_id`; deletes primary + any secondary rows **created with** the import (`created_at >= import.created_at`); **unlinks** pre-existing secondaries (clears `plaid_transaction_id`, `import_source`, `plaid_pending_import_id`); resets import to `status=pending` with `transaction_id` null; JSON `{ success: true, pending_import }`; `422` if not confirmed / no transaction / closed month |
| POST | `/plaid/pending-imports/{pendingImport}/approve-auto-created` | `PlaidImportController::approveAutoCreated` | Approves an `auto_created` row, reinforces the merchant rule via `learnFromConfirmation`, sets **`reviewed_at`** so the row drops out of `GET /plaid/pending-imports` **auto_created**; `204`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/approve-auto-linked` | `PlaidImportController::approveAutoLinked` | Approves an `auto_linked` row (sync matched an existing ledger transaction), reinforces merchant rule from linked transaction, sets **`reviewed_at`**; `204`; owner-only; `422` if not `auto_linked` |
| POST | `/plaid/pending-imports/{pendingImport}/reject-auto-linked` | `PlaidImportController::rejectAutoLinked` | Undoes auto-link: clears ledger `plaid_transaction_id` / `import_source` / `plaid_pending_import_id`, resets pending to `status=pending` with `transaction_id` null; `204`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/correct-auto-created` | `PlaidImportController::correctAutoCreated` | Updates the linked transaction's `category_id`, `type`, `fund_id`, `advance_fund_id`, `exclude_from_expense_basis`, `is_necessity` and retrains the merchant rule; sets **`reviewed_at`** so the row drops out of **auto_created**; body: `{ category_id, type, fund_id?, advance_fund_id?, exclude_from_expense_basis?, is_necessity? }`; returns updated transaction JSON; `422` if not `auto_created` or no linked transaction |
| POST | `/plaid/pending-imports/{pendingImport}/acknowledge-auto-dismiss` | `PlaidImportController::acknowledgeAutoDismiss` | Confirms an auto-dismissed row was correctly ignored: calls `recordSeen` on the merchant rule (increments `total_seen_count`) and sets `reviewed_at`; removes row from `dismissed` queue; `204`; owner-only |
| POST | `/plaid/pending-imports/{pendingImport}/restore-from-dismiss` | `PlaidImportController::restoreFromDismiss` | Creates a ledger transaction from an auto-dismissed row, retrains the merchant rule to `action=categorize` via `learnFromConfirmation`, marks pending import `confirmed`; body: `{ category_id, type, fund_id?, advance_fund_id?, exclude_from_expense_basis?, is_necessity?, description? }`; returns transaction JSON; `422` if not auto-dismissed or closed month |
| GET | `/plaid/items/{plaidItem}/calibrate` | `PlaidImportController::calibrationData` | JSON from `PlaidCalibrationService::buildCalibrationMatches`; ledger sides are slim `{id, date, amount, description, type, fund_id, category}` |
| POST | `/plaid/items/{plaidItem}/calibrate` | `PlaidImportController::applyCalibration` | Body: `ApplyPlaidCalibrationRequest` (`confirmed_pairs[]`, `import_as_new[]`); `PlaidCalibrationService::applyCalibrationResults`; JSON `{ confirmed_linked, imported_pending }` |
| POST | `/plaid/items/{plaidItem}/sync-month` | `PlaidImportController::syncMonth` | Current calendar month; `fetchByDateRange` + `ingestPlaidRowsAsPending` (same path as sync `added`); JSON `{ pending_created, auto_created }` or `502` on Plaid failure |
| POST | `/plaid/items/{plaidItem}/sync-last-month` | `PlaidImportController::syncLastMonth` | Previous calendar month; same ingest path and JSON as `sync-month` |
| POST | `/plaid/items/{plaidItem}/sync` | `PlaidController::sync` | Same as exchange pull; JSON `{pull: …}` |
| DELETE | `/plaid/items/{plaidItem}` | `PlaidController::destroy` | Calls Plaid `/item/remove`, deletes local `plaid_items` row |

### Funds

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/funds` | `FundController::index` | Personal funds: auth user’s funds with `family_id` null. Family funds: all funds with `family_id` = user’s family. Merged with `scope` each (`personal` \| `family`); family rows are omitted from the personal query so the creator does not see duplicates. Each row also includes `has_remaining_percentage_rule` (auth user has active `destination_type='fund'` + `allocation_type='percentage'` + `allocation_base='remaining'` rule for that fund id). Each `movements[]` row includes nested `user` (`name`, etc.) for who recorded the movement |
| POST | `/funds` | `FundController::store` | `{name, description?, is_family_fund?}`; if `is_family_fund=true` and user has `family_id`, fund is family-scoped |
| PUT | `/funds/{fund}` | `FundController::update` | `{name, description?}`; `FundPolicy::update` — owner, **or** any same-family member when the fund is family-scoped |
| DELETE | `/funds/{fund}` | `FundController::destroy` | Requires fund ownership (personal) or family membership with `can_manage_family` (family-scoped). Then `$fund->delete()` with no child detach. **500** on Railway when any `transactions.advance_fund_id` still references the fund (FK restrict; see `docs/ai/09-known-decisions.md`). Success JSON `{message: 'Fund deleted'}` |
| GET | `/funds/{fund}/rules` | `FundController::showRules` | **Backward compatibility:** returns the same JSON as `GET /closeout-rules` (all of the auth user’s rules, not scoped to `{fund}`) |
| POST | `/funds/{fund}/borrow` | `FundController::borrow` | `{amount, description?}`; requires fund ownership or family membership; rejects `422` when the current month is closed for the user |
| POST | `/funds/{fund}/sweep` | `FundController::sweep` | **Body:** `{ amount: number` (required, min 0.01, max = fund balance), `description?: string }`. **Auth:** required (`FundPolicy::update` — fund owner or family member with access). **Effect:** decrements `fund.balance` by `amount`, creates `FundMovement` (`type=savings_sweep`). No `Transaction` created. No month-close guard. **Response:** `201` — `FundMovement` with `user` relationship. **Errors:** `422` if amount > balance or validation fails; `403` if not authorized for the fund |
| POST | `/funds/{fund}/override` | `FundController::overrideBalance` | **Body:** `{ balance: number` (required), `description?: string }`. **Auth:** `FundPolicy::update`. **Effect:** sets `fund.balance` to `balance`, creates `FundMovement` (`type=manual_override`, signed delta, description `Set to $X.XX` plus optional note). No `Transaction`. No month-close guard. Hidden from Month Summary Fund In/Out. **Response:** `201` — `FundMovement` with `user`. **Errors:** `422` if balance unchanged or validation fails; `403` if not authorized |

### Closeout rules (`FundRule` — month hard-close allocations)

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/closeout-rules` | `FundController::showRules` | JSON: auth user’s `FundRule` rows ordered by `order` |
| POST | `/closeout-rules` | `FundController::storeRule` | `{name, order, allocation_type, amount, allocation_base?, is_active?, destination_type, destination_id?, destination_title?, fund_id?, closeout_expense_category_id?}` (`closeout_expense_category_id` must be an expense category in the user’s family). **Title** rules (`destination_type=title`): **`destination_title`** must be unique per user among **active** title rules (`422` on duplicate). |
| PUT | `/closeout-rules/{fundRule}` | `FundController::updateRule` | Same body as POST; `{fundRule}` must belong to auth user (same **`destination_title`** uniqueness among active title rules, excluding this row) |
| DELETE | `/closeout-rules/{fundRule}` | `FundController::destroyRule` | — |

### Family closeout (`families.closeout_mode` + `FamilyCloseoutRule`)

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/family/closeout-settings` | `FamilyCloseoutSettingsController::show` | Family members; JSON `{closeout_mode, can_manage, family_rules}` |
| PUT | `/family/closeout-settings` | `FamilyCloseoutSettingsController::update` | Requires `can_manage_family`; `{closeout_mode: classic\|family_pooled}`; applies to **open months only** |
| GET | `/family/closeout-rules` | `FamilyCloseoutRuleController::index` | Family members; ordered family rules |
| POST | `/family/closeout-rules` | `FamilyCloseoutRuleController::store` | Requires `can_manage_family`; `{name, order, is_active?, stage: surplus\|remaining_after_charity, allocation_type, amount, destination_type, destination_id?, destination_title?, closeout_expense_category_id?}` |
| PUT | `/family/closeout-rules/{familyCloseoutRule}` | `FamilyCloseoutRuleController::update` | Requires `can_manage_family`; same family |
| DELETE | `/family/closeout-rules/{familyCloseoutRule}` | `FamilyCloseoutRuleController::destroy` | Requires `can_manage_family`; same family |

### Debts

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/debts` | `DebtController::index` | Returns `{owed: [...], owing: [...], family_debts: [...]}` |
| GET | `/split-debt-summary` | `DebtController::splitDebtSummary` | Query: `year`, `month` (1–12). JSON: pending closeout split debts grouped by counterpart; each nested `transaction` includes `category` and, when applicable, `debt` with `creditor`, `debtor`, `fund` for debt-payment rows |
| POST | `/debts` | `DebtController::store` | `{is_family_debt?, is_interfamily?, creditor_id?, creditor_name?, amount, description?, interest_enabled?, interest_rate?, loan_received_date?}` |
| PUT | `/debts/{debt}` | `DebtController::update` | Updates debt fields (`description`, `creditor_name`, `interest_enabled`, `interest_rate`, `loan_received_date`) |
| POST | `/debts/pay` | `DebtController::payDebt` | `{debt_id, amount, description?, transaction_date?, split_with_user_id?, split_percentage?}`; rejects `422` when the payment month is hard-closed or the **payer** has soft-closed (creditor / split co-participant soft closes do not block) |
| GET | `/debts/{debt}/payments` | `DebtController::paymentHistory` | Debtor, creditor, or `can_manage_family`; JSON `{ entries, contributions, remaining, remaining_debtor_id, remaining_creditor_id, remaining_debtor_name, remaining_creditor_name }`. Creditor sees income rows for debts where they are creditor, others see expense rows. Inter-family running debts include **overpayment reversal lineage**. Rows include `flow_kind`, `flow_from_*` / `flow_to_*`, and `is_direction_reversal`; `split_breakdown` when a payment was split |
| DELETE | `/debts/{debt}` | `DebtController::destroy` | Only debtor or `can_manage_family` user can delete |
| POST | `/debts/{debt}/repay-fund` | `FundController::repayFund` | `{amount}`; only for fund debts; rejects `422` when the current month is closed for the user |


### Month Closeout

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/closeout/status` | `MonthCloseoutController::status` | Body: `{year, month}`; JSON: `{soft_closes, hard_close, all_soft_closed, family_user_count}` |
| POST | `/closeout/soft-close` | `MonthCloseoutController::softClose` | `{year, month}`; auto-hard-closes if family has only one member; returns `{message, data (soft_close), hard_close?, auto_hard_closed?}` |
| POST | `/closeout/undo-soft-close` | `MonthCloseoutController::undoSoftClose` | `{year, month}`; undoes soft close (must have no hard close) |
| POST | `/closeout/hard-close` | `MonthCloseoutController::hardClose` | `{year, month}`; requires `can_manage_family`; runs the family’s current closeout engine, consolidates pending split debts, applies eligible debt interest through the closed month-end date, and writes `MonthHardClose` snapshots |
| POST | `/closeout/undo-hard-close` | `MonthCloseoutController::undoHardClose` | Undo a hard close; reverts all closeout artifacts. Requires auth + `can_manage_family`. Body: `{year, month}`. Returns `{message}`. |
| GET | `/closeout/closed-months` | `MonthCloseoutController::closedMonths` | JSON: array of hard-closed months for family as `{year, month, closeout_mode}` (`closeout_mode` is the mode stored at hard close) |

### Family members

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/family/users` | inline closure | Returns users in auth user's family |

### Categories

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/categories` | `CategoryController::index` | Returns family categories with shared `is_necessity_default` plus caller-specific `advance_fund_id` + `exclude_from_expense_basis_default` |
| POST | `/categories` | `CategoryController::store` | See `StoreCategoryRequest` (exactly one of `is_income` / `is_expense` must be true). After save, copies family necessity onto every member's Plaid merchant rules for that category; copies the saver's personal advance / remaining-exclusion onto the saver's rules only |
| POST | `/categories/sync-plaid-rules` | `CategoryController::syncPlaidMerchantRules` | No body. Requires `family_id`. Copies all of the auth user's expense-category defaults onto their merchant rules / open pending suggestions / unreviewed auto-created rows. Returns `{merchant_rules, pending_imports, auto_created_transactions}` |
| PUT | `/categories/{category}` | `CategoryController::update` | See `StoreCategoryRequest` (same XOR rule). Same family Plaid sync as POST |
| DELETE | `/categories/{category}` | `CategoryController::destroy` | — |

### Dashboard

| Method | Path | Controller | Notes |
|---|---|---|---|
| GET | `/dashboard/monthly-totals` | `DashboardController::monthlyTotals` | Returns `{total_income, total_expenses}` for current month, auth user only; expenses exclude `is_debt_payment` and `is_closeout_initiated` |

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
| GET | `/month-summary` | `MonthSummaryController::show` | Query: `year`, `month`. Returns `{year, month, is_hard_closed, close_status, category_totals, category_transactions, member_balances, rule_preview, closeout_preview, fund_advance_transactions, fund_movements, debt_repayments, title_savings}` plus **`family_category_totals`** / **`family_category_transactions`** when the user’s **`view_family_expenses`** preference is on (household category overlay; splits counted once at full amount; **inter-member debt payments** omitted from family expense totals, still included in viewer **`category_totals`**). **`closeout_preview`**: `{mode, source: live\|snapshot\|reconstructed, family}` — open months dry-run the current `closeout_mode`; hard-closed months read **`results_snapshot`** (or reconstruct amounts from ledger artifacts). **`category_totals`** is **scoped to the authenticated user** (not full-family). Viewer **income** rows **exclude `is_borrow`** (fund borrow withdrawals align with **`rule_preview.basis.gross_income`**; see **`fund_movements`**). Solo **non–debt-payment** expense rows **exclude `is_closeout_initiated`** (hard-close ledger lines do not inflate **Your expenses** totals; see **`fund_movements`** / **`debt_repayments`**). Categorized **debt repayment** expenses merge into that category; uncategorized repayments aggregate to synthetic **Uncategorized Debt Payments** (`category_id=-1`). **`member_balances`**: net split-expense IOUs for **`is_split` expenses in that month, including split debt repayments and excluding `is_closeout_initiated`**; only non-zero nets appear. Each row also includes creator-source breakdown and history arrays: `from_you_created_amount`, `from_them_created_amount`, `from_you_created_transactions[]`, `from_them_created_transactions[]` (each history row has `transaction_id`, `transaction_date`, `category_name`, `category_icon`, `description`, `total_amount`, `balance_amount`). **`rule_preview.basis.total_expenses`** matches **`MonthCloseoutService::expenseTotalTowardRemainingBasis`** on live classic previews (includes those repayments; excludes `is_closeout_initiated` / `is_borrow` legs and remaining-exclusion advances). **`rule_preview.basis.expense_basis_exclusions`** (aliased as **`non_necessity_expenses`**) separately reports month expense totals where `exclude_from_expense_basis=true` + `advance_fund_id` set. **`rule_preview.basis`** also includes **`gross_allocations_total`** (amount subtracted from gross for remaining; **fund**-target gross rules net month **advances** to that fund so they are not double-counted with **`total_expenses`**) and a **signed** **`remaining_after_expenses`**. **`rule_preview.expense_closeout_basis.lines`** is a short list describing that expense basis. `title_savings` is populated only for hard-closed months and includes `{id, title, amount, is_completed, completed_at}` rows for the authenticated user. **`fund_advance_transactions`** maps fund id → advance-tagged expense rows for the viewer in that month (same scope as **`MonthCloseoutService::fundAdvanceOutstandingByFundForUserMonth`**). **`rule_preview.rules[]`** includes **`destination_id`**, **`fund_advance_outstanding_before`**, and **`net_after_advances`** for fund allocations (subtracts month's advance-tagged expenses to that fund, rule-order; **`net_after_advances` may be negative**). **`destination_type=debt`** rows expose **nominal** **`projected_amount`** and **`net_after_advances`** equal to the **capped** paydown (**`MonthSummary`** shows **`rulePreviewNet()`**, which prefers **`net_after_advances`**—users see capped dollars). **`debt_repayments.paid`** uses each viewer's split share for split debt repayments (and lists co-payers on those expenses). When **`view_family_expenses`** is on, **`debt_repayments.family_debt_paid`** lists family-shared debts with **`you_amount`** / **`family_amount`**. Requires `family_id` (403 if unset). All read-only. |

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
For `type=income`, `advance_fund_id`, `is_split`, `split_data`, and expense-side `debt_id` / `transfer_to_user_id` are cleared server-side before validation (income does not support expense split/advance/debt-payment flow). Income can optionally link debt through `income_debt_mode`, link **family expense repayments** via `is_repayment_mode`, `repayment_for_user_id` (another family member paying the auth user back), and `repayment_links`, or link **external reimbursements** via `is_external_repayment_mode` and `repayment_links` (auth user's own unrepped expenses; no `repayment_for_user_id`). `repayment_links` must sum to income `amount`. Non-income requests force repayment modes off.

For `type=expense`, optional **`debt_id`** (existing `debts.id` for the payer’s family) records a categorized debt repayment: creates/expands the same flow as `DebtService::payDebt` for simple (non-split) payments — reduces `debts.balance`, emits mirrored **`is_debt_payment` creditor income** when `creditor_id` is set. Optional create-only **`transfer_to_user_id`** (another same-family member) is mutually exclusive with `debt_id`; the server find-or-creates a payable in-family running debt, then runs the same repayment expense path. **`debt_id` / `transfer_to_user_id` clear advance fund** (`prepareForValidation`); **split remains allowed**.

`exclude_from_expense_basis` is a boolean transaction flag. It is force-normalized to `false` unless the request is an **expense**, has an **`advance_fund_id`**, is **not split**, and is **not a debt payment** (`debt_id` or `transfer_to_user_id`). When sent as `true`, validation also requires an active auth-user closeout rule targeting that same advance fund (`destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'`). Persistence is **classic-only**: family pooled creates store `false`; updates keep an existing qualifying flag. `is_necessity` is a free boolean on expenses (default true; forced true for income) and is used only for family-pooled charity (`income − necessities`).

```json
{
  "type": "income|expense",
  "amount": 100.00,
  "transaction_date": "2026-05-03",
  "category_id": 1,
  "description": "optional",
  "is_split": false,
  "exclude_from_expense_basis": false,
  "is_necessity": true,
  "split_data": [
    {"user_id": 1, "share_percentage": 60},
    {"user_id": 2, "share_percentage": 40}
  ],
  "debt_id": null,
  "transfer_to_user_id": null,
  "income_debt_mode": "none|existing|new|receipt",
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
- `existing`: increase selected debt `balance` only, append `debts.income_additions` (amount + date + `transaction_id` after create), link transaction to that debt (`transactions.debt_id`); `debt.amount` (original principal) unchanged
- `receipt`: link income to an existing debt as proof of bank receipt (`transactions.debt_id`, `is_loan_receipt=true`); does not change `debt.amount` or `debt.balance`
- `new`: create a new debt from this income amount (external name or interfamily creditor) and link it

### `StoreCategoryRequest`
When `is_expense` is false, `is_split_default`, `split_default`, and `advance_fund_id` are cleared server-side before validation.

`exclude_from_expense_basis_default` is a boolean category flag on the request, but persistence is per-user-per-category (`category_user_defaults`), not on the shared category row. It is force-normalized to `false` unless the category is an expense and has `advance_fund_id`. When sent as `true`, validation requires an active auth-user closeout rule targeting that same advance fund (`destination_type='fund'`, `allocation_type='percentage'`, `allocation_base='remaining'`). `is_necessity_default` is a free boolean for expense categories (default true) and is stored on the shared `categories` row.

```json
{
  "name": "Groceries",
  "icon": "🛒",
  "is_income": false,
  "is_expense": true,
  "exclude_from_expense_basis_default": false,
  "is_necessity_default": true,
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

### `UpdateFamilyCloseoutSettingsRequest`
Requires `can_manage_family`. `{ "closeout_mode": "classic" | "family_pooled" }`.

### `StoreFamilyCloseoutRuleRequest` / `UpdateFamilyCloseoutRuleRequest`
Requires `can_manage_family` and a family. `{name, order, is_active?, stage: surplus|remaining_after_charity, allocation_type, amount, destination_type, destination_id?, destination_title?, closeout_expense_category_id?}`. Title destinations require `destination_title`.

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
