# Token conversions — what a model's tokens cost a customer

AICOUNTLY charges a customer's quota in **tokens**, not in an abstract credit. Providers price
models very differently and we do not want a different price per model, so instead the **token
count is weighted**: a call on an expensive model consumes more of the customer's allowance than
the same call on a cheap one.

```
billable tokens = round( (input + output) × multiplier )
```

Engage owns the multiplier. This document is the Engage half; Pulse enforces it and Console owns
the model catalogue.

## 1. Who owns what

| System | Owns |
|---|---|
| **Console** | Which models exist (`ai_models`), which provider each belongs to, whether a module is bound to one, and each model's commercial **tier** (`ai_models.tier`, migration 081) |
| **Engage** | The **multiplier** per model, the **price** of a token, GST and SAC — everything commercial |
| **Pulse** | The balance, the debit and the refusal. It does the arithmetic with the card Engage pushes |
| **Manage** | What the customer sees |

Engage never keeps its own copy of the model list: it reads Console's over
`GET /portal/ai-models` (capability `ai.catalogue`, Console migration 082). Two lists of models
would drift, and the one that drifts is the one that prices things.

## 2. The screen

**Credits → Token conversions** (`/token-conversions`).

- **Models** — every model Console knows, with what it currently counts as and *why*. A model no
  Console module is bound to is shown as **inactive** rather than hidden, so a rate can be set
  before a model goes live.
- **Rates** — the rates themselves, with their lifecycle.
- **Pricing & fallbacks** — the price of a token, GST, SAC, and the tier fallbacks.

View: `super_admin`, `sales_manager`, `seller_manager`. Create / edit / activate / retire and the
pricing settings: `super_admin`, `sales_manager`. The same split as coupons, because this decides
what a customer is charged just as directly.

## 3. How a multiplier is resolved

Most specific first (`App\Services\Tokens\TokenRateCard`):

| | Rule | `source` |
|---|---|---|
| 1 | An active rate for this exact (provider, model) | `model` |
| 2 | An active rate for this provider with no model — its default | `provider` |
| 3 | The fallback for the model's Console tier | `tier:low` \| `tier:medium` \| `tier:high` |
| 4 | The fallback for the unclassified tier | `unclassified` |

Default fallbacks: **low ×1, medium ×10, high ×25**.

Steps 3 and 4 also raise a **warning**, shown on this screen and in Console, naming the model. A
model nobody has priced is never charged silently.

**Why step 4 is neither free nor a refusal.** Charging nothing would make an unclassified model
the cheapest way to use AI, which is exactly backwards. Refusing would take a product offline
because of a missing commercial setting. It is charged at the middle rate and said so, loudly.

## 4. The rules a rate lives by

Same standard as a coupon, enforced by `engage_token_rates_guard` in the database, not only in
the service:

- A rate is created as a **draft**. It prices nothing.
- **Four eyes**: whoever wrote the rate — or edited the draft — cannot activate it. A
  `super_admin` is exempt, as elsewhere in Engage.
- Once out of draft, the commercial columns are **immutable**. A rate that has priced real usage
  is evidence: retire it and add a new one, so what priced a past call stays on record.
- **One live rate per (provider, model)** at a time, enforced by a partial unique index. Activating
  a second answers `409 rate_exists` and names the one in the way.
- A rate that has left draft is never deleted.

Multipliers are stored as integer **basis points of 1×** (`10000` = 1×, `250000` = 25×). Nothing
that decides what a customer is charged is ever a float. The API accepts either a human multiplier
(`"multiplier": 25`) or raw basis points (`"multiplier_bp": 250000`).

## 5. Price of a token

Held in `engage_settings`, changeable without a deploy:

| Key | Seeded | Meaning |
|---|---|---|
| `token_price_paise_per_1000` | `100` | ₹1.00 per 1,000 tokens, **exclusive of GST** |
| `token_gst_rate` | `18` | Charged on the price **after** any discount (CGST s.15(3)(a)) |
| `token_sac` | `997331` | Finance accepts `99` + four digits |
| `token_tier_fallback_bp` | low 10000, medium 100000, high 250000 | ×1 / ×10 / ×25 |
| `token_unclassified_tier` | `medium` | What an unclassified model is charged at |

