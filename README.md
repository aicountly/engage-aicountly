# engage-aicountly

Internal Aicountly `.org` portal for **sales, leads, subscriptions, licensing,
renewals, proposal follow-ups, credit management, and sales bot automation**.

- Domain: `engage.aicountly.org`
- Frontend served from the domain root
- API served from `engage.aicountly.org/api`
- Staff sign in through Console SSO only; Engage issues its own JWT (does **not** use `my.aicountly.com`).
  Staff **roles come from Console → Roles & Access** (see [Staff roles](#staff-roles-from-console)).

Engage integrates with:

| Integration | Purpose | Env vars |
|-------------|---------|----------|
| `console.aicountly.org` | Central approval center, audit fan-out, bot report summary | `CONSOLE_API_BASE_URL`, `CONSOLE_INBOUND_KEY`, `ENGAGE_SERVICE_KEY` |
| `console.aicountly.org` | SSO sign-in + staff roles (Roles & Access) | `CONSOLE_API_URL`, `CONTROLLER_APP_CODE=engage`, `CONSOLE_API_TOKEN` |
| `worker.apis.aicountly.com` | Playwright screenshots / UI review only | `WORKER_BASE_URL`, `WORKER_API_TOKEN` |
| `reach.aicountly.org` | Campaign leads pushed into Engage (de-duplicated, `ref` → seller attribution) | `REACH_INBOUND_TOKEN` |
| `reach.aicountly.org` | Lead outcomes (stage changes, payments) sent back to Reach via outbox | `REACH_API_BASE_URL`, `REACH_OUTCOME_TOKEN` |
| `finance.aicountly.org` | Weekly seller payout DRAFT batches out; payout status (approved / on hold / paid + UTR / failed …) back — see [docs/SELLER_PROGRAM.md § 6](docs/SELLER_PROGRAM.md) | `FINANCE_API_BASE_URL`, `FINANCE_OUTBOUND_KEY`, `ENGAGE_INBOUND_FINANCE_KEY` |
| `manage.aicountly.com` | Licensing read API for Manage's server (`/api/v1/manage/*`: licences, seats, activity, catalogue, licence types; AIC-S2S v1 HMAC, read-only, 503 until configured) — see [docs/LICENSING.md](docs/LICENSING.md) | `ENGAGE_S2S_ENVIRONMENT`, `ENGAGE_INBOUND_MANAGE_KEY_IDS`, `ENGAGE_INBOUND_MANAGE_SECRET_<KEYID>` |
| `seller.aicountly.com` | Seller portal BFF → `/api/v1/seller-portal/*` (sellers, KYC, leads, commissions, payouts) — see [docs/SELLER_PROGRAM.md](docs/SELLER_PROGRAM.md) | `SELLER_PORTAL_KEY`, `ENGAGE_DATA_KEY`, `ENGAGE_SELLER_DOCS_PATH`, `ENGAGE_SELLER_MAILER` (default `console_providers`: seller emails go through Console's email providers for `app_code=engage`, `CONSOLE_API_TOKEN` with `email.credentials`; `ENGAGE_SMTP_*` is the last resort — [docs/SELLER_PROGRAM.md § 10](docs/SELLER_PROGRAM.md)) |

## Repository layout

```
engage-aicountly/
├── web/                            Vite + React 19 + Tailwind SPA
├── server-php/                     CodeIgniter 4.6 API
├── scripts/
│   ├── cpanel-rsync-api.filters    rsync rules (preserve server api/.env)
│   └── cpanel-post-deploy-api.sh   migrate/seed hook (never edits api/.env)
├── .github/workflows/
│   ├── deploy-github-pages.yml     GitHub Pages (auto on push to main)
│   └── deploy-production.yml       Production cPanel via SSH (manual only)
└── README.md
```

## Local development

```
# Backend (http://localhost:8080/api)
cd server-php
composer install
cp .env.example .env    # fill ENGAGE_DB_*, ENGAGE_JWT_SECRET, ENGAGE_OWNER_*
php spark migrate
php spark db:seed RolesSeeder
php spark db:seed ProductsSeeder
php spark db:seed PipelineStagesSeeder
php spark db:seed BotActionsSeeder
php spark db:seed SettingsSeeder
php spark db:seed LeadSourcesSeeder
php spark db:seed OwnerSeeder
php -S 0.0.0.0:8080 -t . index.php

# Frontend (http://localhost:5173)
cd web
npm install
cp .env.example .env    # VITE_API_URL=http://localhost:8080
npm run dev
```

## GitHub Actions workflows

| Workflow | File | Trigger | Purpose |
|----------|------|---------|---------|
| **Deploy to GitHub Pages** | `.github/workflows/deploy-github-pages.yml` | **Automatic** on every push to `main` (optional manual re-run) | Builds `web/` and publishes to GitHub Pages |
| **Deploy Production via SSH** | `.github/workflows/deploy-production.yml` | **Manual only** (`workflow_dispatch`) | Builds web + server-php, rsyncs to cPanel via SSH, installs the scheduled jobs; **does not replace server `api/.env`** (writes only `CONSOLE_CRON_MONITOR_KEY`) |

### GitHub Pages setup

1. Repo **Settings → Pages → Build and deployment**: Source = **GitHub Actions** (required — deploy fails with “Deployment failed, try again later” if this is still “Deploy from a branch”).
2. Add repository secrets (or variables): `VITE_API_URL`, `VITE_APP_NAME`.
3. Every push to **`main`** triggers **Deploy to GitHub Pages** automatically.

For project pages (`https://<org>.github.io/engage-aicountly/`), Vite sets the base path automatically when `GITHUB_PAGES=true`.

**If the deploy job fails after “Created deployment”:**

- Confirm step 1 above (Source = GitHub Actions), then re-run the workflow.
- **Settings → Environments → `github-pages`**: remove required reviewers if deploys should be automatic (first deploy may create this environment).
- **Settings → Pages**: if a custom domain is set, verify DNS and HTTPS status; try clearing the domain temporarily to test.
- Transient GitHub Pages errors often succeed on **Re-run failed jobs** without code changes.

### Production SSH deploy setup (same secret names as books-react-app)

Production deploy uses **GitHub Secrets / Variables only for SSH access and the frontend build** — not for server `api/.env`. Database and API keys live **only** in `public_html/api/.env` on cPanel, created and maintained manually on the server.

1. Copy these secrets from **books-react-app** (or set the same names on this repo):

   | Secret | Purpose |
   |--------|---------|
   | `PROD_SSH_PRIVATE_KEY` | Deploy key (PEM, no passphrase) |
   | `PROD_SFTP_HOST` | cPanel SSH host |
   | `PROD_SFTP_PORT` | cPanel SSH port (usually `22`) |
   | `PROD_SFTP_USER` | cPanel SSH username |
   | `PROD_SFTP_REMOTE_ROOT` | Document root (e.g. `/home/engage/public_html`) |
   | `VITE_API_URL` | Frontend build only — use `/api` or `https://engage.aicountly.org/api` (not the domain root) |
   | `VITE_APP_NAME` | Frontend build only (optional; defaults in workflow) |
   | `CONSOLE_CRON_MONITOR_KEY` | Console's Cron Job Monitor key (what Console's `CRON_MONITOR_KEY` / `CRON_MONITOR_KEY_HASH` accepts); the deploy writes it into `api/.env` |

2. On cPanel: authorize the deploy key for `PROD_SFTP_USER`.
3. Create `public_html/api/.env` **manually on the server** before the first deploy (copy from `.env.example`). The workflow **never overwrites or edits** this file.
4. Run **Deploy Production via SSH** from the Actions tab.

Deploy flow (mirrors books):

- `web/dist/` → `public_html/` (preserves `api/`)
- `server-php/` → `public_html/api/` via rsync with `scripts/cpanel-rsync-api.filters` (`P .env` — protect server `.env` from `--delete`)
- `scripts/cpanel-post-deploy-api.sh` runs migrate + one-time seeders; fails if `.env` is missing; **does not modify `.env` content**
- **Scheduled jobs:** `api/bin/ops/post-deploy-jobs.sh` (the Aicountly ops kit, identical to console-react-app
  `ops/estate/kit/bin/ops/`) writes `CONSOLE_CRON_MONITOR_KEY` (repository secret, sent over SSH on STDIN) into `api/.env`
  — the only line of `.env` a deploy writes; Console's production refuses the shared `CONSOLE_SERVICE_KEY` for
  heartbeats — and installs `server-php/bin/ops/jobs.txt` into the deploy user's crontab (block `aicountly-engage jobs`,
  `flock -n`, log `~/logs/aicountly-engage-console-audit-deliver.log`): `php spark engage:console-audit-deliver` every
  5 minutes, reporting itself as `engage.console_audit_deliver`. A hand-pasted `spark engage:console-audit-deliver`
  line is removed so it never runs twice; the seller-programme crons stay hand-installed. No secret: a warning (the job
  runs, Console shows it OVERDUE). Crontab not writable: the deploy fails. Check on the server:
  `bash public_html/api/bin/ops/install-jobs.sh --check`.

## Production deployment (cPanel)

1. On cPanel: create a PostgreSQL database (`engage_aicountly`), a DB user, and
   grant all privileges. Note the host, port, DB name, user, password.
2. In `public_html/api/` upload the contents of `server-php/`.
3. Create `public_html/api/.env` on the server (never commit) using
   `server-php/.env.example` as a template. **DB name, DB user, DB password
   must come only from `.env`. Nothing is hardcoded.**
4. On the server, run:
   ```
   cd public_html/api
   php check-env.php
   composer install --no-dev --optimize-autoloader
   php spark migrate
   php spark db:seed RolesSeeder
   php spark db:seed ProductsSeeder
   php spark db:seed PipelineStagesSeeder
   php spark db:seed BotActionsSeeder
   php spark db:seed SettingsSeeder
   php spark db:seed LeadSourcesSeeder
   php spark db:seed OwnerSeeder
   ```
5. Build the frontend:
   ```
   cd web
   VITE_API_URL=/api npm ci && npm run build
   ```
   rsync `web/dist/` into `public_html/` (do **not** overwrite `public_html/api/`).
6. Console side (one-time): in Console `.env`, set
   `ENGAGE_BOT_API_URL=https://engage.aicountly.org/api` and
   `ENGAGE_BOT_SERVICE_KEY=<same value as ENGAGE_SERVICE_KEY on Engage>`.
   In Console DB, insert an `engage` row in `bot_portals` with
   `service_key_hash = sha256(<key>)`.
   Engage calls Console's Bot Orchestrator for approvals only
   (`POST /portal/bot-approval-request`, `POST /portal/bot-execution-result`); Console decides and
   calls back `POST /api/v1/portal/bot/approval-callback`, and reads health, reports and mode from
   Engage's `portal/bot/*`. (The old pushes to `portal/bot-mode-status`, `portal/bot-health` and
   `portal/bot-reports` were removed — Console never had those routes.)
   **Central audit:** every `AuditService` event in its fan-out list (`bot.`, `approval.`,
   `lead.converted`, `discount.applied`, `credit.large`, `auto_mode.`, or `fanout_console`) goes to
   `POST {CONSOLE_API_URL}/portal/audit` with Engage's own key (`CONSOLE_API_TOKEN`, capability
   `audit.write`) through `engage_console_audit_outbox`, retried every 5 minutes by
   `php spark engage:console-audit-deliver`, which the production deploy schedules (`server-php/bin/ops/jobs.txt`; see "Production SSH deploy setup"). The command reports each delivery run to Console's Cron Job Monitor as `engage.console_audit_deliver` (`app/Libraries/CronHeartbeat.php`; `CONSOLE_CRON_MONITOR_KEY` in `.env`, console-react-app `docs/CRON_MONITORS.md`); without the key nothing is reported, and a failed report never fails the command.
   (It used to go to `portal/bot-audit`, which refused Engage's body with 422.)
   **Seller programme crons** ([docs/SELLER_PROGRAM.md § 11](docs/SELLER_PROGRAM.md#11-cron)):
   `7 * * * * cd /path/to/api && php spark engage:commission-release >> writable/logs/commission_release.log 2>&1`,
   `1,6,11,16,21,26,31,36,41,46,51,56 * * * * cd /path/to/api && php spark engage:reach-outcomes-deliver >> writable/logs/reach_outcomes.log 2>&1`,
   `17 3 * * * cd /path/to/api && php spark engage:seller-cleanup >> writable/logs/seller_cleanup.log 2>&1`,
   weekly payout run (Monday 10:00 IST = 04:30 UTC) `30 4 * * 1 cd /path/to/api && php spark engage:seller-payout-run >> writable/logs/seller_payout_run.log 2>&1`,
   Finance batch delivery `3,8,13,18,23,28,33,38,43,48,53,58 * * * * cd /path/to/api && php spark engage:finance-payouts-deliver >> writable/logs/finance_payouts.log 2>&1`.
   **JSON columns:** every model with a JSON cast extends `EngageModel` (`TolerantJsonCast`); pass
   arrays to those fields, never `json_encode()` output. Rows an older release stored double-encoded
   still read; `php spark engage:repair-json` (dry run) / `--apply` rewrites them once after deploy.
7. Reach side (when built): use the value of `REACH_INBOUND_TOKEN` as the
   `X-Portal-Token` header when POSTing to
   `https://engage.aicountly.org/api/internal/reach/leads`.

## Staff roles from Console

Console (`console.aicountly.org` → **Roles & Access**) decides who may use
Engage and with which role. Engage keeps a local mirror in
`engage_roles` / `engage_user_roles` only so the API can enforce roles
without calling Console on every request. Nobody is given a role locally
any more — the old "every new user becomes `super_admin`" provisioning is gone.

| Role (Console app_code `engage`) | Slug | Can do |
|---|---|---|
| Engage Administrator | `super_admin` (100) | Everything |
| Sales Manager | `sales_manager` (70) | Member rights + commission plans (publish / retire), payment confirmed, commission reversal |
| Seller Manager | `seller_manager` (60) | Member rights + seller applications, suspension / termination, seller agreement, KYC review |
| KYC Reviewer | `kyc_reviewer` (40) | Member rights + seller KYC review |
| Engage Member | `member` (10) | Everything except role-gated routes: `PUT /v1/settings`, `PUT /v1/bot/settings`, Partner Master, seller programme |

Seller-programme gates are listed in [docs/SELLER_PROGRAM.md § 8](docs/SELLER_PROGRAM.md#8-roles-console--roles--access-app-engage).

**At sign-in** (`POST /v1/auth/controller-sso`, `GET /v1/auth/sso-callback`,
`POST /v1/auth/console-session`) Console's SSO exchange / introspect payload is used:

- `app_roles` present → the local roles are **replaced** with `app_roles`
  (unknown slugs ignored; `app_superadmin: true` always yields `super_admin`).
  Empty `app_roles` and not `app_superadmin` → **403** "Access not assigned. Ask a
  Console superadmin to assign you a role." and no local user is provisioned.
  `console_user_id`, `roles_synced_at` and `authz_version` are stored on `engage_users`.
- `app_roles` missing (Console not yet upgraded) → sign-in still works; existing
  local roles are kept, a user with none gets `member` — `super_admin` only for a
  Console `global_superadmin`. A warning is logged.

**At token refresh** (`POST /v1/auth/refresh {token}`; the SPA calls it on load
when its stored token is more than 10 minutes old), when `CONSOLE_API_URL` and `CONSOLE_API_TOKEN`
are both set Engage asks Console
`GET {CONSOLE_API_URL}/portal/controller-apps/user-access?app_code=engage&console_user_id=…`
(or `&email=…` before the first sync) with `Authorization: Bearer {CONSOLE_API_TOKEN}`:

| Console answer | Result |
|---|---|
| `exists`/`active`/`app_access` false, or no roles | **403**; every local session ends (`engage_users.tokens_revoked_at`), so the still-unexpired JWT is rejected too |
| access with roles | roles re-synced; the new token carries them |
| unreachable / error | allowed **only** if `roles_synced_at` is under 12 hours old, else **503** (the session is not revoked) |
| `CONSOLE_API_URL` / `CONSOLE_API_TOKEN` not set | warning logged; the token is re-issued with the locally mirrored roles (old behaviour) |

`CONSOLE_API_TOKEN` is a Console service token with capability `staff.roles`
bound to app_code `engage`. **Production must set `CONSOLE_API_URL` and
`CONSOLE_API_TOKEN`**; `GET /health` reports `checks.console_role_sync`.

`JwtFilter` reads the user row on every request: inactive users and tokens issued
before `tokens_revoked_at` get 401, and `roles` come from `engage_user_roles` (not the
token snapshot), so a role change applies on the next request. There is no role
editing in Engage: the Topbar, Settings and Bot settings screens say "Roles are
assigned in Console → Roles & Access". Members do not see **Settings** in the
sidebar, and Settings / Bot settings are read-only for them.

Schema: migration `2026-10-17-000090_AddSellerProgramRoles` adds the seller-programme roles and `engage_roles.level`;
migration `2026-10-14-000080_AddConsoleRoleSync` adds
`engage_users.console_user_id`, `roles_synced_at`, `authz_version`,
`tokens_revoked_at` and upserts the `super_admin` / `member` role rows.

### Tests

```
cd server-php
vendor/bin/phpunit --testsuite Unit
# Feature tests need an isolated Postgres DB (they self-skip otherwise):
createdb engage_test
env database.tests.database=engage_test database.tests.username=postgres \
  database.tests.password=postgres vendor/bin/phpunit
```

## Non-goals

- No marketing, blog, or social modules.
- No sandbox domain logic.
- No calls to `my.aicountly.com`.
- No partner-facing UI in Engage — partners use `partner.aicountly.com`.
- No partner data storage in Engage — Partner Master screens proxy to the
  Partner Portal's admin API; see "Partner Master" below.
- No connection to Manage unless `.env` `MANAGE_API_*` is configured; the
  integration layer is a service placeholder only.

## Portal modules

Engage covers the 18 modules from the plan:

1. Leads (list + kanban + rich detail with bot report timeline)
2. Accounts / Prospects (list + detail with linked contacts & leads)
3. Contacts
4. Sales Pipeline (Kanban across 12 stages)
5. Licensing Interest
6. Subscription Plans (Products + Plans catalogs)
7. Pricing / Discount Requests (with approve/reject)
8. Proposal Management (with proposal lines + totals)
9. Follow-ups
10. Communication Drafts (with approve/reject)
11. Renewals
12. Credit Management (generic ledger for lead/customer/affiliate/internal)
13. Lead Source Tracking
14. Campaign Leads from Reach
15. Sales Bot Queue (14 capabilities)
16. Sales Bot Reports (full audit fields per action)
17. Console Approvals (mirrored to Console)
18. Audit Logs

Plus the **Partner Master** (see below), which Engage owns on behalf of
`partner.aicountly.com`, and the **Seller programme** for `seller.aicountly.com`
(applications, sellers, KYC review, seller agreement, commission plans and
entries, lead payments) — [docs/SELLER_PROGRAM.md](docs/SELLER_PROGRAM.md).

## Partner Master

Engage provides the Partner Master **admin screens** (Add/Edit/Delete/List) —
it stores **no partner data of its own**. Every partner row lives on the
Partner Portal (`partner.aicountly.com`, repo: `aicountly/partner-aicountly`),
which is the single source of truth for both partner records and partner
authentication. Engage's `PartnersController` is a thin proxy: it checks the
superadmin's JWT exactly like every other Engage endpoint, forwards the
request to the Partner Portal's admin API over a shared secret, records an
Engage-side audit event, and relays the response back unchanged. The React
screens don't know the difference — they still call `/api/v1/partners` on
Engage.

```
engage.aicountly.org                        partner.aicountly.com
  Partners → Partner master (React UI)        owns the Partner Master
        |                                            ^
        | superadmin JWT (unchanged)                 |
        v                                            |
  PartnersController  ──── PartnerPortalClient ───────┘
  (no local table)         X-Partner-Admin-Key             |
        |                  (shared secret)                 v
        v                                            Partner database
  engage_audit_logs                                  (table: partners)
  (still records who did what)                              |
                                                              v
                                                     partner.aicountly.com
                                                       partner sign-in
```

**UI** — sidebar **Partners → Partner master** (unchanged):

| Screen | What it does |
|--------|--------------|
| Partner listing | Partner ID, name, email, phone, status, portal access, created/updated; search across name/contact/email/phone/partner ID; filter by status and by whether credentials are set |
| Add partner | Name and email required; email must be valid and unique among live partners. Portal credentials can be issued here — generate a strong password (shown once after saving) or type one — or left for later |
| View partner | Full record plus portal-access state, last login, failed attempts and lock status |
| Edit partner | Updates the master record (credentials are handled separately) |
| Activate / Deactivate | Flips `status`; inactive partners cannot sign in |
| Set / reset password | Sets a password, or generates a strong one shown exactly once (for partners created without credentials, or to reset an existing one) |
| Unlock | Clears a lockout caused by repeated failed portal logins |
| Delete | Soft delete — history is kept and the partner can never sign in again |
| Restore | Brings a deleted partner back when the email is still free |

**API** (`jwt` + `role:super_admin` — Engage Administrators only; before the seller programme any signed-in role could use it):

```
GET    /api/v1/partners                  ?q=&status=&partner_type=&country=
                                         &has_portal_access=&include_deleted=&only_deleted=
                                         &page=&limit=
POST   /api/v1/partners               optional: password, or generate: true
                                         (a generated password is returned once as
                                         generated_password and never stored in clear)
GET    /api/v1/partners/{id}
PUT    /api/v1/partners/{id}
DELETE /api/v1/partners/{id}             soft delete
POST   /api/v1/partners/{id}/activate
POST   /api/v1/partners/{id}/deactivate
POST   /api/v1/partners/{id}/password    {"password":"..."} or {"generate":true}
POST   /api/v1/partners/{id}/unlock
POST   /api/v1/partners/{id}/restore
```

Every response, including validation errors (e.g. "Another partner already
uses this email address."), is the Partner Portal's own response relayed
verbatim — Engage does not duplicate that logic. `password_hash` is never
returned by any endpoint on either side. Every successful write emits an
Engage audit event (`partner_create`, `partner_update`, `partner_delete`,
`partner_restore`, `partner_activate`, `partner_deactivate`,
`partner_password_set`, `partner_unlock`), so "who in Engage did what" is
still recorded here even though the data itself is not.

**Configuration** — `PARTNER_PORTAL_API_URL` and `PARTNER_PORTAL_ADMIN_KEY` in
`api/.env` (see `.env.example`). The key must match `PARTNER_ADMIN_KEY` in the
Partner Portal's own `api/.env`. Without both set, the Partner Master screen
returns a clear `503` rather than failing silently; `GET /health` reports this
under `checks.partner_portal`.

**Schema** — Engage defines none. `engage_partners` existed briefly during
initial development; migration `2026-08-11-000075_DropEngagePartners` removes
it. The authoritative schema (table `partners`) lives in the Partner Portal
repository — see `partner-aicountly/server-php/app/Database/Migrations/`.

## Sales Bot capabilities

`SalesBotService` implements all 14 capabilities requested by Task F: lead
qualification, scoring, follow-up recommendation, email/whatsapp/proposal
drafting, pricing suggestion, CRM stage update, follow-up scheduling, hot/stale
lead detection, renewal follow-up, Reach lead conversion, and Console-gated
approvals for the high-risk actions.

Every bot action writes an `engage_bot_reports` row containing:
`understanding`, `data_accessed` (JSON), `recommendation`, `action_proposed`,
`action_taken`, `approval_status`, `message_draft`, `proposal_draft` (JSON),
`evidence` (JSON), `next_recommended_action`, `error_message`, `created_at`.

### Modes

- **Confirm mode** — every non-trivial action must be approved by a
  superadmin. This is the default.
- **Auto mode** — the bot may run the actions listed in
  `allowed_auto_actions` without an approval. High-risk actions (send external
  message, apply discount, mark converted, create proposal, large credit
  adjustment, auto-mode config change) still require approval.

Modes and allowed actions are configurable in **Bot settings**. Changes are
audited and mirrored to Console.

## Integrations

- **Console outbound**: `ConsoleClient` sends audit events, approval requests,
  execution reports, mode status, health snapshots, and bot report summaries.
  All calls are logged into `engage_console_sync_status` for observability.
- **Console inbound** (`/api/v1/portal/bot/*`): Console can read Engage health,
  fetch bot reports, push mode changes, and post approval decisions. Auth:
  `Authorization: Bearer <ENGAGE_SERVICE_KEY>`.
- **Worker outbound**: `WorkerClient` calls `worker.apis.aicountly.com` only
  for Playwright UI/screenshot/review jobs. Results logged in
  `engage_worker_health`.
- **Reach inbound** (`POST /api/v1/internal/reach/leads`): Reach posts a
  lead payload; Engage validates, upserts the campaign, de-duplicates the lead
  (email case-insensitive, then mobile — a repeat push never changes stage,
  sales_status or owner), records UTM / landing touches, attributes `ref`
  (seller code) to a seller, scores it with the bot, schedules a follow-up for
  new leads, and emits an audit event to Console. Answers 201 `result: created`
  or 200 `result: updated, duplicate: true, matched_on`. Auth:
  `X-Portal-Token: <REACH_INBOUND_TOKEN>`.
- **Reach outbound** (`engage_reach_outcome_outbox`): stage changes (qualified,
  demo, proposal_sent, converted, lost) and confirmed payments of Reach leads go to
  `POST {REACH_API_BASE_URL}/v1/inbound/engage/lead-outcomes` (`X-Engage-Token`),
  delivered by `php spark engage:reach-outcomes-deliver` every 5 minutes.
- **Seller portal** (`/api/v1/seller-portal/*`, `X-Seller-Portal-Key`): the
  seller.aicountly.com BFF. Sellers, credentials, KYC, seller leads and
  commissions live in Engage; weekly payouts are **pending** (not built). See
  [docs/SELLER_PROGRAM.md](docs/SELLER_PROGRAM.md).
- **LLM (placeholder)**: `LlmClient` uses deterministic templates when
  `llm_enabled` is off. Configure `ENGAGE_LLM_*` and enable it to route to a
  real provider (integration point is stubbed but wired).
- **Aicountly Academy fees** ([docs/ACADEMY_FEES_RUNBOOK.md](docs/ACADEMY_FEES_RUNBOOK.md)): products
  `academy` / `edge` / `aspire`; fees are collected through Aicountly Finance's gateways and confirmed on the
  lead (`POST /api/v1/leads/{id}/payments`), which feeds revenue, seller commission and the Reach outcome.
  Revenue per product: `GET /api/v1/lead-payments/by-product?category=Academy`.
- **Manage (placeholder)**: not called unless `MANAGE_API_*` is set. Service
  layer left as an extension seam.
- **Licensing** ([docs/LICENSING.md](docs/LICENSING.md)): staff-only catalogue (licence types,
  versioned offers), account ↔ Manage company mapping and licences with an append-only activity
  timeline (sidebar **Licensing**). Manage's server reads them over AIC-S2S v1 (`/api/v1/manage/*`);
  customers never sign in to Engage. Nothing is seeded; the Manage routes answer 503 until configured.

## Response contract

Every backend response uses one of:

```
{ "ok": true,  "data": ... }
{ "ok": false, "error": "message", "details": ... }
```

## Migrations, seeders, deploy checklist

```
php spark migrate
php spark db:seed RolesSeeder
php spark db:seed ProductsSeeder
php spark db:seed PipelineStagesSeeder
php spark db:seed BotActionsSeeder
php spark db:seed SettingsSeeder
php spark db:seed LeadSourcesSeeder
php spark db:seed OwnerSeeder   # uses ENGAGE_OWNER_EMAIL / _PASSWORD from .env
```

Every migration file uses `BIGSERIAL`, foreign keys where appropriate, and
`engage_*` table names. Everything is idempotent (migrations only run once).

## Health

- Public: `GET https://engage.aicountly.org/api/health` — checks JWT secret,
  DB env, DB connection, and Console/Worker/Reach integration env.
- Authenticated: `GET /api/v1/status/health` returns richer detail. Sidebar
  entry **API health** polls it every 30s.

## Frontend UI

Green/white Aicountly admin look shared with QA/Smoke/Console/Flow:

- Fixed sidebar grouped by domain (Overview, Sales, Licensing & subscriptions,
  Engagement, Credits, Sales bot, Approvals & governance, Integrations, Admin)
- Sticky topbar with the current bot mode badge and superadmin identity
- Reusable pieces: `DataTable`, `FilterBar`, `KanbanBoard`, `Drawer`,
  `EmptyState`, `BotScoreCard`, `Timelines`, `ProposalPanel`,
  `CreditLedgerPanel`, and all badges (Stage / Priority / Score / Approval /
  Source / BotMode / Status)
