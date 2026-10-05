#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TARGET_URL="http://127.0.0.1:8090"
REVERB_HOST="127.0.0.1"
REVERB_PORT="8080"
ENV_FILE="$PROJECT_ROOT/.env"
TUNNEL_LOG="$PROJECT_ROOT/storage/logs/cloudflared-quick-tunnel.log"
TUNNEL_PID_FILE="$PROJECT_ROOT/storage/logs/cloudflared-quick-tunnel.pid"
REVERB_LOG="$PROJECT_ROOT/storage/logs/reverb-quick-tunnel.log"

NEW_TUNNEL_PID=""
ENV_CANDIDATE=""
ENV_UPDATED=0
SUCCESS=0

say() {
    printf '%s\n' "$*"
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    exit_code=$?

    if [ -n "$ENV_CANDIDATE" ] && [ -f "$ENV_CANDIDATE" ]; then
        rm -f -- "$ENV_CANDIDATE"
    fi

    if [ "$SUCCESS" -ne 1 ] && [ "$ENV_UPDATED" -eq 0 ] && [ -n "$NEW_TUNNEL_PID" ]; then
        if is_project_tunnel_pid "$NEW_TUNNEL_PID"; then
            kill -TERM "$NEW_TUNNEL_PID" 2>/dev/null || true
        fi
        rm -f -- "$TUNNEL_PID_FILE"
    fi

    if [ "$SUCCESS" -ne 1 ] && [ "$ENV_UPDATED" -eq 1 ]; then
        printf '%s\n' 'ERROR: The tunnel and .env were updated, but a later health check failed.' >&2
        printf '%s\n' "Inspect $TUNNEL_LOG and $REVERB_LOG; the verified tunnel was left running." >&2
    fi

    exit "$exit_code"
}

trap cleanup EXIT

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

process_cwd() {
    lsof -a -p "$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p' | head -n 1
}

is_project_tunnel_pid() {
    pid="$1"
    kill -0 "$pid" 2>/dev/null || return 1

    command_line="$(ps -p "$pid" -o command= 2>/dev/null || true)"
    case "$command_line" in
        "cloudflared tunnel --url $TARGET_URL"|"$CLOUDFLARED tunnel --url $TARGET_URL") ;;
        *) return 1 ;;
    esac

    [ "$(process_cwd "$pid")" = "$PROJECT_ROOT" ]
}

project_tunnel_pids() {
    pgrep -x cloudflared 2>/dev/null | while IFS= read -r pid; do
        if is_project_tunnel_pid "$pid"; then
            printf '%s\n' "$pid"
        fi
    done
}

stop_project_tunnel() {
    pids="$(project_tunnel_pids || true)"
    [ -n "$pids" ] || return 0

    for pid in $pids; do
        say "Stopping this project's Quick Tunnel (PID $pid)..."
        kill -TERM "$pid"
    done

    attempts=0
    while [ "$attempts" -lt 50 ]; do
        remaining=0
        for pid in $pids; do
            if is_project_tunnel_pid "$pid"; then
                remaining=1
            fi
        done
        [ "$remaining" -eq 0 ] && return 0
        attempts=$((attempts + 1))
        sleep 0.1
    done

    for pid in $pids; do
        if is_project_tunnel_pid "$pid"; then
            say "Quick Tunnel PID $pid did not stop gracefully; stopping that exact PID."
            kill -KILL "$pid"
        fi
    done
}