So **₹1,000 of credit = 1,000,000 tokens**, invoiced as ₹1,000 taxable + ₹180 GST = **₹1,180
collected**.

## 6. Why the token count is flat

The founder's decision is a **flat** count: input and output are added together and weighted once,
rather than weighted separately. Two consequences, both deliberate:

- A call's charge never depends on how its tokens split between input and output, so identical
  total usage always costs the same.
- Per-call charges sum exactly to the charge on the summed tokens, so a bill foots.

The trade is that margin moves with a workload's input/output mix, since providers price output
several times input. That is a pricing decision, not a defect, and the multiplier per model is
where it is absorbed.

## 7. API

All under the staff JWT.

| Route | Role |
|---|---|
| `GET v1/token-rates?status=&provider_code=` | view |
| `GET v1/token-rates/catalogue` | view — every Console model priced, plus the warnings |
| `GET v1/token-rates/pricing` | view |
| `PUT v1/token-rates/pricing` | admin |
| `POST v1/token-rates` | admin — always a draft |
| `PUT v1/token-rates/{id}` | admin — draft only |
| `POST v1/token-rates/{id}/activate` | admin — four eyes |
| `POST v1/token-rates/{id}/retire` `{reason}` | admin |

`GET /catalogue` answers **even when Console cannot be reached**: `catalogue_available` is false
with the reason, and the rates — which are Engage's own data — are returned as usual. Showing an
empty model list would be a lie that invites someone to type them all in again.

## 8. Tables

`engage_token_rates` (migration `2026-11-02-000040_CreateTokenRates`):

| Column | |
|---|---|
| `rate_ref` | `TKR-000012`, from `engage_token_rate_ref_seq` |
| `provider_code` | Console's provider slug |
| `model_key` | Console's model id, or NULL for "every model of this provider" |
| `multiplier_bp` | basis points of 1×, 0 … 10,000,000 |
| `label`, `notes` | |
| `effective_from` / `effective_to` | `effective_to` is closed in SQL on retirement, strictly after `effective_from` |
| `status` | `draft` → `active` → `retired` |
| `created_by`, `edited_by[]`, `approved_by`, `approved_at`, `retired_by`, `retired_at`, `version` | the four-eyes trail |

## 9. Tests

- `tests/Unit/TokenRateCardTest.php` — the arithmetic: resolution order, flat weighting, rounding,
  that charges add up across calls, and that an unknown model is never free.
- `tests/Feature/TokenRateLifecycleTest.php` — the rights, four eyes, immutability, one live rate
  per model, and that the screen survives Console being down.
- `tests/Feature/TokenOrderLifecycleTest.php` — quote, order, price frozen at quote, one open
  order per holder, settlement, and the coupon actually being held and spent (§12.3).
- `tests/Feature/ManageTokenApiTest.php` — **the wire**: the envelope Manage reads, the error
  object, and which key scope may call which route. This is the one that would have caught the
  bug where every route worked and nothing could be bought.
- `tests/e2e/run.php` steps i–n (`tests/e2e/credits.php`) — the whole thing across every
  product, with a real AI Pulse: see `tests/e2e/README.md`.

> Three bugs reached this point past the unit and integration tests, and all three were found by
> the end-to-end run. Pulse's schema splitter cut PL/pgSQL bodies in half, so `php spark migrate`
> never created the wallet tables; `17_token_alerts.sql` had no migration class at all; and these
> routes answered in Engage's internal `{ok:true}` envelope, which Manage's client reads as the
> service being down. None of them is visible from inside one repository.

---

## 10. Token grants and vouchers

Two ways tokens reach a customer's wallet, and one way they are delivered.

### 10.1 A voucher is not a coupon

