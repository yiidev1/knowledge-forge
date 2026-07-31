# Knowledge Base — Store-wise Guide (A → Z)

This document explains, from zero, how Knowledge Forge turns an **Order58 store** into a chat-answerable
**knowledge base**, what every database table is for, what each **status** means, what the coloured badges in
the admin **Stores** page mean, and exactly what happens (step by step) when an admin **uploads a `.txt` file or
types manual text** — including how it reaches **OpenAI** and the **vector store**.

No prior knowledge assumed. Read top to bottom.

---

## 1. The big picture in one paragraph

Each **Order58 store** is mirrored locally and mapped to **exactly one Knowledge Base (KB)**. A KB owns **one
OpenAI vector store** (a searchable container of text). Everything an assistant can answer from is a
**document** attached to that vector store. Documents come from uploads (PDF / image / `.txt` / `.md`), from
**manual text** an admin types, or are generated from Order58 store/knowledge records. All the slow work
(calling Order58, calling OpenAI, indexing) happens in a **background worker** (run by cron every 2 minutes) —
never while you click a button. A store becomes **chattable** only when its KB is provisioned and has at least
one indexed document.

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

## 2. The one rule to remember: three **independent** switches

A store being "on" is **not** one thing — it is **three separate axes**. This is the single most important
idea, and it is exactly what the admin badges show.

| Axis | Column | Who sets it | Meaning |
|------|--------|-------------|---------|
| **Source active** | `knowledge_bases.source_active` (mirrors `order58_stores.active`) | **Order58** (via Sync) | Is the store active in Order58 itself? |
| **Agent enabled** | `knowledge_bases.agent_enabled` | **You, the admin** | Do you allow agents to use this store? |
| **KB ready** | `knowledge_bases.vector_store_status = ready` | **The system** | Is the OpenAI vector store provisioned? |

They do not affect each other. A store can be **Source active** but **Agent disabled**; it can be **Agent
enabled** but not **KB ready**; and so on. (There is also a fourth practical requirement for chatting: at least
one **document ready** — see §7.)

**An agent may chat with a store only when ALL of these are true:**

```
source_active = 1   AND   agent_enabled = 1   AND   vector_store_status = ready   AND   at least 1 enabled, ready document
```

`account_id` is **never** part of this — every active agent sees the same eligible stores.

---

## 3. What the admin badges mean (your exact question)

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

> Why can a store be "Source active + Ready" but still not chat? Because it has **0 docs ready**. Ready means the
> *container* exists; you still need at least one **indexed document** inside it. That is the state of most
> synced stores until their documents are generated/indexed by the worker.

---

## 4. The database — which table, why, and its statuses

There are 15 tables. These are the ones that matter for store-wise knowledge:

### 4.1 `order58_stores` — the raw mirror of an Order58 store
Why: a local, safe copy of each Order58 `account`, so the app never depends on the live API to render.
Key columns:
- `source_id` (UNIQUE) — the Order58 store id (`account.id`). The stable identity.
- `name`, `company`, `snapshot_json` — curated, credential-free fields (address, city, phone, hours…).
- `active` **tinyint(1)** — the source-active flag, copied from Order58 `account.active`. **This is the only
  source of "active".** (`0` or `1`.)
- `sync_hash` — Order58's change fingerprint; if unchanged, sync skips the record.
- `last_seen_sync_run_id` — used by "mark-and-sweep" to deactivate stores that vanish from Order58.

### 4.2 `knowledge_bases` — one per store (the heart)
Why: the thing the assistant answers from; owns the vector store and the source mapping.
Key columns & their statuses:
- `slug` (UNIQUE) — URL id, e.g. `888-chinese`.
- `openai_vector_store_id` — the OpenAI vector store this KB owns (null until provisioned).
- `vector_store_status` **enum** — `pending` → `provisioning` → `ready` (or `failed`). *Is the container built?*
- `status` **enum** — `active` | `archived`. *Is the KB itself in use?* (archived = hidden everywhere.)
- `source_system` = `order58`, `source_store_id` = the store's `source_id` — the mapping (UNIQUE together).
- `source_active` **tinyint** — mirror of the store's `active` (§2).
- `agent_enabled` **tinyint, default 1** — your local override (§2). Sync never touches it.
- `system_instructions` — extra guidance/prompt applied when answering (see §8).

