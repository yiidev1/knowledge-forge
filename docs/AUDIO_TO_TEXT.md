# Audio to Text — setup, operation and rollback

Local speech-to-text for Knowledge Forge, with optional customer/agent speaker separation. Every
stage runs on this machine. **No audio, and nothing derived from it, leaves the server.**

---

## 1. What it does

**Every conversion belongs to an Order58 store.** An authenticated administrator opens
`/audio-to-text`, which leads to a store picker, chooses a store, and lands on that store's own audio
page. There they upload in one of two modes:

* **Common / mixed** — one recording containing both people. The pipeline works out who is speaking,
  and the administrator can correct it afterwards (§8).
* **Separate Customer + Agent** — two recordings from one call, one per person. The roles were
  supplied rather than inferred, so **diarization never runs** for them, nothing is claimed about
  speaker confidence, and there is nothing to correct.

Either way the HTTP request validates the upload, writes each file to a private directory, records its
duration and inserts a **conversation** row plus one `QUEUED` job per recording — then redirects. It
does not transcribe anything.

A background console worker claims the job and does the work:

```
QUEUED → CLAIMED → CONVERTING → TRANSCRIBING → [DIARIZING → MAPPING_SPEAKERS] → SAVING → COMPLETED
```

The job page shows both the stable status and the current stage, polling every two seconds until the
job is terminal. When it finishes it shows the complete transcript, the detected language, the
duration, who uploaded it, and — when speaker separation succeeded — separate **Customer** and
**Agent** sections with their own downloads.

**This is a shared administrator demo.** Every authorized administrator sees every job.
`uploaded_by_admin_id` is retained as audit metadata and surfaced as an "Uploaded by" column, but it
is not an access-control key. Authorization is *authenticated administrator + the job exists*.

---

## 2. Why transcription never runs in the web request

Measured on this machine, not estimated:

| Stage | Wall | Peak RSS | CPU |
|---|---|---|---|
| ffmpeg `-threads 1` | 0.07 s | 49 MB | 97% of one core |
| whisper-cli `-t 1` | 88–94 s | **833.9 MB** | 99% of one core |
| whisper-cli `-t 4` | 26.9 s | — | ~380% |

Real-time factor at one thread is **1.19–1.27**, so a recording at the 300-second cap takes about
355 seconds (measured) against the 600-second timeout — roughly 1.7× headroom. See §6 for the full
5-minute measurement.

Inside PHP-FPM that is a worker process held for a minute and a half, a request the browser abandons
long before it finishes, and no queue in front of any of it. A unit test
(`tests/Unit/AudioToText/WebTierCannotRunWhisperTest.php`) walks every `src/*/Web/` directory and
fails the build if the transcriber, the diarizer, `ProcessRunner` or `proc_open` are so much as named
there. Its allow-list is empty, and should stay that way.

---

## 3. Requirements, and what is already here

| Tool | Path on this machine | Status |
|---|---|---|
| ffmpeg | `/usr/bin/ffmpeg` | present |
| ffprobe | `/usr/bin/ffprobe` | present |
| whisper-cli | `/opt/whisper.cpp/build/bin/whisper-cli` (1.9.3-dev) | present |
| model | `/opt/whisper.cpp/models/ggml-small.bin` (487 MB, multilingual) | present |
| PHP CLI | 8.2.28 | ok |
| PHP-FPM | `unix:/run/php/php8.2-fpm.sock`, runs as `www-data` | ok |
| sherpa-onnx | `/opt/audio-diarization/venv` (1.13.6) | **installed** |
| segmentation model | `/opt/audio-diarization/models/segmentation.onnx` (5,992,913 B) | **installed** |
| embedding model | `/opt/audio-diarization/models/embedding.onnx` (28,281,164 B) | **installed** |
| systemd timer | `knowledge-forge-audio-worker.timer`, every 2 min | **installed and active** |

Speaker separation is installed, enabled (`AUDIO_DIARIZATION_ENABLED=true`) and running in
production. §8 keeps the install procedure for a rebuild or a second machine.

Verify the whole toolchain:

```bash
/usr/bin/ffmpeg -version | head -1
/usr/bin/ffprobe -version | head -1
/opt/whisper.cpp/build/bin/whisper-cli --version
ls -l /opt/whisper.cpp/models/ggml-small.bin
php -m | grep -E 'fileinfo|pdo_mysql|mbstring'

# Diarization, and the models it loads.
/opt/audio-diarization/venv/bin/python3 -c 'import sherpa_onnx; print(sherpa_onnx.__version__)'
sha256sum /opt/audio-diarization/models/*.onnx

# The application's own view — this is the authoritative check, because it validates every
# setting together and is what the worker runs at startup.
sudo -u www-data php yii kf:audio:worker --once
```

Installed model checksums, for confirming a machine matches this one (these are the **extracted**
files; the download archives have their own checksums, recorded in §8):

```
220ad67ca923bef2fa91f2390c786097bf305bceb5e261d4af67b38e938e1079  segmentation.onnx
aa3cfc16963a10586a9393f5035d6d6b57e98d358b347f80c2a30bf4f00ceba2  embedding.onnx
```

The model is deliberately `ggml-small.bin`, **not** `ggml-small.en.bin`: recordings mix English,
Spanish, Gujarati and Hindi, and the English-only model transcribes the rest as though it were
English.

### Web server — one change required for separate uploads

Measured on this host:

| Setting | Current | Required for separate uploads | Note |
|---|---|---|---|
| nginx `client_max_body_size` | `32m` | **`64m`** | **the binding ceiling** |
| PHP `post_max_size` | `40M` | **`64M`** | whole request body |
| PHP `upload_max_filesize` | `40M` | unchanged | per file; 30 MB fits |
| PHP `max_file_uploads` | `30` | unchanged | two is well inside it |
| `AUDIO_TRANSCRIPTION_MAX_SIZE` | 30 MB | **unchanged** | per file, not per request |

A **common** upload is one file and needs nothing changed. A **separate** upload is two files in one
request: two at the 30 MB per-file ceiling plus multipart boundaries and headers is a little over
60 MB, so 64 M leaves roughly 4 MB of headroom. The per-file limit is deliberately *not* reduced — it
is the operator's stated ceiling, and nothing about pairing justifies lowering it.

Until both are raised, a large separate upload dies at nginx with a bare 413 that the application never
sees and cannot explain. Common uploads are unaffected either way.

```bash
# 1. nginx — /etc/nginx/sites-available/knowledge-forge.conf (and docs/nginx/knowledge-forge.conf)
#    client_max_body_size 32m;  →  client_max_body_size 64m;
sudo nginx -t && sudo systemctl reload nginx

# 2. PHP-FPM — /etc/php/8.2/fpm/php.ini
#    post_max_size = 40M  →  post_max_size = 64M
sudo systemctl reload php8.2-fpm

# 3. Confirm what is actually serving requests. The CLI ini is a different file and is not the one
#    that answers HTTP, so `php -i` from a shell can and does report different numbers.
php -r 'echo ini_get("post_max_size"), PHP_EOL;'   # CLI — NOT authoritative
grep -n 'post_max_size' /etc/php/8.2/fpm/php.ini   # FPM — this is the one
```

**Rollback:** restore `32m` and `40M` and reload both. Separate uploads over about 32 MB combined then
fail at nginx again; every other path, including all common uploads, is unaffected. No application
change is needed to roll back.

**nginx is the tightest of the three, so it — not PHP — is what caps
`AUDIO_TRANSCRIPTION_MAX_SIZE`.** Above `client_max_body_size` the upload is refused with a 413 by
the web server before PHP is reached, so the validator never runs and the administrator sees a bare
error page rather than the feature's own message. Raising the setting past ~30 MB therefore means
editing `/etc/nginx/sites-enabled/knowledge-forge.conf` first.

The application does not depend on those ceilings for its messages. `SeparateUploadValidator` checks
each file against the per-file limit and the pair against the combined one, so the ordinary failures
produce a sentence naming the field rather than nginx's bare 413. The server-side check is
authoritative; nginx and PHP are the outer wall.

That matters for one supported case: a 5-minute *stereo* 44.1 kHz WAV is about 50 MB and is rejected.
Every mono format fits — see the size table in `.env` beside `AUDIO_TRANSCRIPTION_MAX_SIZE`. Phone
recordings, which is what this feature processes, are 8 kHz mono at 4.6 MB.

`fastcgi_read_timeout` is irrelevant to transcription — the upload request returns in well under a
second. It only bounds the upload itself.

---

## 4. Running the worker

**As `www-data`.** Job directories are mode 0700 and created by PHP-FPM; no other user can traverse
them, let alone delete them.

```bash
# Continuous — a development terminal.
sudo -u www-data php /var/www/html/knowledge-forge/yii kf:audio:worker

# One job, then exit — what a timer or cron runs, and what tests use.
sudo -u www-data php /var/www/html/knowledge-forge/yii kf:audio:worker --once
```

A second worker refuses the lock, says so, and exits **0** — a correctly-refused duplicate is not a
failure and a supervisor should not restart it in a loop.

### Two independent concurrency guarantees, both required

1. **`flock` on `runtime/audio-to-text/worker.lock`**, non-blocking, held for the process lifetime.
   This is what makes the limit global. `flock` rather than a database advisory lock because the
   kernel releases it however the process dies — no stale lock to detect, no recovery code to get
   subtly wrong. The trade-off is that it covers one machine, which is the deployment.
2. **An atomic conditional claim**: `UPDATE … SET status='PROCESSING' WHERE id=? AND
   status='QUEUED'`, claimed only when exactly one row changed.

The claim alone is not enough: it would happily let worker A take job 1 while worker B takes job 2 —
two whisper processes on one CPU. The lock is what prevents that.

The lock file lives *beside* `jobs/`, never inside it, so the orphan sweep cannot delete the file
that guarantees single-worker operation. It is never unlinked: removing it races with a process that
has already opened the same path.

---

## 5. Cross-project coordination — read this

**`/etc/cron.d/telecom-billing-audio-transcription` is installed and active on this machine.** It
runs a separate audio worker every minute as `www-data`. Knowledge Forge's `worker.lock` cannot see
telecom-billing's, so left alone both projects could run whisper in the same minute: 2 × 834 MB and
2 of 4 physical cores on a 1.6 GHz laptop CPU already at load 2.48.

Three mechanisms, ranked honestly:

**1. Participate in the other project's lock — race-safe, and the real answer.**
Its cron line already serialises itself with `flock -n <its lock file>`. Setting:

```ini
AUDIO_WORKER_FOREIGN_LOCKS=/var/www/html/telecom-billing/runtime/audio-to-text/cron-worker.lock
```

makes our worker take an exclusive non-blocking `flock` on *that same file* before claiming a job and
hold it until the job finishes. Both workers are then in one kernel-arbitrated queue; the loser's
`flock -n` exits 1 with no output. There is no window, because `flock(2)` has no window.

Nothing is written to the other project: the file is opened read-only, so it is never created.

*Cost, stated plainly:* while we transcribe, telecom-billing's queue stalls for up to a few minutes.
On a shared demo box that is the right trade, but it is a real effect.

*Fails closed:* a configured lock that is held, missing **or unreadable** defers the tick. That last
case is not hypothetical — the other project's `runtime/audio-to-text/` is `0750 www-data:www-data`,
so a worker started as any other user cannot read it and would defer every tick. The worker prints an
explicit warning naming the path at startup rather than stalling silently. Run as `www-data`, or
blank the setting.

**2. `AUDIO_WORKER_YIELD_TO_OTHER_WHISPER` — best-effort only.** A process scan that catches a
foreign worker started by hand, which takes no cron lock. **Racy by construction** and never the
basis of an exclusivity claim.

**3. The only complete guarantee — disable the other schedule while demoing:**

```bash
sudo mv /etc/cron.d/telecom-billing-audio-transcription /root/    # disable
sudo mv /root/telecom-billing-audio-transcription /etc/cron.d/    # restore
```

**What is claimed, precisely.** *Within this feature*, exactly one transcription or diarization runs
machine-wide — a guarantee, because both mechanisms are kernel- or InnoDB-arbitrated. *Across both
projects*, mechanism 1 makes cron-driven overlap race-safe, mechanism 2 is best-effort, and only
mechanism 3 is absolute.

---

## 6. Scheduling in production

