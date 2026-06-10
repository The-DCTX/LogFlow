#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
#  LogFlow Agent — macOS
#  Surveille les événements de sécurité via `log stream`
#  et envoie à LogFlow via HTTP.
#
#  Installation :
#    chmod +x macos-agent.sh
#    sudo cp com.logflow.agent.plist /Library/LaunchDaemons/
#    sudo launchctl load /Library/LaunchDaemons/com.logflow.agent.plist
# ─────────────────────────────────────────────────────────────

LOGFLOW_URL="http://YOUR_SERVER/api/receive.php"
API_KEY="YOUR_API_KEY"
HOST="${HOSTNAME:-$(hostname -f)}"
BATCH_SIZE=15
QUEUE=()

send_batch() {
    [ ${#QUEUE[@]} -eq 0 ] && return
    local payload="[$(IFS=,; echo "${QUEUE[*]}")]"
    curl -sf -X POST "$LOGFLOW_URL" \
        -H "X-Api-Key: $API_KEY" \
        -H "Content-Type: application/json" \
        -d "$payload" > /dev/null 2>&1
    QUEUE=()
}

parse_and_queue() {
    local line="$1"

    # Extraire le process et le message depuis le format unified log
    # Format: 2026-06-09 10:23:01.123456+0200  localhost  sshd[1234]  <Notice>: message
    local program severity_str message
    program=$(echo "$line" | awk '{print $3}' | sed 's/\[.*//')
    severity_str=$(echo "$line" | grep -oP '<\K[^>]+' | head -1)
    message=$(echo "$line" | sed 's/^[^:]*:[^:]*:[^:]*://' | xargs)

    local severity=6
    case "${severity_str,,}" in
        error|fault)   severity=3 ;;
        warning)       severity=4 ;;
        notice)        severity=5 ;;
        info|default)  severity=6 ;;
        debug)         severity=7 ;;
    esac

    # Détection sécurité
    if echo "$message" | grep -qiE "failed|failure|denied|invalid|authentication.*fail"; then
        severity=3
    fi

    message="${message//\\/\\\\}"
    message="${message//\"/\\\"}"
    [ -z "$message" ] && return
    [ -z "$program" ] && program="system"

    local entry="{\"host\":\"$HOST\",\"program\":\"$program\",\"severity\":$severity,\"message\":\"$message\",\"os\":\"macos\"}"
    QUEUE+=("$entry")

    [ ${#QUEUE[@]} -ge "$BATCH_SIZE" ] && send_batch
}

echo "[LogFlow Agent macOS] Démarré sur $HOST"
echo "[LogFlow Agent] Envoi vers : $LOGFLOW_URL"

# Surveille les sous-systèmes de sécurité macOS
log stream \
    --predicate '(subsystem == "com.apple.securityd") OR
                 (subsystem == "com.apple.authorization") OR
                 (subsystem == "com.apple.OpenSSH") OR
                 (category == "authentication") OR
                 (eventMessage CONTAINS "authentication") OR
                 (eventMessage CONTAINS "failed") OR
                 (eventMessage CONTAINS "sudo") OR
                 (eventMessage CONTAINS "login")' \
    --style syslog \
    --level info \
    2>/dev/null | while IFS= read -r line; do
        [ -z "$line" ] && continue
        [[ "$line" == Filtering* ]] && continue
        parse_and_queue "$line"
    done

# Flush final si le stream s'arrête
send_batch
