<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['logflow_user'])) { header('Location: /index.php'); exit; }

$error    = '';
$raw_next = $_GET['next'] ?? '/index.php';
$next     = filter_var($raw_next, FILTER_SANITIZE_URL);
if (!$next || !str_starts_with($next, '/')) $next = '/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stored_user = get_setting('auth_username', 'admin');
    $stored_hash = get_setting('auth_password_hash', '');

    if ($username === $stored_user && !empty($stored_hash) && password_verify($password, $stored_hash)) {
        session_regenerate_id(true);
        $_SESSION['logflow_user'] = $username;
        session_write_close();
        header('Location: ' . $next);
        exit;
    }
    error_log("[LF_LOGIN] usr=[" . (isset($username)?htmlspecialchars($username):"?") . "] hash_len=" . strlen($stored_hash ?? "") . " verify=" . (password_verify($password ?? "", $stored_hash ?? "") ? "YES" : "NO"));
    $error = 'Identifiants incorrects.';
    usleep(500_000); // Anti brute-force basique
}
?><!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(APP_NAME) ?> — Connexion</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
    html, body { height: 100%; }
    body { display: flex; align-items: center; justify-content: center; background: var(--bg); }
    .login-wrap { width: 100%; max-width: 380px; padding: 0 1rem; }
    .login-logo { text-align: center; margin-bottom: 2rem; }
    .login-logo h1 { font-size: 1.5rem; font-weight: 700; color: var(--text); margin-top: .6rem; letter-spacing: -.01em; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <svg width="44" height="44" fill="none" stroke="#58a6ff" stroke-width="1.8" viewBox="0 0 24 24">
            <path d="M3 12h18M3 6h18M3 18h18"/>
        </svg>
        <h1><?= h(APP_NAME) ?></h1>
        <div class="text-muted small">Security Log Monitor</div>
    </div>

    <div class="card border-secondary shadow-lg">
        <div class="card-body p-4">
            <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <span><?= h($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="/login.php?next=<?= urlencode($next) ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Identifiant</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-muted">
                            <i class="bi bi-person"></i>
                        </span>
                        <input type="text" name="username"
                               class="form-control bg-dark border-secondary text-light"
                               value="<?= h($_POST['username'] ?? '') ?>"
                               autofocus autocomplete="username" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-muted">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input type="password" name="password"
                               class="form-control bg-dark border-secondary text-light"
                               autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                </button>
            </form>
        </div>
    </div>

    <div class="text-center text-muted small mt-3">
        Identifiants par défaut : <code>admin</code> / <code>logflow2026</code><br>
        <span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Changer le mot de passe dans Setup &rsaquo; Sécurité</span>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