**The chosen scheduler is a systemd timer firing every 2 minutes.** Cron remains in the repository
only as a fallback for a host without systemd. **Run one or the other, never both, and never
alongside a permanent foreground worker.**

```
admins upload freely → jobs stay QUEUED
timer every 2 minutes → kf:audio:worker --once → one oldest QUEUED job → exit
```

**Nothing is installed automatically. Nothing in this project writes to /etc.**

### Why a timer rather than cron

`nice` and `ionice` are *priority* controls, not limits — on an idle machine a niced job still takes a
whole core. The unit's cgroup settings are ceilings the kernel enforces. This host runs systemd 249 on
a cgroup v2 unified hierarchy with `cpu`, `memory`, `io` and `pids` delegated (verified).

It also needs no outer lock file: **systemd will not start a second run of a unit that is still
active.** A transcription takes roughly 90 seconds and can occasionally outlast the interval; when it
does, the next tick is skipped rather than run in parallel.

### Install

```bash
sudo install -o root -g root -m 0644 \
  /var/www/html/knowledge-forge/docs/server/systemd/knowledge-forge-audio-worker.service \
  /etc/systemd/system/
sudo install -o root -g root -m 0644 \
  /var/www/html/knowledge-forge/docs/server/systemd/knowledge-forge-audio-worker.timer \
  /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now knowledge-forge-audio-worker.timer
```

### Verify

```bash
systemctl list-timers knowledge-forge-audio-worker.timer   # next/last fire time
systemctl status knowledge-forge-audio-worker.service      # last run's outcome
journalctl -u knowledge-forge-audio-worker -f              # watch ticks live
systemd-cgtop -1 | grep knowledge-forge                    # confirm the quota applies
```

An idle tick logs `No queued jobs.` and exits 0. A working tick logs
`Processing <id> (<file>)` then `completed in Ns — <lang>, N characters, speaker split: <status>`.

### Change the interval

Edit `OnUnitActiveSec` in the timer — nothing else, no code change:

```bash
sudoedit /etc/systemd/system/knowledge-forge-audio-worker.timer   # OnUnitActiveSec=2min
sudo systemctl daemon-reload
sudo systemctl restart knowledge-forge-audio-worker.timer
```

### Stop or remove

```bash
sudo systemctl disable --now knowledge-forge-audio-worker.timer   # stop scheduling
sudo systemctl stop knowledge-forge-audio-worker.service          # stop a run in flight
sudo rm /etc/systemd/system/knowledge-forge-audio-worker.{service,timer}
sudo systemctl daemon-reload
```

Disabling the timer never loses work: queued jobs stay QUEUED until something runs the worker again.

### Limits, all measured on this machine

| Setting | Value | Derivation |
|---|---|---|
| `CPUQuota` | `100%` | exactly one core; whisper measured 99% at `-t 1` |
| `MemoryHigh` | `1200M` | 1.45 × the 833.9 MB whisper peak — throttles rather than kills |
| `MemoryMax` | `1600M` | 1.9 × peak, OOM backstop |
| `TimeoutStartSec` | `1200` | must exceed 600s transcription + 300s diarization = 900s worst case |
| `Nice` / `IOSchedulingClass` | `15` / `idle` | nginx, PHP-FPM and MySQL served first |

Measured per stage, each pinned to one thread. Two recordings: the original 73.7-second reference,
and 297 seconds of real two-party call audio at the 5-minute duration cap.

| Stage | 73.7 s recording | 297 s recording | RTF | Peak RSS at 297 s |
|---|---|---|---|---|
| ffmpeg | 0.07 s | **0.10 s** | 0.0003× | 49.6 MB |
| whisper-cli `-t 1` | 82–106 s | **354.6 s** | **1.19×** | **904.4 MB** |
| sherpa-onnx diarization | 10.4 s | **48.9 s** | 0.16× | 368.1 MB |
| alignment + role mapping | <20 ms | **15 ms** | — | in-process |
| **end to end** | ~110 s | **403.6 s** | 1.36× | ~964 MB incl. PHP |

Three things follow, and they are why the 5-minute cap needed more than one setting changed:

* **Whisper takes 354.6 s at the cap, which is longer than the old 300 s timeout.** A five-minute
  recording would have been killed mid-transcription. `AUDIO_TRANSCRIPTION_TIMEOUT` is now 600.
* **Diarization needed nothing.** 48.9 s against a 300 s timeout is over 6× headroom, so
  `AUDIO_DIARIZATION_TIMEOUT` stays at 300.
* **Neither memory ceiling moved.** Whisper's footprint is dominated by the 487 MB model, so
  quadrupling the audio took the peak from 833.9 MB to 904.4 MB — still under `MemoryHigh=1200M`.
  Diarization grew more in relative terms (194 → 368 MB, it holds one embedding per segment) but
  remains far below whisper, so the peak is still whisper's.

The stages run sequentially inside one job, so the peak is `max(904, 368) + ~60 MB` for the PHP
worker, not their sum. **Neither speaker separation nor the longer duration cap moved the
ceilings.**

An OOM kill at `MemoryMax` is survivable by design: the transcript is committed the moment Whisper
succeeds, so recovery completes the job and only the speaker split is lost.

### Logs

journald, so there is no custom logfile to grow unbounded or rotate by hand:

```bash
journalctl -u knowledge-forge-audio-worker -f          # follow
journalctl -t kf-audio-worker --since "1 hour ago"     # by identifier
journalctl -u knowledge-forge-audio-worker -p err      # failures only
```

### Worker status under a timer

Between ticks no worker process exists, so the admin page reads **"Scheduled — last ran N ago"**, not
"Running". It says **"Not running"** only once ticks themselves have stopped for
`AUDIO_WORKER_TICK_STALE_AFTER` (180 s ≈ three missed 2-minute ticks... set it to ~400 if you widen
the interval beyond 2 minutes).

### Cron fallback

`docs/server/cron/knowledge-forge-audio-transcription` is kept for a host without systemd. It needs an
**outer** `flock` on `cron-worker.lock` — a *different file* from the application's own `worker.lock`,
because pointing it at the application's lock would make every run skip. Do not install it while the
timer is enabled.

## 7. Configuration — one place to change anything

> ### To change Audio-to-Text configuration, change it in `.env`.

That is the whole rule. Every setting — Whisper binary and model, thread count, upload size, max
duration, timeouts, queue size, retention, heartbeat thresholds, resource thresholds, foreign lock
paths, diarization on/off, diarization binary and models, confidence, speaker count — is one line in
`.env`, and the entire feature picks it up.

Typical changes look like exactly this and nothing else:

```env
AUDIO_DIARIZATION_ENABLED=true
AUDIO_WORKER_FOREIGN_LOCKS=/var/www/html/telecom-billing/runtime/audio-to-text/cron-worker.lock
AUDIO_TRANSCRIPTION_MAX_DURATION=300
```

Every variable is listed with its default and the reasoning behind it in `.env.example`, under
**Audio to Text**.

### Currently deployed

| Setting | Value | Meaning |
|---|---|---|
| `AUDIO_TRANSCRIPTION_MAX_DURATION` | `300` | 5 minutes |
| `AUDIO_TRANSCRIPTION_MAX_SIZE` | `31457280` | 30 MB, under the nginx 32m ceiling (§3) |
| `AUDIO_TRANSCRIPTION_TIMEOUT` | `600` | whisper child process |
| `AUDIO_DIARIZATION_TIMEOUT` | `300` | sherpa child process |
| `AUDIO_TRANSCRIPTION_STALE_AFTER` | `1200` | a job PROCESSING longer than this is presumed dead |
| `AUDIO_TRANSCRIPTION_THREADS` | `1` | every stage pinned to one core |
| `AUDIO_TRANSCRIPTION_MAX_QUEUE` | `0` | unlimited queueing |
| `AUDIO_TRANSCRIPTION_RETENTION_SECONDS` | `0` | keep conversations and recordings indefinitely |
| `AUDIO_DIARIZATION_ENABLED` | `true` | speaker separation on |
| `AUDIO_DIARIZATION_MIN_CONFIDENCE` | `0.55` | role-mapping gate (gate 4, §8) |
| `AUDIO_DIARIZATION_BOUNDARY_TOLERANCE_MS` | `1500` | gap bridging in alignment |
| `AUDIO_DIARIZATION_MAX_SPEAKERS` | `2` | two-party calls |
| `AUDIO_WORKER_FOREIGN_LOCKS` | telecom-billing's lock | cross-project coordination (§5) |

### One exception to "one line": the duration chain

Raising `AUDIO_TRANSCRIPTION_MAX_DURATION` is the one change that is **not** self-contained, because
three other limits have to stay above it. They form a chain, and the shortest link decides the longest
recording the system can actually finish:

```
MAX_DURATION 300  ──(x1.19 measured real-time factor)──>  ~355s of whisper
   TRANSCRIPTION_TIMEOUT 600   must exceed that, and startup validation enforces >= MAX_DURATION x 1.5
   DIARIZATION_TIMEOUT   300   measured ~49s at 5 minutes, so 6x headroom
   STALE_AFTER          1200   must exceed TRANSCRIPTION_TIMEOUT + DIARIZATION_TIMEOUT = 900
   TimeoutStartSec      1200   systemd; must also exceed that 900s worst case  <- lives in /etc
```

The first is checked for you: `AudioToTextSettings::problems()` refuses to run the worker when
`TRANSCRIPTION_TIMEOUT < MAX_DURATION x 1.5`, printing "the Audio-to-Text configuration is not usable"
rather than letting every long recording die on the clock. The last one is a systemd unit, so it needs
a re-install (§6) — the only part of this feature that cannot be changed from `.env` alone.

### How that is guaranteed, for whoever maintains this next

There is exactly one authoritative definition of each value, and four layers that carry it without
adding opinions of their own:

| Layer | File | What it may do |
|---|---|---|
| **Source of truth** | `src/Environment.php` (`SPEC`) | the default, the type, the valid range |
| Deployment override | `.env` | override a default for this machine |
| Transport | `config/common/params.php` | read the variable. Nothing else |
| Assembly | `config/common/di/audio-to-text.php` | build the settings object. Defines no default |
| Consumption | `App\AudioToText\Application\AudioToTextSettings` | the only settings type any service injects |

Two rules keep it that way, and both are worth preserving:

* **No class in `src/AudioToText/` reads an environment variable.** Not one. They receive
  `AudioToTextSettings` and read `->transcription`, `->worker` or `->diarization`.
* **No default is written twice.** `params.php` and the DI file copy values; they never supply a
  fallback, because a fallback in a transport layer is a second source of truth that disagrees with the
  first the moment someone edits one of them.

Adding a setting is therefore three mechanical edits with no judgement calls:

1. `src/Environment.php` — add the `SPEC` entry (default, type, range)
2. `config/common/params.php` — read it
3. `config/common/di/audio-to-text.php` — pass it into the settings object

No constructor anywhere else changes, because every service already injects the one settings object.

### The configuration is validated at startup

`kf:audio:worker` checks the configuration before claiming any work and **refuses to start** on a
problem, naming the variable to fix:

```
[ERROR] The Audio-to-Text configuration is not usable:
          - WHISPER_BINARY: "/nope/whisper" is not an executable file.
          - AUDIO_TRANSCRIPTION_TIMEOUT (10s) is too low for AUDIO_TRANSCRIPTION_MAX_DURATION (120s):
            transcription runs at roughly 1.3x real time, so allow at least 180s.
```

It checks binaries and models exist, that the timeout can actually accommodate the duration cap, and
that every configured foreign lock is readable — the last being the one misconfiguration that would
otherwise stall the queue in total silence.

**Diarization paths are only checked when `AUDIO_DIARIZATION_ENABLED=true`.** Requiring models nobody
has installed would make the shipped default fail its own validation.

Non-fatal observations are printed as notes rather than errors, so a worker with an empty
`AUDIO_WORKER_FOREIGN_LOCKS` or with diarization off says so once and carries on.

### CPU budget

`AUDIO_TRANSCRIPTION_THREADS` is the single CPU budget for the whole pipeline. It sets ffmpeg's
`-threads`, whisper's `-t`, the diarizer's `--num-threads`, and the `OMP_NUM_THREADS` /
`OPENBLAS_NUM_THREADS` / `MKL_NUM_THREADS` family in every child process — the last being what stops a
numeric library sizing its own pool from the core count. That environment is built once, in
`AudioToTextSettings::childProcessEnvironment()`, so there is no per-launcher copy to drift.