read_env_value() {
    key="$1"
    value="$(awk -v key="$key" 'index($0, key "=") == 1 { sub(/^[^=]*=/, ""); print; exit }' "$ENV_FILE")"
    value="${value%$'\r'}"
    case "$value" in
        \"*\") value="${value#\"}"; value="${value%\"}" ;;
        \'*\') value="${value#\'}"; value="${value%\'}" ;;
    esac
    printf '%s' "$value"
}

is_project_reverb_listening() {
    listener_pids="$(lsof -nP -t -iTCP:"$REVERB_PORT" -sTCP:LISTEN 2>/dev/null | sort -u || true)"
    [ -n "$listener_pids" ] || return 1

    for pid in $listener_pids; do
        if [ "$(process_cwd "$pid")" = "$PROJECT_ROOT" ]; then
            return 0
        fi
    done

    return 1
}

project_reverb_pids() {
    listener_pids="$(lsof -nP -t -iTCP:"$REVERB_PORT" -sTCP:LISTEN 2>/dev/null | sort -u || true)"
    [ -n "$listener_pids" ] || return 0

    for pid in $listener_pids; do
        if [ "$(process_cwd "$pid")" = "$PROJECT_ROOT" ]; then
            printf '%s\n' "$pid"
        fi
    done
}

wait_for_pids_to_stop() {
    pids="$1"
    attempts=0
    while [ "$attempts" -lt 30 ]; do
        running=0
        for pid in $pids; do
            if kill -0 "$pid" 2>/dev/null; then
                running=1
            fi
        done
        [ "$running" -eq 0 ] && return 0
        attempts=$((attempts + 1))
        sleep 0.5
    done
    return 1
}

wait_for_reverb() {
    attempts=0
    while [ "$attempts" -lt 30 ]; do
        if is_project_reverb_listening; then
            return 0
        fi
        attempts=$((attempts + 1))
        sleep 0.5
    done
    return 1
}

cd "$PROJECT_ROOT"

require_command cloudflared
require_command php
require_command curl
require_command lsof
require_command ps
require_command pgrep
require_command node

node -e "require.resolve('ws')" >/dev/null 2>&1 || fail "The installed frontend dependencies do not provide the 'ws' module required for WebSocket verification."

CLOUDFLARED="$(command -v cloudflared)"

[ -f artisan ] || fail "Laravel artisan was not found at $PROJECT_ROOT/artisan."
[ -f "$ENV_FILE" ] || fail ".env was not found at $ENV_FILE."
[ -f config/reverb.php ] || fail 'config/reverb.php is missing.'
[ -f config/broadcasting.php ] || fail 'config/broadcasting.php is missing.'

if ! curl --silent --show-error --output /dev/null --connect-timeout 2 --max-time 5 "$TARGET_URL/login"; then
    fail "Caddy target $TARGET_URL is unavailable; the tunnel was not rotated."
fi

OLD_APP_URL="$(read_env_value APP_URL)"
OLD_HOST="$(php -r '$host = parse_url($argv[1], PHP_URL_HOST); if (! is_string($host) || $host === "") { exit(1); } echo $host;' "$OLD_APP_URL")" \
    || fail 'APP_URL is missing or is not a valid URL.'

OLD_ALLOWED_ORIGINS="$(read_env_value REVERB_ALLOWED_ORIGINS)"
[ -n "$OLD_ALLOWED_ORIGINS" ] || say 'REVERB_ALLOWED_ORIGINS is missing or empty; it will be added intentionally.'

stop_project_tunnel

: > "$TUNNEL_LOG"
nohup "$CLOUDFLARED" tunnel --url "$TARGET_URL" > "$TUNNEL_LOG" 2>&1 < /dev/null &
NEW_TUNNEL_PID=$!
printf '%s\n' "$NEW_TUNNEL_PID" > "$TUNNEL_PID_FILE"

NEW_URL=""
attempts=0
while [ "$attempts" -lt 90 ]; do
    if ! kill -0 "$NEW_TUNNEL_PID" 2>/dev/null; then
        fail "cloudflared exited before publishing a URL; inspect $TUNNEL_LOG."
    fi

    NEW_URL="$(grep -Eo 'https://[A-Za-z0-9-]+\.trycloudflare\.com' "$TUNNEL_LOG" | tail -n 1 || true)"
    [ -n "$NEW_URL" ] && break

    attempts=$((attempts + 1))
    sleep 0.5
done

[ -n "$NEW_URL" ] || fail "Timed out waiting for cloudflared to publish a Quick Tunnel URL; inspect $TUNNEL_LOG."

NEW_HOST="$(php -r '$url = $argv[1]; $host = parse_url($url, PHP_URL_HOST); if (parse_url($url, PHP_URL_SCHEME) !== "https" || ! is_string($host) || ! preg_match("/^[A-Za-z0-9-]+\\.trycloudflare\\.com$/", $host)) { exit(1); } echo $host;' "$NEW_URL")" \
    || fail 'cloudflared emitted an invalid Quick Tunnel URL.'

attempts=0
until php -r '$records = dns_get_record($argv[1], DNS_A | DNS_AAAA); exit(is_array($records) && count($records) > 0 ? 0 : 1);' "$NEW_HOST"; do
    attempts=$((attempts + 1))
    [ "$attempts" -lt 90 ] || fail "DNS did not resolve $NEW_HOST within the timeout."
    sleep 1
done

PUBLIC_HTTP_CODE="000"
attempts=0
while [ "$attempts" -lt 45 ]; do
    PUBLIC_HTTP_CODE="$(curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 3 --max-time 10 "$NEW_URL/login" 2>/dev/null || true)"
    [ "$PUBLIC_HTTP_CODE" != "000" ] && [ -n "$PUBLIC_HTTP_CODE" ] && break
    attempts=$((attempts + 1))
    sleep 1
done

[ "$PUBLIC_HTTP_CODE" != "000" ] && [ -n "$PUBLIC_HTTP_CODE" ] \
    || fail "The new tunnel did not return an HTTP response for /login; .env was not changed."

NEW_ALLOWED_ORIGINS="$(php -r '
$raw = $argv[1];
$host = $argv[2];
$url = $argv[3];
$items = array_values(array_filter(array_map("trim", explode(",", $raw)), static fn ($item) => $item !== ""));
$replacement = null;
$result = [];
foreach ($items as $item) {
    $candidate = str_contains($item, "://") ? $item : "https://{$item}";
    $itemHost = parse_url($candidate, PHP_URL_HOST);
    if (is_string($itemHost) && str_ends_with(strtolower($itemHost), ".trycloudflare.com")) {
        if ($replacement === null) {
            $replacement = str_contains($item, "://") ? $url : $host;
            $result[] = $replacement;
        }
        continue;
    }
    $result[] = $item;
}
if ($replacement === null) {
    $usesUrls = count($items) > 0 && count(array_filter($items, static fn ($item) => str_contains($item, "://"))) === count($items);
    $result[] = $usesUrls ? $url : $host;
}
echo implode(",", $result);
' "$OLD_ALLOWED_ORIGINS" "$NEW_HOST" "$NEW_URL")"

ENV_CANDIDATE="$(mktemp "$PROJECT_ROOT/.env.quick-tunnel.XXXXXX")"
cp -p "$ENV_FILE" "$ENV_CANDIDATE"

NEW_APP_URL="$NEW_URL" NEW_ALLOWED_ORIGINS="$NEW_ALLOWED_ORIGINS" php -r '
$path = $argv[1];
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Unable to read the .env candidate.\n");
    exit(1);
}
foreach (["APP_URL" => getenv("NEW_APP_URL"), "REVERB_ALLOWED_ORIGINS" => getenv("NEW_ALLOWED_ORIGINS")] as $key => $value) {
    $pattern = "/^".preg_quote($key, "/")."=.*$/m";
    $count = preg_match_all($pattern, $content);
    if ($count > 1) {
        fwrite(STDERR, "Refusing to update duplicate {$key} entries.\n");
        exit(1);
    }
    if ($count === 1) {
        $content = preg_replace_callback($pattern, static function ($matches) use ($key, $value) {
            $old = substr($matches[0], strlen($key) + 1);
            $quote = strlen($old) >= 2 && (($old[0] === "\"" && substr($old, -1) === "\"") || ($old[0] === "\x27" && substr($old, -1) === "\x27")) ? $old[0] : "";
            return $key."=".$quote.$value.$quote;
        }, $content, 1);
    } else {
        $content = rtrim($content, "\r\n").PHP_EOL.$key."=".$value.PHP_EOL;
        fwrite(STDOUT, "Added missing {$key}.\n");
    }
}
if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write the .env candidate.\n");
    exit(1);
}
' "$ENV_CANDIDATE"

