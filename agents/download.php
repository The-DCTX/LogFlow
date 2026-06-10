<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/settings.php';

$os    = $_GET['os'] ?? '';
$token = $_GET['token'] ?? '';
if (!in_array($os, ['linux','windows','macos'])) { http_response_code(400); exit('OS invalide'); }
if ($token === '') { http_response_code(403); exit("Token d'installation requis"); }

$db = db();
// Le script (et la clé API qu'il embarque) n'est servi que contre un token
// d'enrôlement valide, non révoqué et non expiré.
$ts = $db->prepare("SELECT id, api_key_id FROM install_tokens
                    WHERE token = ? AND revoked = 0
                      AND (expires_at IS NULL OR expires_at > NOW())");
$ts->execute([$token]);
$tok = $ts->fetch();
if (!$tok) { http_response_code(403); exit("Token d'installation invalide ou révoqué"); }

$ak = $db->prepare($tok['api_key_id']
    ? "SELECT api_key FROM api_keys WHERE id = ?"
    : "SELECT api_key FROM api_keys ORDER BY id LIMIT 1");
$tok['api_key_id'] ? $ak->execute([$tok['api_key_id']]) : $ak->execute();
$api_key = $ak->fetchColumn();
if (!$api_key) { http_response_code(404); exit('Clé API introuvable'); }

$db->prepare("UPDATE install_tokens SET last_used = NOW() WHERE id = ?")->execute([$tok['id']]);

$server_url = rtrim(get_setting('server_url', 'http://localhost'), '/');

// ── Catalogues sources ────────────────────────────────────────

$LINUX_CATALOG = [
    'auth_log'    => '/var/log/auth.log',
    'secure'      => '/var/log/secure',
    'fail2ban'    => '/var/log/fail2ban.log',
    'sssd'        => '/var/log/sssd/sssd.log',
    'audit_log'   => '/var/log/audit/audit.log',
    'syslog'      => '/var/log/syslog',
    'messages'    => '/var/log/messages',
    'kern_log'    => '/var/log/kern.log',
    'cron_log'    => '/var/log/cron.log',
    'cron'        => '/var/log/cron',
    'daemon_log'  => '/var/log/daemon.log',
    'boot_log'    => '/var/log/boot.log',
    'mail_log'    => '/var/log/mail.log',
    'dpkg'        => '/var/log/dpkg.log',
    'apt_history' => '/var/log/apt/history.log',
    'yum'         => '/var/log/yum.log',
    'dnf'         => '/var/log/dnf.log',
    'ufw'         => '/var/log/ufw.log',
    'firewalld'   => '/var/log/firewalld',
    'iptables'    => '/var/log/iptables.log',
    'nginx_err'   => '/var/log/nginx/error.log',
    'nginx_acc'   => '/var/log/nginx/access.log',
    'apache_err'  => '/var/log/apache2/error.log',
    'apache_acc'  => '/var/log/apache2/access.log',
    'httpd_err'   => '/var/log/httpd/error_log',
    'httpd_acc'   => '/var/log/httpd/access_log',
    'mysql_err'   => '/var/log/mysql/error.log',
    'pgsql'       => '/var/log/postgresql/postgresql.log',
    'mongodb'     => '/var/log/mongodb/mongod.log',
    'redis'       => '/var/log/redis/redis-server.log',
    'openvpn'     => '/var/log/openvpn.log',
    'samba'       => '/var/log/samba/log.smbd',
    'rkhunter'    => '/var/log/rkhunter.log',
    'letsencrypt' => '/var/log/letsencrypt/letsencrypt.log',
];

$WIN_CATALOG = [
    'logon_ok'            => ['log'=>'Security',                                   'ids'=>[4624]],
    'logon_fail'          => ['log'=>'Security',                                   'ids'=>[4625]],
    'logoff'              => ['log'=>'Security',                                   'ids'=>[4634,4647]],
    'explicit_creds'      => ['log'=>'Security',                                   'ids'=>[4648]],
    'special_privs'       => ['log'=>'Security',                                   'ids'=>[4672]],
    'kerberos_tgt'        => ['log'=>'Security',                                   'ids'=>[4768]],
    'kerberos_tgs'        => ['log'=>'Security',                                   'ids'=>[4769]],
    'kerberos_preauth'    => ['log'=>'Security',                                   'ids'=>[4771]],
    'account_lockout'     => ['log'=>'Security',                                   'ids'=>[4740]],
    'account_created'     => ['log'=>'Security',                                   'ids'=>[4720]],
    'account_modified'    => ['log'=>'Security',                                   'ids'=>[4722,4723,4724,4725,4726]],
    'group_membership'    => ['log'=>'Security',                                   'ids'=>[4728,4732,4756]],
    'group_enum'          => ['log'=>'Security',                                   'ids'=>[4798,4799]],
    'lsass_access'        => ['log'=>'Security',                                   'ids'=>[4656]],
    'network_share'       => ['log'=>'Security',                                   'ids'=>[5140,5145]],
    'process_create'      => ['log'=>'Security',                                   'ids'=>[4688]],
    'service_install'     => ['log'=>'Security',                                   'ids'=>[4697]],
    'scheduled_task'      => ['log'=>'Security',                                   'ids'=>[4698,4699,4700,4701,4702]],
    'audit_log_cleared'   => ['log'=>'Security',                                   'ids'=>[1102]],
    'audit_policy_change' => ['log'=>'Security',                                   'ids'=>[4719,4907]],
    'firewall_change'     => ['log'=>'Security',                                   'ids'=>[4946,4947,4950]],
    'ad_trust'            => ['log'=>'Security',                                   'ids'=>[4706,4713,4716]],
    'service_sys'         => ['log'=>'System',                                     'ids'=>[7045]],
    'service_state'       => ['log'=>'System',                                     'ids'=>[7036,7040]],
    'system_boot'         => ['log'=>'System',                                     'ids'=>[6005,6006,6008]],
    'audit_app_cleared'   => ['log'=>'System',                                     'ids'=>[104]],
    'powershell_logging'  => ['log'=>'Microsoft-Windows-PowerShell/Operational',   'ids'=>[4103,4104]],
    'wmi_persistence'     => ['log'=>'Microsoft-Windows-WMI-Activity/Operational', 'ids'=>[5861]],
    'sysmon_process'      => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[1]],
    'sysmon_network'      => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[3]],
    'sysmon_injection'    => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[8,10,25]],
    'sysmon_registry'     => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[12,13,14]],
    'sysmon_dns'          => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[22,30]],
    'sysmon_driver'       => ['log'=>'Microsoft-Windows-Sysmon/Operational',       'ids'=>[6,7]],
];

