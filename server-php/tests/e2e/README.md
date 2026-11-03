# End-to-end: Engage ⇄ Manage ⇄ my.aicountly ⇄ AI Pulse (plans, licensing, coupons, AI credits)

`tests/e2e/run.php` proves the whole chain with real servers, a real PostgreSQL, real AIC-S2S signatures and the
real command-line jobs. It is not part of `phpunit` (it starts processes and needs several checkouts); run it by hand
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
| i | **AI credits** (`tests/e2e/credits.php`): `engage:basic-offers --apply` → a Basic offer for EVERY product Manage knows, published, and `engage:provision-basic` gives the holder one licence each (42 products, 41 app keys — `ourpeople` rides on `hrms`) |
| j | The token rate card: rates set and activated four-eyes in Engage, every model Console knows priced (model rate → provider default → Console tier → fallback), `engage:token-rates-push` → **a real AI Pulse** over signed AIC-S2S v1; an unsigned push is refused and changes nothing |
| k | The **₹1,000 coupon in four shapes** through Manage's own routes: flat / percent, with and without a minimum, single and multiple use; GST on the discounted price; a full discount still leaves ₹1 to invoice (never a ₹0 invoice); a purchase too small to charge for is refused and points at the voucher instrument |
| l | The purchase: order → Finance's payment-link contract (signature verified by an independent verifier, request body asserted) → `payment.succeeded` into Engage's real inbound route → order paid, grant queued, coupon redemption committed; a retry is a success and grants nothing twice; an unsigned payment event grants nothing |
| m | `engage:token-grants-push` → Pulse credits the wallet once (idempotent on the grant reference), then all 42 products each make a charged AI call against the one shared balance; the ledger nets to the balance and every charge records the multiplier it applied |
| n | The one transaction from three sides: Engage's order and redemption rows, Manage's `GET /api/billing/token-orders` (read key — the quote scope is POST-only), and the invoice number carried through |

## Run

```bash
# 1. dependencies (Engage only; Manage and my.aicountly need none)
(cd engage-aicountly/server-php && composer install)

# 2. a PostgreSQL 16 the script may CREATE/DROP databases on (any superuser-ish role)
export E2E_PG_HOST=127.0.0.1 E2E_PG_PORT=5432 E2E_PG_USER=postgres E2E_PG_PASSWORD=…

# 3. the other two checkouts (integration branch claude/rectify-2026-10 or later)
export E2E_MANAGE_DIR=/path/to/manage-aicountly E2E_MYAIC_DIR=/path/to/my-aicountly-com
# for steps i–n (AI credits); without it those steps print SKIP and the rest still runs
export E2E_PULSE_DIR=/path/to/pulse-aicountly   # needs its own composer install

php engage-aicountly/server-php/tests/e2e/run.php      # exit 0 = all passed, 1 = a failure
```

- my.aicountly takes the port from `DB_UUID_PORT` (externaldb::portFor), which the script passes from `E2E_PG_PORT`.
  Only a my.aicountly checkout older than that connects on 5432 alone: then, on another port, also set
  `E2E_PG_SOCKET_DIR` to the server's unix-socket directory: the script links `.s.PGSQL.5432` there for the run and
  removes it afterwards.
- `E2E_TRANSCRIPT=/path/file.txt` writes every command the script ran with its output; `E2E_KEEP=1` keeps the three
  databases (`e2e<random>_engage|_manage|_myaic`) and the temp folder for inspection.
- Missing prerequisites (no `E2E_MANAGE_DIR` / `E2E_MYAIC_DIR`, no `vendor/`, no `pdo_pgsql`, database unreachable)
  print `SKIP …` and exit 0.

### What stands in for a real service, and why

Engage, Manage, my.aicountly and **AI Pulse** are the real applications against real PostgreSQL,
and every hop between them is a real signed AIC-S2S v1 request over HTTP. Two things are stubbed,
both deliberately:

- **Console's model catalogue**, which is one read-only `GET /portal/ai-models`. The stub serves
  the shape Console serves and checks the bearer token.
- **Finance**, whose own half of this — the GST invoice, the gateway, the payment it re-reads from
  the provider — is proven by finance-aicountly's own suite (`tests/academy-db.php` covers the
  token-purchase payment link against a stubbed Razorpay). Here Finance is a stub that **verifies
  Engage's signature with an independent implementation** of the canonical string, asserts the
  request Engage sent, and then posts a real payment event back into Engage's real inbound route.
  So steps l–n prove Engage's side of the Finance contract, not Finance's bookkeeping.

Nothing else is faked. Steps i–n found three bugs that every unit and integration test had
missed, which is the reason they exist: Pulse's schema splitter cut PL/pgSQL function bodies in
half so `php spark migrate` never created the wallet tables; one schema file had no migration at
all; and Engage's buy-credits routes answered in Engage's internal `{ok:true}` shape, which
Manage's client reads as "the service is down" — so nothing could be bought, while both sides'
own tests passed.

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