Leave it at 1. The worker warns at startup if it is raised.

## 8. Speaker separation — customer and agent

### What the installed toolchain can and cannot do

whisper.cpp 1.9.3 on this machine offers exactly two things that sound relevant, and neither is
speaker diarization:

* **`-di` / `--diarize`** splits *stereo channels*. The reference recording is 8 kHz **mono**, so
  there are no channels to split. This does not work for phone audio.
* **`-tdrz` / `--tinydiarize`** needs a `ggml-small.en-tdrz` model (not installed, English-only) and
  marks *turn boundaries* — it never establishes that turn 1 and turn 3 were the same person.

There is a second, subtler problem that only shows up with real data. Whisper's own segments are far
too coarse to align against: on the 73.7-second reference recording it emitted **five segments**, one
spanning 25.0 s → 39.2 s and containing roughly eight speaker turns. Aligning at segment level puts
both sides of the conversation in one column. The implementation therefore uses `-ojf`, which emits
**per-token millisecond timestamps in the same pass at no extra CPU cost**, and aligns at token level.

### The pipeline

```
ffmpeg → 16 kHz mono WAV
      → whisper-cli -otxt -oj -ojf     (transcript + language + token timestamps, one pass)
      ══ transcript COMMITTED to the database here ══
      → diarizer                        (neutral SPEAKER_00 / SPEAKER_01 intervals)
      → align each token to the interval it overlaps most, coalesce into utterances
      → map neutral clusters to AGENT / CUSTOMER, with a confidence
      → store agent_text, customer_text, speaker_segments
```

Role mapping runs **only** on clusters the diarizer already separated by voice. It never infers
speakers from words — "Yes." tells you nothing about who said it — and it never assumes the first
voice is the agent, because calls open with the customer, an automated greeting, or mid-sentence.

### How roles are decided: exchanges, not vocabulary

Counting role-ish keywords per speaker does not work on order calls, and failed in a specific,
instructive way. **The two sides use the same words.** "Cash" and "card" are substrings of the agent's
own question; a competent agent repeats the address back to confirm it and recites the items during
the recap. Every one of those is the agent doing the job, and every one scored as customer evidence
against them. On a call whose roles are obvious to a human the margin came out at **0.077**.

What actually separates the roles is *position in the exchange* — one side asks for the address, the
other supplies it. So the mapper detects `DialogueAct`s and scores **adjacency pairs**:

| evidence | weight | why |
|---|---|---|
| question + its answer, from two different speakers | 1.5 – 3.0 | takes two people to produce; an echo cannot fake it |
| agent move with no answer found (quote a price, a delivery window, greet) | 0.75 – 1.5 | real but weaker — the answer may simply have been split badly |
| customer move with no question in front of it | **0.0** | indistinguishable from the agent echoing; carries no orientation |
| caller announcing intent ("I'd like to place an order") | 1.0 | the one customer move an order-taker never makes |

Two further rules keep boundary noise in its place. An unpaired act is scaled by the length of the
utterance it was found in, so a strong act on a two-word fragment cannot cancel a completed exchange.
And confidence is `agreement × volume` — how one-sided the evidence is, times how much of it there
is — so a single lucky pair in a short call scores a perfect ratio and still does not publish.

Within one utterance the **most specific act wins**: "cash or card?" is a payment *question* and never
also a payment *choice*. That single rule removes most of the old confusion.

All of it is local, deterministic and offline. The tunable numbers live in one file,
`Domain/Speaker/RoleScoreWeights.php`; the semantics live in `Domain/Speaker/DialogueAct.php` and the
patterns in `Application/Speaker/DialogueActDetector.php`. They are algorithm coefficients, not
operator settings, so they stay in code — `AUDIO_DIARIZATION_MIN_CONFIDENCE` remains the only dial,
and it decides how much certainty is required to publish, not how certainty is reached.

### Outcomes

The gates are checked in this order, and the first one that fails decides the outcome. Order matters:
each asks a question that only makes sense once the previous one has been answered.

| # | Check | Fails as | `agent_text` / `customer_text` |
|---|---|---|---|
| — | diarization disabled, or toolchain not installed | `NOT_SUPPORTED` | NULL |
| — | whisper produced no timestamped tokens | `FAILED` | NULL |
| — | diarizer threw, timed out, or returned no segments | `FAILED` | NULL |
| — | no token could be matched to any segment | `FAILED` | NULL |
| 1 | **alignment** — under 75% of speech attributed by duration | `NEEDS_REVIEW` | NULL |
| 2 | **separation balance** — the diarizer did not really find two speakers | `NEEDS_REVIEW` | NULL |
| 3 | **role mapping** — one cluster, three or more, or no role signals | `NEEDS_REVIEW` | NULL |
| 4 | **role confidence** — below `AUDIO_DIARIZATION_MIN_CONFIDENCE` | `NEEDS_REVIEW` | NULL |
| 5 | one of the two roles ended up with no speech | `NEEDS_REVIEW` | NULL |
| ✓ | all five pass | `COMPLETED` | populated |

Gates 2 and 4 are the two that must **both** hold, and they answer different questions — *were there
two speakers?* and *which one is the agent?*. Conflating them is not hypothetical: a 174-second call
(`21911549.wav`) where diarization gave one cluster 98.4% of the speech and the other a single
three-word turn was published as `COMPLETED` at role confidence **1.000**. The confidence was correct —
with one side's dialogue acts entirely absent there was nothing to contradict — and the result was
still unusable. Gate 2, `SeparationBalance`, is that missing question; its thresholds and their
derivation from measured jobs are documented in the class.

**In every row the full transcript is saved and the job's own status is `COMPLETED`.** A wrong
confident split is worse than an honest "needs review", because nobody re-checks a column that looks
finished. `speaker_segments` is always written when diarization ran, so any mapping can be audited.

### Three different things, and only one of them is a claim

`speaker_segments` stores a role on every utterance that reached role mapping at all, **whatever the
outcome**, because the mapper assigns roles before the confidence gate is evaluated. A `NEEDS_REVIEW`
row rejected at gate 4 and a `COMPLETED` row are therefore indistinguishable by their segments alone.
(A row rejected at gate 1 or 2 never reaches the mapper, so its roles stay `UNKNOWN` and no role
confidence is recorded — there was nothing worth mapping.) That is deliberate — an inconclusive mapping is worth
keeping so it can be second-guessed — but it means the role in that column is *evidence, not a
finding*, and nothing may read it as a conclusion:

| | what it is | where it lives | may be shown as fact |
|---|---|---|---|
| neutral diarization speaker | which voice, not whose | `speaker` in `speaker_segments` | yes, as "Speaker 1", "Speaker 2" |
| tentative role hypothesis | the mapper's guess, at any confidence | `role` in `speaker_segments` | **no** |
| published role | a guess that cleared every gate | `agent_text` / `customer_text` | yes |

**`speaker_separation_status` is the machine's own verdict on which of the last two applies**, and
`SpeakerSeparationStatus::isPublishable()` is the one place that decides it. Since speaker correction
landed there is a *second*, independent route to publication — an administrator confirming the roles —
and `ConversationView::from()` takes both. Nothing else may publish. The invariant:

```
COMPLETED      → confidence ≥ threshold, agent_text and customer_text both populated,
                 conversation labelled Agent / Customer
anything else  → agent_text and customer_text NULL, conversation labelled Speaker 1 / Speaker 2,
                 role names appearing only in an explicitly tentative block
```

`ConversationView` (in `Domain/Speaker/`) enforces it for the detail page, and requires *both* a
publishable status and the aggregate text to actually be present before it will print a role name —
so a self-contradictory row degrades to neutral labels rather than to a confident-looking lie. The
list page needs no equivalent rule because it renders only the aggregate columns, which are NULL by
construction unless the split was published.

### Correcting a conversation by hand — the reviewed layer

Everything the pipeline produces is a machine's best guess. The correction layer lets an administrator
overrule it **without ever destroying what the machine said**, which is the property the whole design
turns on. Sections 8.7–8.12 below are the A-to-Z of that feature: storage, flow, operations, screens,
validation and audit.

---

### 8.7 The two layers, and which one a page reads

Every job carries up to two versions of the same conversation.

| | columns | written by | mutable |
|---|---|---|---|
| **Machine layer** | `transcript`, `speaker_segments`, `agent_text`, `customer_text`, `speaker_separation_status` | `kf:audio:worker`, once | **never again** |
| **Reviewed layer** | `reviewed_segments`, `reviewed_agent_text`, `reviewed_customer_text`, `roles_confirmed_at` | an administrator, through the review page | on every correction |

`EffectiveConversationReader::for(TranscriptionJob)` is the **single** place that decides which one a
screen sees:

```
reviewed_segments IS NOT NULL  →  the reviewed layer
otherwise                      →  the machine layer
```

Every surface goes through it — the conversation page, the correction page and the job detail page —
so "which version is authoritative" is answered once instead of being re-decided at each call site,
where the copies would inevitably drift. It returns an `EffectiveConversation`: the turns, the two role
texts, whether the layer is reviewed, and whether the roles may be shown as fact.

A recording transcribed as *"Yes. For pikup"* still says exactly that in `transcript` after an
administrator has fixed the spelling for readers. That is what makes a correction an auditable
overlay rather than a quiet rewrite.

---

### 8.8 Database schema — the correction layer

Two migrations add everything. Neither touches a machine column.

**`M260831140000AddReviewedConversation`** — six columns on `audio_transcription_jobs`, plus the audit
table.

**`M260831160000AddRolesConfirmation`** — `roles_confirmed_at`, and widens the operation `CHECK` to
admit `CONFIRM_ROLES`.

#### `audio_transcription_jobs` — the columns the correction layer adds

| column | type | null | meaning |
|---|---|---|---|
| `reviewed_segments` | `json` | yes | the corrected turns. **NULL means "never corrected"**, and is what `EffectiveConversationReader` branches on |
| `reviewed_agent_text` | `text` | yes | aggregate Agent text, derived from `reviewed_segments`. NULL until the roles are confirmed |
| `reviewed_customer_text` | `text` | yes | the same for the Customer |
| `reviewed_at` | `datetime` | yes | when the layer was last written |
| `reviewed_by_admin_id` | `bigint` | yes | who last wrote it. FK → `admin_users`, `RESTRICT` |
| `roles_confirmed_at` | `datetime` | yes | when a person explicitly confirmed Agent/Customer. **NULL means unconfirmed** |
| `review_count` | `smallint unsigned` | no, `0` | the optimistic lock. Counts corrections *made*, not layers present |

Why a nullable timestamp rather than a boolean for `roles_confirmed_at`: it carries *when* at the same
storage cost, and the codebase already uses that idiom for exactly this shape of state
(`superseded_at`, `dismissed_at`, `reviewed_at`). There is deliberately **no `roles_confirmed_by`
column** — confirming writes a `CONFIRM_ROLES` revision, so the person and the moment are already
recorded once, and a second copy could only disagree with it.

#### `audio_segment_revisions` — the audit trail

```sql
CREATE TABLE `audio_segment_revisions` (
  `id`              bigint NOT NULL AUTO_INCREMENT,
  `job_id`          bigint unsigned NOT NULL,
  `revision_number` int unsigned NOT NULL,
  `segments_json`   json NOT NULL,      -- the conversation BEFORE this change
  `operation`       varchar(16) NOT NULL,
  `edited_by_type`  varchar(16) NOT NULL,
  `edited_by_id`    bigint unsigned NOT NULL,
  `created_at`      datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_audio_segment_revisions_job_number` (`job_id`, `revision_number`),
  CONSTRAINT `fk_audio_segment_revisions_job`
      FOREIGN KEY (`job_id`) REFERENCES `audio_transcription_jobs` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_audio_segment_revisions_number_positive` CHECK (`revision_number` > 0),
  CONSTRAINT `chk_audio_segment_revisions_by_id_positive`  CHECK (`edited_by_id` > 0),
  CONSTRAINT `chk_audio_segment_revisions_by_type`
      CHECK (`edited_by_type` IN ('admin', 'agent')),
  CONSTRAINT `chk_audio_segment_revisions_operation`
      CHECK (`operation` IN ('MOVE','SPLIT','MERGE','EDIT_TEXT','REVERT','CONFIRM_ROLES'))
) ENGINE=InnoDB;
```

Three decisions in that DDL are load-bearing:

