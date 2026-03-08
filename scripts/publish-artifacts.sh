#!/bin/bash
# Publish cron and logrotate configs and ensure log destinations are ready.
# Must be run as root: sudo scripts/publish-artifacts.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$REPO_ROOT/logs"
LOG_USER="redrover"
CRON_DEST="/etc/cron.d/utility-app"
LOGROTATE_DEST="/etc/logrotate.d/utility-app"

if [[ $EUID -ne 0 ]]; then
    echo "Error: must be run as root (use sudo $0)" >&2
    exit 1
fi

# ── Log directory ─────────────────────────────────────────────────────────────

if [[ ! -d "$LOG_DIR" ]]; then
    mkdir -p "$LOG_DIR"
    echo "Created $LOG_DIR"
fi

chown "$LOG_USER:$LOG_USER" "$LOG_DIR"
chmod 755 "$LOG_DIR"

for log in sync-products.log sync-paid-orders.log sync-products-full.log; do
    touch "$LOG_DIR/$log"
    chown "$LOG_USER:$LOG_USER" "$LOG_DIR/$log"
    chmod 640 "$LOG_DIR/$log"
done

echo "Log directory $LOG_DIR is ready (owner: $LOG_USER)"

# ── cron.d ────────────────────────────────────────────────────────────────────

install -o root -g root -m 644 "$REPO_ROOT/artifacts/cron.tab" "$CRON_DEST"
echo "Installed $CRON_DEST"

# ── logrotate.d ───────────────────────────────────────────────────────────────

install -o root -g root -m 644 "$REPO_ROOT/artifacts/logrotate.conf" "$LOGROTATE_DEST"
echo "Installed $LOGROTATE_DEST"

echo "Done."
