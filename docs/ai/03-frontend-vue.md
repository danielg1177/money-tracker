# 03 — Frontend (Vue 3)

## Mobile-first UI (required context)

**Users are expected to use Money Tracker mainly on mobile devices** (phones, touch-first). When planning or implementing any UI work:

- **Default viewport:** Design and test for **narrow widths first**; use Tailwind’s mobile-first utilities (`sm:`, `md:`, etc.) to enhance for larger screens, not the other way around.
- **Touch:** Prefer large enough tap targets, spacing between controls, and patterns that work with thumbs (bottom nav, sheets/modals reachable on small screens).
- **Density:** Avoid desktop-only density (tiny text, many columns, hover-only affordances). If something works on mobile, it should still be acceptable on desktop.
- **Consistency:** Follow existing patterns in `AppNav.vue`, page cards, and forms so new screens feel native to the same mobile-oriented shell.

## Entry point

`resources/js/app.js` creates the Vue app, installs Vue Router, and mounts `AppShell.vue` into `<div id="app">` (in `resources/views/app.blade.php`).
It also registers a global Axios response interceptor that treats `401`/`419` responses as session expiry, clears `localStorage.user`, and hard-redirects to `/login`.

## Component tree

```
AppShell.vue
├── AppNav.vue              (rendered when `useAuth().user` is set)
│   ├── bottom nav bar      (Dashboard, Transactions, Funds, Debts, Account button)
│   ├── FAB button          (opens TransactionForm modal)
│   ├── TransactionForm.vue (modal overlay, inline in AppNav)
│   │     └── SplitEditor.vue  (shown when "split" toggle is on)
│   └── User Menu Bottom Sheet (Categories, Closeout Rules, My Family, Admin links, Logout)
└── <router-view>           (current page component)
```

`AppShell.vue` uses `useAuth().user` (shared reactive ref). It wraps the `router-view` in `AppNav` when `user` is non-null; otherwise it shows `router-view` alone (e.g. login). Login updates the same ref, so the shell shows the bottom nav immediately without a full reload.

## Router (`resources/js/router/index.js`)

History mode (`createWebHistory`). Route definitions:

| Path | Component | Guard |
|---|---|---|
| `/login` | `Login.vue` | `guest` (redirect to `/dashboard` if logged in) |
| `/` | redirect → `/dashboard` | — |
| `/dashboard` | `Dashboard.vue` | `requiresAuth` |
| `/transactions` | `Transactions.vue` | `requiresAuth` |
| `/funds` | `Funds.vue` | `requiresAuth` |
| `/closeout-rules` | `CloseoutRules.vue` | `requiresAuth` |
| `/debts` | `Debts.vue` | `requiresAuth` |
| `/categories` | `Categories.vue` | `requiresAuth` |
| `/my-family` | `MyFamily.vue` | `requiresAuth` |
| `/month-summary/:yearMonth` | `MonthSummary.vue` | `requiresAuth` |
| `/admin/users` | `admin/Users.vue` | `requiresAuth` + `adminOnly` |
| `/admin/families` | `admin/Families.vue` | `requiresAuth` + `adminOnly` |
| `/admin/categories` | `admin/Categories.vue` | `requiresAuth` + `adminOnly` |

**Navigation guard** (`beforeEach`): reads `user` from localStorage, normalizes via `normalizeAuthUser`. Redirects unauthenticated to `/login`, authenticated guests to `/dashboard`, non-admins away from `adminOnly` routes.

**Note:** `adminOnly` uses `user.isAdmin` from localStorage — the server is the real auth source. The guard is UI-only and can be bypassed by editing localStorage.

## Composables

### `useApi` (`resources/js/composables/useApi.js`)
Thin wrapper around `window.axios`. Returns `{ loading, error, get, post, put, del, delete }` where `delete` is the same function as `del` (for destructuring as `delete: del` without a broken binding). Each method sets `loading = true`, catches errors into `error`, returns `response.data`.

```js
const { loading, error, get, post, put, del } = useApi();
const data = await get('/transactions');
```

### `useAuth` (`resources/js/composables/useAuth.js`)
Manages authentication state. **`user` is a single module-level `ref`** shared by every caller of `useAuth()` so the shell, nav, and pages stay in sync. On composable module load, `user` is initialized from `localStorage` (same shape as after `fetchUser`).

