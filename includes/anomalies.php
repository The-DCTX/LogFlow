<?php
// Détection d'anomalies — exécutée par api/cron.php (HORS chemin d'ingestion).
// Déterministe, seuils configurables, déduplication en code, notifications
// réutilisant Discord/email. Aucune action automatique autre qu'alerter.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/email.php';

// Crée une anomalie si aucune identique n'est déjà ouverte (sinon met à jour).
// Renvoie true seulement si une NOUVELLE anomalie a été créée (→ notifiée).
function raise_anomaly(PDO $db, string $type, string $host, string $ip, string $detail, int $observed, int $sev): bool {
    $ex = $db->prepare("SELECT id FROM anomalies WHERE type=? AND host=? AND source_ip=? AND status='open' LIMIT 1");
    $ex->execute([$type, $host, $ip]);
    if ($id = $ex->fetchColumn()) {
        $db->prepare("UPDATE anomalies SET observed=?, detail=?, updated_at=NOW() WHERE id=?")
           ->execute([$observed, $detail, (int)$id]);
        return false;
    }
    $db->prepare("INSERT INTO anomalies (type, host, source_ip, detail, observed, severity) VALUES (?,?,?,?,?,?)")
       ->execute([$type, $host, $ip, $detail, $observed, $sev]);
    $log = [
        'host' => $host !== '' ? $host : $ip, 'severity' => $sev, 'sec_event' => $type,
        'program' => 'logflow-anomaly', 'message' => $detail, 'os' => null, 'source_ip' => $ip,
    ];
    try { maybe_notify_discord($log); maybe_notify_email($log); } catch (\Throwable $e) {}
    return true;
}

function detect_anomalies(): array {
    if (!(int) get_setting('anomaly_enabled', '1')) return [];
    $db = db();
    $raised = [];

    // 1) Brute-force : ≥ N échecs d'authentification depuis une même IP en M minutes.
    $cnt = max(2, (int) get_setting('anomaly_bruteforce_count', '10'));
    $win = max(1, (int) get_setting('anomaly_bruteforce_window', '10'));
    $q = $db->prepare(
        "SELECT source_ip, MAX(host) host, COUNT(*) c FROM logs
         WHERE sec_event='auth_fail' AND source_ip <> '' AND received_at >= NOW() - INTERVAL ? MINUTE
         GROUP BY source_ip HAVING c >= ?"
    );
    $q->bindValue(1, $win, PDO::PARAM_INT);
    $q->bindValue(2, $cnt, PDO::PARAM_INT);
    $q->execute();
    foreach ($q->fetchAll() as $r) {
        if (raise_anomaly($db, 'brute_force', (string)$r['host'], (string)$r['source_ip'],
            "{$r['c']} échecs d'authentification en {$win} min depuis {$r['source_ip']}", (int)$r['c'], 2)) {
            $raised[] = 'brute_force ' . $r['source_ip'];
        }
    }

    // 2) Silence radio : un hôte habituellement actif (≥ 50 logs) muet depuis > seuil.
    $sil = max(5, (int) get_setting('anomaly_silence_minutes', '30'));
    $q = $db->prepare(
        "SELECT host, MAX(received_at) last, COUNT(*) total FROM logs
         WHERE host <> '' GROUP BY host
         HAVING total >= 50 AND last < NOW() - INTERVAL ? MINUTE AND last > NOW() - INTERVAL 2 DAY"
    );
    $q->bindValue(1, $sil, PDO::PARAM_INT);
    $q->execute();
    foreach ($q->fetchAll() as $r) {
        if (raise_anomaly($db, 'silence', (string)$r['host'], '',
            "Aucun log depuis {$r['last']} (silence > {$sil} min)", 0, 3)) {
            $raised[] = 'silence ' . $r['host'];
        }
    }
    // Auto-résolution : un hôte « silencieux » qui a re-loggé depuis l'alerte.
    // COLLATE explicite : `logs` et `anomalies` peuvent avoir des collations
    // différentes selon la version de MariaDB (uca1400 sur install neuve vs
    // unicode_ci) — on force la comparaison pour éviter « Illegal mix of collations ».
    $db->query(
        "UPDATE anomalies a SET a.status='resolved'
         WHERE a.type='silence' AND a.status='open'
           AND EXISTS (SELECT 1 FROM logs l
                       WHERE l.host = a.host COLLATE utf8mb4_unicode_ci AND l.received_at > a.updated_at)"
    );

    // 3) Nouvel hôte apparu depuis le dernier passage (informatif, non alerté par défaut).
    if ((int) get_setting('anomaly_newhost', '1')) {
        $lastRun = get_setting('anomaly_last_run', '');
        if ($lastRun !== '') {
            $q = $db->prepare(
                "SELECT host, MIN(received_at) first, MAX(source_ip) ip FROM logs
                 WHERE host <> '' GROUP BY host HAVING first > ?"
            );
            $q->execute([$lastRun]);
            foreach ($q->fetchAll() as $r) {
                if (raise_anomaly($db, 'new_host', (string)$r['host'], (string)$r['ip'],
                    "Nouvel hôte détecté : {$r['host']}", 0, 4)) {
                    $raised[] = 'new_host ' . $r['host'];
                }
            }
        }
    }

    set_setting('anomaly_last_run', date('Y-m-d H:i:s'));
    return $raised;
}
