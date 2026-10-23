# Licensing (Phase A) — catalogue, licences and the Manage read API

Engage is where Aicountly's sellable catalogue lives and where every licence is issued. It stays **staff-only**:
customers never sign in to Engage. They see their licences in **manage.aicountly.com**, whose *server* reads them
from Engage over a signed server-to-server API (AIC-S2S v1). Manage's browser never calls Engage.

Binding documents: `commerce-plan-2026-10-18.md` (Phase A, Engage part) and `commerce-contract-v1-draft.md`
(§0 conventions, §4 read API). This phase builds **no money flow**: no checkout, quotes, Finance licence-order inbound
or Engage → Manage entitlement events (Phase B). `order_ref` and `source = 'order'` exist only so Phase B needs no
schema change.

**Dormant by default.** The migration creates empty tables and seeds nothing — no licence type, offer or price exists
until staff create one. The Manage routes answer `503 inbound_not_configured` until the environment variables below
are set. Existing Engage flows (leads, seller programme, Finance payouts) are untouched.

**Nothing commercial is hard-coded.** Licence types, offers, prices, GST rate, price basis, SAC, terms, seats, apps and
entitlements are rows typed by staff. The API is type-agnostic: every label Manage shows comes from data. The only
fixed vocabularies are system ones: `lifecycle_kind` (paid | trial | complimentary — what Manage's subscription
understands), offer status (draft | published | retired) and licence status (below).

## 1. Model

| Table | What it holds |
|---|---|
| `engage_licence_types` | `code` (permanent), `label`, `description`, `lifecycle_kind`, `display_schema` (free JSON for Manage), `active`, `sort_order` |
| `engage_offers` | The catalogue, versioned per `offer_code`: `product_code` (→ `engage_products`), `licence_type_code`, `plan_code` (nullable → `engage_plans`), `label`, `description`, `price_paise`, `currency` (INR), `price_basis` (inclusive / exclusive of GST), `gst_rate`, `sac`, `term_months` + `term_days` (0 + 0 = no end), `seats` (null = no limit), `app_keys[]` (defaults to the product code), `entitlements` (`[{code,label,value,unit}]`), `display` (`{attributes:[{label,value}], cycle_label, …}`), `status`, `valid_from` / `valid_to`. UNIQUE (`offer_code`, `version`); at most one published version per code |
| `engage_account_companies` | Engage account ↔ Manage company `cmp_id`, `verified_by_display` ("verified manually by &lt;staff email&gt;"), `verification_note`, `verified_at`; unlinking keeps the row (`status = unlinked`, reason). A `cmp_id` belongs to at most one account at a time; an account may hold several companies |
| `engage_licences` | `licence_ref` (`LIC-000123`), `cmp_id`, `account_id`, `offer_id` + `offer_code` + `offer_version` + `offer_snapshot` (the exact version sold, labels included), `product_code`, `licence_type_code`, `plan_code`, `app_keys[]`, `seats_total`, `starts_at`, `ends_at` (exclusive), `status`, `status_reason` (internal), `order_ref` (UNIQUE, Phase B), `invoice_refs[]`, `source` (staff / order), `notes` (internal), `version` (optimistic lock) |
| `engage_licence_events` | Append-only activity: `event_id`, `licence_ref`, `cmp_id`, `seq` (per `cmp_id`), `type`, `actor_kind` (staff / system / order), `actor_display`, `summary` (customer-safe), `reason` (internal), `changes` (JSON), `licence_version`, `occurred_at` |
| `engage_licence_seats` | Seat assignments: `member_ref` (optional identifier, e.g. a my.aicountly uuid), `member_label`, `assigned_at`, `released_at` |
| `engage_s2s_nonces`, `engage_s2s_rate` | AIC-S2S nonce replay window and per-key rate counters |

Database guarantees (migration `2026-10-18-000100_CreateLicensingTables`):

- **Published offers are immutable.** Trigger `engage_offers_guard` refuses any change to a published or retired
  version except `published → retired`, and refuses deleting anything but a draft.