| | Coupon (`docs/COUPONS.md`) | Token voucher |
|---|---|---|
| What it is | a discount **rule** applied to a price | a **value**: "worth N tokens" |
| Needs a sale | yes — it reduces one | no |
| Has a balance | **no**, by design | **yes** |
| Can go to zero | no (`net_paise >= 100`) | not applicable: nothing is being priced |
| Leftover | cannot exist | `rollback`: `keep_balance` or `no_rollback` |
| Money | reduces an invoice | **no invoice, no GST** — a free supply |

This is why "here is ₹1,000 of credit, with the unused part kept (or forfeited)" is a
voucher and not a 100%-off coupon. A coupon structurally cannot do it: it has nothing to
hold a balance in, and a ₹0 invoice is both meaningless and refused by Finance
(`total_paise > 0`).

A voucher's value may be entered in rupees; it is converted at the token price **on the day
it is created** and that rate is frozen onto the row, so a voucher handed out as "₹1,000 of
credit" stays worth 1,000,000 tokens even if the price changes before anyone redeems it.

`keep_balance` is allowed only on a single-use voucher. On a shared one it would be a single
pot drained first-come-first-served, which is a different product.

### 10.2 The rules

Same standard as a coupon, because a voucher gives away real value:

- created as a **draft**, which grants nothing;
- **four eyes** — whoever created it cannot activate it;
- once active its value and rules are **frozen** by `engage_token_vouchers_guard`: an active
  voucher cannot quietly become worth less than what customers were told;
- the redemption ledger is **append-only**;
- a single-use code is redeemed once, whatever races it (partial unique index + an advisory
  lock on the code).

### 10.3 Delivery

Tokens are **never handed over inside the request that creates them**. A redemption (or a
confirmed purchase) writes a row to `engage_token_grants`, and
`php spark engage:token-grants-push` (every minute) delivers it to Pulse
`POST /v1/inbound/engage/token-grants`, AIC-S2S v1 signed.

Two reasons: a customer's credit must not depend on Pulse being reachable at that instant,
and a push that times out must be safe to repeat. Pulse is idempotent on `grant_ref`, so a
retry credits once and answers `duplicate: true`.

Back-off doubles from a minute and caps at six hours; after 12 attempts the grant is `dead`
and the job reports an error, because a dead grant names a customer who is owed tokens and
nobody is coming unless somebody is told. Nothing is lost — it is waiting, and
`TokenGrantPusher::requeue()` puts it back once the cause is fixed.

### 10.4 The ₹1,000 test, end to end

`server-php/tests/integration/rs1000_credit_e2e.php` runs the whole path against two real
databases — Engage's and Pulse's, as in production — and asserts each step:

```
Rs 1.00 / 1,000 tokens  →  Rs 1,000 = 1,000,000 tokens
staff create the voucher (draft)  →  a second person activates it
an active voucher cannot be re-valued
customer redeems WELCOME1000      →  grant queued, ledger append-only
Pulse credits the wallet          →  a retry does NOT credit twice
a Sonnet 5 call: 1,000 in + 500 out at 10x = 15,000 billable
wallet: 1,000,000 − 15,000 = 985,000, and the ledger nets to the balance
```

It also states the contrast the decision rests on: the same credit **bought** would be
₹1,000 taxable + ₹180 GST = **₹1,180 collected**; as a voucher it is a free supply with no
invoice and no GST.

---

## 11. Telling the customer

A credits system that can refuse an AI call is only fair if the customer hears about it
before the refusal. Nine triggers, all registered as Console templates
(`console-react-app` migration 083).

### 11.1 Who sends what

| | Owner | Why |
|---|---|---|
| `credits.balance_low` · `balance_critical` · `balance_exhausted` · `usage_spike` | **Pulse detects, Engage delivers** | Pulse owns the balance and the burn rate; Engage holds the holder's e-mail (`engage_holders`) and the Console key |
| `credits.voucher_issued` · `voucher_redeemed` · `purchase_receipt` · `purchase_failed` · `plan.changed` | **Engage** | it owns vouchers, orders and licences |

