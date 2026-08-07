# Knowledge Forge — Zero to End: How It Works, Where, When & Why

**Two things in one document.**

- **Part I — The journey.** Every step from an empty server to a store answering a question, in order. Each step answers **WHAT** happens, **WHERE** it happens (file + table), **WHEN** it is triggered, **WHY** it was built that way, and the **exact rows** written.
- **Part II — The reference.** Column-level schema for all 21 tables, the complete external API catalog, and route/command/worker → write-effect matrices.

Read Part I front to back once. Then use Part II as a lookup.

| Related doc | Focus |
|---|---|
| [`SYSTEM_FLOW_STATUS_AND_SCHEMA.md`](SYSTEM_FLOW_STATUS_AND_SCHEMA.md) | Narrative flow, status lifecycles, vector-store lifecycle |
| [`ORDER58_INTEGRATION.md`](ORDER58_INTEGRATION.md) | Order58 integration design |
| [`knowledge_base_store_wise.md`](knowledge_base_store_wise.md) | Order58 store → KB → chat, A→Z |
| [`PROJECT_GUIDE.md`](PROJECT_GUIDE.md) | Setup, env, routes, deploy |
| [`OPENAI_TECHNICAL_AND_COST_AUDIT.md`](OPENAI_TECHNICAL_AND_COST_AUDIT.md) | OpenAI cost & technical audit |
| [`deploy/worker.md`](deploy/worker.md) | Cron + flock worker operations |

---

## Table of contents

**Part I — Zero to end**

