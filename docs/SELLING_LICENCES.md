# Selling licences — price revisions, the website catalogue and seller licence sales

This document is the audit and plan for selling Aicountly licences (November 2026), and the reference for what was
built. Engage stays the one place where a licence is issued and where a price is decided. Customers see and manage
their licences in Manage; aicountly.com shows the published plans of each product; sellers buy licences in
seller.aicountly.com and apply them to their customers' accounts, earning commission.

```
                         engage.aicountly.org (staff, Console SSO)
   licence types · offers (versioned, scheduled price revisions) · licences · sellers · commission plans
        │ website catalogue          │ seller portal API            │ entitlement push + read API
        ▼ (AIC-S2S, website)         ▼ (X-Seller-Portal-Key)        ▼ (AIC-S2S, manage)
   www.aicountly.com            seller.aicountly.com            manage.aicountly.com
   plans per product            buy licences · stock ·          the customer's plans and licences
                                apply to a customer · earn
                                         │ payment
                                         ▼
                                finance.aicountly.org (invoice, payment link, payment events → Engage)
```

## 1. Audit — what existed before this change

| Area | Found | Gap |
|---|---|---|
| Licence types, offers, licences | Mature: versioned immutable offers, holder-level licences (D-L2), Basic (D-L1), append-only events, entitlement push to Manage, coupons and quotes ([LICENSING.md](LICENSING.md), [COUPONS.md](COUPONS.md)) | — |
| Price revisions | A new offer version replaced the old one **the moment it was published**; `new-version` cleared the dates | No "with effect from" date: a version published with a future `valid_from` retired the current price at once and left **nothing on sale** until that date. No price history view |
| Where an offer is sold | Every on-sale offer appeared in Manage's catalogue | No way to keep an offer off the website or out of the seller portal |
| aicountly.com | Product pages and `/pricing` deliberately state no price ("none is published") | No feed of published plans |
| Seller programme | Applications, approval, KYC, lead registration, commission plans, weekly payouts to Finance ([SELLER_PROGRAM.md](SELLER_PROGRAM.md)) | Staff could not **add a seller directly**; sellers could not **buy** licences or **apply** one to a customer; commission was only for leads staff confirmed by hand |
| Manage | Reads `companies/{cmp_id}/licences|seats|activity` and the catalogue; receives the per-app entitlement push | The read API is **company-scoped only**: every holder-level licence (all licences since D-L2, including Basic) never appears in Manage's Licences tab — only as a plan in the Plans tab, via the push. No field says who sold a licence |
| Finance → Engage payment events | Finance signs with AIC-S2S as client `finance`, sends the order in `lead_code`, and counts delivery only when the answer echoes `data.event_id` | Engage's receiver expected `X-Finance-Key`, read `source_ref`, echoed nothing and never compared the amount paid with the order — so no online payment could ever settle |
| Engage → Finance payment links | `purpose: tokens` only (closed list in Finance code and database) | Engage sent a 0/0/0 GST split, which Finance's strict check refuses; there is no `licences` purpose in Finance yet |

## 2. Decisions

1. **A price revision is a new offer version with an effective date.** Staff choose *Revise price* on the current
   version, give the new price (and, if needed, price basis / GST rate), the **effective-from date (WEF)** and a note.
   A future WEF makes the version `scheduled`; the current version stays on sale until that instant; at the WEF the
   new version takes over everywhere — reads are correct to the second, and `engage:offers-activate` (every minute)
   records it (old version retired at the WEF, new one published). A WEF of today publishes at once. One pending
   revision per offer; it can be cancelled until it takes effect.
2. **What a revision changes**: new sales, renewals and seller orders from the WEF. Existing licences keep the version
   they were sold at until they renew. A quote keeps the version it was made on until it expires, so a customer quoted
   before the revision pays the quoted price. An unpaid seller order keeps its price until the order expires.
3. **Channels.** Every offer names where it is sold: `website`, `manage`, `seller`. A new offer starts on all three;
   offers that existed before this change stay where they were sold before — Manage only — so nothing appears on the
   website or in the seller portal until staff choose it. Channels are marketing visibility, not commercial terms, so
   they can be changed on a published version without a new one. Staff can always issue any offer.