### 11.2 Channels, and what actually works today

One rule: **the cheapest channel that still reaches the person in time to act.**

| Channel | Used for | Live? |
|---|---|---|
| **E-mail** | anything carrying detail or a document | **yes** |
| **WhatsApp** | actionable, read quickly, far cheaper than SMS | no — needs Meta template approval; seeded `draft` |
| **SMS** | only when work has stopped or money moved | no — needs DLT registration; seeded `pending_dlt` |
| In-app | every trigger, always | Pulse's own `buddy_notifications` |

Three separate things block SMS and WhatsApp, and all three are outside this code:

1. **No phone number.** The cross-product contact lookup
   (`my.aicountly POST /api/internal/account/contacts`) returns **e-mail only**, by
   deliberate directory policy. Until it returns a mobile, those sends are skipped for
   want of a recipient.
2. **DLT** for SMS and **Meta approval** for WhatsApp. Console refuses an unapproved
   template with `200 skipped_reason`, not an error, so the code is already correct and
   goes live the day the approvals land.
3. `SMS_SKIP_SEND` and `WHATSAPP_SKIP_SEND` default to `true` in Console.

Escalation is **first-available, not all-channels**: a customer who gets the same message
three ways learns to ignore all three. The list exists so a missing phone number falls
back to e-mail.

### 11.3 Not spamming people

The hard part is not sending a warning; it is not sending it four thousand times.

```
armed  ──crossing detected──▶  queued  ──delivered──▶  sent
  ▲                                                      │
  └──────────────── a top-up re-arms it ─────────────────┘
```

- **Crossing, not level.** "Below 20%" is true for every call after the first.
  `buddy_token_alerts` holds one row per (holder, trigger); a conditional UPSERT means
  two concurrent calls cannot both queue the same alert.
- **A failed delivery is re-armed**, not marked sent: a warning nobody received must not
  be counted as one the customer has had.
- **Idempotency keys** on every send, so a retried run cannot produce a second copy.
- Console adds its own per-recipient rate limit (10/hour) on top.

### 11.4 Runway, not only percent

