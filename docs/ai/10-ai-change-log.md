# 10 — AI Change Log

This file records all significant changes made by AI agents. Add an entry at the top after each session that modifies the codebase.

Format:
```
## YYYY-MM-DD — [Feature/Change Summary]
- Files touched: ...
- Behavioral impact: ...
```

---

## 2026-05-04 — Separate admin role from family roles system
- Files touched: `database/migrations/2026_05_04_013436_add_is_admin_to_users_table.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/AdminController.php`, `resources/js/pages/admin/Users.vue`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) **User roles now: `member` | `head_of_household` only** — no more `admin` role. (2) **New `is_admin` boolean column** on `users` table (default false); existing `admin` role users migrated to `head_of_household` + `is_admin=true`. (3) `User.isAdmin` accessor now reads the DB column instead of checking `role === 'admin'`. (4) `User.canManageFamily` accessor checks `role === 'head_of_household' OR is_admin`. (5) Gate `admin` now checks `user->is_admin` (boolean). Gate `manage_family` checks `role === 'head_of_household' OR is_admin`. (6) `AdminController::createUser` and `::updateUser` validation now only allow `member` and `head_of_household` roles; added `is_admin` boolean validation and handling. (7) Vue component `resources/js/pages/admin/Users.vue` now shows **separate role select** (member/head_of_household) and **separate admin checkbox** with clear label "System Admin — Can manage all users and families"; user display badges show role and admin status separately. (8) API responses continue to include `is_admin` appended attribute for frontend.

## 2026-05-04 — Auto-hard-close for single-member families on soft close
- Files touched: `app/Services/MonthCloseoutService.php`, `app/Http/Controllers/MonthCloseoutController.php`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) `MonthCloseoutService::softClose()` now returns `array{soft_close: MonthSoftClose, hard_close: MonthHardClose|null}` instead of `MonthSoftClose`. (2) When a family has only one member, `softClose()` automatically triggers `hardClose()` immediately (no one else to wait for). (3) `MonthCloseoutController::softClose()` handles the array return and includes `hard_close` and `auto_hard_closed=true` in the response JSON when auto-hard-close occurs. (4) API route `POST /closeout/soft-close` now returns `{message, data (soft_close), hard_close?, auto_hard_closed?}` instead of just `{message, data (soft_close)}`. (5) Single-member families see their month as fully closed (hard-close) immediately after clicking "Close Out", skipping the multi-member approval workflow.

## 2026-05-04 — Debt system overhaul: Three debt types (personal/external, in-family, family-shared)
- Files touched: `database/migrations/2026_05_04_010914_add_debt_scope_fields_to_debts_table.php`, `app/Models/Debt.php`, `app/Http/Controllers/DebtController.php`, `app/Services/DebtService.php`, `routes/web.php`, `docs/ai/02-backend-laravel.md`, `docs/ai/04-database.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) New migration adds `is_family_debt` (bool, default false) and `creditor_name` (varchar nullable) to `debts` table. (2) `DebtController::store` now supports three debt types: **personal to external** (`creditor_name` provided, no `creditor_id`), **in-family** (`creditor_id` = different family member, `is_interfamily=true`), and **family-shared** (`is_family_debt=true`, visible to all family members). Auth user is always the debtor. (3) `DebtController::index` now returns `{owed, owing, family_debts}` with `family_debts` showing debts visible to all. (4) New `DebtController::destroy` route allows debtor or family manager to delete debts. (5) `DebtService::payDebt` updated to allow any family member to pay family debts, vs. only debtor for personal debts. (6) Routes: added `DELETE /debts/{debt}` route, added `Route::view('/debts', 'app')` for SPA shell.

## 2026-05-03 — Closeout rules PUT/POST: fix 500 (`Request::validated` on plain Request)
- Files touched: `app/Http/Controllers/FundController.php`, `database/migrations/2026_05_03_160512_update_fund_rules_for_closeout_system.php`, `tests/Feature/CloseoutRulesApiTest.php`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: `storeRule` / `updateRule` now use the array returned by `$request->validate(...)` instead of `$request->validated()`, which is not available on `Illuminate\Http\Request` in this Laravel version and caused HTTP 500 on `POST`/`PUT /closeout-rules`. The `fund_rules` closeout migration is split into two schema steps (add destination columns, then nullable `fund_id`) for better cross-driver behavior. API docs updated for `/closeout-rules`. AI docs corrected: fund rules run on hard-close, not on each income transaction; `FundAllocationTest` noted as out of sync.

## 2026-05-03 — Debts: Filter pending split debts, consolidate on hard-close
- Files touched: `app/Http/Controllers/DebtController.php`, `app/Services/MonthCloseoutService.php`, `app/Services/DebtService.php`, `docs/ai/06-feature-map.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: (1) Debts page (`GET /debts`) now filters out `is_pending_closeout=true` debts, showing only manually-added debts and confirmed debts from past months; (2) On hard-close, pending split debts are netted per person-pair, consolidated into single running debts, and replace previous period entries (or create new ones if no existing debt); dozens of small split records become one summary debt per pair; (3) `DebtService::payDebt` now rejects attempts to pay pending split debts with an error message directing users to wait for month hard-close.