4. **Sellers pay the published price and earn commission.** A seller buys licences of offers sold on the `seller`
   channel at the same price and GST as everyone else, receives a GST invoice in the seller's legal name (from KYC),
   and earns commission under the **published commission plan** of the product when a licence is **applied to a
   customer** — through the existing ledger, hold window and weekly payout (TDS 194H at Finance). No hidden dealer price
   list exists; if the owner later wants an upfront dealer discount it is a separate, versioned price list.
5. **Stock.** A paid order becomes one *licence unit* per licence bought. A unit is applied once. Units do not lapse
   (a use-by policy, if wanted, belongs in the seller agreement first). Staff can void an unused unit (e.g. refunded).
6. **Applying to a customer.** The seller enters the email the customer signed up with at my.aicountly.com (Engage
   learns every sign-up from Basic provisioning) and confirms the customer asked for it. Exact match only, masked name
   shown, rate limited and audited. If the customer already holds the same plan, the unit **renews** it (one more term
   from its current end); a different live plan of the same product is refused (staff change plans); otherwise a new
   licence is issued — the customer's Basic is superseded and Manage is told within a minute.
7. **Lead protection is respected**: a customer protected for another seller cannot be sold to by a second seller
   (generic answer, as for lead registration). Otherwise the customer's lead is attributed to the selling seller.
8. **Commission sequence per product**: for licence sales, the first sale of a product to a customer is "payment #1"
   for the plan rules and each renewal the next, so `percent_of_first_payment` pays on every new customer licence.
9. **Payment.** Until Finance accepts `purpose: licences`, sellers pay by bank transfer / UPI (instructions are an
   Engage setting) and a sales manager **confirms the payment** on the order with the UTR, Finance's invoice number and
   the exact amount. The Finance payment-link request is built and ready (off until
   `ENGAGE_SELLER_ORDER_PAYMENT_LINKS=1`); a confirmed payment from Finance settles the order through the same code.
10. **Manage** gets holder-scoped read routes and a `sold_by` field; seller actions show the seller's name in a
    licence's activity. Manage must call the holder routes to list holder-level licences (follow-up in Manage).


## 3. What was built

| Where | What | Section |
|---|---|---|
| Engage — offers | **Revise price** with an effective date (scheduled versions), cancel a pending revision, price history; where each offer is sold (channels) | §4, §5 |
| Engage → www.aicountly.com | `GET /api/v1/website/catalogue`: the plans on sale on the website channel, with GST and any scheduled revision | §6 |
| Engage — sellers | **Add a seller directly**; sellers buy licences (orders → stock), apply one to a customer's account (new licence or renewal) and earn commission; staff **Licence orders** screen | §7 |
| Engage → Manage | Holder-scoped read routes (the licences a person holds), `sold_by`, `upcoming` price in the catalogue, the `manage` channel | §8 |
| Finance ⇄ Engage | Payment events received the way Finance sends them; the GST split on payment links; the `purpose: licences` request, ready behind a switch | §9 |
| seller.aicountly.com | Buy licences, Licence orders, Licence stock (apply to a customer), Customer licences — seller-aicountly `docs/ENGAGE_SELLER_API.md` → "Licences" | §7.5 |
| www.aicountly.com | "Plans and pricing" on each live product page, a plans table on `/pricing`, schema.org offers — aicountly-com `includes/engage-catalogue.php` | §6 |

**Nothing changes until staff switch it on.** Existing offers stay Manage-only; the website catalogue answers 503 until
its key is set (and the site keeps its own copy until then); seller orders are paid offline and confirmed by a sales
manager until payment links are switched on; no customer email is sent until `ENGAGE_NOTIFY_SELLER_LICENCES=1`.

## 4. Price revisions "with effect from" (WEF)

```
draft ──publish──▶ published ──revise, WEF today──────▶ new version published; this one retired now
                       │
                       └──revise, WEF in the future──▶ new version "scheduled"
                                                          ├─ at the WEF ──▶ published; the old version retired AT the WEF
                                                          └─ cancel-schedule ──▶ retired (it was never on sale)
```

**Revise** — `POST /api/v1/offers/{id}/revise` (super_admin | sales_manager)

```json
{ "price_paise": 1200000, "price_basis": "exclusive", "gst_rate": "18", "effective_from": "2027-01-01", "note": "Annual price review" }
```