A percentage needs a denominator, and a pay-as-you-go customer has none — their balance
is whatever they last bought, so they read as 100% full until the moment they are empty.
Days of runway (balance ÷ the last 7 days' average burn) works for both kinds of customer
and is what a person actually wants to know. Percent still applies where a plan allowance
gives a real denominator, and whichever is worse wins.

Defaults: low at 20% or 7 days, critical at 5% or 2 days, spike at 5× the daily average.
All in `buddy_settings`, all changeable without a deploy.

### 11.5 The customer can refuse some of these, and not others

Console holds the message preferences and enforces them at the send gate
(console-react-app `docs/NOTIFICATION_PREFERENCES.md`, migrations 084/085). These triggers are
classified by what they actually are:

| Trigger | Category | Refusable? |
|---|---|---|
| `credits.balance_low`, `credits.balance_critical`, `credits.usage_spike` | `service_updates` | yes |
| `credits.balance_exhausted`, `credits.purchase_failed` | `service_alerts` | **no** |
| `credits.purchase_receipt`, `credits.voucher_issued`, `credits.voucher_redeemed`, `plan.changed` | `transactional` | **no** |

Running out is not refusable because it is the only notice before work starts being turned
away, and a credits system that can refuse work is only fair if the person hears about it.
Running *low* is refusable, because somebody who would rather watch their own balance is
entitled to.

A refusal comes back from Console as `200 {sent:false, skipped_reason:"opted_out"}`, and Pulse
disarms that alert rather than re-queueing it every hour (`TokenAlerts::SETTLED_REFUSALS`) —
re-asking somebody who has said no is the system arguing with them.

### 11.6 Off by default

`token_alerts_enabled` ships as `0`. A customer's first balance warning should be
somebody's decision, not a deploy's side effect.

### 11.7 Jobs

| Job | Where | When |
|---|---|---|
| `pulse:token-alerts` | Pulse | hourly at :29 — works out who should be warned |
| `engage:token-alerts-send` | Engage | every 15 min — collects and delivers |


> **Switching all of this on:** [`CREDITS_GO_LIVE.md`](CREDITS_GO_LIVE.md) has the order, the
> verification for each step, and the blockers that are not code.

---

## 12. Buying credits

What a customer buys is tokens. ₹1 per 1,000 tokens, **exclusive of GST**, so ₹1,000 of credits
collects ₹1,180. Bought tokens go to a separate bucket that does not expire and is spent only
after the plan's own monthly allowance is used up.

```
Manage (the owner)  ──quote──▶  Engage prices it            rate × tokens, coupon, GST on the net
                    ──order──▶  Engage freezes that price   and asks Finance for a payment link
                    ◀──link───  the gateway's hosted page   (no card detail ever reaches us)
                                     ⋮ the customer pays at the gateway
Finance  ──payment.succeeded──▶  Engage: order paid, grant queued, coupon spent — one transaction
Engage   ──token-grants-push──▶  Pulse credits the wallet, once, keyed on the grant reference
```

### 12.1 Nothing is granted by anything but a confirmed payment

`settle()` marks the order paid and writes the grant row in the **same transaction**, because an
order marked paid with no grant is a customer who has been charged and given nothing. It is
idempotent on the payment reference: Finance retrying is correct behaviour, and an error would
make it retry for ever. Pulse is idempotent again on the grant reference, so a redelivered push
credits once.

### 12.2 The price is frozen at the quote

Every money column is fixed when the order is created. A customer who opens a payment link on
Tuesday and pays on Friday gets Tuesday's price — the only defensible answer, and the one the
invoice already states. A paid order cannot be re-priced: a database trigger refuses it.

### 12.3 A coupon on a purchase is a coupon, not a discount field

The order carries `coupon_code`, but the fact that the coupon was **used** lives in
`engage_coupon_redemptions` beside every licence redemption — because that table is what
enforces "once per customer", what makes a campaign run out, and what sales reads to see what a
campaign cost. A token order holds the coupon when the order is created (`reserved`), spends it
when the money arrives (`committed`), and gives it back if the order is cancelled (`released`);
the reservation lapses on its own, so an abandoned checkout costs the customer nothing.
`order_ref` records what it was committed to, since a purchase of tokens is not a licence.

There is a floor under every discount (`PricingService::MIN_NET_PAISE`, ₹1): a coupon can take a
purchase down to ₹1 and no further, because a ₹0 invoice is meaningless and Finance refuses one.
A genuinely free grant is a **voucher** (§10), which is a free supply with no invoice and no GST.

### 12.4 The envelope is the contract

Everything Manage calls answers through `AicS2sResponse` — `{success, data, request_id}` or
`{success:false, error:{code, message, details, retryable}}`. Engage's own internal `{ok:true}`
shape is read by Manage's `S2sClient` as "not the AIC-S2S success envelope" and reported to the
customer as Engage being down, whatever it actually said.

Scopes: the **quote** key (POST only, by `AicS2sConfig::SCOPE_METHODS`) prices, orders, cancels
and redeems; the **read** key serves the purchase history, because reading what somebody has
already bought is a read like any other. Widening the quote scope to allow GET would widen it for
everything the quote key can do.

| Route | Key | What it does |
|---|---|---|
| `POST v1/manage/token-orders/quote` | quote | prices it; creates nothing |
| `POST v1/manage/token-orders` | quote | creates the order and raises the payment link |
| `GET  v1/manage/token-orders` | read | the holder's purchases, newest first |
| `POST v1/manage/token-orders/{ref}/cancel` | quote | only while unpaid |
| `POST v1/manage/token-vouchers/redeem` | quote | a credit code into the wallet |
| `POST v1/inbound/finance/payment-events` | `X-Finance-Key` | the only thing that turns money into tokens |
