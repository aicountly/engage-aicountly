# Aicountly Academy fees through Engage — runbook

Course fees for **Aicountly Edge** (long-term training programme) and **Aicountly Aspire** (short-term workshops
and courses) are sales revenue, so they are recorded in Engage. Engage then credits the salesperson, the seller's
commission and the Reach campaign from the same record.

Who owns what:

| Owner | Responsibility |
| --- | --- |
| **Academy** (academy.aicountly.org; staff still use Edge `/ops` and Aspire `/ops` at launch) | The fee, its GST treatment, instalments, and the admission decision. |
| **Aicountly Finance** (finance.aicountly.org) | Aicountly's own money: the Razorpay and Cashfree merchant accounts, payment links, refunds and GST invoices. |
| **Engage** | The lead, the "payment confirmed" record (`engage_lead_payments`), seller commission, the Reach outcome, and the revenue view. Engage holds no gateway and calculates no fee or GST. |

Launch day (soft launch 2026-10-25) is **manual but correct**. The week-1 automation is designed in § 6. It is
not built yet.

---

## 1. Products

Migration `2026-10-24-000010_AddAcademyProducts` (it runs on deploy with `php spark migrate`) and `ProductsSeeder`
(fresh installs only) add the products below:

| code | name | category | use |
| --- | --- | --- | --- |
| `academy` | Aicountly Academy | Academy | A lead who has not chosen a course yet (the umbrella). |
| `edge` | Aicountly Edge | Academy | Long-term training programme. |
| `aspire` | Aicountly Aspire | Academy | Short-term workshops and courses. |

The migration is idempotent. If a row already exists, only its name and category are corrected; its id, sort
order, description and `is_active` stay as they are.

`edge` and `aspire` are also **Manage registry keys** (Licensing: `ManageAppRegistry`, offers' `app_keys`, entitlement
codes `edge.max_active_learners` / `aspire.max_workshops_per_month`). It is one product row per code, shared by both
uses: licensing offers and entitlements keep working on the code, and course leads and fee payments name the same
code. Only the row's category becomes `Academy` (it was `learning`); nothing in Engage reads the category except the
revenue filter below. `academy` is a course-only code, not a Manage app key.

**Matching.** Every lead intake links a free-text interest to a product by **code or display name**. The match
ignores case and extra spaces, and a code match wins over a name match (`ProductsModel::resolve`):

| Intake | Field | `edge`, `EDGE`, `Aicountly Edge` → | Unknown text |
| --- | --- | --- | --- |
| Reach (`POST /api/v1/internal/reach/leads`) | `interested_product` | `interested_product = edge`, product id linked | kept as sent, no link (unchanged) |
| Seller portal (Register Lead) | `interest` (or `interested_product`) | `interested_product = edge`, product id linked | no product; the text stays in `subscription_interest` |
| Payment confirmation | `product_code` | stored as `edge` | stored as sent |

A plan's `product_scope` holds exact codes, so normalising "Edge" to `edge` is what lets commission accrue.
"Aicountly" alone, or "edge programme", matches nothing. Leads from the website arrive through Reach capture,
so the Reach row covers them.

---

## 2. Launch-day flow

```
Academy: fee defined, student admitted
      │
      ▼
Engage lead (interested_product edge | aspire)  ◀── Reach / seller portal / website (via Reach) / staff
      │
      ▼
Finance: payment link created in the Razorpay or Cashfree merchant dashboard (Aicountly's own account)
      │  student pays
      ▼
Sales manager: "Payment confirmed" on the lead in Engage (payment_ref = gateway payment id)
      │
      ▼
Revenue · seller commission · Reach outcome  →  Academy marks the fee settled / confirms the seat
```

### Step 1 — The lead exists in Engage with the right product

