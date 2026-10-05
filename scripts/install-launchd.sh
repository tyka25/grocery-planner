#!/usr/bin/env bash
# Run the web server and the sidecar loop unattended, as launchd agents:
# started at login, restarted if they exit, logs in ~/Library/Logs.
#
#   scripts/install-launchd.sh             install (or reinstall) and start both
#   scripts/install-launchd.sh uninstall   stop and remove both
#   scripts/install-launchd.sh status      show whether they're running
#
# launchd doesn't read your shell profile, so php (Herd) and node (nvm) are
# resolved now and written into the plists as absolute paths. Re-run this
# after switching PHP or Node versions, or moving the repo.
#
# The sidecar runs under `caffeinate -s`, which keeps the Mac from sleeping
# while it's on AC power (display sleep is still allowed). Closing the lid
# still sleeps a laptop unless an external display is attached.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
AGENTS="$HOME/Library/LaunchAgents"
LOGS="$HOME/Library/Logs/grocery-planner"
DOMAIN="gui/$(id -u)"
WEB_LABEL="com.grocery-planner.web"
SIDECAR_LABEL="com.grocery-planner.sidecar"
PORT=8000

unload() {
    for label in "$WEB_LABEL" "$SIDECAR_LABEL"; do
        launchctl bootout "$DOMAIN/$label" 2>/dev/null || true
    done
}

status() {
    for label in "$WEB_LABEL" "$SIDECAR_LABEL"; do
        if launchctl print "$DOMAIN/$label" >/dev/null 2>&1; then
            launchctl print "$DOMAIN/$label" | awk -v l="$label" '
                $1 == "state" { s = $3 } $1 == "pid" { p = $3 }
                $1 == "last" && $2 == "exit" && $3 == "code" { e = substr($0, index($0, "=") + 2) }
                END { printf "%-30s %s%s%s\n", l, s, (p ? " pid " p : ""), (e != "" ? ", last exit " e : "") }'
        else
            printf "%-30s not installed\n" "$label"
        fi
    done
    echo "Logs: $LOGS"
}

case "${1:-install}" in
    uninstall)
        unload
        rm -f "$AGENTS/$WEB_LABEL.plist" "$AGENTS/$SIDECAR_LABEL.plist"
        echo "Removed $WEB_LABEL and $SIDECAR_LABEL."
        exit 0
        ;;
    status)
        status
        exit 0
        ;;
    install) ;;
    *)
        echo "usage: $0 [install|uninstall|status]" >&2
        exit 2
        ;;
esac

PHP="$(command -v php)" || { echo "php not found on PATH" >&2; exit 1; }
NODE="$(command -v node)" || { echo "node not found on PATH" >&2; exit 1; }
NPM="$(command -v npm)" || { echo "npm not found on PATH" >&2; exit 1; }
NODE_BIN="$(dirname "$NODE")"

for f in web/.env sidecar/.env sidecar/storage-state.json; do
    [[ -f "$REPO/$f" ]] || { echo "Missing $f (see CLAUDE.md, 'Running the sidecar')." >&2; exit 1; }
done

# Stop our own jobs first so the port check below only sees strangers.
unload
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $PORT is already in use (a hand-started 'php artisan serve'?):" >&2
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >&2
    echo "Stop it and re-run." >&2
    exit 1
fi

# A phone can't load assets from a Vite dev server on 127.0.0.1, so serve
# a fresh production build. public/hot exists only while `npm run dev` runs.
rm -f "$REPO/web/public/hot"
echo "Building web assets..."
(cd "$REPO/web" && "$NPM" run build --silent >/dev/null 2>&1) \
    || { echo "npm run build failed; run it in web/ to see why." >&2; exit 1; }

mkdir -p "$AGENTS" "$LOGS"
ENV_PATH="$NODE_BIN:/usr/bin:/bin:/usr/sbin:/sbin"

# write_plist LABEL WORKDIR LOGNAME ARG...
write_plist() {
    local label="$1" workdir="$2" logname="$3"
    shift 3
    {
        cat <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$label</string>
    <key>ProgramArguments</key>
    <array>
EOF
        for arg in "$@"; do
            printf '        <string>%s</string>\n' "$arg"
        done
        cat <<EOF
    </array>
    <key>WorkingDirectory</key>
    <string>$workdir</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>$ENV_PATH</string>
    </dict>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>ThrottleInterval</key>
    <integer>30</integer>
    <key>StandardOutPath</key>
    <string>$LOGS/$logname.log</string>
    <key>StandardErrorPath</key>
    <string>$LOGS/$logname.log</string>
</dict>
</plist>
EOF
    } > "$AGENTS/$label.plist"
    plutil -lint -s "$AGENTS/$label.plist"
}

write_plist "$WEB_LABEL" "$REPO/web" web \
    "$PHP" artisan serve --host=0.0.0.0 --port="$PORT"

# `npm run loop` rebuilds dist/ first, so a restart picks up a git pull.
write_plist "$SIDECAR_LABEL" "$REPO/sidecar" sidecar \
    /usr/bin/caffeinate -s "$NPM" run loop

for label in "$WEB_LABEL" "$SIDECAR_LABEL"; do
    launchctl bootstrap "$DOMAIN" "$AGENTS/$label.plist"
done

echo "Installed. php: $PHP, node: $NODE"
sleep 3
status
