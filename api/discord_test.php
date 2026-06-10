<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/discord.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

$webhook = trim($_POST['webhook'] ?? get_setting('discord_webhook_url', ''));
if (empty($webhook)) {
    echo json_encode(['ok' => false, 'error' => 'URL webhook vide']);
    exit;
}
$wh_host = parse_url($webhook, PHP_URL_HOST) ?: '';
if (strcasecmp($wh_host, 'discord.com') !== 0 && strcasecmp($wh_host, 'discordapp.com') !== 0
    && !preg_match('/\\.discord(app)?\\.com$/i', $wh_host)) {
    echo json_encode(['ok' => false, 'error' => 'URL webhook invalide : hôte Discord requis']);
    exit;
}

$ok = send_discord($webhook, [
    'host'      => gethostname() ?: 'logflow-server',
    'program'   => 'logflow-test',
    'severity'  => 3,
    'message'   => 'Ceci est un message de test LogFlow. Si vous voyez ce message, le webhook Discord est correctement configuré et les alertes seront bien reçues.',
    'os'        => 'linux',
    'source_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'sec_event' => null,
], true);

echo json_encode([
    'ok'    => $ok,
    'error' => $ok ? null : 'Requête Discord échouée — vérifiez l\'URL du webhook',
]);
