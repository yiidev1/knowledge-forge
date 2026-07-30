# Knowledge Forge

An admin-operated knowledge-base chat application. An administrator creates knowledge bases, uploads
PDFs and images, and asks questions that are answered **strictly from those documents, with real
citations** — or with an explicit fallback when the documents don't support an answer.

Built on Yii3 (PHP 8.2), server-rendered, single-tenant, admin-only. Retrieval runs on OpenAI-hosted
Vector Stores via the Responses API's File Search tool; the only credential required is an OpenAI API
key.

---

## How it works

- **Documents** (PDF / PNG / JPG / WEBP) are uploaded, stored outside the web root, SHA-256 deduplicated,
  and queued. A background worker indexes them: text PDFs are indexed directly; images and scanned PDFs
  are converted to Markdown by a vision model first (the original filename is still what citations show).
- **Ingestion is asynchronous.** Every OpenAI operation except chat runs in a `flock`-guarded cron
  worker — provisioning a knowledge base's vector store, uploading/indexing files, vision extraction,
  and remote cleanup after a delete or re-index. Uploads and clicks return immediately.
- **Chat is the one synchronous OpenAI call.** Retrieval is forced, and a server-side grounding check
  runs on every answer: no retrieval, no usable results, or no resolvable citation ⇒ the configured
  fallback sentence, never a guess.

See the phase notes in the git history and `docs/` for the detailed design.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 (CLI and `php8.2-fpm`), with `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `intl`, `gd` |
| MySQL | 8.0+ |
| Web server | nginx (sample vhosts in `docs/nginx/`) |
| Composer | 2.x |
| OpenAI | an API key, plus a chat model and a vision model your account can access |

---

## Setup

```bash
# 1. Dependencies (production install omits dev tooling).
composer install --no-dev            # or plain `composer install` for development

# 2. Configuration. Copy and edit; see the file's own comments and §Configuration below.
cp .env.example .env

# 3. Create the schema.
./yii migrate:up

# 4. Verify configuration and connectivity (no OpenAI calls).
./yii kf:health

# 5. Verify OpenAI access and capabilities (makes real calls; needs a live key + model ids).
./yii kf:openai:ping

# 6. Create the first administrator (password generated and printed once).
./yii kf:admin:create
```

Then wire up nginx (`docs/nginx/`), directory permissions (`docs/deploy/fix-permissions.sh`), and the
cron worker (`docs/deploy/worker.md`).

---

## Configuration

All settings come from `.env` (or the real process environment, which wins over the file). Every
variable is documented in [`.env.example`](.env.example). Key points:

- **One `.env`, both tiers.** PHP-FPM and the cron worker run as the same user and load the same file,
  so their configuration cannot drift. `./yii kf:health` prints a redacted config fingerprint; run it as
  the deploy user and as `www-data` and confirm the fingerprints match.
- **Fail fast.** A missing or malformed required variable throws a clear, secret-free error at boot
  rather than silently falling back to a default.
- **Models are intentionally blank** in `.env.example`. Set `OPENAI_CHAT_MODEL` and
  `OPENAI_VISION_MODEL`, then run `./yii kf:openai:ping` to confirm access and capability.
- **`APP_DEBUG=false` in production.** With it on, stack traces and request state render to the browser.

---

## Running the worker

The background worker is a cron job. Full setup — one-time directory prep, the single `flock` + `nice`
cron line, log rotation, and the recovery/reconcile commands — is in **[`docs/deploy/worker.md`](docs/deploy/worker.md)**.

```
kf:worker:run [--limit=N]   one pass: provision → process → clean up
kf:documents:recover        requeue documents stuck in processing
kf:ai:reconcile             resolve ambiguous non-idempotent OpenAI operations
```

---

## Web server

Sample vhosts live in [`docs/nginx/`](docs/nginx/):

- `knowledge-forge.conf` — production (TLS via certbot, HTTP→HTTPS redirect, static asset caching).
- `knowledge-forge.dev.conf` — local development over plain HTTP.

The document root is `public/` only; `runtime/`, `src/`, `config/`, `vendor/` and `.env` sit outside it
and are unreachable over HTTP. Security response headers (CSP, `X-Frame-Options`, `nosniff`,
`Referrer-Policy`, `Permissions-Policy`, and HSTS over HTTPS) are set by the application, so they hold
behind any front end and are not duplicated in the vhost.

---

## Security model

- **Prompt injection** — an immutable security block wraps every model instruction (asserted first and
  reasserted last); document text enters only as tool output and is framed as untrusted reference data.
- **Grounding** — forced File Search plus a server-side verifier; an uncited or unretrieved answer is
  replaced by the fallback, never shown as fact.
- **Secrets** — the API key is a `SecretValue` (throws on stringify, redacted in dumps); a
  `SecretRedactor` scrubs keys/bearer tokens from every message before it is logged or persisted;
  `APP_DEBUG=false` is mandatory in production.
- **Uploads** — random stored filenames, extension from the sniffed MIME (never the client name), stored
  outside `public/`, images validated with `getimagesize()`, size and per-KB caps enforced.
- **IDOR / CSRF / XSS / SQLi** — every child lookup is scoped by its parent id; all state changes are
  CSRF-protected POST forms; output is HTML-escaped and Markdown is rendered with HTML escaping; all
  queries use bound parameters.

Each request carries a random correlation id, echoed as the `X-Correlation-Id` response header and
stamped on every log record, so a user-reported error maps to exact log lines.

---

## Development & quality gate

```bash
composer test                                                   # full Codeception suite
./vendor/bin/psalm                                              # static analysis (level 1)
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run --diff
./vendor/bin/rector --dry-run
./vendor/bin/composer-dependency-analyser --config=composer-dependency-analyser.php
composer yii-config-rebuild                                     # after ANY config/ change
```

All automated tests use fakes for OpenAI — there are zero live API calls in the suite.

---

## Deployment checklist

1. `composer install --no-dev`.
2. `.env` present, owned `<deploy_user>:www-data`, mode `0640`; `APP_DEBUG=false`, `APP_ENV=prod`.
3. `./yii migrate:up` clean.
4. Permissions: `docs/deploy/fix-permissions.sh` (runtime dirs group-writable, setgid, `.env` locked down).
5. `./yii kf:health` exits 0; the CLI and `sudo -u www-data` fingerprints match.
6. `./yii kf:openai:ping` passes (models accessible and capable).
7. nginx vhost installed, TLS obtained, `nginx -t` clean, reloaded.
8. Cron worker installed for `www-data` and log rotation in place (`docs/deploy/worker.md`).
9. First admin created (`kf:admin:create`).
10. Smoke test: create a KB → worker provisions it → upload a document → worker indexes it → ask a
    question and confirm a grounded, cited answer.

## Backup

- **Database** — `mysqldump knowledge_forge_db` on your normal schedule; it holds knowledge bases,
  rules, document metadata, conversations and the operation ledger.
- **Stored files** — back up `runtime/storage/` (original uploads and derived Markdown). The OpenAI
  vector stores can be rebuilt from these via re-index if lost.
- Restore order: database, then files; then run the worker to reconcile any drift.
