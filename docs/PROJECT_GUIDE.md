# Knowledge Forge — Complete Project Guide (A → Z)

> One document that explains **what the system is**, **how every flow works step by step**, and **every database table — when it was introduced, where it is used, and why it exists.** Source of truth is the code under `src/` and `src/Migration/`; this guide summarises it. Dates in migration filenames (`M260724…` = 2026‑07‑24) are the build order.

---

## 1. What Knowledge Forge is

Knowledge Forge is an internal, grounded **question‑answering platform over per‑store knowledge bases**. Each store (mirrored from an external system called **Order58**) maps to exactly one **knowledge base (KB)**, which is backed by an **OpenAI vector store**. Admins and field **agents** ask questions and get answers that are **grounded only in that store's documents**, always with citations. If the documents can't support an answer, the system returns a safe fallback rather than guessing.

Two audiences, two "realms":

- **Admin realm** (`/admin/**`, `/knowledge-bases/**`) — full management: browse stores, manage KB documents, review rules, run/monitor syncs, chat with any store.
- **Agent realm** (`/agent/**`) — a locked‑down surface for field agents who authenticate against the Order58 API; they can only chat with stores an admin has enabled for agents.

Core promises the whole design protects:
- **Grounded or fallback** — an answer without a valid citation is discarded.
- **The browser never calls OpenAI** — web/CLI requests only *enqueue* work; a background **worker** does all OpenAI indexing/cleanup. Chat is the one synchronous OpenAI call.
- **Idempotent, self‑healing sync** — everything keys on a content `sync_hash`; unchanged data is skipped, partial failures retry, nothing is duplicated.

---

## 2. Tech stack & conventions

- **PHP 8.2**, **Yiisoft** components (yii3‑style), **MySQL 8** (`utf8mb4_0900_ai_ci`).
- **Hexagonal / clean architecture** per module: `Domain` (entities, value objects, interfaces), `Application` (services/use‑cases), `Contract` (cross‑module DTOs/interfaces), `Infrastructure` (DB/OpenAI adapters), `Web` (HTTP actions + templates), `Console` (CLI commands).
- **Dependency injection** is mostly autowired; explicit bindings live in `config/common/di/**` and `config/web/di/**`.
- **Tests** (Codeception, `tests/`): suites `Unit`, `Integration` (real MySQL), `Functional`, `Console`, `Web` (browser; needs port `:8080` free). Quality gates: `composer cs-check` (php‑cs‑fixer), `composer psalm`, `composer rector-dry`.
- **Migrations** are reversible and run only against a disposable/test DB in development; never against the real environment casually.

---

## 3. Module map (`src/`)

| Module | Responsibility |
|---|---|
| **Auth** | Admin accounts, password hashing, login throttling, `RequireAdminMiddleware`. |
| **Agent** | Agent identity (from Order58 API), `RequireAgentMiddleware`, agent store directory, agent chat surface. |
| **Order58** | The external integration: HTTP client, sync handlers (stores/agents/knowledge/rules), mirror tables, store directory reader, daily schedulers, generated‑document upsert. |
| **KnowledgeBase** | The KB entity + repositories, provisioning bookkeeping, the canonical chat‑eligibility SQL, the KB "answering rules". |
| **Document** | Documents + index files + processing events; upload validation/storage; the generated‑document repository; source‑type enum. |
| **Rules** | The rule catalog, classification, admin review, projection into vector stores, readiness reporting, the hidden global‑rules KB. |
| **Chat** | Conversations, messages, revisions; the grounded ask service; grounding verifier; citation resolver; instruction builder; edit/regenerate; the read‑only source‑transparency pages; 1–10 answer scoring. |
| **Ai** | OpenAI client + adapters (vector store, file upload/attach, Responses/File Search), the reliability ledger (`ai_operations`). |
| **Worker** | The background runner (`kf:worker:run`) and its drainers (provision → process → cleanup), recovery/reconcile commands. |
| **Shared** | Clock, timezone seam (`AppTimeZone`), DB helpers, logging/redaction, pagination/alphabet helpers, correlation id. |
| **Web** | Shared admin layout, sidebar, security headers, error handling. |

