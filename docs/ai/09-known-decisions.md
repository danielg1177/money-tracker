# 09 — Known Decisions, Gaps & Areas Needing Verification

This document tracks intentional design decisions, known bugs, incomplete features, and areas where the current implementation is unclear.

---

## Confirmed Design Decisions

### Mobile-first UI as the primary client
The product is used **mainly on phones and mobile browsers**. All new or changed UI should assume **narrow viewports and touch** first; wider layouts are optional enhancement. Agents should read `docs/ai/03-frontend-vue.md` (§ Mobile-first UI) before substantial UI changes.

### No API versioning
All routes are in `web.php` under no version prefix. The app uses Laravel's web middleware stack (sessions, CSRF) rather than stateless API tokens. This is intentional for the current SPA-on-same-origin architecture.

### No Eloquent API Resources
Controllers return raw Eloquent model/collection JSON. This keeps code simpler but means the response shape is tightly coupled to the model's `$appends`, `$casts`, and eager-loaded relations.

### No global state management (Vuex/Pinia)
Each page fetches its own data on mount. `localStorage` is the only cross-component state mechanism (auth user). This is a deliberate simplicity choice at the cost of potential stale data and redundant API calls.

### Funds: personal vs family-scoped
**Personal** funds belong to one user (`funds.user_id`, `family_id` null) and are private to that user. **Family** funds set `funds.family_id` (creator still has `user_id`); all members of that family see them via `GET /funds`. The index lists personal rows only when `family_id` is null so a family fund is not returned twice (once as “personal” and once as family).

### Debt `balance` field is never auto-zeroed
Paid debts (balance = 0) remain in the database permanently. There is no "paid" boolean or deletion on full payment. The UI is expected to filter or display them accordingly.

### Inter-family debt original amount is hidden on debt page
For debts between two family members (`creditor_id` present), `resources/js/pages/Debts.vue` intentionally hides the **Original** amount on debt cards and in the payment-history summary line. The page emphasizes current remaining balance for these debts because split repayments can move amounts back and forth; full origin values still exist in debt history data (`initial_value` timeline entry).

### Debt history `initial_value` is principal-only
`DebtController::paymentHistory` computes the synthetic `initial_value` row from principal-only origin (`debt.amount - sum(debt.contributions)`). This keeps the starting amount stable when month-closeout split consolidations add new contribution entries to the debt.

### Closeout debt-payment transaction date policy
When closeout rules allocate to a debt, the generated debt-payment transaction date is:
- **today** if the hard-close month is the same month/year as the current date
- **month-end** (`endOfMonth`) for any non-current closeout month (including past months)

### Closeout-generated movement rows are informational and excluded from closeout basis math
Hard-close now creates closeout-tagged expense rows (`transactions.is_closeout_initiated=true`) for fund/debt allocations, and title-completion creates a similar row when marked done. `MonthCloseoutService` excludes these rows from expense-basis calculations during closeout so they cannot recursively alter same-run allocations.

### Gross-base fund rules net month advances for the “remaining” pool (preview + hard close)
When computing **remaining-after-gross** input for **`allocation_base='remaining'`** rules (and **`GET /month-summary` `rule_preview.basis`**), **gross/net-base** rules whose **`destination_type`** is **`fund`** contribute only **`max(0, nominal_allocation − advance expenses tagged to that fund before the rule in rule order)`** toward the amount subtracted from gross income alongside **`total_expenses`**. Advance-tagged expenses are already in **`total_expenses`**; this avoids double-counting the overlap. Debt and title destinations do not use advance netting. Nominal closeout allocations to funds (ledger movements) are unchanged.

### Remaining-base percentage closeout rules use a shared basis
For rules with `allocation_base='remaining'` and `allocation_type='percentage'`, each rule calculates from the same remaining-after-expenses basis (after gross/fixed deductions), not from a progressively reduced percentage base. Fixed remaining rules still consume the available pool in rule order.

### Debt interest accrues at closed month-end (not closeout run time)
For debts with `interest_enabled=true` and a configured `interest_rate`, hard-close applies interest using daily accrual (`APR / 365`) bounded by the closed month. In-month debt payments reduce interest from the payment date onward. `interest_last_applied_at` is set to the **closed month's last day**, so accrual timing remains tied to the closed month even if users close early/late in real time.

### Fund rules are not applied on income save
`TransactionService::createTransaction` and `updateTransaction` do **not** call `FundService::processIncome`. Fund rules (`FundRule`) are applied during **month hard-close** (`MonthCloseoutService`), not when posting income. `tests/Feature/FundAllocationTest.php` **passes** and documents the current contract: posting income leaves fund balances and `fund_movements` unchanged (including when multiple active rules exist, e.g. gross-base plus remaining-base); the same file also covers **`POST /funds/{id}/borrow`** (creates debt, reduces balance) and insufficient-balance borrow validation.

### Transaction updates do not re-trigger fund allocation
`TransactionService::updateTransaction` deletes and recreates splits and debts. Changing an income transaction's amount does not retroactively adjust fund balances through the transaction path.

### `net_income` allocation base behaves like `gross_income`
In `MonthCloseoutService::processUserCloseoutRules`, `net_income` rules are fetched in the same gross-rule query as `gross_income` rules (both use `allocation_base != 'remaining'`). Both use the same `$grossIncome` value because no net deductions are computed. `FundService::processIncome` is not invoked during month close. There is still no separate net-income basis in closeout; `net_income` is a planned distinction only.

