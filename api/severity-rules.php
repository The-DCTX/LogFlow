<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

if (!function_exists('json_success')) {
    function json_success($data = [], string $msg = 'OK'): void {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $msg, 'data' => $data]); exit;
    }
}
if (!function_exists('json_error')) {
    function json_error(string $msg, int $code = 400): void {
        http_response_code($code); header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]); exit;
    }
}

header('Content-Type: application/json');
$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $_GET['action'] ?? $raw['action'] ?? '';

function sev_ok($s): bool { return is_numeric($s) && (int)$s >= 0 && (int)$s <= 7; }

if ($method === 'GET') {
    $ov = [];
    foreach ($db->query("SELECT id, match_val, severity FROM severity_rules WHERE kind='sec_event'")->fetchAll() as $r)
        $ov[$r['match_val']] = $r;
    $sec = [];
    foreach (SEC_EVENTS as $k => $d) {
        $sec[] = [
            'key' => $k, 'label' => $d['label'], 'icon' => $d['icon'],
            'default' => $d['severity'] ?? null,
            'severity' => $ov[$k]['severity'] ?? ($d['severity'] ?? 6),
            'overridden' => isset($ov[$k]),
            'rule_id' => $ov[$k]['id'] ?? null,
        ];
    }
    $rules = $db->query("SELECT * FROM severity_rules WHERE kind IN ('program','message') ORDER BY sort_order, id")->fetchAll();
    json_success(['sec_events' => $sec, 'rules' => $rules, 'severities' => SEVERITIES]);
}

if ($method === 'POST' && $action === 'recalc') {
    $updated = 0;
    foreach ($db->query("SELECT kind, match_val, severity FROM severity_rules WHERE enabled=1 ORDER BY sort_order, id")->fetchAll() as $r) {
        $sev = (int)$r['severity'];
        if ($r['kind'] === 'sec_event') {
            $st = $db->prepare("UPDATE logs SET severity=? WHERE sec_event=?"); $st->execute([$sev, $r['match_val']]);
        } elseif ($r['kind'] === 'program') {
            $st = $db->prepare("UPDATE logs SET severity=? WHERE program LIKE CONCAT('%',?,'%')"); $st->execute([$sev, $r['match_val']]);
        } else {
            $st = $db->prepare("UPDATE logs SET severity=? WHERE message REGEXP ?");
            try { $st->execute([$sev, $r['match_val']]); } catch (Throwable $e) { continue; }
        }
        $updated += $st->rowCount();
    }
    json_success(['updated' => $updated], "Historique recalculé ($updated lignes ajustées)");
}

if ($method === 'POST' && $action === 'set_sec_event') {
    $key = $raw['key'] ?? '';
    if (!array_key_exists($key, SEC_EVENTS)) json_error('Événement inconnu');
    if (!sev_ok($raw['severity'] ?? null)) json_error('Sévérité invalide');
    $sev = (int)$raw['severity'];
    $ex = $db->prepare("SELECT id FROM severity_rules WHERE kind='sec_event' AND match_val=?"); $ex->execute([$key]);
    if ($id = $ex->fetchColumn()) {
        $db->prepare("UPDATE severity_rules SET severity=?, enabled=1 WHERE id=?")->execute([$sev, $id]);
    } else {
        $db->prepare("INSERT INTO severity_rules (kind,match_val,severity,label,sort_order) VALUES ('sec_event',?,?,?,50)")
           ->execute([$key, $sev, SEC_EVENTS[$key]['label'] ?? $key]);
    }
    json_success([], 'Sévérité mise à jour');
}

if ($method === 'POST') {                         // règle custom
    $kind = in_array($raw['kind'] ?? '', ['program','message'], true) ? $raw['kind'] : null;
    $mv   = trim($raw['match_val'] ?? '');
    if (!$kind)        json_error('Type invalide');
    if ($mv === '')    json_error('Valeur obligatoire');
    if (!sev_ok($raw['severity'] ?? null)) json_error('Sévérité invalide');
    if ($kind === 'message' && @preg_match('~' . str_replace('~','\~',$mv) . '~', '') === false) json_error('Expression régulière invalide');
    $db->prepare("INSERT INTO severity_rules (kind,match_val,severity,label,sort_order) VALUES (?,?,?,?,100)")
       ->execute([$kind, $mv, (int)$raw['severity'], trim($raw['label'] ?? '') ?: null]);
    json_success(['id' => (int)$db->lastInsertId()], 'Règle ajoutée');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    if (!sev_ok($raw['severity'] ?? null)) json_error('Sévérité invalide');
    $db->prepare("UPDATE severity_rules SET severity=?, enabled=? WHERE id=?")
       ->execute([(int)$raw['severity'], isset($raw['enabled']) ? (int)$raw['enabled'] : 1, $id]);
    json_success([], 'Règle mise à jour');
}

if ($method === 'DELETE') {
    $db->prepare("DELETE FROM severity_rules WHERE id=?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Règle supprimée');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
