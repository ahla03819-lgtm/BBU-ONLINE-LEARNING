#!/usr/bin/env bash
# Runtime verification of server-side meeting recording against the real
# application, the real database, a real queue worker and a real LiveKit Egress
# service speaking the Twirp protocol.
#
# Usage: runtime/verify.sh <scenario.json>
set -uo pipefail

BASE="http://127.0.0.1:8123"
EGRESS="http://127.0.0.1:8099"
SCENARIO="$1"

j() { python3 -c "import json,sys;d=json.load(open('$SCENARIO'));print(d$1)"; }
CLASS_ID=$(j "['class_id']")
MEETING_UUID=$(j "['meeting_uuid']")
CHANNEL_ID=$(j "['channel_id']")
TEACHER_EMAIL=$(j "['teacher']['email']")
STUDENT_EMAIL=$(j "['student']['email']")
TEACHER_NAME=$(j "['teacher']['name']")
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n     expected: %s\n     actual:   %s\n' "$1" "$2" "$3"; }
is()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$2" "$3"; fi; }
has()  { case "$2" in *"$3"*) ok "$1";; *) bad "$1" "*$3*" "$2";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "not containing $3" "$2";; *) ok "$1";; esac; }

# --- authenticated HTTP session -------------------------------------------------
# The CSRF token is taken from the meta tag, which is exactly what the application's
# own recording hook does, so the harness exercises the same mechanism a browser does.
# Laravel issues the XSRF-TOKEN cookie on every response, so it is readable in both
# the anonymous and the authenticated state. Sending it as X-XSRF-TOKEN is the
# standard client pattern and is accepted by the same VerifyCsrfToken middleware
# that guards the recording endpoints.
csrf_for() {
  curl -sL -o /dev/null -b "$1" -c "$1" "$BASE/login"
  awk '/XSRF-TOKEN/{print $7}' "$1" | tail -1 | python3 -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))"
}

login() {
  local email="$1" jar="$2" token
  rm -f "$jar"
  token=$(csrf_for "$jar")
  [ -z "$token" ] && { echo "no-csrf"; return; }
  curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -X POST "$BASE/login" \
    -H "X-XSRF-TOKEN: $token" -H 'X-Requested-With: XMLHttpRequest' -H 'Accept: application/json' \
    -H 'Content-Type: application/json' -d "{\"email\":\"$email\",\"password\":\"password\"}"
}

status() { # method url jar [data]
  local method="$1" url="$2" jar="$3" data="${4:-}" token
  token=$(csrf_for "$jar")
  if [ -n "$data" ]; then
    curl -s -b "$jar" -c "$jar" -X "$method" "$url" \
      -H "X-XSRF-TOKEN: $token" -H 'X-Requested-With: XMLHttpRequest' \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$data"
  else
    curl -s -b "$jar" -c "$jar" -X "$method" "$url" \
      -H "X-XSRF-TOKEN: $token" -H 'X-Requested-With: XMLHttpRequest' -H 'Accept: application/json'
  fi
}
code() { # method url jar [data]
  local method="$1" url="$2" jar="$3" data="${4:-}" token
  token=$(csrf_for "$jar")
  if [ -n "$data" ]; then
    curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -X "$method" "$url" \
      -H "X-XSRF-TOKEN: $token" -H 'X-Requested-With: XMLHttpRequest' \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$data"
  else
    curl -s -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" -X "$method" "$url" \
      -H "X-XSRF-TOKEN: $token" -H 'X-Requested-With: XMLHttpRequest' -H 'Accept: application/json'
  fi
}

