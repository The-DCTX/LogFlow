<?php
// Applique les migrations de schéma en attente (idempotent).
// Usage : php migrate.php   — appelé automatiquement par l'entrypoint Docker au démarrage.
// Réservé au CLI : ne fait rien (et n'expose rien) via HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/config.php';

$dir = __DIR__ . '/migrations';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate : connexion DB impossible — ' . $e->getMessage() . "\n");
    exit(1);
}

// Journal des migrations déjà appliquées.
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$done  = array_flip($pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN));
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);   // ordre lexical → préfixe numérique = ordre d'application

$applied = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($done[$version])) { continue; }

    $sql = trim((string) file_get_contents($file));

    // Pas de transaction : sous MariaDB le DDL (CREATE/ALTER) provoque un commit
    // implicite. Les migrations doivent donc être IDEMPOTENTES (IF NOT EXISTS…)
    // pour pouvoir être relancées sans risque après un échec partiel.
    try {
        if ($sql !== '') { $pdo->exec($sql); }
        $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$version]);
        fwrite(STDOUT, "migrate : appliqué $version\n");
        $applied++;
    } catch (Throwable $e) {
        fwrite(STDERR, "migrate : ÉCHEC sur $version — " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, $applied
    ? "migrate : $applied migration(s) appliquée(s).\n"
    : "migrate : schéma à jour, rien à faire.\n");