- `login(email, password)` — POST `/login` (Fortify), then calls `fetchUser()`
- `logout()` — POST `/logout`, clears localStorage
- `fetchUser()` — GET `/user`, normalizes, saves to localStorage
- `user` — the shared reactive ref

## Support utilities

### `normalizeAuthUser` (`resources/js/support/authUser.js`)
Normalizes the user object from either `/user` response or localStorage. Ensures `isAdmin` is a boolean:

```js
isAdmin: Boolean(raw.isAdmin ?? raw.is_admin ?? raw.role === 'admin')
```

### `debtPaymentLabel` (`resources/js/support/debtPaymentLabel.js`)
Shared helper that builds the display label for a debt-related transaction row. Used by `Transactions.vue` and `Dashboard.vue`.

`debtPaymentCategoryLine(transaction)` builds the primary title line:
1. If `transaction.debt` is present: expense repayments show **`Debt Payment · {counterparty}`** (middle dot); creditor income rows show **`Repayment received · {counterparty}`** when the counterparty resolves.
2. Falls back to parsing a `"Debt Payment: …"` prefix from `transaction.description`.
3. Final fallback: **`Debt Payment`** (expense) or **`Debt repayment`** (income).

## Pages

### `Login.vue` (`resources/js/pages/Login.vue`)
Standard email/password form. Uses `useAuth().login()`. On success, redirects to `/dashboard`.

### `Dashboard.vue` (`resources/js/pages/Dashboard.vue`)
Summary view: loads `/transactions` (same **viewer-scoped** list as the Transactions page: own transactions plus split co-participations), `/funds`, `/debts`, current-month `/split-debt-summary`, `/dashboard/monthly-totals`, and `/bank-balance`. Dashboard stat cards include **this calendar month’s** transaction count (filtered from loaded rows by `transaction_date`), funds count, and debts count; debts count includes personal debts (`owed`, `owing`) and `family_debts` returned by the debts endpoint.

For users with `family_id`, a **Bank Account** card appears before Family close progress. Disabled state shows an enable prompt and calls `PUT /bank-balance` with `{ bank_balance_enabled: true }`. Enabled state shows computed balance, baseline set date, and delta summary since set date. Edit mode saves via `PUT /bank-balance` with `{ bank_balance: amount }`; disable uses `PUT /bank-balance` with `{ bank_balance_enabled: false }` after confirmation.

When `user.family_id` is set and there is at least one **calendar month** (derived from loaded `transaction_date` values) that is **not** in `GET /closeout/closed-months` (hard closes), it loads `/family/users` and `POST /closeout/status` for the **earliest such month** (chronologically first month with transactions that still needs a hard close) and shows a **Family close progress** block (same section/card patterns as split debt: uppercase section title `text-sm font-semibold text-gray-400`, month label under the title, card `bg-gray-800 border border-gray-700 rounded-xl p-4`). If every month that has transactions is already hard-closed (or there are no transactions), the block is **hidden**. Each **family member** row uses the same lock **icons** as **Transactions** (no extra border/background on the icon cell): **amber closed** when that month is hard-closed, else **blue closed** if that member has soft-closed, else gray **open** outline. **Hard close month** for `can_manage_family` when everyone has soft-closed and the month is not hard-closed; a short footnote names the month. **This Month's Split Expenses** shows per-counterpart rows only (the old **Split Balance This Month** aggregate card was removed). Split-expense rows open a bottom sheet listing each pending split with **category** as the primary label and an optional **description** in smaller type directly beside it when present; no placeholder when the description is empty. `GET /split-debt-summary` includes nested `transaction.debt` (with `creditor`, `debtor`, `fund`) so debt-payment lines in that sheet show **Debt Payment: {counterparty}** the same way as `Transactions.vue` (`debtPaymentCategoryLine()` in `resources/js/support/debtPaymentLabel.js`). Uses `useApi` (`get`, `post`, `put`) and `useAuth`.

