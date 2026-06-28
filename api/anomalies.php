<?php
// Anomalies — liste + acquittement. Auth de session + CSRF sur les écritures.
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

if ($method === 'GET') {
    $status = ($_GET['status'] ?? 'open');
    $where  = $status === 'all' ? '1=1' : 'status = ' . $db->quote($status);
    $rows = $db->query("SELECT * FROM anomalies WHERE $where ORDER BY (status='open') DESC, updated_at DESC LIMIT 300")->fetchAll();
    $open = (int) $db->query("SELECT COUNT(*) FROM anomalies WHERE status='open'")->fetchColumn();
    json_success(['anomalies' => $rows, 'open' => $open]);
}

if ($method === 'POST' && $action === 'ack') {
    $db->prepare("UPDATE anomalies SET status='ack' WHERE id=?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Anomalie acquittée');
}
if ($method === 'POST' && $action === 'ack_all') {
    $db->query("UPDATE anomalies SET status='ack' WHERE status='open'");
    json_success([], 'Toutes les anomalies acquittées');
}
if ($method === 'DELETE') {
    $db->prepare("DELETE FROM anomalies WHERE id=?")->execute([(int)($raw['id'] ?? 0)]);
    json_success([], 'Anomalie supprimée');
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
