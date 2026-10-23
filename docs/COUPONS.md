# Coupons, quotes and discounted licences

Founder decision **D-C1**: Engage staff with rights create, generate and distribute discount coupons. **GST is charged on
the price after discount.** Seller commission is computed on the **net amount actually invoiced (after discount, before
GST)**. Shared contract: §4.1 (Engage), §4.2 (Finance). Coupons are **web-only** — mobile apps never show coupon,
price or purchase wording.

A coupon is a trade discount agreed before supply and printed on the invoice (it reduces the value of supply), not a
prepaid voucher or wallet credit. A 100 % "free plan" is a complimentary licence, never a coupon: the net never falls
below ₹1.

**Dormant by default.** The migration creates empty tables and seeds nothing. The Manage routes answer
`503 inbound_not_configured` until a Manage key of scope `quote` is configured.

## 1. Model (migration `2026-10-23-200001_CreateCouponTables`)

| Table | What it holds |
|---|---|
| `engage_coupons` | The rule: `coupon_ref` (`CPN-000012`), `code_kind` shared \| single_use, `label`, `description`, `discount_type` percent \| flat, `percent_bp` (basis points, 1000 = 10 %), `flat_paise`, `max_discount_paise` (cap, percent only), `min_order_paise` (minimum **list** price on the offer's basis), `applies_to` `{product_codes[], offer_codes[], licence_type_codes[]}` (empty list = any), `new_or_renewal` any \| new \| renewal, `valid_from`, `valid_to` (exclusive; a typed date is the last valid day), `max_redemptions_total`, `max_redemptions_per_holder`, `stackable` (always false), `seller_id` (referral coupon), `campaign_ref`, `status`, `created_by`, `approved_by` / `approved_at`, `version` |
| `engage_coupon_codes` | `code` UNIQUE, stored normalised (upper case, no spaces / hyphens → **case-insensitive**), `kind`, `status` available → reserved → redeemed (back to available when a reservation is released) \| void, `batch_ref`, `exported_at`, `export_count` |
| `engage_quotes` | `quote_ref` (`QTE-000012`), holder (`holder_uuid` and / or `cmp_id`), optional `lead_id`, `kind` new \| renewal, the offer version, `price_basis`, `gst_rate`, `list / discount / net / taxable / gst / total_paise`, coupon, `status` open \| used \| expired \| cancelled, `expires_at` (+14 days), `licence_ref` once used, `idempotency_key` |
| `engage_coupon_redemptions` | The ledger, one row per use: coupon, code, holder, `holder_key` (`h:<uuid>` or `c:<cmp_id>`), lead, seller (snapshot), offer, amounts (as the quote), `state` reserved \| committed \| released \| reversed, `reserved_until`, `licence_ref`, `finance_document_ref`, `idempotency_key` UNIQUE (`quote:<quote_ref>`), `source` staff \| manage |
| `engage_coupon_attempts` | Every code check: channel, scope (holder or staff), **sha256 of the code (never the code)**, outcome, detailed reason, IP. Rate limits and abuse reports; pruned after 90 days |

Additive columns on existing tables: `engage_subscription_inquiries` (+ `request_ref`, `request_key`, `source`,
`holder_uuid`, `cmp_id`, `offer_code`, `quote_ref`, `contact_*`), `engage_lead_payments` (+ `quote_ref`, `list_paise`,
`discount_paise`, `basis_paise`, `basis_kind`), `engage_commission_entries` (+ `basis_kind`).

Database guarantees:

- **Active rules are immutable.** Trigger `engage_coupons_guard`: once a coupon leaves draft only its lifecycle columns
  (status, approval, timestamps, version) may change; allowed moves draft → active \| retired, active → paused \|
  expired \| exhausted \| retired, paused → active \| expired \| exhausted \| retired, expired \| exhausted → retired.
  Only a draft can be deleted. To change a discount, retire the coupon and create another.
- **The redemption state machine** (trigger `engage_coupon_redemptions_guard`): reserved → committed \| released,
  committed → reversed; amounts and identity never change; a committed redemption keeps its licence; rows are never
  deleted. Checks: `net = list − discount`, `discount ≥ 1`, `net ≥ 100` paise, committed ⇒ `licence_ref`.
- **One live use per single-use code** (partial unique index on `code_id` where state is reserved / committed).
- `idempotency_key` UNIQUE on redemptions; `quote_ref`, `coupon_ref`, codes UNIQUE.

## 2. Rights

| | View coupons, codes, redemptions, report, quotes | Export codes (CSV, audited) | Create / edit draft, activate (approve), pause, retire, generate / add / void codes, create quotes, release / reverse |
|---|---|---|---|
| super_admin | yes | yes | yes |
| sales_manager | yes | yes | yes |
| seller_manager | yes | yes | no (403) |
| kyc_reviewer, member | no (403) | no | no |

Enforced by `role:` filters in `Routes.php` and hidden in the UI (`web/src/lib/coupons.js`).

**Approval (four-eyes).** Activating a draft *is* the approval: it records `approved_by` / `approved_at`. A sales
manager cannot activate a coupon they created (`409 four_eyes`); another sales manager or an administrator does. A
super_admin may approve their own (recorded as the approver). A coupon needs at least one available code to be activated.

**Legacy approvals fixed.** `POST discount-requests/{id}/approve|reject` and `POST approvals/{id}/approve|reject|execute`
now carry `role:super_admin,sales_manager` (before, any signed-in role could approve), and a plain `PUT` on those rows
by any other role can no longer change the decision fields. The Approvals and Pricing / discount requests screens hide
the buttons for other roles (and the discount screen now reads `discount_percent` / `discount_amount`).

## 3. Codes and distribution

- Alphabet for generated codes: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (32 symbols, no 0 / O / 1 / I).
- **Shared** coupon: staff type a code (4–20 letters / digits, e.g. `DIWALI26`; spaces and hyphens ignored) or generate
  one (8 characters). A shared coupon may have several codes.
- **Single-use** coupon: *Generate codes* makes 1–5000 codes per batch: optional prefix (≤ 8 alphabet characters) + 10
  random characters (CSPRNG) + 2 check characters. Check: `S = Σ (i+1)·v(c_i) mod 1021` over prefix + random part;
  characters `alphabet[S div 32]`, `alphabet[S mod 32]` — any single wrong character or swap of neighbours is caught.
  Search space 32^10 ≈ 1.1 × 10^15.
- Codes are case-insensitive and shown in groups of four (`ABCD-EFGH-JKLM-NP`).
- **Distribute**: coupon page → *Export available codes (CSV)* (columns code, display, coupon_ref, label, offer,
  valid_until, status, batch_ref). Every export is audited (`coupon.codes_exported`) and stamps the codes'
  `exported_at` / `export_count`. Send the codes from your own mail / CRM; Engage does not email customers.
- *Void* takes an unused code out of circulation.

## 4. Pricing and GST (`App\Services\Coupons\PricingService`)

All amounts are integer paise. `L` = the offer's list price **on its own price basis**, `R` = the offer's `gst_rate`
held as basis points of a percent (18 % → 1800).

```
discount D = percent ? floor(L × percent_bp / 10000) : flat_paise
           D = min(D, max_discount_paise)          (if the coupon has a cap)
           D = min(D, L − 100)                     (net never below ₹1)
           D < 1 → not applicable
net      N = L − D                                 (same basis as the offer price)
GST on N (exactly Finance's GstCalculator::line, round half up per line):
  exclusive: taxable = N,                 gst = round(N × R / 10000),          total = N + gst
  inclusive: gst = round(N × R / (10000 + R)), taxable = N − gst,              total = N
```

A percent comes off the price as quoted: for an inclusive offer, 20 % off the GST-inclusive price; for an exclusive
offer, 20 % off the taxable value. A flat ₹X comes off the price on its basis (₹X less to pay on an inclusive offer;
₹X less taxable value on an exclusive one). Finance invoices the line with `amount = N`, `list_amount = L`,
`discount = D` and runs its unchanged GstCalculator on N — so Engage's quote and Finance's invoice agree to the paisa.

Worked examples (18 % GST):

| # | Offer | Coupon | List | Discount | Net (billed) | Taxable | GST | Total |
|---|---|---|---|---|---|---|---|---|
| 1 | ₹10,000 **exclusive** | 20 % | ₹10,000.00 | ₹2,000.00 | ₹8,000.00 | ₹8,000.00 | ₹1,440.00 | ₹9,440.00 |
| 2 | ₹11,800 **inclusive** | 20 % | ₹11,800.00 | ₹2,360.00 | ₹9,440.00 | ₹8,000.00 | ₹1,440.00 | ₹9,440.00 |
| 3 | ₹11,800 inclusive | flat ₹500 | ₹11,800.00 | ₹500.00 | ₹11,300.00 | ₹9,576.27 | ₹1,723.73 (`round(1130000×1800/11800)` = 172373 p) | ₹11,300.00 |
| 4 | ₹10,000 exclusive | 50 %, max ₹3,000 | ₹10,000.00 | ₹3,000.00 | ₹7,000.00 | ₹7,000.00 | ₹1,260.00 | ₹8,260.00 |
| 5 | ₹999.99 exclusive | 15 % | ₹999.99 | ₹149.99 (14999.85 p floored) | ₹850.00 | ₹850.00 | ₹153.00 | ₹1,003.00 |
| 6 | ₹500 exclusive | flat ₹600 | ₹500.00 | ₹499.00 (capped at L − ₹1) | ₹1.00 | ₹1.00 | ₹0.18 | ₹1.18 |

Without a coupon, example 1 would be ₹10,000 + ₹1,800 = ₹11,800: GST falls with the discount (D-C1).
`min_order_paise` is compared with the list price on the offer's basis. Tests: `tests/Unit/CouponPricingTest.php`.

## 5. Quotes, reservations and limits

`POST /api/v1/quotes` (staff; super_admin | sales_manager)

```json
{"holder_uuid":"…" | "cmp_id":42, "offer_code":"books-pro", "coupon_code":"diwali-26", "kind":"new|renewal",
 "lead_id":123, "idempotency_key":"optional"}
```

→ `201 {ok, data:{quote_ref, status, holder_uuid, cmp_id, lead_id, kind, offer{code,version,label,product_code},
price_basis, gst_rate, list_paise, discount_paise, net_paise, taxable_paise, gst_paise, total_paise,
coupon{code,coupon_ref,label}|null, redemption{id,state,reserved_until}|null, expires_at, licence_ref,
finance_line{amount_paise, list_amount_paise, discount_paise, discount_ref, price_basis, gst_rate, sac}}}`.
An unusable code answers `422 coupon_not_applicable` with `details.reason` (detailed, staff only) and
`details.reason_code` (generic). The same `idempotency_key` again answers the first quote (200).

- The offer must be published and on sale. With a code, one use is **reserved** for 14 days (`reserved_until` =
  quote `expires_at`).
- **Limits** count live uses: committed + reserved whose `reserved_until` is ahead. `max_redemptions_total` per coupon,
  `max_redemptions_per_holder` per `holder_key` (the holder uuid, else the company). Checked under row locks taken in a
  fixed order — code row, coupon row, redemption row — so two concurrent quotes for the last use cannot both succeed
  (tested with 8 parallel processes and a lock-timeout test), and no two writers deadlock.
- When committed uses reach `max_redemptions_total` the coupon becomes `exhausted`.
- Detailed reasons (staff): unknown_code, code_void, coupon_inactive, coupon_paused, coupon_retired, coupon_expired,
  not_yet_valid, coupon_exhausted, code_used, total_limit, holder_limit, wrong_product, wrong_offer,
  wrong_licence_type, wrong_kind, below_min_order, no_discount, offer_not_on_sale. Generic (Manage): see §7.
- **Expiry**: `php spark engage:coupons-expire` (hourly, `bin/ops/jobs.txt`, Console monitor `engage.coupons_expire`)
  releases reservations past `reserved_until` (single-use codes become available again), expires open quotes past
  `expires_at`, marks active / paused coupons past `valid_to` as `expired`, and prunes attempt rows older than 90 days.
  Reads already treat a lapsed reservation as released and an ended coupon as expired, and the next quote for a
  single-use code whose reservation lapsed releases it on the spot.
- Staff can **release** a reservation (reason) and **reverse** a committed redemption (reason, e.g. the invoice was
  credited in full) from the coupon page or the report. A reversal gives the use back to the limits; a single-use code
  stays redeemed; an exhausted coupon stays exhausted.

## 6. Licences: `quote_ref` and `CouponLicenceHook`

Issue / renew / change-offer accept `quote_ref`. The licensing code calls, **inside the licence write transaction**:

```php
\App\Services\Coupons\CouponLicenceHook::afterLicenceWrite($db, $event, $licence, $input);
```

(`$event` issued | renewed | plan_changed; `$licence` the row just written — `licence_ref`, `offer_code`,
`holder_uuid` / `cmp_id`; `$input['quote_ref']`, `$input['invoice_refs']` or `$input['finance_document_ref']`.)
No `quote_ref` → nothing. Otherwise the quote must be open, unexpired, for the same offer, holder / company (when both
sides know them) and kind (renewal quote ↔ renew); it is marked `used` with the licence_ref and its reservation is
**committed** (`licence_ref`, `finance_document_ref` = the first invoice number) — the licence and the redemption commit
or roll back together. Calling it again for the same licence is a no-op; another licence gets `quote_used`. Problems
throw `CouponException` (`->reason`, `->status` 422). `CouponLicenceHook::preflight(...)` performs the same checks
read-only before the transaction so the caller can answer a clean `422 quote_invalid`.

## 7. Manage-facing routes (AIC-S2S v1, client `manage`, key scope `quote`)

A key of scope **`quote`** may call **POST on exactly these two paths and nothing else** (route filter `aic-s2s:manage,quote`; the `manage` group accepts `read` / `issue` keys only;
60 requests / minute per key). Read keys stay GET-only and issue keys POST-only as before; these two routes refuse read
and issue keys with `403 route_not_allowed_for_key`.

```
ENGAGE_INBOUND_MANAGE_KEY_IDS=manage-read-2026-10,manage-quote-2026-10
ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_QUOTE_2026_10=<≥ 32 bytes, same value in Manage>
ENGAGE_INBOUND_MANAGE_SCOPE_MANAGE_QUOTE_2026_10=quote
```

**`POST /api/v1/manage/coupons/preview`** `{holder_uuid, offer_code, code}` → `200 {success, data:{valid, reason_code,
list_paise, discount_paise, net_paise, gst_rate, gst_paise, total_paise, coupon:{code,label}|null, price_basis,
taxable_paise}}`. No reservation. `reason_code` is generic only: `invalid_or_expired` (unknown / void / not active /
not yet valid / expired), `not_applicable` (product, offer, licence type, new-vs-renewal, minimum, no discount, offer not
on sale), `limit_reached` (used up, total or per-holder limit, single-use code taken). The list price breakdown is still
returned for a refused code (when the offer is on sale).

**`POST /api/v1/manage/upgrade-requests`** `{holder_uuid, cmp_id?, offer_code, coupon_code?, contact:{name, email,
phone?}, request_id?}` → `201 {success, data:{request_ref, quote:{quote_ref, list_paise, discount_paise, net_paise,
gst_rate, gst_paise, total_paise, price_basis, taxable_paise, coupon{code,label}, expires_at}|null,
coupon_reason_code?}}`. Creates a **subscription inquiry** (Licensing & subscriptions → Subscription inquiries;
`source = manage`, `request_ref` `UPG-000001`, linked to the one lead with that email if there is exactly one) and a
quote; with a valid code the quote holds a reserved redemption and is returned, otherwise `quote` is null (and
`coupon_reason_code` says why a given code was not usable). Idempotent on `request_id` (else `X-AIC-Request-Id`): a
retry answers `200` with the same `request_ref`. `422 offer_not_available` when the offer is not on sale.

Errors use the §0.2 envelope (`400 bad_request` with `details.field`, `429 rate_limited` + `Retry-After`, `503`).
Both routes are audited as `manage:<X-AIC-Actor>` (`manage.coupon.preview`, `manage.upgrade_request.created`, and the
rate-limited variants); the raw code is never logged.

## 8. Abuse limits

| What | Limit (env, default) |
|---|---|
| Manage preview per holder | `ENGAGE_COUPON_PREVIEW_LIMIT` (10) per `ENGAGE_COUPON_PREVIEW_WINDOW_SECONDS` (600) → 429 |
| Manage upgrade requests per holder | `ENGAGE_UPGRADE_REQUEST_LIMIT` (5) per `ENGAGE_UPGRADE_REQUEST_WINDOW_SECONDS` (3600) → 429 |
| Per Manage key | AIC-S2S: 60 / minute for a quote key |
| Staff quotes | logged, not limited |

Every attempt is in `engage_coupon_attempts` (code fingerprint only) for abuse review; generic reasons to customers,
detailed reasons only to staff.

## 9. Seller commission on the net

- *Payment confirmed* (`POST v1/leads/{id}/payments`) accepts `quote_ref` (preferred) or `net_amount`:
  `basis_paise` = the quote's (committed redemption's) **taxable value — net after discount, before GST**; else the
  typed `net_amount` (≤ amount paid); else, as before coupons, the amount paid (`basis_kind = payment_amount`). The
  payment row stores `quote_ref`, `list_paise`, `discount_paise`, `basis_paise`, `basis_kind`; the commission entry
  stores `basis_amount` and `basis_kind`. Never the list price.
