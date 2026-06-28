<?php
// Gestion des agents enregistrés (= clés API + leurs tokens d'enrôlement).
// Auth de session requise. Suppression SÉCURISÉE : purge transactionnelle des
// tokens d'installation liés AVANT de supprimer la clé → aucun token réutilisable.
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
if ($method !== 'GET') csrf_check();                        // anti-CSRF sur les écritures
$raw    = json_decode(file_get_contents('php://input'), true) ?: [];

if ($method === 'GET') {
    $rows = $db->query("
        SELECT k.id, k.name, k.api_key, k.created_at, k.last_used,
               (SELECT COUNT(*) FROM install_tokens t WHERE t.api_key_id = k.id) AS tokens_total,
               (SELECT COUNT(*) FROM install_tokens t WHERE t.api_key_id = k.id
                       AND t.revoked = 0 AND (t.expires_at IS NULL OR t.expires_at > NOW())) AS tokens_active
        FROM api_keys k ORDER BY k.id")->fetchAll();
    json_success(['agents' => $rows]);
}

if ($method === 'POST') {                                   // créer un agent (nouvelle clé)
    $name = trim($raw['name'] ?? '');
    if ($name === '') json_error('Nom obligatoire');
    $key = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO api_keys (name, api_key) VALUES (?, ?)")->execute([substr($name, 0, 100), $key]);
    json_success(['id' => (int)$db->lastInsertId(), 'api_key' => $key], 'Agent créé');
}

if ($method === 'PUT') {                                    // renommer un agent
    $id   = (int)($raw['id'] ?? 0);
    $name = trim($raw['name'] ?? '');
    if (!$id)          json_error('ID manquant');
    if ($name === '')  json_error('Nom obligatoire');
    $db->prepare("UPDATE api_keys SET name = ? WHERE id = ?")->execute([substr($name, 0, 100), $id]);
    json_success([], 'Agent renommé');
}

if ($method === 'DELETE') {                                 // suppression sécurisée
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $db->beginTransaction();
    try {
        // 1) Révoque puis supprime TOUS les tokens d'enrôlement de cet agent :
        //    une fois la ligne supprimée, download.php ne peut plus la valider
        //    (WHERE token=? AND revoked=0) → le token ne sert plus à rien.
        $db->prepare("UPDATE install_tokens SET revoked = 1 WHERE api_key_id = ?")->execute([$id]);
        $delTok = $db->prepare("DELETE FROM install_tokens WHERE api_key_id = ?");
        $delTok->execute([$id]);
        $tokensPurged = $delTok->rowCount();

        // 2) Supprime la clé API → l'agent déployé ne peut plus pousser de logs
        //    (receive.php renvoie 403 : clé invalide).
        $delKey = $db->prepare("DELETE FROM api_keys WHERE id = ?");
        $delKey->execute([$id]);
        if ($delKey->rowCount() === 0) { $db->rollBack(); json_error('Agent introuvable', 404); }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        json_error('Échec de la suppression : ' . $e->getMessage(), 500);
    }
    json_success(['tokens_purged' => $tokensPurged],
        "Agent supprimé · $tokensPurged token(s) d'enrôlement révoqué(s) et purgé(s)");
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