* **`segments_json` stores the state *before* the change**, following the `message_revisions`
  precedent. On a job's first correction that is a copy of the machine's own segments, which makes the
  trail self-contained back to origin — you never have to consult another table to know where the
  conversation started.
* **`job_id` is `BIGINT UNSIGNED`**, matching `audio_transcription_jobs.id`. A signed column here
  fails with MySQL error 3780 at foreign-key creation time.
* **`ON DELETE CASCADE`** on the job, unlike the `RESTRICT` on the uploader: the revisions describe a
  conversation, so if the conversation goes they have nothing left to describe.

`ReviewOperation` (a backed enum) is the authority on the allowed values, and the `CHECK` constraint
mirrors it. **Adding a case there requires a migration** to widen the constraint.

#### Table usage at a glance

| table | read by the correction layer | written by it |
|---|---|---|
| `audio_transcription_jobs` | yes — every operation loads the job by `public_id` | only the seven `reviewed_*` / `roles_confirmed_at` / `review_count` columns |
| `audio_segment_revisions` | on the review page, to name who confirmed the roles | one row per accepted operation |
| `admin_users` | joined for the display username | never |
| `audio_worker_heartbeat` | no | no |

---

### 8.9 Routes — every entry point

All behind `RequireAdminMiddleware`. Every authorized administrator may correct every job; the
uploader is recorded for audit only. The `{publicId:[0-9a-f]{32}}` constraint rejects a malformed id
before any action runs and keeps the database id out of every URL.

| method | path | route name | action |
|---|---|---|---|
| GET | `/audio-to-text/job/{publicId}/conversation` | `…job.conversation` | read the conversation as a chat |
| GET | `/audio-to-text/job/{publicId}/review` | `…job.review` | the correction page |
| POST | `…/review/turn/{index}/move` | `…job.review.move` | reassign a whole turn (`role=AGENT\|CUSTOMER`) |
| POST | `…/review/turn/{index}/move-text` | `…job.review.move-text` | reassign a whole turn or a selection to the other speaker |
| POST | `…/review/turn/{index}/split` | `…job.review.split` | cut a turn at a character offset |
| POST | `…/review/turn/{index}/merge` | `…job.review.merge` | join with a neighbour, whole or by range |
| POST | `…/review/turn/{index}/text` | `…job.review.text` | correct the wording |
| POST | `…/review/confirm` | `…job.review.confirm` | confirm Agent/Customer for the conversation |
| POST | `…/review/revert` | `…job.review.revert` | discard every correction |

One route per operation rather than one endpoint dispatching on a field, so the route name, the
audited operation and the button a person pressed all say the same thing.

**Every "View" action opens `/review`** — the global conversions list's and a store history's alike.
A store row goes through `audio-to-text.conversion`, which redirects a common conversion here and
renders a separate one itself (§9.9).

That is safe from everywhere because this page never dead-ends: a row with nothing to correct — still
queued, failed, or never speaker-separated — is redirected on to the job detail page, so one link is
right for every row. An *unknown* id is still a 404: "no such job" and "not available to you" must be
indistinguishable from outside.

**Every route in this table applies to a common (mixed) recording only.** A separate Customer + Agent
conversion has no `speaker_segments` to correct and no speakers to confirm — the roles were supplied,
not inferred — so its conversion page offers neither `/conversation` nor `/review`. See §9.9.

---

### 8.10 Structure — what each class is for

```
Domain/
  ReviewOperation                    MOVE | SPLIT | MERGE | EDIT_TEXT | REVERT | CONFIRM_ROLES
  SegmentRevision                    one audit row
  SegmentRevisionRepositoryInterface port
  EffectiveConversation              turns + role text + isReviewed + rolesConfirmed
  Exception/ReviewRejected           a refusal, worded for the administrator
  Exception/ReviewConflict           somebody else corrected it first

Domain/Speaker/
  ReviewedTurn                       one corrected turn: span, voice, role, text, approx, edited
  ReviewedConversationTurns          the turn list as an immutable value — every rule lives here
  MergeDirection                     Previous | Next
  MergeRefusal                       None | NoNeighbour | DifferentRole | DifferentSpeaker (+ wording)
  SplitPoint                         offered split positions, computed from the text
  SpeakerMarkers                     strips whisper's ">>" speaker-change markers for display
  ConversationView / ConversationTurn / ConversationSide / TurnTiming / ResponseTiming
                                     what a page may claim, and how it is laid out

Application/
  EffectiveConversationReader        the one place that picks a layer
  ReviewConversationService          load → apply → audit → save, atomically

Web/Job/Conversation/                the read-only chat page
Web/Job/Review/                      the correction page
  Action, template                   GET
  ReviewPageView, ReviewTurnView     decisions made once, printed by the template
  ReviewRequest                      shared: who asked, which version, what to say
  Move|MoveText|Split|Merge|Text|Confirm|Revert/Action    one POST each
```

**All the rules live in `ReviewedConversationTurns`.** It is immutable — every operation returns a new
instance — so an invalid change cannot leave a half-applied conversation behind, and the caller decides
when to persist. Nothing reorders turns: the conversation stays in the sequence it was spoken.

---

### 8.11 The operations

| operation | rule | timestamps |
|---|---|---|
| **Move** | set the turn's role. Refused if it already has that role — a correction recording no change | untouched |
| **Move text** | whole turn, or a highlighted range: one or two `splitAt` calls then `moveTo`, then a merge if the result lands beside a matching turn. One transaction, one `MOVE` revision | split halves inherit the parent span, marked `approx` |
| **Split** | cut at a character offset. Refused at offset 0 / length, or if either half trims empty | **both halves inherit the parent's span**, both `approx` |
| **Merge** | join with the neighbour above or below. **Adjacency is the only rule** — role and voice are deliberately not consulted | `min(start)`, `max(end)` |
| **Merge (range)** | move only the highlighted words; the source keeps the rest. Selecting everything falls through to the whole-turn merge | both turns keep their spans and are marked `approx` |
| **Edit text** | replace the wording. Refused if empty or unchanged | untouched; turn marked `edited` |
| **Confirm roles** | write `roles_confirmed_at` and derive the two role columns. Refused if already confirmed, or if either role has no text | none |
| **Revert** | clear the layer *and* the confirmation | n/a |

Three rules deserve their reasoning:

* **Correcting is not confirming.** Fixing a boundary says nothing about who was speaking, so a
  `NEEDS_REVIEW` call keeps Speaker 1 / Speaker 2 through any number of structural corrections. Only
  `CONFIRM_ROLES` publishes, and `roles_confirmed_at` is the state — never inferred from
  `reviewed_segments` existing.
* **Confirmation needs two sides.** `textFor()` returns `''`, not NULL, for a role with no turns, and
  an empty string reads as "text is present" to the publish gate. `hasBothRoles()` is checked in the
  service, so no caller can publish a one-sided split.
* **Timestamps are never invented.** Token timings are not persisted — whisper's `-ojf` output lives in
  the worker's scratch directory and is deleted — so there is no defensible time for a boundary *inside*
  a turn. Split halves keep the parent's full range and say so with `approx`; a range move leaves both
  turns' spans alone and marks them approximate. Interpolating by character position would produce a
  number that looks measured and is not, because speech rate is not uniform.

#### Merge: the rule that was deliberately relaxed

Merging originally required the same role **and** the same diarization voice. That was correct for an
automatic decision and wrong for a manual one: on a normally alternating conversation no two adjacent
turns ever share a role, so merge was never available, and the advice to "move one of them first" led
to a second refusal on the voice check. `manualMergeAvailability()` now returns `NoNeighbour` at the
edges and `None` everywhere else — **adjacency is the whole rule**. The administrator is correcting the
transcript, and their decision is authoritative.

#### Publishing: two independent routes

`ConversationView::from()` decides whether Agent/Customer may be printed as fact:

```php
$published = $aggregateTextPresent
    && ($status?->isPublishable() === true || $rolesConfirmedByHuman);
```

`speaker_separation_status` records what the *machine* concluded and is never rewritten, so without the
second term a confirmation would write correct columns that no page could read. The two routes are not
interchangeable to a reader, and the page says which one applied — a machine result and a person's
assertion are different kinds of fact.

`confirmRoles()` also writes `reviewed_segments`, even when byte-identical to the machine's. Without a
reviewed layer `isReviewed()` stays false, the reader falls back to the raw columns, and the two role
columns it just wrote are never read.

---

### 8.12 The screens

**`/conversation`** — the conversation and nothing else: Customer left, Agent right, speaker label,
timestamp range, response delay, `edited` and `~approximate` markers. No transcript card, no raw text
blocks, no metadata, no downloads. Fixed-height: the header stays put and the messages scroll in their
own container, opening at the newest turn with a *Jump to latest* pill and only the last 20 turns
rendered until *Show earlier messages* is used.

**`/review`** — the same chat layout with per-message controls:

* a **six-dot drag handle** and a **pencil**, grouped on the message's own side, always visible
* **drag** a message across to the opposite lane to reassign it, or onto an adjacent message to merge —
  invalid drops say why rather than doing nothing
* **highlight text** inside a message to reveal *With previous* / *With next*; a partial selection
  moves only those words and the source keeps the rest, a full selection merges the whole turn
* the **pencil** opens an inline editor for the wording
* page-level **Confirm speaker roles** and **Discard all corrections**

Every mutation is a real `<form>` POST with CSRF, confirmed in a `<dialog>` before it fires, and
followed by Post/Redirect/Get. Nothing goes through a JSON side channel, so CSRF, the version check
and the redirect are identical to every other control in the application.

**Progressive enhancement.** The plain per-turn forms live inside `<noscript>`, so a scripting browser
never builds them and the enhanced layout is what paints first; without JavaScript they *are* the
interface. The CSP (`script-src 'self'; style-src 'self'`) forbids inline scripts and styles, so all
behaviour is in `assets/main/admin.js` behind `data-a2t-*` attributes and all styling is under the
`.a2t-` prefix in `assets/main/admin.css`. `ModuleIsolationTest` enforces both.

#### The `>>` markers

Whisper emits `>>` where it hears a speaker change. `SpeakerMarkers::strip()` removes them **for
display and for the correction layer only** — the machine's `transcript` and `speaker_segments` keep
them. Because the reader measures a text selection against the cleaned text, a range move is computed
against the cleaned text too, and both turns it touches are stored cleaned. Storing one convention in
one turn and another in its neighbour would be worse than either.

---

### 8.13 Validation, concurrency and audit

Every accepted operation runs through `ReviewConversationService::apply()`:

```
load the job by public_id          → ReviewRejected if unknown or not COMPLETED
load the current turns             → reviewed layer if present, else the machine's
apply the change (pure, in memory) → ReviewRejected if the rule refuses
BEGIN
    revisions->add(jobId, priorSegmentsJson, operation, adminId)
    applied = jobs->saveReview(... expectedReviewCount)   -- conditional UPDATE
    if (!applied) throw ReviewConflict                    -- rolls the revision back with it
COMMIT
```

* **Validation happens before the transaction opens**, so a refused correction costs nothing and
  leaves no trace.
* **The revision is written first**, so a lost race unwinds both and never leaves an orphan audit row.
* **The optimistic lock is a conditional statement**, not a read-then-write:
  `UPDATE … SET review_count = review_count + 1 WHERE id = ? AND review_count = ?`. Check and write are
  one atomic operation. Every page renders `expected_review_count` into every form.
* **`revert` advances the version too** — the count is of corrections made, not layers present, so a
  stale tab cannot become current again because somebody else reverted.

**What a client may assert, and what it may not.** A turn has no id; its only handle is its index in
the current conversation. So the client sends an index and a *direction*, never a target — the server
derives the neighbour itself, and adjacency is structural rather than something a crafted request can
claim. For a range move the client sends codepoint offsets plus the selected text as a checksum; the
server slices by offset and refuses if the slice disagrees, so a page rendered before somebody else's
edit cannot move the wrong words. Offsets are converted to codepoints in the browser because
JavaScript counts UTF-16 units and `mb_substr` counts codepoints — they diverge from the first emoji.

---

### Installing the diarization toolchain — system-level, run these yourself

Everything below was verified against the live upstream sources on 2026-08-26. The URLs are real, the
sizes and SHA-256 checksums were computed from the actual downloaded files, and the version is pinned.

**Note the misspelling in the embedding-model URL.** The upstream release tag really is
`speaker-recongition-models`; "correcting" it produces a 404.

