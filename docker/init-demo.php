<?php
// Purge optionnelle des données de DÉMONSTRATION au démarrage (déploiement Docker).
//
// Le jeu de démo (db/demo-data.sql) est chargé par MariaDB à la création du volume
// pour peupler le dashboard immédiatement. Pour déployer une instance qui repart
// À VIDE, mettre DEMO_DATA=0 (ou false/off/no) : ce script supprime alors les
// lignes de démo au 1er démarrage suivant.
//
// Défaut (variable absente ou valeur « vraie ») : démo CONSERVÉE, aucun effet.
// Lancé par l'entrypoint (CLI uniquement) ; sans effet via le web.

if (PHP_SAPI !== 'cli') { exit; }

$flag = strtolower(trim((string) getenv('DEMO_DATA')));
if ($flag === '') { exit; }                                  // non défini → démo conservée
if (!in_array($flag, ['0', 'false', 'off', 'no', 'none'], true)) { exit; } // valeur « vraie » → conservée

require __DIR__ . '/../config.php';

// Signature déterministe des lignes de démo (cf. db/demo-data.sql) :
// IP en 192.0.2.0/24 (TEST-NET-1 RFC 5737, jamais réelle) + hôtes fictifs connus.
// Aucune donnée réelle ne peut correspondre, donc la purge est sûre et idempotente.
$DEMO_HOSTS = ['win-dc01', 'web-01', 'db-01', 'fw-01', 'rpi-edge'];

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ph  = implode(',', array_fill(0, count($DEMO_HOSTS), '?'));
    $stmt = $pdo->prepare(
        "DELETE FROM logs WHERE source_ip LIKE '192.0.2.%' AND host IN ($ph)"
    );
    $stmt->execute($DEMO_HOSTS);
    $n = $stmt->rowCount();
    fwrite(STDOUT, "LogFlow : DEMO_DATA=$flag → $n ligne(s) de démo purgée(s).\n");
} catch (Throwable $e) {
    // Non bloquant : une purge ratée ne doit pas empêcher le démarrage.
    fwrite(STDERR, 'LogFlow : purge démo ignorée (' . $e->getMessage() . ")\n");
}
