# End-to-end: Engage ⇄ Manage ⇄ my.aicountly (plans, licensing, coupons)

`tests/e2e/run.php` proves the whole chain with real servers, a real PostgreSQL, real AIC-S2S signatures and the
real command-line jobs. It is not part of `phpunit` (it starts processes and needs three checkouts); run it by hand
before activating the chain in an environment, or after changing any side of it.

| Step | What is proven |
| --- | --- |
| a | Staff (Engage API, JWT) create licence types, publish a **Basic** default offer for books, inventory, billing, notes, docs and a paid `books-pro` |
| b | my.aicountly `php spark accounts:engage-provision` (sweep + signed send) → Engage issues one live Basic licence per product; a second run changes nothing |
| c | `php spark engage:entitlements-push` → Manage `ghm_plan_entitlement`; `GET /api/companyinfo?cmp_id=&app=books` shows the Basic `plan`; Basic `max_companies` 1 refuses a 2nd company |
| d | Coupon (20 %, capped at ₹1,500; maker drafts, a second person activates), staff quote (list ₹10,000 → net ₹8,500 + GST on the net), paid licence issued with `quote_ref` → redemption `committed` with the licence; push → Manage shows the paid plan |
| e | Manage PlanGate: a member creating in the owner's workspace consumes the **owner's** `max_companies`; the next creation (owner or member) → `422 PLAN_LIMIT_REACHED` |
| f | Past end → `php spark engage:licences-expire` → Basic reactivated and pushed → Manage back on Basic, company lifecycle still `active`, `ghm_subscription` untouched, nothing deleted |
| g | Manage `POST /api/billing/coupons/preview` and `/upgrade-requests` → live Engage (quote key) |
| h | Manage `php scripts/plans_sync.php` stores the Basic defaults from Engage's catalogue (no `cmp_id`); `engage:entitlements-reconcile` (read key) reports 0 drift |

## Run

```bash
# 1. dependencies (Engage only; Manage and my.aicountly need none)
(cd engage-aicountly/server-php && composer install)

# 2. a PostgreSQL 16 the script may CREATE/DROP databases on (any superuser-ish role)
export E2E_PG_HOST=127.0.0.1 E2E_PG_PORT=5432 E2E_PG_USER=postgres E2E_PG_PASSWORD=…

# 3. the other two checkouts (integration branch claude/rectify-2026-10 or later)
export E2E_MANAGE_DIR=/path/to/manage-aicountly E2E_MYAIC_DIR=/path/to/my-aicountly-com

php engage-aicountly/server-php/tests/e2e/run.php      # exit 0 = all passed, 1 = a failure
```

- my.aicountly's database connection always uses port 5432. On another port, also set `E2E_PG_SOCKET_DIR` to the
  server's unix-socket directory: the script links `.s.PGSQL.5432` there for the run and removes it afterwards.
- `E2E_TRANSCRIPT=/path/file.txt` writes every command the script ran with its output; `E2E_KEEP=1` keeps the three
  databases (`e2e<random>_engage|_manage|_myaic`) and the temp folder for inspection.
- Missing prerequisites (no `E2E_MANAGE_DIR` / `E2E_MYAIC_DIR`, no `vendor/`, no `pdo_pgsql`, database unreachable)
  print `SKIP …` and exit 0.

## What it sets up (nothing is written to any repository)

- Three fresh databases; Engage `php spark migrate` + `RolesSeeder` + `ProductsSeeder`; Manage `schema.sql` (without
  GRANTs) + `scripts/migrate.php --run`; my.aicountly a minimal `useraictly` + `accounts:migrate-engage-provisioning`.
- Random 64-character keys for every link, passed only as process environment:

  | Link | Caller env | Receiver env |
  | --- | --- | --- |
  | Engage → Manage push | `ENGAGE_OUTBOUND_MANAGE_KEY_ID/_SECRET` | `MANAGE_INBOUND_ENGAGE_*` scope `push` |
  | Engage → Manage reconcile | `ENGAGE_OUTBOUND_MANAGE_READ_KEY_ID/_SECRET` | `MANAGE_INBOUND_ENGAGE_*` scope `read` |
  | Manage → Engage catalogue | `MANAGE_S2S_ENGAGE_KEY_ID/_SECRET` | `ENGAGE_INBOUND_MANAGE_*` scope `read` |
  | Manage → Engage coupons | `MANAGE_S2S_ENGAGE_QUOTE_KEY_ID/_SECRET` | `ENGAGE_INBOUND_MANAGE_*` scope `quote` |
  | my.aicountly → Engage provisioning | `MYAICOUNTLY_S2S_ENGAGE_KEY_ID/_SECRET` | `ENGAGE_INBOUND_MYAICOUNTLY_*` scope `issue` |

  Environment `local` on all three (`ENGAGE_S2S_ENVIRONMENT`, `MANAGE_S2S_ENVIRONMENT`, `MYAICOUNTLY_S2S_ENVIRONMENT`).
- Engage and Manage under `php -S`, both at `/api` like production. Manage runs through its own test router
  (`server-php/tests/support/manage_http_router.php`), whose only difference from production is the session check:
  `Authorization: Bearer ses-<uuid>` is the signed-in person `<uuid>` (no my.aicountly portal needed). Engage staff
  tokens are minted with the run's `ENGAGE_JWT_SECRET` exactly as Engage issues them.