### 4.3 `documents` — every piece of knowledge in a KB
Why: one row per uploaded file / manual text / generated record; the unit the worker indexes.
Key columns & statuses:
- `kind` — `pdf` | `image` | `text` (how it is ingested).
- `source_type` — provenance/routing: `uploaded_pdf`, `uploaded_image`, `uploaded_text`, `manual_text`,
  `order58_store_profile`, `order58_knowledge`.
- `status` **enum** — the lifecycle (very important):
  - `uploaded` — bytes captured, not yet queued.
  - `queued` — waiting for the worker.
  - `processing` — the worker is preparing it (extract/normalize).
  - `indexing` — uploaded to OpenAI, waiting for the vector store to finish indexing.
  - `ready` — searchable; chat can use it. ✅
  - `failed` — something went wrong (see `error_message`); will retry / can be retried.
  - `deleted` — soft-deleted; ignored everywhere.
- `is_enabled` **tinyint, default 1** — per-document on/off. A disabled document is kept but excluded from chat.
- `source_text` — for **manual text**, the *original* text you typed (so you can edit it later).
- `checksum_sha256` + `dedupe_hash` (generated) — prevent the same content being added twice in one KB.
- `title`, `original_filename`, `stored_path`, `size_bytes`, `error_message`, `last_indexed_at`.

### 4.4 `document_index_files` — the bridge to OpenAI
Why: maps each document to its actual **file inside the OpenAI vector store**, and tracks index state.
Key columns:
- `document_id` — which document.
- `role` **enum** — `source` (the file that is searched) | `derived_markdown` (a converted copy, e.g. from a PDF).
- `openai_file_id` (UNIQUE) — the id OpenAI gives the uploaded file.
- `index_status` **enum** — `pending` → `in_progress` → `completed` (or `failed` / `cancelled`). This is OpenAI's
  view of indexing; when it is `completed`, the parent document can become `ready`.
- `pending_removal` **tinyint** — flagged when a file must be detached from OpenAI (e.g. after an edit or
  disable). The cleanup drainer removes flagged files **after** the replacement is attached, so retrieval is
  never left empty.

### 4.5 `document_processing_events` — the audit trail
Why: an append-only log of what happened to each document (`queued`, `processing`, `ready`, `disabled`,
`failed`…), so you can see history without changing the document row.

### 4.6 `integration_sync_runs` — the sync job queue / state machine
Why: every "Sync Stores / Sync Knowledge / Sync Agents / Check connection" click becomes a row here; the worker
drains them.
Key columns & statuses:
- `type` — `stores` | `knowledge` | `agents` | `knowledge_store` | `rebuild_store` | `health`.
- `status` **enum** — `pending` → `running` → `completed` | `completed_with_warnings` | `failed`.
- `progress_json` — page cursor + counters (created/updated/unchanged/deactivated/warnings…).
- `active_key` (UNIQUE, generated) — **coalescing**: a second click of the same operation while one is already
  pending/running is rejected as a duplicate, so you never double-run or double-load Order58.

### 4.7 The rest (brief)
- `order58_knowledge_records` — mirrored Order58 knowledge entries (become `order58_knowledge` documents).
- `order58_agents` — safe agent profiles (no credentials); used to gate agent login (`user_type = agent`).
- `conversations` + `messages` — chat threads and turns; `messages.is_grounded` marks a cited answer.
- `knowledge_base_rules` — extra answering rules per KB.
- `admin_users`, `auth_login_attempts` — admin accounts + login throttling.
- `ai_operations` — ledger of OpenAI calls (idempotency/reliability).
- `migration` — schema version history.

---

## 5. Status glossary (all of them, in one place)

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
| `integration_sync_runs.status` | pending, running, completed, completed_with_warnings, failed | Sync job state |

---

## 6. Who does the work: the background worker

Nothing slow runs in the browser. A single command drains queues:

```
php yii kf:worker:run
```