### `Transactions.vue` (`resources/js/pages/Transactions.vue`)
Full transaction list with date filters (`start_date`, `end_date`); the API returns only **relevant** rows for the signed-in user (their transactions and any family split they participate in). Supports editing (re-opens `TransactionForm`) and deleting. The selected month filter is synced to the URL query as `?month=YYYY-MM`, so refresh and browser back/forward keep the same month context; invalid or missing query values fall back to the current month and the page normalizes the URL. Month quick-select options are generated as a single **descending** timeline (latest to oldest) across year boundaries and include the **next two upcoming months** in addition to current/past entries. Each card uses a **single horizontal row** on narrow viewports (`flex-row`): category on the left (`flex-1 min-w-0`), a **capped-width** right column (`max-w-[12.5rem]` on small screens, wider from `sm`) for amount + split chip so they stay **beside** the title instead of stacking below it; lock and delete sit in a slim column to the right. Split rows (`splits` present): the primary amount is the **signed-in user’s split share**; below it a **purple-styled** control (same palette as the old “Split” chip: `bg-purple-900/50`, purple borders/text) reads **“Split: Total {amount} by {name}”** where the payer is **“You”** when `transaction.user_id` matches the signed-in user, otherwise the owner’s display name and opens a **`Teleport` modal** on click (`@click.stop` so the row does not open edit). The chip is a flex row with **no extra top margin** and **centered** inner lines (`flex items-center` on the button and wrapped text span) so copy sits visually centered in the pill. The modal lists each `TransactionSplit` with **amount** and **share percentage**, **sorted with the logged-in user first**, then others by name; the current user’s row is labeled **(You)**. Non-split rows show the full transaction amount only. Loads `GET /user` before the first transaction fetch so split primary amounts resolve on first paint.

When transactions are **created from the global FAB** (`AppNav` emits `transaction-created`), this page **refetches the active filter range** (selected month or custom range) instead of blindly prepending the new row. **After a successful save from this page’s edit modal** (including a changed `transaction_date`), it refetches the same way so a row **disappears or reappears** immediately when it no longer falls inside the active month or custom date range.
Period **income** total and per-day **income** sums **exclude `is_debt_payment` creditor repayments** (they are not counted as earned income aligned with closeout gross income). Period **expense** total and per-day **expense** sums still include debt repayments paid by you and use **your split share** for split expenses; non-split expenses use the full amount. Footnotes explain split-share expenses and repayment-income exclusion. **Debt-payment expense rows can be tapped to edit**; mirrored debt-payment **income** rows remain locked from edit. Delete reverses paired legs when mirrored. For a selected calendar month (not custom range), a lock icon beside the month dropdown reflects hard-close (amber locked), your soft-close (blue locked), or open-for-you (open lock outline). The sticky header **Close Out** / **Undo** control (top right) only appears when that month is **not** hard-closed and the loaded list has **at least one transaction**, except **Undo** still appears if you have already soft-closed (so you can reopen). **Family close progress** and **hard close** live on the **Dashboard**, not on this page. Row titles for debt-related lines use **`debtPaymentCategoryLine()`** (`resources/js/support/debtPaymentLabel.js`) with **`Debt Payment · {counterparty}`** on payer expenses when `debt` is loaded. **Category emoji** beside the title is shown whenever `transaction.category?.icon` exists, including categorized debt-payment expenses. **Small pills** (same visual family as repayment) flag row attributes: sky **Debt payment** / **Repayment** (`is_debt_payment` expense vs income), amber **Advance** (`advance_fund_id`, optional `advanceFund.name` tooltip), orange **Borrow** (`is_borrow` income), purple **Closeout** (`is_closeout_initiated`); split expenses are **not** duplicated with a title pill—the existing purple **Split: Total…** control beside the amount covers that. Closeout rows also keep purple card tint and stay delete/edit locked. Shared helper: `resources/js/support/debtPaymentLabel.js`.

### `Funds.vue` (`resources/js/pages/Funds.vue`)
Lists the auth user's personal funds. Shows balance and rules. Allows creating funds (with optional starting balance), editing rules, borrowing from a fund, repaying fund debts, and viewing movement history via a bottom-sheet modal. **Note:** "Add Rule" functionality has been removed; only "Edit Rule" is available. The History modal displays all fund movements (allocation, repayment, borrow, closeout_allocation, initial_value, advance_settlement) sorted by date (newest first), with movement types labeled and color-coded (green for positive/income-like movements, amber for borrow/advance_settlement).

### `CloseoutRules.vue` (`resources/js/pages/CloseoutRules.vue`)
Closeout rules now support an optional **Closeout Expense Category** selector (`closeout_expense_category_id`) sourced from expense categories. The selected category is used for backend-generated closeout movement rows (fund/debt allocations during hard close, and title completion transactions).

### `Debts.vue` (`resources/js/pages/Debts.vue`)
Shows "You Owe" and "Owed to You" sections.

