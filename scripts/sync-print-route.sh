#!/bin/bash
# Copy carmarthen's route file into the app directory.
#
# /etc/backup-route lives on the host and names which WAN route is up.  The app
# runs in a container whose /etc is not the host's — but whose /var/www is this
# directory — so the file is copied here, where app/config.php reads it.
#
# Installed as a per-minute cron job by scripts/publish-artifacts.sh, and safe
# to run by hand during a failover when waiting out the poll is not wanted.

set -euo pipefail

SRC="${1:-/etc/backup-route}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$REPO_ROOT/route.ini"

# The carmarthen side may not be installed yet, and a per-minute job must not
# report that every minute.  An absent source leaves route.ini untouched, which
# leaves the print target wherever env.ini already has it.
if [[ ! -e "$SRC" ]]; then
    exit 0
fi

if [[ ! -r "$SRC" ]]; then
    echo "Error: $SRC exists but is not readable" >&2
    exit 1
fi

# Silent when nothing changed, so the job only speaks on an actual failover.
if [[ -f "$DEST" ]] && cmp -s "$SRC" "$DEST"; then
    exit 0
fi

install -m 644 "$SRC" "$DEST"

route="$(sed -n 's/^[[:space:]]*route[[:space:]]*=[[:space:]]*//p' "$DEST" | head -1 | tr -d '[:space:]')"
echo "$(date '+%Y-%m-%d %H:%M:%S') route -> ${route:-unset} ($DEST updated from $SRC)"
