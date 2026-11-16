# TENDRIX

A Vendor Management System for buying intellectual services (IT consulting, freelancers):
**tenders → AI matching → contracts → invoicing → supplier performance**, in one tool, with a separate space for each actor.

The design is inspired by the procurement SaaS described in Opteamis's job posting for a Senior Fullstack
Symfony/Angular developer. It uses the stack that posting lists: PHP 8 / Symfony 7, API Platform,
an event-driven architecture with async processing, Angular, PostgreSQL/MySQL, Redis, PHPUnit, PHPStan,
GitLab CI, Docker, Sentry, and an LLM built into the product.

| | |
|---|---|
| Backend | PHP 8.2+, Symfony 7.4 LTS, API Platform 4, Doctrine ORM, Symfony Workflow, Messenger, LexikJWT |
| Frontend | Angular 19 (standalone components, signals, lazy routes), custom design system, FR locale |
| Data | PostgreSQL 16 (Docker/prod), SQLite (zero-setup local dev), Redis (prod cache) |
| AI | Claude via the official Anthropic PHP SDK (`claude-opus-5-5`), each agent with a deterministic fallback |
| Quality | PHPUnit (19 tests / 107 assertions), PHPStan level 6, GitLab CI, Sentry (prod) |

---

## Roles and spaces

