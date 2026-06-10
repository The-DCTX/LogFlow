<?php
// Worker Discord — exécuté via exec() en arrière-plan ou via cron
// Exemple cron : * * * * * php /var/www/logflow/api/discord_worker.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Accessible uniquement en CLI'); }
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/settings.php';
require_once 'includes/discord.php';

process_discord_queue(20);