- A **seller-owned coupon** (`seller_id`) is a referral: when a quote names the lead, `SellerLeadService::applyReferral`
  attributes the lead to that seller under the usual rules — an attributable seller, first valid registration wins,
  90-day protection; another seller's protection is never overridden (`coupon.seller_referral_ignored` is audited and
  the discount still applies). The redemption records the lead's seller at commit (the coupon's seller when there is
  no lead).

## 9b. Quote → Finance invoice line: field mapping (integration 2026-10)

Finance (`finance-aicountly`, docs/INVOICING.md "Discounted lines", migration `004_invoice_line_discounts`) stores
exactly what `finance_line` carries; nothing is renamed on the way:

| Engage quote (`POST /api/v1/quotes` → `data`, or `data.finance_line`) | Finance invoice line field | Note |
| --- | --- | --- |
| `finance_line.amount_paise` = `net_paise` | `amount_paise` (or leave out: computed as list − discount) | on the offer's price basis |
| `finance_line.list_amount_paise` = `list_paise` | `list_amount_paise` | null when no discount |
| `finance_line.discount_paise` = `discount_paise` | `discount_paise` | null when no discount; Engage caps it at list − 100, Finance validates the same bound |
| `finance_line.discount_ref` = the coupon code | `discount_ref` | the `quote_ref` (`QTE-…`) is also accepted by Finance |
| `finance_line.price_basis` (`price.basis` of the offer) | `price_basis` (`inclusive` / `exclusive`) | |
| `finance_line.gst_rate` (`gst_rate`) | `gst_rate` (percent) | |
| `finance_line.sac` | `sac` | |
| `taxable_paise`, `gst_paise`, `total_paise` | computed by Finance's GstCalculator on `amount` — must equal the quote's | same formula, integer paise, round half up per line |
| `quote_ref` | (not on the line) — staff then issue/renew the licence in Engage with this `quote_ref` | commits the redemption |

