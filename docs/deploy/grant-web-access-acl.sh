#!/usr/bin/env bash
#
# Grants the web/cron user (www-data) the access it needs USING POSIX ACLs — no sudo required, because
# the checkout owner can set ACLs on their own files. This is the no-root alternative to
# fix-permissions.sh (which changes group ownership and needs sudo).
#
# Run as the checkout owner (the user who cloned the repo):
#
#     bash docs/deploy/grant-web-access-acl.sh
#
# Safe to re-run. Idempotent. Requires the `acl` package (setfacl/getfacl) and a filesystem mounted
# with ACL support (ext4/xfs have it on by default).
set -euo pipefail

APP="$(cd "$(dirname "$0")/../.." && pwd)"
WEB_USER="www-data"
OWNER="$(id -un)"   # the checkout owner running this script

command -v setfacl >/dev/null 2>&1 || { echo "setfacl not found — install the 'acl' package."; exit 1; }

echo "App:       $APP"
echo "Web user:  $WEB_USER"

# .env holds the API key and DB password: grant the web user read via ACL, and make sure it is not
# world-readable. No group/other read.
if [ -f "$APP/.env" ]; then
    chmod o= "$APP/.env"
    setfacl -m "u:${WEB_USER}:r--" "$APP/.env"
    echo "Granted $WEB_USER read on .env (still not world-readable)."
fi

# Read-only trees FIRST. Code the app reads but must not write; no default ACL, so the web user never
# gains write on source. This must run BEFORE the writable grant below, because `public` contains
# `public/assets`: a recursive read-only grant on `public` would otherwise strip the write bit the
# asset publisher needs to create new hashed bundle directories.
#
# Grants only target entries owned by the checkout owner. Files the web user created at runtime (logs,
# published asset bundles) are owned by www-data, already accessible to it, and would make setfacl fail
# with "Operation not permitted" if we tried to modify them — so they are skipped.
setfacl -m "u:${WEB_USER}:r-x" "$APP"
for dir in src config vendor; do
    [ -d "$APP/$dir" ] && find "$APP/$dir" -user "$OWNER" -exec setfacl -m "u:${WEB_USER}:rX" {} +
done
# public: traverse the dir and read its top-level static files, but do NOT recurse into the
# runtime-generated public/assets tree — that is granted writable, last, below.
setfacl -m "u:${WEB_USER}:r-x" "$APP/public"
find "$APP/public" -maxdepth 1 -type f -user "$OWNER" -exec setfacl -m "u:${WEB_USER}:r--" {} +
echo "Granted $WEB_USER read/traverse on source directories."

# Writable trees LAST, so they win over the read-only grant on `public` above. Read/write/traverse plus
# a DEFAULT ACL so files/dirs the app creates later inherit the same access — this is what lets a file
# written by PHP-FPM be read by the cron worker, and lets the asset publisher create new bundle
# directories under public/assets. Owner-owned entries only (see note above).
for dir in runtime public/assets; do
    install -d "$APP/$dir"
    find "$APP/$dir" -user "$OWNER" -exec setfacl    -m "u:${WEB_USER}:rwX" {} +
    find "$APP/$dir" -user "$OWNER" -type d -exec setfacl -d -m "u:${WEB_USER}:rwX" {} +
    echo "Granted $WEB_USER rwX (with default ACL) on $dir."
done

echo "Done. Retry http://knowledge-forge.local"
