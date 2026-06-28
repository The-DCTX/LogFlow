<?php
// Protection CSRF partagée pour les endpoints authentifiés par session.
// Le token est lié à la session ; il est exposé aux pages (meta + window.CSRF
// dans header.php) et renvoyé par le JS dans l'en-tête X-CSRF-Token.

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// À appeler en tête des endpoints API sur toute méthode non-GET.
// Échoue de manière fermée (403) si le token est absent ou ne correspond pas.
function csrf_check(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide ou manquant']);
        exit;
    }
}