- [The one rule that explains the whole architecture](#the-one-rule-that-explains-the-whole-architecture)
- [Step 0 — Empty server → schema](#step-0--empty-server--schema)
- [Step 1 — The first administrator](#step-1--the-first-administrator)
- [Step 2 — Login](#step-2--login)
- [Step 3A — Create a knowledge base by hand](#step-3a--create-a-knowledge-base-by-hand)
- [Step 3B — Or mirror every store from Order58](#step-3b--or-mirror-every-store-from-order58)
- [Step 4 — Worker: provision the vector store](#step-4--worker-provision-the-vector-store)
- [Step 5 — Add knowledge (four input types)](#step-5--add-knowledge-four-input-types)
- [Step 6 — Worker: process the document](#step-6--worker-process-the-document)
- [Step 7 — When does chat actually turn on?](#step-7--when-does-chat-actually-turn-on)
- [Step 8 — Ask a question](#step-8--ask-a-question)
- [Step 9 — Edit or regenerate](#step-9--edit-or-regenerate)
- [Step 10 — Change knowledge: re-index, disable, delete, cleanup](#step-10--change-knowledge-re-index-disable-delete-cleanup)
- [Step 11 — Rules: sync → classify → review → dual projection](#step-11--rules-sync--classify--review--dual-projection)
- [Step 12 — The agent realm](#step-12--the-agent-realm)
- [Step 13 — Steady state: every minute, forever](#step-13--steady-state-every-minute-forever)
- [The complete picture on one page](#the-complete-picture-on-one-page)

**Part II — Reference**

- [R1. Table inventory](#r1-table-inventory)
- [R2. Full schema, table by table](#r2-full-schema-table-by-table)
- [R3. External API catalog](#r3-external-api-catalog)
- [R4. Route → write-effect matrix](#r4-route--write-effect-matrix)
- [R5. Console command → write-effect matrix](#r5-console-command--write-effect-matrix)
- [R6. Worker pass → write-effect matrix](#r6-worker-pass--write-effect-matrix)
- [R7. Constraints that shape inserts](#r7-constraints-that-shape-inserts)
- [R8. Delete & cascade behavior](#r8-delete--cascade-behavior)
- [R9. Environment variables that change behavior](#r9-environment-variables-that-change-behavior)
- [R10. Troubleshooting by symptom](#r10-troubleshooting-by-symptom)

**Legend used throughout**

| Tag | Meaning |
|---|---|
| **INS / UPD / DEL** | Row inserted / updated / hard-deleted |
| **SOFT** | Row survives; a flag or timestamp marks it inactive |
| **UPSERT** | Insert-or-update keyed on a unique index |
| 🌐 | Calls `api.openai.com` |
| 🔗 | Calls the Order58 integration API |
| 🕒 | Happens **only** in the cron worker, never in a web request |

---

# PART I — ZERO TO END

## The one rule that explains the whole architecture

> **A web request never calls OpenAI for indexing.**
> Upload, Process next, Re-index, Retry, Sync — every one of them only writes local database rows and returns immediately. The cron worker does all the OpenAI network work.
>
> **The one synchronous provider call on the web tier is chat** (`POST /responses`).

**Why this rule exists.** Indexing is slow (uploads, vision extraction, polling until the vector store finishes) and unreliable (rate limits, timeouts). If a browser request owned that work, three bad things follow: the admin stares at a spinner for a minute, a PHP-FPM worker is tied up for the duration, and a lost connection halfway through leaves a half-indexed document with no record of what happened. By reducing every button to "write a row", a click is always fast, always atomic, and always resumable — the row *is* the durable record of intent.

**How the consequence shows up everywhere.** There is no Redis, no SQS, no message broker. **Rows in specific states *are* the queues:**

| Queue | The query the worker runs | Drained by |
|---|---|---|
| `integration_sync_runs` where `status='pending'` | ordered by `next_attempt_at, id` | Stage 1 |
| `knowledge_bases` where `vector_store_status='pending'` | index `ix_knowledge_bases_provisioning` | Stage 2 |
| `documents` where `status IN ('queued','indexing')` | index `ix_documents_queue` | Stage 3 |
| `document_index_files` where `pending_removal=1` | index `ix_index_files_removal` | Stage 4 |

Each of those four indexes exists **specifically** to make its queue query cheap. That is why they are shaped `(status, priority, next_attempt_at, id)` rather than on single columns.

---

## Step 0 — Empty server → schema

**WHAT.** A bare checkout becomes a running application with 21 tables.

**WHERE.**

```bash
composer install --no-dev     # dependencies
cp .env.example .env          # configuration — edit it
./yii migrate:up              # ← creates the schema
./yii kf:health               # verify config + DB, no network calls
./yii kf:openai:ping          # verify OpenAI key, chat model, vision model (real calls)
```

Migrations live in [src/Migration/](src/Migration/), 25 files, applied in filename order (`M<YYMMDDHHMMSS><Name>`).

**WHEN.** Once at install; again after every deploy that ships a new migration.

**WHY the ordering matters.** Reading the filenames in order is reading the product's history, and each name tells you which feature forced a schema change:

| Migration | What it added | Why |
|---|---|---|
| `M260724100000CreateAdminUsers` | `admin_users` | Someone has to log in first |
| `M260724100100CreateKnowledgeBases` | `knowledge_bases` | The central entity |
| `M260724100200CreateKnowledgeBaseRules` | `knowledge_base_rules` | Prompt guidance per base |
| `M260724100300CreateAuthLoginAttempts` | `auth_login_attempts` | Brute-force throttle |
| `M260725120000CreateDocuments` | `documents`, `document_index_files`, `document_processing_events` | The knowledge unit + its remote artifacts + its audit trail |
| `M260725140000CreateAiOperations` | `ai_operations` | Crash-safety for provider calls |
| `M260726090000AddIndexFileRemovalFlag` | `pending_removal` | Split "flag it" (web) from "delete it remotely" (worker) |
| `M260727100000CreateConversations` | `conversations`, `messages` | Chat |
| `M260728120000CreateOrder58Mirrors` | 3 mirror tables | Integration |
| `M260728120100CreateIntegrationSyncRuns` | `integration_sync_runs` | The sync job queue |
| `M260728120200/300` | `source_*` columns | Provenance on KBs and documents |
| `M260728130000AddConversationAgent` | `agent_admin_id` | Agents get their own threads |
| `M260728140000AddDocumentSourceText` | `source_text` | Generated documents keep their text |
| `M260803120000AddDocumentSourceOverride` | `is_source_overridden` | Admin edits must survive the next sync |
| `M260803160000CanonicalChatThreads` | Thread uniqueness | Stop duplicate threads |
| `M260804120000TypedChatParticipants` | `participant_type/_id` | One typed identity model for admin + agent |
| `M260804130000AddMessageEditingAndRevisions` | `message_revisions`, `reply_to_message_id`, `active_answer_key` | Editing, with audit + one-live-answer |
| `M260805100000/100/200` | Rule mirror + catalog | Order58 rules |
| `M260805110000CreateRuleClassification` | Links, events, aliases | Which store does a rule belong to |
| `M260805120000AddKnowledgeBasePurpose` | `purpose` | Distinguish store bases from the hidden rules base |
| `M260805130000AddMessageAnswerSource` | `answer_source` | Record *which* stage answered |
| `M260805140000AddRuleGlobalAvailability` | `is_globally_available` | Toggle the global projection |

**ROWS WRITTEN.** **INS** `migration` (one row per applied file) + DDL.

**VERIFY.**

```sql
SELECT name, FROM_UNIXTIME(apply_time) FROM migration ORDER BY id DESC LIMIT 5;
```

---

## Step 1 — The first administrator

**WHAT.** Creates the one account that can reach `/`.

**WHERE.** `./yii kf:admin:create` → **INS** `admin_users` (`username`, `password_hash`, `is_active=1`). The password is generated and printed **once**.

**WHEN.** Once at install. There is deliberately **no** signup route and no admin-creates-admin UI.

**WHY console-only.** A self-service or web-exposed account-creation path is a permanent attack surface on an internal tool that needs exactly one privileged human. Shell access is already the strongest gate on the box, so the command reuses it instead of inventing a weaker one.

**WHY `is_active` is re-read every request.** `RequireAdminMiddleware` reloads this row on **every** admin request rather than trusting the session. Setting `is_active=0` therefore locks the account out on the very next click — no session invalidation sweep, no waiting for expiry.

---

## Step 2 — Login

**WHAT.** Two independent authentication realms.

### Admin — `POST /login`

```
throttle check   → auth_login_attempts (by a hashed key, never the username)
password verify  → admin_users.password_hash
success          → UPD admin_users.last_login_at ; clear the throttle row
failure          → INS/UPD auth_login_attempts (attempts+1, maybe locked_until)
```

**WHY the key is hashed.** `attempt_key` is `char(64)` — a hash, not a username. A throttle table keyed by plaintext username is an account-existence oracle: an attacker who can read it (or infer timing from it) learns which usernames are real. Hashing removes that signal entirely.

### Agent — `POST /agent/login`

```
throttle check   → auth_login_attempts        ← BEFORE any network call
🔗 POST /authenticate  (live Order58 API)
gate: authenticated AND user_type == 'agent' AND status == active
success          → session identity only ; clear the throttle
```

**WHY throttle first, network second.** The upstream API is somebody else's server. Checking the local throttle before calling it means a credential-stuffing run against this app never becomes a credential-stuffing run against Order58.

**WHY authenticate live rather than against the mirror.** `order58_agents` holds **safe fields only — never a credential**. Agent identity is Order58's to own; mirroring password material would duplicate a secret this system has no business holding. The mirror exists for profile display, reporting, and the `user_type == agent` gate — not for authentication.

**ROWS WRITTEN.** `auth_login_attempts` (+ `admin_users.last_login_at` for admins). **Agent login writes nothing else** — not even a `order58_agents` touch.

---

## Step 3A — Create a knowledge base by hand

**WHAT.** `POST /knowledge-bases` → one row, `vector_store_status='pending'`.

**WHERE.** `Kb\Create\StoreAction` → **INS** `knowledge_bases`.

**WHEN.** Admin submits the create form.

**WHY it starts `pending` and not `ready`.** The row is a *declaration of intent*: "this base should have a vector store." Creating the store means a network call to OpenAI, which is exactly the work that must not happen in a web request (see [the one rule](#the-one-rule-that-explains-the-whole-architecture)). `pending` hands the job to the worker without any coordination beyond a column value.

**WHY the state is on the row itself, not in a jobs table.** There is one obvious place to look when a base is stuck (`vector_store_status`, `provision_attempts`, `vector_store_error`), and the state can never drift from the entity it describes.

**ROWS.**

```
INS knowledge_bases (
  name, slug, description, system_instructions,
  vector_store_status = 'pending',      ← the queue signal
  provision_attempts  = 0,
  status              = 'active',
  purpose             = 'store'
)
```

`ux_knowledge_bases_slug` rejects a duplicate slug at the database level, not just in validation.

---

## Step 3B — Or mirror every store from Order58

**WHAT.** One click mirrors hundreds of stores and creates a knowledge base for each.

**WHERE / WHEN.**

```
POST /admin/order58/sync   (type=stores)
  └─ INS integration_sync_runs(status='pending', type='stores')   ← the ENTIRE web request
       ↓  cron, within a minute
  worker stage 1, IntegrationSyncDrainer → StoresSyncHandler
```

**WHY the button only enqueues.** Mirroring 233 stores means dozens of paginated HTTP calls to a third-party API. That cannot live inside a browser request under any timeout policy. The run row makes the work durable, resumable, observable (`progress_json`), and — critically — **single-flighted**.

**WHY it cannot double-run.** `integration_sync_runs.active_key` is a generated column:

```sql
active_key = IF(status IN ('pending','running'),
                SHA2(CONCAT(type, ':', COALESCE(scope_ref, 0)), 256),
                NULL)
UNIQUE ux_integration_sync_runs_active (active_key)
```

While a run is pending or running the hash is a real value, so a second insert of the same type/scope collides on the unique index and is rejected. When it reaches a terminal status the expression yields NULL — and MySQL unique indexes ignore NULLs, so the next run is allowed. **Double-clicking Sync is safe by schema design, not by a JavaScript guard.** This same NULL-when-inactive trick appears twice more (Steps 5 and 9); once you see it, you can read half the schema.

**What one page of the run does** (🔗 `GET /accounts?page=N&per_page=100`):

```
for each account on the page:
    UPSERT order58_stores            ← keyed ux_order58_stores_source (source_id)
    if sync_hash unchanged → SKIP the rest      ← the cost control
    INS/find knowledge_bases         ← keyed ux_knowledge_bases_source
    UPSERT documents                 ← source_type='order58_store_profile'
    INS order58_store_aliases        ← official/company/domain/generated names
UPD integration_sync_runs.progress_json = {"nextPage": N+1}
```

**WHY `sync_hash`.** Without it, every sync would rewrite every store-profile document, which would requeue every document, which would re-upload and re-index everything at OpenAI — hundreds of pointless paid operations per run. The hash makes the sync **change-driven**: unchanged store, zero work, zero cost.

**WHY `ux_knowledge_bases_source (source_system, source_store_id)`.** It is the idempotency guarantee. Run the sync a hundred times and store #482 still has exactly one knowledge base. Likewise `ux_documents_source (kb_id, source_type, source_ref)` guarantees one store-profile document per base.

**WHY the sweep only runs after the final page.** At the end of a *fully successful* scan, stores the source stopped returning are deactivated and their KBs marked `source_active=0`. If page 4 of 9 fails, **no sweep happens at all** — a partial fetch must never be read as "these stores were deleted upstream." Deactivation is also soft: vector stores and chat history survive, because a store vanishing from a listing is not the same as an instruction to destroy its knowledge.

**Related mirrors, same shape:**

| Sync type | API | Mirrors into | Produces documents? |
|---|---|---|---|
| `stores` | `GET /accounts` | `order58_stores` | Yes — store profile |
| `knowledge` | `GET /knowledge` | `order58_knowledge_records` | Yes — one per active record |
| `agents` | `GET /agents` | `order58_agents` | **No**, and never calls OpenAI |
| `rules` | `GET /rules` | `order58_rule_records` | Not directly — see Step 11 |
| `rebuild_store` | **none** | — | Yes, forced rewrite from the local mirror |
| `health` | `GET /health` | — | No |

**WHY knowledge-before-store is tolerated.** If a knowledge record arrives for a store that has not been synced yet, it is still mirrored and still marked seen (so the sweep will not wrongly deactivate it), but no document is produced and the run ends `completed_with_warnings`. Once the store is synced, the next run generates the document. The alternative — failing the run — would let one out-of-order record block hundreds of good ones.

---

## Step 4 — Worker: provision the vector store

**WHAT.** The `pending` knowledge base gets its OpenAI vector store.

**WHERE.** Worker stage 2, [`KnowledgeBaseProvisioningDrainer`](src/KnowledgeBase/Application/KnowledgeBaseProvisioningDrainer.php) → `ProvisionKnowledgeBaseService`.

**WHEN.** The next cron pass after Step 3.

**The exact sequence, and why each line exists:**

```
1. claim atomically:  UPD knowledge_bases
                      SET vector_store_status='provisioning', provision_attempts+1,
                          provision_started_at=now
                      WHERE id=? AND vector_store_status='pending'
                                                   ↑ WHY: two workers can never
                                                     double-provision — only one
                                                     UPDATE matches.

2. INS ai_operations(operation_key='vs.create:kb:123', status='pending')
                                                     WHY: see the crash story below.

3. UPD ai_operations → 'in_flight', idempotency_key=<uuid>, started_at=now

4. 🌐 POST /vector_stores  { name, metadata: { kf_op: 'vs.create:kb:123' } }
                                                     WHY the metadata: see below.

5. UPD ai_operations → 'succeeded', result_id='vs_abc…', completed_at=now
6. UPD knowledge_bases → 'ready', openai_vector_store_id='vs_abc…'
```

**WHY `ai_operations` exists at all — the crash story.** Suppose the process dies between steps 4 and 5. OpenAI created the vector store and charged for it; this database has no record of the id. On the next pass the naive behavior is to create *another* store — an orphan that is paid for, never used, and never cleaned up. Repeat under a flapping network and you leak stores indefinitely.

`ai_operations` closes that hole. The row is written **before** the call, so a crash leaves durable evidence that a call was in flight. The create stamps `metadata.kf_op = <operation_key>` on the remote object, so `./yii kf:ai:reconcile` can later run 🌐 `GET /vector_stores`, find the store whose `kf_op` matches the stranded operation, and **adopt** it — marking the operation `succeeded` and writing the id onto the knowledge base. **The remote object carries the local operation's name.** That is what makes recovery possible instead of merely detectable.

**WHY only `vs.create` uses this today.** `OperationTypes` declares `vs.create`, `file.upload` and `vs.attach`, but only `vs.create` is wrapped in `ReliableOperation`. The reason is asymmetric cost of duplication: a leaked *vector store* is a durable, billable, invisible object; a leaked *file upload* is caught by `ux_index_files_openai` and swept up by the cleanup drainer. The ledger was spent where it buys the most.

**WHY it does nothing when OpenAI is unconfigured.** The drainer checks credentials first and exits. Otherwise every pass would burn a `provision_attempts` increment on a call that cannot possibly succeed, and the base would exhaust its attempts and land in `failed` for a *configuration* problem — the most misleading failure mode available.

**ROWS.** **INS** `ai_operations` ×1; **UPD** `ai_operations` ×2–3; **UPD** `knowledge_bases` ×2.

**VERIFY.**

```sql
SELECT slug, vector_store_status, provision_attempts, vector_store_error
FROM knowledge_bases WHERE vector_store_status <> 'ready';

SELECT operation_key, status, result_id, attempts, last_error_code
FROM ai_operations WHERE status IN ('in_flight','needs_reconcile','failed');
```

---

## Step 5 — Add knowledge (four input types)

**WHAT.** Four ways in, one table out. Every one of them lands in `documents` with `status='queued'` and **makes no network call**.

| Input | Route | `source_type` | `kind` |
|---|---|---|---|
| PDF upload | `POST …/documents` | `uploaded_pdf` | `pdf` |
| Image upload | `POST …/documents` | `uploaded_image` | `image` |
| `.txt` / `.md` upload | `POST …/documents` | `uploaded_text` | `text` |
| Typed text | `POST …/manual-text` | `manual_text` | `text` |
| *(generated)* | Order58 sync | `order58_store_profile`, `order58_knowledge` | `text` |
| *(generated)* | Rule approval | `order58_rule_store`, `order58_rule_global`, `order58_rule_common` | `text` |

**The upload path, step by step** ([`UploadValidator`](src/Document/Application/Validation/UploadValidator.php)):

```
1. size check              MAX_UPLOAD_SIZE_MB=25 (images: MAX_IMAGE_UPLOAD_SIZE_MB=8)
2. MIME SNIFFED from content, not from the client-sent header
      allowed: application/pdf, image/png, image/jpeg, image/webp,
               text/plain, text/markdown
3. safe filename generated  → storage_token (char(32)), never the user's filename on disk
4. sha256 checksum
5. file written  runtime/storage/knowledge-bases/{kbId}/
6. INS documents(status='queued', priority, checksum_sha256, …)
7. if the INSERT fails, the file just written is REMOVED
```

**WHY sniff the MIME.** A browser-supplied `Content-Type` is attacker-controlled. `evil.php` announced as `application/pdf` must be rejected on what the bytes *are*, not on what the request *claims*.

**WHY a `storage_token` instead of the original filename.** The user's filename never becomes a path. That removes path traversal, encoding tricks, collisions, and unit-inconsistent filesystem behavior in one move. `original_filename` is kept purely for display and citation — it is data, never a path.

**WHY delete the file when the insert fails.** Ordering is deliberate: write the file, then insert the row. If the insert fails (dedupe collision, DB error) the file is orphaned — so it is removed immediately. The invariant is *no file on disk without a row pointing at it*.

**WHY the dedupe key is a generated column.**

```sql
dedupe_hash = IF(status = 'deleted', NULL, checksum_sha256)   -- STORED
UNIQUE ux_documents_dedupe (knowledge_base_id, dedupe_hash)
```

The requirement is subtle: the same file must not exist twice **in one base at the same time**, but after deleting it an admin must be able to re-upload it. A plain unique index on `checksum_sha256` would forbid the re-upload forever (documents are soft-deleted, so the old row remains). The generated column makes a deleted document's key NULL, and unique indexes ignore NULLs — so the constraint applies to live rows only, while history is preserved intact. **Same trick as `active_key` in Step 3B and `active_answer_key` in Step 9.**

**Manual text and generated documents** skip file sniffing (there is no upload) and store their content in `documents.source_text`. Editing a *generated* document sets `is_source_overridden=1`, which is the flag that tells the next Order58 sync **not to overwrite the admin's edit**. `POST …/reset-order58` clears it and requeues, handing control back to the sync.

**ROWS.** **INS** `documents` (+ a file on disk for uploads). Nothing else. No OpenAI call.

---

## Step 6 — Worker: process the document

**WHAT.** `queued` → `ready`, over several cheap worker passes.

**WHERE.** Worker stage 3, [`DocumentProcessingDrainer`](src/Document/Application/Processing/DocumentProcessingDrainer.php) → [`ProcessDocumentService`](src/Document/Application/Processing/ProcessDocumentService.php).

**WHEN.** Every cron pass, for the highest-priority eligible document.

**Gate first:** the drainer only touches a document whose knowledge base's vector store is already `ready`. If provisioning has not finished, the document is **left unclaimed** — not claimed-and-failed. **WHY:** claiming would burn a `processing_attempts` increment on a document that did nothing wrong, and after three passes a perfectly good document would be `failed` because provisioning was slow.

**Then claim atomically**, same pattern as Step 4: `UPDATE … WHERE id=? AND status='queued'`. One winner, always.

### Three pipelines, chosen by `kind`

**A. PDF with a text layer** — indexed directly.

```
probe the text layer (chars per page)
PdfIngestionPolicy.decide():
   chars_per_page >= PDF_MIN_TEXT_CHARS_PER_PAGE (100)  → index the PDF directly
   otherwise → vision, if within PDF_VISION_MAX_BYTES / _MAX_PAGES
   otherwise → manual review (permanent failure with an actionable message)
```

**WHY "positive evidence only."** The policy's inviolable rule: a PDF is indexed directly **only on positive evidence of a text layer**. If the probe was skipped or failed, that is *absence of evidence*, and absence is never treated as "has text" — it routes to vision. The failure being prevented is the worst kind: a scanned PDF indexed as an empty document, reporting `ready`, retrieving nothing, and silently degrading every answer with no error anywhere. A loud "split this PDF and re-upload" beats a silent empty index every time.

**B. Image** — never indexed as a file.

```
image → base64 data URL (inline, bounded by the image size limit)
🌐 vision model → Markdown transcription
persist to runtime/storage/knowledge-bases/{kbId}/derived/{hash}.md
index the MARKDOWN; the citation still resolves to the original image filename
```

**WHY transcribe instead of upload.** File Search retrieves text. An image uploaded as a file is not searchable. **WHY inline base64** instead of a temporary remote file: no remote object is created, so there is nothing to leak or clean up if the pass dies mid-flight. **WHY persist the Markdown:** vision is the most expensive step in the system, and a retry after a transient attach failure must not pay for it twice. The derived file is checked first and reused.

**C. Text** (uploaded text, manual text, all Order58-generated documents) — rendered to Markdown and indexed.

### The shared finish

```
🌐 POST /files                        → INS document_index_files(role, openai_file_id,
                                            index_status='pending')
🌐 POST /vector_stores/{vs}/files     → attach
poll ONCE (interval 3s, cap 60s), then DEFER   ← WHY: see below
   completed → UPD document_index_files.index_status='completed', usage_bytes
               UPD documents → 'ready', processed_at, last_indexed_at
               INS document_processing_events('ready')
   still in progress → UPD documents → 'indexing'   (resumed next pass)
   transient failure → UPD documents next_attempt_at (backoff)
                       INS document_processing_events('retry')
   attempts exhausted → UPD documents → 'failed', error_code
                        INS document_processing_events('failed')
```

**WHY one poll then defer.** The service never blocks on the provider. A document reaches `ready` across several cheap runs rather than one long blocking one — which is what makes this safe to run on a shared server where a wedged worker would starve everything else. A document that already has index files is simply *resumed*: it polls their state instead of re-uploading.

**WHY the service never throws.** Every outcome — including an unexpected non-provider error — is recorded on the document, so the worker loop can trust the return value and move to the next document. One poisoned document can never stop the queue.

**WHY messages are redacted.** `SecretRedactor` runs before any error text is stored in `documents.error_message` or `document_processing_events.message`. A raw provider exception can carry an API key fragment or an internal path, and this table is read by the admin UI. Messages are capped at 1000 chars for the same reason.

**WHY `document_processing_events` is append-only.** When a document fails at 3 a.m. and is retried into success at 3:05, the *current* row shows only `ready`. The event trail is the only artifact that can answer "was this flaky or is it fine now?" Nothing updates or deletes these rows.

**VERIFY.**

```sql
SELECT d.id, d.status, d.processing_attempts, d.error_code,
       dif.role, dif.index_status, dif.usage_bytes
FROM documents d
LEFT JOIN document_index_files dif ON dif.document_id = d.id
WHERE d.knowledge_base_id = ? AND d.status <> 'deleted';

SELECT status, message, created_at FROM document_processing_events
WHERE document_id = ? ORDER BY id DESC LIMIT 10;
```

---

## Step 7 — When does chat actually turn on?

**WHAT.** The single canonical availability decision, in [`ChatAvailabilityPolicy`](src/Chat/Application/ChatAvailabilityPolicy.php).

**The rule, all three parts:**

```
1. the base is active and its vector store is usable, AND
2. it has at least one USABLE QUALIFYING document
      qualifying  = NOT a store profile
      usable      = has a COMPLETED document_index_files row
      and an admin-disabled document never counts
3. if the base is Order58-linked, it ALSO needs a usable store-profile snapshot
```

**WHY the store profile never qualifies on its own.** It is generated metadata — name, company, contact. A store whose only knowledge is its own name cannot answer a customer question; it would produce fallback after fallback while the UI insisted chat was ready. Requiring one *real* knowledge document makes "available" mean "can actually answer something."

**WHY availability is measured on `document_index_files.index_status`, not `documents.status`.** This is the subtlest rule in the system and worth reading twice. During a re-index, `documents.status` flips back to `queued` — but the **old completed vector-store file is still attached and still perfectly answerable** (see Step 10). If availability read `documents.status`, every routine refresh would blink chat off for its duration, users would see a store "go down" for no reason, and support would chase a phantom. Reading the index files instead measures the **durable last-successful snapshot**: chat stays up on the old content until the new content is genuinely ready.

**WHY one class owns this.** Availability rules live nowhere else — no controller, no template, no agent service, no JavaScript re-implements them. Both the admin and the agent surface, and every server-side chat operation, call this one policy. A duplicated availability check that drifts by one condition produces the worst class of bug: a UI that offers a chat the server then refuses.

`ChatUnavailableReason` returns *why*, not just *whether* — so the UI can say "not provisioned yet" versus "no knowledge documents yet," which are completely different admin actions.

---

## Step 8 — Ask a question

**WHAT.** The one synchronous OpenAI call on the web tier — and the most carefully guarded path in the codebase.

**WHERE.** [`AskKnowledgeBaseService`](src/Chat/Application/AskKnowledgeBaseService.php).

### The full sequence

```
POST /knowledge-bases/{slug}/chat/{cid}
 1. assertChatAvailable()          ← Step 7's policy, server-side, always
 2. validate the question          ← CHAT_MAX_QUESTION_LENGTH=2000
 3. find or create the thread      → INS conversations (first question only)
 4. INS messages(role='user')      ← BEFORE the provider call. Always.
 5. build instructions             ← fixed precedence, below
 6. select bounded history         ← 10 messages / 8000 chars
 7. detect exhaustive intent       ← "list every…" needs a wider net
 8. 🌐 POST /responses             ← forced File Search
 9. resolve citations → documents
10. verify grounding               ← the gate, below
11. INS messages(role='assistant') ← via insertActiveAnswer()
12. UPD conversations.last_message_at
```

**WHY the question is persisted at step 4, before the call.** If OpenAI times out at step 8, the question is already durable. The user sees an error with their question still in the thread, and can retry. Persisting after the answer would mean a provider failure silently swallows what the user typed — and the thread would no longer make sense.

### Instruction precedence — [`InstructionBuilder`](src/Chat/Application/Instruction/InstructionBuilder.php)

```
1. the immutable security block
2. the knowledge base's own instructions (admin free text)
3. the enabled rules, numbered in priority order
4. the immutable reminder — restating that (1) wins over everything above
```

**WHY the security block is repeated at the end.** Instructions 2 and 3 are admin-authored free text, and a later instruction can talk a model out of an earlier one. Book-ending the untrusted middle with the security block means the last thing the model reads is the constraint that must win. **WHY the builder only renders:** rules arrive already filtered and ordered from the repository, so ordering policy lives in the repository and security policy lives in the builder — and the whole thing is a plain provider-neutral string, so swapping AI providers changes nothing here.

### Two-stage retrieval — the global rules mechanism

```
STAGE 1 — this store's own knowledge base
    grounded? → store it (store_knowledge, or store_rule when the
                winning citation is a store rule) and STOP.
                The store always has priority.

STAGE 2 — only if stage 1 produced nothing grounded, and the hidden
    Global Rules base is ready:
        ask THAT base — it holds a projection of EVERY approved rule
        grounded? → store it as global_rule
    A store-stage infrastructure failure PROPAGATES (the store is authoritative).
    A global-stage infrastructure failure is LOGGED and degrades to stage 3.

STAGE 3 — the store's ungrounded fallback (answer_source='fallback')
```

**WHY two stages instead of one merged index.** A rule captured against store A is frequently a policy that applies everywhere. Merging every rule into every store's vector store would bloat each index and dilute retrieval — the store's own documents would compete with hundreds of foreign rules on every query. Two stages keep the store's index clean and its answers authoritative, while still letting any store answer any approved rule. **The store is asked first, always, and its answer always wins.**

**WHY the two stages have different failure policies.** The store base is authoritative: if it is broken, the honest response is "temporarily unavailable." The global base is an enhancement: if it is broken, degrading to the store's fallback is strictly better than failing a request that already has a valid (if ungrounded) result in hand.

**Exactly ONE assistant message is ever written**, via `insertActiveAnswer()` — upholding the one-active-answer invariant even under a concurrent regeneration.

### The grounding gate — [`GroundingVerifier`](src/Chat/Application/Grounding/GroundingVerifier.php)

Runs on **every** response, **before** anything is stored or rendered. It never trusts the model's word that it used the documents.

```
1. file_search must actually have been CALLED        → else fallback
2. its status must be 'completed'                    → else fallback
3. ≥1 result at or above CHAT_MIN_CITATION_SCORE     → else fallback
4. unresolvable citation ids are dropped upstream
5. if citations are required and none resolved:
      the model's text is DISCARDED for the fallback sentence
      — an uncited factual answer is NEVER shown
   if citations are not required:
      the text is shown but flagged not grounded, so the UI can warn
```

**WHY not trust the model.** A model asked to use retrieval will sometimes answer from its own weights and sound completely confident doing it. For an internal knowledge tool, a fluent wrong answer is worse than no answer — it is acted upon. The verifier checks the *mechanical* facts of the response (was the tool called, did it complete, did it return scored results, did the citations resolve to real local documents) rather than anything the model asserts.

**WHY `retrieval_status` is stored either way.** The stored answer text is identical whether the verifier rejected the answer or the model refused on its own. With `store: false` on the provider side, the response cannot be fetched back later. `messages.retrieval_status`, `is_grounded`, `answer_source` and `openai_response_id` are the only durable record of what actually happened — without them the outcome is unrecoverable after the fact.

**One counting subtlety worth knowing before you read the logs:** annotation count and citation count are **not** subtractable. Several chunks of one document each carry their own annotation and collapse to a single citation by design. "16 annotations, 1 citation" is a healthy result, not 15 failures. Genuine resolution failures are logged individually by `CitationResolver`.

**Exhaustive intent.** "List every X" is detected by `ExhaustiveIntentDetector` and widens `max_results` while appending a directive to search several times for term variants, merge, dedupe, and cite per item. **WHY:** one relevance query returns the *closest* chunks, which structurally cannot enumerate a complete set.

**ROWS.** **INS** `messages` ×2 (user, assistant), **INS** `conversations` (first time), **UPD** `conversations.last_message_at`.

**WHY threads cannot duplicate.** `ux_conversations_kb_participant_typed (knowledge_base_id, participant_type, participant_id)` — one canonical thread per participant per base. An admin and an agent chatting with the same store get **separate** threads (different `participant_type`), and neither ever accumulates duplicates, no matter how many times Start is clicked.

---

## Step 9 — Edit or regenerate

**WHAT.** The latest question can be edited within `CHAT_EDIT_WINDOW_MINUTES` (20), and its answer regenerated.

**WHY only the latest question, and only for a while.** Editing an older question would invalidate every answer after it — a cascade with no good UI and no good audit story. Restricting the edit to the newest question means **exactly one** answer is ever invalidated. The time window bounds it further: an edit is a correction of a typo you just made, not a rewrite of history.

### The two-phase design

```
── PHASE 1: short DB transaction (durable facts only) ──────────────
UPD messages SET content=?, edited_at=now, edit_count=edit_count+1
    WHERE id=? AND edit_count=?          ← optimistic lock: two concurrent
                                           edits, one wins, no lost update
INS message_revisions(revision_number, content = the PREVIOUS text,
                      edited_by_type, edited_by_id)
UPD messages SET superseded_at=now       ← the old assistant answer
── COMMIT ──────────────────────────────────────────────────────────

── PHASE 2: OUTSIDE the transaction ────────────────────────────────
🌐 POST /responses
INS messages(role='assistant', reply_to_message_id = the question id)
```

**WHY the provider call is outside the transaction.** A 90-second HTTP call inside an open transaction holds row locks for its whole duration and can be killed by a database timeout, rolling back work that was already correct. Committing the durable facts first, then doing network work, is the only shape that survives a provider failure cleanly.

**WHY a failure after commit is *deliberately* left in place.** If the provider fails at phase 2, the committed edit stays with **no active answer** — a recoverable "Retry" state, reported through `MessageEditOutcome` rather than thrown as an exception. The question is never lost, and no duplicate active answer can appear.

**WHY that state is safe — `active_answer_key`:**

```sql
active_answer_key = CASE WHEN role='assistant' AND superseded_at IS NULL
                         THEN reply_to_message_id END        -- STORED
UNIQUE ux_messages_active_answer (active_answer_key)
```

A live answer holds its question's id in the index; a superseded answer yields NULL and drops out. **Two live answers to one question are impossible at the database level** — not by application discipline, by schema. That is why the retry state is safe: retrying either inserts the one missing answer or collides harmlessly. This is the third appearance of the NULL-when-inactive pattern (after `active_key` and `dedupe_hash`).

**WHY revisions are append-only.** `message_revisions` stores the text **before** each edit, with who edited it and when. Superseded answers stay in `messages` too — hidden in the UI, fully preserved for audit. Nothing is destroyed; things are only marked inactive.

**WHY ownership is resolved server-side.** The participant and the edit target are always resolved from the session and scoped lookups. A forged conversation or message id is **indistinguishable from a missing one** — both return 404. An "access denied" would confirm the row exists, which is an enumeration oracle.

**ROWS.** **UPD** `messages`, **INS** `message_revisions`, **SOFT** old answer, **INS** new answer.

---

## Step 10 — Change knowledge: re-index, disable, delete, cleanup

Every action here is **DB-only in the request**. The remote consequences all land in the worker.

| Action | Row effect | Remote work |
|---|---|---|
| **Retry** | requeue-fresh: `status='queued'`, attempts=0, errors cleared | Stage 3 |
| **Re-index** | requeue-fresh **+** old index files `pending_removal=1` | Stage 3 then 4 |
| **Process now** | `priority` raised + requeue-fresh | Stage 3, sooner |
| **Disable** | `is_enabled=0` — instantly out of chat, index untouched | none |
| **Enable** | `is_enabled=1` + requeue-fresh | Stage 3 |
| **Delete** | `status='deleted'`, `deleted_at` **+** index files `pending_removal=1` | Stage 4 |
| **Edit generated text** | `source_text`, `is_source_overridden=1` + requeue | Stage 3 |
| **Reset to Order58** | `is_source_overridden=0`, `source_text=NULL` + requeue | Stage 3 |

**WHY one shared `requeueFresh()`.** Retry, Re-index, Process-now and Enable all need the same thing: clear the error state, zero the attempt counter, drop `next_attempt_at`, set `queued`. Four separate implementations would drift, and the one that forgot to reset `processing_attempts` would produce a document that "retries" straight into `failed`.

**WHY Disable needs no re-index.** `is_enabled` is read at chat time. Hiding a document is instant and free; there is no reason to pay OpenAI to remove and re-add content that may be re-enabled in a minute.

**WHY delete is soft.** A hard delete would take the audit trail (`document_processing_events` cascades), break historical citations, and make an accidental click unrecoverable. `status='deleted'` also NULLs `dedupe_hash`, which is precisely what lets the same file be re-uploaded later.

### The cleanup drainer, and the guard that keeps chat up

Stage 4 picks up `document_index_files` rows with `pending_removal=1`:

```
🌐 DELETE /vector_stores/{vs}/files/{fid}     detach
🌐 DELETE /files/{fid}                        delete
DEL document_index_files
```

**WHY flag-then-delete rather than deleting inline.** Detach+delete is two network calls per file. Doing them in the request would make "Delete" slow and — worse — a failure mid-way would leave the row deleted locally and the file alive remotely, permanently paying for an orphan nobody can see. The flag makes the intent durable and the network work retryable.

**WHY a transient error keeps the row but a permanent one drops it.** Transient means "try again next pass." Permanent almost always means the remote file is already gone — the desired end state. Keeping the row would loop forever on a file that no longer exists.

**WHY the old file survives until the replacement is ready.** On re-index, the old index file is flagged but **not** removed until its replacement reaches `completed`. Combined with Step 7's availability rule, this is what keeps a store answerable throughout a refresh: old content serves until new content is genuinely ready, then the old is swept. Chat never flickers.

---

## Step 11 — Rules: sync → classify → review → dual projection

The most layered flow in the system, and every layer earns its place.

### 11.1 Mirror (worker, 🔗)

```
🔗 GET /rules?page=N&per_page=100    ← the LIST only, never GET /rules/{id}
UPSERT order58_rule_records          ← keyed ux_order58_rules_source
yield after ORDER58_SYNC_PAGES_PER_RUN pages
```

**WHY never `GET /rules/{id}`.** A per-rule fetch across a large catalog is hundreds of extra requests against someone else's server. The paginated list carries everything needed. The handler also follows the response's `total_pages`, yields between pages so a large catalog drains across worker passes, and relies on the client's capped backoff which honors `Retry-After`. Being a good API citizen is an explicit design goal here.

### 11.2 Canonicalize (dedupe)

```
INS rule_catalog_rules      ← only when canonical_hash is new (exact-content dedupe)
INS rule_catalog_sources    ← mirror row → canonical rule
                              UNIQUE ux_rule_catalog_sources_record
```

**WHY a separate canonical table.** The same policy text is entered separately against dozens of stores upstream. Indexing all 244 mirror rows would mean paying to index the same sentence dozens of times and retrieving near-identical chunks that crowd out everything else. Content-hash dedupe collapses them to 264 canonical rules, and `rule_catalog_sources` remembers **which mirror rows collapsed into which canonical rule** — so provenance survives deduplication.

**WHY `ON DELETE RESTRICT` here.** Both FKs on `rule_catalog_sources` are RESTRICT, not CASCADE. Rule provenance is deliberately un-deletable: you cannot remove a canonical rule or a mirror row that is still referenced. A rule's history is evidence.

### 11.3 Classify (worker)

```
RuleStoreMatcher →
  INS rule_store_links(match_status='suggested'|'confirmed',
                       match_method, matched_text, confidence)
  INS rule_classification_events(admin_user_id = NULL)   ← NULL = machine decision
```

`match_method` is constrained to `source_store_id`, `domain`, `title_exact_alias`, `description_exact_alias`, `manual`, `fuzzy_suggestion` — so **every match records how it was made**, and a fuzzy suggestion is never mistaken for an upstream-declared fact.

`order58_store_aliases` (seeded after a stores sync: official name, company name, domain, generated variants) is what makes text matching work at all — a rule saying "for Joe's Pizza" must resolve to store #482 whose official name is "Joe's Pizza LLC".

**WHY classification is separate from retrieval.** Deciding *which store a rule belongs to* is a data-quality question, answered once, reviewably, with an audit trail. Retrieval is a per-question relevance question. Conflating them would mean re-litigating ownership on every query, non-deterministically.

### 11.4 Review (admin, web)

`POST /admin/order58/rules/review` — one decision, applied, audited, and its searchable projection reconciled **immediately and locally**:

| Action | Effect |
|---|---|
| `confirm_store` | link → `confirmed`; project into that store's KB |
| `reject_store` | link → `rejected`; remove that projection |
| `mark_common` | `scope_type='common'` |
| `mark_unresolved` / `ignore` | park it out of the flow |
| `enable_global` / `disable_global` | toggle `is_globally_available` → the global projection |
| `reprocess` | re-run classification |

Each writes `reviewed_by_admin_id` + `reviewed_at` on the rule and **INS** `rule_classification_events` with the admin's id — which is exactly how machine decisions (`admin_user_id IS NULL`) are told apart from human ones.

### 11.5 Dual projection — the mechanism that makes a rule answerable

```
UPSERT documents (source_type='order58_rule_store')   → that store's KB
UPSERT documents (source_type='order58_rule_global')  → the hidden Global Rules KB
```

**WHY every approved rule is projected twice.** The store copy makes it a first-class part of that store's own knowledge — retrieved at **stage 1**, cited alongside its other documents, ranked against them. The global copy makes it reachable from **stage 2** by *any* store, which is what lets a rule captured against store A answer a question asked at store B. Without the global copy, cross-store rules would be invisible; without the store copy, a store's own rule would lose to its own documents' priority.

**No OpenAI call happens in the review request** — projection only queues local document work. The worker indexes both copies on the next pass.

`./yii kf:rules:reconcile-global` repairs drift between `is_globally_available` and the actual global projections — a converger for the case where a projection was missed.

---

## Step 12 — The agent realm

**WHAT.** Order58 agents log in with their Order58 credentials and chat with any active, chat-ready, agent-enabled store.

**WHERE.** Everything behind `RequireAgentMiddleware` — a completely separate route group from the admin one.

**The boundaries, and why each exists:**

| Boundary | Why |
|---|---|
| Agents never reach an admin route | Separate middleware, separate group. Not a role check inside a shared controller — a shared controller with a role branch is one forgotten branch away from a privilege escalation. |
| `user_type == 'agent'` **and** active status required at login | An Order58 account existing is not authorization to use this tool |
| `account_id` plays **no** part in access control | Deliberate: access is per-store via `agent_enabled`, not per-account. Fewer axes, fewer ways to get it wrong. |
| Agents write only `conversations` + `messages` (+ `message_revisions`) | They cannot touch `knowledge_bases`, `documents`, or any Order58 mirror |
| `participant_type='agent'` on their threads | The typed unique key gives them their own thread, fully separate from any admin thread on the same store |
| Same `ChatAvailabilityPolicy`, same grounding gate | Availability and grounding are **not** re-implemented per surface — one policy class, both realms |

`POST /admin/order58/stores/{id}/agent-access` flips `knowledge_bases.agent_enabled` — one column, checked server-side on every agent request.

---

## Step 13 — Steady state: every minute, forever

```cron
* * * * * flock -n /var/lock/kf-worker.lock nice -n 10 /usr/bin/php /path/yii kf:worker:run
```

**⚠️ The lock file must be a DEDICATED path** — *not* the app's own `runtime/locks/worker.lock` (`DOCUMENT_WORKER_LOCK_PATH`). The runner takes that lock itself; if cron's `flock` grabs the same file first, the runner can never acquire it and **every single run silently skips**. The symptom is brutal to diagnose: cron reports success every minute, and nothing is ever processed. Full setup in [`deploy/worker.md`](deploy/worker.md).

**WHY two locks at all.** `flock` stops two *cron* runs overlapping. The runner's own non-blocking lock stops a *manual* `./yii kf:worker:run` colliding with a cron run. Different problems, so different locks — and that is exactly why they must not share a file.

**One pass, four drainers, always in this order** ([config/common/di/worker.php](config/common/di/worker.php)):

```
1. IntegrationSyncDrainer      🔗  mirror Order58 → mirrors + generated documents
2. KnowledgeBaseProvisioning   🌐  pending KBs → vector stores
3. DocumentProcessing          🌐  queued/indexing documents → ready
4. RemoteCleanup               🌐  pending_removal index files → detached + deleted
```

**WHY that order is not arbitrary.** It follows the dependency chain: sync creates knowledge bases → provisioning gives them vector stores → processing needs a ready vector store → cleanup runs last because it removes what the earlier stages replaced. Run in one pass, a brand-new store can move several steps down that chain instead of one step per minute.

**Recovery, before draining.** Each drainer's `recover()` releases rows stuck past `DOCUMENT_PROCESSING_TIMEOUT_MINUTES` (20) — the fix for a worker killed mid-flight (OOM, deploy, reboot) that left rows claimed in `processing`/`provisioning`. Without it those rows would be stranded forever, since the claim query only matches `queued`/`pending`.

**Operator commands for the rare cases:**

| Command | Fixes |
|---|---|
| `kf:documents:recover` | Documents stuck in `processing`/`indexing` past the timeout |
| `kf:ai:reconcile` | Operations in `needs_reconcile` — adopts vector stores by their `kf_op` tag |
| `kf:order58:reconcile-active` | `knowledge_bases.source_active` drifted from `order58_stores.active` (idempotent) |
| `kf:rules:reconcile-global` | Global rule projections drifted from `is_globally_available` |
| `kf:health` | Config + DB, no network |
| `kf:openai:ping` | Real OpenAI calls: key, chat model, vision model |

**WHY every one of these is a converger.** Each command is idempotent and safe to run at any time: it compares intended state to actual state and fixes the gap. None of them is a one-shot migration that breaks if run twice. That property is what makes them safe to reach for at 3 a.m.

---

## The complete picture on one page

```
 STEP 0-1  ./yii migrate:up ; kf:admin:create
           └→ INS migration, INS admin_users
                                                    ╔═══════════════════╗
 STEP 2    POST /login ──────────────────────────────╢ auth_login_       ║
           └→ UPD admin_users.last_login_at          ║ attempts          ║
                                                    ╚═══════════════════╝
 STEP 3A   POST /knowledge-bases
           └→ INS knowledge_bases(vector_store_status='pending')
 STEP 3B   POST /admin/order58/sync
           └→ INS integration_sync_runs('pending')     [no network!]
                     │
                     ▼  ══ CRON WORKER, EVERY MINUTE ══
 STAGE 1   🔗 GET /accounts /knowledge /agents /rules  (paginated)
           └→ UPSERT order58_* ; INS knowledge_bases ; UPSERT documents
                     │
 STAGE 2   🌐 POST /vector_stores   {metadata:{kf_op:"vs.create:kb:N"}}
           └→ INS/UPD ai_operations ; UPD knowledge_bases → 'ready'
                     │
 STEP 5    POST …/documents  |  …/manual-text        [no network!]
           └→ INS documents('queued') + file on disk
                     │
 STAGE 3   🌐 POST /files → INS document_index_files
           🌐 POST /vector_stores/{vs}/files → poll once → defer
           └→ UPD documents → 'ready' ; INS document_processing_events
                     │
 STEP 7    ChatAvailabilityPolicy: ready VS + usable QUALIFYING doc
                     │                        ↑ measured on index_status,
                     ▼                          NOT documents.status
 STEP 8    POST …/chat/{cid}
           ├→ INS messages(user)          ← before the call, always
           ├→ 🌐 POST /responses   stage 1: this store
           │                       stage 2: hidden global rules base
           │                       stage 3: fallback
           ├→ grounding gate: called? completed? scored? cited?
           └→ INS messages(assistant)     ← ux_messages_active_answer
                     │
 STEP 9    edit → txn{UPD messages, INS message_revisions, SOFT old answer}
                  then 🌐 outside the txn → INS new answer
                     │
 STEP 10   delete/re-index → SOFT + pending_removal=1   [no network!]
                     │
 STAGE 4   🌐 DELETE /vector_stores/{vs}/files/{fid} ; DELETE /files/{fid}
           └→ DEL document_index_files    ← only AFTER the replacement is ready
```

**The five ideas that explain everything above**

1. **Rows are the queue.** No broker. Four state-indexed queries, four drainers.
2. **Web writes intent; the worker does network.** Every button is a row.
3. **NULL-when-inactive generated columns** enforce three invariants in the schema instead of the application: `dedupe_hash` (re-upload after delete), `active_key` (one live sync run), `active_answer_key` (one live answer).
4. **Nothing is destroyed, only marked inactive.** Soft deletes, supersession, revisions, append-only event trails — every one of them exists because a support question three weeks later needs the history.
5. **Verify, don't trust.** Sniff the MIME, not the header. Require positive evidence of a PDF text layer. Check that File Search actually ran before showing its answer.

---

# PART II — REFERENCE

## R1. Table inventory

21 application tables + 1 framework table. Row counts are from the current dev database — indicative of scale, not authoritative.

| # | Table | Domain | Rows | Written by |
|---|---|---|---|---|
| 1 | `admin_users` | Auth | 1 | Console + login |
| 2 | `auth_login_attempts` | Auth | 3 | Web (login throttle) |
| 3 | `knowledge_bases` | KB | 235 | Web + worker + sync |
| 4 | `knowledge_base_rules` | KB | 0 | Web |
| 5 | `documents` | Document | 647 | Web + sync + worker |
| 6 | `document_index_files` | Document | 412 | Worker |
| 7 | `document_processing_events` | Document | 1 101 | Worker (append-only) |
| 8 | `ai_operations` | AI | 208 | Worker (vector-store create) |
| 9 | `conversations` | Chat | 4 | Web |
| 10 | `messages` | Chat | 17 | Web |
| 11 | `message_revisions` | Chat | 1 | Web (append-only) |
| 12 | `integration_sync_runs` | Order58 | 12 | Web enqueues, worker drains |
| 13 | `order58_stores` | Order58 mirror | 235 | Worker (sync) |
| 14 | `order58_knowledge_records` | Order58 mirror | 115 | Worker (sync) |
| 15 | `order58_agents` | Order58 mirror | 522 | Worker (sync) |
| 16 | `order58_rule_records` | Order58 mirror | 244 | Worker (sync) |
| 17 | `order58_store_aliases` | Rules | 469 | Worker (seeder) |
| 18 | `rule_catalog_rules` | Rules | 264 | Worker + web review |
| 19 | `rule_catalog_sources` | Rules | 266 | Worker (dedupe link) |
| 20 | `rule_store_links` | Rules | 148 | Worker + web review |
| 21 | `rule_classification_events` | Rules | 95 | Worker + web (append-only) |
| — | `migration` | Framework | 25 | `./yii migrate:up` |

**Not in the database:** the OpenAI usage snapshot. `POST /admin/openai-usage/sync` writes a JSON file under `runtime/cache` via `FileUsageSnapshotStore` (atomic temp-file + rename under a lock). No table is touched.

---

## R2. Full schema, table by table

### R2.1 `admin_users`

```sql
id              bigint AI PK
username        varchar(64)   NOT NULL
password_hash   varchar(255)  NOT NULL
is_active       tinyint(1)    NOT NULL DEFAULT 1
last_login_at   datetime      NULL
created_at      datetime      NOT NULL
updated_at      datetime      NOT NULL
UNIQUE ux_admin_users_username (username)
```

| Effect | When |
|---|---|
| **INS** | `./yii kf:admin:create` only — no self-signup route exists |
| **UPD** `last_login_at` | Successful `POST /login` |

### R2.2 `auth_login_attempts`

```sql
attempt_key        char(64) PK          -- a hash, never a username
attempts           smallint unsigned NOT NULL DEFAULT 0
window_started_at  datetime NOT NULL
locked_until       datetime NULL
updated_at         datetime NOT NULL
INDEX ix_auth_login_attempts_updated (updated_at)
```

**INS/UPD** on failed `POST /login` or `POST /agent/login`; reset on success.

### R2.3 `knowledge_bases`

```sql
id                        bigint AI PK
name                      varchar(160)  NOT NULL
slug                      varchar(160)  NOT NULL
description               text          NULL
system_instructions       text          NULL
openai_vector_store_id    varchar(64)   NULL      -- 🌐 filled by the worker
vector_store_status       enum('pending','provisioning','ready','failed') DEFAULT 'pending'
provision_attempts        smallint unsigned DEFAULT 0
provision_started_at      datetime      NULL
provision_next_attempt_at datetime      NULL
vector_store_error_code   varchar(64)   NULL
vector_store_error        varchar(500)  NULL
status                    enum('active','archived') DEFAULT 'active'
created_at / updated_at   datetime      NOT NULL
source_system             varchar(32)   NULL      -- 'order58'
source_store_id           bigint unsigned NULL
source_name               varchar(255)  NULL
source_active             tinyint(1)    NULL
agent_enabled             tinyint(1)    NOT NULL DEFAULT 1
purpose                   varchar(16)   NOT NULL DEFAULT 'store'
last_source_synced_at     datetime      NULL
last_indexed_at           datetime      NULL
UNIQUE ux_knowledge_bases_slug         (slug)
UNIQUE ux_knowledge_bases_vector_store (openai_vector_store_id)
UNIQUE ux_knowledge_bases_source       (source_system, source_store_id)
INDEX  ix_knowledge_bases_provisioning (vector_store_status, provision_next_attempt_at, id)
INDEX  ix_knowledge_bases_status_name  (status, name)
```

| Effect | When | API |
|---|---|---|
| **INS** (`pending`) | `POST /knowledge-bases` | — |
| **INS** | Stores sync → `EnsureStoreKnowledgeBaseService` | 🔗 🕒 |
| **INS** | Rules classification ensures the hidden global base (`purpose` ≠ `store`) | 🕒 |
| **UPD** name/description/instructions | `POST /knowledge-bases/{slug}` | — |
| **UPD** `status` | Archive / restore | — |
| **UPD** `agent_enabled` | `POST …/stores/{id}/agent-access` | — |
| **UPD** → `provisioning` | Worker claim | 🕒 |
| **UPD** → `ready` + store id | Vector store created | 🌐 🕒 |
| **UPD** → `failed` | Attempts exhausted | 🌐 🕒 |
| **UPD** `source_*`, `last_source_synced_at` | Sync / `kf:order58:reconcile-active` | 🔗 🕒 |

### R2.4 `knowledge_base_rules`

```sql
id, knowledge_base_id → knowledge_bases.id ON DELETE CASCADE
name varchar(160), instruction text,
priority smallint unsigned DEFAULT 100, is_enabled tinyint(1) DEFAULT 1,
created_at, updated_at
UNIQUE ux_knowledge_base_rules_name (knowledge_base_id, name)
INDEX  ix_knowledge_base_rules_active (knowledge_base_id, is_enabled, priority, id)
```

**INS/UPD/DEL** from the five `…/rules` routes. Read at chat time and injected in `priority` order. **No OpenAI call, no re-index** — a rule change takes effect on the next question.

### R2.5 `documents`

```sql
id                   bigint AI PK
knowledge_base_id    bigint NOT NULL → knowledge_bases.id ON DELETE CASCADE
original_filename    varchar(255) NOT NULL      -- display + citation only, never a path
stored_path          varchar(512) NOT NULL
storage_token        char(32)     NOT NULL
mime_type            varchar(128) NOT NULL      -- SNIFFED, not client-supplied
extension            varchar(16)  NOT NULL
size_bytes           bigint unsigned NOT NULL
checksum_sha256      char(64)     NOT NULL
kind                 varchar(32)  NOT NULL      -- pdf | image | text
status               enum('uploaded','queued','processing','indexing','ready','failed','deleted')
priority             tinyint unsigned DEFAULT 0
processing_attempts  smallint unsigned DEFAULT 0
processing_started_at datetime NULL
next_attempt_at      datetime NULL
error_code           varchar(64)  NULL
error_message        varchar(1000) NULL         -- redacted before storage
processed_at / deleted_at / created_at / updated_at datetime
dedupe_hash          char(64) GENERATED STORED
                     = IF(status='deleted', NULL, checksum_sha256)
source_type          varchar(48) NOT NULL DEFAULT 'uploaded_pdf'
source_ref           varchar(64)  NULL
source_sync_hash     char(64)     NULL
title                varchar(255) NULL
is_enabled           tinyint(1) NOT NULL DEFAULT 1
last_indexed_at      datetime NULL
source_text          mediumtext NULL
is_source_overridden tinyint(1) NOT NULL DEFAULT 0
UNIQUE ux_documents_dedupe (knowledge_base_id, dedupe_hash)
UNIQUE ux_documents_source (knowledge_base_id, source_type, source_ref)
INDEX  ix_documents_kb_status (knowledge_base_id, status, created_at)
INDEX  ix_documents_queue     (status, priority, next_attempt_at, id)
```

`source_type` values (`DocumentSourceType`): `uploaded_pdf`, `uploaded_image`, `uploaded_text`, `manual_text`, `order58_store_profile`, `order58_knowledge`, `order58_rule_store`, `order58_rule_global`, `order58_rule_common`.

| Effect | Trigger | API |
|---|---|---|
| **INS** `queued` | Upload / manual text | — |
| **UPSERT** on `ux_documents_source` | Store profile / knowledge generation, only when `source_sync_hash` changed | 🔗 🕒 |
| **UPSERT** on `ux_documents_source` | Approved-rule dual projection | 🕒 |
| **UPD** → `processing`/`indexing`/`ready`/`failed` | Worker | 🌐 🕒 |
| **SOFT** `status='deleted'` | Delete | — |
| **UPD** `is_enabled` | Toggle | — |
| **UPD** requeue-fresh | Retry / Re-index / Process-now / Enable | — |
| **UPD** `source_text`, `is_source_overridden` | Edit / reset | — |

### R2.6 `document_index_files`

```sql
id, document_id → documents.id ON DELETE CASCADE
role              enum('source','derived_markdown')
derived_path      varchar(512) NULL
openai_file_id    varchar(64)  NULL
index_status      enum('pending','in_progress','completed','failed','cancelled')
usage_bytes       bigint unsigned NULL
last_error_code / last_error_message
created_at / updated_at
pending_removal   tinyint(1) NOT NULL DEFAULT 0
UNIQUE ux_index_files_openai (openai_file_id)
INDEX  ix_index_files_document (document_id, role)
INDEX  ix_index_files_removal  (pending_removal)
```

**This table — not `documents.status` — is what chat availability is computed from.**

| Effect | When | API |
|---|---|---|
| **INS** | Worker uploads an artifact | 🌐 `POST /files` 🕒 |
| **UPD** `completed` + `usage_bytes` | Attach + poll succeeded | 🌐 🕒 |
| **SOFT** `pending_removal=1` | Delete / re-index — web, no network | — |
| **DEL** | Cleanup drainer confirmed the remote file is gone | 🌐 🕒 |

### R2.7 `document_processing_events`

```sql
id, document_id → documents.id ON DELETE CASCADE
status varchar(32), message varchar(1000) NULL, metadata_json json NULL, created_at
INDEX ix_events_document (document_id, id)
```

**INS only.** `ready` / `retry` / `failed` from `ProcessDocumentService`. Never updated, never deleted except by cascade.

### R2.8 `ai_operations`

```sql
id, operation_key varchar(191), type varchar(64),
subject_type varchar(32), subject_id bigint unsigned,
status enum('pending','in_flight','succeeded','needs_reconcile','failed'),
request_fingerprint char(64), idempotency_key char(36), result_id varchar(64),
attempts smallint unsigned, next_attempt_at datetime,
last_error_code / last_error_message,
started_at / completed_at / created_at / updated_at
UNIQUE ux_ai_operations_key (operation_key)
INDEX  ix_ai_operations_status  (status, next_attempt_at, id)
INDEX  ix_ai_operations_subject (subject_type, subject_id)
```

Types: `vs.create`, `file.upload`, `vs.attach`. **Only `vs.create` is wrapped in `ReliableOperation` today** — see Step 4 for why. The create stamps `metadata.kf_op = <operation_key>` on the remote store; `kf:ai:reconcile` finds it by that tag.

### R2.9 `conversations`

```sql
id, knowledge_base_id → knowledge_bases.id ON DELETE CASCADE
title varchar(255), last_message_at datetime NULL,
created_at, updated_at,
agent_admin_id bigint unsigned NULL,      -- legacy, kept for history
participant_type varchar(16) NOT NULL,    -- 'admin' | 'agent'
participant_id   bigint unsigned NOT NULL
UNIQUE ux_conversations_kb_participant_typed (knowledge_base_id, participant_type, participant_id)
INDEX  ix_conversations_kb_activity (knowledge_base_id, last_message_at, id)
INDEX  ix_conversations_agent       (agent_admin_id, last_message_at, id)
CHECK  participant_type IN ('admin','agent') ; participant_id > 0
```

### R2.10 `messages`

```sql
id, conversation_id → conversations.id ON DELETE CASCADE
role enum('user','assistant'), content text,
citations_json json, usage_json json,
is_grounded bit(1) DEFAULT b'0', retrieval_status varchar(32),
openai_response_id varchar(64), model varchar(64),
answer_source varchar(32),          -- store_knowledge|store_rule|global_rule|common_rule|fallback
reply_to_message_id bigint NULL,
superseded_at datetime NULL, edited_at datetime NULL,
edit_count smallint unsigned DEFAULT 0, created_at datetime
active_answer_key bigint GENERATED STORED
    = CASE WHEN role='assistant' AND superseded_at IS NULL THEN reply_to_message_id END
UNIQUE ux_messages_active_answer (active_answer_key)
INDEX  ix_messages_conversation (conversation_id, id)
INDEX  ix_messages_reply_to     (reply_to_message_id)
```

### R2.11 `message_revisions`

```sql
id, message_id → messages.id ON DELETE CASCADE
revision_number int unsigned, content text,     -- the text BEFORE the edit
edited_by_type varchar(16), edited_by_id bigint unsigned, created_at
UNIQUE ux_message_revisions_msg_rev (message_id, revision_number)
CHECK  edited_by_type IN ('admin','agent') ; edited_by_id > 0
```

### R2.12 `integration_sync_runs`

```sql
id, type varchar(32), scope_ref bigint unsigned NULL,
status enum('pending','running','completed','completed_with_warnings','failed'),
attempts smallint unsigned, requested_by_admin_id bigint NULL,
progress_json json NULL,
claimed_at / started_at / completed_at / next_attempt_at datetime NULL,
error_code / error_message, created_at, updated_at
active_key char(64) GENERATED STORED
    = IF(status IN ('pending','running'),
         SHA2(CONCAT(type,':',COALESCE(scope_ref,0)),256), NULL)
UNIQUE ux_integration_sync_runs_active (active_key)
INDEX  ix_integration_sync_runs_status (status, next_attempt_at, id)z
```

`type` (`Order58SyncType`): `stores`, `knowledge`, `agents`, `rules`, `knowledge_store`, `rebuild_store`, `health`.

### R2.13–R2.16 Order58 mirrors

All four share the mirror shape: a unique `source_id`, a `snapshot_json`, a `sync_hash` for change detection, `synced_at`, and `last_seen_sync_run_id` for mark-and-sweep. **None has a foreign key** — see [R8](#r8-delete--cascade-behavior).

| Table | Unique key | Source API | Produces documents? |
|---|---|---|---|
| `order58_stores` | `source_id` | `GET /accounts` | Store profile |
| `order58_knowledge_records` | `source_id` | `GET /knowledge` | One per active record |
| `order58_agents` | `admin_id` | `GET /agents` | **No** — and never calls OpenAI |
| `order58_rule_records` | `source_id` | `GET /rules` | Only via the catalog (Step 11) |

```sql
-- order58_stores
id, source_id (UNIQUE), name, company, active, snapshot_json, sync_hash,
source_updated_at, synced_at, last_seen_sync_run_id, created_at, updated_at
INDEX ix_order58_stores_active (active)

-- order58_knowledge_records
id, source_id (UNIQUE), store_source_id, title, content, knowledge_number,
keyword, type, active, snapshot_json, sync_hash, source_created_at,
source_updated_at, synced_at, last_seen_sync_run_id, created_at, updated_at
INDEX ix_order58_knowledge_store (store_source_id, active)

-- order58_agents   (safe fields only — NEVER a credential)
id, admin_id (UNIQUE), username, first_name, last_name, email_address,
contact_number, role, status, user_type, account_id, snapshot_json, sync_hash,
source_modified_at, synced_at, last_seen_sync_run_id, created_at, updated_at
INDEX ix_order58_agents_status (status, user_type)

-- order58_rule_records
id, source_id (UNIQUE), type, title, description, rule_keyword, created_name,
source_store_id, snapshot_json, sync_hash, source_created_at, source_updated_at,
synced_at, last_seen_sync_run_id, is_active, created_at, updated_at
INDEX ix_order58_rules_active (is_active) ; ix_order58_rules_store (source_store_id)
```

### R2.17 `order58_store_aliases`

```sql
id, store_source_id, alias, normalized_alias, alias_type,
is_approved, created_by_admin_id, created_at, updated_at
UNIQUE ux_store_aliases_store_norm (store_source_id, normalized_alias)
INDEX  ix_store_aliases_norm (normalized_alias, is_approved)
CHECK  alias_type IN ('official_name','company_name','domain','generated','discovered','manual')
```

### R2.18 `rule_catalog_rules`

```sql
id, canonical_hash char(64) UNIQUE, description_hash char(64),
title text, content text,
scope_type varchar(16) DEFAULT 'unresolved',
classification_status varchar(24) DEFAULT 'pending',
classification_confidence decimal(5,4), detected_store_text varchar(500),
classification_reason varchar(1000),
reviewed_by_admin_id bigint unsigned NULL, reviewed_at datetime NULL,
is_active tinyint(1) DEFAULT 1, is_globally_available tinyint(1) DEFAULT 1,
created_at, updated_at
CHECK scope_type IN ('common','store_specific','unresolved')
CHECK classification_status IN ('pending','auto_matched','manually_matched',
      'suggested_common','confirmed_common','ambiguous','unmatched','ignored')
```

### R2.19 `rule_catalog_sources`

```sql
id, rule_catalog_rule_id  → rule_catalog_rules.id   ON DELETE RESTRICT
    order58_rule_record_id → order58_rule_records.id ON DELETE RESTRICT
relation_type varchar(24) DEFAULT 'primary', created_at
UNIQUE ux_rule_catalog_sources_record (order58_rule_record_id)
CHECK  relation_type IN ('primary','exact_duplicate','manually_merged')
```

### R2.20 `rule_store_links`

```sql
id, rule_catalog_rule_id → rule_catalog_rules.id ON DELETE RESTRICT
store_source_id bigint unsigned, knowledge_base_id bigint NULL,
match_status varchar(16), match_method varchar(32),
matched_text varchar(500), confidence decimal(5,4),
is_primary tinyint(1) DEFAULT 0,
created_by_type / created_by_id, created_at, updated_at
UNIQUE ux_rule_store_links_rule_store (rule_catalog_rule_id, store_source_id)
INDEX  ix_rule_store_links_store (store_source_id, match_status)
CHECK  match_method IN ('source_store_id','domain','title_exact_alias',
       'description_exact_alias','manual','fuzzy_suggestion')
CHECK  match_status IN ('suggested','confirmed','rejected')
```

### R2.21 `rule_classification_events`

```sql
id, rule_catalog_rule_id → rule_catalog_rules.id ON DELETE RESTRICT
event_type varchar(48), old_status varchar(24), new_status varchar(24),
message varchar(1000), metadata_json json,
admin_user_id bigint unsigned NULL,      -- NULL ⇒ a machine decision
created_at
INDEX ix_rule_class_events_rule (rule_catalog_rule_id, id)
```

---

## R3. External API catalog

### R3.1 OpenAI — [`HttpOpenAiClient.php`](src/Ai/OpenAi/Client/HttpOpenAiClient.php)

Base URL `OPENAI_BASE_URL` (default `https://api.openai.com/v1`).

| Method + path | Called from | Tables affected |
|---|---|---|
| `POST /files` | Worker — upload source / derived markdown | **INS** `document_index_files` |
| `GET /files?…` | Reconciler / inventory | read-only |
| `DELETE /files/{id}` | Cleanup drainer | **DEL** `document_index_files` |
| `POST /vector_stores` | Provisioning drainer | **UPD** `knowledge_bases` → `ready`; **UPD** `ai_operations` → `succeeded` |
| `GET /vector_stores?…` | `kf:ai:reconcile` — find a lost create by `kf_op` | **UPD** `ai_operations` |
| `GET /vector_stores/{id}` | Provisioning verification | **UPD** `knowledge_bases` |
| `DELETE /vector_stores/{id}` | Teardown | **UPD** `knowledge_bases` |
| `POST /vector_stores/{id}/files` | Worker — attach | **UPD** `document_index_files` |
| `GET /vector_stores/{id}/files?…` | Poll for completion | **UPD** `document_index_files` |
| `GET /vector_stores/{id}/files/{fid}` | Poll one file | **UPD** `document_index_files` |
| `DELETE /vector_stores/{id}/files/{fid}` | Cleanup — detach | **DEL** `document_index_files` |
| `POST /responses` | **Chat (web, synchronous)** — forced File Search | **INS** `messages`; **UPD** `conversations` |

**Two HTTP profiles, deliberately different:**

| Profile | Connect | Timeout | Retries | Why |
|---|---|---|---|---|
| Chat (web) | 5 s | 90 s | **0** | A user is waiting; retries would compound the delay |
| Worker | 10 s | 120 s | 3, backoff ≤ 60 s | Nobody is waiting; transient faults should heal |

### R3.2 Order58 — [`HttpOrder58Client.php`](src/Order58/Client/HttpOrder58Client.php)

| Method + path | Called from | Tables affected |
|---|---|---|
| `GET /health` | `POST /admin/order58/check` (via a `health` run) | `integration_sync_runs` |
| `GET /accounts?page=&per_page=` | `StoresSyncHandler` 🕒 | `order58_stores`, `knowledge_bases`, `documents`, `order58_store_aliases` |
| `GET /accounts/{id}` | Scoped lookups | same, scoped |
| `GET /agents?page=&per_page=` | `AgentsSyncHandler` 🕒 | `order58_agents` |
| `GET /knowledge?…` | `KnowledgeSyncHandler` 🕒 | `order58_knowledge_records`, `documents` |
| `GET /knowledge/{id}` | Scoped lookup | same |
| `GET /rules?page=&per_page=` | `RulesSyncHandler` 🕒 | `order58_rule_records`, `rule_catalog_*`, `rule_store_links`, `rule_classification_events` |
| `GET /rules/{id}` | Diagnostics only — **the sync never calls it** | — |
| `POST /authenticate` | **Agent login (web, synchronous)** | `auth_login_attempts` only |

Profile: connect 10 s, timeout 30 s, 2 retries, backoff ≤ 30 s honoring `Retry-After`. Paging: `ORDER58_API_PAGE_SIZE=100`, `ORDER58_SYNC_PAGES_PER_RUN=1000`, `ORDER58_SYNC_MAX_ATTEMPTS=3`.

### R3.3 Calls that happen inside a web request

Exactly three, by design: 🌐 `POST /responses` (chat), 🔗 `POST /authenticate` (agent login), 🔗 `GET /health` (only if the health run drains inline — the button itself just enqueues). Everything else is enqueued and drained by cron.

---

## R4. Route → write-effect matrix

### Public

| Route | Writes | External |
|---|---|---|
| `GET /login`, `GET /agent/login` | — | — |
| `POST /login` | **UPD** `admin_users.last_login_at`; `auth_login_attempts` | — |
| `POST /agent/login` | `auth_login_attempts` | 🔗 `POST /authenticate` |

### Admin — knowledge bases

| Route | Writes |
|---|---|
| `GET /`, `/knowledge-bases`, `/knowledge-bases/{slug}` | — |
| `POST /knowledge-bases` | **INS** `knowledge_bases` (`pending`) |
| `POST /knowledge-bases/{slug}` | **UPD** `knowledge_bases` |
| `POST …/archive`, `…/restore` | **UPD** `knowledge_bases.status` |
| `POST …/sync-order58-knowledge` | **INS** `integration_sync_runs` (`knowledge_store`) |
| `POST /logout` | — (session only) |

### Admin — KB rules

| Route | Writes |
|---|---|
| `POST …/rules` | **INS** `knowledge_base_rules` |
| `POST …/rules/reorder` | **UPD** `priority` (bulk) |
| `POST …/rules/{ruleId}` | **UPD** `knowledge_base_rules` |
| `POST …/rules/{ruleId}/toggle` | **UPD** `is_enabled` |
| `POST …/rules/{ruleId}/delete` | **DEL** `knowledge_base_rules` |

### Admin — documents

| Route | Writes |
|---|---|
| `POST …/documents` | **INS** `documents` `queued` + file on disk |
| `GET …/documents/{id}/view`, `/download` | — |
| `POST …/documents/{id}/delete` | **SOFT** `documents`; **SOFT** `document_index_files.pending_removal` |
| `POST …/documents/{id}/retry` | **UPD** requeue-fresh |
| `POST …/documents/{id}/reindex` | **UPD** requeue-fresh; **SOFT** old index files |
| `POST …/documents/{id}/process-now` | **UPD** `priority` + requeue-fresh |
| `POST …/documents/{id}/toggle` | **UPD** `is_enabled` (+ requeue on enable) |
| `POST …/documents/{id}/reset-order58` | **UPD** `is_source_overridden=0`, `source_text=NULL` + requeue |
| `POST …/manual-text` | **INS** `documents` (`manual_text`, `queued`) |
| `POST …/documents/{id}/edit` | **UPD** `source_text`, `is_source_overridden=1` + requeue |

**Every one is DB-only.** No OpenAI call in any of them.

### Admin — chat

| Route | Writes | External |
|---|---|---|
| `GET …/chat`, `…/history`, `…/chat/{id}` | — | — |
| `POST …/chat` (start) | **INS** `conversations` (if none) | — |
| `POST …/chat/{cid}` (ask) | **INS** `messages` ×2; **UPD** `conversations` | 🌐 `POST /responses` |
| `POST …/messages/{mid}/edit` | *Txn:* **UPD** `messages`, **INS** `message_revisions`, **SOFT** old answer. *Then:* **INS** new answer | 🌐 `POST /responses` |
| `POST …/messages/{mid}/regenerate` | **SOFT** old answer; **INS** new answer | 🌐 `POST /responses` |

### Admin — Order58 & rules

| Route | Writes | External |
|---|---|---|
| `GET /admin/order58`, `/agents`, `/stores`, `/store-chat` | — | — |
| `POST /admin/order58/sync` | **INS** `integration_sync_runs` (`stores`\|`knowledge`\|`agents`\|`rules`) | — |
| `POST /admin/order58/check` | **INS** `integration_sync_runs` (`health`) | — |
| `POST …/stores/{id}/sync-knowledge` | **INS** run (`knowledge_store`, `scope_ref`) | — |
| `POST …/stores/{id}/rebuild` | **INS** run (`rebuild_store`, `scope_ref`) | — |
| `POST …/stores/{id}/agent-access` | **UPD** `knowledge_bases.agent_enabled` | — |
| `GET /admin/order58/rules`, `/rules/readiness`, `/rules/global`, `/rules/list`, `/rules/{id}` | — | — |
| `POST /admin/order58/rules/review` | **UPD** `rule_catalog_rules`, **UPD/INS** `rule_store_links`, **INS** `rule_classification_events`, **UPSERT/SOFT** `documents` | — |
| `GET /admin/openai-usage` | — | — |
| `POST /admin/openai-usage/sync` | **No table** — JSON file under `runtime/cache` | 🌐 |

### Agent realm

| Route | Writes | External |
|---|---|---|
| `GET /agent`, `…/chat`, `…/history`, `…/chat/{id}` | — | — |
| `POST /agent/stores/{slug}/chat` | **INS** `conversations` (`participant_type='agent'`) | — |
| `POST /agent/stores/{slug}/chat/{cid}` | **INS** `messages` ×2; **UPD** `conversations` | 🌐 `POST /responses` |
| `POST …/messages/{mid}/edit`, `…/regenerate` | Same as admin | 🌐 `POST /responses` |
| `POST /agent/logout` | — | — |

---

## R5. Console command → write-effect matrix

| Command | Writes | External |
|---|---|---|
| `kf:admin:create` | **INS** `admin_users` | — |
| `kf:worker:run [--limit=N]` | See [R6](#r6-worker-pass--write-effect-matrix) | 🌐 🔗 |
| `kf:ai:reconcile` | **UPD** `ai_operations`, `knowledge_bases` | 🌐 `GET /vector_stores` |
| `kf:documents:recover` | **UPD** `documents` — releases rows stuck past the timeout | — |
| `kf:order58:reconcile-active` | **UPD** `knowledge_bases.source_active` (idempotent) | — |
| `kf:rules:reconcile-global` | **UPSERT/SOFT** `documents` (`order58_rule_global`) | — |
| `kf:openai:ping` | — | 🌐 connectivity + capability probe |
| `kf:health` | — | — |
| `./yii migrate:up` | **INS** `migration` + DDL | — |

---

## R6. Worker pass → write-effect matrix

### Stage 1 — `IntegrationSyncDrainer` 🔗

| Type | API | Writes |
|---|---|---|
| `stores` | `GET /accounts` (paged) | **UPSERT** `order58_stores`; **INS/UPD** `knowledge_bases`; **UPSERT** `documents` (store profile, hash-gated); **INS** `order58_store_aliases` |
| `knowledge` | `GET /knowledge` (paged) | **UPSERT** `order58_knowledge_records`, `documents` |
| `knowledge_store` | `GET /knowledge` (scoped) | Same, one store |
| `agents` | `GET /agents` (paged) | **UPSERT** `order58_agents` |
| `rules` | `GET /rules` (paged) | **UPSERT** `order58_rule_records`; **INS** `rule_catalog_rules`, `rule_catalog_sources`, `rule_store_links`, `rule_classification_events` |
| `rebuild_store` | **none** | Force-rewrites that store's generated `documents`, ignoring `sync_hash` |
| `health` | `GET /health` | `integration_sync_runs` only |

Every run updates `progress_json` per page and a terminal status at the end. **Mark-and-sweep only after the final page succeeds.**

### Stage 2 — `KnowledgeBaseProvisioningDrainer` 🌐

**INS** `ai_operations` → **UPD** `in_flight` → 🌐 `POST /vector_stores` (with `metadata.kf_op`) → **UPD** `ai_operations` `succeeded` + **UPD** `knowledge_bases` `ready`. Does nothing when OpenAI is unconfigured.

### Stage 3 — `DocumentProcessingDrainer` 🌐

Only for documents whose KB vector store is `ready`.

| Kind | Pipeline | Writes |
|---|---|---|
| `pdf` (text layer confirmed) | Extract → upload source | **INS** `document_index_files` (`source`) |
| `image` / scanned pdf | 🌐 vision → derived markdown → upload | **INS** `document_index_files` (`derived_markdown`) + derived file |
| `text` | Render markdown → upload | **INS** `document_index_files` (`derived_markdown`) |

Finish: attach → poll once → **UPD** `document_index_files` `completed` → **UPD** `documents` `ready` → **INS** `document_processing_events`.

### Stage 4 — `RemoteCleanupDrainer` 🌐

`pending_removal=1` → 🌐 detach → 🌐 delete → **DEL** `document_index_files`. Transient error keeps the row; permanent drops it.

---

## R7. Constraints that shape inserts

| Constraint | Table | What it prevents |
|---|---|---|
| `ux_documents_dedupe` + NULL-when-deleted | `documents` | Same file twice in one KB — while still allowing re-upload after delete |
| `ux_documents_source` | `documents` | Sync creating a second generated document; makes sync **idempotent** |
| `ux_knowledge_bases_source` | `knowledge_bases` | Two KBs for one Order58 store |
| `ux_knowledge_bases_vector_store` | `knowledge_bases` | Two KBs sharing one vector store |
| `ux_integration_sync_runs_active` (NULL when terminal) | `integration_sync_runs` | Two concurrent runs of the same type/scope |
| `ux_conversations_kb_participant_typed` | `conversations` | Duplicate threads per participant |
| `ux_messages_active_answer` (NULL when superseded) | `messages` | **Two live answers to one question** |
| `ux_message_revisions_msg_rev` | `message_revisions` | Duplicate revision numbers |
| `ux_index_files_openai` | `document_index_files` | Two rows claiming one remote file |
| `ux_ai_operations_key` | `ai_operations` | Two ledger rows for one logical operation |
| `ux_rule_catalog_rules_hash` | `rule_catalog_rules` | Duplicate canonical rules |
| `ux_rule_catalog_sources_record` | `rule_catalog_sources` | A mirror rule belonging to two canonical rules |
| `ux_rule_store_links_rule_store` | `rule_store_links` | Duplicate rule↔store matches |
| `ux_store_aliases_store_norm` | `order58_store_aliases` | Duplicate normalized aliases per store |

**Three generated columns do the heavy lifting** — `documents.dedupe_hash`, `integration_sync_runs.active_key`, `messages.active_answer_key`. All three exploit the same property: MySQL unique indexes ignore NULL, so the constraint binds only live rows while history stays intact.

**Mark-and-sweep, everywhere:** every Order58 sweep runs **only after the final page succeeds**. An interrupted run never sweeps, so a partial fetch can never be misread as "these records were deleted upstream."

---

## R8. Delete & cascade behavior

**`ON DELETE CASCADE`** — deleting the parent removes children:

```
knowledge_bases
 ├── documents          → document_index_files
 │                      → document_processing_events
 ├── knowledge_base_rules
 └── conversations      → messages → message_revisions
```

**`ON DELETE RESTRICT`** — blocked while referenced: `rule_catalog_sources` → both `rule_catalog_rules` and `order58_rule_records`; `rule_store_links` and `rule_classification_events` → `rule_catalog_rules`. **Rule provenance is deliberately un-deletable.**

**No FK at all** — the Order58 mirrors reference upstream ids, not local rows. **WHY:** they are mirrors of a foreign system and must survive it changing shape; a FK would let an upstream change fail a local insert. `rule_store_links.store_source_id` and `.knowledge_base_id` are likewise unconstrained.

**What never hard-deletes:** documents (`status='deleted'`), messages (`superseded_at`), Order58 mirrors (`active`/`is_active`), processing and classification events. `document_index_files` rows **are** hard-deleted — but only by the cleanup drainer, and only after the remote file is confirmed gone.

**Deleting a knowledge base does not delete its OpenAI vector store** — that is a separate `DELETE /vector_stores/{id}` teardown.

---

## R9. Environment variables that change behavior

| Variable | Default | Effect |
|---|---|---|
| `DOCUMENT_WORKER_BATCH_SIZE` | 1 | Items per drainer per pass |
| `DOCUMENT_MAX_PROCESSING_ATTEMPTS` | 3 | Retries before `failed` |
| `DOCUMENT_PROCESSING_TIMEOUT_MINUTES` | 20 | When `recover()` releases a stuck claim |
| `DOCUMENT_RETRY_BASE_SECONDS` | 60 | Backoff base |
| `DOCUMENT_WORKER_LOCK_PATH` | `@runtime/locks/worker.lock` | **Never reuse this for cron's `flock`** |
| `MAX_UPLOAD_SIZE_MB` / `MAX_IMAGE_UPLOAD_SIZE_MB` | 25 / 8 | Keep ≤ nginx `client_max_body_size` ≤ PHP limits |
| `PDF_MIN_TEXT_CHARS_PER_PAGE` | 100 | Below this ⇒ vision, never direct index |
| `PDF_VISION_MAX_PAGES` / `_MAX_BYTES` | 50 / 25 MB | Above ⇒ manual review |
| `CHAT_HISTORY_MESSAGE_LIMIT` / `_CHAR_LIMIT` | 10 / 8000 | Bounded context per question |
| `CHAT_MAX_QUESTION_LENGTH` | 2000 | Input cap |
| `CHAT_EDIT_WINDOW_MINUTES` | 20 | Edit window |
| `CHAT_MAX_OUTPUT_TOKENS` | 1200 | Truncation shows up as a missing-citation fallback |
| `CHAT_REQUIRE_CITATIONS` | true | false ⇒ uncited text is shown but flagged |
| `CHAT_FORCE_FILE_SEARCH` | true | Retrieval is not optional |
| `CHAT_MIN_CITATION_SCORE` | 0.0 | Minimum top-result score to count as retrieved |
| `OPENAI_FILE_SEARCH_MAX_RESULTS` | 20 | Retrieval breadth |
| `OPENAI_INDEX_POLL_INTERVAL/_MAX_SECONDS` | 3 / 60 | Indexing poll cadence |
| `ORDER58_API_PAGE_SIZE` | 100 | Records per page |
| `ORDER58_SYNC_PAGES_PER_RUN` | 1000 | Pages before yielding to the next worker pass |
| `ORDER58_SHOW_STORE_PROFILE_DOCUMENTS` | true | UI visibility only |

**One `.env`, both tiers.** PHP-FPM and cron run as the same user and load the same file, so configuration cannot drift. `./yii kf:health` prints a redacted fingerprint — run it as the deploy user **and** as `www-data` and confirm they match.

---

## R10. Troubleshooting by symptom

| Symptom | First query / check | Usual cause |
|---|---|---|
| Nothing processes at all | Is cron's `flock` file a **dedicated** path? | Sharing `runtime/locks/worker.lock` ⇒ every run skips silently |
| KB stuck `pending` | `SELECT vector_store_status, provision_attempts, vector_store_error FROM knowledge_bases WHERE id=?` | OpenAI unconfigured (drainer no-ops) or provisioning failed |
| Document stuck `queued` | Its KB's `vector_store_status` | Stage 3 won't claim until the vector store is `ready` |
| Document `failed` | `SELECT status, message FROM document_processing_events WHERE document_id=? ORDER BY id DESC` | Scanned PDF over the vision limit ⇒ manual review |
| Chat says unavailable | `ChatAvailabilityPolicy` — is there a **usable qualifying** doc? | Only a store profile exists; or no `completed` index file yet |
| Answer is always the fallback | `SELECT retrieval_status, is_grounded, answer_source FROM messages WHERE …` | `not_called`, no results, or nothing cited |
| Sync button does nothing | `SELECT * FROM integration_sync_runs ORDER BY id DESC LIMIT 5` | The button only enqueues — check the worker |
| Sync won't start again | `active_key` on the previous run | A prior run is still `pending`/`running` |
| Duplicate KBs for one store | `ux_knowledge_bases_source` | Should be impossible — investigate `source_system`/`source_store_id` NULLs |
| Store marked inactive wrongly | `./yii kf:order58:reconcile-active` | Stale mirror data; the command is idempotent |
| A rule never answers | `rule_store_links.match_status` + `is_globally_available` | Not confirmed, or not projected globally — `kf:rules:reconcile-global` |

**The one query to start almost any store investigation:**

```sql
SELECT kb.slug, kb.vector_store_status, kb.agent_enabled, kb.source_active,
       d.id, d.source_type, d.status, d.is_enabled,
       dif.index_status, dif.pending_removal
FROM knowledge_bases kb
LEFT JOIN documents d              ON d.knowledge_base_id = kb.id AND d.status <> 'deleted'
LEFT JOIN document_index_files dif ON dif.document_id = d.id
WHERE kb.source_store_id = ?;
```

Read it top-down: is the vector store `ready` → is there a non-store-profile document → is it `is_enabled` → does it have a `completed` index file that is not `pending_removal`. The first row that fails that chain is the answer.