- `{id}` must be the version **in effect now** (409 `not_current`, also when someone revised it meanwhile). Everything
  else — label, licence type, plan, term, seats, apps, entitlements, display, channels, Basic flag, end of sale — is
  copied from it; only the price, its basis and the GST rate change (`price_basis` / `gst_rate` default to the current
  ones). For anything else, use *New version* as before.
- `effective_from`: `YYYY-MM-DD` means 00:00 IST that day; an ISO-8601 instant is taken as given. Not in the past, and
  before the offer's own `valid_to`. **Today** publishes the new version at once (the old price was on sale until now).
- `note` (3–1000 characters) is kept on the version and shown in the price history.
- Refusals: 422 `validation_failed` (bad price / basis / rate / date / note, or the price is unchanged), 409
  `draft_exists` (publish or discard the code's draft first), 409 `revision_pending` (one revision per offer at a time —
  cancel it first), 409 `default_offer_exists`, 422 `course_not_licensable`.
- Answer 201: the new version, `status` `scheduled` or `published`.
- Publishing a draft whose `valid_from` is in the future also schedules it (the offer editor says so).

**Cancel** — `POST /api/v1/offers/{id}/cancel-schedule {reason}`: the scheduled version is retired with "Withdrawn before
it took effect: …". 409 `not_scheduled`, 409 `already_in_effect` (revise again instead).

**History** — `GET /api/v1/offers/price-history/{offer_code}` (every signed-in role): `{offer_code, current{id, version,
on_sale_now}, scheduled{id, version, effective_from}|null, versions[…offer, effective_to, was_on_sale]}`, newest first.

**When it takes effect.** "In effect" is one SQL predicate, `OfferService::onSaleSql()`: a published version, or a
scheduled one whose WEF has come, unless a newer scheduled version is in effect too. Every reader uses it — Manage's
catalogue, the website catalogue, the sellers' catalogue, staff issue / renew / change plan / quote, Basic provisioning —
so the switch is exact to the second, before any job runs. `php spark engage:offers-activate` (every minute) then
records it: the scheduled version becomes `published`, the one before it `retired` with `retired_at` = the WEF and
"Replaced by vN". When a product's Basic (default) offer changes, its holders' plan states are re-queued for Manage
(`EntitlementOutbox::reproject`; by hand: `php spark engage:entitlements-reproject [--holder=] [--product=] [--dry-run]`).

**What a revision changes.** New sales, renewals and seller orders from the WEF. A licence keeps the version it was sold
at until it is renewed. An open quote keeps the version it was priced on until it expires (`quotedVersion()`). An unpaid
seller order keeps its price until it expires. A seller's renewal never moves a licence to an older version.

**Database.** `engage_offers.status` adds `scheduled` (needs `valid_from` and `scheduled_at`); at most one scheduled
version per code (`uq_engage_offers_scheduled`); the guard trigger `engage_offers_guard` allows draft → published |
scheduled, scheduled → published | retired, published → retired, and keeps a scheduled version's price, basis, rate,
dates and note immutable. New columns: `scheduled_at`, `scheduled_by`, `revision_note`, `retired_reason`.

**Staff UI** (Licensing → Offers): **Revise price** on the version on sale (the new price shown with GST as Finance will
bill it; the date defaults to tomorrow), **Cancel revision** on a scheduled one, **Price history** per offer code.
Audit: `offer.revised`, `offer.revision_cancelled`.

## 5. Where an offer is sold (channels)

`engage_offers.channels` ⊆ {`website`, `manage`, `seller`}:

| Channel | Means |
|---|---|
| `website` | Listed on www.aicountly.com (§6) |
| `manage` | In Manage's catalogue (`GET v1/manage/catalogue`). A product's Basic (default) offer is always there, whatever its channels: Manage's hourly `plans_sync.php` needs it |
| `seller` | Sellers can buy it in seller.aicountly.com — priced offers only (§7) |

A new offer starts on all three; offers that existed before migration `2026-11-15-000110` are on `manage` only. Staff
can always issue any published offer, whatever its channels. Channels are visibility, not price, so
`POST /api/v1/offers/{id}/channels {"channels": ["website", "manage"]}` (super_admin | sales_manager) changes them on a
draft, published or scheduled version without a new version (409 on a retired one); a revision copies them. Staff UI:
the "Sold on" chips on each version and the **Where it is sold** drawer. Audit: `offer.channels_changed`.