It is run automatically by **cron every 2 minutes** (with `flock` so runs never overlap). Each pass runs four
**drainers in this order**:

1. **IntegrationSyncDrainer** — executes one `integration_sync_runs` job (Sync Stores/Knowledge/Agents): pages
   through Order58, updates the mirror tables, and creates generated documents.
2. **KnowledgeBaseProvisioningDrainer** — for each KB that is `pending` (and eligible: source-active + agent-
   enabled), creates its **OpenAI vector store** → `vector_store_status = ready`.
3. **DocumentProcessingDrainer** — takes `queued` documents and indexes them into the vector store (the main
   flow in §7).
4. **RemoteCleanupDrainer** — deletes OpenAI files flagged `pending_removal` (after their replacement is live).

---

## 7. Step by step: admin adds a `.txt` file OR types manual text → OpenAI + vector store

This is the exact path your question asks about. Both a `.txt`/`.md` **upload** and **manual text** end up as a
`kind = text` document and follow the same indexing path; only the first step differs.

### 7.1 What happens in the browser (fast, no OpenAI)

**A) Manual text** (admin types Title + Content):
1. Validate: title required (≤200 chars), content valid UTF-8, non-empty, ≤100 000 chars.
2. **Normalize** the content deterministically (strip BOM, unify line endings to `\n`, trim, collapse blank
   lines, one trailing newline). Same text always produces the same bytes → same checksum.
3. **Dedupe**: `checksum_sha256` of the normalized text is checked within the KB; a duplicate is rejected.
4. Store two things: the **original** text in `documents.source_text` (so you can edit it later) and the
   **normalized** text as a file on disk (this is what gets indexed).
5. Insert a `documents` row: `kind = text`, `source_type = manual_text`, `status = queued`, `is_enabled = 1`.
6. Return immediately and log a `queued` event. **No OpenAI call yet.**

**B) `.txt` / `.md` upload** — identical, except step 1–2 read the uploaded file, reject non-UTF-8/binary, and
set `source_type = uploaded_text`. Markdown is treated as **plain text, never rendered as HTML**.

At this point the document is `queued` and shows as "Provisioning/processing" in the UI. Nothing else happens
until the worker runs.

### 7.2 What the worker does (the OpenAI + vector store part)

On its next pass (≤2 minutes), **DocumentProcessingDrainer** picks up the `queued` document:

1. Mark `status = processing`; record a `processing` event.
2. The **TextDocumentProcessor** produces the indexable content: it simply reads the stored **normalized text**
   (no AI needed for text — unlike PDFs/images which need extraction/vision).
3. **Upload to OpenAI**: the text is uploaded as a **file**; OpenAI returns an `openai_file_id`. A
   `document_index_files` row is written (`role = source`, `index_status = pending`).
4. **Attach to the KB's vector store** (`knowledge_bases.openai_vector_store_id`). Mark `status = indexing` and
   `index_status = in_progress`.
5. **Poll** OpenAI until the file's indexing is `completed` (or `failed`).
6. On success: `index_status = completed`, document `status = ready`, `last_indexed_at` set, a `ready` event
   logged. The document is now searchable. ✅
7. On a transient error: `status = failed` with a backoff; the worker retries later. Permanent errors keep
   `failed` with an `error_message` you can see on the card.

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

### 7.3 Editing manual text later
- If you edit and the **normalized content is unchanged** (e.g. you only fixed the title): only the title is
  updated, **no re-index**, no OpenAI cost.
- If the **content changed**: the new text is stored, the document is re-queued, and the **old** OpenAI file is
  flagged `pending_removal`. The old file stays attached until the new one finishes indexing, then the cleanup
  drainer removes it — so chat is never left with an empty or half-updated store.

### 7.4 Enable / disable a document
- **Disable** (`is_enabled = 0`): the document's index file is flagged for removal and it stops counting toward
  "docs ready"; the row, text and history are preserved. It disappears from chat.
- **Enable**: the document is re-queued and indexed back in.

