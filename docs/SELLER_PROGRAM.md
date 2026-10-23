# Seller programme (seller.aicountly.com ⇄ Engage)

Resellers, associates and business facilitators sell Aicountly through **seller.aicountly.com**. That portal
(repo `aicountly/seller-aicountly`) is a React SPA plus a thin PHP BFF with **no database**: every seller
account, credential, KYC record, lead attribution and commission entry lives here, in Engage.

```
browser ⇄ seller.aicountly.com BFF ──X-Seller-Portal-Key (+ X-Seller-Session)──▶ engage /api/v1/seller-portal/*
Engage staff (Console SSO, role-gated) ──▶ engage /api/v1/{seller-applications, sellers, seller-kyc, seller-agreements,
                                                          commission-plans, commission-entries, leads/{id}/payments}
reach ──X-Portal-Token──▶ engage /api/v1/internal/reach/leads        (now carries ref + UTM / landing data)
engage ──X-Engage-Token──▶ reach /api/v1/inbound/engage/lead-outcomes (outbox, every 5 min)
engage ──X-Engage-Key──▶ finance /api/v1/inbound/engage/payout-batches  (weekly DRAFT batches, outbox)
finance ──X-Finance-Key──▶ engage /api/v1/inbound/finance/payout-status (approved / on_hold / paid + UTR / failed …)
```

