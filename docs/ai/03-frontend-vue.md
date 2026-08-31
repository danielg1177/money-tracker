# 03 — Frontend (Vue 3)

## Mobile-first UI (required context)

**Users are expected to use Money Tracker mainly on mobile devices** (phones, touch-first). When planning or implementing any UI work:

- **Viewport / horizontal scroll:** `resources/views/app.blade.php` uses `width=device-width, initial-scale=1, viewport-fit=cover`. Global CSS (`resources/css/app.css`) sets `overflow-x: clip` on `html`, `body`, and `#app`, and shells (`AppShell.vue`, `AppNav.vue`) use `max-w-full` plus **`min-w-0` on flex main content** so intrinsic-width children cannot force sideways scrolling (common flex pitfall).
- **iOS safe area (notch / Dynamic Island):** With `viewport-fit=cover` and PWA `standalone` + `apple-mobile-web-app-status-bar-style: black-translucent`, the web view extends under the status bar. Bottom nav already uses `env(safe-area-inset-bottom)`; **top** inset is applied via utilities **`pt-safe`** / **`pt-safe-4`** in `app.css` on sticky page headers (`padding-top: env(safe-area-inset-top)`) and on non-sticky pages (Bank connections, Plaid import/calibrate) and login. On devices without a notch the env value is `0`, so layout is unchanged. **PWA home-screen** users are most affected; Safari in-browser may mask the issue when the URL bar provides offset.
- **Native `type="date"` on iOS:** Global base styles constrain **`date` / `datetime-local` / `month` / `time`** to **`width/max-width: 100%`**, **`min-width: 0`**, plus WebKit **`::-webkit-datetime-edit`** / **`::-webkit-datetime-edit-fields-wrapper`** flex shrink rules. On **≤640px touch WebKit**, **`width/max-width: -webkit-fill-available`** (unlayered) overrides intrinsic width inside padded bottom sheets. **`TransactionForm`** wraps the date in **`overflow-hidden` + `contain:layout`** and a border on the wrapper (borderless input) so shadow UI cannot paint past the rounded box. Mobile **`font-size: 16px !important`** intentionally **excludes** date/time/month inputs so that rule does not widen Safari’s date control. Bottom sheets still use **`min-w-0` / `overflow-x-hidden`** as before.
- **iOS focus zoom:** On viewports ≤640px, **`input`, `select`, `textarea`** (except checkbox/radio/range/button types) force **`font-size: 16px`** so Safari does not zoom the whole layout when focusing fields (login and forms use `text-sm` otherwise).
- **Numeric keypads:** Money and fractional fields use **`v-bind="mobileDecimalNumberAttrs"`** from `resources/js/support/mobileNumericInputAttrs.js` (`inputmode="decimal"`, `enterkeyhint="done"`) on **`type="number"`** inputs so phones open a digit-first keypad with a decimal key. Whole-number fields (e.g. rule **order**) use **`mobileIntegerNumberAttrs`** (`inputmode="numeric"`). Browsers map these hints to their best layout (often the large telephone-style grid for integers; decimals use the appropriate numeric layout with `.`).
- **Default viewport:** Design and test for **narrow widths first**; use Tailwind’s mobile-first utilities (`sm:`, `md:`, etc.) to enhance for larger screens, not the other way around.
- **Touch:** Prefer large enough tap targets, spacing between controls, and patterns that work with thumbs (bottom nav, sheets/modals reachable on small screens).
- **Density:** Avoid desktop-only density (tiny text, many columns, hover-only affordances). If something works on mobile, it should still be acceptable on desktop.
- **Consistency:** Follow existing patterns in `AppNav.vue`, page cards, and forms so new screens feel native to the same mobile-oriented shell.

## Entry point

`resources/js/app.js` creates the Vue app, installs Vue Router, and mounts `AppShell.vue` into `<div id="app">` (in `resources/views/app.blade.php`).
It sets `window.history.scrollRestoration = 'manual'` (when supported) and runs a double-`requestAnimationFrame` scroll reset on first router readiness plus `pageshow`, preventing mobile browsers from restoring slight mid-page offsets on reload/back-forward cache restores. It also registers a global wheel guard that blurs focused `input[type="number"]` fields to prevent accidental scroll-wheel value changes, and registers a global Axios response interceptor that treats `401`/`419` responses as session expiry, clears `localStorage.user`, and hard-redirects to `/login`.

## Component tree

