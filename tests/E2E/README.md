# Browser tests — speaker correction

These drive a real Chrome against both screens that correct a transcript: the full page at
`/audio-to-text/job/{publicId}/review` and the Details dialog on `/audio-to-text/store/{sourceId}`.

They are **not** part of `codecept run`. They need a browser, and the rest of the suite deliberately
does not.

## Why they exist

Every regression this feature has had was invisible to markup tests, because none of them was about
markup:

- a `display: flex` on a bare dialog class painted a *closed* `<dialog>` as a block under the page
- a `var` dropped while extracting a shared module broke drag-to-move with `drag is not defined`

Both suites were green throughout. Only a browser found either.

## Running

```
npm install puppeteer-core          # once, in this directory
KF_BASE=http://127.0.0.1:8080 ./run.sh
```

`run.sh` seeds its own store, conversation and administrator, runs a suite, and seeds again for the
next one — the corrections are real, so a suite consumes the conversation it edits. `cleanup.php`
removes everything by its own markers and is safe to run at any time.

Run `cleanup.php` before the PHP suites if you ran a single suite by hand: a queued rendition left
behind is visible to `TtsRenditionRepositoryTest`, which sweeps abandoned rows across the table.
`run.sh` already cleans up after itself.

Nothing outside those fixtures is read or written. The store id is far outside the mirrored range,
and the administrator is named `__kf_e2e_admin__`.

## What they cover

`flows.js` — the Details dialog: edit, history, selection, With previous, With next, move, and the
ends of a conversation.

`page.js` — the full correction page: edit, history, selection, merge, and a real pointer drag onto
the opposite lane's drop band.

`tts.js` — Text to Audio for a recording that holds one side of a call: the modal offers all three,
Caller generates, the rendition goes Ready, an edit makes it stale, and an ambiguous Mixed recording is
still blocked. **No paid provider is called** — the web tier only enqueues, and `complete-tts.php`
stands in for the worker's render with the same hash, render key and columns.

`listen.js` — the three audio options in the Details dialog: the original recording's player, the
generated-AI-audio row in whichever state the recording is actually in, and the browser's own voice.
**`window.speechSynthesis` is replaced with a recording stub** — nothing is spoken out loud and no
provider is involved — so the assertions can be about behaviour rather than sound: messages spoken in
the order they are drawn, the configured `SYSTEM_AUDIO_GAP_MS` gap between them, exactly one bubble highlighted at a time, a pause
during a gap that does not let the next line start, Stop clearing everything, and both closing the
dialog and opening another one silencing the previous recording.

Its stub models Chrome rather than convenience, and that is what makes the pause/resume section
meaningful: `speak()` on a paused engine queues silently and never plays, `cancel()` does not lift
a pause, and `pause()` mid-utterance holds it with `speaking` still true. A stub that simply spoke
whatever it was handed passed against the defect those three quirks caused.

`manage.js` — Manage Audio. Uploads a real replacement for one side of an order through the real
endpoint and asserts the property the feature exists for: while the replacement is still queued the
recording it replaces stays current and the store row still reports the order as complete, and only
once the replacement finishes does it take over — with the one it replaced kept, numbered and still
openable. `complete-replacement.php` stands in for the transcription worker's status write, so no
Whisper run is needed to reach the state under test.

`voices.js` — a recording whose type names its speaker. A Caller upload is diarized like any other, so
its `speaker_segments` really do contain two clusters; these assert that every screen still reads it as
one person, that the speaker controls are absent, that edit/history/merge still work, and that a Mixed
recording is untouched.
