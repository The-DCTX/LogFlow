#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
#  LogFlow Agent — Linux
#  Surveille les logs de sécurité et envoie à LogFlow via HTTP
#
#  Installation :
#    chmod +x linux-agent.sh
#    sudo cp linux-agent.sh /usr/local/bin/logflow-agent
#    sudo cp logflow-agent.service /etc/systemd/system/
#    sudo systemctl enable --now logflow-agent
# ─────────────────────────────────────────────────────────────

LOGFLOW_URL="http://YOUR_SERVER/api/receive.php"
API_KEY="YOUR_API_KEY"
HOSTNAME_OVERRIDE=""          # laisser vide pour auto-detect
BATCH_SIZE=20                 # envoyer par lot
BATCH_DELAY=2                 # secondes entre les envois

# Fichiers à surveiller (adapter selon la distrib)
LOG_FILES=(
    "/var/log/auth.log"       # Debian/Ubuntu
    "/var/log/secure"         # RHEL/CentOS/Fedora
    "/var/log/syslog"
    "/var/log/kern.log"
    "/var/log/fail2ban.log"
    "/var/log/ufw.log"
)

# ─────────────────────────────────────────────────────────────

HOST="${HOSTNAME_OVERRIDE:-$(hostname -f 2>/dev/null || hostname)}"
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
    local file="$2"

    # Extraire programme et PID depuis le format syslog standard
    # ex: Jun  9 10:23:01 hostname sshd[1234]: message
    local program pid message
    if [[ "$line" =~ [A-Z][a-z]{2}[[:space:]]+[0-9]+[[:space:]]+[0-9:]+[[:space:]]+[^[:space:]]+[[:space:]]+([a-zA-Z0-9_/-]+)(\[([0-9]+)\])?:[[:space:]](.*) ]]; then
        program="${BASH_REMATCH[1]}"
        pid="${BASH_REMATCH[3]}"
        message="${BASH_REMATCH[4]}"
    else
        program="syslog"
        message="$line"
    fi

    # Détection sévérité basique
    local severity=6
    if echo "$message" | grep -qiE "failed|failure|invalid|error|denied|refused|blocked|critical"; then
        severity=3
    elif echo "$message" | grep -qiE "warning|warn"; then
        severity=4
    elif echo "$message" | grep -qiE "accepted|success|started|opened"; then
        severity=6
    fi

    # Escaper les guillemets dans le message
    message="${message//\\/\\\\}"
    message="${message//\"/\\\"}"

    local entry="{\"host\":\"$HOST\",\"program\":\"$program\",\"pid\":\"$pid\",\"severity\":$severity,\"message\":\"$message\",\"os\":\"linux\"}"
    QUEUE+=("$entry")

    if [ ${#QUEUE[@]} -ge "$BATCH_SIZE" ]; then
        send_batch
    fi
}

# Filtrer les fichiers existants
EXISTING=()
for f in "${LOG_FILES[@]}"; do
    [ -r "$f" ] && EXISTING+=("$f")
done

if [ ${#EXISTING[@]} -eq 0 ]; then
    echo "Aucun fichier de log lisible trouvé." >&2
    exit 1
fi

echo "[LogFlow Agent] Surveillance de : ${EXISTING[*]}"
echo "[LogFlow Agent] Envoi vers : $LOGFLOW_URL"

# Surveiller avec tail -F (suit les rotations)
tail -Fq -n 0 "${EXISTING[@]}" 2>/dev/null | while IFS= read -r line; do
    parse_and_queue "$line"
    # Flush périodique
    sleep 0."$BATCH_DELAY" 2>/dev/null || true
    send_batch
done