- **Events are append-only.** Trigger `engage_licence_events_append_only` refuses UPDATE, DELETE and TRUNCATE.
- **seq per company** is assigned by trigger `engage_licence_events_seq` (a counter row per `cmp_id`, locked in the
  inserting transaction) — a writer cannot choose it.

Dates: a date typed by staff (`YYYY-MM-DD`) means 00:00 IST that day. A term is added on the Indian calendar
(12 months from 18/10/2026 → `ends_at` 18/10/2027 00:00 IST, i.e. the licence runs **until 17/10/2027**). `ends_at`
is exclusive; the API returns UTC ISO-8601 instants. Money is integer paise.

## 2. Offers: draft → published → retired

| Action | Rule |
|---|---|
| Create | Draft v1 of a new `offer_code`. Product, licence type, label, price, price basis and GST rate are required; nothing is pre-filled |
| Edit / Discard | Drafts only |
| Publish | Draft only; the licence type must be active; a priced offer needs a SAC (Finance prints it); at least one app key; `valid_to` in the future. Publishing **retires the previous published version** of the code in the same transaction |
| New version | Copies any version into a new draft (one draft per code at a time) |
| Retire | Published version only. Existing licences keep their snapshot |

The catalogue Manage sees = published versions whose licence type is active and that are on sale now
(`valid_from ≤ now < valid_to`, either may be empty).

## 3. Licence statuses and transitions

```
             activate                suspend                 reinstate (term not ended)
  issued ───────────────▶ active ─────────────▶ suspended ─────────────────────────▶ active
    │  ▲ issue(activate)     │  ▲                    │
    │  └─────────────────────┘  │ renew              │
    │                           │                    │
    │      term ended (engage:licences-expire)       │
    ├──────────────────────▶ expired ◀───────────────┘
    │                           │
    └──────── revoke ──────▶ revoked (final) ◀── revoke ── active | suspended | expired
```

| Action (staff) | From | Effect | Event |
|---|---|---|---|
| Issue | — | New licence from a **published, on-sale** offer to a company **mapped to the account**; `starts_at` (default now), `ends_at` = start + term, seats = offer seats unless overridden, optional invoice refs; `activate` (default true) | `issued` (+ `activated`) |
| Activate | issued | → active | `activated` |
| Renew | active, expired | `ends_at` += one term of the current published version of the **same** offer (from the current end, or from now if it has ended) → active; a newer version replaces the snapshot; optional invoice refs | `renewed` |
| Extend | issued, active, suspended | `ends_at` → a later date (reason required) | `extended` |
| Suspend | active | → suspended (reason required) | `suspended` |
| Reinstate | suspended, term not ended | → active (reason required) | `reinstated` |
| Revoke | issued, active, suspended, expired | → revoked, final (reason required) | `revoked` |
| Change seats | issued, active, suspended | `seats_total` (≥ seats assigned; null = no limit) | `seats_changed` |
| Assign / release seat | assign: issued, active; release: any | within `seats_total`; one live seat per member | `seat_assigned` / `seat_released` |
| Link invoice | any | append an invoice number to `invoice_refs` | `invoice_linked` |
| Expire (system) | issued, active, suspended with `ends_at ≤ now` | → expired | `expired` (actor system) |

Every write: one transaction, licence row locked (`FOR UPDATE`), the caller's `version` must equal the stored one
(else **409 `version_conflict`** with `details.current_version`), the row is updated with `version + 1` and the event(s)
inserted — the change and its event commit together or not at all. A disallowed transition is **409
`invalid_transition`**. Staff writes are also written to `engage_audit_logs` (`licence.*`, `offer.*`,
`licence_type.*`, `licence.company_linked` / `_unlinked`) and fanned out to Console's central audit.

A live licence whose term has ended **reads as expired** everywhere (staff screens and Manage) even before the expiry
job records it. Upgrades (moving to another offer) are not built: renew refuses another `offer_code`.

`php spark engage:licences-expire` records expiries (with events). It is **not** scheduled by the deploy; once licences
exist add, hourly:

```
23 * * * * cd /path/to/api && php spark engage:licences-expire >> writable/logs/licences_expire.log 2>&1
```

It reports to Console's Cron Job Monitor as `engage.licences_expire` when `CONSOLE_CRON_MONITOR_KEY` is set.