## 6. The website catalogue (www.aicountly.com)

`GET /api/v1/website/catalogue` — AIC-S2S v1 ([LICENSING.md §7](LICENSING.md)), client `website`, a `read` key;
503 `inbound_not_configured` until the keys below are set. Public information, so reads are not audited;
`Cache-Control: private, max-age=60`.

```json
{ "success": true,
  "data": { "generated_at": "2026-11-15T04:30:00Z", "currency": "INR",
    "products": [ { "code": "books", "label": "Aicountly Books", "offers": [ {
      "offer_code": "books-pro", "version": 3, "label": "Books Pro", "description": "…",
      "product": {…}, "licence_type": {…}, "plan": {…}, "is_default": false,
      "price": { "currency": "INR", "amount_paise": 1000000, "basis": "exclusive", "gst_rate": "18", "sac": "997331",
                 "taxable_paise": 1000000, "gst_paise": 180000, "total_paise": 1180000 },
      "term": { "months": 12, "days": 0, "cycle": "Billed yearly" }, "seats": 3,
      "entitlements": [ { "code": "max_companies", "label": "Companies", "value": 2, "unit": "count" } ],
      "attributes": [ { "label": "Support", "value": "Email and chat" } ], "display": { "badge": "Most popular" },
      "effective_from": "…", "valid_to": null,
      "upcoming": { "version": 4, "effective_from": "2027-01-01T00:00:00+05:30", "price": { …same shape… } } } ] } ] },
  "request_id": "…" }
```

- Only offers **in effect now on the `website` channel** (a Basic is listed when it is on that channel), grouped by
  product. `price` is what Finance will bill: GST worked out on the price basis, rounded half up, to the paisa.
- `upcoming` is the scheduled revision, with its effective date — the site shows "From 1 January 2027: ₹14,160".
- `display.badge` (free text) is the card's ribbon; `display.cycle_label` replaces the term text;
  `display.attributes` are listed under the price.

**On the site** (aicountly-com `includes/engage-catalogue.php`, `includes/ui/pricing.php`): the server signs the
request, caches the answer 10 minutes, keeps showing the last good answer for up to 7 days while Engage cannot be
reached, and then shows no price rather than an unconfirmed one. Unconfigured, every page reads exactly as before.
Plans appear only for **live products** (the launch line-up): Beta apps "are not part of any plan" in the site's own
words, and Academy courses are not software. A free plan's button is "Sign up free"; a paid plan's is "Talk to sales",
carrying the product and plan into the enquiry (`utm_campaign` = product, `utm_content` = offer code) — there is no
self-serve checkout yet. Each product page's SoftwareApplication JSON-LD gets `offers` (INR, GST included,
`priceValidUntil` the day before a scheduled revision); `/pricing` lists every published plan by product. The site's
slugs are Engage product codes except `financial-reporting` → `fr` and `our-people` → `hrms`.

**Switching it on.** Choose a key id and a secret of at least 32 bytes (e.g. `openssl rand -hex 32`).

| Engage `api/.env` | aicountly.com `.env` |
|---|---|
| `ENGAGE_INBOUND_WEBSITE_KEY_IDS=website-read-2026-11` | `ENGAGE_API_BASE_URL=https://engage.aicountly.org/api` |
| `ENGAGE_INBOUND_WEBSITE_SECRET_WEBSITE_READ_2026_11=<secret>` | `ENGAGE_WEBSITE_KEY_ID=website-read-2026-11` |
| `ENGAGE_INBOUND_WEBSITE_SCOPE_WEBSITE_READ_2026_11=read` | `ENGAGE_WEBSITE_SECRET=<secret>` |
| (`ENGAGE_S2S_ENVIRONMENT=production`, already set for Manage) | `ENGAGE_S2S_ENVIRONMENT=production` (the default) |

Engage's `/health` then shows `website_inbound: ok (production, 1 key)`. Tick **Website** on the offers to list.

## 7. Sellers selling licences

### 7.1 Adding a seller directly

