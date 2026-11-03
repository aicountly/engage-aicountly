# Switching on AI credits: the order, and how to know each step worked

Everything described in [`TOKEN_CONVERSIONS.md`](TOKEN_CONVERSIONS.md) exists in five
repositories — Engage, AI Pulse, Console, Finance and Manage — and each piece is dormant until
configured. That is deliberate: no customer sees anything until somebody decides. But it also
means the order matters, and nobody holds the whole sequence in their head.

This is that sequence. Each step says how to verify it before the next one, because a step that
silently did not happen is found at the worst possible moment — somebody's balance.

**Nothing here is reversible by itself.** Steps 1–6 change nothing a customer can see; step 7 is
the one that starts charging, and step 8 is the one that starts refusing. Stop after 6 for as
long as you like.

---

## 0. Before you start

| You need | Why |
|---|---|
| A PostgreSQL superuser on each database | migrations |
| Console's product keys for Engage and Pulse | `CONSOLE_API_TOKEN`, one each |
| A Razorpay or Cashfree account live in Finance | collecting the money |
| Finance's seller profile and the current FY's invoice series | a GST invoice cannot be raised without them |

Run the whole chain once in sandbox first:

```bash
E2E_MANAGE_DIR=… E2E_MYAIC_DIR=… E2E_PULSE_DIR=… E2E_PG_… \
  php engage-aicountly/server-php/tests/e2e/run.php
```

152 of its checks are the credits path across every product. If it does not pass in sandbox it
will not pass in production.

---

## 1. Migrations, everywhere

```bash
cd engage-aicountly/server-php  && php spark migrate
cd pulse-aicountly/server-php   && php spark migrate
cd console-react-app/server-php && php scripts/migrate.php
cd finance-aicountly/server-php && php bin/migrate.php
cd manage-aicountly/server-php  && php scripts/migrate.php --run
```

**Verify.** Pulse must have all three token tables — this is the step that failed silently once,
because its schema splitter cut PL/pgSQL function bodies in half:

```sql
SELECT count(*) FROM information_schema.tables
 WHERE table_name IN ('buddy_token_wallet','buddy_token_ledger','buddy_token_alerts');  -- 3
```

Console must have the preference tables and every template classified:

```sql
SELECT count(*) FROM notification_preferences;                                    -- 0 rows, table exists
SELECT count(*) FROM product_notification_templates WHERE consent_category = '';  -- 0
```

## 2. Engage → Pulse (the rate card and the grants)

In Pulse's `.env`:

```ini
PULSE_ENVIRONMENT=production            ; or sandbox — WITHOUT IT EVERY API CALL IS 503
PULSE_S2S_ENVIRONMENT=production
PULSE_INBOUND_ENGAGE_KEY_IDS=engage-2026-11
PULSE_INBOUND_ENGAGE_SECRET_ENGAGE_2026_11=<64 hex>
```

In Engage's `.env`, the same key and `PULSE_API_BASE_URL` **without** `/api` (the clients append
it):

```ini
PULSE_API_BASE_URL=https://pulse.aicountly.com
ENGAGE_OUTBOUND_PULSE_KEY_ID=engage-2026-11
ENGAGE_OUTBOUND_PULSE_SECRET=<the same 64 hex>
```

**Verify.** `curl https://pulse.aicountly.com/api/health` → `"database":"connected"`, and an
unsigned `PUT /api/v1/inbound/engage/token-rate-card` → `401 signature_missing`. If it answers
`503 misconfigured`, `PULSE_ENVIRONMENT` is not set.

## 3. The prices (Engage, Token Conversions)

Sales owns these; they are not deploy settings.

1. **Token Conversions → Pricing**: ₹1 per 1,000 tokens, GST 18%, SAC 997331.
2. **Token Conversions → Rates**: a rate per model that is in use, activated by a second person.
   The screen lists every model Console knows and warns about any that is bound to a module and
   has no rate — that warning is the one to clear before step 7.
3. Push it: `php spark engage:token-rates-push`

**Verify.** In Pulse: `SELECT value FROM buddy_settings WHERE key='gateway_token_rate_card'` has
one entry per model Console knows, not just the ones with rates. If the command says *"Console's
model catalogue is unavailable"* it sent **nothing** — on purpose, because an incomplete card
would silently reprice everything down to the fallback. Fix `CONSOLE_API_TOKEN` first.

## 4. Every product has a Basic, and every holder has one

```bash
php spark engage:basic-offers --dry-run      # read it
php spark engage:basic-offers --apply        # creates DRAFTS, publishes nothing
#   → staff fill in each Basic's limits and publish it
php spark engage:provision-basic --pending   # one Basic licence per holder per product
php spark engage:entitlements-push
cd manage-aicountly/server-php && php scripts/plans_sync.php
```

**Verify.** `GET /v1/offers/basic-readiness` lists no product without a published Basic, and in
Manage `SELECT count(*) FROM ghm_plan_default` equals the number of distinct app keys the estate
offers. A holder with no pushed plan must still resolve to Basic:
`GET /api/v1/me/plan?app=<any>` → `source: default`.

## 5. Finance: the payment link and the invoice

In Engage's `.env`:

