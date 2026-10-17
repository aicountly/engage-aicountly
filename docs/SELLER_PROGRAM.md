# Seller programme (seller.aicountly.com ⇄ Engage)

Resellers, associates and business facilitators sell AICOUNTLY through **seller.aicountly.com**. That portal
(repo `aicountly/seller-aicountly`) is a React SPA plus a thin PHP BFF with **no database**: every seller
account, credential, KYC record, lead attribution and commission entry lives here, in Engage.

```
browser ⇄ seller.aicountly.com BFF ──X-Seller-Portal-Key (+ X-Seller-Session)──▶ engage /api/v1/seller-portal/*
Engage staff (Console SSO, role-gated) ──▶ engage /api/v1/{seller-applications, sellers, seller-kyc, seller-agreements,
                                                          commission-plans, commission-entries, leads/{id}/payments}
reach ──X-Portal-Token──▶ engage /api/v1/internal/reach/leads        (now carries ref + UTM / landing data)
engage ──X-Engage-Token──▶ reach /api/v1/inbound/engage/lead-outcomes (outbox, every 5 min)
engage ──▶ finance (payout batches)                                   PENDING — not built, see "Weekly payouts"
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
    AICOUNTLY." (never says who holds it);
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
  `in_payout` / `paid` entries need a clawback, which arrives with payouts (409 today).
- `engage_credit_ledger` is **not** used for commission: it is a generic credit / points book without plan versions,
  hold windows or payout state. Commission entries are their own ledger; the credit ledger is unchanged.

## 6. Weekly payouts — PENDING (not built)

On hold pending the product owner's decision. Nothing in Engage builds payout runs, calls Finance or accepts a Finance
callback. The seam:

- `app/Services/Payouts/SellerPayoutGateway.php` — the interface a payout implementation provides
  (`buildWeeklyRun()`: Monday 10:00 IST, active + kyc_verified sellers, approved entries, minimum threshold setting
  `seller_payout_min_threshold` = 500.00, entries → `in_payout` with `payout_item_ref`, POST
  `finance /api/v1/inbound/engage/payout-batches`; `applyFinanceStatus()`: `POST engage /api/v1/inbound/finance/payout-status`).
- Schema already allows `in_payout` / `paid`, `payout_item_ref`, `paid_at` on entries.
- Today: `GET seller-portal/payouts` → `{enabled: false, status: "payouts not enabled yet", items: [], total: 0}`;
  `payouts/{ref}` and its statement → 404; dashboard `next_payout_date: null`; `/health` → `seller_payouts: pending`.
- Env names reserved by the contract (not read yet): `FINANCE_INBOUND_ENGAGE_KEY`, `ENGAGE_INBOUND_FINANCE_KEY`.

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
| super_admin | 100 | everything (incl. Partner Master, settings) |
| sales_manager | 70 | view sellers; commission plans (create / publish / retire); payment confirmed; reverse / refund; lead attribution |
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
- `GET payouts` adds `enabled`, `status` and `message` to the list envelope; payout detail / statement are 404.

## 10. Configuration

| Env | Purpose |
|---|---|
| `SELLER_PORTAL_KEY` | ≥ 32 chars, same value in the seller BFF. Unset → 503 on every seller-portal route. |
| `ENGAGE_DATA_KEY` (`_PREVIOUS`) | 32-byte key (64 hex / base64) for KYC encryption. Unset → KYC 503. |
| `ENGAGE_SELLER_DOCS_PATH` | Directory for encrypted KYC files, outside the web root. |
| `SELLER_PORTAL_BASE_URL` | Base of activation / reset links (default https://seller.aicountly.com). |
| `SELLER_REFERRAL_WEBSITE_URL`, `SELLER_REFERRAL_APP_URL` | Referral link bases. |
| `ENGAGE_SELLER_MAILER` | `console` \| `smtp` \| empty (none). |
| `ENGAGE_SELLER_NOTIFY_PRODUCT` | Console product for seller mail (default `engage`). |
| `ENGAGE_SMTP_HOST/_PORT/_USER/_PASS/_CRYPTO`, `ENGAGE_MAIL_FROM(_NAME)` | SMTP mailer. |
| `REACH_API_BASE_URL`, `REACH_OUTCOME_TOKEN` | Reach outcomes (token = Reach's `REACH_INBOUND_ENGAGE_TOKEN`, ≥ 32). |

Settings (Settings screen, `engage_settings`): `seller_login_otp_enabled` (false), `seller_lead_protection_days` (90),
`commission_default_hold_days` (15), `seller_payout_min_threshold` ("500.00", display only until payouts exist).

**Console mail:** sellers are not Console users, so Console must (a) give Engage's `CONSOLE_API_TOKEN` the
`notify.send` + `notify.direct` capabilities for the product, (b) accept direct recipients for that product
(`NotificationSendController::DIRECT_RECIPIENT_PRODUCTS` does not list `engage` today), and (c) have active templates
`seller_activation` (vars name, seller_code, activation_url, expires_at), `seller_password_reset` (name, reset_url,
expires_at), `seller_login_otp` (name, code, expires_minutes). Until then Console answers `sent: false` and Engage says
so (links shown once to staff). SMTP is the alternative.

## 11. Cron

```
# commission hold release (hourly)
7 * * * * cd /path/to/api && php spark engage:commission-release >> writable/logs/commission_release.log 2>&1
# Reach outcome delivery (every 5 minutes, offset from the Console audit job)
1,6,11,16,21,26,31,36,41,46,51,56 * * * * cd /path/to/api && php spark engage:reach-outcomes-deliver >> writable/logs/reach_outcomes.log 2>&1
# seller sessions / one-time tokens / idempotency records cleanup (daily)
17 3 * * * cd /path/to/api && php spark engage:seller-cleanup >> writable/logs/seller_cleanup.log 2>&1
```

Each reports to Console's Cron Job Monitor (`engage.commission_release`, `engage.reach_outcomes_deliver`,
`engage.seller_cleanup`) when `CONSOLE_CRON_MONITOR_KEY` is set; register those monitors in Console.

## 12. Schema

| Migration | Tables / changes |
|---|---|
| `2026-10-17-000090_AddSellerProgramRoles` | roles sales_manager, seller_manager, kyc_reviewer; `engage_roles.level` |
| `2026-10-17-000091_CreateSellerDomain` | `engage_seller_applications`, `engage_sellers`, `engage_seller_sessions`, `engage_seller_tokens`, `engage_seller_agreement_versions`, `engage_seller_agreement_acceptances`, `engage_seller_kyc`, `engage_seller_kyc_documents`, `engage_seller_referral_codes`, `engage_seller_lead_registrations`, `engage_idempotency_keys`; `engage_leads` + seller_id, seller_attribution, seller_attributed_at, seller_protection_expires_at, reach_lead_id, mobile_normalized (backfilled), attribution, converted_at; lead sources `seller_request`, `seller`; settings above |
| `2026-10-17-000092_CreateCommissionTables` | `engage_commission_plans`, `engage_lead_payments`, `engage_commission_entries` |
| `2026-10-17-000093_CreateReachOutcomeOutbox` | `engage_reach_outcome_outbox` |

## 13. Operator steps after deploy

1. `php spark migrate` (the post-deploy hook does it).
2. In `api/.env`: `SELLER_PORTAL_KEY` (same as the BFF), `ENGAGE_DATA_KEY` (generate and **back up**),
   `ENGAGE_SELLER_DOCS_PATH` (create the directory outside `public_html`, writable by PHP only),
   `REACH_API_BASE_URL` + `REACH_OUTCOME_TOKEN` (same as Reach's `REACH_INBOUND_ENGAGE_TOKEN`), and a seller mailer
   (`ENGAGE_SELLER_MAILER=smtp` + `ENGAGE_SMTP_*`, or `console` once Console is set up as in § 10). `php check-env.php`.
3. Add the three cron lines; register the Console cron monitors.
4. In Console → Roles & Access assign sales_manager / seller_manager / kyc_reviewer.
5. Engage → Sellers → Agreement: publish the seller agreement (approved sellers cannot finish activation without it).
6. When the commission plan is decided: Sellers → Commission plans → create, check, publish.
7. `GET /health`: `seller_portal_key`, `seller_data_key`, `seller_mailer`, `reach_outcomes` should read ok.
