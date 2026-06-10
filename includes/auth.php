<?php
// Middleware d'authentification — inclure en tête de chaque page protégée
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logflow_user'])) {
    session_write_close();
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
