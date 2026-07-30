# Background worker (cron + flock)

All OpenAI work except chat runs in a background worker: provisioning a knowledge base's vector store,
processing uploaded documents into the index, and cleaning up remote files after a delete or re-index.
The web tier only ever *enqueues* this work and returns immediately, so an upload or a "Remove" click is
always fast and never blocks on the provider.

The worker is a plain CLI command fired every minute by cron. There is no queue server, no daemon.

## Commands

| Command | What it does | Exit codes |
|---|---|---|
| `kf:worker:run [--limit=N]` | One pass: provision → process → clean up. `--limit` caps items **per drainer** (default `DOCUMENT_WORKER_BATCH_SIZE`). | `0` healthy / nothing to do / lock held · `1` an item failed · `70` infrastructure fault |
| `kf:documents:recover` | Requeues documents stuck in `processing` past `DOCUMENT_PROCESSING_TIMEOUT_MINUTES` (a worker that died mid-run). The worker also does this automatically each pass; this is the manual escape hatch. | `0` |
| `kf:ai:reconcile [--limit=N]` | Resolves non-idempotent OpenAI operations left ambiguous by a lost response, adopting the existing remote object instead of creating a duplicate. | `0` |

The exit code exists so cron mail / monitoring can tell "a document failed" (`1`, expected, visible in the
UI) apart from "the box is broken" (`70`, e.g. the database is unreachable).

## Why the run is non-blocking

Each `kf:worker:run` does a **single** poll of the provider per document and then defers: a document that
is still indexing is left `indexing` and picked up again next minute. A document therefore reaches
`ready` over several cheap runs rather than one long one — which is what keeps the worker friendly on a
shared box. It never sleeps waiting for OpenAI.

## Two locks (belt and braces)

* **`flock` in the crontab line** stops a slow run from overlapping the next minute's run.
* **`FlockWorkerLock` inside the command** takes the *same* lock file, so a manual
  `sudo -u www-data ./yii kf:worker:run` cannot collide with a cron run either.

Both are non-blocking (`LOCK_NB` / `flock -n`): if the lock is held, the new run exits immediately as a
no-op (exit `0`). The OS releases the lock if the process dies, so a crash never leaves it stuck.

## One-time setup

Directories must exist and be group-writable **before** cron first fires (see
[`fix-permissions.sh`](fix-permissions.sh)):

```bash
DEPLOY_USER=<deploy_user>            # owns the checkout; e.g. deploy
APP=/var/www/html/knowledge-forge
sudo install -d -o "$DEPLOY_USER" -g www-data -m 2775 \
     "$APP/runtime/locks" "$APP/runtime/logs" "$APP/runtime/storage"

# Verify it runs as www-data (same identity as PHP-FPM) before automating it.
sudo -u www-data /usr/bin/php "$APP/yii" kf:health
sudo -u www-data /usr/bin/php "$APP/yii" kf:worker:run --limit=1
```

`2775` sets the setgid bit so files created by either the web tier or the worker keep the `www-data`
group — this is what stops "the worker cannot read the file the web tier just uploaded".

## The cron line

Install for **www-data** (`sudo crontab -u www-data -e`) — the same identity as PHP-FPM, so uploaded and
worker-written files are mutually readable and both tiers load the same `.env`. It is a **single** line
(multi-line cron entries silently break):

```cron
MAILTO=""
* * * * * /usr/bin/flock -n /var/www/html/knowledge-forge/runtime/locks/worker.lock /usr/bin/nice -n 10 /usr/bin/php /var/www/html/knowledge-forge/yii kf:worker:run --limit=1 >> /var/www/html/knowledge-forge/runtime/logs/worker.log 2>&1
```

* **`nice -n 10`** — this box serves other sites; the worker yields CPU under contention. The job is
  mostly network-bound, so `ionice` is not needed.
* **`--limit=1`** — one document per minute is deliberate: it bounds memory, OpenAI spend and blast
  radius. Raise only after watching `worker.log`.

## Log rotation

`/etc/logrotate.d/knowledge-forge`:

```
/var/www/html/knowledge-forge/runtime/logs/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data www-data
}
```

## Tunables (`.env`)

| Variable | Meaning |
|---|---|
| `DOCUMENT_WORKER_BATCH_SIZE` | Default `--limit`: items handled per drainer per run. |
| `DOCUMENT_MAX_PROCESSING_ATTEMPTS` | Retries before a document is marked `failed`. |
| `DOCUMENT_PROCESSING_TIMEOUT_MINUTES` | How long a `processing` document may sit before recovery requeues it. |
| `DOCUMENT_RETRY_BASE_SECONDS` | Base for exponential backoff (`base × 2^(attempt-1)`, capped at 1 h). |
| `AI_OPERATION_MAX_ATTEMPTS` | Reconcile/provision attempts before giving up. |
| `OPENAI_INDEX_POLL_INTERVAL_SECONDS` | Delay before the next run re-polls an indexing document. |
| `DOCUMENT_WORKER_LOCK_PATH` | The `flock` file both locks use. |