```bash
sudo mkdir -p /opt/audio-diarization/models
sudo python3 -m venv /opt/audio-diarization/venv
sudo /opt/audio-diarization/venv/bin/pip install --upgrade pip

# Pinned, not "latest": an unpinned install makes this machine unreproducible.
sudo /opt/audio-diarization/venv/bin/pip install 'sherpa-onnx==1.13.6'

cd /tmp

# Segmentation model (pyannote 3.0, MIT) — 6,958,444 bytes
curl -LO https://github.com/k2-fsa/sherpa-onnx/releases/download/speaker-segmentation-models/sherpa-onnx-pyannote-segmentation-3-0.tar.bz2

# Speaker embedding model (3D-Speaker CAM++, bilingual zh+en, Apache-2.0) — 28,281,164 bytes
curl -LO https://github.com/k2-fsa/sherpa-onnx/releases/download/speaker-recongition-models/3dspeaker_speech_campplus_sv_zh_en_16k-common_advanced.onnx

# Verify BEFORE installing. If either line does not say "OK", stop.
echo '24615ee884c897d9d2ba09bb4d30da6bb1b15e685065962db5b02e76e4996488  sherpa-onnx-pyannote-segmentation-3-0.tar.bz2' | sha256sum -c
echo 'aa3cfc16963a10586a9393f5035d6d6b57e98d358b347f80c2a30bf4f00ceba2  3dspeaker_speech_campplus_sv_zh_en_16k-common_advanced.onnx' | sha256sum -c

tar -xjf sherpa-onnx-pyannote-segmentation-3-0.tar.bz2
sudo cp sherpa-onnx-pyannote-segmentation-3-0/model.onnx /opt/audio-diarization/models/segmentation.onnx
sudo cp 3dspeaker_speech_campplus_sv_zh_en_16k-common_advanced.onnx /opt/audio-diarization/models/embedding.onnx

# World-readable so www-data can load them.
sudo chmod -R a+rX /opt/audio-diarization
```

**Why sudo:** `/opt` is root-owned, and the models must be readable by the worker's user.

**Why this embedding model.** CAM++ trained on Chinese *and* English, at 28 MB. Speaker embeddings
model voice rather than words, so a bilingual model handles the code-switched English/Spanish in these
recordings without the 220 MB of the largest ERes2Net variant.

Total footprint: roughly 20 MB of Python packages plus 35 MB of models.

### Then benchmark, before enabling

Diarization RSS has **not** been measured on this hardware, so the memory limits derived from Whisper
alone are provisional. Measure first:

```bash
# 1. Produce the same 16 kHz mono WAV the worker would.
ffmpeg -nostdin -hide_banner -loglevel error -y -threads 1 \
  -i /path/to/21896109.wav -ar 16000 -ac 1 -c:a pcm_s16le /tmp/a2t-bench.wav

# 2. Diarization alone.
/usr/bin/time -v /opt/audio-diarization/venv/bin/python3 \
  /var/www/html/knowledge-forge/src/AudioToText/Infrastructure/Diarization/diarize.py \
  --audio /tmp/a2t-bench.wav \
  --segmentation-model /opt/audio-diarization/models/segmentation.onnx \
  --embedding-model /opt/audio-diarization/models/embedding.onnx \
  --max-speakers 2 --num-threads 1
```

Check three things in that output:

* **"Percent of CPU this job got" is ≈100%, not ≈800%.** Above ~150% means the thread pinning is not
  taking effect and diarization must not be enabled until it is.
* **"Maximum resident set size"** — this is the number the limits are derived from.
* **"Elapsed (wall clock) time"** — added to the ~90 s transcription, this is the new job duration, and
  it must stay comfortably inside `AUDIO_DIARIZATION_TIMEOUT`.

Then recalculate all three memory numbers together, from
`P = max(whisperRSS, diarizationRSS) + ~60 MB` for the PHP worker:

| Setting | Formula | Provisional value (Whisper's 834 MB only) |
|---|---|---|
| `AUDIO_WORKER_MIN_AVAILABLE_MB` | `1.8 P` | 1500 |
| systemd `MemoryHigh` | `1.45 P` | 1200M |
| systemd `MemoryMax` | `1.9 P` | 1600M |

If diarization peaks below Whisper's 834 MB, the current values already hold and nothing changes. If
it peaks higher, all three rise together.

### Finally, enable it

```bash
# In .env — the single place this is switched on.
AUDIO_DIARIZATION_ENABLED=true

# Confirm the worker accepts the configuration before queueing anything.
sudo -u www-data php /var/www/html/knowledge-forge/yii kf:audio:worker --once
```

The worker validates binaries, models and thresholds at startup and refuses to run with a message
naming the variable to fix, so a mistake here surfaces immediately rather than as a failed job.

### One CPU thread, at every stage

Verified against the sherpa-onnx source rather than assumed: `num_threads` defaults to 1 on both the
segmentation and embedding configs, and both feed `SetIntraOpNumThreads()` **and**
`SetInterOpNumThreads()`. Defaults are not a contract, so `diarize.py` sets them explicitly, ffmpeg
gets `-threads 1`, whisper gets `-t 1`, and `ProcessRunner` hands every child a minimal environment
pinning `OMP_NUM_THREADS`, `OPENBLAS_NUM_THREADS` and `MKL_NUM_THREADS` to 1. That environment is
also a hardening win: nothing inherited from PHP-FPM reaches ffmpeg, whisper or Python.

---

## 9. Store-wise audio — two modes, one conversation

Every conversion belongs to an Order58 store, and a conversion may have been recorded as one mixed
file or as two files with the roles already known. This section is the whole of that: the model, the
schema, the routes, the flow and the boundaries it had to respect.

### 9.1 The model — one conversation, one or two recordings

```
audio_conversations                  ← the business view: one row per upload
  └── audio_transcription_jobs       ← the technical view: one row per recording
```

**COMMON** → exactly one child, `source_role = COMMON`.
**SEPARATE** → exactly two children, one `CUSTOMER` and one `AGENT`.

Both views are needed and they legitimately disagree about how many things there are. A separate
upload is **two jobs** in the queue — two files, two Whisper runs, two queue slots — and **one
conversion** to an administrator. The store's history, its counts and its pagination all read
conversations; the global `/audio-to-text/jobs` list stays job-oriented, because seeing both children
individually is the point of the technical view.

The invariant is asserted by `AudioConversation::hasValidShape()` and enforced structurally: the
enqueue writes the parent and every child in one transaction, so it cannot drift.

### 9.2 `mode` *is* the provenance flag

There is no `role_source` column, and there is deliberately no `confidence = 1.0` on a separate child.
`ConversationMode::Separate` means the administrator told us who is on each recording, so:

* the worker **skips diarization entirely** — no diarizer process, no alignment, no role mapping;
* `speaker_separation_status`, `speaker_separation_method`, `speaker_role_confidence` and
  `speaker_separation_completed_at` all stay **NULL**;
* the transcript is written whole into that role's column (`agent_text` *or* `customer_text`), and
  `speaker_segments` stays NULL — a single-speaker recording has no exchange to segment.

That is the same distinction §8.6 draws between a measurement and an assumption, applied one level up.
Writing a confidence for a fact we were *told* would dress a given up as something we worked out,
which is precisely what `speaker_separation_status` exists to prevent.

### 9.3 Database schema

```sql
CREATE TABLE `audio_conversations` (
  `id`                   bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id`            char(32) NOT NULL,          -- 32 random hex; the internal id never leaves the server
  `store_source_id`      bigint unsigned DEFAULT NULL,  -- NULL = a legacy, pre-store upload
  `mode`                 varchar(16) NOT NULL,       -- COMMON | SEPARATE
  `uploaded_by_admin_id` bigint NOT NULL,
  `created_at`           datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_audio_conversations_public_id` (`public_id`),
  KEY `ix_audio_conversations_store` (`store_source_id`, `id`),
  CONSTRAINT `fk_audio_conversations_admin`
      FOREIGN KEY (`uploaded_by_admin_id`) REFERENCES `admin_users` (`id`)
      ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_audio_conversations_mode` CHECK (`mode` IN ('COMMON','SEPARATE'))
) ENGINE=InnoDB;

ALTER TABLE `audio_transcription_jobs`
  ADD COLUMN `conversation_id` bigint unsigned NULL AFTER `id`,
  ADD COLUMN `source_role` varchar(16) NULL AFTER `conversation_id`,
  ADD CONSTRAINT `fk_audio_transcription_jobs_conversation`
      FOREIGN KEY (`conversation_id`) REFERENCES `audio_conversations` (`id`)
      ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `chk_audio_transcription_jobs_source_role`
      CHECK (`source_role` IS NULL OR `source_role` IN ('COMMON','CUSTOMER','AGENT'));
```

Applied by `M260902100000CreateAudioConversations`. Raw SQL because of the `CHECK` constraints, which
is the house style already used by `M260831140000AddReviewedConversation`.

**`RESTRICT` on the conversation**, matching the uploader key in the same table: a job row is an audit
record and must not vanish because its parent was removed.

**There is no foreign key to `order58_stores`, on purpose.** `information_schema` shows *zero*
referential constraints pointing at that table, and five others already reference a store softly by
its mirrored id — `knowledge_bases.source_store_id`, `order58_knowledge_records.store_source_id`,
`order58_rule_records.source_store_id`, `order58_store_aliases.store_source_id`,
`rule_store_links.store_source_id`. The reason is recorded in `M260728120000CreateOrder58Mirrors`: a
record may arrive before its store has been synced. Adding the first-ever hard key here would break
that property for everything else. Store deletion is not a risk regardless — the sync never deletes,
only soft-deactivates (`deactivateNotSeen()`).

Note the type: `order58_stores.source_id` is `bigint unsigned` and `order58_stores.id` is signed. The
soft reference is to `source_id`, and a signed column here would have failed at key creation with
MySQL error 3780 had a key been wanted.

#### The back-fill

The migration adds both columns nullable, so **no existing row is rewritten by the schema change**.
It then back-fills every pre-existing job into its own `COMMON` conversation — a fresh `public_id`,
`store_source_id = NULL`, and `uploaded_by_admin_id` / `created_at` copied from the job — in batches
of 200, and asserts that no job is left with a NULL `conversation_id` before finishing.

Machine and reviewed columns are never read or written by that step. Verified byte-for-byte across the
23 pre-existing conversions:

```
jobs=23  transcript=18747  segments=72802  agent=8098  customer=2812  reviewed=10937   (unchanged)
```

`store_source_id` stays NULL for those rows. There is no store to infer, and inventing one would be
worse than recording that it is unknown.

`down()` follows the house rule — **refuse rather than destroy**: it counts conversations with a
`store_source_id` and throws with the count if any exist, since dropping would lose the store
association. Otherwise it drops the key, then the columns, then the table.

### 9.4 Aggregate status

One pure function, `ConversationStatus::fromChildren(list<JobStatus>)`, unit-tested across every
combination and never re-derived in a template:

| children | conversation |
|---|---|
| all QUEUED | `QUEUED` |
| any PROCESSING, or some terminal and some not | `PROCESSING` |
| all terminal, none failed | `COMPLETED` |
| all terminal, none completed | `FAILED` |
| all terminal, mixed | `PARTIALLY_COMPLETED` |

`PARTIALLY_COMPLETED` is the state the enum exists for: a failed Agent recording must not make a
perfectly good Customer transcript look lost. There is no automatic retry — the successful child keeps
its result and the failed one keeps its error.

**Nothing about ordering is assumed.** The two children of a pair are ordinary FIFO rows and the
worker may process an unrelated job between them. The invariant being relied on is *one heavy job at a
time*, never pair adjacency; `fromChildren()` reads whatever states exist at the moment it is asked.

### 9.5 Routes

| Method | Path | Name | Owner |
|---|---|---|---|
| GET | `/admin/order58/store-audio` | `order58.store-audio` | **Order58** — the picker |
| GET · POST | `/audio-to-text/store/{sourceId:\d+}` | `audio-to-text.store` | Audio-to-Text — upload + that store's history |
| GET | `/audio-to-text/conversion/{publicId:[0-9a-f]{32}}` | `audio-to-text.conversion` | Audio-to-Text — one logical conversion (COMMON redirects to `/review`) |
| GET | `/audio-to-text` | `audio-to-text` | **redirect** → `order58.store-audio` |

Everything else — `/audio-to-text/jobs`, `/job/{publicId}`, `/status`, `/download`, `/conversation`,
`/review` and all seven correction POSTs — is untouched, so every bookmarked result URL survives.

`GET /audio-to-text` is a redirect rather than a 404 so the sidebar entry, bookmarks and every
"Convert a file" link keep working. **`POST /audio-to-text` is gone**: an upload endpoint that cannot
name a store would have to either invent one or write a conversation that no history shows.

**The store comes from the route and nowhere else.** A posted `store_id` is never read — the URL
already says which store this is, and consulting the body for it would let anyone who can reach one
store's page write onto another store's history. Pinned by
`AudioToTextStoreCest::aPostedStoreIdIsIgnored`.

### 9.6 Module isolation — why the picker lives in Order58

Two rules in `ModuleIsolationTest` constrain this, and both match a namespace *literally*:

* Audio-to-Text may not contain the string `App\Order58`;
* **no file outside `src/AudioToText/` may contain the string `App\AudioToText`.**

So neither module may name the other, in code or in a comment. (That is not hypothetical — the first
version of the picker template failed the build on a docblock that merely mentioned the other
namespace while explaining this very rule.)

Store chat solved the same problem years of commits ago:
`src/Order58/Web/StoreChat/template.php` builds its card link with
`$urlGenerator->generate('chat.index', …)` — a **route-name string**, which the router resolves at
render time. The audio picker does exactly the same with `audio-to-text.store`. Neither module names
the other and neither isolation rule had to be relaxed.

Consequences of that boundary:

* The picker is an **Order58** page and reuses `StoreDirectoryReaderInterface` verbatim — the search
  SQL, letter buckets, filters, counts and pagination are not reimplemented, only asked for. It reuses
  the existing `.dir-toolbar`, `.filter-bar`, `.alpha-nav`, `.store-card` and `pager` markup, so it
  adds **no CSS**.
* Audio-to-Text needs only the store's *name* for a page heading, so it carries a narrow port of its
  own — `AudioStoreLookupInterface` / `DbAudioStoreLookup`, one query, following the existing
  `AgentStoreDirectoryInterface` precedent. Only the lookup is duplicated.
* That lookup reads the name from `knowledge_bases.name`, **not** `order58_stores.name`, because that
  is the column the picker sorts, buckets and displays. Reading the other one would show a different
  name on the page you arrived at than on the card you clicked.

Unlike Store chat, audio has **no eligibility gate**: a store with no knowledge base can still have a
recording transcribed, so every card is a live link.

### 9.7 The upload flow

**Common** — the existing pipeline, one new association:

```
POST /audio-to-text/store/{sourceId}   mode=COMMON, audio=<file>
  AudioUploadValidator  →  store file  →  ffprobe duration
  → TX: insert audio_conversations(mode=COMMON) + 1 job(source_role=COMMON)
  → redirect to the conversion, which redirects on to the job page