## 4. Roles

Roles come from Console → Roles & Access (`ConsoleRoleSync::CATALOG`); no new role was added.

| | Read (lists, detail, timeline) | Write (types, offers, mappings, licences) |
|---|---|---|
| super_admin, sales_manager | yes | yes |
| seller_manager, kyc_reviewer, member | yes | no (403) |

UI: sidebar **Licensing → Licences / Offers / Licence types / Account ↔ company**, and on each account page
**Manage companies** + **Licences** panels. Write buttons are hidden for read-only roles; the API enforces the same
rule (`role:super_admin,sales_manager` in `Routes.php`).

Staff API (`/api/v1`, staff JWT): `licence-types`, `offers` (+ `/{id}/publish|retire|new-version`, `DELETE` a draft),
`account-companies` (+ `/{id}/unlink`), `licences` (+ `/{ref}/activate|renew|extend|suspend|reinstate|revoke|seats|
seat-assignments|seat-assignments/{id}/release|invoice-refs`). Answers use Engage's usual `{ok, data}` /
`{ok:false, error, code?, details?}`.

## 5. Manage read API (contract §4)

All under `https://engage.aicountly.org/api/v1/manage/`, GET only, filter `aic-s2s:manage`:

| Route | Answer `data` |
|---|---|
| `companies/{cmp_id}/licences?status=&product_code=&limit=&cursor=` | list of licences (below) + `page` |
| `companies/{cmp_id}/licences/{licence_ref}` | one licence |
| `companies/{cmp_id}/licences/{licence_ref}/seats` | `{licence_ref, total, assigned, items:[{seat_id, member_ref, member_label, assigned_at, released_at}]}` |
| `companies/{cmp_id}/activity?limit=&cursor=` | events, newest first: `{event_id, seq, licence_ref, type{code,label}, summary, actor{kind, display}, changes, occurred_at}` + `page` |
| `catalogue?cmp_id=` | offers on sale: `{offer{code,version}, label, description, product{code,label}, licence_type{code,label,lifecycle_kind}, plan, price{amount_paise,currency,basis,gst_rate,sac}, term{months,days,cycle}, seats, app_keys, entitlements, attributes, display, valid_from, valid_to}` |
| `licence-types` | active types `{code, label, description, lifecycle_kind, display_schema}` |

Licence (type-agnostic, contract §4): `{licence_ref, product{code,label}, licence_type{code,label,lifecycle_kind},
plan{code,label}|null, offer{code,version,label}, status{code,label,tone}, term{starts_at,ends_at,cycle},
seats{total,assigned}, entitlements[{code,label,value,unit}], attributes[{label,value}], app_keys[], invoice_refs[],
issued_at, updated_at, version}`. `status.tone` ∈ success | info | warning | danger | neutral.

- **Scope:** every company query is filtered by `cmp_id`. Another company's licence answers exactly like a missing
  one (**404 `not_found`**); a company with no licences gets **an empty list**, never 404.
- **Not exposed to Manage:** internal reasons, staff names (actor shows "Aicountly team"), notes, account, price
  snapshot, `order_ref`.
- **Envelope (§0.2):** `{"success":true,"data":…,"page":{"next_cursor":…,"limit":25},"request_id":"…"}` (`page` on
  lists only, no totals); errors `{"success":false,"error":{"code","message","details","retryable"},"request_id"}`.
  `X-AIC-Request-Id` is echoed; responses carry `Cache-Control: private, no-store`.
- **Paging (§0.3):** `limit` 1–100 (default 25), `cursor` = base64url(`{"d":"<created_at|occurred_at, µs UTC>","id":<row id>}`),
  ordered by that timestamp DESC, id DESC; `next_cursor: null` on the last page.
- **Errors:** 400 `bad_request` (`details.field`: bad `cmp_id`, `limit`, `cursor`, `status`, `product_code`), 404
  `not_found`, 503 `database_unavailable` (retryable), plus the auth errors below.