### 7.5 PDF / image (for completeness)
Same pipeline, different preparation: a **PDF** has its text extracted (and, if it is a scan with little text,
pages are read with vision); an **image** is described with vision. The result becomes a `derived_markdown`
file that is uploaded and indexed exactly like text. So every document type converges on "a file inside the
vector store".

---

## 8. Step by step: chatting with a store

1. Admin opens **Store chat** (`/admin/order58/store-chat`) or the store's **Open chat**; agents use `/agent`.
2. You need a **chat-ready** KB: `vector_store_status = ready` **and** ≥1 `ready`, enabled document. Otherwise
   the page shows "No documents have finished indexing yet."
3. You ask a question → a `conversations` row + a user `messages` row.
4. The assistant searches **only this KB's vector store** (OpenAI file search), applies the KB's
   `system_instructions` and rules, and answers.
5. The answer is grounded: it is checked against retrieved sources, citations are attached, and
   `messages.is_grounded` is set. If nothing relevant is found, it says so rather than inventing an answer.
6. A conversation is permanently bound to its store/KB — it can never switch stores.

---

## 9. Step by step: how a store becomes a KB in the first place (sync)

1. Admin clicks **Sync Stores** on Data Management → an `integration_sync_runs` row (`type=stores`,
   `status=pending`). (A duplicate click is coalesced by `active_key`.)
2. The worker's IntegrationSyncDrainer pages through Order58 `/accounts`:
   - New/changed store → upsert `order58_stores` (incl. `active` from `account.active`), ensure **one**
     `knowledge_bases` row (`source_system=order58`), and (re)generate its store-profile document.
   - Unchanged store (`sync_hash` matches) → only "marked seen"; nothing rewritten.
   - A new **active** store's KB is created `pending` and gets provisioned; an **inactive** store's KB is created
     but **not** provisioned until it becomes active.
3. **Sync Knowledge** does the same for `/knowledge` records → `order58_knowledge` documents in the owning KB.
4. Provisioning drainer builds each eligible KB's vector store → `ready`.
5. Document processing drainer indexes the generated documents → `ready` → the store now shows "N docs ready".

> Important nuance (the reason the store list once showed "0 active"): the **active** flag lives in
> `order58_stores.active` and is set only from `account.active`. It is never guessed from `account_id`, `demo`,
> `host`, knowledge count or vector-store state. If a mirror row's `active` was ever written wrong, run
> `php yii kf:order58:reconcile-active` to re-derive it from the stored snapshot (safe, idempotent).

---

## 10. Command & cron reference

```bash
php yii kf:worker:run                 # drain sync + provisioning + document indexing + cleanup (one pass)
php yii kf:order58:reconcile-active   # repair store active status from the local snapshot (safe, idempotent)
php yii kf:health                     # config / DB / storage / migrations check
```

Cron (every 2 minutes, non-overlapping):

```
*/2 * * * * /usr/bin/flock -n /var/www/html/knowledge-forge/runtime/locks/worker.lock /bin/sh -c 'cd /var/www/html/knowledge-forge && /usr/bin/php yii kf:worker:run >> /var/www/html/knowledge-forge/runtime/logs/worker.log 2>&1'
```

Watch progress: `tail -f runtime/logs/worker.log`.

---

## 11. Quick answers (cheat sheet)

- **"Source active vs inactive?"** → Order58's own on/off for the store (`account.active`). You cannot change it
  here; Sync copies it.
- **"Agent enabled vs disabled?"** → Your local switch for whether agents may use the store. Survives syncs.
- **"KB ready vs pending/failed?"** → Whether the OpenAI vector store (the searchable container) is built.
- **"Why 'Ready' but can't chat?"** → 0 documents indexed yet. Ready = container exists; you still need docs.
- **"I typed manual text — when can I chat?"** → After the next worker pass indexes it (`queued → processing →
  indexing → ready`, usually within ~2 minutes, given a valid OpenAI key).
- **"Where does the original text live?"** → `documents.source_text` (original) + a normalized file on disk (the
  indexed copy).
- **"Who can an agent chat with?"** → `source_active = 1 AND agent_enabled = 1 AND vector_store_status = ready
  AND ≥1 enabled, ready document`. `account_id` never matters.