- **Reach** (campaigns `academy-edge-launch` and `academy-aspire-launch`, and the aicountly.com/academy "Talk to
  admissions" form): `interested_product` arrives as `edge` / `aspire`. A display name also resolves (§ 1). A
  `ref` / `seller_code` attributes the lead to that seller (90-day protection).
- **Seller portal**: the seller picks Edge or Aspire (or types it). The lead is attributed to the seller.
- **Walk-in or phone**: staff create the lead in Engage.
- Check the lead: the product pill on the lead page shows `edge` / `aspire`. If it is missing or wrong, it can
  still be chosen when you confirm the payment (Step 4).
- If the seller is wrong or missing: `POST /api/v1/leads/{id}/seller {"seller_code":"SLR-000123","reason":"…"}`
  (super_admin | sales_manager | seller_manager). You can also use Lead → "Seller & payments" in the UI.

### Step 2 — Academy admits the student and fixes the fee

Admissions confirm the student in Edge `/ops` or Aspire `/ops`. The fee (and its GST) is whatever Academy
defines. Engage only records the amount that was actually collected.

### Step 3 — Finance creates the payment link

Finance staff create a **Payment Link** in Aicountly's own **Razorpay** or **Cashfree** merchant dashboard. At
launch, Aicountly Finance has no gateway integration of its own yet. Fill in:

- the amount Academy fixed (including GST, if GST applies);
- the student's name, email and mobile;
- a description or reference that includes the **Engage lead code** (`ENG-…`) and the course (`edge` / `aspire`),
  so the payment can be matched to the lead later;
- an expiry.

Send the link to the student. Paid Aspire courses stay **free-registration + invite-only** until the fee is
confirmed (plan item L9).

### Step 4 — The sales manager confirms the payment on the lead

When the gateway shows the payment as **captured / successful**, a **sales_manager** or **super_admin** records
it. Use the UI (Lead → "Seller & payments": amount, gateway id, paid date, **Product**, notes → *Payment
confirmed*) or the API:

```http
POST /api/v1/leads/{lead_id}/payments
Authorization: Bearer <staff JWT>
Content-Type: application/json

{
  "amount":       "50000.00",
  "payment_ref":  "pay_PQ1x2y3z4A5b6C",
  "product_code": "edge",
  "paid_at":      "2026-10-25",
  "notes":        "Razorpay payment link plink_… — Edge batch Nov-2026"
}
```

The fields (`CommissionService::confirmPayment`, route role `super_admin,sales_manager`):

| Field | Required | Rule |
| --- | --- | --- |
| `amount` | yes | INR, at most 2 decimals, > 0, as a string or a number: `"50000.00"`, `"50000"` or `50000`. **No commas.** |
| `payment_ref` | yes | 3–96 characters from `A–Z a–z 0–9 _ . : / -`. Use the **gateway payment id** exactly as the dashboard shows it: Razorpay `pay_…` (not the link id `plink_…` or the order id), Cashfree `cf_payment_id`. With the lead, it is the natural key: sending it again with the same amount → `200 {"duplicate": true}`; with a different amount → `409`. |
| `product_code` | no | `edge` / `aspire` (a name such as "Aicountly Edge" is normalised). **Empty → the quote's product (with `quote_ref`), else the lead's `interested_product`.** Commission plans match on this code. |
| `net_amount` | no | The fee **before GST** (taxable value), at most `amount`. When given, seller commission is calculated on it instead of the amount paid (`basis_kind = net_before_gst`). Use it whenever the fee includes GST. |
| `quote_ref` | no | An Engage quote (`QTE-…`, coupons). Commission is then on that quote's net before GST; `net_amount` is ignored. Not used for Academy fees at launch. |
| `paid_at` | no | A date or timestamp, not more than 1 day ahead. Default: now. |
| `currency` | no | Only `INR` is accepted. |
| `notes` | no | Free text, shown in the lead activity. |

An instalment is a separate payment with its own `payment_ref`. Each one gets the next `sequence_no` on the lead.

### Step 5 — What one confirmation does

1. A row in `engage_lead_payments` (status `confirmed`, `product_code`, `sequence_no`, `confirmed_by`). This is
   the revenue record.
2. A lead activity (`payment_confirmed`) and an audit event (`commission.payment_confirmed`, fanned out to
   Console).
3. **Reach**: if the lead came from Reach, an outcome event `payment_confirmed` with `value_inr` is queued, and
   `engage:reach-outcomes-deliver` sends it within about 5 minutes.
4. **Seller commission**: if the lead is **converted** (stage `converted` or sales_status `won`), has a seller who
   is not terminated, and an **active plan's `product_scope` covers the payment's `product_code`**, an entry is
   *accrued*. After the plan's `hold_days`, the hourly `engage:commission-release` approves it, and the weekly
   payout run sends it to Finance. If the lead is not converted yet, the payment is evaluated when it converts
   (move the stage to `converted` in the UI, or `POST /api/v1/leads/{id}/move-stage {"stage":"converted"}`).
   **Each payment is evaluated once.** A payment confirmed while no plan covered its product never accrues later,
   even after a plan is published. So publish the plan (§ 3) before you confirm the first fee.
5. The answer's `accrual.notes` says what happened, for example "No commission plan published for this product;
   nothing accrued."

### Step 6 — Academy completes the admission

Academy staff mark the fee settled in Edge's Fees screen, or confirm the Aspire registration, only after the
payment is confirmed in Engage (plan item L5).

### Refunds

1. Finance refunds the payment in the Razorpay / Cashfree dashboard.
2. A sales manager marks it refunded in Engage. In the UI, click *Refund* on the payment row. Or use the API:

```http
POST /api/v1/lead-payments/{payment_id}/refund
{ "reason": "Admission withdrawn before batch start" }
```

`payment_id` is Engage's payment id (`GET /api/v1/leads/{lead_id}/payments` → `items[].id`), not the gateway's
id. `reason` needs at least 3 characters. Effect: the payment becomes `refunded`, and its `accrued` / `approved`
commission entries are reversed. Entries already `in_payout` / `paid` come back in `needs_clawback` and need a
manual clawback with Finance. Engage has **no partial refund**: a refund marks the whole payment refunded.
Reach is **not** told about a refund (no outcome event).

### Revenue view

```http
GET /api/v1/lead-payments/by-product?category=Academy[&product_code=edge,aspire][&from=2026-10-25][&to=2026-11-30]
```

This needs super_admin | sales_manager. It returns one row per `product_code` (`product_name`, `category`,
`confirmed_count`, `confirmed_amount`, `paying_leads`, `refunded_count`, `refunded_amount`), plus
`total_confirmed_amount`. `from` / `to` filter on `paid_at` (inclusive dates). The endpoint is read-only and has
no UI yet.

---

## 3. Seller commission plan for the courses (owner sets the rate)

**Engage ships with no Academy plan.** The owner decides the rate and publishes the plan. Until then, course
payments are recorded but nothing accrues, and those payments never accrue later (Step 5 above).

1. **Check for a catch-all plan.** `GET /api/v1/commission-plans?status=active`. An active plan with
   `product_scope: ["*"]` already covers `edge` / `aspire`, and it blocks publishing a separate Academy plan
   (`409 … already covers some of these products`). Either narrow that plan to its SaaS codes (new version →
   publish) or let it cover the courses on purpose.
2. **Create the draft.** In the UI, go to Sellers → Commission plans, or use the API (super_admin |
   sales_manager):

```http
POST /api/v1/commission-plans
{
  "plan_code":     "academy-courses",
  "name":          "Aicountly Academy courses",
  "description":   "Seller commission on Edge and Aspire course fees",
  "rules":         { "type": "percent_of_first_payment", "percent": "<owner's rate>" },
  "product_scope": ["edge", "aspire"],
  "hold_days":     30
}
```

   Rule types (`Libraries/Seller/CommissionRules.php`):
   - `percent_of_first_payment` — only the first payment. With instalments, that is the first instalment only.
   - `percent_recurring_months` (`percent`, `months`) — every payment whose sequence ≤ `months`. Use this to pay
     on each instalment.
   - `flat_per_conversion` (`amount`) — paid once, on the first payment.
   - `slab` — tiers by payment amount.

   Set `hold_days` to cover the Academy refund window.
3. **Publish.** `POST /api/v1/commission-plans/{id}/publish` (or *Publish* in the UI). This plan code is now
   active, and any earlier version of the same code is retired.
4. To change the rate later: `POST /api/v1/commission-plans/{id}/new-version` → edit the draft → publish. Entries
   already accrued keep the plan version they were calculated under.

The `academy` (umbrella) code is deliberately left out of the scope. Confirm a payment as `edge` or `aspire` so
it earns commission.

---

## 4. Daily checks (launch week)

- Every successful gateway payment has a matching Engage payment with the same `payment_ref`. Reconcile the
  Razorpay / Cashfree dashboard against `GET /api/v1/lead-payments/by-product?category=Academy&from=…&to=…`.
- Status → Reach outcomes shows no `dead` rows.
- Seller-attributed course leads that were paid have been moved to `converted`.

---

## 5. Known gaps at launch

- Fees and GST are not modelled in Engage. The amount is what was collected. Commission is calculated on that
  amount unless `net_amount` (the fee before GST) is given — give it whenever GST was charged.
- There is no partial refund (see Refunds).
- `PUT /api/v1/leads/{id}` can change `interested_product`, but it does not relink `interested_product_id`.
  Prefer choosing the product when you confirm the payment.
- The GST invoice for an individual student comes from Finance (Finance invoices need a `cmp_id` today; owner
  decision pending).

---

## 6. Week-1 automation design (Engage ⇄ Aicountly Finance ⇄ Academy) — design only

Goal: plan principle 4 with Finance as the money system. Engage asks Finance for the link. Finance tells Engage
when it is paid. Engage confirms the payment automatically and tells Academy.

Finance side (being built on finance branch `claude/academy-fees-gateway`): Razorpay and Cashfree payment links,
gateway webhooks, AIC-S2S inbound routes for Engage, and a signed outbound event to Engage.

### 6.1 Engage → Finance: create, read and cancel a payment link

| Call | Route on finance.aicountly.org |
| --- | --- |
| Create | `POST /api/v1/inbound/engage/payment-links` + `Idempotency-Key` |
| Read | `GET /api/v1/inbound/engage/payment-links/{id}` |
| Cancel | `POST /api/v1/inbound/engage/payment-links/{id}/cancel` |

- **Auth:** AIC-S2S v1, signed with `Libraries/AicS2s/AicS2sSignature::headers()` (client `engage`, key id,
  secret, environment, actor = the staff member). The scheme is the same one Engage already verifies for Manage.
  New env vars: `FINANCE_S2S_KEY_ID`, `FINANCE_S2S_SECRET`, `ENGAGE_S2S_ENVIRONMENT` (existing). Engage already
  has `FINANCE_API_BASE_URL`.
- **Idempotency-Key:** deterministic, `engage-fee-{lead_id}-{fee_link_seq}`, so a retry returns the same link.
- **Body (proposal, to align with Finance):** `lead_code`, `product_code` (`edge|aspire`), `amount` ("50000.00"),
  `currency` "INR", `description`, `payer {name, email, mobile}`, `gateway` (`razorpay|cashfree`, optional),
  `expires_at`, `callback_reference` (`engage:lead:{id}`), and `academy {admission_ref, batch_ref}` when Academy
  supplies them. The amount and GST come from Academy, not from Engage.
- **Engage storage:** a new table `engage_fee_links` (lead_id, finance_link_id, product_code, amount, url,
  status, idempotency_key, created_by, timestamps).
- **UI:** a "Collect fee" action on the lead (sales_manager). It creates the link and drafts the WhatsApp / email
  through the Sales Bot, which keeps its existing approval rule for outbound messages.

### 6.2 Finance → Engage: payment events → automatic confirmation

- **Route:** `POST /api/v1/inbound/finance/payment-events`. It sits outside the JWT group, like
  `inbound/finance/payout-status`.
- **Auth:** **AIC-S2S v1** (`AicS2sFilter` with a `finance` client entry, keys
  `ENGAGE_INBOUND_FINANCE_KEY_IDS` / `ENGAGE_INBOUND_FINANCE_SECRET_<KEYID>`, nonce replay through
  `engage_s2s_nonces`). The signature covers the raw body and is replay-protected, which money-in events need.
  The existing payout-status route uses a static `X-Finance-Key` (`ENGAGE_INBOUND_FINANCE_KEY`, `FinanceInboundFilter`).
  Align both routes on AIC-S2S: accept both on payout-status for one release, then drop the static key. Until
  Finance signs, payment-events must **not** fall back to the static key.
- **Events:** `payment.succeeded`, `payment.refunded`.
- **Body (proposal):** `event_id`, `event`, `occurred_at`, `payment_link_id`, `gateway`, `gateway_payment_id`,
  `refund_id`, `amount` (decimal string), `currency`, `paid_at`, `lead_code` / `callback_reference`,
  `product_code`, `payer {…}`.
- **Processing:**
  1. Verify the signature before parsing.
  2. Insert `event_id` into a new `engage_inbound_finance_events` table (UNIQUE). If it is already there, answer
     `200 {"duplicate": true}`.
  3. Resolve the lead from `engage_fee_links.finance_link_id`, falling back to `callback_reference` / `lead_code`.
  4. `payment.succeeded` → `CommissionService::confirmPayment($leadId, ['amount' => …, 'payment_ref' =>
     gateway_payment_id, 'paid_at' => …, 'product_code' => …, 'notes' => 'Auto: Finance event …'], $systemActor)`.
     **`payment_ref` must use the same format staff use on launch day** (Razorpay `pay_…`, Cashfree
     `cf_payment_id`), so a payment already confirmed by hand comes back `duplicate`. An amount mismatch (`409`)
     is parked for a human; it is not retried. `confirmPayment` currently casts `$staff['id']`, so add a system
     actor (a dedicated `engage_users` row, or allow `confirmed_by` NULL with `confirmed_via = 'finance'`).
  5. `payment.refunded` → find the payment by lead + `payment_ref` → `refundPayment(...)`. Engage has no partial
     refund yet. A partial refund is parked for a human until `engage_lead_payments` gains `refunded_amount`.
  6. Answers: `2xx` after commit (and for events that are recognised but ignored); `4xx` only for a permanent
     refusal; `5xx` to have Finance retry. Never answer `2xx` before the row is committed.

### 6.3 Engage → Academy: `AcademyFulfilmentOutbox`

- **Shape:** modelled on `Services/ReachOutcomeOutbox.php`. A table `engage_academy_fulfilment_outbox`
  (event_id UNIQUE, lead_id, event, JSONB payload, status pending/delivered/retry/dead, attempts,
  next_attempt_at, last_http_status, last_error). A spark command `engage:academy-fulfilment-deliver` runs every
  5 minutes, in batches of 50, with back-off 2, 4, 8 … minutes (at most 6 h), dead after 12 attempts. Events
  queue even while Academy is not configured. Show it on a status page next to Reach outcomes.
- **Transport:** `POST {ACADEMY_API_BASE_URL}/api/v1/inbound/engage/fee-events`, signed with
  `AicS2sSignature::headers('engage', ACADEMY_S2S_KEY_ID, ACADEMY_S2S_SECRET, ENGAGE_S2S_ENVIRONMENT, 'POST', path, '', body)`.
  Academy is idempotent on `event_id`.
- **Events:** `fee.paid` (`eng-fee-paid-{payment_id}`), `fee.refunded` (`eng-fee-refunded-{payment_id}`), and
  later `lead.qualified`.
- **Payload:** event_id, event, occurred_at, lead_code, product_code, amount, currency, payment_ref, gateway,
  finance_payment_link_id, student {name, email, mobile}, seller_code, academy refs. Academy then records an Edge
  payment of kind `provider_verified`, or confirms the Aspire seat.
- **Trigger:** enqueue from `confirmPayment` / `refundPayment` whenever the payment's product category is
  `Academy`. Manual confirmations then reach Academy too, not only the automated ones.

### 6.4 Tests to write with it

- The signature is verified and a replay is refused.
- A duplicate `event_id` is answered `200` with no second payment.
- A manual confirmation followed by the event gives `duplicate`.
- An amount mismatch is parked.
- A refund reverses the commission.
- Outbox retry and dead states.
- The Finance client sends the AIC-S2S headers and the Idempotency-Key.