- **Audit:** every read writes `engage_audit_logs` (`manage.licences.list`, `manage.licence.read`,
  `manage.licence.seats`, `manage.activity.list`, `manage.catalogue.read`, `manage.licence_types.read`) with
  `actor_email = manage:<X-AIC-Actor>`, `actor_role = service:manage`, `subject_id = cmp_id`, and the request id,
  key id and path in metadata. `X-AIC-Actor` is recorded for audit only — it never authorises anything.
- Any non-GET under `v1/manage/` with a read key → 403 `route_not_allowed_for_key`; no Manage write route exists.

## 6. AIC-S2S v1 (inbound, `App\Filters\AicS2sFilter`)

Reusable: the route names the calling client (`'filter' => 'aic-s2s:manage'`); configuration is read per client by
`App\Libraries\AicS2s\AicS2sConfig`; signing is `App\Libraries\AicS2s\AicS2sSignature` (also usable by an outbound
client in Phase B).

Headers Manage sends on every request:

```
X-AIC-Client:      manage
X-AIC-Key-Id:      manage-read-2026-10
X-AIC-Timestamp:   <unix seconds>                 refused if |now − ts| > 300
X-AIC-Nonce:       <uuid v4>                      refused if already used by this key id in the last 600 s
X-AIC-Environment: production | sandbox | local   must equal ENGAGE_S2S_ENVIRONMENT
X-AIC-Signature:   v1=<hex HMAC-SHA256(secret, canonical)>
X-AIC-Actor:       <my.aicountly uuid> | service  (audit only)
X-AIC-Request-Id:  <uuid>                         (echoed as request_id)
X-Saas-Origin:     manage

canonical = METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" hex(sha256(raw body or ""))
  PATH            = path after /api, e.g. /v1/manage/companies/42/licences
  CANONICAL_QUERY = each k=v decoded then RFC 3986 encoded (rawurlencode; "+" and %20 are a space, "~" stays),
                    sorted by key then value, joined with "&"; "" when there is no query
```

Checks in order: 503 `inbound_not_configured` → 400 `environment_required` / 403 `environment_mismatch` → 401
`signature_missing` → 401 `unknown_key` (client or key id) → 401 `timestamp_stale` → 401 `signature_invalid`
(constant-time compare; also a malformed nonce) → 401 `nonce_replayed` → 403 `route_not_allowed_for_key` (a
`read` key may only GET/HEAD; an `issue` key may only POST) → 429 `rate_limited` with `Retry-After` (120/min per read
key, 30/min per issue key) → 503 `database_unavailable` if the nonce/rate tables cannot be reached.

### Worked signature example

Secret `example-secret-do-not-use-0123456789abcdef` (example only), request
`GET /api/v1/manage/companies/42/licences?status=active&limit=25`, timestamp `1792310400`,
nonce `5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c`, empty body:

```
canonical (6 lines, "\n" between them, no trailing newline):
GET
/v1/manage/companies/42/licences
limit=25&status=active
1792310400
5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855      ← sha256("")

X-AIC-Signature: v1=e0b4ecf7af1a52998341aa7319b716593271a93737a42e1eeab724fac67363f8
```

Reproduce with openssl:

```
printf 'GET\n/v1/manage/companies/42/licences\nlimit=25&status=active\n1792310400\n5b1f3c9e-8d4a-4f2b-9c6e-2a7d1e0f4b3c\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' \
  | openssl dgst -sha256 -hmac 'example-secret-do-not-use-0123456789abcdef'
```

(The same vector is pinned in `tests/Unit/AicS2sSignatureTest.php`.)

## 7. Configuration (`api/.env`, names in `server-php/.env.example`)

| Variable | Meaning |
|---|---|
| `ENGAGE_S2S_ENVIRONMENT` | `production`, `sandbox` or `local`; must equal Manage's `X-AIC-Environment` |
| `ENGAGE_INBOUND_MANAGE_KEY_IDS` | comma-separated live key ids for Manage (two during a rotation), e.g. `manage-read-2026-10` |
| `ENGAGE_INBOUND_MANAGE_SECRET_<KEYID>` | the shared secret for that key id, **at least 32 bytes**; `<KEYID>` upper-cased, every other character → `_` (`manage-read-2026-10` → `ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10`). Generate with `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`; Manage holds the same value |
| `ENGAGE_INBOUND_MANAGE_SCOPE_<KEYID>` | `read` (default; GET only) or `issue` (POST only — nothing uses it in Phase A) |