## 2026-05-03 — useApi: expose `delete` alias for HTTP DELETE
- Files touched: `resources/js/composables/useApi.js`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Pages that destructure `delete: del` from `useApi()` (e.g. admin Users, Families, MyFamily, Categories) receive a real DELETE helper instead of `undefined`; user/family/category deletes work again.

## 2026-05-03 — Transactions: Close Out header button copy and visibility
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Soft-close control labels are **Close Out** and **Undo**; the control is hidden for a selected calendar month that is **hard-closed** or has **no loaded transactions** (unless you already **soft-closed**, so **Undo** remains). Lock icon tooltips updated to match.

## 2026-05-03 — Transactions list: mobile layout keeps amount + split beside category
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Transaction cards no longer use a stacked `flex-col` row on small screens; amount and split pill stay in a **right column** next to the category so split rows do not jump under the title; right column width is capped on mobile for balance

## 2026-05-03 — Dashboard: Family close progress targets earliest open transaction month
- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Closeout status for **Family close progress** uses the **first calendar month (by date) that has at least one transaction and is not hard-closed** (`GET /closeout/closed-months` + transaction dates); the UI shows that month’s label and is **omitted** when there is no such month. Per-member lock icons no longer sit in a bordered/shaded box.

## 2026-05-03 — Dashboard: per-member lock icons on Family close progress
- Files touched: `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Each family member row shows the same **open vs closed** lock **SVGs** as **Transactions** (hard-closed month → amber closed lock for all; else soft-closed → blue closed lock; else gray open lock) in a small bordered cell, with per-member titles

## 2026-05-03 — Family close progress moved from Transactions to Dashboard
- Files touched: `resources/js/pages/Transactions.vue`, `resources/js/pages/Dashboard.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The **Family close progress** UI (member soft-close checklist + **Hard close month** for eligible managers) is removed from the Transactions filter area; Dashboard shows it for the **current calendar month** using the same card/section styling as the rest of that page (uppercase gray section title, `rounded-xl` gray card, helper footnote). Transactions still supports soft close / lock icon / closeout status for the **selected** month via existing header and fetches.

## 2026-05-03 — Transactions: split pill spacing and vertical alignment
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Removed top margin above the purple split summary control; pill content uses flex vertical centering (`items-center`, `leading-tight`) so label text sits evenly in the chip

## 2026-05-03 — Named `login` route for auth redirects
- Files touched: `routes/web.php`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Unauthenticated or expired-session requests to `auth` routes (e.g. GET `/transactions`) redirect to `/login` without `RouteNotFoundException`

## 2026-05-03 — Transactions: split strip shows “You” when you paid
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: The purple **“Split: Total … by …”** line uses **You** instead of your name when the logged-in user is the transaction owner (`user_id`)

## 2026-05-03 — Transactions: purple “Split” strip + split breakdown modal
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Removed the inline **Split** pill next to the category name
  - Split summary uses the **purple** chip color scheme and copy **“Split: Total … by {payer name}”**; clicking it opens a modal (not the edit form) with each participant’s **amount** and **percentage**, **you first**, then alphabetical; **(You)** on the signed-in user’s row

## 2026-05-03 — Transactions: split subline panel, baseline alignment, expense totals use your share
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Split “who paid” line sits in a **lighter** bordered panel; amount, separator, and name use **one baseline** (`inline-flex items-baseline`) so the name matches the description line height
  - **Expense** period total and each day’s **expense** subtotal count **your portion** for split expenses; **income** totals and daily income sums still use full transaction amounts
  - Short footnote under the period totals explains split expense behavior

## 2026-05-03 — Transactions list: split rows show your share + total paid by whom
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Rows with `splits` show the **current user’s portion** as the main amount (green/red by type)
  - A secondary line shows the full transaction amount and the transaction owner’s name; expenses use “Total paid … · {name}”, income uses “Total … · {name}”
  - Non-split rows unchanged
  - Initial mount awaits `GET /user` before loading transactions so the viewer’s split share is known when the list first renders