---

## 4. The core mental model

1. **A knowledge base = a searchable corpus + an OpenAI vector store.** `knowledge_bases.openai_vector_store_id` is the address; `vector_store_status` tracks provisioning.
2. **Documents are rows in `documents`; their searchable copy is a `document_index_files` row** carrying the `openai_file_id`. A document is "usable for chat" only when it has a **completed** index file with a non‑null `openai_file_id` — never merely `documents.status='ready'`.
3. **The database IS the work queue.** A web/CLI action inserts/updates rows (status `queued`, `pending_removal`, a pending `integration_sync_runs` row). The **worker** claims them and performs the OpenAI calls.
4. **`sync_hash` is change detection.** Mirror rows, generated documents, and sync all compare an upstream/content hash; unchanged ⇒ skip (no rewrite, no re‑index).

---

## 5. End‑to‑end flows (step by step)

### 5.1 Order58 data sync (stores, agents, knowledge, rules)
The Order58 API is **page‑based only** (`?page=&per_page=`, no incremental cursor). "Smart sync" therefore = a change‑driven full scan.

1. A sync is **enqueued** (admin button, scheduler, or `EnqueueSyncService`) → a `pending` row in **`integration_sync_runs`** (`type` = stores/agents/knowledge/rules). `active_key` (a generated unique column) guarantees only one *active* run per type.
2. The worker claims the run (`claimed_at`), sets it `running`, and drives the matching **sync handler** page by page, tracking position in `progress_json`.
3. Each upstream record is upserted into its **mirror table** (`order58_stores`, `order58_agents`, `order58_knowledge_records`, `order58_rule_records`) keyed by `source_id`; unchanged `sync_hash` ⇒ untouched. `last_seen_sync_run_id` stamps every row seen this run.
4. **Mark‑and‑sweep** removal runs **only after a fully completed final page**: rows not seen this run are deactivated (never on a partial/failed run).
5. Store/knowledge mirrors then drive **generated documents** (§5.2) and rules drive the **rule pipeline** (§5.3).
6. The run ends `completed` / `completed_with_warnings` / `failed`.

### 5.2 Document ingestion & indexing (the worker)
Applies to admin **uploads** (PDF/image/text/manual text) and **Order58‑generated** documents (store profile, knowledge records).

1. **Enqueue only:** a document row is written to `documents` (`status='queued'`, `kind`, `source_type`). Generated docs go through `SyncDocumentService::upsertGenerated` — new/changed content is written to storage and queued; unchanged `source_sync_hash` is skipped; an admin‑disabled doc is never silently revived; a local override wins over upstream.
2. **Provisioning drainer:** if the KB's vector store isn't ready, it creates it (OpenAI) and sets `knowledge_bases.vector_store_status='ready'` + `openai_vector_store_id`.
3. **Processing drainer:** claims `queued` docs whose KB vector store is `ready`, sets `processing`/`indexing`, then `ProcessDocumentService` uploads the file and **attaches it to the vector store** (`OpenAiKnowledgeIndex`), writing a `document_index_files` row (`index_status='completed'`, `openai_file_id`, one `attributes: {document_id}`). Document becomes `ready`.
4. **Replacement guard:** on re‑index, the *old* index file is flagged `pending_removal` but kept attached until the new one completes — the KB is never left with zero usable copies.
5. **Cleanup drainer:** removes `pending_removal` files from the OpenAI vector store, then deletes the row.
6. Every transition is appended to **`document_processing_events`** (audit).