Binding contract: `SELLER-CONTRACTS.md` (the build's shared contract). Wire shapes of the seller-portal API:
seller-aicountly `docs/ENGAGE_SELLER_API.md` — Engage implements it; differences are listed at the end.

## 1. Lifecycle

**Application** (`engage_seller_applications`): `requested → under_review → approved | rejected`

- `POST /api/v1/seller-portal/applications` from the BFF's "request to become a seller" form. No self sign-up.
- Creates the application (`SAP-NNNNNN`) **and** an Engage lead with `source_type = 'seller_request'`
  (lead source `seller_request`). Consent (contact + privacy policy URL + time + visitor IP) and the visitor's
  UTM / ref / landing path are stored with it.
- Same email again while one is open → 200 with that application; email of an existing seller → 200
  `{application_code: null, status: "received"}`. The BFF shows "submitted" in every case (no enumeration).
- Honeypot fields (`website`, `hp`, `honeypot`, `company_website`) → answered like success, stored nowhere.
- Rate limit: 5 requests per visitor IP per hour (`X-Seller-Client-IP`, trusted only behind the portal key).
- Staff (Sellers → Applications): **Review** (→ under_review), **Approve**, **Reject** (reason required).

**Seller** (`engage_sellers`): `onboarding → kyc_pending → active → suspended ⇄ active → terminated`

| Step | What happens |
|---|---|
| Approve | Seller `SLR-NNNNNN` created (sequence), linked to the application's lead, referral code = seller code. One-time **activation link** (72 h, single use, sha256 stored) `https://seller.aicountly.com/activate?token=…` is emailed when a seller mailer is configured; otherwise it is shown **once** to the approving staff member, with "No email was sent". |
| Activation | Seller sets a password (`POST auth/activation`); status stays `onboarding`. |
| Agreement | Seller signs in and accepts the **current** agreement version (`POST agreement/acceptances`, timestamp + IP + UA recorded) → `kyc_pending`. |
| KYC verified | A reviewer verifies → `active`. |
| Suspend | Reason required; all sessions and one-time links revoked. The seller may sign in again to a **read-only** portal; every write answers 403 `seller_suspended`. |
| Reactivate | Back to `active` / `kyc_pending` / `onboarding`, whichever its agreement + KYC allow. |
| Terminate | Reason required; sessions and links revoked, referral code disabled, sign-in refused (`seller_terminated`). No new commission accrues. |
| Resend activation / Reset password / Unlock | Staff actions on the seller page. Links are emailed or shown once. Reset also ends every session. |

**Agreement versions** (`engage_seller_agreement_versions`, Sellers → Agreement): plain text, `draft → current →
retired`, exactly one current. Publishing a new version makes `acceptance_required` true for every seller; until
they accept, only the agreement, profile and password can change (writes answer 403 `agreement_required`).
Their status is not changed. With no agreement published, approved sellers cannot finish activation (the
approval answer warns about this).

## 2. Authentication

- **Login:** email + password (argon2id, bcrypt fallback). Unknown email / wrong password / not activated →
  401 `invalid_credentials` (one generic message). **Lockout:** 5 failures → locked 15 min (423
  `account_locked`, only disclosed with the correct password). Per-IP throttle 20/min.
- **Email OTP (optional):** setting `seller_login_otp_enabled` (Settings) **and** a configured seller mailer →
  login answers `{otp_required: true, challenge_token, challenge_expires_at, email_masked}`; 6-digit code, 10 min,
  5 attempts. If the code cannot be sent the login fails closed (503 `otp_unavailable`).
- **Sessions:** opaque 32-byte token (hex) in header `X-Seller-Session`; only sha256 stored in
  `engage_seller_sessions`; 8 h idle / 7 d absolute; revoked on logout, suspend, terminate, password change
  (`POST me/password` revokes all and returns a fresh `session_token`) and password reset.
- **Password reset:** `POST auth/password-reset/request` → 202 `{accepted: true}` for any email; a link
  (60 min, single use) `https://seller.aicountly.com/reset-password?token=…` is emailed. A seller still onboarding
  without a password gets a fresh activation link instead. **No mailer configured, or the send fails → 503
  `mailer_unavailable`** — never "sent". Staff-issued reset links last 24 h.
- **Password rules:** 10–128 characters, at least one letter and one number, not the email address
  (same as partner.aicountly.com).
- **Idempotency:** `Idempotency-Key` on seller-portal writes (applications, profile, agreement, KYC, documents,
  leads) is stored 24 h per seller (or visitor IP) + route; a retry replays the stored answer
  (`Idempotent-Replayed: true`); same key with a different body → 409 `conflict`. Login / activation / reset
  answers are never stored (they carry tokens).

## 3. KYC

Required before any payout; sign-in and lead registration work meanwhile.

- `PUT kyc` saves a **draft**: legal name, entity type (individual, proprietorship, partnership, llp, company), PAN,
  optional GSTIN, address, bank (holder, account number, IFSC). Absent `pan` / `gstin` / `bank.account_number` keep
  what is on file; `gstin: null` = not GST-registered.
- Validation: PAN format and 4th character vs entity type (P for individual / proprietorship, F for partnership /
  LLP, C for company); GSTIN format, state code (01–38, 97, 99), check character, and characters 3–12 = PAN;
  IFSC `^[A-Z]{4}0[A-Z0-9]{6}$`; account 9–18 digits; PIN `^[1-9]\d{5}$`.
- Documents (`POST kyc/documents`, `DELETE kyc/documents/{DOC-n}`): pan_card, cancelled_cheque, address_proof,
  gst_certificate (required when a GSTIN is on file). ≤ 5 MB, PDF / JPEG / PNG by content (finfo), re-upload
  replaces the previous one of that type.
- `POST kyc/submission {declaration: true}` → `kyc_submitted` (422 `kyc_incomplete` with the missing fields /
  documents). Editable only while `not_started` or `kyc_rejected` (otherwise 409 `kyc_locked`).
- **Encryption at rest:** PAN, GSTIN and account number are encrypted with libsodium secretbox under
  `ENGAGE_DATA_KEY`; only the last four characters are kept in clear and every API shows masked values
  (`XXXXXX234F`). Document files are encrypted with the same key and written under `ENGAGE_SELLER_DOCS_PATH`
  (random names, mode 0600; set it **outside the web root**; the default `writable/seller-kyc` is denied by
  `.htaccess` and preserved by the deploy rsync filter). No key → KYC answers 503 (fail closed).
  Back the key up: losing it makes every KYC record unreadable. Rotation: new key in `ENGAGE_DATA_KEY`, old one
  in `ENGAGE_DATA_KEY_PREVIOUS`.
- **Review** (Sellers → KYC review / seller page; kyc_reviewer, seller_manager, super_admin): view documents,
  **Verify** (→ seller `active`), **Reject** (reason shown to the seller, who can fix and resubmit),
  **Show full numbers** (audited `seller.kyc_revealed`).

## 4. Leads and attribution

- **Registration** (`POST seller-portal/leads`, `consent_confirmed: true`): creates a lead `source_type = 'seller'`
  with `seller_id`, `seller_attribution = 'registration'` and a protection window of
  `seller_lead_protection_days` (setting, default **90**). First valid registration wins. Duplicates are found by
  email (case-insensitive) then mobile (last 10 digits):
  - already registered by this seller → 409 `duplicate_lead` ("You registered this lead on …; protected until …");
  - protected for another seller, an existing customer (converted / won) or a lead already in the pipeline
    (created or worked on within the window) → 409 `lead_protected` "This contact is already registered with
    Aicountly." (never says who holds it);
  - an older, unprotected, not-converted lead is **claimed** (history and owner kept).
  Every attempt is logged in `engage_seller_lead_registrations`.
- **Referral links:** `https://www.aicountly.com/?ref=SLR-000123`, `https://my.aicountly.com/?ref=…`. Reach carries
  `ref` (top level or `reach_meta.ref`) into Engage; the lead is attributed (`seller_attribution = 'referral'`,
  same window) to an active / kyc_pending seller unless it is closed or protected for another seller.
- Staff can change attribution on the lead page (`POST v1/leads/{id}/seller`, reason required, audited).
- Seller-visible statuses: registered, contacted, qualified, demo, proposal_sent, converted, lost, expired
  (protection ended before conversion).

### Reach intake (fixed)

`POST /api/v1/internal/reach/leads` now de-duplicates by email (case-insensitive), then mobile; a repeat push
**never changes stage, sales_status or owner** (it used to reset converted leads to new/open), only fills empty
contact fields, records the touch (first / last UTM, landing URL, referrer, ref) in `engage_leads.attribution` and an
activity. Answer: 201 `{result: "created", duplicate: false}` or 200 `{result: "updated", duplicate: true,
matched_on: "email"|"mobile"}`, plus `seller_attribution` and `lead.lead_code` as before.

## 5. Commissions (bookkeeping only)

- **Plans** (`engage_commission_plans`, Sellers → Commission plans; write: super_admin | sales_manager):
  `draft → active → retired`, versioned per `plan_code` (editing an active plan = **New version**; publishing it
  retires the previous version). Active plans of different codes may not overlap in product scope (`["*"]` or
  product codes), so exactly one plan applies to a payment. **None is shipped** — nothing accrues until an admin
  publishes one, and the portal says "No commission plan published yet". Engage never suggests a rate.
- **Rules** (money "1234.50", percent "7.5"):
  `{"type":"percent_of_first_payment","percent":"10"}`,
  `{"type":"percent_recurring_months","percent":"5","months":12}`,
  `{"type":"flat_per_conversion","amount":"2500.00"}`,
  `{"type":"slab","applies_to":"first_payment"|"recurring_months","months":12,"slabs":[{"from":"0.00","to":"50000.00","percent":"8"},{"from":"50000.00","to":null,"amount":"6000.00"}]}`.
  `hold_days` (default from setting `commission_default_hold_days` = 15) is the refund / clawback window.
- **Payment confirmed** (lead page → "Seller & payments"; super_admin | sales_manager): `POST v1/leads/{id}/payments
  {amount, payment_ref, paid_at?, product_code?, notes?}` — idempotent on (lead, payment_ref).
- **Accrual** (contract: lead converted + payment confirmed): each confirmed payment is evaluated **once** — when it is
  confirmed on a converted lead, or when its lead converts — against the plan active at that moment for the payment's
  product, for the lead's seller (not terminated). Result: one `engage_commission_entries` row per payment
  (`accrued`, `hold_until = now + hold_days`) linked to lead, payment, product, basis amount, rate, plan id + version,
  or a note on the payment explaining why nothing accrued (no plan, no seller, rule does not pay). Publishing a plan
  later does not accrue retroactively.
- `accrued → approved` by `php spark engage:commission-release` (hourly) once `hold_until` has passed.
- **Reverse** (reason) applies to accrued / approved entries; **Refund** on a payment reverses its entries.
  `in_payout` entries belong to a payout with Finance (409 naming the payout; once Finance reports it failed /
  reversed / cancelled the entry is approved again and can be reversed). `paid` entries would need a clawback, which
  Engage does not do (409; the refund lists them in `needs_clawback`) — handle it with Finance.
- `engage_credit_ledger` is **not** used for commission: it is a generic credit / points book without plan versions,
  hold windows or payout state. Commission entries are their own ledger; the credit ledger is unchanged.

## 6. Weekly payouts (drafts for finance.aicountly.org)

Product decision: Engage compiles weekly payouts **automatically as drafts** and hands them to
**finance.aicountly.org**, where a person reviews and finalises them (TDS under 194H, GST, approval, bank file).
There is no payment-provider API. **Engage never marks a payout paid on its own** — only when Finance reports
`paid` with a UTR.

### Run (`php spark engage:seller-payout-run [--week=YYYY-Www] [--dry-run]`)

- Week = ISO week, Monday 00:00 – Sunday 23:59 IST. The scheduled run (Monday 10:00 IST) pays the week that just
  ended; `--week=2026-W42` names one explicitly. `--dry-run` prints what would be included and writes nothing
  (default week then: the current one, i.e. next Monday's run).
- Collects commission entries `approved` **before the week ended** (cutoff Monday 00:00 IST) and not in a payout, per
  seller that is `active` **and** `kyc_verified` (verified KYC row with PAN and bank account). Suspended / terminated /
  KYC-pending sellers are held: their entries stay `approved` and are picked up by the first run after they qualify.
- Minimum per seller: setting `seller_payout_min_threshold` (Settings, default `"500.00"`); gross below it is
  skipped that week and **rolls over** (exactly the minimum is paid).
- Per paid seller: one `engage_seller_payouts` row `PO-YYYY-WW-NNNNNN` (= Finance `item_ref`; the number is the
  seller code's), entries → `in_payout` with `payout_item_ref`. One batch `PB-YYYY-WW` per run.
- **Idempotent per ISO week**: a week that already has a run (`empty`, `queued`, `sent`, `delivery_failed`,
  `cancelled`) is reported, not rebuilt; only a `failed` run (nothing was written) can be run again. A week earlier
  than one that already ran is refused (409). Concurrency: an advisory lock + `FOR UPDATE` on the entries.
- **Fail closed**: Finance unconfigured (`FINANCE_API_BASE_URL` / `FINANCE_OUTBOUND_KEY`) or no `ENGAGE_DATA_KEY` →
  the run is recorded `failed` with the reason, nothing is reserved, the command exits non-zero; the commission
  simply rolls into a later run.
- Run statuses: `running → empty | queued → sent | delivery_failed`, `failed`, `cancelled`.
  Payout statuses: `queued` (built, Finance has not accepted it yet) → `draft_sent` → `on_hold` / `approved` → `paid`,
  or `failed` / `reversed` / `cancelled`. (`queued` is an Engage addition: nothing says "sent" before Finance answered 2xx.)

### Engage → Finance (outbox `engage_finance_payout_outbox`)

```
POST {FINANCE_API_BASE_URL}/v1/inbound/engage/payout-batches
X-Engage-Key: {FINANCE_OUTBOUND_KEY}            (Finance: FINANCE_INBOUND_ENGAGE_KEY)
Idempotency-Key: PB-2026-42
{"batch_ref":"PB-2026-42","period_start":"2026-10-12","period_end":"2026-10-18","currency":"INR",
 "items":[{"item_ref":"PO-2026-42-000123","seller_code":"SLR-000123",
           "payee":{"legal_name":"…","pan":"ABCPK1234F","gstin":null,"entity_type":"individual",
                    "bank":{"account_holder":"…","account_number":"123456789012","ifsc":"HDFC0001234"}},
           "gross_amount":"12500.00","entries":[501,502]}]}
→ 201 {"batch_id":"…","status":"received"}      (idempotent on batch_ref)
```

- Payee PAN / GSTIN / account number are **decrypted from KYC only while the request body is built**; the body is
  never stored (the outbox holds only the batch ref and delivery state).
- Sent right after the run, retried by `php spark engage:finance-payouts-deliver` (every 5 min): 2xx **with Finance's
  JSON `batch_id`** (201, or 200 `duplicate: true` for a replay) → delivered (payouts `queued → draft_sent`, run `sent`,
  Finance `batch_id` kept); 400 / 409 / 422, or a 2xx that is not Finance's receipt (e.g. `FINANCE_API_BASE_URL`
  without `/api`, answered by Finance's web app with `200 index.html`) → **rejected** (not retried);
  anything else → retry 2, 4, 8 … min (max 6 h), **dead** after 12 attempts. Rejected / dead → run
  `delivery_failed`, shown on Sellers → Payouts with the error.
- **Re-send to Finance** (super_admin): a rejected / dead / pending batch gets a fresh attempt budget and is sent now.
  **Cancel batch** (super_admin, reason): only while Finance **cannot** hold it — nothing was ever attempted, or every
  attempt failed before reaching Finance (DNS / connect / TLS) or got a non-receipt (SPA `200 index.html`), or Finance
  explicitly rejected it (400 / 422) — payouts `cancelled`, entries back to `approved`. After a 409 (Finance knows the
  batch_ref), a lost / unknown answer (5xx, timeout) or a dead outbox the cancel is refused (409; the UI disables the
  button and shows why; sticky `engage_finance_payout_outbox.finance_may_hold`, migration 000095): Re-send to find out,
  then cancel it in Finance, whose `failed` status per item returns the entries to `approved`.

### Finance → Engage (`POST /api/v1/inbound/finance/payout-status`, `X-Finance-Key`)

`{batch_ref, item_ref, status, tds_amount, gst_amount, net_amount, utr|null, paid_at|null, reason|null, tds_section?}`,
key `ENGAGE_INBOUND_FINANCE_KEY` (≥ 32, `hash_equals`; unset → 503 `finance_key_missing`, wrong → 401
`finance_key_invalid`). Answers 200 `{item_ref, status, result: "updated"|"unchanged", entries_paid, entries_returned}`;
422 `validation_failed` (unknown status, bad amount, **`paid` without `utr`**, batch_ref not the item's);
404 unknown item_ref; 409 transition not allowed.

| Payout status | Finance may send | Effect on entries |
|---|---|---|
| `queued` / `draft_sent` / `on_hold` / `approved` | approved, on_hold, paid, failed, reversed, cancelled | — |
| `paid` (UTR required) | reversed (bank return) | `in_payout → paid` (`paid_at`) |
| `failed` / `reversed` / `cancelled` | — (final) | back to `approved`, out of the payout → rolled into a later run |

Idempotent: a repeat of any status already applied (same fields) answers `unchanged`; `paid` again with a different
UTR → 409. Null amounts keep what Finance sent before. Every status is kept in `engage_seller_payout_events`
(timeline on the staff payout page). A status for a batch whose 201 never reached Engage also marks it delivered.
`cancelled` is an Engage addition to the contract's list (Finance cancelling a draft).

### Seller portal

- `GET payouts?status=&page=&limit=` → items `{payout_ref, batch_ref, period_start, period_end, gross_amount,
  tds_amount, gst_amount, net_amount, utr, status, paid_at, reason, entries_count}` — status `processing`
  (queued / draft_sent) | approved | on_hold | paid | failed | reversed | cancelled. TDS / GST / net are `null` until
  Finance reports them; `utr` / `paid_at` only once paid.
- `GET payouts/{payout_ref}` → `{payout: {…item, currency, tds_section: "194H", entries: [{id: "CE-n", lead_code,
  product, amount, accrued_at}], payee: {legal_name, seller_code, entity_type, pan_masked, gstin_masked},
  issuer: {name, address, gstin} | null, generated_at}}`; another seller's ref → 404.
- `GET payouts/{payout_ref}/statement` → `text/html` statement (period, entries, gross, TDS, GST, net, UTR, status;
  "Pending" where Finance has not reported; escaped, no scripts). Issuer block from `PAYOUT_ISSUER_*` env.
- Dashboard: `next_payout_date` = next Monday (run day) when the seller is active and KYC verified, else `null`.

### Staff (Sellers → Payouts)

View: super_admin, sales_manager. Manual run / re-send / cancel: super_admin.
`GET v1/payout-runs` (runs, Finance configuration, schedule), `GET v1/payout-runs/preview?week=` (dry run — default:
what next Monday's run would include so far), `POST v1/payout-runs {week?}`, `GET v1/payout-runs/{id}`,
`POST v1/payout-runs/{id}/resend`, `POST v1/payout-runs/{id}/cancel {reason}`,
`GET v1/seller-payouts?seller_id=&status=&week=&run_id=`, `GET v1/seller-payouts/{id}` (entries + status timeline).
Code: `app/Services/Payouts/` (`SellerPayoutService` implements the `SellerPayoutGateway` seam, `FinancePayoutOutbox`),
`app/Libraries/Seller/PayoutWeek.php`, commands `SellerPayoutRun` / `FinancePayoutsDeliver`.

## 7. Reach outcomes

On a stage change to qualified, demo (`demo_required`), proposal_sent, converted or lost, and on payment confirmed
(stage `payment_confirmed`, `value_inr` = the payment), Engage queues
`{event_id, lead_code, reach_lead_id, email_sha256, stage, sales_status, product, value_inr, occurred_at, seller_code,
campaign_code, source_portal: "engage.aicountly.org"}` in `engage_reach_outcome_outbox` — only for leads that came from
Reach (`reach_lead_id`, `source_type reach_campaign` or source portal reach.aicountly.org). Converted events carry the
sum of confirmed payments (or null). `php spark engage:reach-outcomes-deliver` (every 5 min) POSTs batches of 50 to
`{REACH_API_BASE_URL}/v1/inbound/engage/lead-outcomes` with `X-Engage-Token: {REACH_OUTCOME_TOKEN}` and
`Idempotency-Key`; per-event results `stored | duplicate` → delivered, `rejected` → kept, not retried; a 2xx without
results = all delivered; 400/422 without results = all rejected; anything else → retry with back-off (2, 4, 8 … min,
max 6 h), dead after 12 attempts. Events queue even while Reach is unconfigured. Status: Integrations → Reach outcomes,
`GET v1/status/reach-outcomes`. Hooked into staff move-stage, lead PUT and the Sales Bot's stage moves.

## 8. Roles (Console → Roles & Access, app `engage`)

| Role | Level | Seller programme rights |
|---|---|---|
| super_admin | 100 | everything (incl. Partner Master, settings, manual payout run, re-send / cancel a payout batch) |
| sales_manager | 70 | view sellers; commission plans (create / publish / retire); payment confirmed; reverse / refund; lead attribution; view payouts (runs, preview, per seller) |
| seller_manager | 60 | view; approve / reject applications; suspend / reactivate / terminate; resend activation; reset password; unlock; agreement versions; KYC review; lead attribution |
| kyc_reviewer | 40 | view; KYC review (documents, verify, reject, reveal) |
| member | 10 | nothing in the seller programme; the rest of Engage as before |

`ConsoleRoleSync::CATALOG` mirrors these; migration `2026-10-17-000090` adds the rows (and `engage_roles.level`).
**Partner Master is now super_admin-only** (it was open to every signed-in role). Staff seller actions fan out to
Console's central audit (`seller.*`, `commission.*`).

## 9. Seller-portal API (`/api/v1/seller-portal`, header `X-Seller-Portal-Key` always)

Public: `GET health`, `POST applications`, `POST auth/login`, `POST auth/login/otp`, `POST auth/logout`,
`POST auth/activation/inspect`, `POST auth/activation`, `POST auth/password-reset/request`,
`POST auth/password-reset/inspect`, `POST auth/password-reset`.
With `X-Seller-Session`: `GET|PATCH me`, `POST me/password`, `GET dashboard`, `GET agreement`,
`POST agreement/acceptances`, `GET|PUT kyc`, `POST kyc/submission`, `POST kyc/documents`, `DELETE kyc/documents/{id}`,
`GET leads`, `POST leads`, `GET commissions`, `GET payouts`, `GET payouts/{ref}`, `GET payouts/{ref}/statement`;
Engage extras: `GET referral`, `GET kyc/documents/{id}` (own file), `GET leads/{lead_code}`.
Errors: `{ok: false, error, code, details?}`; codes per ENGAGE_SELLER_API.md (`portal_key_missing` 503 /
`portal_key_invalid` 401, `session_invalid`, `invalid_credentials`, `account_locked`, `seller_suspended`,
`seller_terminated`, `agreement_required`, `agreement_version_mismatch`, `kyc_locked`, `kyc_incomplete`,
`duplicate_lead`, `lead_protected`, `token_invalid`, `weak_password`, `invalid_otp`, `challenge_expired`,
`file_too_large`, `unsupported_type`, `document_limit`, `validation_failed`, `conflict`, `rate_limited`,
`mailer_unavailable`, `otp_unavailable`, `kyc_unavailable`).

### Differences from seller-aicountly docs/ENGAGE_SELLER_API.md

- Seller-request leads use `source_type = 'seller_request'` (the contract) — the doc says `seller_application`.
- Password-reset request with **no mailer configured** answers 503 `mailer_unavailable` for every email (the doc allows a
  5xx only on a real send failure); nothing could be sent, so 202 would be untrue.
- `manager` is always `null` (no account-manager assignment yet); `bank.bank_name` is always `null` (no IFSC lookup).
- Document `status` is `uploaded` until review, then `accepted` (KYC verified) or `rejected` (KYC rejected); there is no
  per-document review.
- Lead-registration duplicate detection also matches open leads from other sources inside the window
  (`lead_protected`), and claims older unprotected ones, as described in § 4.
- Payout `status` can also be `cancelled` (Finance cancelled the draft, or staff cancelled a batch Finance never
  received); the BFF shows unknown statuses by name. The statement is `text/html` (the doc prefers PDF; HTML is allowed).
- Payout detail adds `currency`; `issuer` is `null` unless `PAYOUT_ISSUER_NAME` is set.

## 10. Configuration

| Env | Purpose |
|---|---|
| `SELLER_PORTAL_KEY` | ≥ 32 chars, same value in the seller BFF. Unset → 503 on every seller-portal route. |
| `ENGAGE_DATA_KEY` (`_PREVIOUS`) | 32-byte key (64 hex / base64) for KYC encryption. Unset → KYC 503. |
| `ENGAGE_SELLER_DOCS_PATH` | Directory for encrypted KYC files, outside the web root. |
| `SELLER_PORTAL_BASE_URL` | Base of activation / reset links (default https://seller.aicountly.com). |
| `SELLER_REFERRAL_WEBSITE_URL`, `SELLER_REFERRAL_APP_URL` | Referral link bases. |
| `ENGAGE_SELLER_MAILER` | `console_providers` (default; empty and `console` mean the same) \| `smtp` \| `off` \| `console_notify` (deprecated opt-in). |
| `CONSOLE_API_URL` (or `CONSOLE_API_BASE_URL`), `CONSOLE_API_TOKEN` | Console email providers for `app_code=engage` (capability `email.credentials`). |
| `EMAIL_PROVIDER_CACHE_DIR` | Encrypted provider cache (default `writable/cache/email-providers`). |
| `ENGAGE_SMTP_HOST/_PORT/_USER/_PASS/_CRYPTO`, `ENGAGE_MAIL_FROM`, `ENGAGE_MAIL_FROM_NAME` (default "Aicountly Sellers"), `ENGAGE_MAIL_REPLY_TO` | SMTP mailer, and the last resort when Console gives no provider. From-name / reply-to also apply to Console providers. |
| `ENGAGE_SELLER_NOTIFY_PRODUCT` | Deprecated `console_notify` mode only (default `engage`). |
| `REACH_API_BASE_URL`, `REACH_OUTCOME_TOKEN` | Reach outcomes (token = Reach's `REACH_INBOUND_ENGAGE_TOKEN`, ≥ 32). |
| `FINANCE_API_BASE_URL`, `FINANCE_OUTBOUND_KEY` | Payout batches to Finance (`https://finance.aicountly.org/api`; key = Finance's `FINANCE_INBOUND_ENGAGE_KEY`, ≥ 32). Unset → runs fail closed. |
| `ENGAGE_INBOUND_FINANCE_KEY` | Finance → Engage payout status (`X-Finance-Key`, ≥ 32, same value in Finance). Unset → 503. |
| `PAYOUT_ISSUER_NAME`, `_ADDRESS`, `_GSTIN` | Optional issuer block on payout statements. |

Settings (Settings screen, `engage_settings`): `seller_login_otp_enabled` (false), `seller_lead_protection_days` (90),
`commission_default_hold_days` (15), `seller_payout_min_threshold` ("500.00", minimum weekly payout; below rolls over).

**Seller mail through Console's email providers** (`Services/Seller/SellerMailer.php`, `Libraries/Email/*`, copied from
Reach): Engage asks Console which providers to use —
`GET {CONSOLE_API_URL}/portal/email/providers?app_code=engage`, `Authorization: Bearer {CONSOLE_API_TOKEN}` (the caller
already holds `email.credentials`, Console migration 071) → `data {ttl_seconds, providers: [{code, type: http_api|smtp,
priority, config, secret}]}`; the first is the primary. Engage composes the mail itself (subject and text in
`SellerMailer::compose`) and sends it with Console's transports (`Libraries/EmailTransport/*`, Console's files copied
verbatim — re-copy, never edit).

- Order: primary, then each fallback, until one **accepts**; a staff "resend activation" tries the fallbacks first.
  `sent` is true only on a provider's acceptance; `engage_seller_tokens.delivery` records `email:console_providers`.
- One `send()` sends at most one copy. Every request issues a new token, so a retried sign-in code is a new code, as
  before.
- Cache: the list is cached encrypted (AES-256-GCM, key derived from `CONSOLE_API_TOKEN`, file mode 0600) for
  `ttl_seconds`; while Console is unreachable a stale list is used for up to 15 minutes, with a 60-second back-off;
  a 401 / 403 deletes the cache.
- Last resort: when Console gives no provider (not configured, key refused, unreachable with no usable cache, or an
  empty list) `ENGAGE_SMTP_*` is used if set; otherwise nothing is sent and links are shown once to staff. When
  Console's providers were tried and none accepted, nothing else is tried.
- Secrets are never logged or returned: `/health` (`seller_mailer`) shows provider codes only, e.g.
  "ok (Console providers: email via itwalk_api, fallback aicountly_smtp)".

The old Console **notify** mode (`ENGAGE_SELLER_MAILER=console_notify`) is deprecated: Engage's Console caller has no
`notify.send` / `notify.direct` and Console delivers notify to staff only, so it cannot reach sellers.

## 11. Cron

```
# commission hold release (hourly)
7 * * * * cd /path/to/api && php spark engage:commission-release >> writable/logs/commission_release.log 2>&1
# Reach outcome delivery (every 5 minutes, offset from the Console audit job)
1,6,11,16,21,26,31,36,41,46,51,56 * * * * cd /path/to/api && php spark engage:reach-outcomes-deliver >> writable/logs/reach_outcomes.log 2>&1
# seller sessions / one-time tokens / idempotency records cleanup (daily)
17 3 * * * cd /path/to/api && php spark engage:seller-cleanup >> writable/logs/seller_cleanup.log 2>&1
# weekly seller payout run — Monday 10:00 IST (= 04:30 UTC; with CRON_TZ=Asia/Kolkata write "0 10 * * 1")
30 4 * * 1 cd /path/to/api && php spark engage:seller-payout-run >> writable/logs/seller_payout_run.log 2>&1
# payout batches to Finance — retries (every 5 minutes, offset from the other outboxes)
3,8,13,18,23,28,33,38,43,48,53,58 * * * * cd /path/to/api && php spark engage:finance-payouts-deliver >> writable/logs/finance_payouts.log 2>&1
```

Each reports to Console's Cron Job Monitor (`engage.commission_release`, `engage.reach_outcomes_deliver`,
`engage.seller_cleanup`, `engage.seller_payout_run`, `engage.finance_payouts_deliver`) when `CONSOLE_CRON_MONITOR_KEY` is set; register those monitors in Console.

## 12. Schema

| Migration | Tables / changes |
|---|---|
| `2026-10-17-000090_AddSellerProgramRoles` | roles sales_manager, seller_manager, kyc_reviewer; `engage_roles.level` |
| `2026-10-17-000091_CreateSellerDomain` | `engage_seller_applications`, `engage_sellers`, `engage_seller_sessions`, `engage_seller_tokens`, `engage_seller_agreement_versions`, `engage_seller_agreement_acceptances`, `engage_seller_kyc`, `engage_seller_kyc_documents`, `engage_seller_referral_codes`, `engage_seller_lead_registrations`, `engage_idempotency_keys`; `engage_leads` + seller_id, seller_attribution, seller_attributed_at, seller_protection_expires_at, reach_lead_id, mobile_normalized (backfilled), attribution, converted_at; lead sources `seller_request`, `seller`; settings above |
| `2026-10-17-000092_CreateCommissionTables` | `engage_commission_plans`, `engage_lead_payments`, `engage_commission_entries` |
| `2026-10-17-000093_CreateReachOutcomeOutbox` | `engage_reach_outcome_outbox` |
| `2026-10-18-000094_CreateSellerPayouts` | `engage_payout_runs`, `engage_seller_payouts`, `engage_seller_payout_events`, `engage_finance_payout_outbox`; index on `engage_commission_entries.payout_item_ref` |
| `2026-10-18-000095_AddFinanceMayHoldToPayoutOutbox` | `engage_finance_payout_outbox.finance_may_hold` (sticky; existing rows back-filled conservatively) — blocks Engage-side "Cancel batch" |

## 13. Operator steps after deploy

1. `php spark migrate` (the post-deploy hook does it).
2. In `api/.env`: `SELLER_PORTAL_KEY` (same as the BFF), `ENGAGE_DATA_KEY` (generate and **back up**),
   `ENGAGE_SELLER_DOCS_PATH` (create the directory outside `public_html`, writable by PHP only),
   `REACH_API_BASE_URL` + `REACH_OUTCOME_TOKEN` (same as Reach's `REACH_INBOUND_ENGAGE_TOKEN`), and a seller mailer
   (default `console_providers` with Console's email providers for `engage`, § 10; or `ENGAGE_SELLER_MAILER=smtp` + `ENGAGE_SMTP_*`). `php check-env.php`.
3. Add the five cron lines; register the Console cron monitors.
4. In Console → Roles & Access assign sales_manager / seller_manager / kyc_reviewer.
5. Engage → Sellers → Agreement: publish the seller agreement (approved sellers cannot finish activation without it).
6. When the commission plan is decided: Sellers → Commission plans → create, check, publish.
7. Payouts: generate two keys (`php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`). Engage `FINANCE_OUTBOUND_KEY` =
   Finance `FINANCE_INBOUND_ENGAGE_KEY`; Engage `ENGAGE_INBOUND_FINANCE_KEY` = Finance `ENGAGE_FINANCE_KEY` (sent as `X-Finance-Key`).
   Set `FINANCE_API_BASE_URL=https://finance.aicountly.org/api`. Confirm the minimum payout in Settings. Check
   Sellers → Payouts → "Preview next run", then let Monday's cron run (or run a week manually as super_admin).
   Finance's operator must confirm the TDS (194H) rate / threshold and GST settings there — Engage never computes them.
8. `GET /health`: `seller_portal_key`, `seller_data_key`, `seller_mailer`, `reach_outcomes`, `finance_payouts`,
   `finance_inbound_key` should read ok.