## 2026-05-03 — Dashboard split details: category + optional description
- Files touched: `app/Http/Controllers/DebtController.php`, `resources/js/pages/Dashboard.vue`, `tests/Feature/SplitDebtSummaryTest.php`, `docs/ai/03-frontend-vue.md`, `docs/ai/08-api-routes.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - `GET /split-debt-summary` eager-loads `transaction.category` so each pending split row includes category data
  - **View Details** modal shows the transaction category as the main label (falls back to `Uncategorized` if missing); a non-empty description appears in smaller text immediately to the right of the category (same row when space allows); empty descriptions no longer show “No description”

## 2026-05-03 — Transactions: lock on month filter, header close-out, top totals
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Month filter row shows a lock affordance (open vs closed) tied to your soft-close and family hard-close state; dropdown options no longer append lock emoji
  - **Mark my month closed out** / **Undo my close** moved to the top-right of the sticky Transactions header (hidden when the month is hard-closed)
  - Income and expense totals for the active filter range render below the filters whenever the page is not loading/errored, including when there are zero transactions

## 2026-05-03 — Transactions month status panel defaults to collapsed
- Files touched: `resources/js/pages/Transactions.vue`, `docs/ai/03-frontend-vue.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - The Transactions page "Month Status" panel now starts closed on initial render
  - Users can still expand/collapse it manually via the existing header toggle


