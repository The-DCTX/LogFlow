<?php require_once __DIR__ . '/csrf.php'; ?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle ?? APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-info" href="/index.php">
            <i class="bi bi-journals me-2"></i><?= APP_NAME ?>
        </a>
        <div class="navbar-nav ms-auto flex-row gap-2 align-items-center">
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>" href="/index.php">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'logs.php' ? 'active' : '' ?>" href="/logs.php">
                <i class="bi bi-list-ul me-1"></i>Logs
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'syslog.php' ? 'active' : '' ?>" href="/syslog.php">
                <i class="bi bi-hdd-network me-1"></i>Syslog
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'agents.php' ? 'active' : '' ?>" href="/agents.php">
                <i class="bi bi-robot me-1"></i>Agents
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'templates.php' ? 'active' : '' ?>" href="/templates.php">
                <i class="bi bi-mortarboard me-1"></i>Apprentissage
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'security.php' ? 'active' : '' ?>" href="/security.php">
                <i class="bi bi-shield-lock me-1"></i>Sécurité
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'anomalies.php' ? 'active' : '' ?>" href="/anomalies.php">
                <i class="bi bi-activity me-1"></i>Anomalies
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'cve.php' ? 'active' : '' ?>" href="/cve.php">
                <i class="bi bi-bug me-1"></i>CVE
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'severity.php' ? 'active' : '' ?>" href="/severity.php">
                <i class="bi bi-sliders me-1"></i>Sévérité
            </a>
            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'setup.php' ? 'active' : '' ?>" href="/setup.php">
                <i class="bi bi-gear me-1"></i>Setup
            </a>
            <div class="vr mx-1 text-secondary" style="opacity:.3"></div>
            <span class="text-muted small me-1"><i class="bi bi-person-circle me-1"></i><?= h($_SESSION['logflow_user'] ?? '') ?></span>
            <a class="nav-link text-danger" href="/logout.php" title="Déconnexion">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>
<main class="container-fluid py-4">
