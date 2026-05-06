# 04 — Database

## Engine

- **Development/test:** MySQL
- **Local MySQL instance:** `.local/mysql/data/money_tracker_test/` (used for local testing)
- **PHPUnit:** Uses environment-provided DB config (`.env.testing` / process env); `phpunit.xml` does not hardcode DB credentials
- Default `DB_CONNECTION` in `.env.example` is `mysql`

## Migrations

All custom migrations are dated `2026-04-30` or later. Key migrations:

| File | Creates / Modifies |
|---|---|
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_04_30_190721_add_two_factor_columns_to_users_table` | Adds Fortify 2FA columns to `users` |
| `2026_04_30_190832_create_families_table` | `families` |
| `2026_04_30_190833_create_categories_table` | `categories` |
| `2026_04_30_190834_create_funds_table` | `funds` |
| `2026_05_03_160512_add_family_id_to_funds_table` | Nullable `family_id` on `funds` for family-shared buckets |
| `2026_04_30_190835_create_transactions_table` | `transactions` |
| `2026_04_30_190836_create_fund_rules_table` | `fund_rules` |
| `2026_04_30_190837_add_family_id_and_role_to_users_table` | Adds `family_id`, `role` to `users` |
| `2026_04_30_190838_create_debts_table` | `debts` |
| `2026_04_30_190839_create_fund_movements_table` | `fund_movements` |
| `2026_04_30_190840_create_transaction_splits_table` | `transaction_splits` |
|| `2026_05_04_013436_add_is_admin_to_users_table` | Adds `is_admin` boolean to `users`; migrates existing `admin` role to `head_of_household` + `is_admin=true` |
| `2026_05_04_010914_add_debt_scope_fields_to_debts_table` | Adds `is_family_debt`, `creditor_name` to `debts` |
| `2026_05_04_164628_add_debt_id_to_transactions_table` | Adds nullable `debt_id` FK (`nullOnDelete`) to `transactions` |
| `2026_05_04_183714_add_payment_details_to_transactions_table` | Adds `paid_by_user_id` FK (`nullOnDelete`) and `is_closeout_initiated` boolean to `transactions` |
| `2026_05_04_204012_fix_debts_transaction_id_cascade_and_repair_orphans` | Sets `is_pending_closeout=false` on pending debts with null `transaction_id`; replaces `debts.transaction_id` FK with `cascadeOnDelete` (was `nullOnDelete`) |
| `2026_05_04_205520_add_contributions_to_debts_table` | Adds nullable `contributions` JSON column to `debts` |
| `2026_05_04_211754_repair_missing_april_2026_split_debt` | Data repair: inserts a confirmed April 2026 inter-family split debt for family 1 if missing; no-ops in test environments |
| `2026_05_05_212303_ensure_categories_are_income_xor_expense` | Data repair: normalizes `categories` so each row is income-only or expense-only (not both / not neither) |
| `2026_05_05_214052_add_mirror_transaction_id_to_transactions_table` | Nullable `mirror_transaction_id` self-FK linking paired debt-payment expense ↔ creditor income rows |

## Table schemas

### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar | |
| `email` | varchar unique | |
| `email_verified_at` | timestamp nullable | |
| `password` | varchar | hashed |
| `remember_token` | varchar nullable | |
| `family_id` | bigint FK nullable | → `families.id` |
| `role` | varchar | `head_of_household` \| `member` (no more `admin` role — admin is now a boolean) |
| `is_admin` | boolean | default false; grants system-admin permissions independent of family role |
| `two_factor_*` | various | Fortify 2FA columns |
| `timestamps` | | |

### `families`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar | |
| `description` | text nullable | |
| `timestamps` | | |

### `categories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `family_id` | bigint FK | → `families.id` |
| `name` | varchar | |
| `icon` | varchar nullable | emoji or icon identifier |
| `is_income` | boolean | default false; **must be the logical opposite of `is_expense`** (exactly one true per row) |
| `is_expense` | boolean | default false; **must be the logical opposite of `is_income`** |
| `is_split_default` | boolean | default false |
| `split_default` | json nullable | default split percentages `[{user_id, share_percentage}]` |
| `advance_fund_id` | bigint FK nullable | → `funds.id` with `nullOnDelete`; default advance fund for **expense** transactions in this category (cleared when category is not expense-capable) |
| `timestamps` | | |

### `funds`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK | → `users.id` (owner/creator) |
| `family_id` | bigint FK nullable | → `families.id`; null = personal fund only |
| `name` | varchar | |
| `description` | text nullable | |
| `balance` | decimal(15,2) | default 0 |
| `timestamps` | | |