A key id whose secret is missing or shorter than 32 bytes is not live. No environment or no live key → every Manage
route answers 503 `inbound_not_configured`. `GET /health` reports `checks.manage_inbound` (never a secret).
Rotation: add the new id to `KEY_IDS` with its secret, switch Manage to it, then remove the old id.

## 8. Runbook — staff issuing a licence by hand (interim, until Phase B)

Payment is still collected and invoiced outside Engage (commerce plan §6 "Manual sales runbook").

1. **Payment and invoice** — collect payment (Pay link or bank transfer) and issue the GST invoice from Aicountly's
   Smart Books company as today. Note the invoice number.
2. **Catalogue (once per offer)** — a super_admin or sales_manager creates the licence type
   (Licensing → Licence types) and the offer (Licensing → Offers → New offer: product, type, label, price in ₹, price
   basis, GST rate, SAC, term, seats, apps, entitlements), checks it, and **Publishes** it. To change anything later:
   **New version** → edit → Publish (the old version is retired; issued licences keep theirs).
3. **Map the company** — on the customer's account (Sales → Accounts → account → *Manage companies* → Map a company)
   enter the Manage company id (`cmp_id`) and how you verified it (e.g. the owner read it out from Manage on a call).
   It is recorded as "verified manually by &lt;you&gt;". A company can be mapped to one account only.
4. **Issue** — on the account page (*Licences → Issue licence*) or Licensing → Licences: choose the mapped company and
   the published offer, the start date (dd/mm/yyyy shown; the licence runs until the day before the end), seats if they
   differ from the offer, the Smart Books invoice number, and leave **Activate now** ticked. Confirm.
5. **Manage entitlement (interim)** — until Phase B's entitlement events, also run Manage's
   `subscription_admin.php set --kind=paid --status=active --period-end=… --source=manual` so the app shows it
   (the licence's `ends_at`, last day = the day before).
6. **Later changes** — open the licence: *Renew* (adds a term, attach the new invoice number), *Extend* (goodwill,
   reason), *Suspend* / *Reinstate* / *Revoke* (reason, kept internal), *Change seats*, *Assign a seat*, *Link invoice*.
   Each writes one activity event the customer sees in Manage (without the internal reason). If the screen says the
   licence was changed by someone else, reload and redo the action.

## 9. Tests

```
cd server-php
vendor/bin/phpunit --testsuite Unit
createdb engage_test
env database.tests.database=engage_test database.tests.username=postgres database.tests.password=postgres vendor/bin/phpunit
```

Licensing: `tests/Unit/AicS2sSignatureTest.php`, `tests/Unit/LicensingSupportTest.php`,
`tests/Feature/AicS2sFilterTest.php`, `tests/Feature/LicensingCatalogueTest.php`,
`tests/Feature/LicenceLifecycleTest.php`, `tests/Feature/ManageReadApiTest.php` (base `tests/_support/LicensingTestCase.php`).

## 10. Deviations from the contract draft

- **Extra fields** (additive): licences also carry `offer{code,version,label}`, `app_keys[]`, `issued_at`,
  `updated_at`, and `licence_type.lifecycle_kind`; activity items carry `seq` and `licence_ref`. Nothing in §4 is
  missing or renamed.
- **Catalogue / licence types** answer an unpaged list (`page` omitted) — the contract only defines paging for lists of
  documents; both are small and bounded by what staff publish.
- **`status=expired` / `active`** filters use the status Manage is shown (a live licence past its end counts as
  expired).
- **`catalogue` requires `cmp_id`** (contract: every read carries it) but does not yet vary by company.
- **Seats** `items` list only live assignments to Manage; staff also see released ones.
- **`X-Saas-Origin`** is sent by `AicS2sSignature::headers()` but not enforced by the receiver (no re-entry path exists
  into Engage's Manage routes).
- **Rate limit** uses a per-minute window in PostgreSQL (`engage_s2s_rate`), not a sliding window; nonces live in
  `engage_s2s_nonces` (pruned on each request).
