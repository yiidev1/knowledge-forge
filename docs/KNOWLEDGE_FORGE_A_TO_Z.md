# Knowledge Forge — Complete A-to-Z Guide

> **Single master document** for this project. It consolidates the material from `docs/` into one place:
> overview, architecture, folder structure, end-to-end flows, **every database table** (what / why / when / who writes it), OpenAI + Order58 integration, worker/cron, setup, routes, and operator cheat sheets.
>
> **Source of truth** is always the code under `src/` and migrations under `src/Migration/`. If this file and the code disagree, trust the code.
>
> **Secrets:** never paste real keys or passwords here. Real values live only in `.env` (git-ignored).

---

## Table of contents

1. [What this project is](#1-what-this-project-is)
2. [The one architectural rule](#2-the-one-architectural-rule)
3. [Technology stack](#3-technology-stack)
4. [Project structure](#4-project-structure)
5. [How the application boots](#5-how-the-application-boots)
6. [End-to-end system flow](#6-end-to-end-system-flow)
7. [Authentication (admin + agent)](#7-authentication-admin--agent)
8. [Knowledge bases & vector stores](#8-knowledge-bases--vector-stores)
9. [Documents: upload → index → ready](#9-documents-upload--index--ready)
10. [Background worker (cron)](#10-background-worker-cron)
11. [Grounded chat](#11-grounded-chat)
12. [Order58 integration](#12-order58-integration)
13. [Rules catalog & dual projection](#13-rules-catalog--dual-projection)
14. [Database: all tables A→Z](#14-database-all-tables-az)
15. [Entity relationships](#15-entity-relationships)
16. [External APIs](#16-external-apis)
17. [Routes & screens](#17-routes--screens)
18. [Console commands](#18-console-commands)
19. [Configuration (`.env`)](#19-configuration-env)
20. [Local setup & production deploy](#20-local-setup--production-deploy)
21. [Security model](#21-security-model)
22. [Troubleshooting](#22-troubleshooting)
23. [Quick cheat sheet](#23-quick-cheat-sheet)
24. [Related specialized docs](#24-related-specialized-docs)

---

## 1. What this project is

**Knowledge Forge** is an admin-operated (plus Order58 agent) **knowledge-base chat** application.

An administrator:

1. Creates **knowledge bases** (or syncs them from Order58 stores).
2. Adds **documents** (PDF / image / text / manual text / Order58-generated content / classified rules).
3. Asks questions that are answered **only from indexed documents, with real citations** — or with an explicit **fallback** when the documents cannot support an answer.

**Problem it solves:** plain chatbots hallucinate. This app forces every answer through **OpenAI-hosted Vector Stores + File Search**, then a **server-side grounding check**. No retrieval / no usable citation ⇒ fallback sentence, never a guess.

| Area | Status |
|---|---|
| Admin login, session, logout, login throttling | Implemented |
| Knowledge base CRUD + local rules | Implemented |
| Document upload / manual text / enable-disable / retry / reindex / process-now | Implemented |
| Background worker: sync, provision, ingest, remote cleanup | Implemented |
| Grounded chat with citations + fallback | Implemented |
| Order58 store/knowledge/agent/rules sync | Implemented |
| Separate Order58 **agent** login + chat realm | Implemented |
| Rules classification + dual projection (store + global) | Implemented |
| Self-service signup / multi-tenant SaaS / streaming chat / S3 / DOCX | **Not** implemented |

---

## 2. The one architectural rule

> **A web request never calls OpenAI for indexing.**
> Upload, Process next, Re-index, Retry, Sync — only write local DB rows and return.
> The cron worker does all OpenAI (and Order58 sync) network work.
>
> **The only synchronous provider call on the web tier for product answers is chat** (`POST /responses`).
> Agent login also calls Order58 `POST /authenticate` synchronously (auth, not indexing).

**Why:** indexing is slow and unreliable. Keeping the browser out of that work makes every click fast, atomic, and resumable — **the row is the durable record of intent**.

**There is no Redis/SQS.** Status columns **are** the queues:

| Queue (rows) | Worker stage |
|---|---|
| `integration_sync_runs` `status='pending'` | 1. Order58 sync |
| `knowledge_bases` `vector_store_status='pending'` | 2. Provision vector store |
| `documents` `status IN ('queued','indexing')` | 3. Process / poll index |
| `document_index_files` `pending_removal=1` | 4. Remote cleanup |

---

## 3. Technology stack

| Layer | Choice |
|---|---|
| Language | PHP 8.2–8.5 |
| Framework | Yii3 components (`yiisoft/*`) — DI, router, DB, view, session, CSRF, migrations |
| Database | MySQL 8.0+ (InnoDB, `utf8mb4_0900_ai_ci`, UTC) |
| Web | nginx + PHP-FPM; doc root = `public/` only |
| OpenAI | Custom typed HTTP gateway (Guzzle) — **no OpenAI SDK** |
| Retrieval | Hosted Vector Stores + File Search (embeddings managed by OpenAI) |
| PDF probe | `smalot/pdfparser` |
| Markdown render | `league/commonmark` (HTML-escaped) |
| Config | `.env` via `vlucas/phpdotenv` → `src/Environment.php` only |

---

## 4. Project structure

```
knowledge-forge/
├── public/                 # HTTP doc root (index.php + published assets)
├── config/                 # DI, routes, params (common / web / console)
├── src/                    # All application code (PSR-4 App\)
│   ├── Shared/             # Clock, secrets, markdown, DB helpers, middleware, layout
│   ├── Auth/               # Admin login, throttle, CreateAdminCommand
│   ├── Agent/              # Order58 agent login realm + chat UI
│   ├── KnowledgeBase/      # KB CRUD, rules, provisioning
│   ├── Document/           # Upload, processors, process/retry/reindex/cleanup
│   ├── Chat/               # Ask, grounding, citations, history, edit/regenerate
│   ├── Order58/            # Sync client, mirrors, IntegrationSyncDrainer
│   ├── Rules/              # Rule catalog, classification, projection
│   ├── Ai/                 # OpenAI ports/adapters, operation ledger, usage
│   ├── Worker/             # WorkerRunner, flock lock, console commands
│   ├── Migration/          # Schema migrations (25 files)
│   ├── Web/                # Dashboard, layouts, errors
│   ├── Console/            # Health etc.
│   ├── Environment.php     # ONLY place env vars are read
│   └── bootstrap.php       # Loads .env
├── runtime/                # Writable: logs/, locks/, storage/, cache/
├── tests/                  # Codeception (fakes OpenAI — no live calls in suite)
├── docs/                   # Documentation (this file is the master)
├── .env / .env.example
└── yii                     # Console entry
```

Each feature module uses the same layers:

```
Web / Console  →  Application (services)  →  Domain (entities + interfaces)
                         ↑
              Infrastructure (DB / disk / HTTP) implements ports
```

Dependencies point **inward**. Domain code must not import Yii, Guzzle, or OpenAI classes.

| Module | Responsibility | Main tables |
|---|---|---|
| `Auth` | Admin accounts & throttle | `admin_users`, `auth_login_attempts` |
| `Agent` | Agent session & store chat | uses `conversations` / `messages` |
| `KnowledgeBase` | KB lifecycle + local rules + VS provision | `knowledge_bases`, `knowledge_base_rules` |
| `Document` | Files + indexing pipeline | `documents`, `document_index_files`, `document_processing_events` |
| `Chat` | Grounded Q&A | `conversations`, `messages`, `message_revisions` |
| `Order58` | External sync | `order58_*`, `integration_sync_runs` |
| `Rules` | Rule catalog / classify / project | `rule_catalog_*`, `rule_store_links`, `rule_classification_events` |
| `Ai` | OpenAI gateway + ledger | `ai_operations` |
| `Worker` | Orchestrates drainers | (drives all status columns) |

---

## 5. How the application boots

**Web:** `public/index.php` → `bootstrap.php` (load `.env`) → Composer autoload → `yiisoft/config` DI → middleware → router → invokable Action → Application service → Repository → MySQL / template.

**Middleware order:** `ErrorCatcher` → `SecurityHeadersMiddleware` → `CorrelationIdMiddleware` → `SessionMiddleware` → `CsrfTokenMiddleware` → `RequestCatcherMiddleware` → `Router`. Admin routes add `RequireAdminMiddleware`; agent routes add `RequireAgentMiddleware`.

**Console:** `./yii` → same bootstrap/DI; commands from `config/console/commands.php`.

**Config flow for DB:**

```
.env → Environment.php → params.php → DbParams → DbConnectionFactory → ConnectionInterface → Db*Repository
```

---

## 6. End-to-end system flow

```
┌──────────────┐     ┌────────────────────┐     ┌─────────────────────────┐
│ Admin / Agent│────▶│ MySQL              │────▶│ OpenAI                  │
│ UI (Yii3)    │     │ knowledge_bases    │     │ 1 Vector Store per KB   │
└──────┬───────┘     │ documents + index  │     │ File Search at chat     │
       │             │ conversations…     │     └─────────────────────────┘
       │             └─────────▲──────────┘
       │                       │
       └──────────────────────▶│ Cron: kf:worker:run (flock)
                               │ sync → provision → process → cleanup
                               └──────────────────────────────
```

### Path A — Manual knowledge base

| Step | What | DB | OpenAI? |
|---|---|---|---|
| 1 | Create KB | `knowledge_bases` `vector_store_status=pending` | No |
| 2 | Worker provisions | → `ready` + `openai_vector_store_id` | **Yes** create store |
| 3 | Upload / manual text | `documents` `status=queued` + file on disk | No |
| 4 | Worker indexes | → `processing` → `indexing` → `ready` | **Yes** upload+attach |
| 5 | Ask question | `conversations` / `messages` | **Yes** Responses + File Search |

### Path B — Order58 store

| Step | What |
|---|---|
| 1 | Admin clicks Sync → **INS** `integration_sync_runs(pending)` only |
| 2 | Worker mirrors stores → `order58_stores` + ensures 1 KB per store |
| 3 | Sync knowledge → generated `documents` (`order58_*` source types) |
| 4 | Same provision + document processing as Path A |
| 5 | Agents chat when store active + `agent_enabled` + VS ready + ≥1 ready enabled doc |

### Ready for chat (admin)

```
KB.status = active
AND vector_store_status = ready
AND openai_vector_store_id IS NOT NULL
AND ≥1 document with usable completed index file AND is_enabled = 1
```

(For agents also: `source_active = 1` AND `agent_enabled = 1`.)

### One-page pipeline

```
migrate + admin:create
    → login
    → create KB  OR  enqueue Order58 sync
         ↓ worker
    provision vector store (pending → ready)
    → upload / sync documents (queued)
         ↓ worker
    process (processing → indexing → ready)
    → chat (POST /responses + grounding)
    → delete/reindex flags pending_removal
         ↓ worker
    remote cleanup
```

---

## 7. Authentication (admin + agent)

### Admin (`/login`)

| Concern | Location |
|---|---|
| Form / submit | `src/Auth/Web/Login/` |
| Logic | `LoginService` |
| Users table | `admin_users` |
| Throttle | `auth_login_attempts` (`sha256(username\|ip)`) |
| Session | `SessionAdminIdentityStore` |
| Gate | `RequireAdminMiddleware` (reloads user every request; `is_active=0` locks out immediately) |

**Create first admin (only supported way — no signup UI):**

```bash
./yii kf:admin:create              # default username "admin"
./yii kf:admin:create myname --generate-password
```

Password is hashed with `password_hash(PASSWORD_DEFAULT)`; printed once if generated.

### Agent (`/agent/login`)

- Separate realm in `src/Agent/`.
- Credentials posted **live** to Order58 `POST /authenticate` (password never stored locally).
- Admission: `user_type === 'agent'` AND `status === 'active'`.
- Session key `kf.agent`; throttle namespaced so it does not collide with admin.
- Agents see eligible stores and chat; they cannot reach admin/sync/upload routes.

---

## 8. Knowledge bases & vector stores

**Invariant:** one knowledge base ↔ one OpenAI vector store.

`vector_store_status` lifecycle:

```
pending ──claim──▶ provisioning ──ok──▶ ready
   ▲                    └──error/backoff──▶ failed
   └──────────── Retry provisioning (UI) ────────┘
```

| Status | Docs process? | Chat? |
|---|---|---|
| `pending` / `provisioning` | No | No |
| `ready` | Yes | Yes (if docs ready) |
| `failed` | No | No |

Order58-linked KBs are only provisioned when `source_active=1` AND `agent_enabled=1`. Inactive stores keep their existing store id and conversations.

KB `purpose`: normally `store`; a hidden global rules base uses a non-store purpose for stage-2 retrieval.

Local **knowledge_base_rules** are prompt instructions (priority order). Changing them does **not** re-index — they apply on the next chat.

---

## 9. Documents: upload → index → ready

### Document statuses

| Status | Meaning |
|---|---|
| `queued` | Waiting for worker (uploads start here) |
| `processing` | Claimed; producing/uploading/attaching |
| `indexing` | Waiting for OpenAI index poll |
| `ready` | Indexed; usable if `is_enabled=1` |
| `failed` | Needs Retry |
| `deleted` | Soft-deleted |
| `uploaded` | Schema default / vestigial — web creates as `queued` |

### Operator buttons (web = DB only)

| Button | Effect | OpenAI in request? |
|---|---|---|
| Process next | `priority=1`, `next_attempt_at=NULL` | No |
| Re-index | `ready` → `queued`, old index files `pending_removal` | No |
| Retry | `failed` → `queued` | No |
| Disable | `is_enabled=0`, flag remote removal | No |
| Enable | requeue fresh → `queued` | No |
| Remove | `status=deleted`, local file gone, remote cleanup queued | No |

### Processing by kind

| Kind | Path |
|---|---|
| `pdf` (text OK) | Upload original as `role=source` |
| `pdf` (scanned) / `image` | Vision → derived Markdown → `role=derived_markdown` |
| `text` / manual / Order58 text | Upload UTF-8 markdown as `role=source` (no vision) |

**Vision reuse:** if derived Markdown already exists on disk, Retry/Re-index does not re-bill vision.

### Source types (`documents.source_type`)

`uploaded_pdf`, `uploaded_image`, `uploaded_text`, `manual_text`, `order58_store_profile`, `order58_knowledge`, `order58_rule_store`, `order58_rule_global`, `order58_rule_common`.

---

## 10. Background worker (cron)

```bash
./yii kf:worker:run [--limit=N]   # one pass
./yii kf:documents:recover        # stuck processing → queued
./yii kf:ai:reconcile             # adopt lost OpenAI creates
```

**Drainer order** (`config/common/di/worker.php`) — dependency order matters:

1. `IntegrationSyncDrainer` — Order58 sync
2. `KnowledgeBaseProvisioningDrainer` — create vector stores
3. `DocumentProcessingDrainer` — index documents
4. `RemoteCleanupDrainer` — detach/delete remote files

**Locks:** use **two different lock files** — cron `flock` on a dedicated path, and in-app `DOCUMENT_WORKER_LOCK_PATH`. Sharing one file makes every run silently skip.

Example cron (www-data, adjust paths):

```cron
* * * * * /usr/bin/flock -n /var/lock/kf-worker.lock /usr/bin/nice -n 10 /usr/bin/php /var/www/html/knowledge-forge/yii kf:worker:run --limit=1 >> /var/www/html/knowledge-forge/runtime/logs/worker.log 2>&1
```

Full ops notes: [`deploy/worker.md`](deploy/worker.md).

Claiming uses atomic conditional `UPDATE … WHERE status = eligible` (affected rows = 1) so two workers never process the same item.

---

## 11. Grounded chat

Service: `AskKnowledgeBaseService` (`src/Chat/Application/`).

1. Guard: KB ready + usable indexed docs.
2. Persist user message first.
3. Build instructions: immutable security preamble + KB system instructions + enabled rules + fallback sentence.
4. Call OpenAI with **forced File Search** on the KB vector store (and optionally a second stage for the global rules base).
5. Resolve citations: `file_id` → `document_index_files` → `documents` → display filename/title.
6. `GroundingVerifier`: no retrieval / incomplete / no citations (when required) ⇒ `CHAT_FALLBACK_MESSAGE`, `is_grounded=false`.
7. Persist assistant message (`citations_json`, `usage_json`, `retrieval_status`, `answer_source`, …).

Edit/regenerate uses `message_revisions` + soft-supersede old answers (`active_answer_key` uniqueness = one live answer).

Two HTTP client profiles: `ai.client.chat` (short timeout) vs `ai.client.worker` (long timeout).

---

## 12. Order58 integration

Module: `src/Order58/`. Admin page `/admin/order58`.

| Sync type | API | Mirror table | Produces documents? |
|---|---|---|---|
| `stores` | `GET /accounts` | `order58_stores` | Store profile doc |
| `knowledge` | `GET /knowledge` | `order58_knowledge_records` | One per active record |
| `agents` | `GET /agents` | `order58_agents` | **No** (never OpenAI) |
| `rules` | `GET /rules` | `order58_rule_records` | Via rules catalog projection |
| `knowledge_store` / `rebuild_store` | scoped | — | Per-store regen |
| `health` | `GET /health` | — | No |

**Web only enqueues** `integration_sync_runs`. Double-click is safe: generated `active_key` UNIQUE while pending/running.

Key invariants:

- One store = one KB = one vector store (`source_system` + `source_store_id`).
- `_sync_hash` change detection — unchanged records are not re-indexed.
- Mark-and-sweep deactivation only after a full successful scan.
- Bearer token is a `SecretValue`; never logged or sent to the browser.
- `account_id` is **never** authorization for agent chat access.

Env: `ORDER58_API_BASE_URL`, `ORDER58_API_TOKEN`, timeouts/retries/page size (see `.env.example`).

---

## 13. Rules catalog & dual projection

Pipeline:

1. **Mirror** Order58 rules → `order58_rule_records`
2. **Canonicalize / dedupe** → `rule_catalog_rules` + `rule_catalog_sources`
3. **Classify** → `rule_store_links` + `rule_classification_events` (aliases in `order58_store_aliases`)
4. **Admin review** (confirm / reject / mark common / global toggle / reprocess)
5. **Dual projection** into documents:
   - Store-specific → document on that store's KB (`order58_rule_store`)
   - Global / common → document on hidden global rules KB (`order58_rule_global` / `order58_rule_common`)

Chat can answer from store knowledge, store rules, or global rules (`messages.answer_source`).

---

## 14. Database: all tables A→Z

**Engine:** InnoDB · **Charset:** utf8mb4 · **Time zone:** UTC (`+00:00`) · **Prefix:** none.

**21 application tables** (+ framework `migration`).

### Inventory

| # | Table | Domain | Why it exists | Written by |
|---|---|---|---|---|
| 1 | `admin_users` | Auth | Who can use the admin UI | Console create + login update |
| 2 | `auth_login_attempts` | Auth | Brute-force throttle (hashed keys) | Login (admin + agent) |
| 3 | `knowledge_bases` | KB | Central entity + VS provisioning state | Web + sync + worker |
| 4 | `knowledge_base_rules` | KB | Per-KB prompt rules (priority) | Web |
| 5 | `documents` | Document | Unit of knowledge + processing queue | Web + sync + worker |
| 6 | `document_index_files` | Document | OpenAI file artifacts + citation keys | Worker (+ web flags removal) |
| 7 | `document_processing_events` | Document | Append-only audit trail | Worker / services |
| 8 | `ai_operations` | AI | Crash-safe ledger for non-idempotent creates | Worker + reconcile |
| 9 | `conversations` | Chat | Chat threads (typed participant) | Web |
| 10 | `messages` | Chat | User/assistant messages + grounding metadata | Web |
| 11 | `message_revisions` | Chat | Edit history | Web |
| 12 | `integration_sync_runs` | Order58 | Sync job queue / ledger | Web enqueue, worker drain |
| 13 | `order58_stores` | Order58 | Mirrored stores | Worker sync |
| 14 | `order58_knowledge_records` | Order58 | Mirrored knowledge | Worker sync |
| 15 | `order58_agents` | Order58 | Mirrored agent profiles (no credentials) | Worker sync |
| 16 | `order58_rule_records` | Order58 | Mirrored rules | Worker sync |
| 17 | `order58_store_aliases` | Rules | Name aliases for classification | Worker + admin |
| 18 | `rule_catalog_rules` | Rules | Canonical deduped rules | Worker + review |
| 19 | `rule_catalog_sources` | Rules | Link catalog ↔ mirror records | Worker |
| 20 | `rule_store_links` | Rules | Which store a rule belongs to | Worker + review |
| 21 | `rule_classification_events` | Rules | Classification audit trail | Worker + web |
| — | `migration` | Framework | Applied migration history | `migrate:up` |

**Not in MySQL:** OpenAI usage snapshot → JSON under `runtime/cache`.

---

### 14.1 `admin_users`

**When:** install (`kf:admin:create`); login updates `last_login_at`.

| Column | Notes |
|---|---|
| `id` | PK |
| `username` | UNIQUE |
| `password_hash` | `password_hash()` / `password_verify()` |
| `is_active` | Honoured every request |
| `last_login_at` | On success |
| `created_at` / `updated_at` | UTC |

---

### 14.2 `auth_login_attempts`

**When:** every failed (and checked) login; cleared on success.

| Column | Notes |
|---|---|
| `attempt_key` | PK — hash, never plaintext username |
| `attempts` | Failures in window |
| `window_started_at` | |
| `locked_until` | NULL = not locked |
| `updated_at` | Cleanup index |

Defaults: 5 failures / 15 min window → 15 min lock (`AUTH_*` env).

---

### 14.3 `knowledge_bases`

**When:** admin create form; Order58 store sync; rules global base ensure.

| Column | Purpose |
|---|---|
| `name`, `slug` (UNIQUE), `description` | Identity / URL |
| `system_instructions` | Below immutable security preamble |
| `openai_vector_store_id` | UNIQUE remote store id (NULL until provisioned) |
| `vector_store_status` | `pending\|provisioning\|ready\|failed` — **the provision queue** |
| `provision_*` / `vector_store_error*` | Attempts, backoff, errors |
| `status` | `active\|archived` |
| `source_system`, `source_store_id` | Order58 link (UNIQUE pair) |
| `source_name`, `source_active`, `agent_enabled` | Integration flags |
| `purpose` | `store` vs hidden rules base |
| `last_source_synced_at` | Sync stamp |

**Why status on the row:** one place to look when stuck; state cannot drift from the entity.

---

### 14.4 `knowledge_base_rules`

**When:** admin manages rules on KB detail. **No OpenAI.**

FK → `knowledge_bases` CASCADE. Unique `(knowledge_base_id, name)`. `priority` lower wins. Injected at chat time.

---

### 14.5 `documents`

**When:** upload, manual text, Order58 generation, rule projection; worker advances status.

| Important columns | Why |
|---|---|
| `stored_path`, `storage_token` | Safe storage outside web root |
| `mime_type` | **Server-sniffed**, never client |
| `checksum_sha256` / generated `dedupe_hash` | UNIQUE live dedupe; deleted rows get NULL hash ⇒ re-upload allowed |
| `kind` | `pdf\|image\|text` — processor selection |
| `status` / `priority` / `next_attempt_at` | **The document queue** |
| `source_type` / `source_ref` / `source_sync_hash` | Provenance + sync change detection |
| `is_enabled` | Hide from chat without delete |
| `source_text` | Editable original for manual text |
| `is_source_overridden` | Admin edit survives next sync |

Files live under `runtime/storage/` (or `KNOWLEDGE_STORAGE_PATH`).

---

### 14.6 `document_index_files`

**When:** worker uploads/attaches; web sets `pending_removal`; cleanup deletes row after remote delete.

| Column | Why |
|---|---|
| `role` | `source` or `derived_markdown` |
| `openai_file_id` | UNIQUE — **citation resolution key** |
| `index_status` | `pending\|in_progress\|completed\|failed\|cancelled` |
| `pending_removal` | Split “flag in web” from “delete in worker” |

Chat availability is driven by completed index files, not only `documents.status`.

---

### 14.7 `document_processing_events`

**When:** processing milestones. Append-only audit (`status`, `message`, `metadata_json`). Not a queue.

---

### 14.8 `ai_operations`

**When:** non-idempotent OpenAI creates (esp. vector store create) need crash safety.

| Column | Why |
|---|---|
| `operation_key` | UNIQUE deterministic key e.g. `vs.create:kb:12` |
| `status` | `pending\|in_flight\|succeeded\|needs_reconcile\|failed` |
| `result_id` | Adopted provider id |
| `request_fingerprint` | Distinguish real matches |

`kf:ai:reconcile` finds stores tagged with `metadata.kf_op`.

---

### 14.9 `conversations`

**When:** start/ask chat (admin or agent).

| Column | Why |
|---|---|
| `knowledge_base_id` | Thread bound to one KB forever |
| `participant_type` / `participant_id` | Typed identity (`admin`/`agent`) — UNIQUE per KB |
| `agent_admin_id` | Legacy column kept for history |
| `title`, `last_message_at` | List UI |

---

### 14.10 `messages`

**When:** every ask; assistant after grounding.

| Column | Why |
|---|---|
| `role` | `user\|assistant` |
| `content` | Message body |
| `citations_json` / `usage_json` | Resolved citations + tokens |
| `is_grounded` | **BIT(1)** — verified retrieval + citations |
| `retrieval_status` | Audit trail |
| `answer_source` | Which stage answered |
| `reply_to_message_id`, `superseded_at`, `active_answer_key` | Edit/regenerate: one live answer |

---

### 14.11 `message_revisions`

**When:** user/admin edits a message. Stores previous content + editor identity. Append-only.

---

### 14.12 `integration_sync_runs`

**When:** admin clicks Sync / Check / rebuild; worker claims and runs.

Generated `active_key` UNIQUE while `pending|running` ⇒ single-flight per type/scope.

Types: `stores`, `knowledge`, `agents`, `rules`, `knowledge_store`, `rebuild_store`, `health`.

---

### 14.13–14.16 Order58 mirrors

Shared shape: unique source id, `snapshot_json`, `sync_hash`, `last_seen_sync_run_id` (mark-and-sweep). **No FK** to KBs — logical link via `source_*` columns.

| Table | Unique | Documents? |
|---|---|---|
| `order58_stores` | `source_id` | Store profile |
| `order58_knowledge_records` | `source_id` | Yes |
| `order58_agents` | `admin_id` | No |
| `order58_rule_records` | `source_id` | Via catalog |

---

### 14.17 `order58_store_aliases`

Aliases (official name, company, domain, manual, …) used to match rules to stores. UNIQUE `(store_source_id, normalized_alias)`.

---

### 14.18 `rule_catalog_rules`

Canonical rule after dedupe. Holds `scope_type`, `classification_status`, confidence, review fields, `is_globally_available`.

---

### 14.19 `rule_catalog_sources`

Links catalog rule ↔ `order58_rule_records` (`primary` / `exact_duplicate` / `manually_merged`).

---

### 14.20 `rule_store_links`

Which store a catalog rule maps to (`suggested|confirmed|rejected`) and how it was matched.

---

### 14.21 `rule_classification_events`

Append-only history of classification decisions (machine or admin).

---

### Cascades

Deleting a **knowledge base** cascades to rules, documents (and their index files/events), and conversations/messages. Order58 mirrors are **not** FK-cascaded — deactivate via sync.

---

## 15. Entity relationships

```
admin_users
auth_login_attempts

knowledge_bases 1───* knowledge_base_rules
       │
       ├── * documents 1───* document_index_files
       │         └── * document_processing_events
       │
       └── * conversations 1───* messages 1───* message_revisions

order58_stores ──(logical)──> knowledge_bases (source_system + source_store_id)
order58_knowledge_records ──(sync)──> documents
order58_rule_records ──> rule_catalog_* ──> rule_store_links ──> documents (projection)
order58_agents
order58_store_aliases
integration_sync_runs
ai_operations   (cross-cutting ledger)
```

---

## 16. External APIs

### OpenAI (`OPENAI_BASE_URL`, default `https://api.openai.com/v1`)

| Call | Tier | Effect |
|---|---|---|
| `POST /vector_stores` | Worker | KB → `ready` |
| `POST /files` + attach + poll | Worker | Index files → completed; doc → `ready` |
| `DELETE` file / VS file | Worker cleanup | Remove remote + local index row |
| `POST /responses` | **Web chat** | Messages + grounding |

No embedding model env var — File Search manages embeddings.

### Order58 (`ORDER58_API_BASE_URL`)

| Call | Tier | Effect |
|---|---|---|
| `GET /accounts|/knowledge|/agents|/rules` | Worker | Mirrors + generated docs |
| `GET /health` | Worker (enqueued) | Health run result |
| `POST /authenticate` | **Web agent login** | Session only |

---

## 17. Routes & screens

Defined in `config/common/routes.php`.

**Public:** `GET|POST /login`, `GET|POST /agent/login`.

**Admin (gated):** dashboard `/`, knowledge bases CRUD, documents (upload/manual/edit/toggle/retry/reindex/process-now/delete), rules, chat, Order58 sync (`/admin/order58`), rules review, OpenAI usage (direct URL).

**Agent (gated):** `/agent`, `/agent/stores/{slug}/chat[/{id}]`.

There is **no HTTP health endpoint** — use `./yii kf:health`.

---

## 18. Console commands

| Command | Purpose |
|---|---|
| `./yii migrate:up` | Apply schema |
| `./yii kf:admin:create` | First/additional admin |
| `./yii kf:health` | Config + DB (no OpenAI) |
| `./yii kf:openai:ping` | Live OpenAI capability check |
| `./yii kf:worker:run [--limit=N]` | One worker pass |
| `./yii kf:documents:recover` | Unstick documents |
| `./yii kf:ai:reconcile` | Adopt ambiguous OpenAI creates |
| `./yii kf:order58:reconcile-active` | Fix `source_active` drift |
| `./yii kf:rules:reconcile-global` | Fix global rule projection drift |

---

## 19. Configuration (`.env`)

Copy `.env.example` → `.env`. All keys validated in `src/Environment.php`.

**Must set:** `APP_ENV`, `APP_DEBUG`, `DB_*`, `OPENAI_API_KEY`, `OPENAI_CHAT_MODEL`, `OPENAI_VISION_MODEL`, storage/worker/chat/auth tunables.

**Order58 (optional until sync):** `ORDER58_API_BASE_URL`, `ORDER58_API_TOKEN`.

**Production:** `APP_ENV=prod`, `APP_DEBUG=false`.

After editing `.env`, re-run `bash docs/deploy/grant-web-access-acl.sh` and reload PHP-FPM so `www-data` can still read it.

---

## 20. Local setup & production deploy

### Local

```bash
cd /var/www/html/knowledge-forge
composer install
cp .env.example .env          # fill DB_* and OPENAI_*
./yii migrate:up
bash docs/deploy/grant-web-access-acl.sh
./yii kf:admin:create
./yii kf:health
./yii kf:openai:ping          # real API calls
./yii kf:worker:run --limit=1
composer test
```

nginx samples: [`nginx/knowledge-forge.dev.conf`](nginx/knowledge-forge.dev.conf), [`nginx/knowledge-forge.conf`](nginx/knowledge-forge.conf).

### Production checklist

1. `composer install --no-dev` (+ `composer yii-config-rebuild` if config changed)
2. `.env` locked down (`0640`, deploy user + www-data), `APP_DEBUG=false`
3. `./yii migrate:up`
4. Permissions / ACL scripts under `docs/deploy/`
5. `kf:health` fingerprints match for CLI and `www-data`
6. `kf:openai:ping` passes
7. nginx + TLS
8. Cron worker installed (see §10)
9. First admin created
10. Smoke: create/sync KB → worker provisions → upload/sync docs → worker indexes → grounded chat

**Backup:** `mysqldump` + `runtime/storage/`. Vector stores can be rebuilt via re-index from local files.

---

## 21. Security model

- Immutable security preamble wraps model instructions; document text is untrusted tool output.
- Forced File Search + grounding verifier.
- Secrets: `SecretValue` + `SecretRedactor`; never log keys.
- Uploads: random names, sniffed MIME, outside `public/`, size caps, image validation.
- IDOR: child lookups scoped by parent; CSRF on state changes; HTML-escaped output; bound SQL params.
- Correlation id on every request (`X-Correlation-Id`) and log line.

---

## 22. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Browser 500, CLI works | `.env` ACL dropped after edit | `grant-web-access-acl.sh` + reload php-fpm |
| Docs stuck `queued` | KB vector store not `ready` | Wait/fix provisioning; check `vector_store_status` |
| Worker “runs” but does nothing | Cron flock on same file as app lock | Use separate lock paths |
| Doc stuck `processing` | Worker died mid-run | `kf:documents:recover` |
| Duplicate vector stores risk | Lost response after create | `kf:ai:reconcile` |
| Chat always fallback | No ready/enabled docs or grounding fail | Check index_status + citations |
| Agent cannot see store | `source_active` / `agent_enabled` / VS / docs | Sync + toggle + wait for ready |
| Vision ping fails | Bad model or fixture | Restore `probe.png` or fix `OPENAI_VISION_MODEL` |

---

## 23. Quick cheat sheet

```
Create KB       → vector_store_status=pending → worker → ready
Upload/sync doc → status=queued → (store ready) → processing → indexing → ready
Process next    → priority only (no OpenAI in web)
Re-index        → ready→queued + pending_removal
Retry           → failed→queued
Disable         → is_enabled=0 + remote cleanup
Delete          → status=deleted + remote cleanup
Chat            → File Search → citations → grounding → message
Order58 Sync    → INS sync_runs only → worker mirrors + generates docs
```

| Document status | Process next | Re-index | Retry | In chat? |
|---|---|---|---|---|
| `queued`/`processing`/`indexing` | Yes | No | No | No |
| `ready` + enabled | No | Yes | No | **Yes** |
| `failed` | No | No | Yes | No |
| disabled / deleted | — | — | — | No |

**Key source files**

| Concern | Path |
|---|---|
| Routes | `config/common/routes.php` |
| Worker order | `config/common/di/worker.php` |
| Env | `src/Environment.php` |
| Process document | `src/Document/Application/ProcessDocumentService.php` |
| Provision KB | `src/KnowledgeBase/Application/ProvisionKnowledgeBaseService.php` |
| Chat ask | `src/Chat/Application/AskKnowledgeBaseService.php` |
| Migrations | `src/Migration/` |

---

## 24. Related specialized docs

This file is the **master A–Z**. Deeper / narrower material still lives in:

| File | Focus |
|---|---|
| [`DATABASE_SCHEMA_AND_API_EFFECTS.md`](DATABASE_SCHEMA_AND_API_EFFECTS.md) | Step-by-step “zero to end” with exact row writes + full column SQL |
| [`SYSTEM_FLOW_STATUS_AND_SCHEMA.md`](SYSTEM_FLOW_STATUS_AND_SCHEMA.md) | Status machines & operator UI detail |
| [`PROJECT_GUIDE.md`](PROJECT_GUIDE.md) | Beginner ops guide (env matrix, screens) |
| [`ARCHITECTURE_AND_INTEGRATION.md`](ARCHITECTURE_AND_INTEGRATION.md) | Extension recipes / linking other apps |
| [`ORDER58_INTEGRATION.md`](ORDER58_INTEGRATION.md) | Order58 phases & invariants |
| [`knowledge_base_store_wise.md`](knowledge_base_store_wise.md) | Store-wise journey |
| [`OPENAI_TECHNICAL_AND_COST_AUDIT.md`](OPENAI_TECHNICAL_AND_COST_AUDIT.md) | Cost & technical audit |
| [`deploy/worker.md`](deploy/worker.md) | Cron + flock operations |
| [`nginx/`](nginx/) | Sample vhosts |

---

*Consolidated from the `docs/` folder for Knowledge Forge. Prefer `src/` and migrations if anything drifts.*
