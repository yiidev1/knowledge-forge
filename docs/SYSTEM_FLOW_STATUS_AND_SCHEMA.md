# Knowledge Forge — System Flow, Statuses, Vector Store & Database Schema

A practical, source-verified guide to **how this project works end to end**: what happens when you create a knowledge base, upload a document, click **Process next** / **Re-index**, how **statuses** change, how the **OpenAI vector store** is created and used, and what each **database table** is for.

Related docs (do not replace this one):

| Doc | Focus |
|-----|--------|
| [`PROJECT_GUIDE.md`](PROJECT_GUIDE.md) | Full project guide (setup, routes, env, deploy) |
| [`knowledge_base_store_wise.md`](knowledge_base_store_wise.md) | Order58 store → KB → chat (A→Z) |
| [`deploy/worker.md`](deploy/worker.md) | Cron + flock worker operations |
| [`DATABASE_SCHEMA_AND_API_EFFECTS.md`](DATABASE_SCHEMA_AND_API_EFFECTS.md) | Column-level schema + route/command → row-write → external-API matrix |

---

## Table of contents

1. [Big picture](#1-big-picture)
2. [Architecture and domains](#2-architecture-and-domains)
3. [Step-by-step: create KB → chat](#3-step-by-step-create-kb--chat)
4. [Vector store lifecycle](#4-vector-store-lifecycle)
5. [Document status lifecycle](#5-document-status-lifecycle)
6. [Document processing pipeline (by kind)](#6-document-processing-pipeline-by-kind)
7. [Operator actions (UI buttons)](#7-operator-actions-ui-buttons)
8. [Background worker (one cron pass)](#8-background-worker-one-cron-pass)
9. [Chat and grounding](#9-chat-and-grounding)
10. [Database schema](#10-database-schema)
11. [Entity relationship overview](#11-entity-relationship-overview)
12. [Quick reference cheat sheet](#12-quick-reference-cheat-sheet)

---

## 1. Big picture

Knowledge Forge is a **knowledge-base chat** app. Administrators manage knowledge bases and documents; answers come **only from indexed documents** (with citations), or a safe fallback when retrieval cannot support an answer.

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────────────┐
│ Admin /     │     │ MySQL            │     │ OpenAI                  │
│ Agent UI    │────▶│ knowledge_bases  │────▶│ Vector Store (1 per KB) │
│ (Yii3 web)  │     │ documents        │     │ + uploaded files        │
└─────────────┘     │ document_index…  │     │ File Search at chat     │
       │            │ conversations…   │     └─────────────────────────┘
       │            └────────▲─────────┘
       │                     │
       │            ┌────────┴─────────┐
       └───────────▶│ Cron worker      │  (flock-locked)
                    │ kf:worker:run    │  sync → provision → process → cleanup
                    └──────────────────┘
```

**Core rules:**

1. **Web requests never call OpenAI for indexing.** Upload / Process next / Re-index / Retry only change local DB state. The worker does the OpenAI work.
2. **Chat is the one synchronous OpenAI call** on the web tier (forced File Search + grounding check).
3. **One knowledge base ↔ one OpenAI vector store.**
4. **Documents are the unit of knowledge.** Each ready, enabled document contributes searchable content attached to that vector store.
5. **Statuses are the queue.** There is no Redis/SQS — rows in `pending` / `queued` / `indexing` drive the worker.

---

## 2. Architecture and domains

Code lives under `src/` with clear domains:

| Domain | Responsibility |
|--------|----------------|
| **Auth** | Admin login, session, throttle |
| **Agent** | Separate agent login realm; chat with eligible stores |
| **KnowledgeBase** | KB CRUD, rules, archive, **vector-store provisioning** |
| **Document** | Upload, storage, processors, process / retry / reindex / toggle / delete, remote cleanup |
| **Chat** | Conversations, forced File Search, citations, grounding |
| **Order58** | Sync stores / agents / knowledge; map stores → KBs |
| **Ai** | Typed OpenAI gateway (no SDK), operation ledger, usage |
| **Worker** | Flock lock + ordered drainers |

Typical layering inside a domain:

```
Web (Action + template) → Application (service) → Domain (entities/enums) ← Infrastructure (DB / disk / HTTP)
```

---

## 3. Step-by-step: create KB → chat

### Path A — Manual knowledge base

| Step | What happens | DB effect | OpenAI? |
|------|--------------|-----------|---------|
| 1 | Admin creates KB (name, slug, …) | `knowledge_bases` row: `status=active`, `vector_store_status=pending`, `openai_vector_store_id=NULL` | No |
| 2 | Worker runs `KnowledgeBaseProvisioningDrainer` | Claim → `provisioning`; on success → `ready` + store id | **Yes** — create vector store |
| 3 | Admin uploads PDF / image / text or adds manual text | `documents` row with `status=queued`; file on disk under `runtime/storage/` | No |
| 4 | Worker runs `DocumentProcessingDrainer` (only if KB vector store is `ready`) | `queued` → `processing` → `indexing` → `ready` (or `failed`) | **Yes** — upload file + attach to store |
| 5 | Admin opens chat and asks a question | `conversations` / `messages` rows | **Yes** — Responses API + File Search |

### Path B — Order58 store

| Step | What happens |
|------|----------------|
| 1 | Sync pulls stores → `order58_stores` |
| 2 | System ensures one KB per store (`source_system` + `source_store_id`) |
| 3 | Sync may queue generated documents (store profile / knowledge records) |
| 4 | Same provisioning + document processing as Path A |
| 5 | Agents see the store only when **source active** + **agent enabled** + **vector store ready** + ≥1 enabled ready document |

### What “ready for chat” means

A knowledge base is chattable when:

```
KB status = active
AND vector_store_status = ready
AND openai_vector_store_id IS NOT NULL
AND at least one document with status = ready AND is_enabled = 1
```

(For the agent realm, also require `source_active = 1` and `agent_enabled = 1`.)

---

## 4. Vector store lifecycle

### What a vector store is (in this project)

An **OpenAI Vector Store** is a remote searchable container. Knowledge Forge stores its id in:

```
knowledge_bases.openai_vector_store_id
```

Documents do **not** hold the store id. They attach files into the parent KB’s store; attachments are tracked in `document_index_files`.

### Vector store statuses (`knowledge_bases.vector_store_status`)

| Status | Meaning | Documents can process? | Chat? |
|--------|---------|------------------------|-------|
| `pending` | Created locally; OpenAI store not created yet | **No** (worker skips docs) | No |
| `provisioning` | Worker claimed this KB and is creating the store | No | No |
| `ready` | Store exists; id saved | **Yes** | Yes (if docs ready) |
| `failed` | Create failed permanently / attempts exhausted | No | No |

Enum: `src/KnowledgeBase/Domain/VectorStoreStatus.php`.

### When the vector store is created

1. KB insert sets `vector_store_status = pending`.
2. Cron runs `kf:worker:run`.
3. **`KnowledgeBaseProvisioningDrainer`** selects provisionable KBs:
   - `vector_store_status = pending`
   - KB `status = active`
   - backoff due (`provision_next_attempt_at` null or past)
   - For Order58-linked KBs: `source_active = 1` **and** `agent_enabled = 1` (inactive / agent-disabled stores defer provisioning)
4. Atomic **claim**: `pending` → `provisioning`, `provision_attempts++`.
5. `ProvisionKnowledgeBaseService` creates the store via the Ai gateway (ledger key like `vs.create:kb:{id}`), name pattern `kf-{id}-{slug}`.
6. Outcomes:
   - **Success** → `ready`, save `openai_vector_store_id`
   - **Transient error** → back to `pending` + backoff
   - **Unrecoverable / max attempts** → `failed`
7. Stuck `provisioning` past timeout is recovered back to `pending`.

### How documents use the vector store

```
Document (local file)
    → processor produces Indexable content (PDF bytes or derived Markdown)
    → OpenAI file upload
    → attach file to knowledge_bases.openai_vector_store_id
    → document_index_files row (openai_file_id, index_status)
    → poll until index_status = completed
    → documents.status = ready
```

**Critical dependency:** `findProcessable()` joins documents to knowledge bases and requires:

```sql
kb.vector_store_status = 'ready'
AND kb.openai_vector_store_id IS NOT NULL
```

So documents stay `queued` until the KB’s vector store is ready. That is intentional — there is nowhere to attach files yet.

### What happens on delete / re-index / disable

| Action | Local | Remote (async) |
|--------|-------|----------------|
| Delete document | Soft-delete (`status=deleted`), flag index files `pending_removal=1` | `RemoteCleanupDrainer` detaches/deletes OpenAI files |
| Re-index | Reset doc to `queued`; flag old index files for removal | Old files cleaned later; worker uploads/attaches again |
| Disable | `is_enabled=0`; flag index files for removal | Remote files cleaned; chat ignores disabled docs |
| Archive KB | KB `status=archived` | Remote vector store is **not** deleted by the normal archive path |

### Project-wide effects of vector store status

| Area | Effect of `ready` | Effect of not ready |
|------|-------------------|---------------------|
| Document worker | Processes `queued` / `indexing` docs | Docs wait in queue |
| Chat (admin) | Allowed if docs ready | Blocked |
| Agent chat | Allowed if also source/agent flags | Blocked |
| Upload UI | Still accepts uploads (queues locally) | Uploads sit as `queued` until store ready |
| Process next | Prioritises in-progress docs | Still only local priority; no OpenAI until store ready |

---

## 5. Document status lifecycle

### Technical statuses (`documents.status`)

Enum: `src/Document/Domain/DocumentStatus.php`

| Status | Meaning | In progress? |
|--------|---------|--------------|
| `uploaded` | Schema default / vestigial — **web creates as `queued`** | No |
| `queued` | Waiting for worker (or requeued after retry/reindex/enable) | **Yes** |
| `processing` | Worker claimed; producing / uploading / attaching | **Yes** |
| `indexing` | Files uploaded; waiting for OpenAI index completion (poll) | **Yes** |
| `ready` | Fully indexed; usable in chat (if `is_enabled=1`) | No |
| `failed` | Unrecoverable or attempts exhausted; needs Retry | No |
| `deleted` | Soft-deleted; excluded from lists / dedupe | No |

Helpers:

- `isInProgress()` → `queued` \| `processing` \| `indexing`
- `isRetryable()` → `failed` only

### Admin display statuses (`DocumentDisplayStatus`)

Shown in the KB detail UI (collapsed for admins):

| Display | When |
|---------|------|
| **Ready** | `status=ready` and `is_enabled=1` |
| **Processing** | enabled and status is uploaded/queued/processing/indexing |
| **Failed** | `status=failed` and enabled |
| **Disabled** | `is_enabled=0` (overrides lifecycle) |

### Happy-path state machine

```
                 create/upload/manual/sync
                          │
                          ▼
                       queued ──────────────────────────────┐
                          │ claim (worker)                  │
                          ▼                                 │
                     processing                             │
                     /    |    \                            │
                    /     |     \                           │
                   ▼      ▼      ▼                          │
              indexing  ready  failed                       │
                 │        ▲       │                         │
                 │ poll   │       │ Retry / (ops)           │
                 └────────┘       └──────► queued ──────────┘
                              Re-index (from ready) ──► queued
                              Enable (from disabled) ──► queued
```

### What each transition does to the database

| From → To | Who | Side effects |
|-----------|-----|--------------|
| *(insert)* → `queued` | Upload / ManualText / Order58 sync | File on disk; event logged |
| `queued` → `processing` | Worker claim | `processing_attempts++`, set `processing_started_at` |
| `indexing` → `processing` | Worker claim (poll) | Attempts **not** incremented |
| `processing` → `indexing` | ProcessDocumentService | Set `next_attempt_at` = now + poll interval |
| `processing` / `indexing` → `ready` | All index files `completed` | Set `processed_at`; chat-eligible |
| `*` mid-flight → `queued` | Transient AI error / stuck recovery | Backoff via `next_attempt_at` |
| mid-flight → `failed` | Unrecoverable / max attempts | `error_code` / `error_message` set |
| `failed` → `queued` | **Retry** | `requeueFresh` + mark index files pending removal |
| `ready` → `queued` | **Re-index** / content edit / enable | Same fresh requeue pattern |
| any → `deleted` | **Remove** | `deleted_at` set; local file removed; remote cleanup queued |

### `requeueFresh()` (shared by Retry / Re-index / Enable)

Resets a document for a clean worker pass:

| Column | New value |
|--------|-----------|
| `status` | `queued` |
| `processing_attempts` | `0` |
| `processing_started_at` | `NULL` |
| `next_attempt_at` | `NULL` |
| `error_code` / `error_message` | `NULL` |
| `processed_at` | `NULL` |

### Index file statuses (`document_index_files.index_status`)

| Status | Meaning |
|--------|---------|
| `pending` | Row created; upload/attach not finished |
| `in_progress` | OpenAI still indexing |
| `completed` | Searchable in the vector store |
| `failed` | Indexing failed |
| `cancelled` | Cancelled at provider |

`pending_removal = 1` means the cleanup drainer should detach/delete the remote file and drop the row.

### Processing events (`document_processing_events`)

Append-only audit log (status + message + optional JSON). Used for admin/debug trail — not for queue control.

---

## 6. Document processing pipeline (by kind)

After claim (`status=processing`), `ProcessDocumentService` runs a kind-specific processor, then uploads/attaches/polls.

### A. Text PDF (`kind=pdf`, text layer OK)

```
Probe PDF text
  → INDEX_DIRECT: upload original PDF as role=source
  → VISION (scanned / weak text): vision → derived .md → role=derived_markdown
  → MANUAL_REVIEW: document → failed
```

### B. Image (`kind=image`)

```
Always vision → derived Markdown on disk → upload .md as role=derived_markdown
(Raw image is never indexed into the vector store)
```

### C. Text / manual / Order58 text (`kind=text`)

```
No vision. Upload stored UTF-8 content as role=source (filename {token}.md)
```

### Shared finish sequence

1. If index-file rows already exist → skip produce; **poll** only.
2. Else produce Indexables → create `document_index_files` → upload + attach to vector store.
3. Poll provider `fileState`; update `index_status`.
4. **All completed** → document `ready`.
5. **Still indexing** → document `indexing`, schedule `next_attempt_at`.
6. **Transient AI error** → requeue with exponential backoff; at max attempts → `failed`.

**Vision reuse:** if derived Markdown already exists on disk, Retry / Re-index **do not re-run vision** (no second vision bill).

### Worker selection order (which document is next)

Eligible: `status IN (queued, indexing)` AND KB vector store ready AND backoff due.

Order:

```
priority DESC,
next_attempt_at IS NULL ASC,   -- due polls/retries before never-scheduled
next_attempt_at ASC,
id ASC
```

So **Process next** (`priority=1`, `next_attempt_at=NULL`) jumps the document to the front of the next worker batch.

---

## 7. Operator actions (UI buttons)

On the KB detail page (`src/KnowledgeBase/Web/Show/template.php`), document-row actions:

| Button | Visible when | Route | Status change? | OpenAI in request? |
|--------|--------------|-------|----------------|--------------------|
| **Edit** | Manual text | `kb.documents.edit.show` | Content change may requeue | No |
| **Disable / Enable** | Always (if can manage) | `kb.documents.toggle` | Disable: no status change; Enable: → `queued` | No |
| **Process next** | `queued` / `processing` / `indexing` | `kb.documents.process-now` | **No** — only `priority=1`, `next_attempt_at=NULL` | No |
| **Re-index** | `status = ready` exactly | `kb.documents.reindex` | `ready` → `queued` | No (worker later) |
| **Retry** | Display status Failed | `kb.documents.retry` | `failed` → `queued` | No |
| **Remove** | Always | `kb.documents.delete` | → `deleted` | No |

### Process next vs Re-index (mental model)

- **Process next** = “put this in-flight document first in the queue.” Does **not** process synchronously.
- **Re-index** = “tear down current OpenAI attachments (async) and queue a fresh index pass from local content / derived Markdown.”

### Disable vs Delete

| | Disable | Delete |
|--|---------|--------|
| Row kept | Yes | Soft-deleted (`deleted`) |
| Chat | Hidden | Gone |
| Remote files | Flagged for cleanup | Flagged for cleanup |
| Reversible | Enable (requeues) | Re-upload (dedupe allows after delete) |

---

## 8. Background worker (one cron pass)

Command: `./yii kf:worker:run [--limit=N]`  
Lock: flock under `runtime/locks/` (see `docs/deploy/worker.md`)

**Drainer order** (`config/common/di/worker.php`) — order matters:

| # | Drainer | Job |
|---|---------|-----|
| 1 | `IntegrationSyncDrainer` | Order58 sync runs (stores / knowledge / agents / rebuild) |
| 2 | `KnowledgeBaseProvisioningDrainer` | Create OpenAI vector stores for pending KBs |
| 3 | `DocumentProcessingDrainer` | Claim + process / poll documents |
| 4 | `RemoteCleanupDrainer` | Remove OpenAI files flagged `pending_removal` |

Also useful:

- `kf:documents:recover` — stuck `processing` → `queued`
- `kf:ai:reconcile` — operation ledger reconciliation

---

## 9. Chat and grounding

Service: `AskKnowledgeBaseService` (`src/Chat/Application/`).

1. Guard: KB ready for chat + ≥1 enabled ready document.
2. Persist user message.
3. Build instructions: immutable security preamble + KB system instructions + enabled rules (by priority) + fallback sentence.
4. Call OpenAI with **forced File Search** against `openai_vector_store_id`.
5. Resolve citations: provider `file_id` → `document_index_files` → `documents` → original filename / title.
6. **GroundingVerifier**: if retrieval/citations are insufficient → return configured fallback, `is_grounded=false`.
7. Persist assistant message (`citations_json`, `usage_json`, `retrieval_status`, …).

Agent chat reuses the same Chat services; threads may set `conversations.agent_admin_id`.

---

## 10. Database schema

Engine: **InnoDB**, charset **utf8mb4**, collation **utf8mb4_0900_ai_ci**.  
Migrations: `src/Migration/`.

### 10.1 `admin_users`

Admin accounts (single role).

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT PK | |
| `username` | VARCHAR(64) UNIQUE | |
| `password_hash` | VARCHAR(255) | `password_hash()` / `password_verify()` |
| `is_active` | TINYINT(1) | |
| `last_login_at` | DATETIME NULL | |
| `created_at` / `updated_at` | DATETIME | |

### 10.2 `auth_login_attempts`

Login throttle keyed by `sha256(username|ip)`.

| Column | Notes |
|--------|-------|
| `attempt_key` | PK |
| `attempts` | Count in window |
| `window_started_at` | |
| `locked_until` | NULL = not locked |
| `updated_at` | |

### 10.3 `knowledge_bases`

One row = one KB = one vector store (when provisioned).

| Column | Type / values | Purpose |
|--------|---------------|---------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(160) | Display name |
| `slug` | VARCHAR(160) UNIQUE | URL key |
| `description` | TEXT NULL | |
| `system_instructions` | TEXT NULL | Below security preamble |
| `openai_vector_store_id` | VARCHAR(64) UNIQUE NULL | Remote store id |
| `vector_store_status` | `pending` \| `provisioning` \| `ready` \| `failed` | Provision lifecycle |
| `provision_attempts` | SMALLINT | |
| `provision_started_at` | DATETIME NULL | Claim marker |
| `provision_next_attempt_at` | DATETIME NULL | Backoff |
| `vector_store_error_code` / `vector_store_error` | | Redacted errors |
| `status` | `active` \| `archived` | KB lifecycle |
| `source_system` | VARCHAR(32) NULL | e.g. Order58 |
| `source_store_id` | BIGINT NULL | Unique with `source_system` |
| `source_name` | VARCHAR(255) NULL | |
| `source_active` | TINYINT NULL | Mirrors Order58 store active |
| `agent_enabled` | TINYINT DEFAULT 1 | Local agent switch |
| `last_source_synced_at` | DATETIME NULL | |
| `last_indexed_at` | DATETIME NULL | Migrated; unused by app code today |
| `created_at` / `updated_at` | DATETIME | |

**Key indexes:** slug; vector store id; provisioning (`vector_store_status`, `provision_next_attempt_at`, `id`); source unique (`source_system`, `source_store_id`).

### 10.4 `knowledge_base_rules`

Per-KB instructions applied in priority order during chat.

| Column | Notes |
|--------|-------|
| `knowledge_base_id` | FK → `knowledge_bases` CASCADE |
| `name` | Unique per KB |
| `instruction` | TEXT |
| `priority` | Lower applied earlier / wins ordering |
| `is_enabled` | |

### 10.5 `documents`

| Column | Type / values | Purpose |
|--------|---------------|---------|
| `id` | BIGINT PK | |
| `knowledge_base_id` | FK CASCADE | Parent KB |
| `original_filename` | VARCHAR(255) | Display / citations |
| `stored_path` | VARCHAR(512) | Relative to storage root |
| `storage_token` | CHAR(32) | Stable token in filename / OpenAI name |
| `mime_type` / `extension` / `size_bytes` | | Server-detected |
| `checksum_sha256` | CHAR(64) | Content hash |
| `kind` | `pdf` \| `image` \| `text` | Processor selection |
| `status` | see §5 | Processing lifecycle |
| `priority` | TINYINT DEFAULT 0 | Process next sets `1` |
| `processing_attempts` | SMALLINT | Claim from `queued` increments |
| `processing_started_at` | DATETIME NULL | |
| `next_attempt_at` | DATETIME NULL | Backoff / index poll |
| `error_code` / `error_message` | | Redacted |
| `processed_at` / `deleted_at` | | |
| `dedupe_hash` | GENERATED | `checksum` if not deleted, else NULL — unique per KB |
| `source_type` | VARCHAR(48) | `uploaded_pdf`, `uploaded_image`, `uploaded_text`, `manual_text`, `order58_*`, … |
| `source_ref` | VARCHAR(64) NULL | External id (Order58); NULL for uploads |
| `source_sync_hash` | CHAR(64) NULL | Sync change detection |
| `title` | VARCHAR(255) NULL | |
| `is_enabled` | TINYINT DEFAULT 1 | Chat visibility without delete |
| `source_text` | TEXT NULL | Original manual-text body |
| `last_indexed_at` | DATETIME NULL | Migrated; unused today |
| `created_at` / `updated_at` | DATETIME | |

**Key indexes:** dedupe unique; queue (`status`, `priority`, `next_attempt_at`, `id`); source unique (`knowledge_base_id`, `source_type`, `source_ref`).

### 10.6 `document_index_files`

OpenAI artifacts for a document (source PDF or derived Markdown).

| Column | Notes |
|--------|-------|
| `document_id` | FK CASCADE |
| `role` | `source` \| `derived_markdown` |
| `derived_path` | Local Markdown path (vision) |
| `openai_file_id` | UNIQUE remote file id |
| `index_status` | `pending` \| `in_progress` \| `completed` \| `failed` \| `cancelled` |
| `usage_bytes` | |
| `last_error_*` | |
| `pending_removal` | `1` = cleanup drainer should remove remotely |
| timestamps | |

### 10.7 `document_processing_events`

Append-only history: `document_id`, `status`, `message`, `metadata_json`, `created_at`.

### 10.8 `ai_operations`

Durable ledger for non-idempotent OpenAI creates (e.g. vector store create).

| Column | Notes |
|--------|-------|
| `operation_key` | UNIQUE logical key (`vs.create:kb:12`) |
| `type` / `subject_type` / `subject_id` | |
| `status` | `pending` \| `in_flight` \| `succeeded` \| `needs_reconcile` \| `failed` |
| `result_id` | Provider id when succeeded |
| attempts / backoff / errors / timestamps | |

### 10.9 `conversations` / `messages`

| `conversations` | `messages` |
|-----------------|------------|
| `knowledge_base_id` FK | `conversation_id` FK |
| `title`, `last_message_at` | `role` = `user` \| `assistant` |
| `agent_admin_id` NULL for admin chat | `content`, `citations_json`, `usage_json` |
| | `is_grounded`, `retrieval_status` |
| | `openai_response_id`, `model` |

### 10.10 Order58 mirrors

| Table | Purpose |
|-------|---------|
| `order58_stores` | Mirrored stores (`source_id` unique) |
| `order58_agents` | Mirrored agents (`admin_id` unique) — profile, not authz |
| `order58_knowledge_records` | Mirrored knowledge content per store |
| `integration_sync_runs` | Sync job queue/ledger (`stores`, `knowledge`, `agents`, `knowledge_store`, `rebuild_store`, `health`) |

`integration_sync_runs.active_key` is a generated unique key so only one active run exists per type/scope while pending/running.

---

## 11. Entity relationship overview

```
admin_users
auth_login_attempts

knowledge_bases 1───* knowledge_base_rules
       │
       │ 1
       │
       ├── * documents 1───* document_index_files
       │         │
       │         └── * document_processing_events
       │
       └── * conversations 1───* messages

order58_stores ──(logical)──> knowledge_bases (source_system + source_store_id)
order58_knowledge_records ──(sync)──> documents (generated)
order58_agents
integration_sync_runs

ai_operations   (cross-cutting ledger for OpenAI creates)
```

**Cascade deletes:** deleting a KB removes rules, documents (and their index files / events), and conversations/messages.

---

## 12. Quick reference cheat sheet

### Status → what you can do

| Document status | Process next | Re-index | Retry | Chat uses it? |
|-----------------|:------------:|:--------:|:-----:|---------------|
| `queued` | Yes | No | No | No |
| `processing` | Yes | No | No | No |
| `indexing` | Yes | No | No | No |
| `ready` (+ enabled) | No | Yes | No | **Yes** |
| `failed` | No | No | Yes | No |
| `deleted` | — | — | — | No |
| disabled (`is_enabled=0`) | by technical status | by technical status | by display Failed | **No** |

### Vector store status → system effect

| KB `vector_store_status` | Docs process? | Chat? |
|--------------------------|---------------|-------|
| `pending` / `provisioning` | No | No |
| `ready` | Yes | Yes (if docs ready) |
| `failed` | No | No |

### One-line flows

```
Create KB          → vector_store_status=pending → worker creates store → ready
Upload document    → status=queued → (store ready) → processing → indexing → ready
Process next       → priority=1, next_attempt_at=NULL (no status change, no OpenAI)
Re-index           → ready→queued, old files pending_removal, worker re-attaches
Retry              → failed→queued, same cleanup + requeue pattern
Disable            → is_enabled=0, remote detach; status unchanged
Enable             → is_enabled=1, requeueFresh → queued
Delete             → status=deleted, local file gone, remote cleanup async
Chat               → File Search on vector store → citations → grounding check
```

### Key source files

| Concern | Path |
|---------|------|
| Routes | `config/common/routes.php` |
| Worker order | `config/common/di/worker.php` |
| Document statuses | `src/Document/Domain/DocumentStatus.php` |
| Vector store statuses | `src/KnowledgeBase/Domain/VectorStoreStatus.php` |
| Process document | `src/Document/Application/ProcessDocumentService.php` |
| Process next | `src/Document/Application/ProcessNowService.php` |
| Re-index | `src/Document/Application/ReindexDocumentService.php` |
| Provision KB | `src/KnowledgeBase/Application/ProvisionKnowledgeBaseService.php` |
| Chat ask | `src/Chat/Application/AskKnowledgeBaseService.php` |
| KB detail UI | `src/KnowledgeBase/Web/Show/template.php` |
| Migrations | `src/Migration/` |

---

*This document describes behaviour as implemented in the codebase. Prefer the source of truth in `src/` and migrations if anything drifts.*