## 10. Runbook — quote → Finance invoice with discount → licence

1. **Create the coupon** (Sales → Coupons → New coupon): label, shared or single-use, percent / flat, cap, minimum,
   products / offers / licence types, new or renewal, validity, limits, seller (referral) or campaign. Add the shared
   code or generate the single-use codes.
2. **Approve**: another sales manager (or an administrator) opens it and clicks *Approve & activate*.
3. **Distribute**: *Export available codes (CSV)*; send them through your mail / CRM / seller.
4. **Quote** (Sales → Quotes): the customer's holder uuid (or Manage company), the offer, the code (and the lead if
   known) → note the **quote_ref** and the *Next steps* box. A customer's "Request upgrade" in Manage arrives as a
   subscription inquiry that already carries a quote_ref.
5. **Invoice** (finance.aicountly.org → Offline payments → Record payment): one line with the quote's figures —
   *List price* (`finance_line.list_amount_paise`), *Discount* (`discount_paise`), *Coupon code / quote ref*
   (`discount_ref`), price basis and GST rate as quoted; leave *Amount* empty (computed as list − discount). The amount
   received must equal the quote's total. Another Finance person verifies → the invoice shows List, Discount (code),
   Taxable, GST, Total (docs/INVOICING.md "Discounted lines").
6. **Issue the licence** (Licensing → Issue licence or the account page) with the invoice number and **Quote ref** —
   the reservation is committed with the licence. Renewals: a quote with *Renewal* and the renew action.