### 5.3 Rule pipeline (sync → catalog → classify → review → project → index)
1. **Mirror:** rules land in **`order58_rule_records`** (raw upstream, `is_active`).
2. **Catalog:** each distinct rule becomes a canonical **`rule_catalog_rules`** row (dedup by `canonical_hash`); the mirror→canonical mapping is **`rule_catalog_sources`** (`relation_type` primary/duplicate/merged).
3. **Classify:** the classifier decides `scope_type` (common / store_specific / unresolved) and `classification_status` (pending, auto_matched, manually_matched, suggested_common, confirmed_common, ambiguous, unmatched, ignored). Store matches are recorded in **`rule_store_links`** (`match_status` suggested/confirmed/rejected, `match_method`), aided by **`order58_store_aliases`**. Every transition is logged in **`rule_classification_events`**.
4. **Admin review** (`RuleReviewService`): confirm/reject a store, mark common, ignore, toggle global availability. Each action re‑runs the reconciler.
5. **Project (materialize):** `RuleProjectionReconciler` turns a rule into **generated documents** — enqueue‑only, no OpenAI:
   - **Global projection** `order58_rule_global` → the single hidden **Global Rules KB** (`slug order58-common-rules`, `purpose='shared_rules'`). Created for **every active, globally‑available rule** (`is_active AND is_globally_available`, default on) — this is the complete answerable rule corpus.
   - **Store projection** `order58_rule_store` into a store KB → **no longer created** (Phase 1). Store chat must answer only from genuine store knowledge; any legacy store‑rule document is **retired** (disable → `pending_removal` → worker cleanup). See `kf:rules:retire-store-projections --dry-run`.
   - `order58_rule_common` is legacy/dead (always retired).
6. The worker indexes the global projection into the Global Rules vector store exactly like any document.

### 5.4 Store Chat (grounded answer) — current behaviour
Entry: admin `GET/POST /knowledge-bases/{slug}/chat*`, agent `GET/POST /agent/stores/{slug}/chat*`. Server: `AskKnowledgeBaseService`.