$MACOS_CATALOG = [
    'auth_mac'           => ['(process == "securityd")'],
    'authd_mac'          => ['(subsystem == "com.apple.authd")'],
    'login_session'      => ['(process == "loginwindow" AND composedMessage CONTAINS "sessionDidLogin")'],
    'sudo_mac'           => ['(process == "sudo")'],
    'ssh_mac'            => ['(process == "sshd")'],
    'opendirectory_mac'  => ['(process == "sysadminctl")', '(process == "dscl")'],
    'screen_sharing_mac' => ['(process == "screensharingd")'],
    'tcc_mac'            => ['(process == "tccd")'],
    'persistence_mac'    => ['(subsystem == "com.apple.loginwindow.logging" AND composedMessage CONTAINS "performAutolaunch")'],
    'launchd_mac'        => ['(subsystem == "com.apple.xpc.launchd")'],
    'gatekeeper_mac'     => ['(subsystem == "com.apple.securityd" AND composedMessage CONTAINS "code signing")'],
    'network_mac'        => ['(composedMessage CONTAINS "new connection")'],
    'kext_mac'           => ['(process == "kextd")'],
    'keychain_mac'       => ['(process == "loginwindow" AND sender == "Security")'],
    'installer_mac'      => ['(subsystem == "com.apple.installer")', '(subsystem == "com.apple.packagekit")'],
    'update_mac'         => ['(subsystem == "com.apple.SoftwareUpdate")'],
    'system_changes_mac' => ['(subsystem == "com.apple.security")'],
];