7. **Seller commission**: Lead → Seller & payments → *Payment confirmed* with the amount paid and the quote_ref.
8. **Refund / credit note**: Finance credit note (credits the net paid); then reverse the redemption in Engage (coupon
   page → Redemptions → Reverse) and refund the lead payment (which reverses commission).

Until the licensing integration wires `CouponLicenceHook` into issue / renew / change-offer, step 6 records the
licence but not the redemption: commit it from the hook integration (see the hand-back notes) — the reservation
otherwise lapses after 14 days.

## 11. Configuration (`api/.env`, names in `server-php/.env.example`)

| Variable | Meaning |
|---|---|
| `ENGAGE_INBOUND_MANAGE_SCOPE_<KEYID>=quote` | A Manage key limited to the two coupon routes (§7) |
| `ENGAGE_COUPON_PREVIEW_LIMIT`, `ENGAGE_COUPON_PREVIEW_WINDOW_SECONDS` | Preview checks per holder per window (10 / 600) |
| `ENGAGE_UPGRADE_REQUEST_LIMIT`, `ENGAGE_UPGRADE_REQUEST_WINDOW_SECONDS` | Upgrade requests per holder per window (5 / 3600) |

Cron (installed from `bin/ops/jobs.txt`): `coupons-expire | 41 * * * * | {php} spark engage:coupons-expire`.

