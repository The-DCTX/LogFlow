<?php
// Apprentissage du parser — gabarits découverts + règles de classification.
// Auth de session + CSRF requis sur les écritures. Les motifs sont validés et
// ne sont JAMAIS exécutés comme du code (uniquement utilisés comme regex testée).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/templating.php';
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

// Valide qu'un motif compile avec le délimiteur « / » (et borne le ReDoS via @).
function valid_pattern(string $p): bool {
    if ($p === '' || strlen($p) > 255) return false;
    return @preg_match('/' . $p . '/i', '') !== false;
}

if ($method === 'GET') {
    $onlyUnclassified = ($_GET['unclassified'] ?? '') === '1';
    $where = "status != 'ignored'";
    if ($onlyUnclassified) $where .= " AND sec_event IS NULL";
    $templates = $db->query(
        "SELECT id, program, template, example, count, sec_event, status, last_seen
         FROM log_templates WHERE $where ORDER BY (sec_event IS NULL) DESC, count DESC LIMIT 300"
    )->fetchAll();
    $rules = $db->query(
        "SELECT id, label, program_match, pattern, sec_event, enabled, source, created_at
         FROM parse_rules ORDER BY id DESC"
    )->fetchAll();
    $secList = [];
    foreach (SEC_EVENTS as $k => $d) $secList[$k] = ['label' => $d['label'], 'icon' => $d['icon'] ?? '', 'severity' => $d['severity'] ?? null];
    json_success(['templates' => $templates, 'rules' => $rules, 'sec_events' => $secList]);
}

if ($method === 'POST' && $action === 'analyze') {       // analyse à la demande (bornée)
    $res = learn_templates((int)($raw['limit'] ?? 5000));
    json_success($res, "Analyse : {$res['processed']} log(s), {$res['templates']} gabarit(s)");
}

if ($method === 'POST' && in_array($action, ['ignore', 'reset'], true)) {
    $st = $action === 'ignore' ? 'ignored' : 'new';
    $db->prepare("UPDATE log_templates SET status = ? WHERE id = ?")->execute([$st, (int)($raw['id'] ?? 0)]);
    json_success([], $action === 'ignore' ? 'Gabarit ignoré' : 'Gabarit réactivé');
}

if ($method === 'POST' && $action === 'promote') {       // crée une règle de classification
    $sec = (string)($raw['sec_event'] ?? '');
    if (!isset(SEC_EVENTS[$sec]))               json_error('Type d\'événement inconnu');
    $pattern = trim((string)($raw['pattern'] ?? ''));
    if (!valid_pattern($pattern))               json_error('Motif (regex) invalide');
    $prog  = substr(trim((string)($raw['program_match'] ?? '')), 0, 100) ?: null;
    $label = substr(trim((string)($raw['label'] ?? '')), 0, 120) ?: ('Appris : ' . $sec);
    $db->prepare("INSERT INTO parse_rules (label, program_match, pattern, sec_event, source)
                  VALUES (?,?,?,?, 'suggested')")->execute([$label, $prog, $pattern, $sec]);
    if (!empty($raw['template_id'])) {
        $db->prepare("UPDATE log_templates SET status='reviewed', sec_event=? WHERE id=?")
           ->execute([$sec, (int)$raw['template_id']]);
    }
    json_success(['id' => (int)$db->lastInsertId()], 'Règle de classification créée');
}

if ($method === 'POST' && $action === 'toggle_rule') {
    $db->prepare("UPDATE parse_rules SET enabled = 1 - enabled WHERE id = ?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Règle mise à jour');
}

if ($method === 'DELETE') {
    $db->prepare("DELETE FROM parse_rules WHERE id = ?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Règle supprimée');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