mv -f -- "$ENV_CANDIDATE" "$ENV_FILE"
ENV_CANDIDATE=""
ENV_UPDATED=1

php artisan config:clear --no-interaction

OLD_REVERB_PIDS="$(project_reverb_pids || true)"
php artisan reverb:restart --no-interaction
if [ -n "$OLD_REVERB_PIDS" ]; then
    wait_for_pids_to_stop "$OLD_REVERB_PIDS" || fail 'The existing project Reverb process did not honor the graceful restart signal.'
fi

if ! wait_for_reverb; then
    if lsof -nP -iTCP:"$REVERB_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
        fail "Port $REVERB_PORT is listening, but not from this project; it was not touched."
    fi

    : > "$REVERB_LOG"
    nohup php artisan reverb:start --host="$REVERB_HOST" --port="$REVERB_PORT" > "$REVERB_LOG" 2>&1 < /dev/null &
    REVERB_PID=$!
    say "Reverb was not supervised after its graceful stop; started project Reverb as PID $REVERB_PID."
    wait_for_reverb || fail "Reverb did not resume on $REVERB_HOST:$REVERB_PORT; inspect $REVERB_LOG."
fi

NEW_URL="$NEW_URL" NEW_HOST="$NEW_HOST" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$origins = config("reverb.apps.apps.0.allowed_origins", []);
exit(config("app.url") === getenv("NEW_URL") && in_array(getenv("NEW_HOST"), $origins, true) ? 0 : 1);
' || fail 'Laravel runtime configuration does not match the new tunnel hostname.'