$WIN_SEV_MAP = [
    4624=>6,4634=>7,4647=>7,4672=>5,4768=>5,4769=>5,
    4625=>3,4740=>2,4771=>3,4648=>4,
    4720=>4,4726=>4,4728=>4,4732=>4,4756=>4,
    4722=>5,4723=>5,4724=>4,4725=>4,
    4688=>6,4689=>7,4697=>3,7045=>3,
    4698=>3,4699=>4,4700=>5,4701=>5,4702=>5,
    4719=>2,4907=>3,5140=>5,5145=>5,5861=>2,4656=>2,
    1102=>1,104=>1,4946=>4,4947=>4,4950=>3,
    4103=>5,4104=>4,7036=>6,7040=>4,
    6005=>6,6006=>6,6008=>2,4798=>6,4799=>6,
    4706=>2,4713=>2,4716=>3,
    1=>6,3=>6,6=>4,7=>5,8=>2,10=>2,12=>5,13=>5,14=>5,22=>6,25=>2,30=>6,
];

// ── Résolution des sources sélectionnées ─────────────────────
$valid_keys = match($os) {
    'linux'   => array_keys($LINUX_CATALOG),
    'windows' => array_keys($WIN_CATALOG),
    'macos'   => array_keys($MACOS_CATALOG),
    default   => [],
};

$src_param = trim($_GET['sources'] ?? 'all');
if ($src_param === 'all' || $src_param === '') {
    $selected = $valid_keys;
} elseif ($src_param === 'none') {
    $selected = [];
} else {
    $selected = array_values(array_intersect(explode(',', $src_param), $valid_keys));
}
if (empty($selected)) {
    $selected = match($os) {
        'linux'   => ['auth_log','secure','audit_log','syslog','messages','fail2ban','ufw'],
        'windows' => ['logon_ok','logon_fail','account_lockout','special_privs','audit_log_cleared','service_install','service_sys'],
        'macos'   => ['auth_mac','authd_mac','login_session','sudo_mac','ssh_mac','tcc_mac'],
        default   => [],
    };
}

// ── Dispatch ──────────────────────────────────────────────────
if ($os === 'linux') {
    header('Content-Type: application/x-sh');
    header('Content-Disposition: attachment; filename="logflow-install-linux.sh"');
    echo build_linux($server_url, $api_key, $selected, $LINUX_CATALOG);
} elseif ($os === 'macos') {
    header('Content-Type: application/x-sh');
    header('Content-Disposition: attachment; filename="logflow-install-macos.sh"');
    echo build_macos($server_url, $api_key, $selected, $MACOS_CATALOG);
} elseif ($os === 'windows') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="logflow-install.ps1"');
    echo build_windows($server_url, $api_key, $selected, $WIN_CATALOG, $WIN_SEV_MAP);
}

