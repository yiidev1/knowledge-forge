# Knowledge Base — Store-wise Guide (A → Z)

This is the complete reference for Knowledge Forge: how the project works end to end, how an **Order58
store** becomes a chat-answerable **knowledge base**, the **full database schema** (every table — why it
exists, when a row is inserted, when it is updated), a **which-action-writes-which-table** matrix, the
**external APIs** it calls (Order58 + OpenAI), and every **status** and **admin badge** — plus the two newest
features: the **chat-availability policy** and **editable chat questions with revision audit**.

No prior knowledge assumed. Read top to bottom, or jump via the contents.

> **Security note:** this document names environment variables (e.g. `OPENAI_API_KEY`, `ORDER58_API_TOKEN`,
> `DB_PASSWORD`) but **never** their values. Real secrets live only in `.env`, which is not committed. Never
> paste secret values into docs, logs, or commits.

---

## Contents

1. [The big picture in one paragraph](#1-the-big-picture-in-one-paragraph)
2. [Architecture & code layout](#2-architecture--code-layout)
3. [The one rule: three independent switches](#3-the-one-rule-three-independent-switches)
4. [What the admin badges mean](#4-what-the-admin-badges-mean)
5. [The database — table by table (all 16)](#5-the-database--table-by-table-all-16)
6. [Status glossary (all of them, in one place)](#6-status-glossary-all-of-them-in-one-place)
7. [Which action writes which table (effect matrix)](#7-which-action-writes-which-table-effect-matrix)
8. [The background worker](#8-the-background-worker)
9. [Step by step: upload a `.txt` / manual text → OpenAI](#9-step-by-step-upload-a-txt--manual-text--openai)
10. [Step by step: how a store becomes a KB (sync)](#10-step-by-step-how-a-store-becomes-a-kb-sync)
11. [Step by step: chatting with a store](#11-step-by-step-chatting-with-a-store)
12. [Chat availability policy (when the composer is on/off)](#12-chat-availability-policy-when-the-composer-is-onoff)
13. [Editing a chat question (revision audit + regeneration)](#13-editing-a-chat-question-revision-audit--regeneration)
14. [External APIs — Order58 & OpenAI](#14-external-apis--order58--openai)
15. [Command & cron reference](#15-command--cron-reference)
16. [Quick answers (cheat sheet)](#16-quick-answers-cheat-sheet)

---

## 1. The big picture in one paragraph

Each **Order58 store** is mirrored locally and mapped to **exactly one Knowledge Base (KB)**. A KB owns **one
OpenAI vector store** (a searchable container of text). Everything an assistant can answer from is a
**document** attached to that vector store. Documents come from uploads (PDF / image / `.txt` / `.md`), from
**manual text** an admin types, or are generated from Order58 store/knowledge records. All the slow work
(calling Order58, calling OpenAI, indexing) happens in a **background worker** (run by cron every 2 minutes) —
never while you click a button. A store becomes **chattable** only when its KB is provisioned **and** it holds
at least one **usable** indexed document (the synced store *profile* alone does not count — see §12).

```
Order58 store ──(sync)──> order58_stores ──> knowledge_bases (1 per store)
                                                   │
                                                   ├── openai vector store (1 per KB)
                                                   │
                          documents (many) ────────┘   each indexed file → document_index_files
                                                   │
                                              conversations → messages   (chat, grounded in the vector store)
```

---

## 2. Architecture & code layout

Knowledge Forge is a **Yii 3 / Yiisoft** application (PHP 8.2, MySQL 8.0) built in a **hexagonal (ports &
adapters)** style. Each business area is a **module** under `src/<Module>/` and is split into the same layers:

| Layer | Folder (per module) | What lives here | Depends on |
|-------|---------------------|-----------------|------------|
| **Domain** | `Domain/` | Entities, value objects, enums, domain exceptions. Pure PHP, no framework, no I/O. | nothing |
| **Application** | `Application/` | Use-case services that orchestrate domain + ports (e.g. `AskKnowledgeBaseService`). | Domain + Contract |
| **Contract** | `Contract/` | **Ports** — interfaces the application depends on (repositories, external clients). | Domain |
| **Infrastructure** | `Infrastructure/` (and `Client/`, `OpenAi/`) | **Adapters** — real implementations: `Db*Repository` (PDO/Yii DB), HTTP clients, storage. | Contract |
| **Web** | `Web/` | Thin HTTP actions + PHP view templates; convert requests → application calls → responses. | Application |
| **Console** | `Console/` | CLI commands (the worker, health, reconcile). | Application |

**Modules** (`src/`): `Admin`, `Agent`, `Ai` (OpenAI adapters + usage ledger), `Chat`, `Document`,
`KnowledgeBase`, `Order58`, `Shared` (clock, DB helpers, storage, middleware), `Migration`.

**The dependency rule:** application code depends only on **interfaces** in `Contract/`; the concrete
`Db*`/`Http*` adapters are wired in via dependency injection (`config/common/di/*.php`). This is why the same
service can be unit-tested against an in-memory fake and run in production against MySQL/OpenAI unchanged.

**Two request lifecycles** — remember this distinction, it explains everything:

```
FAST PATH (browser, synchronous)              SLOW PATH (background worker, async)
──────────────────────────────               ─────────────────────────────────────
click → Web action → Application              cron every 2 min → kf:worker:run
      → write local DB rows                        → drains queues (sync, provision,
      → enqueue work (status=queued/pending)         index, cleanup)
      → redirect (PRG)   [no OpenAI]                → calls Order58 + OpenAI
                                                    → flips rows to ready/failed
```

The **one exception** to "no OpenAI in the browser" is **chat**: answering a question calls OpenAI
synchronously inside the POST (the user is waiting for the answer). Everything else — provisioning, document
indexing, sync, cleanup — is deferred to the worker.

**Runtime layout:** uploaded/normalized files live under the configured storage root on disk; the worker's lock
(`DOCUMENT_WORKER_LOCK_PATH`) and logs live under `runtime/locks/` and `runtime/logs/`. Configuration is
env-driven (`.env` → `Environment::SPEC` → `config/common/params.php`); secrets stay in `.env` only.

---

## 3. The one rule: three **independent** switches

A store being "on" is **not** one thing — it is **three separate axes**. This is the single most important
idea, and it is exactly what the admin badges show.

| Axis | Column | Who sets it | Meaning |
|------|--------|-------------|---------|
| **Source active** | `knowledge_bases.source_active` (mirrors `order58_stores.active`) | **Order58** (via Sync) | Is the store active in Order58 itself? |
| **Agent enabled** | `knowledge_bases.agent_enabled` | **You, the admin** | Do you allow agents to use this store? |
| **KB ready** | `knowledge_bases.vector_store_status = ready` | **The system** | Is the OpenAI vector store provisioned? |

They do not affect each other. A store can be **Source active** but **Agent disabled**; it can be **Agent
enabled** but not **KB ready**; and so on. (There is also a fourth practical requirement for chatting: at least
one **usable document** — see §12.)

**An agent may chat with a store only when ALL of these are true:**

```
source_active = 1   AND   agent_enabled = 1   AND   vector_store_status = ready   AND   ≥1 usable document
```

`account_id` is **never** part of this — every active agent sees the same eligible stores.

---

## 4. What the admin badges mean

On **Order58 stores** (`/admin/order58/stores`) each card shows badges. Here is precisely what each means and
where it comes from:

- 🟢 **Source active** — Order58 says this store is active (`order58_stores.active = 1`, mirrored to
  `knowledge_bases.source_active = 1`). Set only by **Sync Stores**, from the Order58 `account.active` field.
- ⚪ **Source inactive** — Order58 says the store is inactive (`active = 0`). Its KB and history are kept, but it
  is hidden from agents. You cannot make it active from here — Order58 controls this.
- 🔵 **Agent enabled** — your local switch is on (`agent_enabled = 1`): agents are allowed to use this store
  (if it is also source-active + ready). New stores default to enabled.
- ⚪ **Agent disabled** — you turned agents off for this store (`agent_enabled = 0`). A normal sync will **never**
  change this back; only you can, with the **Enable agents** button. Use this to hide a store from agents even
  while it stays active in Order58.
- 🟢 **Ready** — the KB's OpenAI vector store is provisioned (`vector_store_status = ready`). It can hold and
  search documents.
- 🔵 **Provisioning pending** / 🔴 **failed** — the vector store has not been created yet (`pending` /
  `provisioning`) or creation errored (`failed`). The worker keeps trying pending ones.

The card also shows two counts:

- **N docs ready** — how many documents are indexed and enabled (these are what chat actually searches).
- **N knowledge** — how many Order58 knowledge records are mirrored for this store.

The card **filters** at the top map 1:1 to these axes: `Source active`, `Source inactive`, `Agent enabled`,
`Agent disabled`, `KB ready`, `KB pending/failed`.

> Why can a store be "Source active + Ready" but still not chat? Because it has **0 usable docs**. Ready means
> the *container* exists; you still need at least one **indexed document** inside it (and the synced store
> profile alone doesn't qualify — §12). That is the state of most synced stores until their documents are
> generated/indexed by the worker.

---

## 5. The database — table by table (all 16)

There are **16 tables**: 15 data tables + `migration` (Yii's schema-version log). Each entry below states **why
it exists**, its **key columns**, **when a row is inserted**, and **when it is updated**. Full column lists come
from the live schema; timestamps (`created_at` / `updated_at`) are on nearly every table and omitted from the
key-column lists for brevity.

Conventions used everywhere: booleans are stored as **`tinyint(1)`** (`0`/`1`); ids are **`bigint`**;
"fingerprint"/checksum columns are **`char(64)`** (SHA-256); several tables use a **STORED GENERATED** column
plus a UNIQUE index to enforce an invariant in the database itself (see the ⚙ notes).

### 5.1 `order58_stores` — the raw mirror of an Order58 store
**Why:** a local, credential-free copy of each Order58 `account`, so the app never depends on the live API to
render pages. Mirror-of-record for the **active** flag.
**Key columns:** `source_id` (UNIQUE, the Order58 store id — the stable identity), `name`, `company`,
`active` **tinyint(1)** (the *only* source of "active", copied from `account.active`), `snapshot_json` (curated
fields: address, city, phone, hours…), `sync_hash` **char(64)** (Order58's change fingerprint — if unchanged,
sync skips the record), `source_updated_at`, `synced_at`, `last_seen_sync_run_id` (used by mark-and-sweep to
deactivate stores that vanish from Order58).
**Inserted when:** Sync Stores discovers a new Order58 account.
**Updated when:** Sync Stores sees a changed `sync_hash`; reconcile-active repairs `active`; each sync stamps
`last_seen_sync_run_id`.

### 5.2 `knowledge_bases` — one per store (the heart)
**Why:** the thing the assistant answers from; owns the vector store and the source mapping.
**Key columns & statuses:**
- `slug` (UNIQUE) — URL id, e.g. `888-chinese`.
- `name`, `description`, `system_instructions` — extra guidance/prompt applied when answering (§11).
- `openai_vector_store_id` **varchar(64)** — the OpenAI vector store this KB owns (null until provisioned).
- `vector_store_status` **enum** — `pending` → `provisioning` → `ready` (or `failed`). *Is the container built?*
- `provision_attempts`, `provision_started_at`, `provision_next_attempt_at`, `vector_store_error_code`,
  `vector_store_error` — provisioning retry/backoff bookkeeping.
- `status` **enum** — `active` | `archived`. *Is the KB itself in use?* (archived = hidden everywhere.)
- `source_system` = `order58`, `source_store_id` **bigint unsigned** = the store's `source_id` — the mapping
  (UNIQUE together).
- `source_name`, `source_active` **tinyint** (mirror of the store's `active`, §3), `last_source_synced_at`.
- `agent_enabled` **tinyint, default 1** — your local override (§3). Sync never touches it.
- `last_indexed_at` — **note: a dead column on this table too**; readiness is derived from documents, not this.
**Inserted when:** Sync Stores first mirrors a store (one KB per store); also manual KB create (`kb.store`).
**Updated when:** sync (source fields), admin edit/archive/restore, provisioning drainer (status/vector-store
id), enable/disable agents (`order58.store.agent-access`).

### 5.3 `documents` — every piece of knowledge in a KB
**Why:** one row per uploaded file / manual text / generated record; the unit the worker indexes.
**Key columns & statuses:**
- `kind` **varchar** — `pdf` | `image` | `text` (how it is ingested).
- `source_type` **varchar(48)** — provenance/routing: `uploaded_pdf`, `uploaded_image`, `uploaded_text`,
  `manual_text`, `order58_store_profile`, `order58_knowledge`.
- `status` **enum** — the lifecycle: `uploaded` → `queued` → `processing` → `indexing` → `ready`
  (`failed` / `deleted` are the off-ramps). See §6.
- `is_enabled` **tinyint, default 1** — per-document on/off. A disabled document is kept but excluded from chat.
- `is_source_overridden` **tinyint** — for generated docs: an admin edited the body, so a resync must not
  clobber the local edit.
- `source_text` **mediumtext** — for **manual text**, the *original* text you typed (so you can edit it later).
- `source_ref`, `source_sync_hash` — link/fingerprint back to the Order58 record for generated docs.
- `checksum_sha256` **char(64)** + `dedupe_hash` (⚙ **STORED GENERATED**, UNIQUE per KB) — prevent the same
  content being added twice in one KB.
- `priority`, `processing_attempts`, `processing_started_at`, `next_attempt_at`, `error_code`, `error_message`,
  `processed_at`, `deleted_at` — worker scheduling / retry / soft-delete bookkeeping.
- `title`, `original_filename`, `stored_path`, `storage_token`, `mime_type`, `extension`, `size_bytes`,
  `last_indexed_at` (**dead column — never written**; use a completed `document_index_files` row instead).
**Inserted when:** upload, manual-text create, or sync generating a store-profile / knowledge document.
**Updated when:** the worker advances `status`; retry/reindex/process-now requeue; enable/disable toggles
`is_enabled`; edit changes `source_text`/checksum; delete sets `status=deleted` + `deleted_at`.

### 5.4 `document_index_files` — the bridge to OpenAI
**Why:** maps each document to its actual **file inside the OpenAI vector store**, and tracks index state. This
table (not `documents.status`) is the **durable "usable snapshot" signal** (§12).
**Key columns:**
- `document_id` — which document.
- `role` **enum** — `source` (the file that is searched) | `derived_markdown` (a converted copy, e.g. from a PDF).
- `derived_path` — on-disk path of the converted copy, if any.
- `openai_file_id` **varchar(64), UNIQUE** — the id OpenAI gives the uploaded file.
- `index_status` **enum** — `pending` → `in_progress` → `completed` (or `failed` / `cancelled`). OpenAI's view
  of indexing; when it is `completed`, the parent document can become `ready`.
- `usage_bytes`, `last_error_code`, `last_error_message`.
- `pending_removal` **tinyint** — flagged when a file must be detached from OpenAI (after an edit/disable/delete
  or requeue). The cleanup drainer removes a flagged file **only after** a completed replacement exists (or the
  document is deleted/disabled), so retrieval is never left empty (§9.3, the replacement guard).
**Inserted when:** the worker uploads a file to OpenAI during indexing.
**Updated when:** indexing progresses/polls; requeue/edit/disable flags `pending_removal`; cleanup deletes the
row after detaching from OpenAI.

### 5.5 `document_processing_events` — the audit trail
**Why:** an append-only log of what happened to each document, so you can see history without changing the
document row.
**Key columns:** `document_id`, `status` **varchar** (`queued`, `processing`, `ready`, `disabled`, `failed`,
`updated`…), `message`, `metadata_json`, `created_at`. **Append-only — never updated.**
**Inserted when:** any document state transition (upload, queue, process, ready, fail, enable/disable, edit).

### 5.6 `integration_sync_runs` — the sync job queue / state machine
**Why:** every "Sync Stores / Sync Knowledge / Sync Agents / Check connection / Rebuild" click becomes a row
here; the worker drains them.
**Key columns & statuses:**
- `type` **varchar** — `stores` | `knowledge` | `agents` | `knowledge_store` | `rebuild_store` | `health`.
- `scope_ref` — the store id for store-scoped runs (knowledge_store / rebuild_store).
- `status` **enum** — `pending` → `running` → `completed` | `completed_with_warnings` | `failed`.
- `attempts`, `claimed_at`, `started_at`, `completed_at`, `next_attempt_at`, `error_code`, `error_message`.
- `requested_by_admin_id`, `progress_json` (page cursor + counters: created/updated/unchanged/deactivated/…).
- `active_key` (⚙ **STORED GENERATED**, UNIQUE) — **coalescing**: a second click of the same operation while one
  is already pending/running is rejected as a duplicate, so you never double-run or double-load Order58.
**Inserted when:** an admin triggers any sync/health/rebuild action.
**Updated when:** the worker claims (`running`), advances `progress_json`, and finishes (`completed`/`failed`).

### 5.7 `order58_knowledge_records` — mirrored Order58 knowledge entries
**Why:** local copy of each Order58 knowledge article; each becomes an `order58_knowledge` **document** in the
owning KB.
**Key columns:** `source_id` (Order58 knowledge id), `store_source_id` (which store it belongs to), `title`,
`content` **text**, `knowledge_number`, `keyword`, `type`, `active` **tinyint**, `snapshot_json`,
`sync_hash` **char(64)**, `source_created_at`, `source_updated_at`, `synced_at`, `last_seen_sync_run_id`.
**Inserted when:** Sync Knowledge discovers a new record.
**Updated when:** its `sync_hash` changes; mark-and-sweep stamps `last_seen_sync_run_id`.

### 5.8 `order58_agents` — safe agent profiles (login gate)
**Why:** a credential-free mirror of Order58 users, used to **gate agent login** (only `user_type = agent` may
sign in to the agent panel) and to render the agent directory.
**Key columns:** `admin_id` **bigint unsigned** (the agent's Order58 identity — this is the `participant_id`
for agent chat threads), `username`, `first_name`, `last_name`, `email_address`, `contact_number`, `role`,
`status`, `user_type` **varchar** (`agent` gates login), `account_id`, `snapshot_json`,
`sync_hash`, `source_modified_at`, `synced_at`, `last_seen_sync_run_id`.
**Inserted when:** Sync Agents discovers a new user.
**Updated when:** its `sync_hash` changes; mark-and-sweep stamps `last_seen_sync_run_id`.
> Note: agents **authenticate against the live Order58 API** (`POST /authenticate`), not against a local
> password. This table holds only the safe profile + the `user_type` gate; no credentials are stored.

### 5.9 `knowledge_base_rules` — extra answering rules per KB
**Why:** admin-authored instructions layered on top of a KB's `system_instructions` when answering.
**Key columns:** `knowledge_base_id`, `name`, `instruction` **text**, `priority` **smallint unsigned** (order),
`is_enabled` **tinyint**.
**Inserted when:** admin adds a rule (`kb.rules.store`).
**Updated when:** edit / reorder / enable-disable (`kb.rules.update|reorder|toggle`); deleted on
`kb.rules.delete`.

### 5.10 `conversations` — one persistent thread per store + participant
**Why:** chat history is **not** a list of many "start conversation" threads. There is **exactly one** canonical
thread per Knowledge Base and logged-in participant.
**Key columns & uniqueness:**
- `knowledge_base_id` — which store/KB this thread belongs to (never switches).
- `participant_type` **varchar(16)** — `admin` | `agent`. Discriminator so numeric ids cannot collide.
- `participant_id` **bigint unsigned** — for `admin`: local `admin_users.id`; for `agent`: Order58 `admin_id`.
- **UNIQUE** `ux_conversations_kb_participant_typed` on `(knowledge_base_id, participant_type, participant_id)`.
- `agent_admin_id` — **legacy/compat only.** `NULL` for admin threads, agent id for agent threads. Ownership
  uses `participant_type` + `participant_id`, not this column alone.
- `title` — usually the KB/store name; `last_message_at` — sorts activity, recalculated when messages are added.
**Inserted when:** the **first POST** (send a question) find-or-creates the thread. **GET never inserts.**
**Updated when:** each new/edited message touches `last_message_at`.
> **Older design (removed):** a shared admin thread keyed on `COALESCE(agent_admin_id, 0)`. Migration
> `M260804120000TypedChatParticipants` backfilled shared admin rows to the sole active admin when exactly one
> existed; otherwise it refused to guess. It never deleted or merged messages.

### 5.11 `messages` — turns inside one conversation
**Why:** user + assistant turns for a single thread; the UI and OpenAI history both read from here.
**Key columns:**
- `conversation_id` — parent thread; `role` **enum** — `user` | `assistant`; `content` **text**.
- `citations_json`, `usage_json`, `is_grounded` **bit(1)**, `retrieval_status`, `openai_response_id`, `model`.
- **Message-editing columns (added `M260804130000`):**
  - `reply_to_message_id` **bigint, null** — on **assistant** rows, the user message this answer replies to.
  - `superseded_at` **datetime, null** — non-null = an outdated answer: **excluded from live view + OpenAI
    history**, kept for audit.
  - `edited_at` **datetime, null** — on **user** rows, the last edit time.
  - `edit_count` **smallint unsigned, default 0** — optimistic-lock version + audit counter.
  - `active_answer_key` (⚙ **STORED GENERATED** = `reply_to_message_id` when the row is an assistant answer and
    `superseded_at IS NULL`; else null) + UNIQUE `ux_messages_active_answer` → **at most one active answer per
    question**, enforced by the database.
- Ordered for display as `created_at ASC, id ASC`. The UI loads the newest ~40 and can load older via cursor
  (`before_message_id`).
**Inserted when:** a question is asked (user row) and answered (assistant row); a retry inserts a fresh
assistant row.
**Updated when:** an edit rewrites the user row's `content` (bumping `edit_count`, setting `edited_at`) and
stamps the old answer's `superseded_at`; `touch()` updates the parent conversation.
> OpenAI prompt history is **bounded separately** (`CHAT_HISTORY_MESSAGE_LIMIT` / `CHAT_HISTORY_CHAR_LIMIT`)
> and excludes superseded rows — a long thread does not send the whole lifetime history to the model.

### 5.12 `message_revisions` — prior versions of edited questions (audit)
**Why:** `messages.content` always holds the **latest** question text; this table preserves every **prior**
version for audit. Added by migration `M260804130000`.
**Key columns:** `message_id` (FK → `messages.id`, cascade), `revision_number` **int unsigned** (UNIQUE with
`message_id`), `content` **text** (the prior text being replaced), `edited_by_type` **varchar(16)**
(`admin`|`agent`), `edited_by_id` **bigint unsigned** (the editor's participant id), `created_at`.
**Inserted when:** a question is edited — the *outgoing* text is snapshotted here before `messages.content` is
overwritten. **Append-only.**

### 5.13 `ai_operations` — the OpenAI reliability ledger
**Why:** idempotency + reliability for every OpenAI-mutating call, so retries never double-create vector stores
or files, and in-flight/failed operations can be reconciled.
**Key columns:** `operation_key` **varchar(191)** (logical id of the operation), `type`, `subject_type` +
`subject_id` (what it acts on), `status` **enum** (`pending` → `in_flight` → `succeeded` |
`needs_reconcile` | `failed`), `request_fingerprint` **char(64)**, `idempotency_key` **char(36)** (sent to
OpenAI), `result_id` (e.g. the vector-store / file id returned), `attempts`, `next_attempt_at`,
`last_error_code`, `last_error_message`, `started_at`, `completed_at`.
**Inserted when:** the worker begins a reliable OpenAI operation (provision, index, remove).
**Updated when:** the operation moves through in-flight → succeeded/failed/needs-reconcile.

### 5.14 `admin_users` — local admin accounts
**Why:** the KF admin panel's own accounts (separate identity space from Order58 agents).
**Key columns:** `username` (UNIQUE), `password_hash` **varchar(255)**, `is_active` **tinyint**,
`last_login_at`.
**Inserted when:** an admin account is provisioned (seed/console). **Updated when:** login stamps
`last_login_at`; account edits.
> `admin_users.id` is the `participant_id` for **admin** chat threads. It is a **different numeric space** from
> Order58 `admin_id` — hence `participant_type` disambiguates (§5.10).

### 5.15 `auth_login_attempts` — admin login throttling
**Why:** brute-force protection for the admin login (rate-limit + lockout).
**Key columns:** `attempt_key` **char(64)** (PK — hashed identifier of the attempt bucket), `attempts`
**smallint unsigned**, `window_started_at`, `locked_until`, `updated_at`.
**Inserted/updated when:** each admin login attempt increments the bucket; a lockout sets `locked_until`.

### 5.16 `migration` — schema version log
`migration` — **Yii's own schema-version log** (one row per applied migration class). Never edited by hand;
`php yii migrate:up` appends to it. The full history is listed in §14.4 / the `src/Migration/` folder.

---

## 6. Status glossary (all of them, in one place)

| Where | Values | Plain meaning |
|-------|--------|---------------|
| `knowledge_bases.vector_store_status` | pending, provisioning, ready, failed | Is the OpenAI container built? |
| `knowledge_bases.status` | active, archived | Is the KB in use or hidden? |
| `knowledge_bases.source_active` | 0 / 1 | Order58 says the store is active? |
| `knowledge_bases.agent_enabled` | 0 / 1 | Admin allows agents? |
| `documents.status` | uploaded, queued, processing, indexing, ready, failed, deleted | Document lifecycle |
| `documents.is_enabled` | 0 / 1 | Document counted for chat? |
| `document_index_files.index_status` | pending, in_progress, completed, failed, cancelled | OpenAI's indexing of one file |
| `document_index_files.role` | source, derived_markdown | Searched file vs. converted copy |
| `document_index_files.pending_removal` | 0 / 1 | File flagged to detach from OpenAI (guarded, §9.3) |
| `integration_sync_runs.status` | pending, running, completed, completed_with_warnings, failed | Sync job state |
| `ai_operations.status` | pending, in_flight, succeeded, needs_reconcile, failed | OpenAI operation ledger |
| `messages.role` | user, assistant | Who spoke |
| `messages.superseded_at` | null / datetime | Answer is current / outdated-hidden-but-audited (§13) |

**Document lifecycle in detail:** `uploaded` (bytes captured, not queued) → `queued` (waiting for the worker) →
`processing` (worker extracting/normalizing) → `indexing` (uploaded to OpenAI, waiting for the vector store to
finish) → `ready` (searchable ✅). Off-ramps: `failed` (see `error_message`; will retry / can be retried) and
`deleted` (soft-deleted, ignored everywhere).

---

## 7. Which action writes which table (effect matrix)

"Fast path" = happens in the browser request (local rows only, no OpenAI). "Worker" = deferred to
`kf:worker:run`. Routes are the names in `config/common/routes.php`.

| Admin/agent action (route) | Fast-path writes | Worker then writes | External call |
|----------------------------|------------------|--------------------|---------------|
| **Sync Stores** (`order58.sync`, type=stores) | `integration_sync_runs` (+`active_key` dedupe) | `order58_stores`, `knowledge_bases` (KB per store), `documents` (store-profile), `document_processing_events` | Order58 `GET /accounts` |
| **Sync Knowledge** (`kb.sync-order58-knowledge`) | `integration_sync_runs` | `order58_knowledge_records`, `documents` (order58_knowledge), events | Order58 `GET /knowledge` |
| **Sync Agents** (`order58.sync`, type=agents) | `integration_sync_runs` | `order58_agents` | Order58 `GET /agents` |
| **Check connection** (`order58.check`) | `integration_sync_runs` (type=health) | — | Order58 `GET /health` |
| **Rebuild store** (`order58.store.rebuild`) | `integration_sync_runs` (type=rebuild_store) | re-derives KB + documents for one store | Order58 |
| **Enable/disable agents** (`order58.store.agent-access`) | `knowledge_bases.agent_enabled` | — | — |
| **Upload file** (`kb.documents.upload`) | `documents` (uploaded_*), file on disk, event `queued` | `document_index_files`, status→ready, `ai_operations` | OpenAI Files + Vector Stores (worker) |
| **Create manual text** (`kb.manual-text.create`) | `documents` (manual_text + `source_text`), disk file, event | `document_index_files`, ready, `ai_operations` | OpenAI (worker) |
| **Edit manual text** (`kb.documents.edit`) | `documents` (source_text/checksum), flag old file `pending_removal`, event | new `document_index_files`, ready; cleanup deletes old | OpenAI (worker) |
| **Enable/disable document** (`kb.documents.toggle`) | `documents.is_enabled`, flag `pending_removal` (disable), event | requeue+index (enable) / cleanup (disable) | OpenAI (worker) |
| **Retry / Reindex / Process-now** (`kb.documents.retry|reindex|process-now`) | `documents.status=queued`/priority, flag old file, event | re-index → ready | OpenAI (worker) |
| **Delete document** (`kb.documents.delete`) | `documents.status=deleted`+`deleted_at`, flag file | cleanup detaches OpenAI file | OpenAI (worker) |
| **Create/edit/archive KB** (`kb.store|update|archive|restore`) | `knowledge_bases` | provisioning drainer builds vector store | OpenAI (worker) |
| **KB rules** (`kb.rules.*`) | `knowledge_base_rules` | — | — |
| **Ask a question** (`chat.start` / `agent.chat.start`) | `conversations` (find-or-create), `messages` (user + assistant) | — | OpenAI **Responses** (synchronous, in request) |
| **Edit a question** (`chat.message.edit` / `agent.…`) | `messages` (rewrite user, supersede old answer, insert new answer), `message_revisions` | — | OpenAI Responses (synchronous) |
| **Retry regeneration** (`chat.message.regenerate` / `agent.…`) | `messages` (insert answer if none active) | — | OpenAI Responses (synchronous) |
| **Admin login** (`auth.login`) | `auth_login_attempts`, `admin_users.last_login_at` | — | — |
| **Agent login** (`agent.login`) | (session only; gate reads `order58_agents.user_type`) | — | Order58 `POST /authenticate` |
| **Load older messages** (`chat.history` / `agent.chat.history`) | — (read only) | — | — |

---

## 8. Who does the work: the background worker

Nothing slow runs in the browser. A single command drains queues:

```
php yii kf:worker:run
```

It is run automatically by **cron every 2 minutes** (with `flock` so runs never overlap). Each pass runs four
**drainers in this order**:

1. **IntegrationSyncDrainer** — executes one `integration_sync_runs` job (Sync Stores/Knowledge/Agents): pages
   through Order58, updates the mirror tables, and creates generated documents.
2. **KnowledgeBaseProvisioningDrainer** — for each KB that is `pending` (and eligible: source-active + agent-
   enabled), creates its **OpenAI vector store** → `vector_store_status = ready`. Wrapped in an `ai_operations`
   ledger entry for idempotency.
3. **DocumentProcessingDrainer** — takes `queued` documents and indexes them into the vector store (the main
   flow in §9). Batch size = `DOCUMENT_WORKER_BATCH_SIZE`.
4. **RemoteCleanupDrainer** — deletes OpenAI files flagged `pending_removal` — but **only** once a completed
   replacement exists or the document is deleted/disabled (§9.3, the replacement guard).

> **Cron lock gotcha:** the flock must use a **dedicated** lock file, *not* the app's runtime worker lock, or
> every run skips. See the cron line in §15.

---

## 9. Step by step: upload a `.txt` / manual text → OpenAI

Both a `.txt`/`.md` **upload** and **manual text** end up as a `kind = text` document and follow the same
indexing path; only the first step differs.

### 9.1 What happens in the browser (fast, no OpenAI)

**A) Manual text** (admin types Title + Content):
1. Validate: title required (≤200 chars), content valid UTF-8, non-empty, ≤ `CHAT_MAX_QUESTION_LENGTH`-style
   text limit.
2. **Normalize** the content deterministically (strip BOM, unify line endings to `\n`, trim, collapse blank
   lines, one trailing newline). Same text always produces the same bytes → same checksum.
3. **Dedupe**: `checksum_sha256` of the normalized text is checked within the KB (via `dedupe_hash`); a
   duplicate is rejected.
4. Store two things: the **original** text in `documents.source_text` (so you can edit it later) and the
   **normalized** text as a file on disk (this is what gets indexed).
5. Insert a `documents` row: `kind = text`, `source_type = manual_text`, `status = queued`, `is_enabled = 1`.
6. Return immediately and log a `queued` event in `document_processing_events`. **No OpenAI call yet.**

**B) `.txt` / `.md` upload** — identical, except step 1–2 read the uploaded file, reject non-UTF-8/binary, and
set `source_type = uploaded_text`. Markdown is treated as **plain text, never rendered as HTML**.

### 9.2 What the worker does (the OpenAI + vector store part)

On its next pass (≤2 minutes), **DocumentProcessingDrainer** picks up the `queued` document:

1. Mark `status = processing`; record a `processing` event.
2. The **TextDocumentProcessor** produces the indexable content: it reads the stored **normalized text** (no AI
   needed for text — unlike PDFs/images which need extraction/vision).
3. **Upload to OpenAI**: the text is uploaded as a **file** (OpenAI **Files API**); OpenAI returns an
   `openai_file_id`. A `document_index_files` row is written (`role = source`, `index_status = pending`). The
   call is wrapped in an `ai_operations` ledger entry.
4. **Attach to the KB's vector store** (`knowledge_bases.openai_vector_store_id`). Mark `status = indexing` and
   `index_status = in_progress`.
5. **Poll** OpenAI (interval `OPENAI_INDEX_POLL_INTERVAL_SECONDS`, cap `OPENAI_INDEX_POLL_MAX_SECONDS`) until
   the file's indexing is `completed` (or `failed`).
6. On success: `index_status = completed`, document `status = ready`, a `ready` event logged. The document is
   now searchable. ✅
7. On a transient error: `status = failed` with a backoff (`DOCUMENT_RETRY_BASE_SECONDS`,
   `DOCUMENT_MAX_PROCESSING_ATTEMPTS`); the worker retries later. Permanent errors keep `failed` with an
   `error_message`.

```
[browser]  type/upload  ──>  documents(status=queued, kind=text)          (instant, no OpenAI)
                                     │
[worker]   processing  ──>  read normalized text
                                     │
           upload file  ──>  OpenAI file  ──>  document_index_files(role=source, openai_file_id, pending)
                                     │
           attach       ──>  KB vector store   (status=indexing / in_progress)
                                     │
           poll         ──>  completed  ──>  documents(status=ready)   ✅  → "N docs ready" +1
```

### 9.3 Editing / disabling / re-indexing — the replacement guard
- **Unchanged edit** (only the title, or cosmetic whitespace): metadata updated, **no re-index**, no OpenAI cost.
- **Changed edit / reindex / retry / enable:** the document is re-queued and the **old** `document_index_files`
  row is flagged `pending_removal`. Crucially, the **RemoteCleanupDrainer keeps the old file** until a
  **completed** replacement exists (or the doc is deleted/disabled). It never treats "no incomplete replacement
  exists" as "a completed replacement exists" — so with a batch size of 1, the KB is never briefly left without
  a usable copy.
- **Disable** (`is_enabled = 0`): the file is flagged for removal and the document stops counting toward
  readiness; the row, text and history are preserved. **A resync/reindex never re-enables an admin-disabled
  document.**
- **Delete**: `status = deleted` + `deleted_at`; the file becomes immediately eligible for cleanup.

### 9.4 PDF / image (for completeness)
Same pipeline, different preparation: a **PDF** has its text extracted (and, if it is a scan with little text,
pages are read with vision using `OPENAI_VISION_MODEL`); an **image** is described with vision. The result
becomes a `derived_markdown` file that is uploaded and indexed exactly like text. Every document type converges
on "a file inside the vector store".

---

## 10. Step by step: how a store becomes a KB (sync)

1. Admin clicks **Sync Stores** on Data Management → an `integration_sync_runs` row (`type=stores`,
   `status=pending`). A duplicate click is coalesced by `active_key`.
2. The worker's IntegrationSyncDrainer pages through Order58 `GET /accounts`:
   - New/changed store → upsert `order58_stores` (incl. `active` from `account.active`), ensure **one**
     `knowledge_bases` row (`source_system=order58`), and (re)generate its store-profile document.
   - Unchanged store (`sync_hash` matches) → only "marked seen" (`last_seen_sync_run_id`); nothing rewritten.
   - A new **active** store's KB is created `pending` and gets provisioned; an **inactive** store's KB is created
     but **not** provisioned until it becomes active.
3. **Sync Knowledge** does the same for `GET /knowledge` records → `order58_knowledge` documents in the owning
   KB.
4. Provisioning drainer builds each eligible KB's vector store → `ready`.
5. Document processing drainer indexes the generated documents → `ready` → the store now shows "N docs ready".

> Important nuance (the reason the store list once showed "0 active"): the **active** flag lives in
> `order58_stores.active` and is set only from `account.active`. It is never guessed from `account_id`, `demo`,
> `host`, knowledge count or vector-store state. If a mirror row's `active` was ever written wrong, run
> `php yii kf:order58:reconcile-active` to re-derive it from the stored snapshot (safe, idempotent).

---

## 11. Step by step: chatting with a store

Chat is a **WhatsApp-style single persistent thread** per store and logged-in participant — not a list of
many conversations you must "Start" each time.

### 11.1 Who gets which thread

```
Store / Knowledge Base A
  ├── Admin 1 thread   (participant_type=admin,  participant_id=1)
  ├── Admin 2 thread   (participant_type=admin,  participant_id=2)   ← separate history
  ├── Agent 10 thread  (participant_type=agent,  participant_id=10)
  └── Agent 20 thread  (participant_type=agent,  participant_id=20)

Store / Knowledge Base B
  ├── Admin 1 thread   (different row from Admin 1 on store A)
  └── Agent 10 thread  (different row from Agent 10 on store A)
```

Isolation rules (always enforced in lookup and history):

- Store A admin ≠ Store B admin (different `knowledge_base_id`).
- Store A admin ≠ Store A agent (different `participant_type`).
- Store A agent 10 ≠ Store A agent 20 (different `participant_id`).
- Admin id `1` ≠ Agent id `1` (type separates them).
- Opening another participant's `conversationId` URL → **404** (never redirects into their history).

### 11.2 Opening chat (GET — lookup only, no insert)

1. Admin: **Store chat** (`/admin/order58/store-chat`) or the store card's chat icon →
   `GET /knowledge-bases/{slug}/chat`. Agent: `GET /agent/stores/{slug}/chat`.
2. You need a **chat-available** KB (§12): provisioned **and** ≥1 usable qualifying document. Otherwise the
   composer is blocked with an explanation and history is read-only.
3. The app resolves the participant server-side:
   - Admin → `CurrentAdmin` → `ChatParticipant::admin(admin_users.id)`.
   - Agent → `CurrentAgent` → `ChatParticipant::agent(Order58 admin_id)`.
4. It **looks up** the canonical conversation. If none exists yet → empty state ("Ask your first question").
   **No `conversations` row is created on GET** (browsing alone never leaves empty threads).
5. If a thread exists → load the newest ~40 messages (superseded answers excluded); show **Load older
   messages** when more exist.
6. Header shows the **store / KB name**. Newest messages are at the bottom; page scrolls there on load.

### 11.3 Sending a message (POST — find or create, then answer)

1. Composer POSTs to the same slug URL (`chat.start` / `agent.chat.start`). Transport is normal **POST +
   redirect (PRG)** — no WebSockets / SSE. The UI may show "Sending…" / "Thinking…" while waiting.
2. The availability policy is re-checked **server-side** (a direct POST to an unavailable KB is rejected, not
   just hidden in the UI).
3. **findOrCreate** the canonical thread for `(knowledge_base_id, participant_type, participant_id)`:
   - Insert if missing.
   - If two requests race, the unique index makes one win; the other catches the duplicate-key error,
     **re-selects** the existing row, and continues — the user never sees a race error.
4. Save the user message → call OpenAI **Responses API with File Search** on **this KB's vector store only** →
   verify grounding → save the assistant message (citations, grounding, usage, `reply_to_message_id`) on the
   **same** conversation.
5. Redirect back to the slug chat page (same persistent thread). Further questions always reuse it.

Legacy URLs with `{conversationId}` still work for bookmarks: if the id belongs to the **current** participant
and KB → redirect to the slug chat; otherwise → **404**.

### 11.4 History pagination vs OpenAI context (do not confuse them)

| Layer | Behaviour |
|-------|-----------|
| **UI** | Newest 40 messages; "Load older" uses a stable cursor `(created_at, id)` via `/chat/history`. Superseded answers hidden. |
| **OpenAI** | Only a **bounded** recent window (`CHAT_HISTORY_MESSAGE_LIMIT` / `CHAT_HISTORY_CHAR_LIMIT`) is sent, **excluding superseded turns**. A long thread does **not** grow prompt cost forever. |

Grounding: the answer is verified against the retrieved citations; `CHAT_MIN_CITATION_SCORE`,
`CHAT_REQUIRE_CITATIONS`, and `CHAT_FALLBACK_MESSAGE` control when the assistant answers vs. declines.

### 11.5 Ops / migration notes (typed participants)

```bash
php yii chat:participant-backfill-report --write=default   # before migrate on a new environment
php yii migrate:up                                         # applies M260804120000TypedChatParticipants
```

- Prefer a **mysqldump** before migrate. `migrate:down` only restores the old unique shape when each KB has at
  most one admin thread; if multiple admins already have separate threads, down **aborts** and you restore from
  the dump. It never deletes or merges messages.

---

## 12. Chat availability policy (when the composer is on/off)

**Canonical rule (identical for admin and agent):** chat is available for a KB only when it is **provisioned**
**and** has at least one **usable qualifying document**.

- **"Usable" = a completed snapshot exists**, i.e. a `document_index_files` row with
  `index_status = 'completed'` and a non-null `openai_file_id`, for a document that is `is_enabled = 1` and not
  `deleted`. Availability is read from **this durable signal, not `documents.status`** — because a resync
  mutates the same document row in place (`reindex()` → `status = queued`), and reading `status` would make chat
  **flicker unavailable** during every refresh. Reading the last completed index file keeps chat available
  across refreshes.
- **The synced store profile never counts.** A document with `source_type = 'order58_store_profile'` is
  explicitly **excluded** from the qualifying set. Order58-linked KBs therefore need a *real* document
  (`order58_knowledge`, an upload, or manual text) — the auto-generated profile alone does not enable chat.
  For a non-Order58 KB, any usable enabled document qualifies.
- **Where it is enforced:** the single source of truth is `Chat/Application/ChatAvailabilityPolicy`
  (`isAvailable`, `getUnavailableReason`, `assertAvailable`). It is consulted in:
  - the admin/agent chat index actions (to render the composer or an explanation),
  - the KB **Show** page ("Open chat" is a disabled button with a reason when unavailable),
  - `AskKnowledgeBaseService::assertChatAvailable()` — so a **direct POST bypass is rejected server-side**.
- **Unavailable reasons** (`ChatUnavailableReason`): `NotProvisioned` (no vector store yet), `Order58NotReady`
  (Order58-linked but missing the profile/qualifying combination), `NoQualifyingDocument` (provisioned but no
  usable doc), and `Available`.
- **When unavailable:** history is **read-only** (past turns still render) and the composer is disabled with an
  explanation. Enabling one qualifying document (upload / manual text / synced knowledge that indexes to
  `completed`) turns chat on.

---

## 13. Editing a chat question (revision audit + regeneration)

Admins and agents can **edit the latest question they asked** and have the answer **regenerated** from the
corrected text. Prior versions are preserved for audit; superseded answers are hidden but never deleted.

**Rules:**
- Only the **latest user question** in a thread is editable; assistant messages are never editable.
- A **20-minute window** (`CHAT_EDIT_WINDOW_MINUTES`, default 20) from the question's original `created_at`,
  measured on the server clock.
- Regeneration is **synchronous** (reuses the normal OpenAI Responses + File Search flow), excluding superseded
  turns from the history sent to the model.

**What happens on edit (`chat.message.edit` / `agent.chat.message.edit`):**
1. Availability + ownership + "is this the latest user turn?" + within-window checks (server-side; failures map
   to 404 for forged ids, 409 for stale/conflicting edits, and flashes for window-expired / unchanged).
2. In one DB transaction: snapshot the outgoing text into `message_revisions`, rewrite `messages.content`
   (bump `edit_count`, set `edited_at`), and stamp the old answer's `superseded_at` (it becomes hidden but
   audited).
3. Outside the transaction: regenerate the answer and insert a **new** assistant row linked via
   `reply_to_message_id`. The UNIQUE `ux_messages_active_answer` guarantees **exactly one active answer** per
   question; a duplicate-key race is caught narrowly and resolved by re-reading the active answer.
4. If OpenAI fails during regeneration: the edit is **not rolled back** (question saved, old answer superseded);
   the turn is left with **no active answer**, the composer is disabled, and a **"Regeneration failed — Retry"**
   action appears (`chat.message.regenerate`). Retry is idempotent and allowed past the 20-minute window.

**Server-side guard:** a thread whose latest user message has **no active (non-superseded) answer** blocks a
brand-new question until it is answered/retried — so a failed edit can never strand an unanswered turn behind a
newer one.

**Audit:** superseded answers and all `message_revisions` remain queryable via dedicated audit accessors
(`findAllByConversationIncludingSuperseded`, `findByMessage`) even though they are hidden from the live UI and
from OpenAI history.

---

## 14. External APIs — Order58 & OpenAI

Knowledge Forge talks to exactly two external services. All calls are made from the **worker** (sync,
provisioning, indexing, cleanup) or, for **agent login** and **chat answering**, inside the web request.

### 14.1 Order58 REST API
Base URL `ORDER58_API_BASE_URL`; bearer token `ORDER58_API_TOKEN` (never logged). Tunables:
`ORDER58_API_PAGE_SIZE`, `ORDER58_API_TIMEOUT_SECONDS`, `ORDER58_API_CONNECT_TIMEOUT_SECONDS`,
`ORDER58_API_MAX_RETRIES`, `ORDER58_API_RETRY_MAX_BACKOFF_SECONDS`, `ORDER58_SYNC_PAGES_PER_RUN`,
`ORDER58_SYNC_MAX_ATTEMPTS`. Client: `Order58/Client/HttpOrder58Client` (port
`Order58/Contract/Order58ClientInterface`).

| Method (client) | HTTP | Purpose | Feeds table |
|-----------------|------|---------|-------------|
| `health()` | `GET /health` | connection check | `integration_sync_runs` (type=health) |
| `listAccounts(page, perPage)` | `GET /accounts?page=&per_page=` | page through stores | `order58_stores` → `knowledge_bases` |
| `getAccount(id)` | `GET /accounts/{id}` | one store's detail | `order58_stores` |
| `listAgents(page, perPage)` | `GET /agents?page=&per_page=` | page through users | `order58_agents` |
| `listKnowledge(storeId, page, perPage)` | `GET /knowledge?page=&per_page=&store_id=` | page through knowledge records | `order58_knowledge_records` → `documents` |
| `getKnowledgeRecord(id)` | `GET /knowledge/{id}` | one knowledge record | `order58_knowledge_records` |
| `authenticate(username, password)` | `POST /authenticate` | **agent login** (live credential check; password in JSON body only, never logged) | — (session; gated by `order58_agents.user_type`) |

Pagination + change detection: each list is paged; each record carries a `sync_hash` so unchanged records are
skipped, and a mark-and-sweep (`last_seen_sync_run_id`) deactivates records that vanish upstream.

### 14.2 OpenAI API
Base URL `OPENAI_BASE_URL`; key `OPENAI_API_KEY` (admin ops may use `OPENAI_ADMIN_API_KEY`; never logged).
Models: `OPENAI_CHAT_MODEL` (answering), `OPENAI_VISION_MODEL` (PDF/image extraction). Reliability tunables
exist for both the worker and chat paths (`OPENAI_WORKER_*`, `OPENAI_CHAT_*`, `OPENAI_INDEX_POLL_*`,
`OPENAI_FILE_SEARCH_MAX_RESULTS`). Clients: `Ai/OpenAi/Client/HttpOpenAiClient`, adapters
`OpenAiKnowledgeIndex` (port `Ai/Contract/KnowledgeIndexInterface`) and `OpenAiChatCompletionProvider`
(port `ChatCompletionProviderInterface`).

**Files API** — upload the document's text/derived-markdown as a file; OpenAI returns the `openai_file_id`
stored in `document_index_files`.

**Vector Stores API** — `createStore` (one per KB, id stored in `knowledge_bases.openai_vector_store_id`),
attach a file to the store, poll the file's index state, detach/remove a file, delete a store. These back the
`KnowledgeIndexInterface` methods `createStore` / `indexContent` / `fileState` / `removeFile` / `deleteStore`.

**Responses API (File Search)** — `ask()` sends the bounded, non-superseded history plus the question with a
**`file_search` tool bound to the KB's single vector store**, so retrieval is scoped to that store only. The
response's citations and usage are stored on the assistant `messages` row; grounding is verified before the
answer is shown.

**Reliability ledger:** every mutating OpenAI call (provision, index, remove) is wrapped in an `ai_operations`
row with an `idempotency_key` and `request_fingerprint`, so a retry never double-creates a store or file, and a
crash mid-flight leaves a `needs_reconcile` record instead of orphaned remote state.

### 14.3 Internal HTTP routes (for reference)
Admin panel routes are grouped under the admin prefix; agent panel routes under `/agent`. The full route-name
list lives in `config/common/routes.php` — key groups: `kb.*` (knowledge base + documents + rules), `order58.*`
(sync + stores + agents + store chat), `chat.*` and `agent.chat.*` (chat + message edit/regenerate + history),
`ai.usage.*` (OpenAI usage dashboard), `auth.*` / `agent.login` (auth). The §7 matrix maps the important ones
to their table effects.

### 14.4 Schema history (migrations)
Applied in order (`src/Migration/`, logged in the `migration` table):

```
M260724100000CreateAdminUsers            M260728120100CreateIntegrationSyncRuns
M260724100100CreateKnowledgeBases        M260728120200AddKnowledgeBaseSourceColumns
M260724100200CreateKnowledgeBaseRules    M260728120300AddDocumentSourceColumns
M260724100300CreateAuthLoginAttempts     M260728130000AddConversationAgent
M260725120000CreateDocuments             M260728140000AddDocumentSourceText
M260725140000CreateAiOperations          M260803120000AddDocumentSourceOverride
M260726090000AddIndexFileRemovalFlag     M260803160000CanonicalChatThreads
M260727100000CreateConversations         M260804120000TypedChatParticipants
M260728120000CreateOrder58Mirrors        M260804130000AddMessageEditingAndRevisions
```

The last one (`M260804130000AddMessageEditingAndRevisions`) adds the message-editing columns + `active_answer_key`
unique index + `message_revisions`, and is **preflight-gated**: it aborts before any DDL if the existing data
has an assistant with no preceding user turn, or a question with more than one active answer — protecting
production data.

---

## 15. Command & cron reference

```bash
php yii kf:worker:run                 # drain sync + provisioning + document indexing + cleanup (one pass)
php yii kf:order58:reconcile-active   # repair store active status from the local snapshot (safe, idempotent)
php yii kf:health                     # config / DB / storage / migrations check
php yii migrate:up                    # apply pending migrations (preflight-gated where relevant)
php yii chat:participant-backfill-report --write=default   # pre-migration report for typed chat participants
```

Cron (every 2 minutes, non-overlapping — note the **dedicated** lock file, not the app's runtime worker lock):

```
*/2 * * * * /usr/bin/flock -n /var/www/html/knowledge-forge/runtime/locks/cron-worker.lock /bin/sh -c 'cd /var/www/html/knowledge-forge && /usr/bin/php yii kf:worker:run >> /var/www/html/knowledge-forge/runtime/logs/worker.log 2>&1'
```

Watch progress: `tail -f runtime/logs/worker.log`.

---

## 16. Quick answers (cheat sheet)

- **"Source active vs inactive?"** → Order58's own on/off for the store (`account.active`). You cannot change it
  here; Sync copies it.
- **"Agent enabled vs disabled?"** → Your local switch for whether agents may use the store. Survives syncs.
- **"KB ready vs pending/failed?"** → Whether the OpenAI vector store (the searchable container) is built.
- **"Why 'Ready' but can't chat?"** → 0 **usable** documents yet. Ready = container exists; you still need a
  real indexed doc, and the synced store *profile* alone does not count (§12).
- **"I typed manual text — when can I chat?"** → After the next worker pass indexes it (`queued → processing →
  indexing → ready`, usually within ~2 minutes, given a valid OpenAI key).
- **"Where does the original text live?"** → `documents.source_text` (original) + a normalized file on disk (the
  indexed copy). The link to OpenAI is `document_index_files.openai_file_id`.
- **"Who can an agent chat with?"** → `source_active = 1 AND agent_enabled = 1 AND vector_store_status = ready
  AND ≥1 usable document`. `account_id` never matters.
- **"Do all admins share one chat per store?"** → **No.** Each admin has their own persistent thread
  (`participant_type=admin`, `participant_id=admin_users.id`). Agents likewise each have their own
  (`participant_type=agent`, Order58 `admin_id`).
- **"Does opening chat create a conversation?"** → **No.** GET only looks up. The first **POST** (send a
  question) find-or-creates the canonical thread.
- **"Can admin 1 and agent 1 collide?"** → **No.** Ownership is `(type, id)`, not a single number.
- **"Is chat real-time / sockets?"** → **No.** Normal form POST → OpenAI → redirect. Progressive
  "Sending… / Thinking…" UI only.
- **"Can I edit a question I just asked?"** → Yes — the **latest** one, within 20 minutes; the answer
  regenerates and the old version is kept for audit (§13).
- **"Which table is the source of truth for 'chat available'?"** → a **completed** `document_index_files` row
  (not `documents.status`), so refreshes don't flicker chat off (§12).
- **"How does an agent log in?"** → against the **live Order58 API** (`POST /authenticate`), gated locally by
  `order58_agents.user_type = agent`. No agent password is stored here.

---

## 17. Order58 Rules (sync → classify → materialize → chat fallback)

Order58 has a global **Rules** list (store-agnostic guidance). Knowledge Forge mirrors it, deduplicates it into a
**canonical catalog**, classifies each rule (store-specific vs common vs unresolved), materializes only **confirmed**
rules into searchable documents, and answers chat **store-first, common-rules-second, fallback-third**. Built in five
additive phases; nothing here changes existing store/knowledge/agent behavior.

### 17.1 New tables
- **`order58_rule_records`** — raw mirror of the Rules API (one row per upstream rule; `source_id` UNIQUE,
  `sync_hash`, `snapshot_json`, `is_active` soft-delete, `source_store_id` nullable/future). `title` is `TEXT`
  (rule "titles" can be long free text).
- **`rule_catalog_rules`** — canonical (deduplicated) rules. Identity = `canonical_hash` =
  **SHA-256(normalized_title + "\0" + normalized_description)** (UNIQUE). `description_hash` groups *possible*
  duplicates for review only. Carries `scope_type` (`common`/`store_specific`/`unresolved`) + `classification_status`.
- **`rule_catalog_sources`** — audit link from every raw record to exactly one canonical (`primary`/`exact_duplicate`);
  `order58_rule_record_id` UNIQUE.
- **`order58_store_aliases`** — store name/company/domain aliases for matching; UNIQUE(`store_source_id`,`normalized_alias`).
- **`rule_store_links`** — canonical↔store links with `match_status` (`suggested`/`confirmed`/`rejected`) +
  `match_method`; UNIQUE(`rule_catalog_rule_id`,`store_source_id`).
- **`rule_classification_events`** — append-only classification/review audit.

All foreign keys between these are **RESTRICT** (audit rows are only soft-deactivated, never physically deleted).
Additive columns: **`knowledge_bases.purpose`** (`store` default / `shared_rules` for the hidden base) and
**`messages.answer_source`** (chat-source audit). New `documents.source_type` values `order58_rule_store` /
`order58_rule_common` (VARCHAR — no migration). Migrations: `M260805100000`, `100100`, `100200` (widen title),
`110000`, `120000` (purpose), `130000` (answer_source).

### 17.2 Statuses
- `classification_status`: `pending` → `auto_matched` / `manually_matched` / `suggested_common` / `confirmed_common`
  / `ambiguous` / `unmatched` / `ignored`. **Only an admin sets `confirmed_common`** — a heuristic can only *suggest*.
- `rule_store_links.match_status`: `suggested` (fuzzy) / `confirmed` (exact or admin) / `rejected`.
- `messages.answer_source`: `store_knowledge` / `store_rule` / `common_rule` / `fallback`.

### 17.3 Rules API (reused Order58 client/auth)
- `GET /rules?page=&per_page=` — paginated list (the only endpoint the sync uses; `per_page=100`).
- `GET /rules/{id}` — one rule; **reserved for targeted refresh/diagnostics**, never used in a normal full sync.
Pagination follows the response's `total_pages`; the existing capped backoff (honoring `Retry-After`), `pagesPerRun`
yield, and flock serialization keep the sync gentle on the upstream server.

### 17.4 Sync + worker (`type = rules`)
Admin **Data Management → Sync Rules** enqueues an `integration_sync_runs` row (coalesced by `active_key`). The
worker's `IntegrationSyncDrainer` dispatches it to `RulesSyncHandler`, which: pages the list → mirrors each record
(`_sync_hash` skip) → links it to a canonical (exact dedupe) → **classifies** (deterministic matching) → **materializes**
confirmed rules → mark-and-sweep **only after the final page**. Idempotent: a second identical sync creates no new rows.

### 17.5 Dedupe policy
Two raw rules collapse into one canonical rule **only when both** their normalized title **and** description match
(never by title alone — several "Moon Temple" rules with different bodies stay separate). Description-only matches are
surfaced as *possible duplicates* for review, never auto-merged. Every raw row is preserved and audit-linked. An
upstream content change re-links the same source to a new canonical (never a duplicate) and recomputes active flags.

### 17.6 Store matching (strict priority)
1. `source_store_id` (future authoritative id) — highest confidence. 2. exact **domain** alias. 3. exact **title**
alias (whole-word). 4. exact **description** alias. 5. **fuzzy** → *suggestion only*, never auto-confirmed. Multiple
exact stores → **ambiguous**. An apparent-but-unknown store name → **unmatched** (never "common"). A future
`source_store_id` that conflicts with a manually-confirmed mapping records a `store_id_conflict` event, preserves the
reviewed mapping, and never silently moves documents.

### 17.7 Common-rule safety
"No store detected" is **never** automatically "common". A general-language phrase can only yield `suggested_common`;
**only an explicit admin confirmation** sets `scope_type=common` + `confirmed_common`, and only `confirmed_common`
rules are materialized into the hidden base. Pending / suggested_common / ambiguous / unmatched rules are never
searchable and never answer chat.

### 17.8 Materialization + the hidden Common-Rules base
Confirmed **store-specific** rules become an `order58_rule_store` document in that store's KB; confirmed **common**
rules become an `order58_rule_common` document in a single hidden **Common-Rules** knowledge base (slug
`order58-common-rules`, `purpose=shared_rules`, `agent_enabled=0`, no store source). It is created **lazily** (first
confirmed common rule), provisions its vector store through the normal drainer, and is **excluded** from the admin KB
directory, admin store directory and agent directory — but stays reachable to provisioning, indexing, cleanup, health
and usage. Materialization reuses `SyncDocumentService` (create/update/disable + the replacement guard). **Admin review
actions reconcile the projection immediately** (no full re-sync, no OpenAI in the request). Rule document body:
`# Rule: {title}` / Scope / Matched store / Rule: {description}.

### 17.9 Chat: store-first → common-second → fallback
`AskKnowledgeBaseService` answers from the **store** KB first. Only if that produces no *grounded* answer, and the
hidden Common-Rules base is **ready** (a dedicated readiness check — `vector_store_status=ready` AND ≥1 usable enabled
`order58_rule_common` document; it does **not** use or change the store `ChatAvailabilityPolicy`), it asks the common
base. A grounded common answer is saved as `common_rule`; otherwise the store's fallback is saved. **Exactly one**
assistant message is written. A store-stage infrastructure error propagates (temporarily unavailable); a common-stage
error degrades to the store fallback. `answer_source` = `store_rule` when the winning citation is a store-rule document,
else `store_knowledge`. The common base can never make an unavailable store chattable.

### 17.10 Reporting (`/admin/order58/rules`)
Read-only: source/canonical totals, exact-duplicate + possible-duplicate counts, the classification breakdown, the
searchable-document status breakdown, the latest sync counters, and a **reconciliation warning** when active source
rules are not all accounted for by a canonical link. Review actions POST to `/admin/order58/rules/review` (CSRF).

### 17.11 Failure/retry & commands
Invalid JSON / `success=false` fail the run safely; a partial/failed pagination never sweeps. Transient HTTP →
existing backoff; duplicate-key races caught narrowly. Local writes are transactional; no OpenAI call is held in a DB
transaction or a browser request (except chat answering). Commands: `php yii migrate:up` (apply the rules migrations),
then Admin → **Sync Rules** → the worker (`kf:worker:run`, cron) does the rest; watch `runtime/logs/worker.log`.
</content>
