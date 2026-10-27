# Case Order recording fixtures

Answered by `RecordingStreamer` instead of the provider when `ORDER58_RECORDING_STREAM_SOURCE=fixture`
and `APP_ENV` is `dev` or `test` (production always streams live). No request leaves the machine.

- `{session id}.wav` — served for that call session.
- `{session id}.status` — an HTTP status the provider is pretended to have answered (`404`, `503`, …).
- `default.wav` — served for every other session: a synthetic 6-second tone (8 kHz, mono, 16-bit PCM),
  so any stored order plays something locally. It contains no customer audio.
