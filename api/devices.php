<?php
// CRUD du registre d'appareils (table devices). Auth de session requise.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
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
if ($method !== 'GET') csrf_check();                        // anti-CSRF sur les écritures
$raw    = json_decode(file_get_contents('php://input'), true) ?: [];

function clean_device(array $r): array {
    $type = array_key_exists($r['type'] ?? '', DEVICE_TYPES) ? $r['type'] : 'other';
    $mt   = in_array($r['match_type'] ?? '', ['host', 'ip'], true) ? $r['match_type'] : 'host';
    $mv   = trim($r['match_value'] ?? '');
    if ($mt === 'ip' && $mv !== '' && !filter_var($mv, FILTER_VALIDATE_IP)) {
        json_error('Adresse IP invalide');
    }
    return [
        'match_type'  => $mt,
        'match_value' => substr($mv, 0, 255),
        'name'        => substr(trim($r['name'] ?? ''), 0, 120),
        'type'        => $type,
        'vendor'      => substr(trim($r['vendor'] ?? ''), 0, 60) ?: null,
        'icon'        => substr(trim($r['icon'] ?? ''), 0, 40) ?: null,
        'notes'       => substr(trim($r['notes'] ?? ''), 0, 255) ?: null,
    ];
}

if ($method === 'GET') {
    json_success(['devices' => all_devices(), 'types' => DEVICE_TYPES]);
}

if ($method === 'POST') {
    $d = clean_device($raw);
    if ($d['match_value'] === '') json_error('Host / IP obligatoire');
    if ($d['name'] === '')        json_error('Nom obligatoire');
    try {
        $db->prepare("INSERT INTO devices (match_type,match_value,name,type,vendor,icon,notes)
                      VALUES (:match_type,:match_value,:name,:type,:vendor,:icon,:notes)")->execute($d);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') json_error('Ce host/IP est déjà associé à un appareil', 409);
        throw $e;
    }
    json_success(['id' => (int)$db->lastInsertId()], 'Appareil ajouté');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $d = clean_device($raw);
    if ($d['match_value'] === '') json_error('Host / IP obligatoire');
    if ($d['name'] === '')        json_error('Nom obligatoire');
    try {
        $st = $db->prepare("UPDATE devices SET match_type=:match_type, match_value=:match_value,
                            name=:name, type=:type, vendor=:vendor, icon=:icon, notes=:notes WHERE id=:id");
        $st->execute($d + ['id' => $id]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') json_error('Ce host/IP est déjà associé à un autre appareil', 409);
        throw $e;
    }
    json_success([], 'Appareil mis à jour');
}

if ($method === 'DELETE') {
    $db->prepare("DELETE FROM devices WHERE id=?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Appareil supprimé');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