`POST /api/v1/sellers` (super_admin | seller_manager) `{name, email, mobile, business_name?, entity_type?, city?,
state?, note?}` creates the same seller an approved application does — `SLR-NNNNNN`, a referral code, status
`onboarding`, a one-time activation link emailed (or shown once when no mailer is configured) — and the seller then
accepts the agreement and completes KYC as usual. 409 `seller_exists` (the email is already a seller) or 409
`application_open` (approve that application instead, so its lead and history stay linked). Staff UI: Sellers →
**+ Add seller**; the sellers list marks them "Added by staff".

### 7.2 The flow

```
seller (active, KYC verified)
  │ 1  GET licence-catalogue → POST licence-orders {lines:[{offer_code, quantity}]}       (Idempotency-Key)
  ▼
SLO-000123  awaiting_payment — price held until expires_at (setting seller_order_validity_days, default 7)
  │ 2  seller pays: bank transfer / UPI quoting SLO-000123 (setting seller_order_payment_instructions),
  │    or a Finance payment link once ENGAGE_SELLER_ORDER_PAYMENT_LINKS=1
  │ 3  settled: a sales manager confirms it (UTR, amount, Finance invoice no.) or Finance sends payment.succeeded
  ▼
paid — one licence unit per licence bought: SLU-000456 … available
  │ 4  POST customers/lookup {email} (masked answer) → POST licence-stock/SLU-000456/apply
  │       {customer_email, consent_confirmed: true}                                    (Idempotency-Key)
  ▼
a new licence for the customer (source seller) or one more term on theirs; Basic superseded; Manage told
the customer's lead attributed to the seller → lead payment → commission entry (hold) → weekly payout via Finance
```

### 7.3 Orders

- **Who.** An `active` seller whose KYC is `verified` (403 `seller_not_active` / `kyc_required`).
- **What.** Offers in effect on the `seller` channel with a price above zero (never an Academy course). Up to 10 lines,
  each offer once, quantity 1–100. A line is the offer's price on its basis × quantity, GST worked out once on the
  line amount, rounded half up — exactly as Finance's `GstCalculator` does, so the invoice and the order agree to the
  paisa. Each line keeps the offer code, version and price it was ordered at.
- **Billed to** the seller's legal name, GSTIN, address and state from KYC (decrypted on the server; the seller sees
  the GSTIN masked, `27XXXXXXXXXF1Z5`). CGST + SGST or IGST follows the state: 422 `billing_state_unknown` when the
  KYC address does not tell it; 503 `kyc_unavailable` when KYC cannot be read.
- **Idempotency.** `Idempotency-Key` is required; the same key with a different body is 409 `idempotency_conflict`.
- **Statuses.** `awaiting_payment` → `paid` | `cancelled` (by the seller or staff, with a reason) | `expired`
  (`php spark engage:seller-orders-expire`, hourly at :53, once `expires_at` has passed). A payment that arrives for
  an expired order is still honoured — the seller paid the price they were quoted. A cancelled order is never settled
  (409 `order_closed`: refund it). A paid order cannot be cancelled; staff withdraw unused licences instead.
- **Settlement** (`SellerLicenceOrderService::settle`, staff or Finance): the amount must be the order total to the
  paisa (409 `amount_mismatch`, nothing recorded); one payment settles one order (409 `payment_used`); the same payment
  again answers 200 `duplicate`. The units are created in the same transaction, the line's taxable value and GST split
  across them to the paisa.
- **Rate limits** per seller: 30 orders an hour; 10 payment-link requests an hour per order.
- **Staff** (Sellers → **Licence orders**, `/seller-licence-orders`; the seller's page links to theirs):
  `GET /api/v1/seller-licence-orders` (`?status=&seller_id=&q=`), `GET …/{ref}`,
  `POST …/{ref}/confirm-payment {payment_ref (UTR), amount ("23600.00") or amount_paise, invoice_no, invoice_date?, paid_at?}`,
  `POST …/{ref}/cancel {reason}`, `GET /api/v1/seller-licence-units` (`?status=available|applied|void`),
  `POST /api/v1/seller-licence-units/{ref}/void {reason}` (an unused licence only: 409 `unit_not_available`).
  View: super_admin | sales_manager | seller_manager; confirm, cancel, withdraw: super_admin | sales_manager.
- Audit: `seller.licence_order_created`, `seller.licence_order_paid` and `seller.licence_order_cancelled` (both also
  to Console), `seller.licence_unit_voided`.

### 7.4 Applying a licence to a customer

- **Lookup** `POST customers/lookup {email}` — exact match on the email the customer signed up with at
  my.aicountly.com (Engage learns every sign-up from Basic provisioning). Masked name and email only, whether this
  seller may sell to them, and the plans they hold of the products in the seller's stock (so the portal can say
  "renews" or "different plan"). No account: `found: false` with the seller's own sign-up link. Two accounts with the
  email: 409 `customer_ambiguous`. 30 lookups an hour per seller; each is audited (`seller.customer_lookup`, the email
  hashed).