**Warning — naming inversion:** The API returns `{ owed, owing }` where:
- `owed` = debts where auth user is the **debtor** (they owe money)
- `owing` = debts where auth user is the **creditor** (others owe them)

The Vue page uses `debts.owing` for "You Owe" and `debts.owed` for "Owed to You" — **this is reversed from the backend key names**. This is a known bug.

**Interest settings:** Add/Edit Debt modals include an **Apply monthly interest at closeout** toggle, APR input, and optional **Loan Received Date**. When enabled, payloads include `interest_enabled=true` and `interest_rate` (annual %). Debt cards show `Interest: X.XX% APR` and loan received date when present.

**Payment History Modal:** When viewing a debt's payment history via the **History** button, the modal displays timeline rows from `GET /debts/{id}/payments`, including normal payment transactions plus synthetic entries. `type='initial_value'` (debt origin) renders with blue styling; `type='interest_accrual'` (monthly closeout interest events) renders with amber styling and `+` amount. Synthetic rows omit paid-by user/actions.

### `Categories.vue` (`resources/js/pages/Categories.vue`)
Family category management. Create/edit/delete categories. Includes `IconPicker` component. **Type** is chosen with **Income** or **Expense** radios (mutually exclusive). Split default (`is_split_default`, `split_default`) and default advance fund (`advance_fund_id`) UI appears **only for expense** categories; switching type to income clears those fields locally and the API strips them for income-only categories. Fetches funds for the advance-fund dropdown. List shows a single Income or Expense badge; "Split Default" only shows when the category has the flag and is an expense category.

After create/update/delete, the page dispatches a `window` event (`categories-changed`) so shared shells (notably `AppNav` + global `TransactionForm`) refresh category options immediately without requiring a hard page refresh.

### `MyFamily.vue` (`resources/js/pages/MyFamily.vue`)
Shows current user's family info and members. Only accessible to `head_of_household` or `admin` (guarded server-side by `can:manage_family`). Allows adding/removing members.

### `MonthSummary.vue` (`resources/js/pages/MonthSummary.vue`)
Displays a comprehensive financial summary for a specific month (route param: `/month-summary/:yearMonth`, e.g., `/month-summary/2026-05`). Shows:
- **Close status header:** Lock icon indicating hard-closed (amber), all members soft-closed (blue), or open (gray outline)
- **Spending by Category:** Lists all transactions grouped by category, showing expense totals in red and income totals in green (**category aggregates exclude debt-repayment transactions** — same query as backend)
- **Income note:** Explains that debtor repayments owed to you are excluded from income category totals; see Debt repayments
- **Debt repayments:** Dedicated section backed by JSON `debt_repayments.{paid,received}` — sky-tint rows for repayment **received**, amber tint for repayment **paid**; copy states these are excluded from gross income / allocation rules at hard close
- **Family Balances:** Shows inter-member debts from split transactions (only if balances exist), indicating whether each member owes you or you owe them
- **Fund In/Out:** Displays monthly fund movement activity grouped by fund, including non-rule and rule-related movements (borrow, repayment, initial value, closeout allocation, advance settlement) with in/out/net totals
- **Projected Closeout / Closeout Results:** Dry-run preview of the month's fund allocation rules with basis (gross income, expenses, remaining) and projected amounts for each active rule
- **Title Savings:** For hard-closed months with `title_savings` rows, a new section lists each closeout title allocation and allows completion toggles per row. **Mark Done** calls `POST /title-savings/{id}/complete`; **Undo** calls `DELETE /title-savings/{id}/complete`. Completed rows show a green "Done" badge, green amount tint, and formatted completion date.
Month summary remains read-only for summary aggregates but now supports title-saving completion state updates in-place (without a full refetch). Uses `useApi` (`get`, `post`, `del`) and `useRoute`/`useRouter`.

### `admin/Users.vue` (`resources/js/pages/admin/Users.vue`)
Admin-only. Lists all users, create/edit/delete. Lets admin assign `family_id`, `role`, and optionally set a new password while editing (blank keeps the current password).

### `admin/Families.vue` (`resources/js/pages/admin/Families.vue`)
Admin-only. Lists all families. Create families, manage members.

### `admin/Categories.vue` (`resources/js/pages/admin/Categories.vue`)
Admin-only route in the router. **Has no corresponding POST route on the backend** — `POST /admin/categories` does not exist in `web.php`. The regular `/categories` POST route serves all authenticated users. This page may be broken or unused.