if [ "$OLD_HOST" != "$NEW_HOST" ]; then
    current_app_url="$(read_env_value APP_URL)"
    current_origins="$(read_env_value REVERB_ALLOWED_ORIGINS)"
    case "$current_app_url,$current_origins" in
        *"$OLD_HOST"*) fail 'The previous Quick Tunnel hostname is still active in .env.' ;;
    esac
fi

PUBLIC_HTTP_CODE="$(curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 3 --max-time 15 "$NEW_URL/login" 2>/dev/null || true)"
[ "$PUBLIC_HTTP_CODE" != "000" ] && [ -n "$PUBLIC_HTTP_CODE" ] || fail 'Public /login verification failed after configuration reload.'
say 'Public app: OK'

WS_RESULT="$(node - "$NEW_URL" "$ENV_FILE" <<'NODE'
const fs = require('node:fs');
const WebSocket = require('ws');

const publicUrl = process.argv[2];
const envPath = process.argv[3];
const env = fs.readFileSync(envPath, 'utf8');
const match = env.match(/^REVERB_APP_KEY=(.*)$/m);
if (!match) process.exit(2);
let key = match[1].trim();
if ((key.startsWith('"') && key.endsWith('"')) || (key.startsWith("'") && key.endsWith("'"))) key = key.slice(1, -1);
if (!key) process.exit(2);

const wsUrl = new URL(`/app/${encodeURIComponent(key)}`, publicUrl);
wsUrl.protocol = 'wss:';
wsUrl.search = 'protocol=7&client=js&version=8.4.0&flash=false';

let opened = false;
let established = false;
const socket = new WebSocket(wsUrl, { origin: publicUrl });
const timer = setTimeout(() => {
    socket.close();
    process.exit(opened ? 3 : 4);
}, 15000);

socket.addEventListener('open', () => {
    opened = true;
});

socket.addEventListener('message', (event) => {
    try {
        const payload = JSON.parse(String(event.data));
        if (payload.event === 'pusher:connection_established') {
            established = true;
            clearTimeout(timer);
            socket.close();
            process.stdout.write('connected');
        }
    } catch (_) {
        // Ignore non-JSON frames and keep waiting for the Pusher handshake.
    }
});

socket.addEventListener('close', () => {
    if (!established && opened) setTimeout(() => process.exit(3), 10);
});

socket.addEventListener('error', () => {
    clearTimeout(timer);
    process.exit(opened ? 3 : 4);
});
NODE
)" || fail 'Public WebSocket/Pusher handshake verification failed.'

[ "$WS_RESULT" = 'connected' ] || fail 'Public WebSocket opened without a Pusher connection-established event.'

SUCCESS=1

say '=================================================='
say 'BBU Quick Tunnel Ready'
say '=================================================='
say 'Public URL:'
say "$NEW_URL"
say ''
say 'Tunnel PID:'
say "$NEW_TUNNEL_PID"
say ''
say 'Local target:'
say "$TARGET_URL"
say ''
say 'Laravel config:'
say 'cleared'
say ''
say 'Reverb:'
say "restarted and listening on $REVERB_HOST:$REVERB_PORT"
say ''
say 'APP_URL:'
say 'updated'
say ''
say 'REVERB_ALLOWED_ORIGINS:'
say 'updated'
say ''
say 'Public HTTP:'
say "OK ($PUBLIC_HTTP_CODE)"
say ''
say 'WebSocket 101 / Pusher connection:'
say 'OK'
say ''
say 'Open:'
say "$NEW_URL"
say '=================================================='