## 12. API summary (staff, `/api/v1`, JWT)

| Route | Role |
|---|---|
| `GET coupons ?status=&code_kind=&seller_id=&campaign_ref=&q=`, `GET coupons/{id}`, `GET coupons/{id}/codes ?status=&batch_ref=&limit=&offset=` | view |
| `GET coupons/{id}/codes.csv ?status=available\|…\|all&batch_ref=` (audited) | view |
| `POST coupons`, `PUT coupons/{id}` (draft, `version`), `DELETE coupons/{id}` (draft) | admin |
| `POST coupons/{id}/activate \| pause \| retire` (`version`, `reason`) | admin |
| `POST coupons/{id}/codes` (`{code?}` shared · `{count, prefix?}` single-use), `POST coupons/{id}/codes/{codeId}/void` | admin |
| `GET coupon-redemptions ?coupon_id=&seller_id=&state=&holder_uuid=&quote_ref=&licence_ref=`, `GET coupon-report ?from=&to=&coupon_id=&seller_id=` | view |
| `POST coupon-redemptions/{id}/release \| reverse` (`reason`) | admin |
| `GET quotes`, `GET quotes/{quote_ref}` · `POST quotes` | view · admin |

Audit events (fanned out to Console): `coupon.created|updated|discarded|activated|resumed|paused|retired|expired|
exhausted|code_added|codes_generated|codes_exported|code_voided|redeemed|reservation_released|redemption_reversed|
seller_referral_ignored`, `quote.created`, `manage.coupon.preview`, `manage.upgrade_request.created`.

## 13. Tests

`tests/Unit/CouponPricingTest.php` (pricing, rounding, codes, scope rules), `tests/Feature/CouponLifecycleTest.php`
(rights, four-eyes, immutability, codes, CSV), `tests/Feature/CouponRedemptionTest.php` (quotes, limits, row locks and
8-process concurrency, state machine, expiry, hook), `tests/Feature/ManageCouponApiTest.php` (scope, generic reasons,
rate limits, upgrade requests), `tests/Feature/ApprovalRightsTest.php`, `tests/Feature/CouponCommissionTest.php`.