// ─────────────────────────────────────────────────────────────
// LINUX
// ─────────────────────────────────────────────────────────────
function build_linux(string $url, string $key, array $sel, array $catalog): string
{
    $paths = [];
    foreach ($sel as $k) {
        if (isset($catalog[$k])) $paths[] = $catalog[$k];
    }
    $paths      = array_unique($paths);
    $candidates = implode(' ', $paths);
    $src_label  = count($paths) . ' sources : ' . implode(', ', array_slice($paths, 0, 5)) . (count($paths) > 5 ? '…' : '');

    $tpl = <<<'BASH'
#!/usr/bin/env bash
# LogFlow Agent — Linux Installer
# Serveur : __URL_BASE__
# Sources : __SRC_LABEL__
set -e

LOGFLOW_URL="__RECEIVE_URL__"
API_KEY="__API_KEY__"
AGENT_BIN="/usr/local/bin/logflow-agent"
SERVICE_FILE="/etc/systemd/system/logflow-agent.service"
HOSTNAME_VAL=$(hostname -f 2>/dev/null || hostname)

echo "========================================"
echo " LogFlow Agent — Linux"
echo " __SRC_LABEL__"
echo "========================================"

LOG_FILES=()
for f in __CANDIDATES__; do
    [ -f "$f" ] && [ -r "$f" ] && { LOG_FILES+=("$f"); echo "  [OK] $f"; }
done
[ ${#LOG_FILES[@]} -eq 0 ] && { echo "Aucun fichier lisible trouvé." >&2; exit 1; }
echo ""

cat > "$AGENT_BIN" << 'AGENT'
#!/usr/bin/env bash
LOGFLOW_URL="__RECEIVE_URL__"
API_KEY="__API_KEY__"
HOST=$(hostname -f 2>/dev/null || hostname)
BATCH=20
Q=()

flush() {
    [ ${#Q[@]} -eq 0 ] && return
    printf '%s\n' "${Q[@]}" | paste -sd, - | { read p; curl -sf -X POST "$LOGFLOW_URL" \
        -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
        -d "[$p]" > /dev/null 2>&1; }
    Q=()
}

parse() {
    local l="$1" prog pid msg sev=6
    local _re='[A-Z][a-z]{2} +[0-9]+ +[0-9:]+ +[^ ]+ +([a-zA-Z0-9_/.-]+)(\[([0-9]+)\])?: +(.*)'
    if [[ "$l" =~ $_re ]]; then
        prog="${BASH_REMATCH[1]}"; pid="${BASH_REMATCH[3]}"; msg="${BASH_REMATCH[4]}"
    else prog="syslog"; msg="$l"; fi
    grep -qiE "fail|invalid|denied|refused|blocked|brute|error" <<< "$msg" && sev=3
    grep -qiE "warn" <<< "$msg" && [ $sev -eq 6 ] && sev=4
    msg="${msg//\\/\\\\}"; msg="${msg//\"/\\\"}"; [ ${#msg} -gt 800 ] && msg="${msg:0:800}..."
    Q+=("{\"host\":\"$HOST\",\"program\":\"${prog:-syslog}\",\"pid\":\"$pid\",\"severity\":$sev,\"message\":\"$msg\",\"os\":\"linux\"}")
    [ ${#Q[@]} -ge $BATCH ] && flush
}

FILES=(__FILES__)
EX=(); for f in "${FILES[@]}"; do [ -r "$f" ] && EX+=("$f"); done
[ ${#EX[@]} -eq 0 ] && { echo "Aucun log lisible" >&2; exit 1; }

tail -Fq -n 0 "${EX[@]}" 2>/dev/null | while IFS= read -r line; do parse "$line"; flush; done
AGENT

chmod +x "$AGENT_BIN"
sed -i "s|__RECEIVE_URL__|$LOGFLOW_URL|g"  "$AGENT_BIN"
sed -i "s|__API_KEY__|$API_KEY|g"          "$AGENT_BIN"
STR=""; for f in "${LOG_FILES[@]}"; do STR="$STR \"$f\""; done
sed -i "s|__FILES__|${STR}|g"              "$AGENT_BIN"

cat > "$SERVICE_FILE" << 'SVC'
[Unit]
Description=LogFlow Agent
After=network.target
[Service]
Type=simple
ExecStart=/usr/local/bin/logflow-agent
Restart=on-failure
RestartSec=10
[Install]
WantedBy=multi-user.target
SVC

systemctl daemon-reload && systemctl enable --now logflow-agent
echo "Service démarré."

R=$(curl -sf -X POST "$LOGFLOW_URL" -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
    -d "{\"host\":\"$HOSTNAME_VAL\",\"program\":\"install\",\"severity\":6,\"message\":\"Agent Linux installé\",\"os\":\"linux\"}" 2>&1)
grep -q '"ok":true' <<< "$R" && echo "Connexion OK" || echo "Vérifiez l'URL : __URL_BASE__"

echo ""
echo "Done ! journalctl -u logflow-agent -f"
BASH;

    return str_replace(
        ['__URL_BASE__', '__RECEIVE_URL__', '__API_KEY__', '__SRC_LABEL__', '__CANDIDATES__'],
        [$url, $url . '/api/receive.php', $key, $src_label, $candidates],
        $tpl
    );
}

// ─────────────────────────────────────────────────────────────
// MACOS
// ─────────────────────────────────────────────────────────────
function build_macos(string $url, string $key, array $sel, array $catalog): string
{
    $predicates = [];
    foreach ($sel as $k) {
        foreach ($catalog[$k] ?? [] as $p) {
            $predicates[] = $p;
        }
    }
    if (empty($predicates)) {
        $predicates = ['(process == "sshd")', '(process == "sudo")'];
    }
    $predicate = implode(" OR\n                 ", array_unique($predicates));
    $src_label = count($sel) . ' sources : ' . implode(', ', array_slice($sel, 0, 5)) . (count($sel) > 5 ? '…' : '');

    $tpl = <<<'BASH'
#!/usr/bin/env bash
# LogFlow Agent — macOS Installer
# Serveur : __URL_BASE__
set -e

LOGFLOW_URL="__RECEIVE_URL__"
API_KEY="__API_KEY__"
AGENT_BIN="/usr/local/bin/logflow-macos-agent"
PLIST="/Library/LaunchDaemons/com.logflow.agent.plist"
HOST_VAL=$(hostname -f 2>/dev/null || hostname)

echo "========================================"
echo " LogFlow Agent — macOS"
echo " __SRC_LABEL__"
echo "========================================"
[ "$(id -u)" -ne 0 ] && { echo "sudo requis."; exit 1; }
mkdir -p /usr/local/bin

cat > "$AGENT_BIN" << 'AGENT'
#!/usr/bin/env bash
LOGFLOW_URL="__RECEIVE_URL__"
API_KEY="__API_KEY__"
HOST=$(hostname -f 2>/dev/null || hostname)
BATCH=15; Q=()

flush() {
    [ ${#Q[@]} -eq 0 ] && return
    printf '%s\n' "${Q[@]}" | paste -sd, - | { read p; curl -sf -X POST "$LOGFLOW_URL" \
        -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
        -d "[$p]" > /dev/null 2>&1; }
    Q=()
}

parse() {
    local l="$1" prog msg sev=6
    prog=$(awk '{print $3}' <<< "$l" | sed 's/\[.*//')
    msg=$(sed 's/^[^:]*:[^:]*:[^:]*://' <<< "$l" | xargs)
    [ -z "$msg" ] && return
    grep -qiE "fail|denied|auth" <<< "$msg" && sev=3
    grep -qiE "warn" <<< "$msg" && [ $sev -eq 6 ] && sev=4
    msg="${msg//\\/\\\\}"; msg="${msg//\"/\\\"}"
    Q+=("{\"host\":\"$HOST\",\"program\":\"${prog:-system}\",\"severity\":$sev,\"message\":\"$msg\",\"os\":\"macos\"}")
    [ ${#Q[@]} -ge $BATCH ] && flush
}

log stream \
    --predicate '__PREDICATE__' \
    --style syslog --level info 2>/dev/null | while IFS= read -r line; do
    [[ "$line" == Filtering* ]] && continue
    [ -z "$line" ] && continue
    parse "$line"
done
AGENT

chmod +x "$AGENT_BIN"
sed -i '' "s|__RECEIVE_URL__|$LOGFLOW_URL|g" "$AGENT_BIN"
sed -i '' "s|__API_KEY__|$API_KEY|g"         "$AGENT_BIN"
echo "Agent installé."

cat > "$PLIST" << 'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>             <string>com.logflow.agent</string>
    <key>ProgramArguments</key>
    <array><string>/usr/local/bin/logflow-macos-agent</string></array>
    <key>RunAtLoad</key>         <true/>
    <key>KeepAlive</key>         <true/>
    <key>StandardErrorPath</key> <string>/var/log/logflow-agent.log</string>
    <key>StandardOutPath</key>   <string>/var/log/logflow-agent.log</string>
</dict>
</plist>
PLIST

launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "LaunchDaemon chargé."

R=$(curl -sf -X POST "$LOGFLOW_URL" -H "X-Api-Key: $API_KEY" -H "Content-Type: application/json" \
    -d "{\"host\":\"$HOST_VAL\",\"program\":\"install\",\"severity\":6,\"message\":\"Agent macOS installé\",\"os\":\"macos\"}" 2>&1)
grep -q '"ok":true' <<< "$R" && echo "Connexion OK" || echo "Vérifiez l'URL : __URL_BASE__"

echo ""
echo "Done ! tail -f /var/log/logflow-agent.log"
BASH;

    return str_replace(
        ['__URL_BASE__', '__RECEIVE_URL__', '__API_KEY__', '__SRC_LABEL__', '__PREDICATE__'],
        [$url, $url . '/api/receive.php', $key, $src_label, $predicate],
        $tpl
    );
}

// ─────────────────────────────────────────────────────────────
// WINDOWS
// ─────────────────────────────────────────────────────────────
function build_windows(string $url, string $key, array $sel, array $catalog, array $sev_map): string
{
    // Regrouper les EventIDs par journal
    $by_log = [];
    foreach ($sel as $k) {
        $def = $catalog[$k] ?? null;
        if (!$def || empty($def['ids'])) continue;
        $log = $def['log'];
        foreach ($def['ids'] as $id) {
            $by_log[$log][] = $id;
        }
    }
    foreach ($by_log as &$ids) { $ids = array_unique($ids); }
    unset($ids);

    $src_label = count($sel) . ' sources sélectionnées';

    // Construire le bloc SEV_MAP PS avec uniquement les IDs utilisés
    $all_ids = array_merge(...array_values($by_log ?: [[]]));
    $sev_entries = [];
    foreach (array_unique($all_ids) as $id) {
        $sev_entries[] = $id . '=' . ($sev_map[$id] ?? 5);
    }
    $sev_ps = '@{' . implode(';', $sev_entries) . '}';

    // Construire les blocs de collecte par journal
    $collect_blocks = '';
    foreach ($by_log as $log_name => $ids) {
        $ids_ps = '@(' . implode(',', $ids) . ')';
        $collect_blocks .= <<<PS

    # Journal : {$log_name}
    try {
        \$ev_{$log_name} = Get-WinEvent -FilterHashtable @{LogName='{$log_name}';Id={$ids_ps};StartTime=\$since} -MaxEvents \$MAX -EA SilentlyContinue
        foreach (\$ev in \$ev_{$log_name}) {
            \$sev = if (\$SEV.ContainsKey(\$ev.Id)) { \$SEV[\$ev.Id] } else { 5 }
            \$msg = "EventID:\$(\$ev.Id) | \$(\$ev.Message -replace '\r?\n',' ' -replace '\s+',' ')"
            \$entries += @{host=\$Env:COMPUTERNAME;program='{$log_name}';severity=\$sev;os='windows';time=\$ev.TimeCreated.ToString('yyyy-MM-dd HH:mm:ss');message=\$msg.Substring(0,[Math]::Min(\$msg.Length,1000))}
        }
    } catch {}
PS;
    }
    // Nettoyer les noms de variables PS invalides (slashes)
    $collect_blocks = preg_replace_callback(
        '/\$ev_([^\s=]+)/',
        fn($m) => '$ev_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $m[1]),
        $collect_blocks
    );

    // Politiques d'audit
    $audit_lines = [];
    if (array_intersect($sel, ['logon_ok','logon_fail','explicit_creds','account_lockout']))
        $audit_lines[] = '    auditpol /set /subcategory:"Logon" /success:enable /failure:enable 2>&1|Out-Null';
    if (array_intersect($sel, ['account_created','account_modified','group_membership']))
        $audit_lines[] = '    auditpol /set /subcategory:"Account Management" /success:enable /failure:enable 2>&1|Out-Null';
    if (array_intersect($sel, ['process_create']))
        $audit_lines[] = '    auditpol /set /subcategory:"Process Creation" /success:enable 2>&1|Out-Null';
    if (array_intersect($sel, ['scheduled_task']))
        $audit_lines[] = '    auditpol /set /subcategory:"Other Object Access Events" /success:enable 2>&1|Out-Null';
    if (array_intersect($sel, ['firewall_change']))
        $audit_lines[] = '    auditpol /set /subcategory:"Filtering Platform Policy Change" /success:enable /failure:enable 2>&1|Out-Null';
    $audit_policy = implode("\n", $audit_lines) ?: '    # Aucune politique supplémentaire';

    $tpl = <<<'PS'
#Requires -RunAsAdministrator
# LogFlow Agent — Windows
# Serveur : __URL_BASE__
# Sources : __SRC_LABEL__
# Usage   : PowerShell Admin > .\logflow-install.ps1
#           ou : irm "__URL_BASE__/agents/download.php?os=windows" | iex

$LOGFLOW_URL = "__RECEIVE_URL__"
$API_KEY     = "__API_KEY__"
$AGENT_DIR   = "C:\Program Files\LogFlow"
$AGENT_PATH  = "$AGENT_DIR\logflow-agent.ps1"
$TASK_NAME   = "LogFlowAgent"
$LOG_DIR     = "C:\ProgramData\LogFlow"

Write-Host "LogFlow Agent — Windows" -ForegroundColor Cyan
Write-Host "Sources : __SRC_LABEL__" -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $AGENT_DIR | Out-Null
New-Item -ItemType Directory -Force -Path $LOG_DIR   | Out-Null

try {
__AUDIT_POLICY__
    Write-Host "Audit configure." -ForegroundColor Green
} catch { Write-Host "Audit non configure (non bloquant)." -ForegroundColor Yellow }

$AgentCode = @'
$LOGFLOW_URL = "__RECEIVE_URL__"
$API_KEY     = "__API_KEY__"
$POLL        = 30
$MAX         = 100
$LOG_FILE    = "C:\ProgramData\LogFlow\logflow-agent.log"
$SEV         = __SEV_MAP__

function Log($m) { "[$([datetime]::Now.ToString('yyyy-MM-dd HH:mm:ss'))] $m" | Add-Content $LOG_FILE }
function Send($e) {
    if ($e.Count -eq 0) { return }
    $b = $e | ConvertTo-Json -Compress
    if ($e.Count -eq 1) { $b = "[$b]" }
    try { Invoke-RestMethod -Uri $LOGFLOW_URL -Method POST -Body $b -ContentType "application/json" -Headers @{"X-Api-Key"=$API_KEY} | Out-Null }
    catch { Log "Erreur: $_" }
}

Log "Agent démarré sur $Env:COMPUTERNAME"
$last = (Get-Date).AddSeconds(-$POLL)

while ($true) {
    $since = $last; $last = Get-Date; $entries = @()
__COLLECT_BLOCKS__
    if ($entries.Count -gt 0) { Send $entries; Log "$($entries.Count) events" }
    Start-Sleep -Seconds $POLL
}
'@

$AgentCode = $AgentCode -replace '__RECEIVE_URL__',$LOGFLOW_URL -replace '__API_KEY__',$API_KEY
Set-Content -Path $AGENT_PATH -Value $AgentCode -Encoding UTF8
Write-Host "Agent ecrit." -ForegroundColor Green

$act  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NonInteractive -WindowStyle Hidden -File `"$AGENT_PATH`""
$trig = New-ScheduledTaskTrigger -AtStartup
$set  = New-ScheduledTaskSettingsSet -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 2) -ExecutionTimeLimit ([TimeSpan]::Zero)
$prin = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
Unregister-ScheduledTask -TaskName $TASK_NAME -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TASK_NAME -Action $act -Trigger $trig -Settings $set -Principal $prin -Force | Out-Null
Start-ScheduledTask -TaskName $TASK_NAME
Write-Host "Tache planifiee active." -ForegroundColor Green

try {
    $r = Invoke-RestMethod -Uri $LOGFLOW_URL -Method POST -Headers @{"X-Api-Key"=$API_KEY} -ContentType "application/json" `
        -Body "{`"host`":`"$Env:COMPUTERNAME`",`"program`":`"install`",`"severity`":6,`"message`":`"Agent Windows installe`",`"os`":`"windows`"}"
    if ($r.ok) { Write-Host "Connexion OK" -ForegroundColor Green }
} catch { Write-Host "Erreur connexion : __URL_BASE__" -ForegroundColor Red }

Write-Host ""
Write-Host "Done ! C:\ProgramData\LogFlow\logflow-agent.log"
Write-Host "Dashboard : __URL_BASE__"
Write-Host "Supprimer : Unregister-ScheduledTask -TaskName LogFlowAgent -Confirm:`$false"
PS;

    return str_replace(
        ['__URL_BASE__', '__RECEIVE_URL__', '__API_KEY__', '__SRC_LABEL__',
         '__AUDIT_POLICY__', '__SEV_MAP__', '__COLLECT_BLOCKS__'],
        [$url, $url . '/api/receive.php', $key, $src_label,
         $audit_policy, $sev_ps, $collect_blocks],
        $tpl
    );
}
