<?php
// CVE — acquittement des détections + gestion des signatures. Auth + CSRF.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

function json_success($data = [], string $msg = 'OK'): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msg, 'data' => $data]); exit;
}
function json_error(string $msg, int $code = 400): void {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]); exit;
}

header('Content-Type: application/json');
$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') csrf_check();
$raw    = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $raw['action'] ?? '';

// Valide qu'un motif compile avec le délimiteur « ~ » (cf. includes/cve.php).
function cve_pattern_ok(string $p): bool {
    return $p !== '' && strlen($p) <= 255 && @preg_match('~' . $p . '~i', '') !== false;
}

if ($method === 'POST' && $action === 'ack') {
    $cve = trim((string)($raw['cve_id'] ?? ''));
    $host = trim((string)($raw['host'] ?? ''));
    if ($cve === '') json_error('CVE manquant');
    $db->prepare("INSERT IGNORE INTO cve_acks (cve_id, host) VALUES (?, ?)")->execute([$cve, $host]);
    json_success([], 'Détection marquée comme traitée');
}

if ($method === 'POST' && $action === 'toggle') {
    $db->prepare("UPDATE cve_signatures SET enabled = 1 - enabled WHERE id = ?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Signature mise à jour');
}

if ($method === 'POST' && $action === 'add') {
    $cve = trim((string)($raw['cve_id'] ?? ''));
    if (!preg_match('/^CVE-\d{4}-\d{4,}$/i', $cve)) json_error('Identifiant CVE invalide (format CVE-AAAA-NNNN)');
    $pattern = trim((string)($raw['pattern'] ?? ''));
    if (!cve_pattern_ok($pattern)) json_error('Motif (regex) invalide');
    $cvss = (float)($raw['cvss'] ?? 0);
    if ($cvss < 0 || $cvss > 10) json_error('CVSS hors plage (0-10)');
    $db->prepare(
        "INSERT INTO cve_signatures (cve_id, name, pattern, cvss, remediation, source)
         VALUES (?,?,?,?,?, 'manual')
         ON DUPLICATE KEY UPDATE name=VALUES(name), cvss=VALUES(cvss), remediation=VALUES(remediation), enabled=1"
    )->execute([strtoupper($cve), substr(trim((string)($raw['name'] ?? '')),0,120), $pattern, $cvss, substr(trim((string)($raw['remediation'] ?? '')),0,500)]);
    json_success(['id' => (int)$db->lastInsertId()], 'Signature enregistrée');
}

if ($method === 'DELETE') {
    // Les signatures intégrées ne sont pas supprimables (seulement désactivables).
    $db->prepare("DELETE FROM cve_signatures WHERE id = ? AND source = 'manual'")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Signature supprimée');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