## 2026-05-03 — Auto-logout on session timeout in SPA
- Files touched: `resources/js/app.js`, `docs/ai/03-frontend-vue.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/07-workflows.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact:
  - Added a global Axios response interceptor in the Vue entrypoint
  - Any `401` or `419` response from authenticated requests now clears local auth state and redirects to `/login`
  - Prevents the app from remaining in a stale "logged in" UI state after the backend session expires

## 2026-05-03 — Family-scoped funds support added
- Files touched: `app/Http/Controllers/FundController.php`, `app/Policies/FundPolicy.php`, `resources/js/pages/Funds.vue`, `resources/js/pages/CloseoutRules.vue`, `docs/ai/08-api-routes.md`
- Behavioral impact:
  - **Backend:**
    - `FundController::index()` now returns both personal funds (user_id only) and family-scoped funds (family_id set) with `scope` appended to each (`scope: 'personal'` or `scope: 'family'`)
    - `FundController::store()` validates optional `is_family_fund` boolean; if true and user has `family_id`, creates fund with both `user_id` and `family_id`
    - `FundPolicy` updated: `view()` and `update()` allow access to user's personal funds OR family-scoped funds in same family; `delete()` requires fund ownership (personal) OR family membership + `can_manage_family` (family-scoped)
  - **Frontend:**
    - Funds.vue imports `useAuth()` to check `user.family_id`
    - Fund card displays scope badge: "Personal" (gray) or "Family" (purple) next to fund name
    - New Fund modal shows "Family Fund" checkbox only if user has `family_id`
    - `createFund()` includes `is_family_fund` in POST payload and resets to `false` after creation
    - CloseoutRules.vue groups fund options into optgroups: "Personal Funds" and "Family Funds" (latter only if any exist)
    - New computed properties `personalFunds` and `familyFunds` filter funds by scope

## 2026-05-03 — Split debt summary cards added to Dashboard.vue
- Files touched: `resources/js/pages/Dashboard.vue`
- Behavioral impact:
  - Dashboard now fetches `/split-debt-summary?year={year}&month={month}` on mount for current month
  - New "This Month's Split Expenses" section displays above stats grid (only when splitDebtSummary.length > 0)
  - Each split debt shows person name, net amount ("you owe X", "they owe you X", or "Settled" with appropriate colors: red, green, gray)
  - "View Details" button opens bottom-sheet modal showing all transactions for that split pair
  - Modal displays date, description, amount with direction label, and formatted currency for each transaction
  - New helper functions: `formatCurrency()`, `formatDate()`, `getNetAmountText()`, `getNetAmountClass()`, `getTransactionAmountClass()`, `openDetailsModal()`
  - Modal uses Transition component with slide-up animation (matches Funds.vue pattern)
  - Note displayed below section: "These splits will be applied to your debt balance when your family closes the month."
  - Stats grid moved below split debt section

## 2026-05-03 — CloseoutRules.vue page created for independent rule management
- Files touched: `resources/js/pages/CloseoutRules.vue`, `resources/js/router/index.js`, `resources/js/components/AppNav.vue`
- Behavioral impact:
  - New dedicated page for managing closeout rules (replaces fund-specific rules UI)
  - Fetches rules from GET `/closeout-rules` on mount
  - Rules displayed as cards with order badge, name, allocation display, destination label, and status dot
  - Supports CRUD operations: Create (POST), Read (GET), Update (PUT), Delete (DELETE)
  - Add/Edit modal (bottom-sheet) supports all rule fields:
    - Name, Order, Allocation Type (percentage or fixed)
    - Amount, Applied To (gross_income or remaining)
    - Destination: Fund (with fund select), Debt (with user's owed debts), or Title (custom text input)
    - Active toggle
  - Deletes use confirm-on-first-click pattern (matches existing UI)
  - Rules sorted by order on display
  - Destination labels show fund names, debt descriptions, or custom titles
  - Routes to `/closeout-rules` and integrated into navigation between Funds and Debts
  - Mobile-first design with dark theme consistent with existing pages

## 2026-05-03 — Month closeout status panel added to Transactions page
- Files touched: `resources/js/pages/Transactions.vue`
- Behavioral impact:
  - New collapsible status panel displays below month filter (only for selected months, not custom range)
  - Panel shows "Month Status" header with dynamic status badge (Open, Ready to Close, or Closed)
  - Badge colors: gray for Open, blue for Ready to Close, amber for Closed
  - Lists all family members with checkmarks showing who has soft-closed
  - Action buttons appear at panel bottom:
    - "Mark My Month Done" button (blue) if user hasn't soft-closed and month isn't hard-closed
    - "Undo My Close" button (gray) if user has soft-closed and month isn't hard-closed
    - "Hard Close Month" button (amber, requires can_manage_family) if all members soft-closed and month not hard-closed
  - Hard close shows confirmation dialog before proceeding
  - Panel is collapsible with chevron icon in header that rotates on toggle
  - Status updates are fetched from `/closeout/status` endpoint on month change
  - Actions trigger appropriate closeout endpoints and reload status + transactions
  - Mobile-first design with dark theme consistent with existing page styling

## 2026-05-03 — Month closure UI indicators and transaction locking
- Files touched: `app/Http/Controllers/MonthCloseoutController.php`, `routes/web.php`, `resources/js/pages/Transactions.vue`
- Behavioral impact:
  - **Backend:** New `closedMonths()` method in MonthCloseoutController returns list of hard-closed months for family
  - **Backend:** New GET `/closeout/closed-months` route for fetching closed month list
  - **Frontend:** Transactions.vue now fetches closeout status on month change via POST to `/closeout/status`
  - **Frontend:** New `closeoutStatus` ref and `isCurrentMonthHardClosed` computed to track month closure state
  - **Frontend:** New `closedMonths` ref populated from `/closeout/closed-months` endpoint on component mount
  - **Frontend:** New `isMonthClosed(year, month)` helper checks if a specific month is hard-closed
  - **Frontend:** Month selector now displays 🔒 emoji next to closed months
  - **Frontend:** Visual badge appears below month filter when selected month is hard-closed with lock icon and message
  - **Frontend:** Lock icon (amber padlock SVG) displays on each transaction when month is hard-closed
  - **Frontend:** Transactions are visually disabled when month is closed (reduced opacity, cursor-not-allowed)
  - **Frontend:** Delete button is disabled with tooltip when month is hard-closed
  - **Frontend:** Transaction rows cannot be clicked to edit when month is hard-closed

## 2026-05-03 — Split debt summary API endpoint added
- Files touched: `app/Http/Controllers/DebtController.php`, `routes/web.php`
- Behavioral impact:
  - New `splitDebtSummary()` method in DebtController returns pending split debts for a given month grouped by counterpart user
  - Validates year and month parameters
  - Filters to pending split debts only (is_pending_closeout = true)
  - Returns array of summaries keyed by counterpart ID with: counterpart user object, you_owe total, they_owe total, and transaction details
  - New GET `/split-debt-summary` route (requires authentication and family_id)
  - Powers dashboard card showing split debt status for the month

## 2026-05-03 — Controllers and routes for month closeout system
- Files touched: `app/Http/Controllers/FundController.php`, `app/Http/Controllers/MonthCloseoutController.php`, `routes/web.php`
- Behavioral impact:
  - **FundController updates:** 
    - `showRules()` now returns all rules for auth user (removed Fund parameter)
    - `storeRule()` adds validation for `destination_type` (fund/debt/title), `destination_id`, `destination_title`; makes `fund_id` nullable; uses direct FundRule::create() instead of fund authorization
    - `updateRule()` validates new destination fields, checks rule belongs to auth user instead of checking fund ownership
    - New `destroyRule()` method deletes rules that belong to auth user
  - **MonthCloseoutController created (new):**
    - `status()` returns month status array with soft closes, hard close, flags
    - `softClose()` creates soft close record with validation and exception handling
    - `undoSoftClose()` removes soft close records
    - `hardClose()` requires `can_manage_family` role, executes full closeout workflow
  - **Routes updates:**
    - New `/closeout-rules` GET/POST/PUT/DELETE routes for rule management (replaces fund-specific routes)
    - `/closeout/status`, `/closeout/soft-close`, `/closeout/undo-soft-close`, `/closeout/hard-close` POST endpoints
    - `/closeout-rules` view route added
    - Backward compatibility: kept `/funds/{fund}/rules` GET route pointing to showRules()
    - Old `/fund-rules` routes removed in favor of `/closeout-rules`

## 2026-05-03 — Month closeout service created (full implementation)
- Files touched: `app/Services/MonthCloseoutService.php`
- Behavioral impact:
  - New service with 8 methods for soft/hard closing months
  - `softClose()` creates per-user soft close records with validation
  - `undoSoftClose()` removes soft closes with state checks
  - `allMembersSoftClosed()` verifies all family users have soft-closed
  - `isHardClosed()` checks if month is hard-closed
  - `getMonthStatus()` returns comprehensive month status array
  - `hardClose()` executes full month closeout: applies all user rules, confirms split debts, creates hard close record
  - Private `processUserCloseoutRules()` calculates gross income, processes rules (gross/fixed first, then remaining-based), and allocates amounts
  - Private `applyRuleAllocation()` routes allocations to funds, debts, or titled savings based on rule `destination_type`
  - Comprehensive allocation methods handle fund balance increments with movement records, debt payment creation, and title savings creation

## 2026-05-03 — Transaction service updated for month closeout (remove fund processing)
- Files touched: `app/Services/TransactionService.php`
- Behavioral impact:
  - Removed automatic fund income processing from `createTransaction()` and `updateTransaction()` — funds will now only be updated during month hard-closes
  - Removed `FundService` dependency from constructor (was only used for income processing)
  - Constructor is now empty and removed per project rules (no empty public zero-param constructors)
  - Split debts now created with `is_pending_closeout => true` flag in both `createTransaction()` and `updateTransaction()` methods
  - All existing transaction tests pass (6 tests, 11 assertions)

## 2026-05-03 — Month closeout system foundation (migrations & models)
- Files touched: 
  - Migrations: `database/migrations/2026_05_03_160512_add_family_id_to_funds_table.php`, `database/migrations/2026_05_03_160512_update_fund_rules_for_closeout_system.php`, `database/migrations/2026_05_03_160512_create_month_soft_closes_table.php`, `database/migrations/2026_05_03_160512_create_month_hard_closes_table.php`, `database/migrations/2026_05_03_160513_add_is_pending_closeout_to_debts_table.php`, `database/migrations/2026_05_03_160513_create_closeout_title_savings_table.php`
  - Models: `app/Models/MonthSoftClose.php`, `app/Models/MonthHardClose.php`, `app/Models/CloseoutTitleSaving.php`, `app/Models/Fund.php`, `app/Models/FundRule.php`, `app/Models/Debt.php`, `app/Models/Family.php`, `app/Models/User.php`
- Behavioral impact: 
  - New `month_soft_closes` table tracks per-user monthly soft closes (records when a user finalizes month data)
  - New `month_hard_closes` table tracks per-family monthly hard closes (records when family admin finalizes a month)
  - New `closeout_title_savings` table allows fund rules to save to titled records during closeout (e.g., "Medical Reserve")
  - `funds` table now links to `families` via optional `family_id` (family-level funds future-proofing)
  - `fund_rules` now support flexible routing: `destination_type` (fund/debt/title) + `destination_id` + `destination_title` allow rules to target debts or custom title-based savings instead of just funds
  - `debts` table now tracks `is_pending_closeout` flag (marks debts awaiting closeout processing)
  - All new models include proper relationships to Family and User; updated existing models with corresponding reverse relationships

## 2026-05-03 — Initial AI documentation set created
- Files touched: `docs/ai/00-repo-overview.md`, `docs/ai/01-architecture.md`, `docs/ai/02-backend-laravel.md`, `docs/ai/03-frontend-vue.md`, `docs/ai/04-database.md`, `docs/ai/05-auth-permissions.md`, `docs/ai/06-feature-map.md`, `docs/ai/07-workflows.md`, `docs/ai/08-api-routes.md`, `docs/ai/09-known-decisions.md`, `docs/ai/10-ai-change-log.md`
- Behavioral impact: Documentation only — no code changes made
