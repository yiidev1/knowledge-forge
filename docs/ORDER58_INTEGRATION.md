# Order58 Integration — Phase 1 (Sync Foundation)

Knowledge Forge synchronizes **stores, agents and knowledge** from the Order58 Integration API into a
local mirror, maps each store to one Knowledge Base backed by one OpenAI vector store, and generates
deterministic indexable documents from Order58 records. All Order58 and OpenAI work runs in the background
worker — never in a web request.

> Scope: this document began as **Phase 1** (the sync foundation). The Order58 **agent login realm** and the
> agent **chat UI** (Phase 2) and the admin **knowledge input types** — `.txt`/`.md` upload, manual-text
> create/edit, and per-document enable/disable (Phase 3) — are now **built and covered by tests**. See
> [Phase 2](#phase-2--agent-authentication--chat-complete) and
> [Phase 3](#phase-3--admin-knowledge-input-types-complete).

---

## Architecture

New self-contained module `src/Order58/` (hexagonal, mirrors `src/Ai/`):

| Layer | Contents |
|---|---|
| `Contract/` | `Order58ClientInterface` port + DTOs (`Order58Account`, `Order58Agent`, `Order58KnowledgeRecord`, `Order58Page`, `Order58Health`) + exception hierarchy |
| `Client/` | `HttpOrder58Client` (Guzzle PSR-18, bounded retry, token redaction), `Order58ResponseParser`, `Order58ErrorMapper`, `Order58RetryPolicy`, `Order58Credentials`, `Order58HttpProfile` |
| `Application/` | mappers, deterministic formatters, `EnsureStoreKnowledgeBaseService`, `SyncDocumentService`, `EnqueueSyncService`, `Sync/` handlers + `IntegrationSyncDrainer` |
| `Domain/` | mirror models, `SyncRun` state machine, `Order58SyncType`/`SyncRunStatus`/`SyncProgress`, repository interfaces |
| `Infrastructure/` | `DbOrder58StoreRepository`, `DbOrder58AgentRepository`, `DbOrder58KnowledgeRepository`, `DbSyncRunRepository` |
| `Web/` | `DataManagement` page + sync/check actions, read-only `Agents` page |

**Reused unchanged** (the key win): the KB provisioning drainer, the document processing + cleanup
pipeline (`DocumentProcessingDrainer` → `ProcessDocumentService` → `KnowledgeIndexInterface` → poll →
ready), `DocumentProcessorRegistry`, `WorkerRunner`/`FlockWorkerLock`, `SecretValue`/`SecretRedactor`/
`SafeLogContext`. The only additive change to existing code is a predicate on the provisioning query (defer
inactive stores) and a new `DocumentKind::Text` + `TextDocumentProcessor`.

Worker drainer order (`config/common/di/worker.php`): **Order58 sync → KB provisioning → document
processing → remote cleanup**, so a store synced this run is provisioned and indexed within the same pass.

---

## Configuration variables

One shared Bearer token; change it in one place and every call updates. Add to `.env` (see `.env.example`):

| Variable | Default | Purpose |
|---|---|---|
| `ORDER58_API_BASE_URL` | *(empty)* | `http://restro_yii.test/api/integration/v1` (dev) or `https://order58.seawolf.io/api/integration/v1` (prod) |
| `ORDER58_API_TOKEN` | *(empty, secret)* | Server-to-server Bearer token — never exposed to the browser, logs or DB |
| `ORDER58_API_CONNECT_TIMEOUT_SECONDS` | `10` | connect timeout |
| `ORDER58_API_TIMEOUT_SECONDS` | `30` | request timeout |
| `ORDER58_API_MAX_RETRIES` | `2` | retries for 429/5xx/network only |
| `ORDER58_API_RETRY_MAX_BACKOFF_SECONDS` | `30` | backoff cap |
| `ORDER58_API_PAGE_SIZE` | `100` | pagination page size |
| `ORDER58_SYNC_MAX_ATTEMPTS` | `3` | sync-run retry cap |
| `ORDER58_SYNC_PAGES_PER_RUN` | `1000` | pages per worker invocation before yielding (resumable) |

The token is added to the `SecretRedactor` literals, so even an echoed request or transport error cannot
carry it into a log. Until the base URL + token are set, the sync drainer stays quiet (no calls).

---

## Database & migrations

Four additive migrations in `src/Migration/` (down/up round-trip verified). Charset/collation reuse the
existing migrations' table options.

- `M260728120000CreateOrder58Mirrors` — `order58_stores`, `order58_agents`, `order58_knowledge_records`.
  Each keeps the source id (unique), `_sync_hash`, source status, safe snapshot, timestamps, and
  `last_seen_sync_run_id` (mark-and-sweep marker).
- `M260728120100CreateIntegrationSyncRuns` — the sync state machine with a generated `active_key` STORED
  column under a UNIQUE index for coalescing (mirrors `documents.dedupe_hash`).
- `M260728120200AddKnowledgeBaseSourceColumns` — `source_system`, `source_store_id` (unique with system),
  `source_name`, `source_active`, `agent_enabled`, `last_source_synced_at`, `last_indexed_at`.
- `M260728120300AddDocumentSourceColumns` — `source_type`, `source_ref`, `source_sync_hash`, `title`,
  `is_enabled`, `last_indexed_at`; unique `(knowledge_base_id, source_type, source_ref)`.

```bash
php yii migrate:up          # apply (safe, additive)
php yii migrate:down --limit=4   # revert the four (development only)
```

---

## Worker command & cron

Uses the existing worker; no new command required:

```bash
php yii kf:worker:run --limit=1   # one pass of all drainers, including Order58 sync
```

Example cron (single flock-guarded, nice-d line — the repo's existing recommendation; do not install cron
without approval):

```cron
* * * * * /usr/bin/flock -n /var/www/html/knowledge-forge/runtime/locks/worker.lock /usr/bin/nice -n 10 /usr/bin/php /var/www/html/knowledge-forge/yii kf:worker:run --limit=1 >> /var/www/html/knowledge-forge/runtime/logs/worker.log 2>&1
```

---

## Admin sync workflow

Page: **Order58 sync** in the sidebar → `/admin/order58`. Renders entirely from local DB state (never calls
the API on render). Three **independent** primary buttons, each POST + CSRF, each enqueue-only, each
disabled only while *its own* operation is pending/running:

1. **Sync Stores** — full paginated `/accounts` scan; mirror by `account.id`; create one KB per store;
   regenerate the store-profile document on change.
2. **Sync Knowledge** — full paginated `/knowledge` scan; generate a knowledge document per record.
3. **Sync Agents** — full paginated `/agents` scan; safe fields only; no documents, no OpenAI.

Plus **Check Connection** (enqueues a bounded health probe; the page shows the last cached result) and, for
one store, secondary `knowledge_store` and `rebuild_store` operations
(`/admin/order58/stores/{storeId}/…`). A second click of the same operation while it is active is coalesced
(the DB rejects the duplicate) so the Order58 server is never double-loaded.

---

## Key invariants & behavior

- **One store = one Knowledge Base = one OpenAI vector store**, enforced by unique
  `(source_system, source_store_id)` on `knowledge_bases` and the existing unique `openai_vector_store_id`.
- **Deferred provisioning for inactive stores**: an inactive store's KB is created with
  `source_active = false` and is not provisioned; the provisioning query only claims source-backed KBs
  where `source_active = 1 AND agent_enabled = 1`. When a later sync flips the store active, provisioning
  proceeds. An already-provisioned store that goes inactive keeps its KB, vector store id and conversations.
- **`_sync_hash` change detection**: an unchanged record is left untouched — only its
  `last_seen_sync_run_id` + `synced_at` are stamped (so the sweep never deactivates it); no regeneration,
  no OpenAI re-upload, no re-index.
- **Safe deactivation (mark-and-sweep)**: missing records are deactivated only *after* the full scan's
  final page succeeds, using the NULL-safe predicate
  `active = 1 AND (last_seen_sync_run_id IS NULL OR last_seen_sync_run_id <> :run)`. A failed/partial run
  performs no deactivation and retains its page cursor for a safe retry. A per-store knowledge run only
  evaluates that store's records.
- **Sync Knowledge with a missing store**: the record is still mirrored and marked seen, but no document is
  generated and no KB is created; `skipped_missing_store` is counted and the run finishes
  *Completed with warnings* — *"Run Sync Stores first."* Once the store is synced, the next run indexes it.
- **`account_id` is never authorization** — mirrored as employer/profile data only. No agent-to-store
  mapping table exists.

---

## Generated source-document format

`Order58StoreProfileFormatter` and `Order58KnowledgeFormatter` produce **byte-identical UTF-8** for the same
input (fixed field order, unified LF line endings, collapsed blank lines, labelled sections). `_sync_hash`
is never in the body. Documents carry `source_type` (`order58_store_profile` / `order58_knowledge`),
`source_ref` (the source id) and `source_sync_hash`, and flow through the existing worker as
`kind = text` (indexed directly, no AI). A changed record rewrites the text and requeues fresh, flagging the
old vector-store file for removal *after* the replacement indexes (the KB is never left without a copy).

---

## Failure & retry

- Client retries only 429/5xx/network, bounded by `ORDER58_API_MAX_RETRIES` with exponential backoff;
  authentication (401/403) and validation failures are never retried.
- A sync run that hits a transient error is requeued with backoff up to `ORDER58_SYNC_MAX_ATTEMPTS`, then
  `failed`. A non-transient error fails immediately. All error/warning messages are redacted and truncated.
- A run stuck `running` past 15 minutes is recovered to `pending` by the drainer's `recover()`.

---

## Security

- Bearer token wrapped in `SecretValue`, revealed only on the Authorization header, and in the
  `SecretRedactor` literals — never logged, persisted or sent to the browser.
- Agents are mirrored with **safe fields only**; no password/token is ever stored (the API returns none).
- All state-changing routes are POST + CSRF, behind the admin auth middleware; no slow work in web requests.

---

## Local setup

```bash
cp .env.example .env         # set ORDER58_API_BASE_URL + ORDER58_API_TOKEN (+ DB_*, OPENAI_* as usual)
composer install
composer yii-config-rebuild
php yii migrate:up
php yii kf:health
# Admin UI → Order58 sync → Sync Stores; then:
php yii kf:worker:run --limit=1   # drains the sync run; re-run to provision + index
```

---

## Production deployment checklist (do not deploy as part of this task)

1. `composer install --no-dev` and `composer yii-config-rebuild`.
2. Set `ORDER58_API_BASE_URL=https://order58.seawolf.io/api/integration/v1` and `ORDER58_API_TOKEN` in
   `.env`; re-run the ACL grant script; reload PHP-FPM.
3. `php yii migrate:up` (additive; reversible via `migrate:down`).
4. Confirm the worker cron is installed (`kf:worker:run --limit=1`).
5. Run **Sync Stores**, then **Sync Knowledge**, from the admin page; watch `runtime/logs/worker.log`.

**Rollback**: `php yii migrate:down --limit=4` cleanly reverts all four migrations (verified). Keep a
pre-deploy `mysqldump`.

---

## Phase 2 — Agent authentication & chat (COMPLETE)

A **separate authentication realm** for Order58 agents (`src/Agent/`), independent of the local admin realm.

**Agent login** (`GET|POST /agent/login`): credentials are posted server-side to Order58 `POST /authenticate`
with the shared Bearer token. The password is never stored, hashed locally or logged (it travels only in the
request body; logging is endpoint/status only). Admission requires an authenticated account **with
`user_type === 'agent'` and `status === 'active'`** — merchant/admin/operation/trainee accounts are refused
with the same generic "invalid credentials" as a wrong password (no enumeration). Only the safe identity
(`admin_id`, username, display name, email, status, user_type) is placed in the session under key `kf.agent`
— never a password, token, or `account_id`. Login attempts reuse the existing `DbLoginThrottle` under a
namespaced key (`order58-agent:…`), so agent and admin buckets never collide. `RequireAgentMiddleware` gates
every `/agent/*` route and trusts the session identity (no per-request network call).

**Agent chat** (`/agent`, `/agent/stores/{slug}/chat[/{conversationId}]`): an agent sees **every** active,
`agent_enabled`, ready store that has at least one indexed document — the same list for all active agents;
`account_id` is never consulted and there is no agent-to-store table. Selecting a store opens a chat bound to
that one knowledge base. Answering reuses the existing `AskKnowledgeBaseService` unchanged, so grounding,
citation resolution (validated against the conversation's KB), the fallback, and `messages.is_grounded`
BIT(1) all apply exactly as for admin chat. Conversations are bound to the agent (`conversations.agent_admin_id`,
added by `M260728130000AddConversationAgent`) and every read is scoped by both store and agent — another
agent's or another store's conversation id yields a 404. A conversation can never switch stores.

Agents cannot reach any admin route, sync data, upload documents, edit rules, provision vector stores, or
view credentials — those live behind `RequireAdminMiddleware` in a different route group.

## Phase 3 — Admin knowledge input types (COMPLETE)

Three admin-authored document types, all flowing through the **same** worker-driven indexing pipeline as
uploaded PDFs/images and Order58-generated documents — nothing here calls OpenAI in the web request.

**Text-file upload (`.txt`, `.md`).** `SupportedFileTypes` now maps `text/plain`/`text/markdown` to
`DocumentKind::Text`; `UploadValidator` additionally rejects a text file whose bytes are not valid UTF-8.
`UploadDocumentService` branches on kind: a text file is read, **normalized** (see below), content-hashed for
dedupe, and its normalized text is written as the stored artifact (`source_type = uploaded_text`). Markdown
is treated as **text, never rendered as HTML**.

**Manual text (create + edit).** `GET/POST /knowledge-bases/{slug}/manual-text` creates a document from a
typed Title + Content; `GET/POST /knowledge-bases/{slug}/documents/{id}/edit` edits one. `ManualTextService`
validates (title required, ≤200 chars; content valid UTF-8, non-empty after normalization, ≤100 000 chars),
dedupes within the KB, and stores the **original submitted text** (`documents.source_text`, added by
`M260728140000AddDocumentSourceText`) for later editing while indexing the **normalized** text. Rendering is
always escaped (`Html::encode`) — submitted text is never trusted HTML. On edit, the normalized content is
re-hashed: an **unchanged** edit updates only the title/original and **never re-indexes**; a **changed** edit
rewrites the stored text, flags the old vector-store file `pending_removal`, and requeues fresh — so the
previous copy stays attached until the replacement is indexed, then `RemoteCleanupDrainer` detaches it.

**Enable/disable (every source type).** `POST /knowledge-bases/{slug}/documents/{id}/toggle` with an explicit
`enabled` field (idempotent under double-submit). Disabling sets `documents.is_enabled = 0`, flags the index
file for removal, and records a `disabled` event — the row, history and stored text are all preserved.
Enabling requeues the document. Disabled documents are excluded from `countReadyForKnowledgeBase` (chat
readiness) and from the agent store directory's `EXISTS` predicate, so a disabled document cannot answer.

**Deterministic normalization** (`PlainTextNormalizer`, shared by both text paths): strip a UTF-8 BOM, unify
CRLF/CR to LF, right-trim each line, collapse runs of blank lines, trim, and add exactly one trailing
newline. The same knowledge — however it was typed or line-ended — yields **byte-identical** output, which is
what makes "unchanged content is never re-indexed" reliable and lets per-KB content dedupe compare like with
like.

**Design note — minimal ripple.** A single `TextDocumentProcessor` (kind `Text`, no AI) serves all text
source types. Rather than widen the heavily-used `Document` entity / `DocumentRepositoryInterface`, editing
and listing go through a separate `TextDocumentRepositoryInterface` (+ `EditableTextDocument` /
`DocumentListItem` read models); `NewDocument`/`createQueued` gained `sourceType`/`title`/`sourceText`
parameters (defaulted at the end) so the nine existing construction sites are untouched.

**Tests.** `PlainTextNormalizerTest` (determinism, BOM/line-ending/blank-line handling, UTF-8 validation),
`ManualTextServiceTest` (create validation/dedupe/limit; edit unchanged→no-reindex, changed→reindex-keeping-
old-file, edit-to-duplicate, missing/non-manual → 404), `ToggleDocumentServiceTest` (disable flags removal,
enable requeues, KB-scoped 404), plus a text-file case in `UploadDocumentServiceTest`. The full suite is green
(483 tests, 1151 assertions); `M260728140000` was verified with a down/up round-trip.

### Remaining, deferred

- Chat/answering and vector-store provisioning require a **live OpenAI key**; all automated tests use fakes
  and make no live calls.

## Phase 4 — Store active-status correction & store directories (COMPLETE)

### The active-status defect (audited, not assumed)

The Data Management page showed **0 / 233 active stores** while the Order58 API returned `active` for many
accounts (e.g. account ids 1491, 1151). Auditing the whole path against the live mirror
(`order58_stores.active`, `snapshot_json.active`, `knowledge_bases.source_active`) showed the parser and
mapper were **correct** — the authoritative value was intact in `snapshot_json.active` (206 true / 27 false)
and in `knowledge_bases.source_active` (already 206 = 1). The `order58_stores.active` **column** was 0 for all
233: stale data written by the very first sync run, which persisted the boolean flag incorrectly. The current
`save()` writes `active ? 1 : 0` correctly, but because the source `_sync_hash` was unchanged, change
detection only ever "marks seen" — so the stale rows were never rewritten.

### Fixes

- **Explicit, total normalization** (`Order58\Contract\ActiveFlag::normalize()`): accepts boolean, integer
  `1`/`0` and numeric string `"1"`/`"0"`; anything missing or unrecognised becomes `null` ("unknown"), never a
  silent `false`. `Order58Account` gained `activeKnown`; `StoresSyncHandler` skips a record whose active flag
  is unknown — it marks it seen (so the sweep preserves it), leaves the stored status untouched, and finishes
  `completed_with_warnings`. `account.active` is the **only** source-active signal; it is never derived from
  `account_id`, `demo`, `host`, knowledge count, vector-store state or any other field.
- **Reconciliation** (`Order58\Application\ActiveStatusReconciler`, command `kf:order58:reconcile-active`):
  re-derives active from the authoritative snapshot and corrects `order58_stores.active` and
  `knowledge_bases.source_active`, **independent of `_sync_hash`**. It never calls Order58, never regenerates a
  document or re-indexes, preserves knowledge-base ids, vector stores, documents, conversations and every
  administrator `agent_enabled` choice, and only writes rows that are actually wrong (idempotent). On this
  environment it corrected **206** store rows (0 / 233 → **206 / 233**); a second run corrects 0.
- **`source_active` vs `agent_enabled` are independent.** `source_active` mirrors the store and is set by sync;
  `agent_enabled` is a local admin override, defaulted to enabled for a new store and **never** auto-disabled
  by sync (an inactive store is hidden via `source_active`, not by flipping `agent_enabled`). A re-sync never
  overwrites an explicit disable.

### Admin metrics & store directory

- The Data Management page now shows four **independent** metrics instead of one conflated count:
  source-active Order58 stores (`order58_stores.active`), agent-enabled knowledge bases, ready knowledge
  bases, and stores available to agents (the agent-availability predicate). Active-store count is strictly
  `order58_stores.active = 1`.
- **Admin store directory** `GET /admin/order58/stores`: full-width, responsive card grid (4/2/1) with
  server-side search (name, company, city, address, store id), status filters (all / source active / source
  inactive / agent enabled / agent disabled / KB ready / KB pending-failed), A–Z + "#" alphabet nav with
  counts, and pagination. Backed by `StoreDirectoryReaderInterface` / `DbStoreDirectoryReader`, which issues a
  bounded set of queries (page rows with correlated document & knowledge counts, total, letter counts) — no
  N+1. Each card shows name, company, address/city/state, store id, source-active / agent-enabled / vector
  badges, ready-document and knowledge-record counts, and admin actions (Manage KB, Open chat when ready, Sync
  knowledge, Rebuild docs, Enable/disable agent access). It deliberately does **not** carry the legacy Order58
  links (Create Case Study, Create Knowledge, Store Login).

### Agent store directory

- `GET /agent` redesigned to the same full-width responsive grid with search and A–Z nav. It lists **only**
  stores satisfying the availability predicate — `source_active = 1 AND agent_enabled = 1 AND vector store
  ready AND ≥ 1 enabled, ready document` — the same set for every active agent; `account_id` is never
  consulted and there is no agent-to-store table. Cards show name, location and document availability with a
  clearly visible, non-clipping **Open chat** button (never "Login"); no admin actions appear. Existing
  agent/conversation authorization and store-binding are unchanged.

### Tests (all green: full suite 516 tests, 1253 assertions)

Unit: `ActiveFlagTest` (int/string/bool/invalid), extended `Order58ResponseParserTest` (every representation +
unknown), `AlphabetIndexTest` (case-insensitive, "#", normalize), `AgentHomeTemplateTest` (no admin actions in
the agent realm). Integration (real MySQL, sentinel ids): `ActiveStatusReconcilerTest` (stale→repaired,
agent_enabled + hash preserved, invalid snapshot never overwrites), `SourceStateIndependenceTest`
(agent-disable survives re-sync), `StoreDirectoryReaderTest` (search isolation, filters, alphabet + "#"
buckets), `AgentStoreDirectoryTest` (includes eligible; excludes inactive / disabled / not-ready / no-doc;
takes no agent identity so `account_id` cannot filter).

### Verification performed (local only)

`php yii kf:order58:reconcile-active` → 206 corrected, then 0 on re-run (idempotent). Store 1491/1151 now
Source Active; 61/71 stay Source Inactive; `agent_enabled` unchanged (all 233 = 1). Authenticated render of
`/admin/order58/stores` and `/admin/order58` returns HTTP 200 with the grid, alphabet, filters and the four
metrics. Not run here (needs live API / browser): a live "Sync Stores" pass — retention after reconcile is
guaranteed by the mark-and-sweep (`markSeen` before the sweep; unchanged-hash rows keep their `active`) and
proven by `Order58StoreSweepTest` and the reconciler's hash-untouched assertion.

### Remaining, deferred (Phase 4)

- The per-store secondary actions (`knowledge_store`, `rebuild_store`) are wired to the store-directory cards
  now; the KB detail page still does not surface them.
- Chat/answering and vector-store provisioning still require a **live OpenAI key**.
