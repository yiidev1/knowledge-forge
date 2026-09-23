#!/usr/bin/env bash
# Browser tests for speaker correction, on both screens that offer it.
#
# Not part of `codecept run`: these need a real browser, and the rest of the suite deliberately does
# not. They exist because every earlier regression in this feature was invisible to markup tests —
# a stylesheet that painted a closed dialog, a `var` dropped during a refactor — and only a browser
# could have caught either.
#
# Requires: Google Chrome at /usr/bin/google-chrome, and the app served at $KF_BASE.
#   npm install puppeteer-core   (once, in this directory)
#   KF_BASE=http://127.0.0.1:8080 ./tests/E2E/run.sh
set -euo pipefail
cd "$(dirname "$0")"

export KF_BASE="${KF_BASE:-http://127.0.0.1:8080}"

for suite in flows page voices tts; do
    echo "=== $suite ==="
    # Reseeded per suite: these corrections are real, so a suite consumes the conversation it edits.
    php seed.php > fixtures.json
    node "$suite.js"
done

php cleanup.php
