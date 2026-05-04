# 05 — Auth & Permissions

## Authentication mechanism

**Laravel Fortify** provides session-based authentication. No API tokens or JWT are used.

Key files:
- `app/Providers/FortifyServiceProvider.php` — registers Fortify actions, rate limiters
- `app/Actions/Fortify/CreateNewUser.php` — user creation logic
- `config/fortify.php` — Fortify config

### Login flow
1. Vue's `useAuth().login(email, password)` POSTs to `/login` (handled by Fortify)
2. Laravel sets a session cookie
3. `fetchUser()` calls `GET /user` — returns the authenticated user JSON
4. User object is stored in `localStorage` and reactive state
5. All subsequent Axios requests include the session cookie + CSRF token automatically

### Logout flow
1. POST `/logout` (Fortify route) — invalidates session server-side
2. `localStorage.removeItem('user')` — clears client-side user state

### Session timeout/expiry flow
1. Any Axios response with `401` (unauthenticated) or `419` (CSRF/session expired) is intercepted globally in `resources/js/app.js`
2. Interceptor clears `localStorage.user`
3. Browser is hard-redirected to `/login`

### CSRF
`app.blade.php` contains `<meta name="csrf-token" content="{{ csrf_token() }}">`. `bootstrap.js` reads this and sets `axios.defaults.headers.common['X-CSRF-TOKEN']`.

## User roles

Roles are stored as a plain `varchar` column on `users.role`. Valid values:

| Role | String value | Description |
|---|---|---|
| Admin | `admin` | Full system access; manages all users and families |
| Head of Household | `head_of_household` | Can manage their own family; can add/remove members |
| Member | `member` | Regular user; can use all financial features |

## Computed user attributes (appended to JSON)

These are computed in `User.php` and serialized with every user response:

| JSON key | Logic |
|---|---|
| `is_admin` | `role === 'admin'` |
| `is_head_of_household` | `role === 'head_of_household'` |
| `can_manage_family` | `role === 'admin' OR role === 'head_of_household'` |

The frontend normalizes these into `isAdmin` via `normalizeAuthUser()`.

## Laravel Gates (defined in `AppServiceProvider`)

| Gate name | Passes when |
|---|---|
| `admin` | `user->isAdmin` (i.e. `role === 'admin'`) |
| `head_of_household` | `user->is_head_of_household` |
| `manage_family` | `role` is `admin` or `head_of_household` |

## Route middleware

| Middleware | Applied to |
|---|---|
| `auth` | All routes except `/`, `/login`, `/dashboard` (SPA shells), `/categories` (SPA shell), `/admin/categories` (SPA shell), `/my-family` (SPA shell) |
| `can:admin` | `POST /admin/users`, `PUT /admin/users/{user}`, `DELETE /admin/users/{user}`, `GET /admin/users`, `GET /admin/families`, `POST /admin/families` |
| `can:manage_family` | `PUT /admin/families/{family}`, `DELETE /admin/families/{family}`, `POST /admin/families/{family}/users`, `DELETE /admin/families/{family}/users/{user}`, `GET /my-family` |

**Note:** The SPA shell routes (`Route::view`) are public (no `auth` middleware). The server only enforces auth on the JSON endpoints. Browser navigation to any path always returns the SPA shell, and the Vue router handles auth client-side.

## Policies

### FundPolicy (`app/Policies/FundPolicy.php`)
- `view(User, Fund)` → `$user->id === $fund->user_id`
- `update(User, Fund)` → `$user->id === $fund->user_id`
- `delete(User, Fund)` → `$user->id === $fund->user_id`

Used by `FundController` via `$this->authorize('view'|'update'|'delete', $fund)`.

### DebtPolicy (`app/Policies/DebtPolicy.php`)
- `view(User, Debt)` → user is debtor or creditor AND same family

**Not currently invoked** by `DebtController` — the policy exists but `$this->authorize()` is not called.

## Frontend auth guards (Vue Router)

Located in `resources/js/router/index.js` `beforeEach`:

- `requiresAuth`: if no user in localStorage → redirect to `/login`
- `guest`: if user in localStorage → redirect to `/dashboard`
- `adminOnly`: if user in localStorage but `!user.isAdmin` → redirect to `/dashboard`

These are **UI-only guards** based on localStorage. The server enforces real authorization.

## 2FA

Fortify 2FA columns exist in the `users` table migration. `FortifyServiceProvider` registers `RedirectIfTwoFactorAuthenticatable`. **No 2FA UI is implemented in the Vue app.** 2FA is scaffolded but effectively unused.

## Known permission gaps

1. **`CategoryController`** — no authorization policy or ownership check. Any authenticated member of any family can edit/delete any category (if they know the ID).
2. **`DebtController`** — `DebtPolicy` is not applied. Any authenticated user can access debt records as long as they match `family_id` (index) or have no guard at all (store has family membership check; payDebt checks debtor_id).
3. **`TransactionController::update/destroy`** — checks `user_id === auth()->id()` OR `family_id === auth()->family_id`. Family members can delete each other's transactions.
4. **`AdminController::createFamily`** — requires `can:admin` gate. But `updateFamily`, `deleteFamily`, `addFamilyMember`, `removeFamilyMember` use `can:manage_family`, which includes `head_of_household`. The logic inside manually re-checks role for head_of_household scope.