| Role | Who | Can |
|---|---|---|
| `ROLE_REQUESTER` (*donneur d'ordre*) | Hiring manager at a client | Draft tenders (with the AI writer), submit them for approval, shortlist or decline proposals, evaluate suppliers |
| `ROLE_BUYER` (*acheteur*) | Client procurement officer | Everything a requester can, plus publish, close and cancel tenders, accept a proposal, sign contracts, approve, reject and pay invoices |
| `ROLE_SUPPLIER` | IT services firm or freelancer network | See open or invited tenders, propose consultants, sign contracts, issue and fix invoices, see their own scorecard |
| `ROLE_ADMIN` | Platform operator | Manage organizations and users, suspend accounts, read the audit log, see the platform-wide dashboard |

Suppliers can sign up on their own (`/register`). Admins onboard client organizations.

## Business workflows

Each lifecycle is a Symfony **state machine** (`backend/config/packages/workflow.yaml`). Guards encode
*who* may fire a transition. `WorkflowGuardSubscriber` encodes business rules such as deadlines and the
days left on a contract. Everything goes through a single endpoint,
`POST /api/{tenders|proposals|contracts|invoices}/{id}/transition` with `{transition, comment}`. Errors are mapped precisely:
**400/422** unknown transition or broken business rule, **403** wrong role, **404** other tenant, **409** wrong current state.
Detail responses include `availableTransitions` for the current user, so the UI never duplicates authorization logic.

```
Tender    draft → pending_approval → published ⇄ closed → awarded        (+ reject → draft, cancel)
Proposal  submitted → shortlisted → accepted                             (+ reject*, withdraw)
Contract  pending_buyer_signature → pending_supplier_signature → active → completed   (+ terminate*)
Invoice   draft → submitted → approved → paid                            (+ reject* → resubmit)
                                                                          * a written reason is required
```

Accepting a proposal, in one transaction: generates the contract (`CT-YYYY-NNNN`, dates computed from
availability and workload), awards the tender, and declines the competing proposals with a reason.
Invoices take the contract's daily rate at creation, are numbered with gapless sequences (row lock),
are capped by the days left on the contract, allow one per month, and get 30-day payment terms.

## Event-driven architecture

```
HTTP request ──► State processor ──► Doctrine flush ──► DomainEventRecorder (postFlush)
                                                          │  dispatched after commit, end of request
                                                          ▼
                                            Messenger "async" transport (Doctrine, or Redis)
                       ┌──────────────────────────────┼──────────────────────────────┐
                 ScoreProposal (AI)       NotifyOnWorkflowTransition        AuditWorkflowTransition
```

Events are published **only after commit**, and never from inside `flush()`. A slow LLM call never blocks
a supplier's submission: the score appears a few seconds later, and the UI polls for it. Messages that keep
failing go to the `failed` transport after 3 retries with backoff.

## AI agents (`backend/src/Ai`)

| Agent | Endpoint / trigger | Without an API key |
|---|---|---|
| **TenderWriterAgent**: turns a brief into a structured tender | `POST /api/ai/tender-draft` | Structured template |
| **MatchingAgent**: match score from 0 to 100 | async on each proposal | Deterministic score with written analysis |
| **PricingAgent**: fair daily rate ("juste prix") | `POST /api/ai/price-suggestion`, and on tender creation | Same numbers, template rationale |

The match score is **explainable by design**: skills 45 + experience 20 + daily rate 20 + availability 15,
computed in code. Claude writes the analysis and may adjust the score by at most ±10 points, based on the
free-text CV. Two identical profiles therefore always get the same baseline. The prompt tells the model to
ignore name, gender, age and origin. Pricing uses anonymized platform data (signed contracts first), with a
reference grid for a cold start. AI calls are rate-limited per user (30 per 10 minutes).

## Security

**Sessions**
- The JWT (8 h) lives only in an `HttpOnly; SameSite=Strict; Path=/api` cookie (`Secure` in production via
  `JWT_COOKIE_SECURE=1`). JavaScript never sees it, so an XSS cannot steal a session. The token carries no
  business data (identity, roles and a session version only). API clients may still send `Authorization: Bearer`.
- **Server-side revocation**: each user has a session version embedded in every token. Logout
  (`POST /api/auth/logout`), a password change or a deactivation increments it and kills every existing token.
- Changing your own password requires the current one. Passwords: 12 characters minimum, medium strength,
  checked against breached-password lists. Login is throttled (5 per minute), sign-up is rate-limited (5 per hour per IP).

**Access control**
- **Multi-tenant row-level isolation**: `TenantIsolationExtension` scopes every API Platform query.
  Data from another organization is a 404, not a 403. Suppliers never see restricted consultations
  they weren't invited to. Buyers never see supplier drafts.
- **Field-level confidentiality** (`RoleGroupsContextBuilder`): suppliers never receive buyer-internal data
  (AI fair price, internal comments, competitor count and invited list, buyer staff identities) nor the AI
  match score of their own proposals. E-mail addresses are not shared across organizations.
- Strict roles: invoices are visible to buyers, suppliers and admins only; market pricing is a buyer-side tool;
  roles must match the organization type; admins cannot demote, deactivate or suspend themselves.
  Accounts and organizations are deactivated, never hard-deleted (history is retained).

**Data protection and traceability**
- Candidate CVs are pseudonymised (name, e-mail, phone, links removed) before reaching the LLM, and
  third-party text is fenced as data to resist prompt injection. The model can only move the score by ±10.
- Audit trail of logins, logouts, every transition and every admin change to accounts and organizations
  (changed fields, never secrets), shown in the admin UI. Sentry never receives personal data.

**Transport and infrastructure**
- API responses: `Cache-Control: no-store`, `nosniff`, `X-Frame-Options: DENY`, `default-src 'none'` CSP.
- nginx: HSTS, a strict CSP (no inline scripts except the theme bootstrap, allowed by a hash computed at image
  build), security headers on every location, request rate limits, unprivileged container, no source maps.
- Production refuses to start with placeholder or short secrets; API docs, Swagger UI and the Hydra
  entrypoint are disabled in production (available in dev at `/api/docs`).

---

## Getting started

### Option A: local, no Docker (SQLite)

Requirements: PHP ≥ 8.2 (pdo_sqlite, intl recommended), Composer, Node 22 (20.19+ works), [Symfony CLI](https://symfony.com/download).

```bash
make install     # composer + npm + JWT keys + SQLite demo database
```

Then run each of these in its own terminal:

```bash
make api
```

```bash
make worker
```

```bash
make front
```

Open **http://localhost:4200**. The Angular dev server proxies `/api` to `127.0.0.1:8000`.
`backend/php.ini` turns on OPcache for the Symfony CLI server only. It makes requests about 4× faster on Windows/XAMPP.

On Windows without `make`, run the commands from the `Makefile` by hand. If generating the JWT keys
fails with an OpenSSL error, point `OPENSSL_CONF` at your `openssl.cnf` (for example `C:\xampp\php\extras\ssl\openssl.cnf`).

### Option B: full stack with Docker (PostgreSQL, Redis, nginx)

```bash
make up          # creates .env.docker with random secrets, then docker compose up -d --build
```

```bash
make demo        # same, plus the demo dataset (local only: demo accounts share a public password)
```

Open **http://localhost:8080**. Services: `web` (nginx + SPA), `api` (PHP-FPM, runs migrations at startup),
`worker` (Messenger), `scheduler` (closes tenders past their deadline every 5 minutes), `database`, `redis`.

### Demo accounts

All accounts use the password `Demo!Tendrix2026` (seeded by `AppFixtures`, development only).
In dev builds the login page has one-click buttons for them.

| Account | Role |
|---|---|
| `claire.dubois@horizon.test` | Buyer, Horizon Assurances |
| `paul.bernard@horizon.test` | Requester, Horizon Assurances |
| `karim.saidi@nova.test` / `nadia.cohen@nova.test` | Buyer / Requester, Nova Retail Group |
| `contact@digitalcraft.test`, `bd@datanext.test`, `sales@cloudforge.test`, `hello@atlas-freelance.test` | Suppliers |
| `admin@tendrix.test` | Platform administrator |

The dataset covers every state: tenders that are drafts, pending approval, open (one of them restricted)
and awarded. It also has scored proposals, an active contract with 4 months of invoices, a completed and
evaluated contract, and a contract waiting for the supplier's signature.

### Enabling Claude

Set `ANTHROPIC_API_KEY` in `backend/.env.local` (or `.env.docker`). The model is set with `ANTHROPIC_MODEL`
(default `claude-opus-5-5`). If the key is missing, the call fails, or the model refuses, every agent falls back silently.

---

## Project layout

```
backend/
  src/Entity/          Organization, User, Tender, Proposal, Contract, Invoice, SupplierEvaluation,
                       Notification, AuditLog, DocumentSequence (API Platform resources)
  src/ApiResource/     Registration, AI agents, Dashboard, SupplierPerformance (custom resources)
  src/State/           processors and providers (creation rules, transitions, reporting)
  src/Workflow/        guard and side-effect subscribers
  src/Message*/        domain events and async handlers
  src/Ai/              LlmClient (Anthropic SDK), MatchingAgent, PricingAgent, TenderWriterAgent
  src/Doctrine/        TenantIsolationExtension
  src/Security/        voters, user checker
  src/Migrations/      PostgreSQL schema
  tests/               Unit and functional API tests
frontend/src/app/
  core/                API client, auth (JWT + signals), guards, interceptor, labels
  shared/              UI kit (status badge, score ring, stars, chart, skills input, workflow actions)
  features/            auth, dashboard, tenders, proposals, contracts, invoices, suppliers, notifications, profile, admin
docker/                PHP-FPM and nginx images
compose.yaml           full stack
.gitlab-ci.yml         PHPStan, PHPUnit, Angular build, image build and push
```

## Main API endpoints

| | |
|---|---|
| `POST /api/auth/login`, `POST /api/auth/register`, `GET /api/me` | Authentication |
| `/api/tenders`, `/api/proposals`, `/api/contracts`, `/api/invoices`, `/api/supplier_evaluations` | Business resources, filters and pagination (JSON-LD/Hydra) |
| `POST /api/{resource}/{id}/transition` | Lifecycle actions |
| `POST /api/ai/tender-draft`, `POST /api/ai/price-suggestion` | AI agents |
| `GET /api/reports/dashboard`, `GET /api/reports/suppliers` | Reporting |
| `/api/notifications`, `GET /api/me/notifications/unread-count`, `POST /api/me/notifications/read-all` | Notifications |
| `/api/organizations`, `/api/users`, `/api/audit_logs` | Administration |
| `GET /api/health` | Health probe |

## Quality

```bash
make test        # PHPUnit: workflows, tenant isolation, invoicing rules, registration, dashboards
make phpstan     # level 6, clean
make ci          # phpstan + tests + production SPA build
```