jq_() { python3 -c "
import json, sys
raw = sys.stdin.read().strip()
if not raw:
    print('<empty>'); raise SystemExit
try:
    d = json.loads(raw)
except Exception:
    print('<non-json>'); raise SystemExit
import re
expr = sys.argv[1]
# Auto-prefix only a pure subscript chain such as ['recording']['status'].
# A list comprehension that already names d must be left alone.
if re.fullmatch(r'(?:\[[^\[\]]+\])+', expr):
    expr = 'd' + expr
try:
    print(eval(expr, {'d': d, 'json': json, 'len': len, 'sum': sum, 'any': any, 'all': all}))
except Exception:
    print('<missing>')
" "$1"; }

egress_field() { curl -s "$EGRESS/__state" | jq_ "['$1']"; }
recording_db() { php runtime/recording-state.php "$1" 2>/dev/null; }

TEACHER_JAR=/tmp/rt-teacher.jar
STUDENT_JAR=/tmp/rt-student.jar
URL_REC="$BASE/collaboration/classes/$CLASS_ID/meetings/$MEETING_UUID/recordings"
URL_LEAVE="$BASE/collaboration/classes/$CLASS_ID/meetings/$MEETING_UUID/leave"
URL_END="$BASE/school-classes/$CLASS_ID/meetings/$MEETING_UUID/end"
URL_MESSAGES="$BASE/collaboration/classes/$CLASS_ID/channels/$CHANNEL_ID/messages"

banner() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

reset_all() {
  curl -s -o /dev/null "$EGRESS/__reset"
  php runtime/reset-recordings.php >/dev/null 2>&1
}

# ================================================================================
for SCEN in $(python3 -c "import json;print(' '.join(json.load(open('$SCENARIO'))['scenarios']))"); do
  reset_all
  banner "Scenario: $SCEN"
  TLOGIN=$(login "$TEACHER_EMAIL" "$TEACHER_JAR"); is "teacher signs in over HTTP" "302" "$TLOGIN"
  SLOGIN=$(login "$STUDENT_EMAIL" "$STUDENT_JAR"); is "student signs in over HTTP" "302" "$SLOGIN"

  # A student must never be able to start, stop, or learn that the control exists.
  SC=$(code POST "$URL_REC" "$STUDENT_JAR" '{"duration_minutes":12}')
  is "student cannot start recording" "403" "$SC"
  is "student start created no egress" "0" "$(egress_field starts)"

  ROOM_HTML=$(curl -s -b "$STUDENT_JAR" "$BASE/collaboration/classes/$CLASS_ID/meetings/$MEETING_UUID/room")
  hasnt "student room page carries no can_start_recording" "$ROOM_HTML" '"can_start_recording":true'

  case "$SCEN" in
  manual)
    START=$(status POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":12}')
    is "host starts a 12-minute recording" "recording" "$(printf '%s' "$START" | jq_ "['recording']['status']")"
    is "exactly one egress was started" "1" "$(egress_field starts)"
    is "the projection reports the configured layout" "screen-share" "$(printf '%s' "$START" | jq_ "['recording']['layout']")"

    # Everyone in the room sees the same authoritative state.
    ST=$(status GET "$URL_REC/current" "$STUDENT_JAR")
    is "student sees the recording as active" "recording" "$(printf '%s' "$ST" | jq_ "['recording']['status']")"
    is "student cannot start (no can_start)" "False" "$(printf '%s' "$ST" | jq_ "['recording']['can_start']")"
    DEADLINE=$(printf '%s' "$ST" | jq_ "['recording']['scheduled_stop_at']")
    has "the student is handed the authoritative deadline" "$DEADLINE" "T"

    # A duplicate start cannot produce a second egress.
    DUP=$(code POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":5}')
    is "a second start is rejected" "422" "$DUP"
    is "still only one egress" "1" "$(egress_field starts)"

    STOP=$(status POST "$URL_REC/stop" "$TEACHER_JAR")
    is "manual stop reaches processing" "processing" "$(printf '%s' "$STOP" | jq_ "['recording']['status']")"
    is "stop reason is manual" "manual" "$(printf '%s' "$STOP" | jq_ "['recording']['stop_reason']")"
    is "the provider was asked to stop exactly once" "1" "$(egress_field stops)"

    CARD=$(recording_db card)
    is "exactly one channel card exists" "1" "$(printf '%s' "$CARD" | jq_ "['count']")"
    is "the card is in processing" "processing" "$(printf '%s' "$CARD" | jq_ "['status']")"
    ;;

  timed)
    START=$(status POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":1}')
    is "host starts a 1-minute recording" "recording" "$(printf '%s' "$START" | jq_ "['recording']['status']")"
    is "provider start was sent" "1" "$(egress_field starts)"
    echo "  ... waiting for the server-authoritative deadline"
    php runtime/wait-for.php "recording-is-ready" 150
    DB=$(recording_db card)
    is "the deadline job stopped it" "duration_reached" "$(printf '%s' "$DB" | jq_ "['stop_reason']")"
    is "exactly one provider stop" "1" "$(egress_field stops)"
    is "exactly one channel card" "1" "$(printf '%s' "$DB" | jq_ "['count']")"
    is "the recording became watchable" "ready" "$(printf '%s' "$DB" | jq_ "['status']")"
    ;;

  leave)
    START=$(status POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":12}')
    is "host starts recording" "recording" "$(printf '%s' "$START" | jq_ "['recording']['status']")"

    # A student leaving must not touch the host's recording.
    status POST "$URL_LEAVE" "$STUDENT_JAR" >/dev/null
    is "student leave did not stop the recording" "recording" "$(status GET "$URL_REC/current" "$TEACHER_JAR" | jq_ "['recording']['status']")"
    is "student leave did not stop the provider" "0" "$(egress_field stops)"

    status POST "$URL_LEAVE" "$TEACHER_JAR" >/dev/null
    DB=$(recording_db card)
    is "the recorder's explicit leave stopped it" "recorder_left" "$(printf '%s' "$DB" | jq_ "['stop_reason']")"
    is "the provider was stopped exactly once" "1" "$(egress_field stops)"
    is "the class received exactly one card" "1" "$(printf '%s' "$DB" | jq_ "['count']")"
    is "the meeting itself was untouched" "active" "$(recording_db meeting)"
    ;;

  refresh)
    START=$(status POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":12}')
    is "host starts a 12-minute recording" "recording" "$(printf '%s' "$START" | jq_ "['recording']['status']")"
    BEFORE=$(status GET "$URL_REC/current" "$TEACHER_JAR" | jq_ "['recording']['scheduled_stop_at']")
    sleep 2
    # A brand new session is exactly what a browser refresh produces.
    login "$TEACHER_EMAIL" "$TEACHER_JAR" >/dev/null
    AFTER=$(status GET "$URL_REC/current" "$TEACHER_JAR" | jq_ "['recording']['scheduled_stop_at']")
    is "the deadline survived the refresh" "$BEFORE" "$AFTER"
    is "the recording survived the refresh" "recording" "$(status GET "$URL_REC/current" "$TEACHER_JAR" | jq_ "['recording']['status']")"
    is "no duplicate egress was created" "1" "$(egress_field starts)"
    is "the provider was never asked to stop" "0" "$(egress_field stops)"
    ;;

  end)
    START=$(status POST "$URL_REC" "$TEACHER_JAR" '{"duration_minutes":12}')
    is "host starts recording" "recording" "$(printf '%s' "$START" | jq_ "['recording']['status']")"
    EC=$(code POST "$URL_END" "$TEACHER_JAR")
    is "the host ends the meeting" "200" "$EC"
    DB=$(recording_db card)
    is "the recording was stopped by the end" "meeting_ended" "$(printf '%s' "$DB" | jq_ "['stop_reason']")"
    is "the provider was stopped exactly once" "1" "$(egress_field stops)"
    is "the class received exactly one card" "1" "$(printf '%s' "$DB" | jq_ "['count']")"
    is "the meeting reached its terminal state" "ended" "$(recording_db meeting)"
    ;;
  esac

  # --- playback authorization, common to every scenario -------------------------
  if [ "$(printf '%s' "$(recording_db card)" | jq_ "['status']")" = "ready" ]; then
    MSGS=$(status GET "$URL_MESSAGES" "$STUDENT_JAR")
    PLAY=$(printf '%s' "$MSGS" | jq_ "[m for m in d['messages'] if m['type']=='meeting_recording'][0]['recording']['playback_url']")
    has "the ready card exposes a playback route" "$PLAY" "/recordings/"
    hasnt "the card payload carries no stored file path" "$(printf '%s' "$MSGS")" "meeting-recordings/"
    hasnt "the card payload carries no egress id" "$(printf '%s' "$MSGS")" "EG_runtime_"
    # The projection is an absolute URL built from APP_URL; the harness plays it
    # against the local server it is actually talking to.
    PLAY_PATH="${PLAY#*://*/}"
    PC=$(curl -s -o /dev/null -w '%{http_code}' -b "$STUDENT_JAR" "$BASE/$PLAY_PATH")
    is "an authorised student can play the recording" "200" "$PC"
    CT=$(curl -s -o /dev/null -w '%{content_type}' -b "$STUDENT_JAR" "$BASE/$PLAY_PATH")
    has "the recording is served as a private video" "$CT" "video/mp4"
  fi
done

printf '\n\033[1mRuntime totals: %d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