### Debt repayment income/expense asymmetry is intentional (hybrid cash-flow model)
The app uses a deliberate hybrid model for debt transactions:

- **Debt payments made** (`type=expense, is_debt_payment=true`) **are included** in `calculateExpenseTotalTowardRemainingBasis` and therefore reduce the remaining pool available to savings rules. Rationale: the cash genuinely left the user's account and is no longer available for savings allocations.
- **Debt repayments received** (`type=income, is_debt_payment=true`) **are excluded** from `grossIncome` in `processUserCloseoutRules` and `getRulePreview`. Rationale: receiving a repayment is not new earned income — it converts an existing receivable (money already owed to the user) back into cash. Triggering savings rules on it would effectively double-allocate money that was already accounted for.

This is consistent with zero-based / YNAB-style budgeting: savings rules run on **earned income only**; debt obligations (manual payments or automated via closeout rules targeting a debt) are satisfied as cash-flow expenses before the remaining savings pool is calculated.

**Bank balance** tracks both sides as real cash movements (received repayments increase the balance; payments decrease it). Only the **closeout savings rule basis** treats received repayments as non-income.

**UI implication:** The income category panel and the "Gross Income" figure in Projected Closeout both exclude debt repayments received. The Month Summary "Debt Repayments" section is the authoritative display for those transactions.

### Session-based auth with CSRF (not API tokens)
Fortify provides session authentication. This means the app cannot be used as a pure API backend without significant changes. The CSRF token is embedded in the Blade shell and sent with every Axios request.

---

## Known Bugs

### Debts page naming inversion
In `resources/js/pages/Debts.vue`, the "You Owe" section uses `debts.owing` and the "Owed to You" section uses `debts.owed`. But in `DebtController::index`:
- `owed` = debts where auth user is the **debtor** (they owe money)
- `owing` = debts where auth user is the **creditor** (others owe them)

The Vue page has these **reversed**. This is a confirmed bug.

### `POST /admin/categories` route does not exist
`resources/js/pages/admin/Categories.vue` attempts to `POST /admin/categories` but this route is not defined in `web.php`. The admin categories page is non-functional for writes.

### Legacy `App.vue` is orphaned
`resources/js/components/App.vue` references routes and a component architecture from before the Vue Router migration. It is not imported anywhere. It contains dead references to `GET /admin/categories/{family_id}`. It should not be edited or relied upon.

---

## Known Authorization Gaps

### `CategoryController` has no policy
Any authenticated user can `PUT /categories/{category}` or `DELETE /categories/{category}` with any category ID, even one belonging to a different family. There is no `CategoryPolicy` and no ownership check.

### `DebtPolicy` is unused
`app/Policies/DebtPolicy.php` exists and checks family membership + debtor/creditor, but `DebtController` never calls `$this->authorize()`. Anyone in a family can view any debt in that family via the index endpoint.

### `TransactionController` allows same-family deletes
`TransactionController::destroy` allows deletion if `$transaction->family_id === $user->family_id` — any family member can delete another member's transaction.

---

## Incomplete Features

### 2FA
Fortify 2FA is scaffolded (migration, action redirect). No 2FA setup or verification UI exists in the Vue app.

### Admin Categories page
The route and Vue component exist but backend support for admin-specific category management is missing.

### `distributeEqually` in `SplitCalculator`
`SplitCalculator::distributeEqually` exists but is never called from any controller or service. It may have been intended for a "split equally" shortcut in the form.

### `StoreFundRequest`, `StoreFundRuleRequest`, `UpdateFundRuleRequest`, `CreateFamilyRequest`, `CreateUserRequest`
These Form Request classes exist in `app/Http/Requests/` but are not used by their respective controllers (which validate inline instead). They may be outdated or intended for a refactor.

---

## Needs Verification

- **Fortify `home` config:** `config/fortify.php` sets `home => '/home'`. The app navigates to `/dashboard`. It's unclear if this causes any redirect issue after login (Fortify's redirect after login may attempt `/home` which has no Laravel route).
- **`split_default` / `advance_fund_id` in `TransactionForm`:** When type is **expense** and the selected category has these fields set (and the category is expense-capable), the form enables split (**equal shares** across `familyUsers`, not the saved `split_default` percentages) and pre-populates advance fund when set. Income transactions ignore category defaults.
- **`FundMovement` factory:** No factory exists for `FundMovement`. Tests that need fund movement records must create them manually.
- **`TransactionSplit` factory:** No factory exists for `TransactionSplit`.
- **`DebtController::store` family validation:** The store endpoint checks both users are in the same family as the auth user. However, it doesn't prevent creating a debt where the auth user is neither debtor nor creditor.

---

## Files safe to ignore

- `resources/js/components/App.vue` — legacy, orphaned
- `resources/views/welcome.blade.php` — default Laravel welcome page, not in app flow
- `database/seeders/DatabaseSeeder.php` seeds one admin user and one family without factories/fake data (production-safe with `--no-dev`)
- `routes/console.php` — default `inspire` command and `Schedule::command('plaid:daily-sync')->dailyAt('02:00')` (requires a scheduler worker / cron calling `schedule:run`)
