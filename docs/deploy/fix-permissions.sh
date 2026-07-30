#!/usr/bin/env bash
#
# Grants the web/cron user (www-data) the access it needs, while the deployment user (the checkout
# owner) keeps ownership for git, Composer and migrations.
#
# Why this is needed: PHP-FPM and the cron worker run as www-data, but the files are owned by the
# deployment user. Without this, www-data cannot read .env (config falls back to defaults) nor write
# runtime/ and public/assets (asset publishing and caches fail -> HTTP 500).
#
# Run once after checkout, and again only if new writable directories appear:
#
#     sudo bash docs/deploy/fix-permissions.sh
#
# Safe to re-run. Idempotent.
set -euo pipefail

APP="$(cd "$(dirname "$0")/../.." && pwd)"
DEPLOY_USER="$(stat -c '%U' "$APP/composer.json")"   # whoever owns the checkout
WEB_GROUP="www-data"

echo "App:          $APP"
echo "Deploy user:  $DEPLOY_USER"
echo "Web group:    $WEB_GROUP"

# .env: readable by www-data via group, never world-readable (it holds the API key and DB password).
if [ -f "$APP/.env" ]; then
    chown "$DEPLOY_USER:$WEB_GROUP" "$APP/.env"
    chmod 0640 "$APP/.env"
    echo "Fixed .env ownership and mode (0640 $DEPLOY_USER:$WEB_GROUP)."
fi

# Writable trees: owned by the deploy user, group www-data, group-writable, setgid so NEW files keep
# the www-data group (this is what lets an upload written by PHP-FPM be read back by the cron worker).
for dir in runtime public/assets; do
    install -d "$APP/$dir"
    chown -R "$DEPLOY_USER:$WEB_GROUP" "$APP/$dir"
    # setgid on directories (2775), plain group-writable on files.
    find "$APP/$dir" -type d -exec chmod 2775 {} +
    find "$APP/$dir" -type f -exec chmod 0664 {} +
    echo "Fixed $dir (2775 dirs, 0664 files, group $WEB_GROUP, setgid)."
done

echo "Done. Reload PHP-FPM is not required. Retry http://knowledge-forge.local"
