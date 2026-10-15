#!/usr/bin/env bash
# Topic Management and the Practice Orders topic controls, in a real Chrome.
#
#   KF_BASE=http://127.0.0.1:8080 ./tests/E2E/run-topics.sh
#
# Headless: nothing here needs a screen. The fixture store has no host, so no demo link is offered and
# no request can reach any real ordering system.
set -uo pipefail
cd "$(dirname "$0")"

export KF_BASE="${KF_BASE:-http://127.0.0.1:8080}"

php seed-topics.php > fixtures-topics.json || exit 1

# One seeding, two scripts: topics.js walks the behaviour, order-testing-topics.js measures the layout
# the behaviour is wrapped in. Both failures matter, so neither short-circuits the other.
status=0
node topics.js || status=1
node order-testing-topics.js || status=1
node verification.js || status=1

php cleanup-topics.php
rm -f fixtures-topics.json
exit $status