- **Apply** `POST licence-stock/{unit_ref}/apply {customer_email, consent_confirmed: true}` (120 an hour per seller):
  - the unit must be unused (409 `unit_used` / `unit_void`) and the seller still active;
  - a customer protected for another seller is refused with the generic `lead_protected`, as for lead registration;
  - the **same plan** live → **renewal**: one more term from its current end (a renewal never moves the licence to an
    older version); a **different live plan of the same product** → 409 `plan_conflict` (staff use *Change plan*);
    suspended → 409 `licence_suspended`; issued but not yet active → 409 `licence_not_active`; a live licence with no
    end → 409 `live_licence_exists`;
  - otherwise a **new licence** (`source = seller`, `seller_id`, starting now) to the customer's holder; their Basic of
    that product is superseded. The plan state is queued for Manage in the same transaction (Manage has it within a
    minute).
- The licence's activity reads "‹organisation›, Aicountly seller SLR-…"; Manage's licence detail shows **Sold by**.
- **Commission.** The customer's lead is attributed to the seller (`attribution = licence_sale`, converted / won),
  and the sale is recorded as a lead payment (`source = seller_licence`, the amount the seller paid for the unit,
  commission basis its **taxable value** — net before GST, D-C1) with a **per-product sequence** (§2.8), evaluated under
  the product's published commission plan → a commission entry on hold → released → the weekly payout through
  Finance (TDS 194H at Finance). A seller applying a licence to their **own** sign-up email gets the licence and no
  commission.
- **Customer email.** Off by default. `ENGAGE_NOTIFY_SELLER_LICENCES=1` sends Console's `plan.changed` template once
  its variables are confirmed in Console; Manage shows the licence either way.
- Audit: `seller.licence_applied`.

### 7.5 The seller portal

seller.aicountly.com adds **Buy licences**, **Licence orders**, **Licence stock** (apply to a customer) and **Customer
licences** (renewals due first). Engage routes under `/api/v1/seller-portal` (`X-Seller-Portal-Key` + `X-Seller-Session`):
`GET licence-catalogue`, `GET|POST licence-orders`, `GET licence-orders/{ref}`, `POST licence-orders/{ref}/cancel`,
`POST licence-orders/{ref}/payment-link`, `GET licence-stock`, `POST licence-stock/{ref}/apply`,
`POST customers/lookup`, `GET customer-licences`. Wire shapes, error codes and the BFF's checklist: seller-aicountly
`docs/ENGAGE_SELLER_API.md` → "Licences".

## 8. Manage

What Engage now gives Manage (all `aic-s2s:manage`, read, same envelope, paging, errors and audit as
[LICENSING.md §5](LICENSING.md)):

