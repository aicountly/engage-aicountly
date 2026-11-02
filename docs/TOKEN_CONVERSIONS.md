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
