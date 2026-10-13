#!/usr/bin/env bash
# The floating recording player, in a real browser.
#
# Separate from run.sh: this one needs a HEADED Chrome (Document Picture-in-Picture has nowhere to put
# itself without a screen) and its own fixture. The fixture's store host is a subdomain of order58.com
# that does not resolve, so the second tab never reaches the client's live ordering system.
#   KF_BASE=http://127.0.0.1:8080 ./tests/E2E/run-practice.sh
set -uo pipefail
cd "$(dirname "$0")"

export KF_BASE="${KF_BASE:-http://127.0.0.1:8080}"

php seed-practice.php > fixtures-practice.json
before=$(php attempts-practice.php)

node practice.js
status=$?

after=$(php attempts-practice.php)
echo
echo "attempts for the fixture store: before=$before after=$after"

# One press is one attempt. The reload and the refused second press must add nothing.
# One free order pressed twice by one operator = one attempt. The held order already has its own,
# seeded for somebody else, and must not gain a second.
if [ "$after" -eq 2 ]; then
    echo "  PASS  exactly two attempts exist: the one this operator started, and the pre-held one"
else
    echo "  FAIL  expected 2 attempts, found $after"
    status=1
fi

php cleanup-practice.php
exit $status
