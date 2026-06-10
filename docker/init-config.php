<?php
// Initialise certains réglages depuis les variables d'environnement
// (déploiement Docker). Lancé par l'entrypoint au démarrage ; sans effet hors CLI.
if (PHP_SAPI !== 'cli') { exit; }

$url = getenv('SERVER_URL');
if (!$url) { exit; }

require __DIR__ . '/../config.php';
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS);
    $cur = $pdo->query("SELECT value FROM settings WHERE key_name = 'server_url'")->fetchColumn();
    // N'écrase pas une URL déjà personnalisée via l'interface Setup,
    // mais remplace les valeurs par défaut / placeholders connus.
    $defaults = ['', 'http://localhost', 'http://YOUR_SERVER_IP'];
    if ($cur === false || in_array($cur, $defaults, true)) {
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('server_url', ?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$url]);
        fwrite(STDOUT, "LogFlow : server_url initialisé à $url\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'LogFlow : init server_url ignoré (' . $e->getMessage() . ")\n");
}