```ini
FINANCE_API_BASE_URL=https://finance.aicountly.org
ENGAGE_OUTBOUND_FINANCE_S2S_KEY_ID=engage-issue-2026-11
ENGAGE_OUTBOUND_FINANCE_S2S_SECRET=<64 hex>
ENGAGE_INBOUND_FINANCE_KEY=<64 hex>          ; Finance → Engage payment events
```

Finance needs the matching inbound secret with scope `issue`, and its own
`FINANCE_OUTBOUND_ENGAGE_S2S_*` pointing back at Engage.

**Verify.** Buy ₹1 of credits as a real customer in sandbox and watch it all the way through:
an `engage_token_orders` row goes `quoted → awaiting_payment → granted`, a `fin_payment_links`
row exists with `purpose='tokens'`, and an invoice is numbered from the current FY's series.

## 6. Console: messages and preferences

```bash
cd console-react-app/server-php
php scripts/notification_callers.php add engage --name="Engage" \
    --products=engage --capabilities=notify.send,notify.direct,ai.catalogue,notify.preferences
php scripts/notification_callers.php add buddy  --name="AI Pulse" \
    --products=buddy  --capabilities=notify.send,notify.direct
```

In Console's `.env`: `NOTIFY_UNSUBSCRIBE_KEY` (≥32 bytes) and `CONSOLE_PUBLIC_URL`. Without the
first, optional e-mail carries no unsubscribe link and no `List-Unsubscribe` header, which the
large receivers notice.

Then write the thirteen credit templates (Console → Communications → Notifications, products
*AI Pulse* and *Engage*) and activate them. SMS stays `pending_dlt` until the template is
registered on DLT; e-mail works the day it is activated.

**Verify.** Console's Notifications screen shows each trigger's message category and whether a
recipient may refuse it. `credits.balance_exhausted` must read *cannot be switched off*;
`credits.balance_low` must read *a recipient can switch this off*.

## 7. Turn on charging — shadow first

Pulse ships `gateway_wallet_mode = shadow`: the charge is worked out and written to the ledger,
and **nobody is refused**. Leave it there for at least a full billing cycle.

```sql
SELECT * FROM buddy_token_wallet ORDER BY updated_at DESC LIMIT 20;
UPDATE buddy_settings SET value = 'enforce' WHERE key = 'gateway_wallet_mode';  -- when ready
```

Read the ledger before you enforce. If a customer's weekly burn is larger than anything they
could plausibly have bought, the rate card is wrong, not the customer.

## 8. Turn on the warnings

```sql
UPDATE buddy_settings SET value = '1' WHERE key = 'token_alerts_enabled';
```

Cron:

| Job | Where | When |
|---|---|---|
| `pulse:token-alerts` | Pulse | hourly at :29 |
| `engage:token-alerts-send` | Engage | every 15 minutes |
| `engage:token-grants-push` | Engage | every 5 minutes |
| `engage:token-rates-push` | Engage | daily, and after any rate change |
| `engage:provision-basic --pending` | Engage | daily |

`token_alerts_enabled` ships off because a customer's first balance warning should be somebody's
decision, not a deploy's side effect. Enable it **before** step 7's `enforce`, so nobody is
refused without having been warned.

---

## Known blockers, as of 2026-11

These are not bugs; they are things outside the code that have to happen.

1. **SMS and WhatsApp cannot reach a licence holder yet.** Console's contact lookup
   (`POST /api/internal/account/contacts`) returns an e-mail address and nothing else — a
   deliberate directory policy — and `SMS_SKIP_SEND` / `WHATSAPP_SKIP_SEND` default to true.
   Until DLT template registration and Meta's template approval are done, **e-mail is the only
   channel that works.** It is enough: every credit trigger has an e-mail template.
2. **DLT registration** for every SMS template, under the single AICOUNTLY sender id. This is
   why `company_name` is a variable on every customer-facing trigger: the recipient sees
   "AICOUNTLY", so the body is the only place the workspace can be named.
3. **DND / consent on the DLT platform** is a separate check with the operator for promotional
   SMS. Console's preference store is Aicountly's own record, not a substitute for it.
4. **No recipient-facing preferences screen** in any product yet. The API is there
   (console-react-app `docs/NOTIFICATION_PREFERENCES.md`); the screen is each product's own
   work. Until one exists, a customer's only self-serve route is the unsubscribe link in an
   e-mail footer, which does work.

## If something goes wrong

| Symptom | Almost always |
|---|---|
| Every Pulse API call is `503 misconfigured` | `PULSE_ENVIRONMENT` is not set |
| The rate push says the catalogue is unavailable | `CONSOLE_API_TOKEN` in Engage, or the `ai.catalogue` capability |
| Manage says credits are unavailable | the quote key, or Finance not configured — the tab needs both |
| A purchase is stuck at `awaiting_payment` | Finance never sent `payment.succeeded`; check `ENGAGE_INBOUND_FINANCE_KEY` |
| Tokens paid for but not credited | `engage:token-grants-push` is not running; the grant is still `pending` and is safe |
| A customer gets no warnings | `token_alerts_enabled`, or they opted out — Console's send log says `refused:opted_out` |

Nothing in this list loses money or tokens. A grant that was not delivered stays queued; an
order that was not settled stays unpaid; an alert that was not sent stays armed.