## Components

### `TransactionForm.vue` (`resources/js/components/TransactionForm.vue`)
Modal form for creating or editing a transaction. Props: `categories` (Array), `familyUsers` (Array), `funds` (Array), **`debtsPayload`** (shape of `GET /debts`; optional), `transaction` (Object, optional for edit mode). Fields: type (income/expense), amount, description, date, category. The category `<select>` shows only rows matching the current type (income vs expense), **sorted A–Z by name** (`localeCompare`, case-insensitive). **Pay toward a tracked debt** (expense only): submits `debt_id`. **Split** controls remain available for expense transactions even when debt repayment is on, allowing split debt-payment expenses from the transaction form. **Advance against fund** remains disabled when debt repayment is on.

When **split** is turned on (manually or because the category has a split default), initial `split_data` is **equal shares across all `familyUsers`** (via `resources/js/support/equalFamilySplit.js`); the user can still adjust percentages in `SplitEditor` or tap **Equal Split** to rebalance.

For **income**, a dedicated debt association block supports:
- `No` (plain income)
- `Existing` (attach income to an existing debt you owe; increases debt)
- `New Debt` (create debt on submit, external creditor or family member, optional family-shared flag)
- Income → New Debt now also supports debt settings: `Apply monthly interest at closeout` and APR %. Loan received date for this path is derived from the transaction date.

Editing now allows debt-payment **expense** transactions and keeps repayment mode active while editing those rows. Debt-payment **income** mirror rows show a non-editable banner to direct users to edit the payer expense row instead. Submit omits split fields for income and includes income debt fields only when selected. Emits `created`, `updated`, or `close` events.

### `SplitEditor.vue` (`resources/js/components/SplitEditor.vue`)
Sub-component of `TransactionForm`. Renders a list of family members with percentage inputs. Validates that percentages sum to 100 before allowing submission. The **Equal Split** button uses the same proportional rounding helper as initial defaults (`equalSharePercentages` in `resources/js/support/equalFamilySplit.js`) so percentages sum to exactly 100.

### `AppNav.vue` (`resources/js/components/AppNav.vue`)
Bottom navigation bar with 4 primary nav links (Dashboard, Transactions, Funds, Debts) and an Account button. The Account button opens a bottom-sheet menu containing Categories, Closeout Rules, My Family (if applicable), Admin links (if admin), and Logout. Also contains the FAB (floating action button) that opens the `TransactionForm` modal. On mount it fetches categories, family users, funds, **and debts** (`GET /debts`); those props are passed into `TransactionForm` so advance-fund and **pay toward debt** work from the global FAB on every page.

`AppNav` also listens for `categories-changed` and reloads those form dependencies, keeping the FAB category dropdown in sync right after category CRUD.

### `IconPicker.vue` (`resources/js/components/IconPicker.vue`)
Simple emoji/icon selector used within `Categories.vue`. Includes dog (`🐶`), family (`👨‍👩‍👧‍👦`), and heart (`❤️`) emoji options in the picker list.

### `App.vue` (`resources/js/components/App.vue`) — LEGACY
This file exists but is **not imported or used anywhere**. It appears to be an older monolithic SPA component from before the router-based architecture was introduced. It contains references to `/admin/categories/{family_id}` GET routes that don't exist. **Do not modify or rely on this file.**

## CSS / Styling

`resources/css/app.css`:
```css
@import 'tailwindcss';
@source '../../resources/js/**/*.vue';
@source '../../resources/views/**/*.blade.php';
```

No `tailwind.config.js` — Tailwind v4 reads source files via `@source` directives. Custom cursor utility defined inline.

## State management

There is **no Vuex or Pinia**. State is managed locally in each page component using Vue 3 `ref`/`reactive`. The only shared state mechanism is `localStorage` for the auth user object. Pages independently fetch their data on `onMounted`.

## Axios configuration

`resources/js/bootstrap.js` configures `window.axios` with:
- `X-Requested-With: XMLHttpRequest` header (triggers `expectsJson()` on Laravel side)
- CSRF token from `<meta name="csrf-token">` via `axios.defaults.headers.common['X-CSRF-TOKEN']`

`resources/js/app.js` extends Axios behavior with a global auth-timeout interceptor so expired sessions do not leave the SPA in a broken authenticated UI state.
