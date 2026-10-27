#!/usr/bin/env bash
# Case Order call recordings, in a real browser.
#
# Pressing Play makes the server fetch the recording, so this runner starts its OWN server, with
# ORDER58_RECORDING_STREAM_SOURCE=fixture, on a port of its own, and stops it afterwards. Every
# recording is then answered from tests/_data/call-recordings and no request can reach the client's
# IP-allowlisted recording host, whatever else is running on this machine.
#   ./tests/E2E/run-case-recordings.sh
set -uo pipefail
cd "$(dirname "$0")"

PORT="${KF_CASE_RECORDINGS_PORT:-8091}"
export KF_BASE="http://127.0.0.1:${PORT}"
ROOT="$(cd ../.. && pwd)"

ORDER58_RECORDING_STREAM_SOURCE=fixture php -S "127.0.0.1:${PORT}" -t "$ROOT/public" "$ROOT/public/index.php" \
    > /dev/null 2>&1 &
server=$!
trap 'kill $server 2>/dev/null' EXIT

for _ in $(seq 1 50); do
    curl -s -o /dev/null "$KF_BASE/login" && break
    sleep 0.1
done

php seed-case-recordings.php > fixtures-case-recordings.json
mkdir -p _shots

node case-recordings.js
status=$?

php cleanup-case-recordings.php
rm -f fixtures-case-recordings.json
exit $status
