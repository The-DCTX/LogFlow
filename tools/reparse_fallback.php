<?php
// Outil ponctuel (CLI) — répare les logs syslog tombés en repli (host = IP source)
// en re-parsant leur message avec la logique corrigée. Idempotent et sûr : ne
// modifie une ligne que si un VRAI nom d'hôte (différent de l'IP) est extrait.
// Usage : php tools/reparse_fallback.php [--apply] [--limit=N]
//   sans --apply : simulation (dry-run, n'écrit rien).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/syslog_parse.php';

$apply = in_array('--apply', $argv, true);
$limit = 100000;
foreach ($argv as $a) if (str_starts_with($a, '--limit=')) $limit = max(1, (int)substr($a, 8));

$db = db();
$sel = $db->prepare("SELECT id, source_ip, program, message FROM logs
                     WHERE source='syslog' AND host = source_ip ORDER BY id ASC LIMIT ?");
$sel->bindValue(1, $limit, PDO::PARAM_INT);
$sel->execute();
$rows = $sel->fetchAll();

$upd = $db->prepare("UPDATE logs SET host=?, program=?, message=? WHERE id=?");
$scanned = 0; $fixed = 0; $sample = [];
foreach ($rows as $r) {
    $scanned++;
    $p = parse_syslog((string)$r['message'], (string)$r['source_ip']);
    if (!$p) continue;
    $newHost = $p['cols'][1];
    $newProg = $p['cols'][5];
    $newMsg  = $p['cols'][7];
    // On ne répare que si un vrai hostname (≠ IP) a pu être extrait.
    if ($newHost === '' || strcasecmp($newHost, (string)$r['source_ip']) === 0) continue;
    $fixed++;
    if (count($sample) < 5) $sample[] = "#{$r['id']}  {$r['source_ip']} → host={$newHost} prog={$newProg}";
    if ($apply) $upd->execute([$newHost, $newProg, $newMsg, $r['id']]);
}

echo ($apply ? "[APPLIQUÉ] " : "[SIMULATION] ") . "scannés=$scanned, réparables=$fixed\n";
foreach ($sample as $s) echo "  $s\n";
if (!$apply && $fixed) echo "→ relancer avec --apply pour écrire les corrections.\n";
