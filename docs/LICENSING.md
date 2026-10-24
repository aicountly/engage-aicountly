# Licensing — catalogue, holder licences, Basic plan, Manage read API and entitlement push

Engage is where Aicountly's sellable catalogue lives and where every licence is issued. It stays **staff-only**:
customers never sign in to Engage. They see their licences in **manage.aicountly.com**, whose *server* reads them
from Engage over a signed server-to-server API (AIC-S2S v1). Manage's browser never calls Engage.

Binding documents: `commerce-plan-2026-10-18.md` (Phase A, Engage part), `commerce-contract-v1-draft.md`
(§0 conventions, §4 read API) and the Aicountly rectification contracts v1 (23 Oct 2026, §3 "Plans & entitlements")
with founder decisions **D-L1** (no trials; every signed-up user holds the free **Basic** plan of every app; a paid
plan replaces Basic for that app) and **D-L2** (licences apply to the USER — the workspace owner — not to one company).

What exists now:

- **Holder-level licences** (§3): a licence names the holder (`holder_uuid`, the my.aicountly uuid of the owner), a
  Manage company (`cmp_id`, legacy company-level licences keep working; Manage resolves them via the company's owner),
  or both.
- **Basic is data** (§2): each product's Basic is its published **default offer** (`is_default`; at most one per
  product, DB-enforced). Nothing is seeded in production; `php spark engage:basic-offers` creates DRAFTS for staff to
  fill in and publish.
- **Basic provisioning** (§5b): my.aicountly calls Engage after every sign-up; Engage issues every published Basic.
- **Entitlement push** (§6): every licence write queues the holder's full plan state per app in the same transaction;
  a worker pushes it to Manage. **This replaces the old manual `subscription_admin.php` step.**
- **Upgrade / downgrade** (`change-offer`, §3).

Still not built: checkout, Finance licence-order inbound, proration. `order_ref` and `source = 'order'` exist only so
that work needs no schema change. Coupons (rectification contract §4) are a separate stream; they plug into the
extension point in §12.