### `fund_rules`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK | → `users.id` |
| `fund_id` | bigint FK **nullable** | → `funds.id`; null when rule targets a debt or title instead of a fund |
| `name` | varchar | |
| `order` | integer | processing priority (1 = first) |
| `allocation_type` | varchar | `percentage` \| `fixed` |
| `amount` | decimal(15,2) | percentage value (e.g. 10 for 10%) or fixed amount |
| `allocation_base` | varchar nullable | `gross_income` \| `net_income` \| `remaining`; only meaningful when `allocation_type = 'percentage'` |
| `is_active` | boolean | default true |
| `destination_type` | varchar(16) | `fund` \| `debt` \| `title`; determines where the allocation goes |
| `destination_id` | bigint nullable | FK to the target `fund` or `debt` id when `destination_type` is `fund` or `debt` |
| `destination_title` | varchar(255) nullable | Custom title string when `destination_type = 'title'` (e.g., "Medical Reserve") |
| `timestamps` | | |

### `transactions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `family_id` | bigint FK | → `families.id` |
| `user_id` | bigint FK | → `users.id` |
| `category_id` | bigint FK nullable | → `categories.id` |
| `fund_id` | bigint FK nullable | → `funds.id` (set on borrow transactions) |
| `type` | varchar | `income` \| `expense` |
| `amount` | decimal(15,2) | |
| `description` | text nullable | |
| `transaction_date` | date | |
| `is_split` | boolean | default false |
| `split_data` | json nullable | snapshot: `[{user_id, share_percentage}]` |
| `is_borrow` | boolean | default false — income from fund borrow |
| `is_debt_payment` | boolean | default false — payment transaction |
| `debt_id` | bigint FK nullable | → `debts.id` with `nullOnDelete`; links a payment transaction to the debt it settles |
| `paid_by_user_id` | bigint FK nullable | → `users.id` with `nullOnDelete`; tracks who initiated the payment (for multi-user families) |
| `is_closeout_initiated` | boolean | default false; true when the payment was generated by a month closeout rule |
| `advance_fund_id` | bigint FK nullable | → `funds.id` with `nullOnDelete`; marks an expense transaction as advancing against a fund (settled at month hard-close) |
| `mirror_transaction_id` | bigint FK nullable | → `transactions.id` with `nullOnDelete`; paired leg for unsplit debtor expense ↔ creditor **income** when repaying an in-member debt (`is_debt_payment`) |
| `timestamps` | | |

### `transaction_splits`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `transaction_id` | bigint FK | → `transactions.id` |
| `user_id` | bigint FK | → `users.id` |
| `share_percentage` | decimal(5,2) | |
| `amount` | decimal(15,2) | computed dollar amount |
| `timestamps` | | |

### `debts`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `family_id` | bigint FK | → `families.id` |
| `debtor_id` | bigint FK | → `users.id` |
| `creditor_id` | bigint FK nullable | → `users.id` (null for fund-borrow or external debts) |
| `fund_id` | bigint FK nullable | → `funds.id` (set when debt is to a fund) |
| `transaction_id` | bigint FK nullable | → `transactions.id` (`cascadeOnDelete` — deleting the split transaction deletes this debt row) |
| `amount` | decimal(15,2) | original amount |
| `balance` | decimal(15,2) | remaining unpaid |
| `description` | text nullable | |
| `contributions` | json nullable | Closeout contribution records: `[{month, year, amount}]`; used by the debt history modal to show closeout additions separately from manual payments |
| `is_family_debt` | boolean | false = personal debt; true = visible to all family members |
| `creditor_name` | varchar(255) nullable | name for external creditors (e.g., "Bank of America"); used when `creditor_id` is null |
| `is_pending_closeout` | boolean | true during month hard-close split processing |
| `timestamps` | | |

### `fund_movements`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `fund_id` | bigint FK | → `funds.id` |
| `user_id` | bigint FK | → `users.id` |
| `type` | varchar | `allocation` \| `borrow` \| `repayment` |
| `amount` | decimal(15,2) | |
| `transaction_id` | bigint FK nullable | → `transactions.id` |
| `timestamps` | | |

## Entity relationship summary

```
families ──< users ──< funds ──< fund_rules
                │             └──< fund_movements
                │             └──< debts (fund_id)
                ├──< categories
                └──< transactions ──< transaction_splits
                                  └──< debts (transaction_id)
debts: debtor_id → users, creditor_id → users (nullable)
```

## Factories

All models have factories in `database/factories/`:
- `UserFactory` — sets role to `member` by default; password is `password`
- `FamilyFactory`
- `CategoryFactory`
- `FundFactory` — balance defaults to 0
- `FundRuleFactory` — sets `is_active = true`
- `TransactionFactory`
- `DebtFactory`

No `FundMovementFactory` or `TransactionSplitFactory` exist.

## Seeder

`database/seeders/DatabaseSeeder.php` creates one default family (`Household`) and one admin user (`admin@example.com`) without factories/fake data.