```
AppShell.vue
├── AppNav.vue              (rendered when `useAuth().user` is set; main slot is flush to the top so each page header touches the viewport edge; page sticky headers use **`pt-safe`** so title text sits below the notch/Dynamic Island; bottom nav uses `padding-bottom: env(safe-area-inset-bottom)` and spacer height includes safe-area)
│   ├── bottom nav bar      (Dashboard, Transactions, Funds, Debts, Account button)
│   ├── FAB button          (opens TransactionForm modal)
│   ├── TransactionForm.vue (modal overlay, inline in AppNav)
│   │     └── SplitEditor.vue  (shown when "split" toggle is on)
│   └── User Menu Bottom Sheet (Bank connections, Categories, Closeout Rules, Settings, My Family, Admin links, Logout)
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
| `/bank-connections` | `BankConnections.vue` | `requiresAuth` — Plaid Link (`financekit_supported` on link token when enabled for Apple Card / Wallet where Plaid allows), pending-import banner, **Sync all banks this month** / **last month** (`POST /plaid/sync-month`, `POST /plaid/sync-last-month` when any item linked) plus per-item sync-this-month / sync-last-month, calibrate navigation, disconnect; short Apple Card note under Connect |
| `/plaid/import-review` | `PlaidImportReview.vue` | `requiresAuth` — loads `GET /plaid/pending-imports`, `GET /categories`, `GET /funds`, `GET /debts`, `GET /family/users`. Tabs: **Review**, **Transfers**, **Auto**, **Recent** (only when `manually_dismissed` non-empty), **Ignored** (auto-dismiss). Expanded row mirrors main transaction options: income debt (none / existing / new), **Family member paying me back** (`PlaidImportRepaymentOptions` — same mirror-expense behavior as `TransactionForm`), expense pay-toward-debt, split + `SplitEditor`, advance fund + non-necessity when the fund allows it; confirm POST sends the same payload shape as `TransactionForm`. **Split into multiple transactions** mode allocates amounts across lines; each line uses `PlaidImportSplitLineOptions.vue` (including repayment on income lines). **Suggested repayment** banner pre-fills repayment from `raw_payload.suggested_repayment_group`. **Possible match** indigo banner when `suggested_ledger_match` is present — **Accept match** (`POST …/link`) or **Ignore** (client-side dismiss). **Possible savings sweep match** emerald banner when `suggested_sweep_match_id` is set — **Match to Sweep** opens picker (`GET …/sweep-candidates`, `POST …/link-to-sweep`); **Link to savings sweep** manual toggle on Review (expanded) and Transfers tabs. **Recent** lists manual dismissals with badge **Always Ignored** (`has_learned_dismiss_rule`) or **Dismissed**; **Undo** (`POST …/undo-dismiss`) restores the row to Review or Transfers. **Ignored** tab: auto-dismissed restore / acknowledge. **Auto-created → Correct It** show the same repayment UI for income. Also **Suggest matches** / **Link**, card-payment dismiss (**Always ignore** / **Dismiss once**), Confirm / Dismiss. **Auto** tab includes `auto_created` and `auto_linked` rows: **Linked** badge + **Confirm Match** (`approve-auto-linked`) / **Not a Match** (`reject-auto-linked`, re-fetches pending for Review); **Auto** badge rows keep **Looks Correct** / **Correct It** |
| `/plaid/calibrate/:itemId` | `PlaidCalibrate.vue` | `requiresAuth` — matched / unmatched bank / unmatched ledger tabs, apply `POST /plaid/items/{id}/calibrate` |
| `/funds` | `Funds.vue` | `requiresAuth` |
| `/closeout-rules` | `CloseoutRules.vue` | `requiresAuth` |
| `/debts` | `Debts.vue` | `requiresAuth` |
| `/categories` | `Categories.vue` | `requiresAuth` |
| `/my-family` | `MyFamily.vue` | `requiresAuth` |
| `/settings` | `Settings.vue` | `requiresAuth` |
| `/month-summary/:yearMonth` | `MonthSummary.vue` | `requiresAuth` |
| `/admin/users` | `admin/Users.vue` | `requiresAuth` + `adminOnly` |
| `/admin/families` | `admin/Families.vue` | `requiresAuth` + `adminOnly` |
| `/admin/categories` | `admin/Categories.vue` | `requiresAuth` + `adminOnly` |

`scrollBehavior` always returns `{ top: 0, left: 0 }`, so each route navigation starts at the top of the page rather than restoring a previous scroll offset.

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

### `useSelectedMonth` (`resources/js/composables/useSelectedMonth.js`)
Shared calendar-month selection for **Transactions** and **Month Summary**. **`selectedMonth` is a module-level `ref`** (`YYYY-MM`) so changing the month on either page survives navigating away (Dashboard, Funds, etc.) and coming back. Initialized from `sessionStorage` (same-tab reload) or the current calendar month. Invalid values are ignored. **Custom Range** on Transactions does not overwrite this month.

- `selectedMonth` — the shared reactive ref
- `setSelectedMonth(value)` — validates `YYYY-MM` and persists to `sessionStorage`

Month parsing, current-month default, and the descending 26-month quick-select list live in `resources/js/support/yearMonth.js`.

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

### `yearMonth` (`resources/js/support/yearMonth.js`)
Calendar-month helpers used by Transactions and Month Summary: `parseYearMonth` (`YYYY-MM` or `null`), `currentCalendarMonth()`, `monthNames`, and `buildQuickSelectMonths()` (26-month descending list including the next two upcoming months).

## Pages

### `Login.vue` (`resources/js/pages/Login.vue`)
Standard email/password form. Uses `useAuth().login()`. On success, redirects to `/dashboard`.

### `Dashboard.vue` (`resources/js/pages/Dashboard.vue`)
Summary view: loads `/transactions` (same **viewer-scoped** list as the Transactions page: own transactions plus split co-participations), `/funds`, `/debts`, current-month `/split-debt-summary`, `/dashboard/monthly-totals`, and `/bank-balance`. Dashboard stat cards include **this calendar month’s** transaction count (filtered from loaded rows by `transaction_date`, excluding closeout fund-allocation rows), funds count, and debts count; debts count includes personal debts (`owed`, `owing`) and `family_debts` returned by the debts endpoint.

For users with `family_id`, a **Bank Account** card appears before Family close progress. Disabled state shows an enable prompt and calls `PUT /bank-balance` with `{ bank_balance_enabled: true }`. Enabled state shows computed balance, baseline set date, and delta summary since set date. Edit mode saves via `PUT /bank-balance` with `{ bank_balance: amount }`; disable uses `PUT /bank-balance` with `{ bank_balance_enabled: false }` after confirmation.

When `user.family_id` is set and there is at least one **calendar month** (derived from loaded `transaction_date` values) that is **not** in `GET /closeout/closed-months` (hard closes), it loads `/family/users` and `POST /closeout/status` for the **earliest such month** (chronologically first month with transactions that still needs a hard close) and shows a **Family close progress** block (same section/card patterns as split debt: uppercase section title `text-sm font-semibold text-gray-400`, month label under the title, card `bg-gray-800 border border-gray-700 rounded-xl p-4`). If every month that has transactions is already hard-closed (or there are no transactions), the block is **hidden**. Each **family member** row uses the same lock **icons** as **Transactions** (no extra border/background on the icon cell): **amber closed** when that month is hard-closed, else **blue closed** if that member has soft-closed, else gray **open** outline. **Hard close month** for `can_manage_family` when everyone has soft-closed and the month is not hard-closed; a short footnote names the month. **This Month's Split Expenses** shows per-counterpart rows only (the old **Split Balance This Month** aggregate card was removed). Split-expense rows open a bottom sheet listing each pending split with **category** as the primary label and an optional **description** in smaller type directly beside it when present; no placeholder when the description is empty. `GET /split-debt-summary` includes nested `transaction.debt` (with `creditor`, `debtor`, `fund`) so debt-payment lines in that sheet show **Debt Payment: {counterparty}** the same way as `Transactions.vue` (`debtPaymentCategoryLine()` in `resources/js/support/debtPaymentLabel.js`). Uses `useApi` (`get`, `post`, `put`) and `useAuth`.

### `Transactions.vue` (`resources/js/pages/Transactions.vue`)
Full transaction list with date filters (`start_date`, `end_date`); the API returns only **relevant** rows for the signed-in user (their transactions and any family split they participate in) unless Settings **View all family expenses** is on (`GET /transactions?view=family` household overlay; split rows still appear once). When that overlay is on, owner names are shown, the viewer can still **edit/delete their own rows** (same special-row locks as overlay-off), and other members’ rows stay browse-only (including repayment, external reimbursement, and closeout rows) and mark the payer with a **yellow** family icon + name plus a **4px yellow left bar** (half the 8px bottom type bar), using the same normal card fills as the viewer’s own rows. Overlay-off splits they paid that you participate in use the same yellow name/icon + left bar treatment, with the usual diagonal violet + type-color bottom bar. Card **type color** is a **thick bottom bar** (8px): solid **green** income / **red** expense, or a **diagonal** half **violet** + half type color on **splits**. When a day includes other members’ non-split rows, a small centered **dot** sits after the last viewer-associated row (your solos and any splits) before those unassociated family solos. The period totals card shows **You** and **Family** income/expense figures and **excludes closeout fund-allocation rows**. **Family** expense totals also omit payments from one family member to another (inter-member debt-payment expenses); **You** totals still include those payments. Hard-close transfers into funds (`is_closeout_initiated` expenses described as `Closeout transfer to fund: …`, linked `FundMovement` `closeout_allocation`) are listed in their own **Closeout fund movements** group (blue cards; title is the destination **fund name**; **your** rows first, then other members’ rows at the bottom with the same yellow name/icon + left bar as their other family-view cards) and are not placed on the last calendar day or counted in that day’s totals. Each **date header** also shows **You** and **Family** totals on one line (**Family** then **You**), with income above expenses when both exist that day; the **Family** and **You** labels stay column-aligned across those rows. Within each day, rows are ordered **your transactions** (income first, then expenses), then **split transactions** (splits you paid for first, then splits a family member paid for), then **other family members’** transactions (then category label, then alphabetically). Supports editing (re-opens `TransactionForm`) and deleting the viewer’s own rows even when the overlay is on. The selected month is stored in **`useSelectedMonth`** (shared with Month Summary) and also synced to the URL query as `?month=YYYY-MM`. A valid query wins and updates the shared month; a missing query restores the shared month (not “today”) and normalizes the URL. Browser back/forward still follows the query. Invalid query values are ignored in favor of the shared month. Month quick-select options are generated as a single **descending** timeline (latest to oldest) across year boundaries and include the **next two upcoming months** in addition to current/past entries. Each card uses a **single horizontal row** on narrow viewports (`flex-row`): category on the left (`flex-1 min-w-0`), a **capped-width** right column (`max-w-[12.5rem]` on small screens, wider from `sm`) for amounts so they stay **beside** the title instead of stacking below it; lock and delete sit in a slim column to the right. Split rows (`splits` present): each participant’s share is listed **one per line** in a two-column stack (signed-in user first as **`You:`**, then **`{name}:`**), so labels start on one vertical line and amounts on another, with **`Total:`** in **purple** on the same grid (no “Split: Total … by …” pill). Tapping the amount stack still opens a **`Teleport` modal** (`@click.stop` so the row does not open edit). The modal lists each `TransactionSplit` with **amount** and **share percentage**, **sorted with the logged-in user first**, then others by name; the current user’s row is labeled **(You)**. Non-split rows show the full transaction amount only. Loads `GET /user` before the first transaction fetch so split primary amounts resolve on first paint.

When a **calendar month** quick-select is active (not **Custom Range**), the page also loads **`GET /month-summary`** and shows **Split balances (this month)** when **`member_balances`** is non-empty (net shared-expense IOUs vs other members for that month; same rules as **Month Summary**).

When transactions are **created from the global FAB** (`AppNav` emits `transaction-created`), this page **refetches the active filter range** (selected month or custom range) instead of blindly prepending the new row. **After a successful save from this page’s edit modal** (including a changed `transaction_date`), it refetches the same way so a row **disappears or reappears** immediately when it no longer falls inside the active month or custom date range.
Period **income** total and per-day **income** sums **exclude `is_debt_payment` creditor repayments** (they are not counted as earned income aligned with closeout gross income). Period **expense** total and per-day **expense** sums still include debt repayments paid by you and use **your split share** for split expenses; non-split expenses use the full amount. When family view is on, **Family** expense totals (period and per-day) omit payments from one family member to another (`debt.creditor_id` set); **You** totals still include them. External and fund debt payments still count as family spending. When the filtered list includes any viewer-counted `is_non_necessity=true` expense rows (excluding `is_closeout_initiated`), the period totals card adds a two-line breakdown under Expenses: **Total Necessities** and **Total Non-Necessities** (violet amount). Footnotes explain split-share expenses and repayment-income exclusion. **Debt-payment expense rows can be tapped to edit only while the selected month is open for you**; mirrored debt-payment **income** rows remain locked from free edit, but creditors can **Record as expense** / **Also recorded as expense** via `DebtPaymentBenefitForm.vue` (`POST/PUT/DELETE /transactions/{income}/debt-payment-benefit`) to attach a linked normal expense (category, split, advance, non-necessity). Benefit expense rows show a **From debt repayment** badge and are edited/removed through that form (not the normal transaction editor). After editing/saving from a scrolled list position, the page restores the prior scroll offset so the user stays near the same list location instead of jumping to top. Delete reverses paired legs when mirrored (including any benefit expense). For a selected calendar month (not custom range), a lock icon beside the month dropdown reflects hard-close (amber locked), your soft-close (blue locked), or open-for-you (open lock outline). When the selected month is hard-closed or soft-closed by the viewer, transaction cards show a lock, edit/delete interactions are disabled, and empty-state copy notes that new transactions are blocked. Locked-month dimming (`opacity-75`) is **not** applied to reimbursement rows (`is_repaid`, `is_repayment`, `is_repayment_mirror`), so their cyan / amber / faded-gray card fills stay visible. The sticky header **Close Out** / **Undo** control (top right) only appears when that month is **not** hard-closed and the loaded list has **at least one transaction**, except **Undo** still appears if you have already soft-closed (so you can reopen). **Family close progress** and **hard close** live on the **Dashboard**, not on this page. Row titles for debt-related lines use **`debtPaymentCategoryLine()`** (`resources/js/support/debtPaymentLabel.js`) with **`Debt Payment · {counterparty}`** on payer expenses when `debt` is loaded. **Category emoji** beside the title is shown whenever `transaction.category?.icon` exists, including categorized debt-payment expenses. **Small pills** (same visual family as repayment) flag row attributes: sky **Debt payment** / **Repayment** (`is_debt_payment` expense vs income — **tap** opens a debt-details bottom sheet, which for income also offers Record/Edit benefit expense), amber **Advance** (`advance_fund_id`, optional `advanceFund.name` tooltip), violet **Non-necessity** (`is_non_necessity` expense), orange **Borrow** (`is_borrow` income), purple **Closeout** (`is_closeout_initiated`); split expenses are **not** duplicated with a title pill—participant shares and **Total:** sit beside the title. Cyan **Repayment received** (income `is_repayment`) and **Repaid by …** (expense `is_repaid`) chips are **buttons** that open bottom sheets listing covered expenses or repayment income details. **Reimbursed externally** (expense repaid via external income) and **External reimbursement** (income linked to external expenses) are also buttons that open bottom sheets showing the linked income or expense rows respectively. The blue **bank institution** pill (`import_source=plaid`) opens a **Bank Import** bottom sheet (import metadata, all sibling rows sharing `plaid_pending_import_id`, and **Undo Import** when status is `confirmed`). Closeout rows also keep purple card fill and stay delete/edit locked. Card **fills** stay as they were for the viewer (gray, plus repayment/mirror/closeout tints). Other members are no longer yellow-filled; they use those same fills with a yellow family icon + name and a 4px yellow left bar. **External reimbursement** income uses the same faded gray card as **Reimbursed externally** expenses (`opacity-40`). Card **type color** is a **thick bottom bar** (**green** income, **red** expense, **diagonal violet + type color** on splits) for every owner. Delete-confirm still uses a full red card. Shared helper: `resources/js/support/debtPaymentLabel.js`.

### `Funds.vue` (`resources/js/pages/Funds.vue`)
Lists the auth user's personal funds. Shows balance and rules. Allows creating funds (with optional starting balance), editing rules, borrowing from a fund, **sweeping balance to external savings** (`POST /funds/{id}/sweep` via a teal **Sweep** button and bottom-sheet modal when balance &gt; 0; partial or full amount, optional note, no closed-month guard), repaying fund debts, and viewing movement history via a bottom-sheet modal. Fund balances on cards and in the history header now use sign-aware colors (positive green, negative red, zero neutral). **Note:** "Add Rule" functionality has been removed; only "Edit Rule" is available. The History modal displays all fund movements (allocation, repayment, borrow, closeout_allocation, initial_value, advance_settlement, **savings_sweep**) sorted by date (newest first), with movement types labeled and color-coded (green for positive/income-like movements, amber for borrow/advance_settlement, teal for savings_sweep outflows).

### `CloseoutRules.vue` (`resources/js/pages/CloseoutRules.vue`)
Closeout rules now support an optional **Closeout Expense Category** selector (`closeout_expense_category_id`) sourced from expense categories. The selected category is used for backend-generated closeout movement rows (fund/debt allocations during hard close, and title completion transactions).

### `Debts.vue` (`resources/js/pages/Debts.vue`)
Shows "You Owe" and "Owed to You" sections.

**Warning — naming inversion:** The API returns `{ owed, owing }` where:
- `owed` = debts where auth user is the **debtor** (they owe money)
- `owing` = debts where auth user is the **creditor** (others owe them)

The Vue page uses `debts.owing` for "You Owe" and `debts.owed` for "Owed to You" — **this is reversed from the backend key names**. This is a known bug.

**Interest settings:** Add/Edit Debt modals include an **Apply monthly interest at closeout** toggle, APR input, and optional **Loan Received Date**. When enabled, payloads include `interest_enabled=true` and `interest_rate` (annual %). Debt cards show `Interest: X.XX% APR` and loan received date when present. Edit Debt normalizes API date values (`YYYY-MM-DDTHH:mm:ss…`) to `YYYY-MM-DD` for the date input via `dateStringForInput` in `openEditDebtModal`.

**Inter-family debt cards:** For debts between two family members (`creditor_id` present), the debt page hides the **Original** amount on the card and in the history modal summary line; only the **Remaining** balance summary is shown there. These cards also hide the progress bar and `% paid/% collected` labels, so inter-family debt display focuses on current owed/owing amount instead of cumulative collection progress.

**Pay Debt modal:** Amount input has no HTML `max` cap (server validates external debts). For inter-family debts (`creditor_id` present, not `is_family_debt`), a hint below **Amount to Pay** explains that paying more than the balance reverses the debt to the other person. `openPayModal` still pre-fills the amount with the current balance.

**Payment History Modal:** When viewing a debt's payment history via the **History** button, the modal displays `GET /debts/{id}/payments` (`entries` plus lineage `contributions` / `remaining`). Timeline rows include normal payment transactions plus synthetic entries. For **inter-family** running debts, `entries` follow **overpayment reversal lineage** (`reversed_from_debt_id`), so History on a newly created reverse debt still shows the original loan and payments, but an independent second loan between the same people stays on its own History. Every row uses the same card layout: a kind badge (**Loan** / **Direction reversed** / **Payment** / interest / loan addition / loan received), a `From → To` money-flow line (using **You** when the viewer is a party), date, optional description, and a signed amount. Loan-side rows (`initial_value`, income addition, loan receipt) use **`+`**; repayment rows use **`-`** (emerald). Direction-reversal initials use an orange badge. Remaining uses the lineage open balance (not only the opened card) with a short who-owes line. Closeout contribution cards (split settlements added into debt principal) render above the timeline from the envelope `contributions` as **red positive** values (`+`). Closeout-tagged payment rows include directional copy clarifying whether the closeout payment was **paid by you** or **received by you**.

### `Categories.vue` (`resources/js/pages/Categories.vue`)
Family category management. Create/edit/delete categories. Includes `IconPicker` component. **Type** is chosen with **Income** or **Expense** radios (mutually exclusive). Split default (`is_split_default`, `split_default`) and default advance fund (`advance_fund_id`) UI appears **only for expense** categories; switching type to income clears those fields locally and the API strips them for income-only categories. Turning on **Use as split default** seeds **equal percentages** across `familyUsers` (`equalSplitPayloadForFamilyUsers`); opening an existing split-default category keeps saved `split_default` until the user clears and re-enables the checkbox. Split defaults remain family-shared, while advance-fund and non-necessity defaults are read/written per user-category by the backend. When an expense category has an advance fund selected and that fund row includes `has_non_necessity_rule=true`, the modal shows a **Default transactions as non-necessity** checkbox (`is_non_necessity_default`); switching category type away from expense or clearing the advance fund resets the checkbox locally. Fetches funds for the advance-fund dropdown. The list filters by Income/Expense tabs; each row is **tap/click to edit** (opens the bottom sheet), with a separate **Delete** control on the right (`@click.stop` so delete does not open edit). List shows a single Income or Expense badge; "Split Default" only shows when the category has the flag and is an expense category, and "Non-Necessity Default" appears for expense categories where `is_non_necessity_default=true`.

After create/update/delete, the page dispatches a `window` event (`categories-changed`) so shared shells (notably `AppNav` + global `TransactionForm`) refresh category options immediately without requiring a hard page refresh.

### `MyFamily.vue` (`resources/js/pages/MyFamily.vue`)
Shows current user's family info and members. Only accessible to `head_of_household` or `admin` (guarded server-side by `can:manage_family`). Allows adding/removing members.

### `Settings.vue` (`resources/js/pages/Settings.vue`)
Available to every authenticated user from the Account bottom sheet. Shows the signed-in email (read-only). Password form posts to Fortify `PUT /user/password`. **View all family expenses** toggle (`PUT /user/settings`) persists `users.view_family_expenses` and refreshes `useAuth` / localStorage. Hidden when the user has no `family_id`. Family view is a household overlay on Transactions and Month Summary; on Transactions the viewer can still edit/delete their own rows, while other members’ rows stay browse-only.

### `MonthSummary.vue` (`resources/js/pages/MonthSummary.vue`)
Displays a comprehensive financial summary for a specific month (route param: `/month-summary/:yearMonth`, e.g., `/month-summary/2026-05`). A top **View month** selector allows switching to other month-summary routes without leaving the page. Changing that selector (or landing on a valid `:yearMonth`) writes **`useSelectedMonth`**, so returning to Transactions shows the same month. An invalid route param is replaced with the shared month. Shows:
- **Close status header:** Lock icon uses app-wide month-state colors: hard-closed (amber/yellow closed lock), viewer soft-closed (blue closed lock), or open (gray open lock). **Hard Close** is shown when the month is not hard-closed, every member has soft-closed, and the signed-in user has **`can_manage_family`** (`head_of_household` or `is_admin`), matching `MonthCloseoutController` authorization. When the viewed month is already hard-closed and the user has `can_manage_family`, the header shows an **Undo Hard Close** destructive action that prompts a native confirmation dialog before posting to `/closeout/undo-hard-close`.
- **Your expenses / your income categories:** Viewer-scoped monthly totals grouped by category (expense totals in red, income in green; **split bills use only your portion** — same rules as **`GET /month-summary` `category_totals`**; **solo expenses omit `is_closeout_initiated`**, matching **Projected closeout → Expenses**; **solo income omits fund borrows (`is_borrow`)**, matching **Gross Income**). Tracked **debt repayments** you pay use the **transaction’s category** when set; otherwise they appear under **Uncategorized Debt Payments** (`category_id` sentinel **-1**). **Debt repayments** below still lists each repayment for context. Each section sorts categories by amount (highest first), shows the **top four** by default with a **full-width** control to reveal the rest when there are more, then a **Total expenses** / **Total income** summary row for **all** categories in that section (not only the visible four). When Settings **View all family expenses** is on, each category row and the section totals show **You** large and colored (expense red / income green) with the **Family** combined amount in smaller gray type underneath (from **`family_category_totals`**; splits counted once at full amount; payments from one family member to another omitted from **Family** but still in **You**; categories only others used still appear with You $0). When `rule_preview.basis.non_necessity_expenses > 0`, the expenses footer also shows **Total Necessities** (`expenseCategoriesTotal - nonNecessityExpenses`) and **Total Non-Necessities** (violet), as sub-rows under **Total expenses** (viewer-only; not dual You/Family). Notes under **Your Expenses** / **Your Income** tie **Projected closeout** to the same exclusions (hard-close ledger lines → **Fund In/Out** / **Debt Repayments**; debtor repayments and borrows excluded from gross).
- **Category transaction detail modal:** Tapping any category row in **Your Expenses** or **Your Income** opens a bottom-sheet modal listing every transaction that contributes to that category total for the selected month (`summary.category_transactions`, or `summary.family_category_transactions` when family view is on). Expense rows display the viewer’s counted amount in personal view (including split-share amounts and synthetic uncategorized debt-payment bucket entries), matching the total math; family view lists unique household rows at full amount with **owner name**; other members use a yellow family icon + name and a 4px yellow left bar (including overlay-off other-paid splits), and type color is a thick bottom bar matching Transactions (**green** income, **red** expense, **diagonal violet + type color** on splits).
- **Modal description/split rendering:** In that category detail modal, when a transaction description is empty the UI falls back to the category name. Rows flagged as split show a **Split transaction** indicator and participant breakdown lines (`name`, `%`, and amount) from `split_breakdown`.
- **Income note:** Explains that debtor repayments owed to you are excluded from income category totals; see Debt repayments
- **Debt repayments:** Dedicated section backed by JSON `debt_repayments.{paid,received}` — sky-tint rows for repayment **received**, amber tint for repayment **paid**; **`paid`** amounts reflect **your split share** when the payer-side repayment was split among family members; copy states these are excluded from gross income / allocation rules at hard close. When family view is on, **family-shared debts** (`is_family_debt`) use `debt_repayments.family_debt_paid` instead of individual paid rows: each debt shows **You** (viewer contribution) large and **Family** (household total, splits counted once) underneath.
- **Split balances (this month):** Placed after **Your Income** — net shared-expense IOUs vs each member (**`member_balances`**); excludes repayment and closeout split parents; omits counterparties who net to zero or had no qualifying split activity vs you. Each member row also shows source subtotals from split transactions **you created** vs **they created**; UI signs are source-directional, so **your created split transactions** render positive/green (they owe you) and **their created split transactions** render negative/red (you owe them), independent of the final net row. Each source has a History button that opens a transaction-detail bottom sheet with the same source-directional signs. In that sheet, rows are grouped by category with a category header (including icon when available), then ordered by transaction date within each category. If a row has no description, the row label falls back to the category name.
- **Fund In/Out:** Displays monthly fund movement activity grouped by fund, including non-rule and rule-related movements (borrow, repayment, initial value, closeout allocation, advance settlement) with in/out/net totals
- **Projected Closeout / Closeout Results:** Dry-run preview of the month's fund allocation rules with basis (gross income, expenses, gross-base rule allocations, **signed** remaining). The summary row relabels the expense term to **Necessity Expenses** when `rule_preview.basis.non_necessity_expenses` is present (> 0), to clarify that `basis.total_expenses` excludes non-necessity advance spending. The **Gross-base rules** summary figure nets month **advances** against **fund**-destination gross rules so the same advance is not counted both in the necessity expense basis and again in full rule nominal amounts. **`rulePreviewNet()`** prefers **`net_after_advances`** when present, so **debt-destination rules** surface the **capped** payoff (matches hard-close **`allocateToDebt`**) rather than the **nominal** **`projected_amount`** line from the JSON. When remaining is **negative**, an **amber warning** appears at the top of the section; the summary row highlights remaining in amber. An **“How expenses count here”** card lists server-provided **`expense_closeout_basis.lines`** and explains how remaining relates to gross-base rules vs. the expense total. Each rule shows **`net_after_advances`** (fund rules: nominal allocation minus advances toward that fund—**negative** when advances exceed the rule line); subtitle lines clarify **rule allocation minus advances** for fund destinations only. **Fund-destination rules** that have month advance transactions tagged to that fund (`fund_advance_transactions` keyed by `destination_id`) are tappable: a bottom-sheet modal lists each advance row (date, category icon/name, description, non-necessity badge, amount) plus a **Total advances** footer matching closeout netting scope.
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

When an expense has `advance_fund_id` set and the selected fund has `has_non_necessity_rule=true` (from `GET /funds`), the advance section shows a **Mark as non-necessity** sub-toggle. Submit sends `is_non_necessity=true` only when expense + non-split + advance selected + qualifying fund rule are all true; otherwise it sends false. The toggle is automatically cleared when split is enabled, debt-payment mode is enabled, type changes away from expense, or advance fund is cleared. Category changes also auto-apply `is_non_necessity_default` when present and the selected advance fund qualifies.

The form watches `transaction_date` and posts `/closeout/status` for that month. If the family month is hard-closed, or the signed-in user has soft-closed that month, the selected date is allowed to remain and a blue closed-month warning banner is shown at the top. Save/create remains blocked on submit (red inline error appears only after submit attempt). When the user changes back to an open month, those warnings/errors clear and normal save flow resumes. Backend `ClosedMonthGuard` is still authoritative for the **owner/payer**; other members’ soft closes do not block an open user from saving splits that include them.

When **split** is turned on (manually or because the category has a split default), initial `split_data` is **equal shares across all `familyUsers`** (via `resources/js/support/equalFamilySplit.js`); the user can still adjust percentages in `SplitEditor` or tap **Equal Split** to rebalance.

For **income**, a dedicated debt association block supports:
- `No` (plain income)
- `Existing` (attach income to an existing debt you owe; increases debt)
- `New Debt` (create debt on submit, external creditor or family member, optional family-shared flag)
- Income → New Debt now also supports debt settings: `Apply monthly interest at closeout` and APR %. Loan received date for this path is derived from the transaction date.

**Loan repayment received (income):** Toggle **Family member repaying a loan to me** (`DebtRepaymentReceivedOptions.vue`) — picks a debt from `debtsPayload.owing` with balance; creates the same mirror as the debtor’s “Pay toward a tracked debt” flow.

**Expense repayment (income):** Optional toggle **Family member paying me back for expenses I covered** sets `is_repayment_mode`, picks another family member (`repayment_for_user_id`, excluding self), and multi-selects repayable expenses from `GET /transactions/repayable-expenses` (`repayment_links` must sum to the income amount). **External reimbursement:** separate toggle **Outside party reimbursed me for an expense I covered** sets `is_external_repayment_mode` and links the auth user's own repayable expenses (no family member, no mirror expense). The two toggles are mutually exclusive. Client validation blocks submit when member, links, or totals are missing/mismatched. Edit mode pre-fills from `transaction.repaymentLinks` / `is_repayment` and `is_external_repayment` on the first link.

### `PlaidImportSplitLineOptions.vue` (`resources/js/components/PlaidImportSplitLineOptions.vue`)
Per-line options for Plaid **split import** rows (`PlaidImportReview.vue`). Mirrors `TransactionForm` income/expense option blocks: income debt modes, **expense-repayment income** (via shared `PlaidImportRepaymentOptions.vue`), expense **Match to existing transaction** (toggle + dropdown from `GET …/split-link-candidates?amount=`), expense pay-toward-debt, family split + `SplitEditor`, advance fund + non-necessity. Match-existing mode is mutually exclusive with pay-toward-debt, split, and advance fund. Parent owns line state (`match_existing_mode`, `link_to_transaction_id`, `is_repayment_mode`, etc.); `fetchSplitLinkCandidates` in `PlaidImportReview.vue` loads candidates per line; `buildSplitLinePayload` / `validateSplitLine` / `splitLinesValid` enforce link selection before `POST …/confirm-split`.

### `PlaidImportRepaymentOptions.vue` (`resources/js/components/PlaidImportRepaymentOptions.vue`)
Shared repayment picker for Plaid import review: **To Review** single confirm, **split lines**, **Dismissed** restore, and **Auto-created** correction forms. Label: **Family member paying me back** (creates mirror expense on their account). Clears income-debt fields when enabled. Loads `GET /transactions/repayable-expenses` when the toggle is on.

Editing now allows debt-payment **expense** transactions and keeps repayment mode active while editing those rows. Debt-payment **income** mirror rows show a non-editable banner to direct users to edit the payer expense row instead. Submit omits split fields for income and includes income debt fields only when selected. Emits `created`, `updated`, or `close` events.

### `SplitEditor.vue` (`resources/js/components/SplitEditor.vue`)
Sub-component of `TransactionForm`. Renders a list of family members with percentage inputs. Validates that percentages sum to 100 before allowing submission. The **Equal Split** button uses the same proportional rounding helper as initial defaults (`equalSharePercentages` in `resources/js/support/equalFamilySplit.js`) so percentages sum to exactly 100.

### `AppNav.vue` (`resources/js/components/AppNav.vue`)
Bottom navigation bar with 4 primary nav links (Dashboard, Transactions, Funds, Debts) and an Account button. The Account button opens a bottom-sheet menu containing **Bank connections** (with a small **red badge** when `GET /plaid/pending-imports?count_only=1` returns `count > 0`; count is loaded on shell mount and refreshed when the menu opens), Categories, Closeout Rules, **Settings**, My Family (if applicable), Admin links (if admin), and Logout. Also contains the FAB (floating action button) that opens the `TransactionForm` modal. On mount it fetches categories, family users, funds, **debts**, and the pending-import count; those props are passed into `TransactionForm` so advance-fund and **pay toward debt** work from the global FAB on every page.

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

There is **no Vuex or Pinia**. State is managed locally in each page component using Vue 3 `ref`/`reactive`. Cross-page client state is limited to module-level composable refs: **`useAuth`** (auth user, also `localStorage`) and **`useSelectedMonth`** (Transactions / Month Summary calendar month, also `sessionStorage`). Pages independently fetch their data on `onMounted`.

## Axios configuration

`resources/js/bootstrap.js` configures `window.axios` with:
- `X-Requested-With: XMLHttpRequest` header (triggers `expectsJson()` on Laravel side)
- CSRF token from `<meta name="csrf-token">` via `axios.defaults.headers.common['X-CSRF-TOKEN']`

`resources/js/app.js` extends Axios behavior with a global auth-timeout interceptor so expired sessions do not leave the SPA in a broken authenticated UI state.
