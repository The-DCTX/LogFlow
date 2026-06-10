<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db.php';

const DISCORD_COLORS = [
    0 => 0xED4245, 1 => 0xED4245, 2 => 0xED4245,
    3 => 0xF0883E, 4 => 0xFEE75C,
    5 => 0x5865F2, 6 => 0x57F287, 7 => 0xB9BBBE,
];
const DISCORD_SEV_EMOJI  = [0=>'🆘',1=>'🚨',2=>'🔴',3=>'🟠',4=>'🟡',5=>'🔵',6=>'🟢',7=>'⚪'];
const DISCORD_SEV_LABEL  = [0=>'EMERGENCY',1=>'ALERT',2=>'CRITICAL',3=>'ERROR',4=>'WARNING',5=>'NOTICE',6=>'INFO',7=>'DEBUG'];
const DISCORD_OS_ICON    = ['linux'=>'🐧','windows'=>'🪟','macos'=>'🍎','other'=>'💻'];

// ── Enfile une notification (non bloquant, pas de cURL ici) ───
function maybe_notify_discord(array $log): void
{
    $webhook = get_setting('discord_webhook_url', '');
    if (empty($webhook)) return;

    $min_sev  = (int)get_setting('discord_min_severity', '3');
    $cooldown = (int)get_setting('discord_cooldown', '5');
    $sec_only = (bool)(int)get_setting('discord_sec_events_only', '0');

    $sev = (int)($log['severity'] ?? 6);
    if ($sev > $min_sev) return;
    if ($sec_only && empty($log['sec_event'])) return;

    $event_key = !empty($log['sec_event']) ? $log['sec_event'] : 'sev_' . $sev;
    $host      = (string)($log['host'] ?? 'unknown');

    try {
        $db = db();
        // Vérifie cooldown ET file d'attente pour éviter les doublons
        $dup = $db->prepare(
            "SELECT 1 FROM discord_notifications
              WHERE host=? AND event_key=? AND notified_at > NOW()-INTERVAL ? MINUTE
             UNION ALL
             SELECT 1 FROM discord_queue
              WHERE host=? AND event_key=? AND queued_at > NOW()-INTERVAL ? MINUTE
             LIMIT 1"
        );
        $dup->execute([$host, $event_key, $cooldown, $host, $event_key, $cooldown]);
        if ($dup->fetch()) return;

        $db->prepare(
            "INSERT INTO discord_queue (host, event_key, program, severity, message, os, source_ip, sec_event)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $host, $event_key,
            substr($log['program'] ?? '', 0, 100),
            $sev,
            $log['message'] ?? '',
            $log['os'] ?? null,
            $log['source_ip'] ?? '',
            $log['sec_event'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[LogFlow/Discord] queue: ' . $e->getMessage());
    }
}

// ── Traitement de la file Discord (appelé en arrière-plan) ────
function process_discord_queue(int $limit = 20): void
{
    $lock = sys_get_temp_dir() . '/logflow_discord.lock';
    $fp   = @fopen($lock, 'w');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) return; // Worker déjà actif

    try {
        $db      = db();
        $webhook = get_setting('discord_webhook_url', '');
        if (empty($webhook)) return;

        $stmt = $db->prepare("SELECT * FROM discord_queue ORDER BY id LIMIT ?");
        $stmt->execute([$limit]);

        foreach ($stmt->fetchAll() as $row) {
            if (send_discord($webhook, $row)) {
                $db->prepare("INSERT IGNORE INTO discord_notifications (host, event_key) VALUES (?,?)")
                   ->execute([$row['host'], $row['event_key']]);
                $db->prepare("DELETE FROM discord_queue WHERE id=?")->execute([$row['id']]);
            } else {
                // Échec : incrémente, supprime après 3 tentatives
                $db->prepare("UPDATE discord_queue SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
                $db->prepare("DELETE FROM discord_queue WHERE id=? AND attempts>=3")->execute([$row['id']]);
            }
        }
        // Purges périodiques
        $db->exec("DELETE FROM discord_queue WHERE queued_at < NOW()-INTERVAL 2 HOUR");
        $db->exec("DELETE FROM discord_notifications WHERE notified_at < NOW()-INTERVAL 24 HOUR");
    } catch (\Throwable $e) {
        error_log('[LogFlow/Discord] worker: ' . $e->getMessage());
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ── Envoi effectif d'un embed Discord ─────────────────────────
function send_discord(string $url, array $log, bool $is_test = false): bool
{
    $sev     = (int)($log['severity'] ?? 6);
    $color   = DISCORD_COLORS[$sev]    ?? 0xB9BBBE;
    $emoji   = DISCORD_SEV_EMOJI[$sev] ?? '⚪';
    $label   = DISCORD_SEV_LABEL[$sev] ?? (string)$sev;
    $os_icon = DISCORD_OS_ICON[$log['os'] ?? ''] ?? '💻';

    $host    = (string)($log['host']    ?? 'unknown');
    $program = (string)($log['program'] ?? '—');
    $message = (string)($log['message'] ?? '');
    $sec     = $log['sec_event'] ?? null;

    $title = $is_test
        ? '✅ LogFlow — Test webhook Discord'
        : "{$emoji} {$label} — {$host}";

    $desc = mb_strlen($message) > 1500
        ? mb_substr($message, 0, 1500) . '…'
        : $message;

    $fields = [
        ['name' => 'Hôte',      'value' => '`'.$host.'`',    'inline' => true],
        ['name' => 'Programme', 'value' => '`'.$program.'`', 'inline' => true],
        ['name' => 'OS',        'value' => $os_icon.' '.($log['os'] ?? '—'), 'inline' => true],
        ['name' => 'Sévérité',  'value' => $label.' ('.$sev.')', 'inline' => true],
    ];
    if (!empty($log['source_ip']) && $log['source_ip'] !== '127.0.0.1')
        $fields[] = ['name' => 'IP source', 'value' => '`'.$log['source_ip'].'`', 'inline' => true];
    if (!empty($sec))
        $fields[] = ['name' => '🔒 Événement sécurité', 'value' => '`'.$sec.'`', 'inline' => true];

    $server_url = rtrim(get_setting('server_url', ''), '/');
    $payload = [
        'username' => 'LogFlow',
        'embeds'   => [[
            'title'       => $title,
            'description' => $desc ?: '*(message vide)*',
            'color'       => $color,
            'fields'      => $fields,
            'footer'      => ['text' => 'LogFlow Security Monitor'],
            'timestamp'   => date('c'),
            'url'         => $server_url ? $server_url . '/logs.php' : '',
        ]],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code === 204 || $code === 200;
}
