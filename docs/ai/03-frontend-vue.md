# 03 — Frontend (Vue 3)

## Entry point

`resources/js/app.js` creates the Vue app, installs Vue Router, and mounts `AppShell.vue` into `<div id="app">` (in `resources/views/app.blade.php`).
It also registers a global Axios response interceptor that treats `401`/`419` responses as session expiry, clears `localStorage.user`, and hard-redirects to `/login`.

## Component tree

```
AppShell.vue
├── AppNav.vue              (rendered when user is in localStorage)
│   ├── bottom nav bar      (Dashboard, Transactions, Funds, Debts, Categories)
│   ├── admin dropdown      (Users, Families, Admin Categories — isAdmin only)
│   ├── FAB button          (opens TransactionForm modal)
│   └── TransactionForm.vue (modal overlay, inline in AppNav)
│         └── SplitEditor.vue  (shown when "split" toggle is on)
└── <router-view>           (current page component)
```

`AppShell.vue` reads `user` from localStorage on `onMounted`. It only wraps the `router-view` in `AppNav` if a user is present; otherwise shows `router-view` directly (for the login page).

**Important:** `AppShell.vue` does not reactively update if localStorage changes while the app is running. It only reads on mount.

## Router (`resources/js/router/index.js`)

History mode (`createWebHistory`). Route definitions:

| Path | Component | Guard |
|---|---|---|
| `/login` | `Login.vue` | `guest` (redirect to `/dashboard` if logged in) |
| `/` | redirect → `/dashboard` | — |
| `/dashboard` | `Dashboard.vue` | `requiresAuth` |
| `/transactions` | `Transactions.vue` | `requiresAuth` |
| `/funds` | `Funds.vue` | `requiresAuth` |
| `/debts` | `Debts.vue` | `requiresAuth` |
| `/categories` | `Categories.vue` | `requiresAuth` |
| `/my-family` | `MyFamily.vue` | `requiresAuth` |
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
Manages authentication state. Reads from localStorage on mount.

- `login(email, password)` — POST `/login` (Fortify), then calls `fetchUser()`
- `logout()` — POST `/logout`, clears localStorage
- `fetchUser()` — GET `/user`, normalizes, saves to localStorage
- `user` — reactive ref

## Support utilities

### `normalizeAuthUser` (`resources/js/support/authUser.js`)
Normalizes the user object from either `/user` response or localStorage. Ensures `isAdmin` is a boolean:

```js
isAdmin: Boolean(raw.isAdmin ?? raw.is_admin ?? raw.role === 'admin')
```

## Pages

### `Login.vue` (`resources/js/pages/Login.vue`)
Standard email/password form. Uses `useAuth().login()`. On success, redirects to `/dashboard`.

### `Dashboard.vue` (`resources/js/pages/Dashboard.vue`)
Summary view: loads `/transactions`, `/funds`, `/debts`, and current-month `/split-debt-summary`. When `user.family_id` is set and there is at least one **calendar month** (derived from loaded `transaction_date` values) that is **not** in `GET /closeout/closed-months` (hard closes), it loads `/family/users` and `POST /closeout/status` for the **earliest such month** (chronologically first month with transactions that still needs a hard close) and shows a **Family close progress** block (same section/card patterns as split debt: uppercase section title `text-sm font-semibold text-gray-400`, month label under the title, card `bg-gray-800 border border-gray-700 rounded-xl p-4`). If every month that has transactions is already hard-closed (or there are no transactions), the block is **hidden**. Each **family member** row uses the same lock **icons** as **Transactions** (no extra border/background on the icon cell): **amber closed** when that month is hard-closed, else **blue closed** if that member has soft-closed, else gray **open** outline. **Hard close month** for `can_manage_family` when everyone has soft-closed and the month is not hard-closed; a short footnote names the month. Split-expense cards open a bottom sheet listing each pending split with **category** as the primary label and an optional **description** in smaller type directly beside it when present; no placeholder when the description is empty. Uses `useApi` (`get`, `post`) and `useAuth`.

