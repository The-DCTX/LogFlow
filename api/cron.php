<?php
// Cron LogFlow — purge des logs + traitement file Discord
// Ajouter en crontab : 0 3 * * * php /var/www/logflow/api/cron.php >> /var/log/logflow-cron.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Accessible uniquement en CLI'); }
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/settings.php';
require_once 'includes/discord.php';
require_once 'includes/templating.php';
require_once 'includes/anomalies.php';

$db        = db();
$retention = (int)get_setting('log_retention_days', (string)MAX_LOG_AGE_DAYS);

if ($retention > 0) {
    $stmt = $db->prepare("DELETE FROM logs WHERE received_at < NOW() - INTERVAL ? DAY");
    $stmt->execute([$retention]);
    $deleted = $stmt->rowCount();
    if ($deleted > 0) {
        echo date('Y-m-d H:i:s') . " — Purge: {$deleted} logs supprimés (> {$retention} jours)\n";
    }
}

// Traite les notifications Discord en attente
process_discord_queue(50);

// Apprentissage du parser (hors chemin d'ingestion) — borné, idempotent (filigrane).
try {
    $lt = learn_templates(20000);
    if (($lt['processed'] ?? 0) > 0) {
        echo date('Y-m-d H:i:s') . " — Apprentissage: {$lt['processed']} logs analysés, {$lt['templates']} gabarit(s)\n";
    }
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " — Apprentissage ÉCHEC: " . $e->getMessage() . "\n";
}

// Détection d'anomalies (brute-force, silence, nouvel hôte) — hors ingestion.
try {
    $an = detect_anomalies();
    if ($an) echo date('Y-m-d H:i:s') . " — Anomalies: " . count($an) . " nouvelle(s) — " . implode(', ', $an) . "\n";
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " — Anomalies ÉCHEC: " . $e->getMessage() . "\n";
}

echo date('Y-m-d H:i:s') . " — Cron OK\n";