worker: ffmpeg → whisper → diarize → align → role-map → save     (unchanged)
```

**Separate** — one submission, two children, no diarization:

```
POST /audio-to-text/store/{sourceId}   mode=SEPARATE, customer_audio=…, agent_audio=…
  SeparateUploadValidator: BOTH files, field-specific errors   ← nothing stored until both pass
  store both files, ffprobe both                                ← outside the lock
  → TX: insert conversation(mode=SEPARATE) + job(CUSTOMER) + job(AGENT)
  → on any failure: every file written so far is removed, no partial batch
worker: claims ONE child at a time, FIFO, as always
        ffmpeg → whisper → save.  separate() is never called.
```

The skip is a single early `return` at the one existing diarization call site, guarded on
`$job->sourceRole?->isProvided()`, and completion branches to
`markCompletedWithProvidedRole()`.

#### The queue cap reserves both slots or neither

`assertQueueHasRoom()` gained a count. Inside the **existing** `enqueueExclusively()` named lock:

```
enqueueExclusively(function () {
    assertQueueHasRoom(2);      // room for the pair, or QueueFull before anything is written
    TX: insert conversation + CUSTOMER child + AGENT child
});
```

`countActive() + $needed > $maxQueue`, not `>=`. Accepting the Customer and then rejecting the Agent
is therefore impossible: the cap is evaluated once, for the pair, before either row exists.
`maxQueue = 0` (the default) still skips the lock entirely.

#### Both forms are plain HTML

The mode selector is **two separate forms**, each carrying its own hidden `mode`, rather than one form
with a JavaScript toggle. The content security policy here is `script-src 'self'` with no inline
JavaScript (§10, *Styles and scripts are not inline*), and a toggle that hides half a form is the
kind of thing that quietly submits the wrong fields when the script does not run. What the
administrator sees is exactly what is posted.

### 9.8 Server load — two different quantities

**Peak concurrency is unchanged.** The worker still claims one job, holds `worker.lock`, and runs
exactly one heavy process at a time. Every existing ceiling — the admission guard, the timeouts, the
systemd `MemoryMax` — is preserved untouched, and pairing introduces no new peak.

**Total work per conversation is the sum of its children.** A separate conversation is two
transcriptions: roughly the sum of both recordings' CPU time, two queue slots, and a completion time
of `t(customer) + t(agent)` **plus queue wait**, where each `t` is that recording's actual runtime —
not a figure derived from its duration. This is a throughput cost, not a concurrency risk, and it is
why the cap reserves two slots.

### 9.9 The conversion page

`/audio-to-text/conversion/{publicId}` takes the *conversation's* public id and branches on mode:

* **COMMON** → redirects to `/audio-to-text/job/{childPublicId}/review`, the same destination the
  global conversions list's View action uses, so **View means one thing wherever it is pressed**. One
  child, and the detail, `/conversation` and `/review` screens already do the right thing for it;
  nothing is reimplemented and nothing about those screens changes. `/review` is safe for every row
  because it never dead-ends: a job with nothing to correct — still queued, failed, or never
  speaker-separated — redirects itself on to the detail page (§8.9).
* **SEPARATE** → its own read-only view: the store, the uploader, the aggregate status, and **two
  known-role blocks** — Customer and Agent — each with its own status, filename, duration, transcript
  and, on failure, its own error message, so a failed Agent child is visible beside a completed
  Customer one. Each block links to its child's own job page for the technical detail.

**A separate conversation is never offered `/conversation` or `/review`.** Both are built on
`speaker_segments` turns, which a separate upload does not have: two files recorded independently
carry no shared clock, so interleaving them into one thread would mean inventing an ordering nobody
measured. Role confirmation is skipped for the same reason the diarizer is — the roles were given, not
inferred. The page says so in a sentence rather than leaving a reader to infer it from two missing
buttons.

### 9.10 Retention and cleanup

The worker's housekeeping pass deletes expired jobs one at a time. Immediately after that loop it
calls `AudioConversationRepositoryInterface::deleteChildless()`:

```sql
DELETE c FROM audio_conversations c
WHERE NOT EXISTS (SELECT 1 FROM audio_transcription_jobs j WHERE j.conversation_id = c.id)
```

One statement, one place. Both children of a pair get their `expires_at` from the same window at
enqueue so they expire together in practice, but the sweep is written for the general case where they
do not — a pair whose two children fall in different passes still leaves no orphan. With the default
indefinite retention nothing expires and the sweep is a no-op.

### 9.11 Files

**New — Order58 (the picker):** `src/Order58/Web/StoreAudio/{Action,template}.php`.

**New — Audio-to-Text:**
`Domain/{AudioConversation,AudioConversationChild,AudioConversationRepositoryInterface,
ConversationMode,ConversationStatus,SourceRole,AudioStore,AudioStoreLookupInterface}.php`,
`Application/SeparateUploadValidator.php`,
`Infrastructure/{DbAudioConversationRepository,DbAudioStoreLookup}.php`,
`Web/Job/Store/{Action,template}.php`, `Web/Job/Conversion/{Action,template}.php`,
`src/Migration/M260902100000CreateAudioConversations.php`.

**Modified:** `TranscriptionQueue` (`enqueue()` → `enqueueConversation()`,
`assertQueueHasRoom(int $needed)`), `TranscriptionJobRepositoryInterface` +
`DbTranscriptionJobRepository` (`create()` gains two nullable parameters,
`markCompletedWithProvidedRole()` added), `AudioTranscriptionWorkerCommand` (the diarization branch
and the childless sweep), `Web/Action.php` (now the redirect), `config/common/routes.php`,
`config/common/di/audio-to-text.php`, and one CSS rule — `.a2t-badge--partially-completed`.

**Removed:** `src/AudioToText/Web/template.php`, the store-less upload form.

**No application configuration change.** Nothing new is operator-tunable, so nothing was added to
`Environment.php` or `params.php`; the per-file limit keeps its existing setting. The only operator
action is the request-size raise in §3.

### 9.12 Tests

| Suite | What it pins |
|---|---|
| `Unit/ConversationStatusTest` | every child-state combination, including partial failure, and that every state has a badge class that exists in the stylesheet |
| `Unit/AudioConversationTest` | the shape invariants, and that duration is summed rather than maximised |
| `Unit/SeparateUploadValidatorTest` | field-specific errors, both files reported at once, and that any pair the per-file rule accepts fits the combined ceiling |
| `Integration/AudioConversationTest` | against real MySQL and real ffprobe: paired enqueue, **a failure on the second child leaves no conversation, no job and no stored file**, a cap with room for one rejects a pair whole, `forStore`/`countForStore` count a pair as one, and the childless sweep spares a parent with a surviving child |
| `Web/AudioToTextStoreCest` | picker, store page, both modes end to end, no diarization for supplied roles, no half-created pair, store scoping, a posted `store_id` ignored, the conversion page's two blocks and absent `/review`, escaping, no path leaks |
| `Unit/ModuleIsolationTest` | unchanged and still passing: neither module names the other |
| `Unit/WebTierCannotRunWhisperTest` | now points at `Web/Job/Store/Action.php` — the file that actually enqueues |

**Before any DB-touching suite**, check the real queue. `TranscriptionJobRepositoryTest` calls
`claimNextQueued()`, which would take a genuine pending upload:

```bash
mysql -e 'SELECT COUNT(*) FROM audio_transcription_jobs WHERE status IN ("QUEUED","PROCESSING")' knowledge_forge_db
```

---

## 10. Files

### Created

```
src/AudioToText/
  Domain/
    JobStatus, ProcessingStage, SpeakerRole, SpeakerSeparationStatus, WorkerMode,
    WorkerProcessState, WorkerSchedulerState                     enums
    TranscriptionJob, TranscriptionJobListItem, QueueSummary,
    WorkerHeartbeat, WorkerStatusView                            row objects / read models
    AudioTranscriptionException                                  dual-message: user text + log detail
    TranscriptionJobRepositoryInterface,
    WorkerHeartbeatRepositoryInterface,
    SystemResourceProbeInterface                                 ports

  Domain/Speaker/
    TranscriptToken, SpeakerSegment, SpeakerUtterance            pipeline value objects
    SpeakerSeparatedTranscript                                   the stage's whole result
    AlignmentQuality                                             duration-weighted attribution metrics
    SeparationBalance          ── gate 1 ──                      were there two speakers at all?
    DialogueAct, RoleScoreWeights                                role-mapping semantics / coefficients
    ConversationView, ConversationTurn                           what a page may claim
    ConversationSide, TurnTiming, ResponseTiming                 layout and reply timing, never stored
    SpeakerMarkers                                               strips whisper's ">>" for display
    SpeakerDiarizerInterface                                     port

  ── the correction layer (§8.7–8.13) ──────────────────────────────────────────
  Domain/
    ReviewOperation                                              the six audited operations
    SegmentRevision, SegmentRevisionRepositoryInterface          the audit row and its port
    EffectiveConversation                                        turns + role text + which layer
    Exception/ReviewRejected                                     a refusal, worded for the admin
    Exception/ReviewConflict                                     somebody corrected it first

  Domain/Speaker/
    ReviewedTurn                                                 one corrected turn
    ReviewedConversationTurns                                    every correction rule, immutable
    MergeDirection, MergeRefusal                                 merge direction and its verdict
    SplitPoint                                                   split positions offered in the words

  Application/
    EffectiveConversationReader                                  the one place that picks a layer
    ReviewConversationService                                    load → apply → audit → save

  Infrastructure/
    DbSegmentRevisionRepository                                  the audit trail

  Web/Job/Conversation/                                          the read-only chat page
  Web/Job/Review/                                                the correction page
    Action, template                                             GET
    ReviewPageView, ReviewTurnView                               decisions made once
    ReviewRequest                                                shared POST handling
    Move|MoveText|Split|Merge|Text|Confirm|Revert/Action          one POST each
  Web/_partial/thread.php                                        the bubbles, shared by the
                                                                 detail and conversation pages

  Application/
    AudioToTextSettings + Settings/{Transcription,Worker,Diarization}Settings   the one settings type
    AudioUploadValidator, TranscriptionQueue, QueuedAudioStorage,
    TranscriptFilename, TranscriptPreview                        upload path
    WorkerAdmissionGuard, ForeignLockGuard, WorkerHealthService  worker admission and liveness

  Application/Speaker/
    SpeakerTranscriptAligner, AlignedTranscript                  tokens x intervals -> utterances
    DialogueActDetector                                          utterance -> dialogue acts
    SpeakerRoleMapper          ── gate 2 ──                      which cluster is the agent
    SpeakerSeparationService                                     orchestrates, never throws

  Infrastructure/
    Process/{ProcessRunner,ProcessResult}                        argv-only, no shell, timeout
    AudioTranscriber, AudioDurationProbe                         ffmpeg / whisper / ffprobe
    Db{TranscriptionJob,WorkerHeartbeat}Repository, ProcSystemResourceProbe
    Diarization/{SherpaOnnxSpeakerDiarizer,NullSpeakerDiarizer,diarize.py}

  Web/               upload page, job page, global list, status endpoint, download, page guard
  Console/           AudioTranscriptionWorkerCommand