### `Transactions.vue` (`resources/js/pages/Transactions.vue`)
Full transaction list with date filters (`start_date`, `end_date`). Supports editing (re-opens `TransactionForm`) and deleting. Each card uses a **single horizontal row** on narrow viewports (`flex-row`): category on the left (`flex-1 min-w-0`), a **capped-width** right column (`max-w-[12.5rem]` on small screens, wider from `sm`) for amount + split chip so they stay **beside** the title instead of stacking below it; lock and delete sit in a slim column to the right. Split rows (`splits` present): the primary amount is the **signed-in user’s split share**; below it a **purple-styled** control (same palette as the old “Split” chip: `bg-purple-900/50`, purple borders/text) reads **“Split: Total {amount} by {name}”** where the payer is **“You”** when `transaction.user_id` matches the signed-in user, otherwise the owner’s display name and opens a **`Teleport` modal** on click (`@click.stop` so the row does not open edit). The chip is a flex row with **no extra top margin** and **centered** inner lines (`flex items-center` on the button and wrapped text span) so copy sits visually centered in the pill. The modal lists each `TransactionSplit` with **amount** and **share percentage**, **sorted with the logged-in user first**, then others by name; the current user’s row is labeled **(You)**. Non-split rows show the full transaction amount only. Loads `GET /user` before the first transaction fetch so split primary amounts resolve on first paint.
Period **income** total and per-day income sums use each transaction’s **full amount**. Period **expense** total and per-day **expense** sums use **your split share** for split expenses (same rule as the primary amount on split rows); non-split expenses use the full amount. A short footnote under the period totals card explains split expense behavior. For a selected calendar month (not custom range), a lock icon beside the month dropdown reflects hard-close (amber locked), your soft-close (blue locked), or open-for-you (open lock outline). The sticky header **Close Out** / **Undo** control (top right) only appears when that month is **not** hard-closed and the loaded list has **at least one transaction**, except **Undo** still appears if you have already soft-closed (so you can reopen). **Family close progress** and **hard close** live on the **Dashboard**, not on this page.

### `Funds.vue` (`resources/js/pages/Funds.vue`)
Lists the auth user's personal funds. Shows balance, rules, and movements. Allows creating funds, adding/editing rules, borrowing from a fund, and repaying fund debts.

### `Debts.vue` (`resources/js/pages/Debts.vue`)
Shows "You Owe" and "Owed to You" sections.

**Warning — naming inversion:** The API returns `{ owed, owing }` where:
- `owed` = debts where auth user is the **debtor** (they owe money)
- `owing` = debts where auth user is the **creditor** (others owe them)

The Vue page uses `debts.owing` for "You Owe" and `debts.owed` for "Owed to You" — **this is reversed from the backend key names**. This is a known bug.

### `Categories.vue` (`resources/js/pages/Categories.vue`)
Family category management. Create/edit/delete categories. Includes `IconPicker` component. Supports `is_income`, `is_expense`, `is_split_default`, and `split_default` (JSON split template).

### `MyFamily.vue` (`resources/js/pages/MyFamily.vue`)
Shows current user's family info and members. Only accessible to `head_of_household` or `admin` (guarded server-side by `can:manage_family`). Allows adding/removing members.

### `admin/Users.vue` (`resources/js/pages/admin/Users.vue`)
Admin-only. Lists all users, create/edit/delete. Lets admin assign `family_id` and `role`.

### `admin/Families.vue` (`resources/js/pages/admin/Families.vue`)
Admin-only. Lists all families. Create families, manage members.

### `admin/Categories.vue` (`resources/js/pages/admin/Categories.vue`)
Admin-only route in the router. **Has no corresponding POST route on the backend** — `POST /admin/categories` does not exist in `web.php`. The regular `/categories` POST route serves all authenticated users. This page may be broken or unused.

## Components

### `TransactionForm.vue` (`resources/js/components/TransactionForm.vue`)
Modal form for creating or editing a transaction. Fields: type, amount, description, date, category, is_split toggle. When `is_split` is enabled, renders `SplitEditor`. On submit, calls `POST /transactions` or `PUT /transactions/{id}`. Emits `saved` event.

### `SplitEditor.vue` (`resources/js/components/SplitEditor.vue`)
Sub-component of `TransactionForm`. Renders a list of family members with percentage inputs. Validates that percentages sum to 100 before allowing submission.

### `AppNav.vue` (`resources/js/components/AppNav.vue`)
Bottom navigation bar + admin dropdown. Contains the FAB (floating action button) that opens the `TransactionForm` modal. Also shows user name and logout button.

### `IconPicker.vue` (`resources/js/components/IconPicker.vue`)
Simple emoji/icon selector used within `Categories.vue`.

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