1. **Availability gate** (`ChatAvailabilityPolicy`, the one canonical rule): the KB must be active, its vector store `ready` with a non‑null id, and it must have a **usable qualifying document** (enabled, not deleted, with a completed index file, source type is *genuine content* — not the store profile and **not a rule projection**). Order58‑linked KBs also require `source_active=1` and a usable store‑profile snapshot; the agent realm additionally requires `agent_enabled=1`. Enforced on GET (hard block/redirect for admin; 404 via the agent resolver) **and** on POST.
2. **Find‑or‑create thread:** GET only *finds*; the first POST find‑or‑creates the participant's canonical conversation (`conversations`, unique per `(knowledge_base_id, participant_type, participant_id)`). The user question is persisted **before** the provider call.
3. **Instructions:** immutable security block + the KB's own **answering rules** (`knowledge_base_rules`) + fallback text + an exhaustive‑intent directive when the question asks for "all/every".
4. **Single‑stage retrieval (Phase 1):** one forced **File Search** call against **this KB's own vector store only** (`OpenAiChatCompletionProvider`). Store chat **never** consults the global/common rules base — rule content is served exclusively by the dedicated Rule Chat (§5.9).
5. **Citations → grounding:** raw `file_citation` annotations are resolved by `openai_file_id → document_index_files → documents` (scoped to this KB, so a citation can't leak across bases). `GroundingVerifier` requires retrieval completed + ≥1 result + (config `CHAT_REQUIRE_CITATIONS=true`) ≥1 resolved citation; otherwise the text is discarded and the **fallback** is stored.
6. **Persist one answer:** exactly one active assistant message (`messages`, guarded by the `active_answer_key` unique index), tagging `answer_source` (`store_knowledge` / `store_rule` / `fallback`), `is_grounded`, `retrieval_status`, `openai_response_id`, citations.
7. **History:** OpenAI receives only the bounded `RecentMessagesHistoryPolicy` window (message + char limits); `store:false`, so the DB is the sole history source.

### 5.5 Edit / regenerate / revisions
- Only the **latest user** question is editable, by its owner, within the edit window.
- An edit runs in a short transaction: optimistic‑lock the question on `edit_count`, write the prior text to **`message_revisions`**, and **supersede** the current answer (`messages.superseded_at`). Regeneration runs after, using history *before* the edited turn (superseded/after excluded).
- A provider failure leaves the edit committed with no active answer → recoverable "Retry". Regeneration is idempotent (no‑op if an active answer exists). The `ux_messages_active_answer` unique index enforces **exactly one active answer per question**.

### 5.6 Agent realm
- Login posts credentials to the Order58 API (`AgentLoginService`); only `user_type='agent'`, `status='active'` accounts are admitted. The session stores a minimal `AgentIdentity` (no token, no account id).
- Per request, `RequireAgentMiddleware` trusts the session identity; store access is authorised per request by `AgentStoreResolver → findAvailableBySlug` (an unavailable store is a **404**, never revealed). The agent directory uses the same canonical eligibility SQL **AND** `agent_enabled=1`.

### 5.7 Daily schedulers & timezone
- `APP_TIMEZONE` (default `America/New_York`) is a **display/business/scheduling** seam only — **DB timestamps stay UTC** (`SystemClock`, `AppTimeZone`).
- Two independent daily schedulers (enqueue‑only): **Knowledge 02:00** (`kf:order58:schedule-knowledge`), **Rules 03:00** (`kf:order58:schedule-rules`) America/New_York. A reservation table **`order58_daily_sync_schedules`** (unique `(sync_type, ny_date)`) makes them idempotent per NY‑day, failure‑safe (retry if enqueue fails), and catch‑up‑aware (a missed window recovers on the next pass).

### 5.8 Provisioning & cleanup, reliability ledger
- OpenAI vector‑store creation and file removal are done by the worker drainers, never in a web request.
- **`ai_operations`** is a reliability ledger: OpenAI creates are wrapped so they are idempotent (`operation_key`, `request_fingerprint`, `idempotency_key`) and reconcilable (`needs_reconcile`) after a crash.

### 5.9 Rule Chat (dedicated surface)
- Admin `GET/POST /admin/rule-chat*`, agent `GET/POST /agent/rule-chat*`. Same `AskKnowledgeBaseService`, but with `ChatRetrievalScope::RuleOnly` and the hidden Global Rules base resolved by `RuleChatKnowledgeBaseResolver`.
- Availability is `RuleChatAvailability` → `CommonRulesReadiness`, deliberately **separate** from the store `ChatAvailabilityPolicy`: the rules base must be provisioned **and** hold at least one usable Ready `order58_rule_global` document. A synced‑but‑unmaterialized rule never enables it.
- The thread is still private per participant — the base is shared, the conversation is not.

### 5.10 Source transparency (read‑only)
Every chat surface can show what it is allowed to answer from. Strictly read‑only: these pages render no upload, edit, delete, retry or sync control in either realm.

- **Knowledge** — `chat.sources.knowledge` / `agent.chat.sources.knowledge`. `ChatKnowledgeSourcesService` joins the knowledge base's own document list with the canonical usable‑snapshot set (`DocumentRepositoryInterface::findUsableDocumentIds`) and filters by the surface's `ChatRetrievalScope`, so "available to this chat" means exactly what retrieval can reach. Selecting a title discloses the document's own text via `ServeCanonicalDocumentService::textBody()` — the artifact retrieval reads — capped at 4 000 characters.
- **Rules** — `admin.rule-chat.sources.rules` / `agent.rule-chat.sources.rules` list the indexed global rules Rule Chat can search, using `RuleReadinessReaderInterface` in `Ready` + `hiddenBaseOnly` scope (the same derivation §5.9 gates on). A store‑scoped variant exists at `chat.sources.rules` / `agent.chat.sources.rules`; it states plainly that **store chat cannot answer from catalog rules** and lists them for reference only, alongside the `knowledge_base_rules` that really do shape the reply. Those two routes are reachable by URL but deliberately **not linked** from the store‑chat header.
- Store scoping is structural: admin pages resolve through `KnowledgeBaseFinder`, agent pages through `AgentStoreResolver` (an unavailable store is a 404), and the catalog query keys on that store's own Order58 `source_id`.
- Shared template paths come from `SourceViews` / `ChatPartials` and must stay **absolute** — the view renderer does not expand an `@src/...` alias in a view name, and an action that sets only a layout then dies with "The view path is not set." (`tests/Unit/Chat/SourceViewsTest.php` pins this.)

### 5.11 Answer scoring (1–10)
Post‑hoc feedback on an assistant answer, on all four chat surfaces. **No OpenAI call**; retrieval, grounding, citation verification and availability are untouched.

1. **States** — no row = unrated (`Would you like to rate this answer?` Yes/No); `score` set = rated (`8/10 · Good` + a pencil that reopens the slider on the saved value); `dismissed_at` set with no score = declined, leaving a quiet `Rate this answer` link so an accidental "No" is recoverable. Rating a dismissed answer clears `dismissed_at`.
2. **Writes happen twice only** — "Save score" and "No". Yes / Change / Rate‑this‑answer are client‑side toggles, so dragging the slider never records anything.
3. **A dismissal is not a zero.** `score` stays NULL, so declining can never drag an average down.
4. **Authorization** reuses the edit kernel: `findOwnedThreadById(conversationId, kbId, participant)` → `findByIdInConversation(messageId, conversationId)` → must be an assistant message → must not be superseded. Participant always from the session; a forged id is a 404. `admin(1)` ≠ `agent(1)`.
5. **Validation is strict** — only an integer 1–10. `8abc`, `8.5`, `""`, `0` and `11` are rejected, never coerced; the DB repeats the range as a CHECK.
6. **A dismissal aimed at an already‑rated answer is refused** (`AnswerScoreInvalid::alreadyRated`), so a stale page cannot discard a score.
7. **Rendering** — `MessageScoreView::compute()` loads every displayed answer's state in one `IN (…)` query (no N+1), mirroring `MessageEditView`. Older messages fetched by the load‑older AJAX path show a saved score **read‑only**; the rating control lives only on the server‑rendered thread, where the form carries its own CSRF token.

---

## 6. Database reference — every table (when, where, why)

23 application tables (+ Yiisoft's `migration` bookkeeping), verified against the schema. Grouped by domain. "Since" = the migration that introduced it.

### 6.1 Identity & auth
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **admin_users** | `M260724100000` | Admin accounts for the `/admin` realm; loaded every request by `RequireAdminMiddleware`. | `username` (unique), `password_hash`, `is_active`, `last_login_at`. |
| **auth_login_attempts** | `M260724100300` | Login throttling/lockout, keyed by a hashed attempt key. | `attempt_key` (PK), `attempts`, `window_started_at`, `locked_until`. |

### 6.2 Knowledge bases & their "answering rules"
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **knowledge_bases** | `M260724100100` (+ source cols `M260728120200`, `purpose` `M260805120000`) | The central entity: one per store (or the hidden rules base). Drives provisioning, chat eligibility, the store directory. | `slug` (unique), `openai_vector_store_id` (unique), `vector_store_status`, `status` (active/archived), `source_system`/`source_store_id`/`source_active` (Order58 link), `agent_enabled`, `purpose` (`store`/`shared_rules`). |
| **knowledge_base_rules** | `M260724100200` | The KB's **behavioural answering rules** injected into chat instructions (tone/precedence) — *distinct from the Order58 rule catalog*. | `knowledge_base_id`, `instruction`, `priority`, `is_enabled`. |

### 6.3 Documents & indexing
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **documents** | `M260725120000` (+ source cols `…120300`, source_text `…140000`, override `M260803120000`) | Every uploaded or generated document; the worker's processing queue. | `knowledge_base_id`, `status` (uploaded…ready/failed/deleted), `kind`, `source_type` (see below), `source_ref`, `source_sync_hash`, `is_enabled`, `is_source_overridden`, `dedupe_hash`. |
| **document_index_files** | with documents (`M260725120000`; removal flag `M260726090000`, status index `M260806100000`) | The **searchable copy** in the vector store — the durable readiness signal for chat. | `document_id`, `openai_file_id` (unique), `index_status` (pending…completed), `pending_removal`, `role`. |
| **document_processing_events** | with documents | Append‑only audit of every processing transition. | `document_id`, `status`, `metadata_json`. |

**`documents.source_type` values** (`DocumentSourceType`): `order58_store_profile`, `order58_knowledge`, `order58_rule_store`, `order58_rule_global`, `order58_rule_common`, `uploaded_pdf`, `uploaded_image`, `uploaded_text`, `manual_text`. **Qualifying chat content** = everything *except* the store profile and the three rule projections.

### 6.4 Chat
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **conversations** | `M260727100000` (+ agent `M260728130000`, typed participants `M260804120000`) | One canonical thread per participant per KB. | `knowledge_base_id`, `participant_type` (admin/agent) + `participant_id` (**unique** `ux_conversations_kb_participant_typed`), `agent_admin_id` (legacy), `last_message_at`. |
| **messages** | `M260727100000` (+ answer_source `M260805130000`, editing `M260804130000`) | The turns. | `conversation_id`, `role`, `content`, `citations_json`, `is_grounded`, `retrieval_status`, `openai_response_id`, `answer_source`, `reply_to_message_id`, `superseded_at`, `edit_count`, `active_answer_key` (**unique** → one active answer/question). |
| **message_revisions** | `M260804130000` | Audit of edited questions (prior text + who/when). | `message_id`, `revision_number` (unique w/ message), `content`, `edited_by_type`/`edited_by_id`. |
| **chat_answer_scores** | `M260812100000` | Per‑participant 1–10 feedback on an assistant answer (§5.11). Kept off `messages` so an answer stays immutable and an admin and an agent can rate the same answer independently. | `message_id` (FK → `messages`, CASCADE), `participant_type`/`participant_id`, `score` (NULL or 1–10), `dismissed_at`, unique `(message_id, participant_type, participant_id)`. |

### 6.5 Order58 mirrors & sync
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **order58_stores** | `M260728120000` | Mirror of Order58 stores; source for the store directory + each store KB. | `source_id` (unique), `active`, `snapshot_json`, `sync_hash`, `last_seen_sync_run_id`. |
| **order58_agents** | `M260728120000` | Mirror of Order58 agents (reference/reporting). | `admin_id`, `status`, `user_type`, `account_id`, `sync_hash`. |
| **order58_knowledge_records** | `M260728120000` | Mirror of store knowledge items → generates `order58_knowledge` documents. | `source_id` (unique), `store_source_id`, `title`, `content`, `active`, `sync_hash`. |
| **order58_rule_records** | `M260805100000` | Raw mirror of Order58 rules → feeds the rule catalog. | `source_id` (unique), `title`, `description`, `is_active`, `sync_hash`. |
| **integration_sync_runs** | `M260728120100` | The sync job queue + history (one active per type). | `type`, `status`, `progress_json`, `active_key` (unique), `claimed_at`, `completed_at`. |
| **order58_daily_sync_schedules** | `M260807100000` | Per‑NY‑day idempotency reservation for the two daily schedulers. | unique `(sync_type, ny_date)`, `status` (pending/enqueued/failed), `integration_sync_run_id`. *(Present in code; the shared dev DB may be a migration behind.)* |

### 6.6 Rule catalog, classification, projection
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **rule_catalog_rules** | `M260805100100` (title widen `…100200`, global flag `M260805140000`) | The canonical, deduped rule; the thing admins review; drives projection. | `canonical_hash` (unique), `scope_type`, `classification_status`, `is_active`, `is_globally_available`. |
| **rule_catalog_sources** | `M260805100100` | Maps a canonical rule to its raw mirror record(s). | `rule_catalog_rule_id`, `order58_rule_record_id` (unique), `relation_type`. |
| **rule_store_links** | `M260805110000` | Which store(s) a rule matches, and how confidently. | `rule_catalog_rule_id`, `store_source_id`, `match_status`, `match_method`, unique `(rule, store)`. |
| **rule_classification_events** | `M260805110000` | Append‑only audit of every classification/review transition. | `rule_catalog_rule_id`, `event_type`, `old_status`→`new_status`. |
| **order58_store_aliases** | `M260805110000` | Alternate store names to improve rule→store matching. | `store_source_id`, `normalized_alias`, unique `(store, normalized_alias)`. |

### 6.7 AI reliability
| Table | Since | Why / where used | Key columns |
|---|---|---|---|
| **ai_operations** | `M260725140000` | Idempotent, reconcilable ledger wrapping OpenAI create calls (vector store, files). | `operation_key` (unique), `status` (pending/in_flight/succeeded/needs_reconcile/failed), `request_fingerprint`, `idempotency_key`, `result_id`. |

---

## 7. Console commands (`config/console/commands.php`)

| Command | Purpose |
|---|---|
| `kf:worker:run` | The background worker: provision → process → cleanup drainers. Run every minute via cron + a **dedicated** flock file. |
| `kf:documents:recover` | Recovers stuck/failed documents. |
| `kf:ai:reconcile` | Reconciles `ai_operations` left `needs_reconcile` after a crash. |
| `kf:order58:reconcile-active` | Reconciles store active‑status from mirror data. |
| `kf:order58:schedule-knowledge` / `…:schedule-rules` | The two daily schedulers (02:00 / 03:00 NY, enqueue‑only). |
| `kf:rules:reconcile-global` | Backfills the global projection for every active rule (idempotent, no OpenAI). |
| `kf:rules:repair-lifecycle` | Repairs rule index‑file lifecycle state. |
| `kf:rules:retire-store-projections` | **Fleet retire** of `order58_rule_store` documents from store KBs. `--dry-run` reports scope first; the real run enqueues retirement (worker removes remotely). |
| `kf:admin:create` | Create an admin account. |
| `kf:openai:ping` | Connectivity check to OpenAI. |
| `chat:thread-merge-report` / `chat:participant-backfill-report` | Chat data audits. |

---

## 8. Routes (`config/common/routes.php`)

**Admin group** (`RequireAdminMiddleware`):
- Store directory `GET /admin/order58/stores` → `order58.stores`; Store‑chat picker `GET /admin/order58/store-chat`; Data management + sync `POST /admin/order58/sync`.
- Rules: readiness `GET /admin/order58/rules/readiness`, list `…/rules/list`, hidden global `…/rules/global`.
- KB manage `GET /knowledge-bases/{slug}`; chat `GET|POST /knowledge-bases/{slug}/chat`, history/show/ask, `…/messages/{id}/edit|regenerate|score|dismiss-score`.
- Rule Chat `GET|POST /admin/rule-chat`, history/show/ask, `…/messages/{id}/edit|regenerate|score|dismiss-score`.
- Source transparency (GET, read‑only): `…/chat/knowledge`, `…/chat/rules`, `/admin/rule-chat/rules`.

**Agent group** (`RequireAgentMiddleware`): `GET /agent/stores` (home), `/agent/stores/{slug}/chat*` and `/agent/rule-chat*` mirroring the admin chat routes (`agent.chat.*`, `agent.rule-chat.*`) — including the `score` / `dismiss-score` pair and the same read‑only source pages. Login routes sit outside both groups.

Nav is declared as PHP arrays in the sidebars (`src/Web/Shared/Layout/Admin/_sidebar.php`, `src/Agent/Web/Layout/_sidebar.php`); a route that isn't registered is silently skipped. Both sidebars carry an A–Z index built from `AlphabetIndex`, linking into the listing's own `?letter=` filter rather than duplicating it — admin's browses stores, the agent's browses the stores that agent may chat with.

---

## 9. Configuration & environment

- `src/Environment.php` `SPEC` is the config authority (typed env with defaults). Notable: `DB_*`, `OPENAI_*`, `CHAT_*` (model, `CHAT_REQUIRE_CITATIONS`, `CHAT_MIN_CITATION_SCORE`, `CHAT_FORCE_FILE_SEARCH`, history limits), `APP_TIMEZONE`, the Order58 base URL/token.
- Params/DI: `config/common/params.php`, `config/common/di/**`, `config/web/di/**`, `config/console/**`.
- Deploy assets live in `docs/deploy` and `docs/nginx`.

---

## 10. Invariants & safety rules (do not break)

1. **Browser/CLI never call OpenAI** — they enqueue; the worker (flock‑guarded) does all OpenAI indexing/cleanup. Chat is the only synchronous OpenAI call.
2. **Grounded‑or‑fallback** — an uncited/unretrieved answer is discarded for the fallback.
3. **Usable = completed index snapshot**, never `documents.status` alone.
4. **One canonical chat‑eligibility definition** mirrored in PHP (`ChatAvailabilityPolicy`) and SQL (`KnowledgeBaseChatEligibilitySql`), cross‑checked by a test.
5. **Exactly one active answer per question** (`ux_messages_active_answer`); superseded answers are hidden from the thread and OpenAI history but kept for audit.
6. **DB timestamps are UTC**; `APP_TIMEZONE` is display/business only.
7. **Idempotent sync** on `sync_hash`; mark‑and‑sweep only after a completed final page; no duplicate KBs/mirror rows/documents.
8. **Store chat answers only from genuine store knowledge** — no rule documents, no global/common rules base (Phase 1 boundary).
9. **Migrations reversible & prod‑safe**; add an index only when `EXPLAIN` shows the need.
10. **Scoring is feedback, never content** — it makes no provider call, cannot alter an answer, and a dismissal stores no score (never a zero). A superseded or user‑role message is not scorable.
11. **Shared view paths are absolute** (`SourceViews`, `ChatPartials`) — a `@src/...` alias in a view name is not expanded and takes the page down with "The view path is not set.".

---

## 11. Glossary

- **Store** — an Order58 business, mirrored into `order58_stores`, mapped 1:1 to a knowledge base.
- **Knowledge base (KB)** — a corpus + its OpenAI vector store; `purpose='store'` for stores, `purpose='shared_rules'` for the hidden Global Rules base.
- **Vector store** — OpenAI's searchable index of a KB's files (File Search).
- **Generated document** — a document deterministically produced from an Order58 source (store profile, knowledge record, rule projection), upserted by `SyncDocumentService`.
- **Projection** — turning a canonical rule into an indexed document (global, and historically store‑scoped).
- **Grounding** — the check that an answer is supported by ≥1 valid citation resolving to a live document in the same KB.
- **Fallback** — the safe "not enough information" reply stored when grounding fails.
- **Realm** — the admin surface vs the agent surface, each with its own middleware, layout, and authorisation.
- **Answer score** — a participant's 1–10 rating of one assistant answer (`chat_answer_scores`); a *dismissal* is the explicit decision not to rate, and carries no score.
- **Retrievable** — a document with a completed index snapshot that is also inside the surface's `ChatRetrievalScope`; what the source‑transparency pages label "available to this chat".

---

*Generated as a single reference for the current codebase (through source transparency and 1–10 answer scoring, 2026‑08‑12). When code and this doc disagree, the code wins — update this file.*
