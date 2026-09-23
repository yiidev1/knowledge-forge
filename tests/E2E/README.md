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

Nothing outside those fixtures is read or written. The store id is far outside the mirrored range,
and the administrator is named `__kf_e2e_admin__`.

## What they cover

`flows.js` — the Details dialog: edit, history, selection, With previous, With next, move, and the
ends of a conversation.

`page.js` — the full correction page: edit, history, selection, merge, and a real pointer drag onto
the opposite lane's drop band.

`voices.js` — a recording whose type names its speaker. A Caller upload is diarized like any other, so
its `speaker_segments` really do contain two clusters; these assert that every screen still reads it as
one person, that the speaker controls are absent, that edit/history/merge still work, and that a Mixed
recording is untouched.