| Route | Answer |
|---|---|
| `holders/{holder_uuid}/licences?status=&product_code=&limit=&cursor=` | the licences a **person** holds: every Basic, plans staff issued to them, plans sold to them by a seller |
| `holders/{holder_uuid}/licences/{licence_ref}` | one licence (another holder's: 404 `not_found`) |
| `holders/{holder_uuid}/licences/{licence_ref}/seats` | its seats |
| `holders/{holder_uuid}/activity?limit=&cursor=` | its events, newest first |

- A malformed uuid is 400 `bad_request`. Audit: `manage.holder_licences.list`, `manage.holder_licence.read`,
  `manage.holder_licence.seats`, `manage.holder_activity.list`.
- Every licence answer adds `sold_by: {kind: "seller", seller_code, name} | null`, and an attribute
  `{label: "Sold by", value: "‹name› (SLR-…)"}` so Manage's generic attribute list shows it without a code change.
- The catalogue lists only offers on the `manage` channel (and every Basic), each with `upcoming` (version,
  effective_from, price) when a revision is scheduled.

**Manage follow-ups** (manage-aicountly; not changed here):

1. **Licences tab:** call `holders/{uuid}/licences` with the signed-in user's own my.aicountly uuid — as Manage asks
   only for its own company today — and merge it with the company list on `licence_ref`. Until then every
   holder-level licence (every Basic, and everything issued since D-L2, seller sales included) shows only as a plan
   in the Plans tab, through the entitlement push.
2. **Plans tab:** show `upcoming` ("₹14,160 from 1 January 2027").

## 9. Finance

**Payment events (fixed here).** `POST /api/v1/inbound/finance/payment-events` (filter `finance-events`) now takes
Finance's `EngagePaymentEventOutbox` as it is sent: signed AIC-S2S v1 as client `finance` with an `issue` key
(`ENGAGE_INBOUND_FINANCE_KEY_IDS`, `…_SECRET_<KEYID>`, `…_SCOPE_<KEYID>=issue`), or the old `X-Finance-Key`; the
order in `lead_code` (`source_ref` and `order_ref` are read too); `event_id` echoed for every event Engage acted on
(Finance counts an event delivered only then; a repeat answers the same with `duplicate: true`); the amount must be
the order total (409 `amount_mismatch`, nothing granted). `TKO-…` token orders and `SLO-…` seller licence orders are
acted on; `payment.refunded` on a seller order is recorded for staff, who withdraw unused licences. Anything else is
acknowledged without `event_id`.

**GST split on payment links.** Engage used to send CGST / SGST / IGST as 0 / 0 / 0, which Finance's strict check
refuses. Links now carry SGST = ⌊GST / 2⌋ and CGST = the rest when the payer is in Aicountly's state
(`ENGAGE_GST_STATE_CODE`), else IGST — Finance's own `GstCalculator` rule.

**Payment links for seller orders (proposed contract).** With `ENGAGE_SELLER_ORDER_PAYMENT_LINKS=1`, Engage asks
Finance for a link per order through the existing client (`POST /v1/inbound/engage/payment-links`, AIC-S2S, scope
`issue`):

```json
{ "purpose": "licences", "source_ref": "SLO-000123", "seller_code": "SLR-000045", "buyer_gstin": "27ABCDE1234F1Z5",
  "payer": { "name": "RK Associates", "email": "accounts@rk.example", "mobile": "98XXXXXX10", "state_code": "27",
             "address": "…", "pincode": "411001" },
  "amount_paise": 2360000,
  "lines": [ { "description": "Books Pro — 2 licences", "product_code": "books", "offer_code": "books-pro",
               "offer_version": 2, "quantity": 2, "unit_price_paise": 1180000, "amount_paise": 2360000,
               "price_basis": "inclusive", "gst_rate": "18", "sac": "997331",
               "taxable_paise": 2000000, "cgst_paise": 180000, "sgst_paise": 180000, "igst_paise": 0 } ],
  "fee": { "taxable_paise": 2000000, "cgst_paise": 180000, "sgst_paise": 180000, "igst_paise": 0 },
  "expire_at": "2026-11-22T04:30:00Z" }
```

Finance answers with the link as for `purpose: tokens`, and later sends `payment.succeeded` with `lead_code` =
`SLO-000123`, which settles the order through the same code as a staff confirmation.

**Finance follow-ups** (finance-aicountly; not changed here):

1. Accept `purpose: licences` — today the purpose is a closed list in Finance's code and database (`tokens` only) —
   and invoice per line (description, SAC, rate, CGST / SGST / IGST per line).
2. Invoice the seller as a business: legal name and GSTIN, with no "client" record in Finance today.
3. Payer mobile: Finance requires one; Engage sends the KYC mobile when there is one.
4. Check at link creation that the GSTIN's state (its first two digits) matches `payer.state_code`.

Until then: `ENGAGE_SELLER_ORDER_PAYMENT_LINKS=0`. Sellers pay by bank transfer / UPI quoting the order reference;
Finance raises the invoice by hand; a sales manager confirms the payment on the order.

## 10. Configuration, runbook, roles and tests

**Environment** (`api/.env`; names in `server-php/.env.example`):

| Name | Default | For |
|---|---|---|
| `ENGAGE_GST_STATE_CODE` | — | Aicountly's GST state (two digits): CGST + SGST or IGST on Finance links (tokens and seller orders) |
| `ENGAGE_INBOUND_WEBSITE_KEY_IDS`, `…_SECRET_<KEYID>`, `…_SCOPE_<KEYID>=read` | — | the website catalogue (§6) |
| `ENGAGE_INBOUND_FINANCE_KEY_IDS`, `…_SECRET_<KEYID>`, `…_SCOPE_<KEYID>=issue` | — | signed payment events from Finance (§9) |
| `ENGAGE_SELLER_ORDER_PAYMENT_LINKS` | `0` | Finance payment links for seller orders (§9) |
| `ENGAGE_NOTIFY_SELLER_LICENCES` | `0` | email the customer when a seller applies a licence (§7.4) |

**Settings** (Engage → Settings, super_admin): `seller_order_validity_days` (7) — how long an unpaid order holds its
price; `seller_order_payment_instructions` — the bank account / UPI ID sellers pay to (empty: "Aicountly will share the
bank details for this order"). The Settings screen saves one setting at a time as `{key, value}`; that form used to be
stored as two settings literally named `key` and `value`, so earlier edits made there never took effect — re-enter any
value changed on that screen before this release (the last attempt is in the rows named `key` and `value`).

**Scheduled jobs** (`server-php/bin/ops/jobs.txt`, installed by the production deploy):

```
* * * * *    php spark engage:offers-activate        # Console monitor engage.offers_activate
53 * * * *   php spark engage:seller-orders-expire   # Console monitor engage.seller_orders_expire
```

**Switching on, in order:**

1. Deploy: migrations `2026-11-15-000100` (scheduled revisions), `000110` (channels), `000200` (seller licence sales)
   are additive. Register the two Console cron monitors.
2. Offers: check each plan to be sold (price, basis, GST rate, SAC, term, entitlements) and tick where it is sold.
   Change a price only with **Revise price** and its effective date.
3. Website: set the key pair on both sides (§6), check Engage's `/health`, open a product page and `/pricing`.
4. Sellers: set `seller_order_payment_instructions`; publish a commission plan for each product sellers will sell;
   tick **Seller** on those offers; tell sellers (a seller can buy once active and KYC-verified).
5. When a seller pays: raise the invoice in Finance, then Sellers → Licence orders → the order → **Confirm payment**
   (UTR, amount, Finance invoice number). The licences appear in the seller's stock at once.
6. Later: Finance `purpose: licences` → `ENGAGE_SELLER_ORDER_PAYMENT_LINKS=1`; Manage's holder routes (§8);
   `ENGAGE_NOTIFY_SELLER_LICENCES=1` once the `plan.changed` template is checked.

**Roles** (Console → Roles & Access; no new role):

| Action | Roles |
|---|---|
| Revise a price, cancel a revision, change channels | super_admin, sales_manager |
| Price history, offers | every signed-in role |
| Add a seller directly | super_admin, seller_manager |
| See seller licence orders and stock | super_admin, sales_manager, seller_manager |
| Confirm a payment, cancel an order, withdraw an unused licence | super_admin, sales_manager |
| Settings (payment instructions, order validity) | super_admin |

**Tests** (`server-php/tests/Feature`): `OfferRevisionsTest` (revise now / later, every read switches at the WEF before
the job runs, the job, validation, cancel, publishing with a future date, immutability, quotes keep their price,
channels), `WebsiteCatalogueTest` (dormant until configured, website offers with GST and `upcoming`, other clients' keys),
`SellerDirectCreateTest`, `SellerLicenceSalesTest` (order → confirm → stock → apply / renew / conflict / protection →
commission; Finance settlement and amount checks), `ManageHolderReadApiTest` (holder scope, `sold_by`),
`SettingsUpdateTest`. seller-aicountly: `php server-php/tests/run.php` and `cd web && npm test`. aicountly-com:
`tests/EngageCatalogueTest.php` (`vendor/bin/phpunit -c tests/phpunit.xml`).
