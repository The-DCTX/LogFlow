<?php
// Cron LogFlow — purge des logs + traitement file Discord
// Ajouter en crontab : 0 3 * * * php /var/www/logflow/api/cron.php >> /var/log/logflow-cron.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Accessible uniquement en CLI'); }
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/settings.php';
require_once 'includes/discord.php';

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

echo date('Y-m-d H:i:s') . " — Cron OK\n";
