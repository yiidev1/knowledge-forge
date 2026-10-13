#!/usr/bin/env bash
# Geometry of the two invoice panels on the Order Comparison page, in a real browser.
#
# Separate from run.sh: that one seeds and consumes audio conversations, and this reads one page
# without changing anything. Requires Google Chrome at /usr/bin/google-chrome and the app at $KF_BASE.
#   npm install puppeteer-core   (once, in this directory)
#   KF_BASE=http://127.0.0.1:8080 ./tests/E2E/run-compare.sh
set -euo pipefail
cd "$(dirname "$0")"

export KF_BASE="${KF_BASE:-http://127.0.0.1:8080}"

php seed-compare.php > fixtures-compare.json
node compare.js
status=$?
php cleanup-compare.php
exit $status
