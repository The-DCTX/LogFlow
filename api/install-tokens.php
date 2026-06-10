<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'GET') {
    $rows = $db->query("SELECT id, token, label, api_key_id, created_at, last_used, expires_at, revoked
                        FROM install_tokens ORDER BY revoked, id DESC")->fetchAll();
    echo json_encode(['ok' => true, 'tokens' => $rows]);
    exit;
}

if ($method === 'POST' && ($raw['action'] ?? '') === 'revoke') {
    $db->prepare("UPDATE install_tokens SET revoked = 1 WHERE id = ?")
       ->execute([(int)($raw['id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST') {                                   // créer un token
    $label  = trim($raw['label'] ?? '') ?: null;
    $key_id = (int)($raw['api_key_id'] ?? 0) ?: null;
    $exp    = trim($raw['expires_at'] ?? '') ?: null;        // 'YYYY-MM-DD HH:MM:SS' ou null
    $token  = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO install_tokens (token, label, api_key_id, expires_at)
                  VALUES (?, ?, ?, ?)")
       ->execute([$token, $label, $key_id, $exp]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId(), 'token' => $token]);
    exit;
}

if ($method === 'DELETE') {
    $db->prepare("DELETE FROM install_tokens WHERE id = ?")
       ->execute([(int)($raw['id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