src/Migration/M260826120000CreateAudioTranscriptionJobs.php
src/Migration/M260826120100AddSpeakerSeparationColumns.php
src/Migration/M260826130000RetainSuccessfulRecordings.php
src/Migration/M260826140000AllowMultipleQueuedJobsPerAdmin.php
config/common/di/audio-to-text.php
docs/server/systemd/knowledge-forge-audio-worker.{service,timer}
docs/server/cron/knowledge-forge-audio-transcription
tests/Support/AudioToTextSettingsFactory.php
tests/Unit/AudioToText/*  tests/Integration/AudioToText/*  tests/Web/AudioToTextCest.php
```

**Two independent gates decide whether a split is published**, and keeping them apart is load-bearing.
`SeparationBalance` asks whether the diarizer found two speakers; `SpeakerRoleMapper` asks which one is
the agent. A confident answer to the second says nothing about the first — see §8.

### Modified — all additive

| File | Change |
|---|---|
| `src/Environment.php` | 24 `SPEC` entries |
| `config/common/params.php` | three parameter blocks |
| `config/common/routes.php` | five routes inside the existing admin group |
| `config/console/commands.php` | `kf:audio:worker` |
| `src/Web/Shared/Layout/Admin/_sidebar.php` | one nav entry |
| `assets/main/admin.css` | `.a2t-*` styles |
| `assets/main/admin.js` | polling and list refresh |
| `.env.example` | documented settings |

The DI file is *new* rather than an edit because `config/configuration.php` globs
`common/di/*.php` — so adding the feature required no change to any config file that already existed.

### Styles and scripts are not inline — deliberately

The application's CSP is `script-src 'self'; style-src 'self'` with **no `unsafe-inline`**. An inline
`<script>` would silently not run and a `style=""` attribute would be dropped. Templates publish
intent through `data-a2t-*` attributes; behaviour lives in `assets/main/admin.js`. Both pages work
with JavaScript disabled — they just need a manual refresh, which the copy says.

---

## 11. Database

`audio_transcription_jobs` — one row per job. **No audio is stored in the database**;
`stored_audio_path` holds a bare filename and only until the worker deletes the recording.

Two details worth knowing:

* **`active_uploader_admin_id` no longer exists.** The original schema carried a `STORED` generated
  column equal to `uploaded_by_admin_id` while a job was QUEUED or PROCESSING and `NULL` otherwise,
  with a unique index on it — a race-proof way to enforce one active job per administrator. That
  restriction was removed in `M260826140000AllowMultipleQueuedJobsPerAdmin`, which drops the index and
  then the column, because it enforced "one at a time" in the wrong place: it stopped people *queueing*
  work, which is what a queue is for. Concurrency is the worker's business (§12), not the upload form's.
  The migration is forward-only — the applied earlier migrations were not rewritten.
* **The foreign key is `RESTRICT`, not `CASCADE`** — originally forced rather than preferred, because
  MySQL refuses a foreign key with `CASCADE` on the base column of a stored generated column (error
  1215). The generated column is gone, but `RESTRICT` stays: a job row is an audit record of who
  uploaded what, and deleting an administrator should not silently take their conversations with it.

**The store-wise layer adds two columns to this table** — `conversation_id` and `source_role` — and
one table, `audio_conversations`. Both are documented in full in **§9.3**, including the back-fill that
gave every pre-existing job a parent, why there is no foreign key to `order58_stores`, and why a
recording whose role was supplied leaves every separation column NULL.

**The correction layer adds seven columns to this table** — `reviewed_segments`,
`reviewed_agent_text`, `reviewed_customer_text`, `reviewed_at`, `reviewed_by_admin_id`,
`roles_confirmed_at` and `review_count` — and one table, `audio_segment_revisions`. They are
documented in full in **§8.8**, including why `reviewed_segments IS NULL` is the flag that decides
which layer a page reads, and why the audit row stores the state *before* each change. Nothing in that
layer ever writes a machine column.

`audio_segment_revisions` — one row per accepted correction, `job_id` → `audio_transcription_jobs`
with `ON DELETE CASCADE` (unlike the uploader's `RESTRICT`: a revision describes a conversation, so it
has nothing left to describe once the conversation is gone). Its `operation` `CHECK` mirrors the
`ReviewOperation` enum, so **adding a case there needs a migration**.

`audio_worker_heartbeat` — a single row (`CHECK (id = 1)`) carrying two independent facts: `beat_at`
/ `state` for process liveness, and `last_tick_at` / `mode` for whether anything is still invoking the
worker. A table rather than a runtime file so the web tier can read it without access to the worker's
private directory. It is purely informational — `worker.lock` remains the authority on concurrency,
and every heartbeat write is wrapped so it can never take the worker down.

```bash
./yii migrate:up
```

---

## 12. Queueing and ordering

### Uploading and processing are separate concerns

An administrator may queue as many recordings as they like, whatever else is in flight. The worker
processes them one at a time. There is no per-administrator limit — an earlier design had one, and it
enforced "one at a time" in the upload form, which stopped people queueing work rather than stopping
the machine being overloaded.

```
Admin uploads A, B, C   →  all three QUEUED immediately
Worker                  →  A processes → completes
                        →  B processes → completes
                        →  C processes → completes
```

### Enqueue ordering

```
upload present → upload error → empty → size → extension → real MIME sniff
  → write the recording to its directory (ffprobe needs bytes on disk)
  → ffprobe duration check               (outside any lock: a slow probe blocks nobody)
  → [ if a queue cap is configured ] ┌ GET_LOCK → count active → INSERT → RELEASE_LOCK ┐
  → [ otherwise ]                    └ INSERT ────────────────────────────────────────┘
```

With the cap disabled — the default — the named lock is skipped entirely. That is not only an
optimisation: taking a lock with a five-second timeout on every upload would mean a busy moment could
refuse an upload as "queue full" when no limit exists at all.

When a cap *is* configured, the lock name is `CONCAT(DATABASE(), ':audio-to-text:enqueue')`. The
prefix matters: **MySQL named locks are server-global, not per-schema**, so on a host running several
applications an unprefixed name would let one project's uploads block another's.

Everything after the file is written is wrapped so the job directory is deleted on any rejection.

### FIFO, and why nothing jumps the queue

`claimNextQueued()` scans `WHERE status = 'QUEUED' ORDER BY id ASC`, then claims a candidate with an
atomic `UPDATE … SET status='PROCESSING' WHERE id = ? AND status = 'QUEUED'`. `id` is monotonic, so a
recording uploaded later can never be taken before an earlier one. The queue position shown on a job
page is computed with the same ordering, so it is the position the worker will actually take it in.

### A finished job is terminal, permanently

Both halves of the claim filter on `status = 'QUEUED'` — the candidate scan and the conditional
`UPDATE`. A COMPLETED or FAILED row therefore cannot be selected, cannot be claimed, and is never
reprocessed, however many times the worker ticks or how much is queued alongside it.

Housekeeping cannot requeue anything either: it only ever moves jobs *out* of PROCESSING (into
COMPLETED or FAILED), never back into QUEUED. There is no automatic retry anywhere. If retrying a
recording is ever wanted, it must create a **new** job rather than reopening a terminal row —
otherwise the original result would be silently overwritten and its audit trail lost.

### One job at a time — enforced in the worker

| Mechanism | Stops |
|---|---|
| `flock` on `worker.lock` | a second worker process, however started |
| atomic conditional claim | two workers taking the same row |
| foreign lock (`AUDIO_WORKER_FOREIGN_LOCKS`) | another project starting Whisper concurrently |
| `AUDIO_TRANSCRIPTION_THREADS=1` | one job using more than one core |
| one sequential pipeline per job | ffmpeg, Whisper and diarization overlapping |

**Many QUEUED jobs: yes. Many PROCESSING jobs: no.**

## 13. What is kept, and what is cleaned up

### Successful conversations are kept — indefinitely, by default

For every job that completes, all of this is retained and none of it expires unless you configure a
window:

| Retained | Where |
|---|---|
| the original uploaded recording | `runtime/audio-to-text/recordings/<publicId>/source.<ext>` |
| complete transcript | `audio_transcription_jobs.transcript` |
| customer-only text | `.customer_text` |
| agent-only text | `.agent_text` |
| speaker-labelled conversation | `.speaker_segments` (JSON) |
| detected language, duration | `.detected_language`, `.duration_seconds` |
| uploader and audit metadata | `.uploaded_by_admin_id`, `.created_at/.started_at/.completed_at` |
| processing metadata | `.processing_stage`, `.speaker_separation_status/_method`, `.speaker_role_confidence` |

### Two separate trees, and the separation is the safety property

```
runtime/audio-to-text/
├── worker.lock                          the single-worker guarantee — outside both trees
├── jobs/<publicId>/                     TEMPORARY workspace. Swept. Deleted when the job ends.
│     source.<ext>  audio.wav  transcript.txt  transcript.json
└── recordings/<publicId>/               PERMANENT. Never swept.
      source.<ext>                       the retained original recording
```

On success the recording is **moved** — `rename()`, atomic within a filesystem — out of `jobs/` and
into `recordings/` *before* the row is marked complete. A move rather than a copy means the file
exists in exactly one place at any moment, so there is never a window where the sweeper could collect
something the database already considers retained. The temporary workspace is then deleted.

The orphan sweep only ever walks `jobs/`. Retained recordings are in a **sibling tree, not a
subdirectory**, so "the sweeper cannot reach them" is a fact about the layout rather than a rule
someone has to remember. Both trees are under `runtime/`, outside the web root, and no filesystem path
is ever shown in the UI or returned by any endpoint — the job page says only "Retained on this
server".

### Retention: one setting

```env
AUDIO_TRANSCRIPTION_RETENTION_SECONDS=0
```

* **`0` = keep indefinitely.** The default, and what this deployment uses. A job created under it gets
  `expires_at = NULL`, and the purge query filters `expires_at IS NOT NULL`, so nothing can match.
* **A positive value** = expire that many seconds after creation. `2592000` is 30 days.

Changing it later is that one line. No PHP class, no SQL, no migration: the worker reads it through
`AudioToTextSettings` like every other setting.

### What housekeeping may still delete

Even with retention disabled, the worker still cleans up scaffolding:

* **abandoned temporary workspaces** — `jobs/<publicId>/` directories older than the stale window whose
  job is no longer active;
* **the temporary workspace of every finished job**, success or failure;
* **stale `PROCESSING` jobs** — recovered after 600 s. If a transcript was already committed the job is
  *completed* with the speaker split marked failed; only a crash before transcription fails the job.

**It never deletes a retained recording or a completed conversation while retention is disabled.**
Retained recordings are removed in exactly one place: alongside their database row, when a configured
retention window has passed. That path is unreachable at `RETENTION_SECONDS=0`.

### Structured data for later use

`speaker_segments` holds the conversation as JSON, so future work can consume it directly without
parsing HTML or re-deriving anything:

```json
[
  {"start_ms": 0,    "end_ms": 1617, "speaker": "SPEAKER_00", "role": "AGENT",    "text": "Hello?",  "confidence": 1.0},
  {"start_ms": 2899, "end_ms": 3507, "speaker": "SPEAKER_01", "role": "CUSTOMER", "text": "Hello.",  "confidence": 1.0}
]
```

Both the neutral acoustic cluster and the mapped business role are stored, so a customer utterance can
be paired with the agent response that followed it, and any mapping can be audited after the fact.
Nothing consumes this yet, and it is deliberately coupled to nothing.

## 14. Security

| Concern | How |
|---|---|
| Authentication | every route inside the existing `RequireAdminMiddleware` group |
| Authorization | authenticated administrator + job exists; 404 (never 403) otherwise |
| Enumeration | 32-hex random `public_id` in URLs; the database id never appears |
| CSRF | the application-wide middleware; the upload form carries a token |
| File type | `finfo` over the real leading bytes — the browser's declared type is ignored entirely |
| Path safety | the client filename never becomes a path; the server names the file `source.<ext>` |
| Private storage | `runtime/audio-to-text/`, outside the web root; 0750 / 0700, never 0777 |
| Shell safety | `proc_open()` with an argv **array** — no shell, so quoting and `$(…)` are inert |
| Process limits | wall-clock timeout, SIGTERM → 2 s → SIGKILL, 256 KB cap per output stream |
| Output escaping | every rendered value through `Html::encode()`; raw JSON never shown |
| Error separation | user-safe wording in `error_message`; exit codes, stderr and paths to the log only |
| Downloads | body from the database, never from the request; filename rebuilt and folded to `[A-Za-z0-9._-]` |

---

## 15. Testing

```bash
composer test        # full Codeception suite — free port 8080 first
composer psalm       # errorLevel 1
composer cs-check
composer quality

# Just this feature, which is what you usually want:
vendor/bin/codecept run Unit tests/Unit/AudioToText/
vendor/bin/codecept run Integration tests/Integration/AudioToText/
vendor/bin/codecept run Web 'tests/Web/AudioToText*Cest.php'
```

| Suite | Covers |
|---|---|
| `Unit/AudioToText/` | upload validation, filename and preview safety, settings and duration limits, worker health and admission, foreign locks, alignment, dialogue acts, role mapping, separation balance, conversation display, conversation status and shape, separate-upload validation, module isolation |
| `Integration/AudioToText/` | against real MySQL — atomic claim, FIFO, stale recovery, queue counts, heartbeat, the reviewed layer, and (with real ffprobe) the paired enqueue, its rollback, the pair-aware queue cap and the childless sweep |
| `Web/AudioToTextCest.php` | the served application — auth, validation, the global list, the job page, downloads, escaping |
| `Web/AudioToTextStoreCest.php` | store-wise audio — the picker, a store's page, both upload modes, store scoping, a posted `store_id` ignored, and the conversion page |
| `Web/AudioToTextConversationCest.php` · `AudioToTextReviewCest.php` | the conversation-only page and the correction screen |

No automated test requires ffmpeg, whisper or a diarization model. The web suite uploads a real WAV
but no worker runs, so jobs stop at `QUEUED` — which is the assertion.

> **Do not run the integration or full suite while real uploads are pending.**
> `TranscriptionJobRepositoryTest` exercises `claimNextQueued()` against the **live** database — one
> case drains the queue in a loop — so it will claim a real administrator's queued jobs and leave them
> `PROCESSING` when the test ends. The stale sweep then fails them after
> `AUDIO_TRANSCRIPTION_STALE_AFTER`, and the recording has to be uploaded again. Check first:
>
> ```bash
> mysql -e "SELECT id, original_filename, status FROM audio_transcription_jobs
>           WHERE status IN ('QUEUED','PROCESSING')" knowledge_forge
> ```
>
> If a run does claim one, put it back before the stale window expires — nothing is lost, because no
> transcript was written yet:
>
> ```sql
> UPDATE audio_transcription_jobs SET status='QUEUED', processing_stage='QUEUED', started_at=NULL
> WHERE id IN (…) AND status='PROCESSING';
> ```
>
> The real fix is to scope those tests to their own administrators the way `AudioToTextCest` already
> does. Until then this is a known sharp edge, not a surprise.

---

## 16. Troubleshooting

| Symptom | Cause |
|---|---|
| "Audio worker: Not running", jobs stay QUEUED | no worker; start it, or check `systemctl status knowledge-forge-audio-worker.timer` |
| "Audio worker: Scheduled — last ran N ago" | normal for a timer between ticks |
| "Deferring — a coordination lock is unavailable" | `AUDIO_WORKER_FOREIGN_LOCKS` points at a file this user cannot read; run as `www-data` or blank it (§5) |
| "Deferring new jobs while the server is busy" | low memory or high load; check `free -m` and `/proc/loadavg` |
| Every tick defers immediately | the worker is not running as `www-data` — see the startup warning it prints |
| "Speech recognition is not available on this server" | `WHISPER_BINARY` is not executable |
| "The speech recognition model has not been installed" | `WHISPER_MODEL` is not readable |
| "You already have a transcription in progress" | one active job per administrator, by design |
| A separate upload dies with a bare nginx 413 | `client_max_body_size` / `post_max_size` not raised to 64 — see §3 |
| A store's page shows "Nothing uploaded for this store yet" after an upload | the upload went to a different store; the store comes from the URL, so check which page it was made from |
| A conversion page offers no Correct speakers button | it is a separate Customer + Agent upload; the roles were supplied, so there is nothing to correct (§9.9) |
| Speaker split always "Not supported" | `AUDIO_DIARIZATION_ENABLED=false`, or the models are missing |
| Job fails immediately with no detail | check `runtime/logs/` — technical detail never reaches the browser |

---

## 17. Rollback

### Rolling back part of it

The migrations are a stack, applied in this order, so a partial rollback unwinds from the top:

| Applied | Migration | Adds |
|---|---|---|
| 1st | `M260826120000CreateAudioTranscriptionJobs` | the two tables |
| 2nd | `M260826120100AddSpeakerSeparationColumns` | agent/customer text, speaker segments |
| 3rd | `M260826130000RetainSuccessfulRecordings` | `retained_audio_path`, nullable `expires_at` |
| 4th | `M260826140000AllowMultipleQueuedJobsPerAdmin` | drops the one-active-job index and column |
| 5th | `M260831140000AddReviewedConversation` | the reviewed layer and `audio_segment_revisions` |
| 6th | `M260831160000AddRolesConfirmation` | `roles_confirmed_at` |
| 7th | `M260902100000CreateAudioConversations` | `audio_conversations`, `conversation_id`, `source_role` |

**The newest one refuses to unwind while any conversation has a store.** `down()` counts
conversations with a `store_source_id` and throws with the count, because dropping the table would
lose the store association silently. Clear or export those first if you genuinely mean to.

`migrate:down N` unwinds the **newest N**, so the recipes below are cumulative — you cannot reach a
middle migration without unwinding everything stacked on it.

**Store-wise audio only** — back to global, store-less uploads:

```bash
./yii migrate:down 1        # M260902100000 — refuses while any conversation has a store
```

Every job, transcript and reviewed correction survives — only the parent table and the two columns
pointing at it go. The web tier must go back with it, because `/audio-to-text` and its POST are now
the store page: restore `src/AudioToText/Web/{Action,template}.php` and the four routes from git, and
remove `src/Order58/Web/StoreAudio`.

**Retention behaviour as well** — back to a required expiry date on every job:

```bash
./yii migrate:down 4        # M260902100000 … back through M260826130000
```

Existing rows with no expiry are given a far-future date rather than "now", so reverting cannot
schedule a mass deletion of conversations you have been keeping deliberately. Retained recordings on
disk are left alone; remove `runtime/audio-to-text/recordings/` by hand if you want them gone.

**Speaker separation as well** — note this unwinds everything above it too:

```bash
./yii migrate:down 6        # M260902100000 … back through M260826120100
rm -rf src/AudioToText/Domain/Speaker src/AudioToText/Application/Speaker \
       src/AudioToText/Infrastructure/Diarization
rm -f tests/Unit/AudioToText/Speaker*Test.php
# Remove the AUDIO_DIARIZATION_* entries from src/Environment.php, config/common/params.php,
# config/common/di/audio-to-text.php and .env.example.
composer yii-config-rebuild && composer test
```

Transcripts, detected languages and every job survive untouched.

### The whole feature

```bash
# 1. Stop the SCHEDULE first, then any running process.
sudo systemctl disable --now knowledge-forge-audio-worker.timer
sudo rm -f /etc/systemd/system/knowledge-forge-audio-worker.{service,timer}
sudo systemctl daemon-reload
sudo rm -f /etc/cron.d/knowledge-forge-audio-transcription
pkill -f 'kf:audio:worker'

# 2. Confirm nothing is mid-run.
pgrep -a -f 'kf:audio:worker|whisper-cli'

# 3. Drop the tables. migrate:down refuses while jobs still exist. Export first if you want to keep
#    anything: transcripts, speaker splits and the retained recordings all go with them.
#    Seven migrations now — the whole stack in the table above.
./yii migrate:down 7

# 4. Remove the feature.
rm -rf src/AudioToText tests/Unit/AudioToText tests/Integration/AudioToText \
       tests/Web/AudioToText*Cest.php config/common/di/audio-to-text.php \
       docs/AUDIO_TO_TEXT.md docs/server/systemd docs/server/cron \
       runtime/audio-to-text
# The store picker is an Order58 page and goes with the feature it points at.
rm -rf src/Order58/Web/StoreAudio
rm -f src/Migration/M260826120000CreateAudioTranscriptionJobs.php \
      src/Migration/M260826120100AddSpeakerSeparationColumns.php \
      src/Migration/M260826130000RetainSuccessfulRecordings.php \
      src/Migration/M260826140000AllowMultipleQueuedJobsPerAdmin.php \
      src/Migration/M260831140000AddReviewedConversation.php \
      src/Migration/M260831160000AddRolesConfirmation.php \
      src/Migration/M260902100000CreateAudioConversations.php
rm -f tests/Support/AudioToTextSettingsFactory.php
rm -rf tests/Support/Fake/AudioToText

# 5. Revert the additive edits.
git checkout -- src/Environment.php config/common/params.php config/common/routes.php \
       config/console/commands.php src/Web/Shared/Layout/Admin/_sidebar.php \
       assets/main/admin.css assets/main/admin.js .env.example

# 6. Rebuild and confirm.
composer yii-config-rebuild && composer test && git status --short
```

**Do not uninstall ffmpeg, `/opt/whisper.cpp` or `/opt/audio-diarization`** as part of an application
rollback — telecom-billing on this machine uses the same toolchain.

---

## 18. Known limitations

* `flock` covers **one machine**. Several hosts sharing one database would need a database lease.
* Speaker separation is only as good as the diarizer. 8 kHz telephone audio upsampled to 16 kHz is
  harder for speaker embeddings than clean wideband audio, which is exactly why the `NEEDS_REVIEW`
  path exists rather than a forced two-way split.
* The role mapper's signals are tuned for restaurant/order calls. A different domain needs different
  signals; the neutral clusters and the alignment are domain-independent.
* Cross-project exclusivity is race-safe only against schedules that take a lock file (§5).
* **Diarization sometimes misses a speaker entirely** on difficult telephone audio — one cluster gets
  the whole call and the other a few words. Gate 2 (§8) detects that and refuses to publish, but it
  cannot repair it: the recording comes back as `NEEDS_REVIEW` with the full transcript intact and no
  split. Better separation would mean a different embedding model, not a threshold change.
* The integration suite operates on the live database and can claim real queued jobs — see §15.
* **A separate Customer + Agent conversion is never interleaved into one thread.** The two files carry
  no shared clock, so there is no ordering to recover — only one to invent. The conversion page shows
  them side by side instead, and the conversation and correction screens do not apply (§9.9). If a
  future recorder emits a common start timestamp for both legs, that becomes possible; nothing today
  does.
* **Legacy conversions have no store.** Every job that predates §9 was back-filled into its own
  `COMMON` conversation with `store_source_id = NULL`, because there is no store to infer and
  inventing one would be worse than recording that it is unknown. They still appear on
  `/audio-to-text/jobs`; they appear on no store's page.