**Dormant by default.** The migrations create empty tables and seed nothing — no licence type, offer or price exists
until staff create one. The Manage and my.aicountly routes answer `503 inbound_not_configured`, and the entitlement
push keeps changes queued, until the environment variables in §8 are set. Existing Engage flows (leads, seller
programme, Finance payouts) are untouched.

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
| `engage_licences` | `licence_ref` (`LIC-000123`), `holder_uuid` (my.aicountly uuid, nullable), `cmp_id` (nullable — at least one of the two), `account_id` (nullable only for a provisioned Basic), `offer_id` + `offer_code` + `offer_version` + `offer_snapshot` (the exact version sold, labels included), `product_code`, `licence_type_code`, `plan_code`, `app_keys[]`, `seats_total`, `starts_at`, `ends_at` (exclusive), `status`, `status_reason` (internal), `is_basic` (issued from the product's default offer), `is_addon` (a deliberate second live licence), `idempotency_key` + `request_hash` (issue replay), `superseded_at`, `holder_note`, `order_ref` (UNIQUE, Phase B), `invoice_refs[]`, `source` (staff / order / provisioning), `notes` (internal), `version` (optimistic lock). At most one held Basic per (holder, product): `uq_engage_licences_basic_live` |
| `engage_licence_events` | Append-only activity: `event_id`, `licence_ref`, `cmp_id`, `holder_uuid`, `seq` (per `cmp_id`; per holder for a licence without a company), `type`, `actor_kind` (staff / system / order), `actor_display`, `summary` (customer-safe), `reason` (internal), `changes` (JSON), `licence_version`, `occurred_at` |
| `engage_holders` | Holders Engage heard of (provisioning, or a staff issue): `holder_uuid`, `email`, `name`, `signed_up_at`, `source` — the staff "find a holder" lookup |
| `engage_entitlement_outbox`, `engage_entitlement_seq` | The Engage → Manage push (§6): one row per pushed full state of (holder, app), `seq` per holder |
| `engage_licence_seats` | Seat assignments: `member_ref` (optional identifier, e.g. a my.aicountly uuid), `member_label`, `assigned_at`, `released_at` |
| `engage_s2s_nonces`, `engage_s2s_rate` | AIC-S2S nonce replay window and per-key rate counters |

Database guarantees (migration `2026-10-18-000100_CreateLicensingTables`):

- **Published offers are immutable.** Trigger `engage_offers_guard` refuses any change to a published or retired
  version except `published → retired`, and refuses deleting anything but a draft.
- **Events are append-only.** Trigger `engage_licence_events_append_only` refuses UPDATE, DELETE and TRUNCATE.
- **seq per company** is assigned by trigger `engage_licence_events_seq` (a counter row per `cmp_id`, locked in the
  inserting transaction; per `holder_uuid` for a licence without a company) — a writer cannot choose it.
- **One published Basic per product**: `uq_engage_offers_default` (product_code) WHERE is_default AND published.
- **One held Basic per holder + product**: `uq_engage_licences_basic_live`.

Migrations: `2026-10-18-000100_CreateLicensingTables`, `2026-10-23-100100_HolderLevelLicensing`,
`2026-10-23-100200_CreateEntitlementOutbox` (all additive).

Dates: a date typed by staff (`YYYY-MM-DD`) means 00:00 IST that day. A term is added on the Indian calendar
(12 months from 18/10/2026 → `ends_at` 18/10/2027 00:00 IST, i.e. the licence runs **until 17/10/2027**). `ends_at`
is exclusive; the API returns UTC ISO-8601 instants. Money is integer paise.

## 2. Offers: draft → published → retired

| Action | Rule |
|---|---|
| Create | Draft v1 of a new `offer_code`. Product, licence type, label, price, price basis and GST rate are required; nothing is pre-filled |
| Edit / Discard | Drafts only |
| Publish | Draft only; the licence type must be active; a priced offer needs a SAC **Finance accepts (`99` + 4 digits, e.g. 997331)**; at least one app key and **every app key a Manage registry key** (§6.1; `drive` is saved as `docs`); `valid_to` in the future; a default offer must be Basic-shaped (below). Publishing **retires the previous published version** of the code in the same transaction |
| Default (Basic) | `POST offers/{id}/default {is_default}` on a draft or a published version. A default offer must be **free (price 0), open-ended (term 0 + 0) and of a complimentary licence type**. At most one **published** default per product — a second answers **409 `default_offer_exists`** (also enforced by the database). A new version copies the flag |
| New version | Copies any version into a new draft (one draft per code at a time) |
| Retire | Published version only. Existing licences keep their snapshot |

The catalogue Manage sees = published versions whose licence type is active and that are on sale now
(`valid_from ≤ now < valid_to`, either may be empty).

`php spark engage:basic-offers --dry-run | --apply [--licence-type=<code>] [--create-type]` creates, for every
product whose code Manage knows (Aicountly Academy course products are skipped) and that has no default offer yet, a
**draft** `<product>-basic` offer (price 0, no
end, no seat limit, `is_default`, **no entitlements**). It never publishes: staff set the Basic limits
(entitlements, §6.2 conventions) and publish. Idempotent.

## 3. Licence statuses and transitions

```
             activate                suspend                 reinstate (term not ended)
  issued ───────────────▶ active ─────────────▶ suspended ─────────────────────────▶ active
    │  ▲ issue(activate)     │  ▲                    │
    │  └─────────────────────┘  │ renew, change-offer│
    │                           │                    │
    │      term ended (engage:licences-expire)       │
    ├──────────────────────▶ expired ◀───────────────┘
    │                           │
    └──────── revoke ──────▶ revoked (final) ◀── revoke ── active | suspended | expired | superseded

  Basic only:  active ──(a paid licence of the same holder + product becomes active)──▶ superseded
               superseded ──(no paid licence active any more: revoke / expiry / suspend)──▶ active
```

| Action (staff) | From | Effect | Event |
|---|---|---|---|
| Issue | — | New licence from a **published, on-sale** offer to a **holder** (`holder_uuid`; one Engage has not heard of needs `holder_note` — how staff verified the uuid) and/or a company **mapped to the account**; `starts_at` (default now), `ends_at` = start + term, seats = offer seats unless overridden, optional invoice refs; `activate` (default true). Guards: `Idempotency-Key` header (or `idempotency_key`) replays a retry (`replayed: true`; same key + other request → **409 `idempotency_conflict`**); a second live paid licence of the same product for the same holder or company → **409 `live_licence_exists`** unless `is_addon: true`. Optional `quote_ref` (coupons, §12) | `issued` (+ `activated`) |
| Activate | issued | → active | `activated` |
| Renew | active, expired | `ends_at` += one term of the current published version of the **same** offer (from the current end, or from now if it has ended) → active; a newer version replaces the snapshot; optional invoice refs | `renewed` |
| Extend | issued, active, suspended | `ends_at` → a later date (reason required) | `extended` |
| Suspend | active | → suspended (reason required) | `suspended` |
| Reinstate | suspended, term not ended | → active (reason required) | `reinstated` |
| Revoke | issued, active, suspended, expired | → revoked, final (reason required) | `revoked` |
| Change seats | issued, active, suspended | `seats_total` (≥ seats assigned; null = no limit) | `seats_changed` |
| Assign / release seat | assign: issued, active; release: any | within `seats_total`; one live seat per member | `seat_assigned` / `seat_released` |
| Link invoice | any | append an invoice number to `invoice_refs` | `invoice_linked` |
| Change offer | issued, active, suspended | `POST licences/{ref}/change-offer {version, offer_code, offer_version?, term_mode keep\|restart, seats_total?, reason?, quote_ref?}`: move to another published, on-sale offer of the **same product** (upgrade or downgrade; `changes.direction` by price). `keep` (default) keeps `ends_at`; `restart` starts the new term now. Seats follow the new offer unless sent (≥ assigned). Never on a Basic licence (409 `basic_not_changeable` — issue the paid licence instead) and never **to** a default offer (revoke the paid licence: Basic returns). No proration is computed | `plan_changed` |
| Supersede / reactivate (system) | Basic: active ⇄ superseded | in the same transaction as the paid licence's change (issue / activate / reinstate → superseded; revoke / expire / suspend → active again) | `superseded` / `reactivated` |
| Expire (system) | issued, active, suspended with `ends_at ≤ now` | → expired | `expired` (actor system) |

Every write: one transaction, licence row locked (`FOR UPDATE`), the caller's `version` must equal the stored one
(else **409 `version_conflict`** with `details.current_version`), the row is updated with `version + 1`, the event(s)
inserted, the holder's Basic superseded / reactivated, the coupon hook run (§12) and the entitlement state queued for
Manage (§6) — all of it commits together or not at all (licensing writes run with CodeIgniter transaction exceptions
on, so a failed statement can never be reported as success). A disallowed transition is **409
`invalid_transition`**. Staff writes are also written to `engage_audit_logs` (`licence.*`, `offer.*`,
`licence_type.*`, `licence.company_linked` / `_unlinked`) and fanned out to Console's central audit.

A live licence whose term has ended **reads as expired** everywhere (staff screens and Manage) even before the expiry
job records it. Renew keeps the same `offer_code`; moving to another offer is `change-offer`.

`php spark engage:licences-expire` records expiries (with events), reactivates the holder's Basic and queues the new
state. It is **scheduled hourly by `bin/ops/jobs.txt`** (`licences-expire`, installed by the deploy; it replaces any
hand-installed line) and reports to Console's Cron Job Monitor as `engage.licences_expire`. **No automation ever
starts Manage's 45-day restriction / purge clock**: a paid plan that ends falls back to Basic.

## 4. Roles

Roles come from Console → Roles & Access (`ConsoleRoleSync::CATALOG`); no new role was added.

| | Read (lists, detail, timeline) | Write (types, offers, mappings, licences) |
|---|---|---|
| super_admin, sales_manager | yes | yes |
| seller_manager, kyc_reviewer, member | yes | no (403) |

UI: sidebar **Licensing → Licences / Offers / Licence types / Account ↔ company / Manage sync**, and on each account
page **Manage companies** + **Licences** panels. The licence screens show the holder; offers carry a **Basic (default)**
toggle; a licence has **Change plan**; **Manage sync** shows the outbox (pending / delivered / rejected / dead) with
**Re-queue**. Write buttons are hidden for read-only roles; the API enforces the same
rule (`role:super_admin,sales_manager` in `Routes.php`).

Staff API (`/api/v1`, staff JWT): `licence-types`, `offers` (+ `/{id}/publish|retire|new-version|default`, `DELETE` a
draft), `account-companies` (+ `/{id}/unlink`), `licences` (`?holder_uuid=&is_basic=` filters; + `/{ref}/activate|renew|
extend|suspend|reinstate|revoke|seats|seat-assignments|seat-assignments/{id}/release|invoice-refs|change-offer`),
`holders` (`?q=` e-mail / name / uuid; `/{uuid}` with licences and pushes), `entitlements/outbox` (+ `/{id}/requeue`,
write roles), `entitlement-codes` (read-only, below). Answers use Engage's usual `{ok, data}` /
`{ok:false, error, code?, details?}`.

**Products and entitlement codes (fleet alignment, 2026-10).** `ProductsSeeder` (run by every deploy) carries every
application Engage licenses as a product, code = Manage registry key (`ManageAppRegistry::CATALOG_KEYS`, 36 keys:
manage, books, hrms, auditor, fr, secretarial, vault, chat, calendar, docs, notes, pos, billing, inventory, sales,
purchases, insights, buddy, contacts, remote, advisor, appointments, contracts, pay, crm, helpdesk, receptionist,
valuations, email, messaging, voice, integrate, benefits, capital, apis, community) — idempotent, no price,
no offer; existing rows and the legacy codes (ourpeople, flow, console, other) are never touched. `academy`, `edge`
(Aicountly Edge) and `aspire` (Aicountly Aspire) are **course products only, not licensable** (§ Aicountly Academy
courses below). Offers' `app_keys` are
validated against the same mirror (drive → docs, pulse → buddy, lobby → receptionist, connect → chat). Manage
stores no plan for `app_key: manage` (its push answers 422), so publishing an offer that names `manage` is refused and
`engage:basic-offers` skips the `manage` product (see §6.1).
`GET v1/entitlement-codes[?app=sales,drive]` (any signed-in role) lists the limit codes staff can pick
(`EntitlementCodeCatalogue`: `max_companies` and `max_users`, which Manage enforces for every app, plus each product's
own codes from its fleet role file — e.g. `sales.max_orders_per_month`, `pos.max_terminals`, `drive.storage_mb`);
`?app=` narrows to Manage's codes plus those apps'. Licensing → Offers → Entitlements offers them as autocomplete
(narrowed to the offer's app keys, "show codes of every app" to widen); a code the list does not know is still
accepted as typed (`free_text_allowed: true`), so a product can add a limit before this list knows it — except a code
prefixed with a course key (`edge.…`, `aspire.…`, `academy.…`), which is refused.

**Aicountly Academy courses (owner decision, 2026-10-24).** Aicountly Edge and Aicountly Aspire are courses Aicountly
runs itself; they are never licensed to companies. Their `engage_products` rows (`academy`, `edge`, `aspire`, category
`Academy`) are course products only — leads, fee payments, seller commission and the revenue view use them
(docs/ACADEMY_FEES_RUNBOOK.md) — and are not licensable. `ManageAppRegistry::COURSE_KEYS` lists them; none is in
`CATALOG_KEYS` / `PLAN_KEYS` (Manage retired `edge` and `aspire` from its registry too —
`CompanyAppRegistry::ACADEMY_COURSE_KEYS`, a push for them answers 422 `unknown_application`, and Manage's migration
025 revokes its copies of Engage licences for them), and `EntitlementCodeCatalogue` has no code for them. Consequently:

- an offer cannot be created, versioned or published for a course product (any product of category `Academy`) or with
  a course key in `app_keys` (422 `course_not_licensable` at publish / new version, a field error on the draft);
- `engage:basic-offers` skips them ("Aicountly Academy course product (not licensable)");
- no licence can be issued from such an offer; a course licence issued before the decision cannot be activated,
  renewed, extended or moved to another offer (409 `course_not_licensable`) — staff may suspend, revoke or let it
  expire (there is no "non-renewing" status, so the licence row itself is left as it is);
- the entitlement outbox never queues a push for them (pending ones were skipped by the migration below), and
  `engage:entitlements-reconcile` does not count an older Manage still listing `edge` / `aspire` as drift;
- the Manage read API (§5) never returns a course licence (404 like a missing one), its seats or events, or a course
  offer in `catalogue`.

Migration `2026-10-24-000020_RetireAcademyCourseLicensing` retired any draft / published course offer (and any
published offer naming a course key; a draft of another product only lost the course keys), skipped pending
entitlement pushes for them, and logged — without changing — any licence already issued for a course. To list
those licences for the owner:

```sql
SELECT licence_ref, product_code, status, cmp_id, holder_uuid, account_id, offer_code, offer_version, starts_at, ends_at
  FROM engage_licences
 WHERE product_code IN ('academy','edge','aspire') OR app_keys && ARRAY['academy','edge','aspire']::text[]
 ORDER BY id;
-- and the offers the migration retired:
SELECT id, offer_code, version, product_code, status, retired_at FROM engage_offers
 WHERE product_code IN ('academy','edge','aspire') OR app_keys && ARRAY['academy','edge','aspire']::text[] ORDER BY id;
```

## 5. Manage read API (contract §4)

All under `https://engage.aicountly.org/api/v1/manage/`, GET only, filter `aic-s2s:manage`:

| Route | Answer `data` |
|---|---|
| `companies/{cmp_id}/licences?status=&product_code=&limit=&cursor=` | list of licences (below) + `page` |
| `companies/{cmp_id}/licences/{licence_ref}` | one licence |
| `companies/{cmp_id}/licences/{licence_ref}/seats` | `{licence_ref, total, assigned, items:[{seat_id, member_ref, member_label, assigned_at, released_at}]}` |
| `companies/{cmp_id}/activity?limit=&cursor=` | events, newest first: `{event_id, seq, licence_ref, type{code,label}, summary, actor{kind, display}, changes, occurred_at}` + `page` |
| `catalogue?cmp_id=` | offers on sale: `{offer{code,version}, label, description, product{code,label}, licence_type{code,label,lifecycle_kind}, plan, price{amount_paise,currency,basis,gst_rate,sac}, term{months,days,cycle}, seats, app_keys, is_default, entitlements, attributes, display, valid_from, valid_to}`. **`cmp_id` is optional** (Manage's hourly `plans_sync.php` reads the Basic defaults — `is_default: true` — without one); when given it is validated and the answer is the same |
| `licence-types` | active types `{code, label, description, lifecycle_kind, display_schema}` |

Licence (type-agnostic, contract §4): `{licence_ref, product{code,label}, licence_type{code,label,lifecycle_kind},
plan{code,label}|null, offer{code,version,label}, status{code,label,tone}, term{starts_at,ends_at,cycle},
seats{total,assigned}, entitlements[{code,label,value,unit}], attributes[{label,value}], app_keys[], invoice_refs[],
issued_at, updated_at, version}`. `status.tone` ∈ success | info | warning | danger | neutral.

- **Scope:** every company query is filtered by `cmp_id`. Another company's licence answers exactly like a missing
  one (**404 `not_found`**); a company with no licences gets **an empty list**, never 404.
- **Aicountly Academy courses:** a licence of a course product (`academy`, `edge`, `aspire`) is never returned — the
  list leaves it out, its detail and seats answer 404 `not_found`, its events are not in `activity` — and no course
  offer is in `catalogue` (§4, Aicountly Academy courses).
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

## 5b. Basic provisioning (my.aicountly → Engage, contract §3.2)

```
POST https://engage.aicountly.org/api/v1/provisioning/accounts       filter aic-s2s:myaicountly,issue
X-AIC-Client: myaicountly   (AIC-S2S v1 headers as in §7; key scope issue — a read key gets 403)
{"holder_uuid":"10042","email":"asha@example.com","name":"Asha","signed_up_at":"2026-10-23T10:00:00Z","request_id":"<uuid>"}
→ 200 {"success":true,"data":{"holder_uuid":"10042","licences":[{"licence_ref":"LIC-000123","product_code":"books",
        "plan":{"code":"books-basic","label":"Basic"},"status":"active"}]},"request_id":"…"}
```

- For every product with a **published default offer** (on sale, active type) Engage ensures exactly one held Basic
  licence for (holder, product) — `source provisioning`, no account, no company, actor "my.aicountly sign-up".
  Idempotent: a retry answers the same licences and writes nothing. If an active paid licence of the product exists
  the Basic is created `superseded`. A Basic **staff revoked** is not re-issued (reported `revoked`).
- Products without a default offer are skipped. After publishing a new product's Basic run
  `php spark engage:provision-basic --pending` (every provisioned holder still missing one), or `--holder=<uuid>`.
- The holder (e-mail, name) is recorded in `engage_holders` for the staff lookup.
- Errors: 400 `bad_request` (not a JSON object), 422 `validation_failed` (`details.holder_uuid|email|signed_up_at|
  request_id`), 503 `database_unavailable` (retryable), plus the §7 auth errors. my.aicountly calls it from an outbox
  with retry; sign-up is never blocked by it.

## 6. Entitlement push (Engage → Manage, contract §3.3)

Every licence write — issue, activate, renew, extend, suspend, reinstate, revoke, expire, change-offer, seats,
provisioning, and the Basic supersede / reactivate it causes — queues, **in the same transaction**, the FULL current
state of each (holder, app) it touches in `engage_entitlement_outbox` (unchanged states — e.g. a seat assignment —
queue nothing). `php spark engage:entitlements-push` (every minute, `jobs.txt`) sends them:

```
POST {MANAGE_API_BASE_URL}/v1/inbound/engage/entitlements      AIC-S2S v1, X-AIC-Client: engage,
                                                                X-AIC-Request-Id = event_id
{"event_id":"uuid","holder_uuid":"10042","app_key":"books","seq":17,
 "plan":{"code":"books-pro","label":"Pro","offer_code":"books-pro","offer_version":2,
         "licence_type_code":"paid","lifecycle_kind":"paid","is_default":false},
 "status":"active","licence_ref":"LIC-000123","starts_at":"…","ends_at":"…|null","seats_total":10,
 "entitlements":[{"code":"max_companies","label":"Companies","value":5,"unit":"count"}],"occurred_at":"…"}
```

- **Which licence speaks** for (holder, app): an effectively active paid licence (several → the one ending last);
  else the holder's Basic (`status active`, `plan.is_default true`, plan and entitlements from the product's
  **current** published default offer); else the latest suspended / expired / revoked licence (that status); else
  `none` with `plan: null`. `plan.code` = the offer's plan code, else its offer code.
- **seq** is monotonic per holder (`engage_entitlement_seq`, taken under a row lock in the writing transaction).
  Delivery is strictly in seq order per holder: a row is sent only when no lower seq of that holder is pending; a
  pending row with a newer pending state of the same (holder, app) is `skipped` (states are full, so only the newest
  matters). Manage ignores seq ≤ its stored seq.
- **Answers:** 2xx → `delivered`; 400 / 404 / 409 / 410 / 422 → `rejected` (not retried, logged critical);
  0 / 401 / 403 / 408 / 429 / 5xx → retried after 2, 4, 8 … minutes (at most 6 h), `dead` after 12 attempts.
- **Dead-letter visibility:** `/health` `checks.entitlement_outbox` ("attention: N dead, M rejected …"),
  `checks.manage_outbound`; Licensing → **Manage sync** lists rows and **Re-queue** queues the CURRENT state again as
  a new event (next seq).
- **Legacy company-only licences** (no holder) are pushed with `holder_uuid: null` and an extra `cmp_id`, seq per
  company — Manage resolves the holder through the company's owner (contract: "push what you know").
- **Dormant** until `MANAGE_API_BASE_URL` + `ENGAGE_OUTBOUND_MANAGE_KEY_ID` / `_SECRET` + `ENGAGE_S2S_ENVIRONMENT`
  are set: rows stay pending and are sent once configured.
- `php spark engage:entitlements-reconcile [--holder=]` (daily, `jobs.txt`) is **report-only**: it reads Manage's
  stored state (`GET {MANAGE_API_BASE_URL}/v1/inbound/engage/entitlements/{holder_uuid}`, same S2S key) for each holder
  Engage has pushed to and prints drift (missing, status / plan / licence / end differ, Manage seq behind). It never
  writes. `php spark engage:entitlements-reproject [--holder=] [--product=] [--dry-run]` re-queues changed states —
  run it after publishing a **new version of a Basic** (Basic holders are pushed with the current Basic limits).

### 6.1 Manage app keys

An offer's `app_keys` must be keys Manage knows. `App\Libraries\Licensing\ManageAppRegistry` is a static mirror of
Manage's `CompanyAppRegistry` (APP_KEYS, COMPANY_LEVEL_KEYS, ALIASES — the `AppCatalogService` ids Manage serves at
`GET /api/apps`), pinned by `tests/Unit/LicensingAutomationUnitTest.php`. Aliases are folded when a draft is saved
(`drive` → `docs`, `connect` → `chat`, `ourpeople` → `hrms` …); an unknown key blocks publishing. When Manage adds an
application, add it to the mirror in the same change.

Publishing, `engage:basic-offers` and the entitlement outbox accept exactly the keys **Manage holds a plan for**:
`ManageAppRegistry::PLAN_KEYS` / `planKey()` — every catalogued key except `manage` (Manage's `max_companies` /
`max_users` ride on each application's plan). An offer naming `manage` is refused at publish ("Manage holds no plan for:
manage"), and the outbox never queues a push Manage would refuse. `edge` and `aspire` are in neither copy: they are
Aicountly Academy courses, never licensed (§4); reconcile ignores them should an older Manage still list them. The
list is pinned in both repositories to the same file,
`server-php/tests/_support/fixtures/manage_plan_app_keys.json` here and `server-php/tests/fixtures/manage_plan_app_keys.json`
in manage-aicountly (byte-identical; `ManageAppRegistryTest` and Manage's `plan_app_keys_contract_test` pin each side to
it). `engage:entitlements-reconcile` also reads Manage's live list (`GET v1/inbound/engage/app-keys`, read key) and prints
`drift app keys: …` when they differ (an older Manage without the route is reported, not counted).

### 6.2 Entitlement codes (contract §3.6)

Values are data on the offer (`entitlements`); an absent code means unlimited / feature on. Manage enforces
`max_companies` and `max_users`; products enforce `<app>.<limit>` codes (`books.max_branches`,
`inventory.max_warehouses`, `billing.max_bills_per_month`, `drive.storage_mb`, `notes.max_notes` …) and
`feature.<app>.<name>` booleans.

## 7. AIC-S2S v1 (inbound, `App\Filters\AicS2sFilter`)

Reusable: the route names the calling client (`'filter' => 'aic-s2s:manage'`) and, optionally, the key scopes it
accepts (`'aic-s2s:myaicountly,issue'` — a key of another scope gets 403 `route_not_allowed_for_key`); configuration
is read per client by `App\Libraries\AicS2s\AicS2sConfig` (`ENGAGE_INBOUND_<CLIENT>_*`, optional
`ENGAGE_INBOUND_<CLIENT>_RATE_PER_MINUTE`); signing is `App\Libraries\AicS2s\AicS2sSignature`, used outbound by
`App\Libraries\AicS2s\ManageClient` (§6).

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

## 8. Configuration (`api/.env`, names in `server-php/.env.example`)

| Variable | Meaning |
|---|---|
| `ENGAGE_S2S_ENVIRONMENT` | `production`, `sandbox` or `local`; must equal Manage's `X-AIC-Environment` |
| `ENGAGE_INBOUND_MANAGE_KEY_IDS` | comma-separated live key ids for Manage (two during a rotation), e.g. `manage-read-2026-10` |
| `ENGAGE_INBOUND_MANAGE_SECRET_<KEYID>` | the shared secret for that key id, **at least 32 bytes**; `<KEYID>` upper-cased, every other character → `_` (`manage-read-2026-10` → `ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10`). Generate with `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`; Manage holds the same value |
| `ENGAGE_INBOUND_MANAGE_SCOPE_<KEYID>` | `read` (default; GET only) or `issue` (POST only — no Manage route uses it yet) |
| `ENGAGE_INBOUND_MYAICOUNTLY_KEY_IDS`, `_SECRET_<KEYID>`, `_SCOPE_<KEYID>=issue` | my.aicountly's provisioning key(s) (§5b), same rules |
| `ENGAGE_INBOUND_<CLIENT>_RATE_PER_MINUTE` | optional per-client limit per key (1–6000) instead of 120 read / 30 issue (e.g. 120 for my.aicountly's backfill) |
| `MANAGE_API_BASE_URL` | Manage's API base **including `/api`** (entitlement push, §6) |
| `ENGAGE_OUTBOUND_MANAGE_KEY_ID`, `ENGAGE_OUTBOUND_MANAGE_SECRET` | the PUSH-scope key Manage issued to Engage (secret ≥ 32 bytes, never logged) |
| `ENGAGE_OUTBOUND_MANAGE_READ_KEY_ID`, `ENGAGE_OUTBOUND_MANAGE_READ_SECRET` | the READ-scope key Manage issued for `engage:entitlements-reconcile` (Manage's push key may only POST; its read key may only GET). Unset: reconcile uses the push key and Manage answers 403 |

A key id whose secret is missing or shorter than 32 bytes is not live. No environment or no live key → every route of
that client answers 503 `inbound_not_configured`. `GET /health` reports `checks.manage_inbound`,
`checks.myaicountly_inbound`, `checks.manage_outbound` and `checks.entitlement_outbox` (never a secret).
Rotation: add the new id to `KEY_IDS` with its secret, switch Manage to it, then remove the old id.

## 9. Runbook

The old interim step "also run Manage's `subscription_admin.php set …`" is **retired**: Engage now tells Manage itself
(§6). Do not write `ghm_subscription` by hand for a licence — automation never writes it and never restricts.

### 9.1 Once, when switching this on (activation order)

1. **Deploy Engage** (migrations run; `jobs.txt` installs `licences-expire`, `entitlements-push`,
   `entitlements-reconcile`). Everything is dormant: push rows stay pending.
2. **Basic offers** — a super_admin runs `php spark engage:basic-offers --dry-run`, then `--apply` (add
   `--create-type` if no complimentary licence type exists). For each draft `<product>-basic`: set the Basic limits
   (entitlements, §6.2), check the app key, **Publish**. Only published defaults are provisioned.
3. **Manage keys** — Manage issues Engage a push key (and a read key for the reconcile:
   `ENGAGE_OUTBOUND_MANAGE_READ_KEY_ID` / `_SECRET`); set `MANAGE_API_BASE_URL`, `ENGAGE_OUTBOUND_MANAGE_KEY_ID`,
   `ENGAGE_OUTBOUND_MANAGE_SECRET` (and `ENGAGE_S2S_ENVIRONMENT`). Manage's inbound route must be live first; until
   then leave these unset (rows wait). Check `/health` → `manage_outbound: ok`; pending rows drain within a minute.
4. **my.aicountly key** — set `ENGAGE_INBOUND_MYAICOUNTLY_KEY_IDS` / `_SECRET_<KEYID>` / `_SCOPE_<KEYID>=issue`
   (optionally `_RATE_PER_MINUTE=120`) here and the same key on my.aicountly; check `myaicountly_inbound: ok`. New
   sign-ups get their Basic.
5. **Backfill** existing users from my.aicountly (`php spark accounts:engage-provision-backfill --dry-run`, then for
   real; batched and resumable). Watch Licensing → Manage sync.
6. Next day: read the `engage:entitlements-reconcile` output (cron log / Console monitor) for drift.

A product whose Basic is published later: publish it, then `php spark engage:provision-basic --pending`. A new
version of a Basic: publish it, then `php spark engage:entitlements-reproject --product=<code>`.

### 9.2 Selling a paid plan (until checkout exists)

1. **Payment and invoice** — collect payment (Pay link or bank transfer) and issue the GST invoice as today. Note the
   invoice number.
2. **Catalogue (once per offer)** — create the licence type and the offer (Licensing → Offers → New offer: product,
   type, label, price in ₹, price basis, GST rate, SAC `99xxxx`, term, seats, Manage app keys, entitlements) and
   **Publish**. To change it later: **New version** → edit → Publish.
3. **Find the holder** — the owner of the workspace the plan is for (D-L2: every company the owner owns uses it). In
   *Issue licence* search the holder by e-mail or uuid (holders who signed up are known); for one Engage has not heard
   of, type the my.aicountly uuid and say how you verified it. Optionally also map and pick a Manage company (legacy).
4. **Issue** — choose the offer, start date, seats, invoice number; leave **Activate now** ticked. The holder's Basic
   for that product is superseded and Manage receives the new plan within a minute (Licensing → Manage sync). A
   second paid licence of the same product is refused unless you tick **Add-on**; a double click is harmless (the
   drawer sends an idempotency key).
5. **Later changes** — open the licence: *Renew*, *Change plan* (upgrade / downgrade within the product; keep the end
   date or restart the term), *Extend*, *Suspend* / *Reinstate* / *Revoke*, *Change seats*, *Assign a seat*, *Link
   invoice*. Each change reaches Manage automatically. Revoking or letting a paid plan expire brings Basic back.
6. **If Manage sync shows rejected or dead rows** — read the error (e.g. an unknown app key or Manage's inbound not
   live), fix the cause, then **Re-queue**.

## 10. Tests

```
cd server-php
vendor/bin/phpunit --testsuite Unit
createdb engage_test
env database.tests.database=engage_test database.tests.username=postgres database.tests.password=postgres vendor/bin/phpunit
```

Licensing: `tests/Unit/AicS2sSignatureTest.php`, `tests/Unit/LicensingSupportTest.php`,
`tests/Feature/AicS2sFilterTest.php`, `tests/Feature/LicensingCatalogueTest.php`,
`tests/Feature/LicenceLifecycleTest.php`, `tests/Feature/ManageReadApiTest.php`, `tests/Feature/HolderLicensingTest.php`
(holder licences, outbox atomicity and seq order, delivery / retry / dead letter, idempotency, duplicate guard,
default-offer uniqueness, Basic supersede / reactivate, change-offer, provisioning, commands),
`tests/Unit/LicensingAutomationUnitTest.php` (registry mirror, signature vectors, jobs.txt) (base
`tests/_support/LicensingTestCase.php`).

## 11. Deviations from the contract draft

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
- **Rectification contract §3** (additive): pushes for a legacy company-only licence carry `holder_uuid: null` and
  `cmp_id`; a staff-revoked Basic is not re-issued by provisioning; Basic holders are pushed with the product's
  current Basic version; `GET catalogue` no longer requires `cmp_id`.

## 12. Extension points

`App\Services\Licensing\LicenceHooks::afterLicenceWrite(BaseConnection $db, string $event, array $licence, array
$input)` runs **inside the licence write transaction** for `issue`, `renew` and `change-offer` (a no-op here).
`$input` is the staff request, including the optional `quote_ref` (validated for shape only, stored nowhere by
licensing). The coupons stream implements it (commit the reserved redemption with the `licence_ref`); throwing
`LicenceHookRejected` refuses the write with its status / code / message and rolls everything back.
