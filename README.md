# engage-aicountly

Internal AICOUNTLY `.org` portal for **sales, leads, subscriptions, licensing,
renewals, proposal follow-ups, credit management, and sales bot automation**.

- Domain: `engage.aicountly.org`
- Frontend served from the domain root
- API served from `engage.aicountly.org/api`
- Superadmin-only login; auth is independent (JWT, does **not** use `my.aicountly.com`)

Engage integrates with:

| Integration | Purpose | Env vars |
|-------------|---------|----------|
| `console.aicountly.org` | Central approval center, audit fan-out, bot report summary | `CONSOLE_API_BASE_URL`, `CONSOLE_INBOUND_KEY`, `ENGAGE_SERVICE_KEY` |
| `worker.apis.aicountly.com` | Playwright screenshots / UI review only | `WORKER_BASE_URL`, `WORKER_API_TOKEN` |
| `reach.aicountly.org` | Campaign leads pushed into Engage | `REACH_INBOUND_TOKEN` |

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
| **Deploy Production via SSH** | `.github/workflows/deploy-production.yml` | **Manual only** (`workflow_dispatch`) | Builds web + server-php, rsyncs to cPanel via SSH; **does not modify server `api/.env`** |

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

2. On cPanel: authorize the deploy key for `PROD_SFTP_USER`.
3. Create `public_html/api/.env` **manually on the server** before the first deploy (copy from `.env.example`). The workflow **never overwrites or edits** this file.
4. Run **Deploy Production via SSH** from the Actions tab.

Deploy flow (mirrors books):

- `web/dist/` → `public_html/` (preserves `api/`)
- `server-php/` → `public_html/api/` via rsync with `scripts/cpanel-rsync-api.filters` (`P .env` — protect server `.env` from `--delete`)
- `scripts/cpanel-post-deploy-api.sh` runs migrate + one-time seeders; fails if `.env` is missing; **does not modify `.env` content**

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
7. Reach side (when built): use the value of `REACH_INBOUND_TOKEN` as the
   `X-Portal-Token` header when POSTing to
   `https://engage.aicountly.org/api/internal/reach/leads`.

## Non-goals

- No marketing, blog, or social modules.
- No sandbox domain logic.
- No calls to `my.aicountly.com`.
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
  lead payload; Engage validates, upserts campaign + lead, scores it with the
  bot, schedules a follow-up, and emits an audit event to Console. Auth:
  `X-Portal-Token: <REACH_INBOUND_TOKEN>`.
- **LLM (placeholder)**: `LlmClient` uses deterministic templates when
  `llm_enabled` is off. Configure `ENGAGE_LLM_*` and enable it to route to a
  real provider (integration point is stubbed but wired).
- **Manage (placeholder)**: not called unless `MANAGE_API_*` is set. Service
  layer left as an extension seam.

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

Green/white AICOUNTLY admin look shared with QA/Smoke/Console/Flow:

- Fixed sidebar grouped by domain (Overview, Sales, Licensing & subscriptions,
  Engagement, Credits, Sales bot, Approvals & governance, Integrations, Admin)
- Sticky topbar with the current bot mode badge and superadmin identity
- Reusable pieces: `DataTable`, `FilterBar`, `KanbanBoard`, `Drawer`,
  `EmptyState`, `BotScoreCard`, `Timelines`, `ProposalPanel`,
  `CreditLedgerPanel`, and all badges (Stage / Priority / Score / Approval /
  Source / BotMode / Status)
