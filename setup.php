<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/discord.php';
require_once __DIR__ . '/includes/email.php';

$pageTitle = 'Setup — ' . APP_NAME;

// ── Sauvegarde de l'URL ────────────────────────────────────────
$saved = false;
$error = '';
// ── Changement de mot de passe ─────────────────────────────────
$pw_saved = false; $pw_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_user = trim($_POST['new_username'] ?? '');
    $new_pw   = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    if (strlen($new_user) < 2) {
        $pw_error = 'L\'identifiant doit faire au moins 2 caractères.';
    } elseif (strlen($new_pw) < 8) {
        $pw_error = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($new_pw !== $confirm) {
        $pw_error = 'Les mots de passe ne correspondent pas.';
    } else {
        set_setting('auth_username',      $new_user);
        set_setting('auth_password_hash', password_hash($new_pw, PASSWORD_BCRYPT));
        session_write_close();
        header('Location: setup.php?pw_saved=1#security');
        exit;
    }
}
$pw_saved = isset($_GET['pw_saved']);

// ── Sauvegarde config Discord ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discord_settings'])) {
    set_setting('discord_webhook_url',   trim($_POST['discord_webhook_url'] ?? ''));
    set_setting('discord_min_severity',  (string)(int)($_POST['discord_min_severity'] ?? 3));
    set_setting('discord_cooldown',      (string)max(1, (int)($_POST['discord_cooldown'] ?? 5)));
    set_setting('discord_sec_events_only', isset($_POST['discord_sec_events_only']) ? '1' : '0');
    session_write_close();
    header('Location: setup.php?discord_saved=1#discord');
    exit;
}

// ── Sauvegarde de l'URL ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['server_url'])) {
    $url = trim($_POST['server_url'] ?? '');
    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
        $url = rtrim($url, '/');
        set_setting('server_url', $url);
        set_setting('server_name', trim($_POST['server_name'] ?? APP_NAME));
        session_write_close();
        header('Location: setup.php?saved=1');
        exit;
    } else {
        $error = 'URL invalide. Utilisez le format http://IP ou http://fqdn.domaine.';
    }
}
if (isset($_GET['saved'])) $saved = true;

$discord_saved = isset($_GET['discord_saved']);

// ── Sauvegarde config SMTP ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smtp_settings'])) {
    set_setting('smtp_host',            trim($_POST['smtp_host'] ?? ''));
    set_setting('smtp_port',            (string)max(1, (int)($_POST['smtp_port'] ?? 587)));
    set_setting('smtp_user',            trim($_POST['smtp_user'] ?? ''));
    if (!empty($_POST['smtp_pass']))  set_setting('smtp_pass', $_POST['smtp_pass']);
    set_setting('smtp_from',            trim($_POST['smtp_from'] ?? ''));
    set_setting('smtp_to',              trim($_POST['smtp_to'] ?? ''));
    set_setting('smtp_min_severity',    (string)(int)($_POST['smtp_min_severity'] ?? 3));
    set_setting('smtp_enabled',         isset($_POST['smtp_enabled']) ? '1' : '0');
    set_setting('smtp_sec_events_only', isset($_POST['smtp_sec_events_only']) ? '1' : '0');
    session_write_close();
    header('Location: setup.php?smtp_saved=1#email');
    exit;
}
$smtp_saved = isset($_GET['smtp_saved']);

// ── Sauvegarde rétention ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retention_settings'])) {
    set_setting('log_retention_days', (string)max(1, (int)($_POST['log_retention_days'] ?? 90)));
    session_write_close();
    header('Location: setup.php?retention_saved=1#retention');
    exit;
}
$retention_saved = isset($_GET['retention_saved']);

// ── Données ────────────────────────────────────────────────────
$server_url  = get_setting('server_url', 'http://YOUR_SERVER_IP');
$server_name = get_setting('server_name', APP_NAME);
$is_configured = ($server_url !== 'http://YOUR_SERVER_IP' && !empty($server_url));

$db   = db();
$keys = $db->query("SELECT id, name, api_key, created_at, last_used FROM api_keys ORDER BY id")->fetchAll();
$api_key = $keys[0]['api_key'] ?? '';
$api_id  = $keys[0]['id'] ?? 0;

// Générer une nouvelle clé API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_key'])) {
    $name   = trim($_POST['key_name'] ?? 'Nouveau');
    $newkey = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO api_keys (name, api_key) VALUES (?,?)")->execute([$name, $newkey]);
    header('Location: setup.php#apikeys');
    exit;
}
if (isset($_GET['del_key']) && is_numeric($_GET['del_key']) && count($keys) > 1) {
    $db->prepare("DELETE FROM api_keys WHERE id=?")->execute([(int)$_GET['del_key']]);
    header('Location: setup.php#apikeys');
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  BANNER de statut                                          -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($saved): ?>
<div class="alert alert-success alert-dismissible d-flex align-items-center gap-2 mb-4 fade show" role="alert">
    <i class="bi bi-check-circle-fill"></i>
    <div>Configuration sauvegardée. Tous les scripts d'installation reflètent désormais la nouvelle URL.</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger d-flex gap-2 mb-4"><i class="bi bi-exclamation-triangle-fill"></i><?= h($error) ?></div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 1 — Adresse du serveur                             -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="server-config">
    <div class="card-header bg-primary bg-opacity-10 border-bottom border-primary">
        <h5 class="mb-0">
            <span class="badge bg-primary me-2">1</span>
            Adresse du serveur LogFlow
            <?php if ($is_configured): ?>
            <span class="badge bg-success ms-2 fs-7"><i class="bi bi-check-lg me-1"></i>Configuré</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark ms-2 fs-7"><i class="bi bi-exclamation-triangle me-1"></i>À configurer</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Renseignez l'adresse IP ou le FQDN de ce serveur. Tous les scripts d'installation seront automatiquement pré-configurés avec cette valeur.
        </p>
        <form method="POST" action="setup.php#server-config" class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">URL du serveur <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary"><i class="bi bi-globe2 text-info"></i></span>
                    <input type="url" name="server_url" class="form-control bg-dark border-secondary text-light"
                           value="<?= h($server_url) ?>"
                           placeholder="http://192.168.1.100  ou  http://logflow.mondomaine.com" required>
                </div>
                <div class="form-text">HTTP ou HTTPS, sans slash final.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nom du serveur</label>
                <input type="text" name="server_name" class="form-control bg-dark border-secondary text-light"
                       value="<?= h($server_name) ?>" placeholder="LogFlow">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-floppy me-1"></i>Enregistrer
                </button>
            </div>
        </form>

        <?php if ($is_configured): ?>
        <div class="mt-3 p-3 bg-dark rounded border border-secondary d-flex align-items-center gap-3">
            <i class="bi bi-check-circle-fill text-success fs-4"></i>
            <div>
                <div class="fw-semibold text-light">Serveur actif</div>
                <a href="<?= h($server_url) ?>" target="_blank" class="text-info text-decoration-none">
                    <?= h($server_url) ?> <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 2 — Clés API                                       -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="apikeys">
    <div class="card-header bg-success bg-opacity-10 border-bottom border-success">
        <h5 class="mb-0"><span class="badge bg-success me-2">2</span>Clés API</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Chaque agent utilise une clé API pour s'authentifier. Créez une clé par site ou équipe.</p>

        <div class="table-responsive mb-3">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Nom</th><th>Clé API</th><th>Créée le</th><th>Dernier usage</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                <tr>
                    <td class="fw-semibold"><?= h($k['name']) ?></td>
                    <td>
                        <code class="text-info" id="key-<?= $k['id'] ?>"><?= h($k['api_key']) ?></code>
                        <button class="btn btn-sm btn-outline-secondary ms-1 py-0" onclick="copyText('key-<?= $k['id'] ?>')">
                            <i class="bi bi-copy"></i>
                        </button>
                    </td>
                    <td class="text-muted small"><?= h($k['created_at']) ?></td>
                    <td class="text-muted small"><?= $k['last_used'] ? h($k['last_used']) : '<span class="text-secondary">Jamais</span>' ?></td>
                    <td>
                        <?php if (count($keys) > 1): ?>
                        <a href="setup.php?del_key=<?= $k['id'] ?>#apikeys" class="btn btn-sm btn-outline-danger py-0"
                           onclick="return confirm('Supprimer cette clé ?')">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="POST" action="setup.php#apikeys" class="row g-2 align-items-end">
            <div class="col-auto">
                <input type="text" name="key_name" class="form-control form-control-sm bg-dark border-secondary text-light"
                       placeholder="Nom de la clé" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-success" type="submit" name="new_key" value="1">
                    <i class="bi bi-plus-lg me-1"></i>Nouvelle clé
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 3 — Télécharger & installer l'agent               -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="agents">
    <div class="card-header bg-info bg-opacity-10 border-bottom border-info">
        <h5 class="mb-0"><span class="badge bg-info text-dark me-2">3</span>Installer l'agent</h5>
    </div>
    <div class="card-body">
        <?php if (!$is_configured): ?>
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>Configurez d'abord l'<a href="#server-config" class="alert-link">adresse du serveur</a> pour que les commandes soient pré-remplies.</div>
        </div>
        <?php endif; ?>

        <!-- Sélecteur de clé API -->
        <?php if (count($keys) > 1): ?>
        <div class="row g-2 mb-3 align-items-center">
            <div class="col-auto"><label class="form-label mb-0 fw-semibold">Clé API utilisée :</label></div>
            <div class="col-auto">
                <select id="key-selector" class="form-select form-select-sm bg-dark border-secondary text-light" onchange="updateCommands()">
                    <?php foreach ($keys as $k): ?>
                    <option value="<?= (int)$k['id'] ?>" data-key="<?= h($k['api_key']) ?>"><?= h($k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Tokens d'installation ─────────────────────────────── -->
        <div class="p-3 mb-4 bg-dark rounded border border-secondary">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="fw-semibold"><i class="bi bi-key me-2"></i>Token d'installation</span>
                <span class="text-muted small">requis pour télécharger un agent — révocable, n'expose jamais la clé API à un anonyme</span>
            </div>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-auto">
                    <label class="form-label small text-muted mb-1">Libellé (optionnel)</label>
                    <input id="tok-label" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="ex. serveurs-prod">
                </div>
                <div class="col-auto">
                    <button class="btn btn-info btn-sm" onclick="genToken()"><i class="bi bi-plus-lg me-1"></i>Générer</button>
                </div>
                <div class="col-auto">
                    <label class="form-label small text-muted mb-1">Token actif (utilisé dans les commandes)</label>
                    <select id="token-selector" class="form-select form-select-sm bg-dark text-light border-secondary" onchange="updateCommands()"></select>
                </div>
            </div>
            <div id="tok-fresh" class="alert alert-success py-2 px-3 small d-none mb-2"></div>
            <div class="table-responsive">
                <table class="table table-dark table-sm align-middle mb-0 small">
                    <thead><tr><th>Libellé</th><th>Token</th><th>Créé</th><th>Dernier usage</th><th>État</th><th></th></tr></thead>
                    <tbody id="tok-body"><tr><td colspan="6" class="text-muted text-center py-2">Chargement…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ── Sélection des catégories de logs ──────────────────── -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-semibold">Sources à surveiller</span>
                <span class="badge bg-secondary ms-1" id="cat-count">0 sélectionnée(s)</span>
                <div class="ms-auto d-flex gap-1">
                    <button class="btn btn-sm btn-outline-secondary" onclick="selectAllCats()">Tout</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="selectNoneCats()">Aucun</button>
                    <button class="btn btn-sm btn-outline-info" onclick="selectSecurityCats()">
                        <i class="bi bi-shield-lock me-1"></i>Sécurité
                    </button>
                </div>
            </div>
            <div class="row g-2" id="cat-grid"></div>
            <div class="mt-2 p-2 bg-dark rounded border border-secondary small text-muted" id="cat-summary" style="display:none">
                <i class="bi bi-info-circle me-1"></i><span id="cat-summary-text"></span>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" id="osTabs">
            <li class="nav-item">
                <button class="nav-link active d-flex align-items-center gap-2" data-bs-toggle="tab" data-bs-target="#tab-linux">
                    <i class="bi bi-terminal-fill"></i>Linux
                    <span class="badge bg-secondary" id="tab-badge-linux" style="font-size:.65rem">—</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center gap-2" data-bs-toggle="tab" data-bs-target="#tab-windows">
                    <i class="bi bi-windows"></i>Windows
                    <span class="badge bg-secondary" id="tab-badge-windows" style="font-size:.65rem">—</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center gap-2" data-bs-toggle="tab" data-bs-target="#tab-macos">
                    <i class="bi bi-apple"></i>macOS
                    <span class="badge bg-secondary" id="tab-badge-macos" style="font-size:.65rem">—</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link d-flex align-items-center gap-2" data-bs-toggle="tab" data-bs-target="#tab-rsyslog">
                    <i class="bi bi-hdd-network"></i>rsyslog
                </button>
            </li>
        </ul>

        <div class="tab-content" id="osTabContent">

            <!-- ─── Linux ─────────────────────────────────── -->
            <div class="tab-pane fade show active" id="tab-linux">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 bg-dark rounded border border-secondary">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-semibold text-info"><i class="bi bi-terminal me-2"></i>Installation en une commande</span>
                                <button class="btn btn-sm btn-outline-info" onclick="copyCode('linux-oneliner')">
                                    <i class="bi bi-copy me-1"></i>Copier
                                </button>
                            </div>
                            <pre class="mb-0 text-light small" id="linux-oneliner">curl -fsSL "<?= h($server_url) ?>/agents/download.php?os=linux&token=GENEREZ_UN_TOKEN" | sudo bash</pre>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Détecte automatiquement les fichiers de logs disponibles, installe l'agent dans <code>/usr/local/bin/</code> et crée un service systemd.
                        </p>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <a href="agents/download.php?os=linux&token=GENEREZ_UN_TOKEN" class="btn btn-outline-secondary btn-sm" id="linux-dl-btn" download>
                            <i class="bi bi-download me-1"></i>Télécharger le script
                        </a>
                        <button class="btn btn-sm btn-outline-secondary" onclick="previewScript('linux')">
                            <i class="bi bi-eye me-1"></i>Aperçu
                        </button>
                    </div>
                </div>

                <hr class="my-3 border-secondary">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="text-muted mb-0">Logs surveillés</h6>
                    <span class="badge bg-secondary" id="src-count-linux">0</span>
                    <div class="ms-auto d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectAllSrc('linux')">Tout</button>
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectNoneSrc('linux')">Aucun</button>
                        <button class="btn btn-sm btn-outline-danger py-0" onclick="selectHotSrc('linux')"><i class="bi bi-fire me-1"></i>Critiques</button>
                    </div>
                </div>
                <div class="row g-2" id="sources-grid-linux"></div>

                <hr class="my-3 border-secondary">
                <h6 class="text-muted">Vérifier l'installation</h6>
                <pre class="bg-dark border border-secondary rounded p-2 small text-success">systemctl status logflow-agent
journalctl -u logflow-agent -f</pre>
            </div>

            <!-- ─── Windows ───────────────────────────────── -->
            <div class="tab-pane fade" id="tab-windows">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="alert alert-info d-flex gap-2 py-2 mb-0">
                            <i class="bi bi-shield-exclamation"></i>
                            <div>Exécuter dans une console <strong>PowerShell Admin</strong>.</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 bg-dark rounded border border-secondary">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-semibold text-info"><i class="bi bi-terminal me-2"></i>Installation en une commande</span>
                                <button class="btn btn-sm btn-outline-info" onclick="copyCode('win-oneliner')">
                                    <i class="bi bi-copy me-1"></i>Copier
                                </button>
                            </div>
                            <pre class="mb-0 text-light small" id="win-oneliner">irm "<?= h($server_url) ?>/agents/download.php?os=windows&token=GENEREZ_UN_TOKEN" | iex</pre>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Télécharge et exécute l'installeur PowerShell, active l'audit de sécurité, crée une tâche planifiée SYSTEM.
                        </p>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <a href="agents/download.php?os=windows&token=GENEREZ_UN_TOKEN" class="btn btn-outline-secondary btn-sm" id="win-dl-btn" download>
                            <i class="bi bi-download me-1"></i>Télécharger le .ps1
                        </a>
                        <button class="btn btn-sm btn-outline-secondary" onclick="previewScript('windows')">
                            <i class="bi bi-eye me-1"></i>Aperçu
                        </button>
                    </div>
                </div>

                <hr class="my-3 border-secondary">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="text-muted mb-0">Événements surveillés</h6>
                    <span class="badge bg-secondary" id="src-count-windows">0</span>
                    <div class="ms-auto d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectAllSrc('windows')">Tout</button>
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectNoneSrc('windows')">Aucun</button>
                        <button class="btn btn-sm btn-outline-danger py-0" onclick="selectHotSrc('windows')"><i class="bi bi-fire me-1"></i>Critiques</button>
                    </div>
                </div>
                <div class="row g-2" id="sources-grid-windows"></div>

                <hr class="my-3 border-secondary">
                <h6 class="text-muted">Vérifier l'installation</h6>
                <pre class="bg-dark border border-secondary rounded p-2 small text-success">Get-ScheduledTask -TaskName "LogFlowAgent" | Select-Object State
Get-Content "C:\ProgramData\LogFlow\logflow-agent.log" -Tail 20 -Wait</pre>
            </div>

            <!-- ─── macOS ──────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-macos">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 bg-dark rounded border border-secondary">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-semibold text-info"><i class="bi bi-terminal me-2"></i>Installation en une commande</span>
                                <button class="btn btn-sm btn-outline-info" onclick="copyCode('mac-oneliner')">
                                    <i class="bi bi-copy me-1"></i>Copier
                                </button>
                            </div>
                            <pre class="mb-0 text-light small" id="mac-oneliner">curl -fsSL "<?= h($server_url) ?>/agents/download.php?os=macos&token=GENEREZ_UN_TOKEN" | sudo bash</pre>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Installe l'agent dans <code>/usr/local/bin/</code> et crée un LaunchDaemon qui démarre automatiquement.
                        </p>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap">
                        <a href="agents/download.php?os=macos&token=GENEREZ_UN_TOKEN" class="btn btn-outline-secondary btn-sm" id="mac-dl-btn" download>
                            <i class="bi bi-download me-1"></i>Télécharger le script
                        </a>
                        <button class="btn btn-sm btn-outline-secondary" onclick="previewScript('macos')">
                            <i class="bi bi-eye me-1"></i>Aperçu
                        </button>
                    </div>
                </div>

                <hr class="my-3 border-secondary">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h6 class="text-muted mb-0">Sources (Unified Log)</h6>
                    <span class="badge bg-secondary" id="src-count-macos">0</span>
                    <div class="ms-auto d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectAllSrc('macos')">Tout</button>
                        <button class="btn btn-sm btn-outline-secondary py-0" onclick="selectNoneSrc('macos')">Aucun</button>
                        <button class="btn btn-sm btn-outline-danger py-0" onclick="selectHotSrc('macos')"><i class="bi bi-fire me-1"></i>Critiques</button>
                    </div>
                </div>
                <div class="row g-2" id="sources-grid-macos"></div>

                <hr class="my-3 border-secondary">
                <h6 class="text-muted">Vérifier l'installation</h6>
                <pre class="bg-dark border border-secondary rounded p-2 small text-success">sudo launchctl list | grep logflow
tail -f /var/log/logflow-agent.log</pre>
            </div>

            <!-- ─── rsyslog ────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-rsyslog">
                <p class="text-muted mb-3">Configurer rsyslog pour injecter directement en base de données.</p>
                <div class="row g-3">
                    <div class="col-12">
                        <h6 class="text-info">1. Installer le module MySQL</h6>
                        <pre class="bg-dark border border-secondary rounded p-2 small text-light">apt install rsyslog-mysql     # Debian/Ubuntu
yum install rsyslog-mysql     # RHEL/CentOS</pre>
                    </div>
                    <div class="col-12">
                        <h6 class="text-info">2. Fichier de configuration</h6>
                        <pre class="bg-dark border border-secondary rounded p-2 small text-light">module(load="ommysql")

*.* action(type="ommysql"
    server="localhost"
    db="<?= DB_NAME ?>"
    uid="<?= DB_USER ?>"
    pwd="<?= DB_PASS ?>"
    template="RSYSLOG_SyslogProtocol23Format")</pre>
                    </div>
                    <div class="col-12">
                        <h6 class="text-info">3. Redémarrer</h6>
                        <pre class="bg-dark border border-secondary rounded p-2 small text-light">systemctl restart rsyslog</pre>
                    </div>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 4 — Alertes Discord                                 -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php
$dc_webhook  = get_setting('discord_webhook_url', '');
$dc_min_sev  = (int)get_setting('discord_min_severity', '3');
$dc_cooldown = (int)get_setting('discord_cooldown', '5');
$dc_sec_only = (bool)(int)get_setting('discord_sec_events_only', '0');
$dc_enabled  = !empty($dc_webhook);
?>
<div class="card border-0 shadow-sm mb-4" id="discord">
    <div class="card-header border-bottom" style="background:rgba(88,101,242,.1);border-color:rgba(88,101,242,.3)!important">
        <h5 class="mb-0 d-flex align-items-center gap-2">
            <span class="badge me-1" style="background:#5865F2">4</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#5865F2"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.032.054a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
            Alertes Discord
            <?php if ($dc_enabled): ?>
            <span class="badge bg-success ms-1 fs-7"><i class="bi bi-check-lg me-1"></i>Configuré</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 fs-7">Non configuré</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if ($discord_saved): ?>
        <div class="alert alert-success d-flex gap-2 mb-3 py-2">
            <i class="bi bi-check-circle-fill"></i>Configuration Discord sauvegardée.
        </div>
        <?php endif; ?>

        <p class="text-muted mb-3">
            Envoyez des alertes en temps réel dans un salon Discord via webhook.
            Les notifications respectent un cooldown par hôte pour éviter le flood.
        </p>

        <form method="POST" action="setup.php#discord">
            <input type="hidden" name="discord_settings" value="1">
            <div class="row g-3">

                <!-- Webhook URL -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        URL du webhook Discord
                        <a href="https://support.discord.com/hc/fr/articles/228383668" target="_blank" class="text-info small ms-2">
                            <i class="bi bi-question-circle"></i> Comment créer un webhook ?
                        </a>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary" style="color:#5865F2">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.032.054a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                        </span>
                        <input type="url" name="discord_webhook_url"
                               class="form-control bg-dark border-secondary text-light font-monospace"
                               value="<?= h($dc_webhook) ?>"
                               placeholder="https://discord.com/api/webhooks/…">
                        <button type="button" class="btn btn-outline-secondary" onclick="testDiscord()">
                            <i class="bi bi-send me-1"></i>Tester
                        </button>
                    </div>
                    <div id="discord-test-result" class="mt-1 small"></div>
                </div>

                <!-- Sévérité minimale -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sévérité minimale</label>
                    <select name="discord_min_severity" class="form-select bg-dark border-secondary text-light">
                        <?php
                        $sev_opts = [0=>'Emergency (0)',1=>'Alert (1)',2=>'Critical (2)',
                                     3=>'Error (3) — Recommandé',4=>'Warning (4)',
                                     5=>'Notice (5)',6=>'Info (6)'];
                        foreach ($sev_opts as $k => $label):
                        ?>
                        <option value="<?= $k ?>" <?= $dc_min_sev === $k ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Notifie pour sévérité ≤ ce seuil.</div>
                </div>

                <!-- Cooldown -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Anti-flood (cooldown)</label>
                    <select name="discord_cooldown" class="form-select bg-dark border-secondary text-light">
                        <?php foreach ([1=>'1 minute',2=>'2 minutes',5=>'5 minutes — Recommandé',
                                        10=>'10 minutes',15=>'15 minutes',30=>'30 minutes',60=>'1 heure'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $dc_cooldown === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Délai min entre 2 alertes pour le même hôte + événement.</div>
                </div>

                <!-- Option sec_events only -->
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch ms-1 mb-2">
                        <input class="form-check-input" type="checkbox" name="discord_sec_events_only"
                               id="dc-sec-only" <?= $dc_sec_only ? 'checked' : '' ?>>
                        <label class="form-check-label" for="dc-sec-only">
                            <span class="fw-semibold">Événements sécurité uniquement</span><br>
                            <span class="text-muted small">Ignore les logs sans détection SEC_EVENT</span>
                        </label>
                    </div>
                </div>

                <!-- Actions -->
                <div class="col-12 d-flex gap-2 pt-1">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-floppy me-1"></i>Enregistrer
                    </button>
                    <?php if ($dc_enabled): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearDiscord()">
                        <i class="bi bi-trash me-1"></i>Désactiver
                    </button>
                    <?php endif; ?>
                </div>

            </div>
        </form>

        <?php if ($dc_enabled): ?>
        <!-- Résumé config active -->
        <div class="mt-3 p-3 bg-dark rounded border d-flex align-items-center gap-3"
             style="border-color:rgba(88,101,242,.3)!important">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="#5865F2"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.032.054a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
            <div class="small">
                <div class="text-light fw-semibold mb-1">Discord actif</div>
                <div class="text-muted">
                    Alerte si sévérité ≤ <strong class="text-warning"><?= $sev_opts[$dc_min_sev] ?? $dc_min_sev ?></strong>
                    · Cooldown <strong class="text-info"><?= $dc_cooldown ?> min</strong>
                    <?php if ($dc_sec_only): ?>
                    · <span class="badge bg-secondary">Sécurité seulement</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
$smtp_host_v  = get_setting('smtp_host', '');
$smtp_port_v  = (int)get_setting('smtp_port', '587');
$smtp_user_v  = get_setting('smtp_user', '');
$smtp_from_v  = get_setting('smtp_from', '');
$smtp_to_v    = get_setting('smtp_to', '');
$smtp_min_v   = (int)get_setting('smtp_min_severity', '3');
$smtp_en_v    = (bool)(int)get_setting('smtp_enabled', '0');
$smtp_sec_v   = (bool)(int)get_setting('smtp_sec_events_only', '0');
$smtp_cfg     = !empty($smtp_host_v) && !empty($smtp_user_v);
?>
<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 5 — Alertes Email                                   -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="email">
    <div class="card-header border-bottom" style="background:rgba(240,136,62,.08);border-color:rgba(240,136,62,.3)!important">
        <h5 class="mb-0 d-flex align-items-center gap-2">
            <span class="badge me-1" style="background:#f0883e">5</span>
            <i class="bi bi-envelope-fill" style="color:#f0883e"></i>
            Alertes Email (SMTP)
            <?php if ($smtp_en_v && $smtp_cfg): ?>
            <span class="badge bg-success ms-1 fs-7"><i class="bi bi-check-lg me-1"></i>Actif</span>
            <?php elseif ($smtp_cfg): ?>
            <span class="badge bg-warning text-dark ms-1 fs-7">Configuré — désactivé</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1 fs-7">Non configuré</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if ($smtp_saved): ?>
        <div class="alert alert-success d-flex gap-2 mb-3 py-2">
            <i class="bi bi-check-circle-fill"></i>Configuration SMTP sauvegardée.
        </div>
        <?php endif; ?>

        <p class="text-muted mb-3">
            Recevez des alertes email lorsque des événements critiques sont détectés.
            Supporte SMTP + STARTTLS (port 587), SMTP SSL/TLS (port 465) et SMTP simple (port 25).
        </p>

        <form method="POST" action="setup.php#email">
            <input type="hidden" name="smtp_settings" value="1">
            <div class="row g-3">

                <!-- Activation -->
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="smtp_enabled"
                               id="smtp-enabled" <?= $smtp_en_v ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="smtp-enabled">
                            Activer les alertes email
                        </label>
                    </div>
                </div>

                <!-- Serveur SMTP -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Serveur SMTP <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_host"
                           class="form-control bg-dark border-secondary text-light font-monospace"
                           value="<?= h($smtp_host_v) ?>"
                           placeholder="smtp.gmail.com  ou  smtp.office365.com">
                    <div class="form-text">Nom d'hôte ou IP du serveur SMTP.</div>
                </div>

                <!-- Port -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Port</label>
                    <select name="smtp_port" class="form-select bg-dark border-secondary text-light">
                        <?php foreach ([587=>'587 — STARTTLS', 465=>'465 — SSL/TLS', 25=>'25 — Simple'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $smtp_port_v === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- From -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Adresse expéditeur</label>
                    <input type="email" name="smtp_from"
                           class="form-control bg-dark border-secondary text-light font-monospace"
                           value="<?= h($smtp_from_v) ?>"
                           placeholder="logflow@mondomaine.com">
                    <div class="form-text">Laissez vide pour utiliser l'identifiant.</div>
                </div>

                <!-- Identifiant -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Identifiant SMTP <span class="text-danger">*</span></label>
                    <input type="text" name="smtp_user"
                           class="form-control bg-dark border-secondary text-light font-monospace"
                           value="<?= h($smtp_user_v) ?>"
                           placeholder="user@gmail.com" autocomplete="username">
                </div>

                <!-- Mot de passe -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Mot de passe SMTP</label>
                    <input type="password" name="smtp_pass"
                           class="form-control bg-dark border-secondary text-light"
                           placeholder="<?= $smtp_cfg ? '(inchangé si vide)' : 'App password, token…' ?>"
                           autocomplete="current-password">
                    <div class="form-text">Laissez vide pour ne pas modifier.</div>
                </div>

                <!-- Destination -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Adresse de destination <span class="text-danger">*</span></label>
                    <input type="email" name="smtp_to"
                           class="form-control bg-dark border-secondary text-light font-monospace"
                           value="<?= h($smtp_to_v) ?>"
                           placeholder="admin@mondomaine.com">
                    <div class="form-text">Email qui recevra les alertes.</div>
                </div>

                <!-- Sévérité minimale -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sévérité minimale</label>
                    <select name="smtp_min_severity" class="form-select bg-dark border-secondary text-light">
                        <?php
                        $sev_opts2 = [0=>'Emergency (0)',1=>'Alert (1)',2=>'Critical (2)',
                                      3=>'Error (3) — Recommandé',4=>'Warning (4)',5=>'Notice (5)'];
                        foreach ($sev_opts2 as $k => $lbl):
                        ?>
                        <option value="<?= $k ?>" <?= $smtp_min_v === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Option sec_events only -->
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch ms-1 mb-2">
                        <input class="form-check-input" type="checkbox" name="smtp_sec_events_only"
                               id="smtp-sec-only" <?= $smtp_sec_v ? 'checked' : '' ?>>
                        <label class="form-check-label" for="smtp-sec-only">
                            <span class="fw-semibold">Événements sécurité uniquement</span><br>
                            <span class="text-muted small">Ignore les logs sans SEC_EVENT</span>
                        </label>
                    </div>
                </div>

                <!-- Actions -->
                <div class="col-12 d-flex gap-2 pt-1">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-floppy me-1"></i>Enregistrer
                    </button>
                    <?php if ($smtp_cfg): ?>
                    <button type="button" class="btn btn-outline-info btn-sm" onclick="testEmail()">
                        <i class="bi bi-send me-1"></i>Envoyer un test
                    </button>
                    <?php endif; ?>
                </div>
                <div id="email-test-result" class="col-12 small"></div>

            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 6 — Sécurité / Authentification                    -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="security">
    <div class="card-header bg-danger bg-opacity-10 border-bottom border-danger">
        <h5 class="mb-0"><span class="badge bg-danger me-2">6</span>Authentification</h5>
    </div>
    <div class="card-body">
        <?php if ($pw_saved): ?>
        <div class="alert alert-success d-flex gap-2 mb-3 py-2">
            <i class="bi bi-check-circle-fill"></i>Identifiants mis à jour. Reconnectez-vous si nécessaire.
        </div>
        <?php endif; ?>
        <?php if ($pw_error): ?>
        <div class="alert alert-danger d-flex gap-2 mb-3 py-2">
            <i class="bi bi-exclamation-triangle-fill"></i><?= h($pw_error) ?>
        </div>
        <?php endif; ?>

        <p class="text-muted mb-3">
            Modifiez l'identifiant et le mot de passe d'accès à l'interface LogFlow.
            Le mot de passe doit faire <strong>au moins 8 caractères</strong>.
        </p>

        <form method="POST" action="setup.php#security" class="row g-3">
            <input type="hidden" name="change_password" value="1">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Identifiant</label>
                <input type="text" name="new_username"
                       class="form-control bg-dark border-secondary text-light"
                       value="<?= h(get_setting('auth_username', 'admin')) ?>"
                       minlength="2" required autocomplete="username">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nouveau mot de passe</label>
                <input type="password" name="new_password"
                       class="form-control bg-dark border-secondary text-light"
                       minlength="8" required autocomplete="new-password" placeholder="8 caractères min.">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Confirmer</label>
                <input type="password" name="confirm_password"
                       class="form-control bg-dark border-secondary text-light"
                       minlength="8" required autocomplete="new-password" placeholder="Retapez le mot de passe">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-shield-lock me-1"></i>Mettre à jour les identifiants
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$retention_days_v = (int)get_setting('log_retention_days', (string)MAX_LOG_AGE_DAYS);
?>
<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 7 — Rétention automatique                          -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4" id="retention">
    <div class="card-header border-bottom" style="background:rgba(57,197,187,.08);border-color:rgba(57,197,187,.3)!important">
        <h5 class="mb-0 d-flex align-items-center gap-2">
            <span class="badge me-1" style="background:#39c5bb">7</span>
            <i class="bi bi-clock-history" style="color:#39c5bb"></i>
            Rétention automatique des logs
        </h5>
    </div>
    <div class="card-body">
        <?php if ($retention_saved): ?>
        <div class="alert alert-success d-flex gap-2 mb-3 py-2">
            <i class="bi bi-check-circle-fill"></i>Rétention mise à jour.
        </div>
        <?php endif; ?>

        <p class="text-muted mb-3">
            Purgez automatiquement les logs anciens pour maîtriser la taille de la base de données.
            La purge est effectuée par le script <code>api/cron.php</code>.
        </p>

        <form method="POST" action="setup.php#retention" class="row g-3">
            <input type="hidden" name="retention_settings" value="1">

            <div class="col-md-4">
                <label class="form-label fw-semibold">Durée de rétention</label>
                <div class="input-group">
                    <input type="number" name="log_retention_days" min="1" max="3650"
                           class="form-control bg-dark border-secondary text-light"
                           value="<?= $retention_days_v ?>">
                    <span class="input-group-text bg-dark border-secondary text-muted">jours</span>
                </div>
                <div class="form-text">Les logs plus anciens seront supprimés. Valeur actuelle : <strong class="text-info"><?= $retention_days_v ?> jours</strong>.</div>
            </div>

            <div class="col-md-8 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1"></i>Enregistrer
                </button>
            </div>

        </form>

        <hr class="border-secondary my-3">
        <h6 class="text-info mb-2">Automatiser avec cron</h6>
        <p class="text-muted small mb-2">Ajoutez cette ligne à votre crontab pour exécuter la purge chaque nuit à 3h00 :</p>
        <div class="position-relative">
            <pre class="bg-dark border border-secondary rounded p-2 small text-light pe-5" id="cron-cmd">0 3 * * * php /var/www/logflow/api/cron.php >> /var/log/logflow-cron.log 2>&1</pre>
            <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-1" onclick="copyCode('cron-cmd')">
                <i class="bi bi-copy"></i>
            </button>
        </div>
        <div class="mt-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>Commande : <code>crontab -e</code> pour éditer la crontab root, ou <code>sudo crontab -e</code>.
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  ÉTAPE 8 — Vérification                                   -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-secondary bg-opacity-10 border-bottom border-secondary">
        <h5 class="mb-0"><span class="badge bg-secondary me-2">8</span>Vérifier la réception des logs</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="text-info">Test manuel (curl)</h6>
                <div class="position-relative">
                    <pre class="bg-dark border border-secondary rounded p-2 small text-light pe-5" id="test-curl">curl -X POST "<?= h($server_url) ?>/api/receive.php" \
  -H "X-Api-Key: <?= h($api_key) ?>" \
  -H "Content-Type: application/json" \
  -d '{"host":"test-host","program":"test","severity":6,"message":"Test LogFlow OK","os":"linux"}'</pre>
                    <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-1" onclick="copyCode('test-curl')">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
                <p class="text-muted small">Réponse attendue : <code class="text-success">{"ok":true}</code></p>
            </div>
            <div class="col-md-6">
                <h6 class="text-info">Test en direct</h6>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-info" onclick="sendTestLog()">
                        <i class="bi bi-send me-2"></i>Envoyer un log de test
                    </button>
                    <div id="test-result" class="text-muted small"></div>
                </div>
                <hr class="border-secondary">
                <a href="/logs.php?period=5m" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-list-ul me-1"></i>Voir les logs récents
                </a>
                <a href="/index.php" class="btn btn-outline-primary btn-sm ms-2">
                    <i class="bi bi-speedometer2 me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!--  Modal aperçu script                                      -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="scriptModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="scriptModalTitle">Aperçu du script</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <pre class="p-3 mb-0 text-light small" id="scriptModalContent" style="max-height:75vh;overflow-y:auto;white-space:pre-wrap;word-break:break-all">Chargement…</pre>
            </div>
            <div class="modal-footer border-secondary">
                <button class="btn btn-sm btn-outline-info" onclick="copyCode('scriptModalContent')">
                    <i class="bi bi-copy me-1"></i>Copier
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
const SERVER_URL = <?= json_encode($server_url) ?>;
const API_KEY    = <?= json_encode($api_key) ?>;
const API_ID     = <?= (int)$api_id ?>;

// ── Catégories (affichage) ────────────────────────────────────
const CATEGORIES = [
    {key:'auth',     icon:'bi-shield-lock',       color:'danger',    label:'Authentification',   desc:'Connexions, échecs, comptes verrouillés, SSH, RDP, Kerberos'},
    {key:'packages', icon:'bi-box-arrow-in-down',  color:'warning',   label:'Paquets / Services', desc:'Installation logiciels, nouveaux services, daemons'},
    {key:'firewall', icon:'bi-fire',               color:'warning',   label:'Pare-feu / Réseau',  desc:'Connexions bloquées, règles firewall, modifications réseau'},
    {key:'system',   icon:'bi-cpu',                color:'info',      label:'Système',            desc:'Kernel, syslog général, démarrages/arrêts'},
    {key:'web',      icon:'bi-globe2',             color:'primary',   label:'Applications web',   desc:'Nginx, Apache, accès et erreurs HTTP'},
    {key:'database', icon:'bi-database',           color:'secondary', label:'Bases de données',   desc:'MySQL, PostgreSQL, MongoDB, Redis'},
    {key:'audit',    icon:'bi-eye',                color:'success',   label:'Audit avancé',       desc:'auditd, cron, processus, modifications de comptes, anti-forensics'},
];

// ── Catalogue des sources ─────────────────────────────────────
const SOURCE_CATALOG = {
linux: [
  {key:'auth_log',    path:'/var/log/auth.log',                  label:'auth.log',               desc:'SSH, sudo, PAM — Debian/Ubuntu',                                cat:'auth',     hot:true,  def:true},
  {key:'secure',      path:'/var/log/secure',                    label:'secure',                 desc:'SSH, sudo, PAM — RHEL/CentOS',                                  cat:'auth',     hot:true,  def:true},
  {key:'fail2ban',    path:'/var/log/fail2ban.log',              label:'fail2ban.log',           desc:'IPs bannies, brute-force détecté',                              cat:'auth',     hot:true,  def:true},
  {key:'sssd',        path:'/var/log/sssd/sssd.log',             label:'sssd.log',               desc:'Intégration Active Directory / LDAP',                           cat:'auth',     hot:false, def:false},
  {key:'audit_log',   path:'/var/log/audit/audit.log',           label:'audit/audit.log',        desc:'auditd — syscalls, execve, modifications /etc, SELinux',         cat:'audit',    hot:true,  def:true},
  {key:'syslog',      path:'/var/log/syslog',                    label:'syslog',                 desc:'Journal système généraliste — Debian/Ubuntu',                   cat:'system',   hot:false, def:true},
  {key:'messages',    path:'/var/log/messages',                  label:'messages',               desc:'Journal système généraliste — RHEL/CentOS',                     cat:'system',   hot:false, def:true},
  {key:'kern_log',    path:'/var/log/kern.log',                  label:'kern.log',               desc:'Kernel — modules chargés, erreurs matérielles, OOM',            cat:'system',   hot:false, def:false},
  {key:'cron_log',    path:'/var/log/cron.log',                  label:'cron.log',               desc:'Tâches cron Debian/Ubuntu — persistance T1053',                 cat:'audit',    hot:true,  def:true},
  {key:'cron',        path:'/var/log/cron',                      label:'cron',                   desc:'Tâches cron RHEL/CentOS',                                       cat:'audit',    hot:true,  def:true},
  {key:'daemon_log',  path:'/var/log/daemon.log',                label:'daemon.log',             desc:'Processus démons — services en arrière-plan',                   cat:'system',   hot:false, def:false},
  {key:'boot_log',    path:'/var/log/boot.log',                  label:'boot.log',               desc:'Démarrage système, redémarrages inattendus',                    cat:'system',   hot:false, def:false},
  {key:'mail_log',    path:'/var/log/mail.log',                  label:'mail.log',               desc:'Serveur mail — exfiltration, spam, relais non autorisé',         cat:'system',   hot:false, def:false},
  {key:'dpkg',        path:'/var/log/dpkg.log',                  label:'dpkg.log',               desc:'Installations paquets Debian/Ubuntu',                           cat:'packages', hot:false, def:true},
  {key:'apt_history', path:'/var/log/apt/history.log',           label:'apt/history.log',        desc:'Historique transactions APT complet',                           cat:'packages', hot:false, def:false},
  {key:'yum',         path:'/var/log/yum.log',                   label:'yum.log',                desc:'Installations paquets YUM — CentOS/RHEL 7',                     cat:'packages', hot:false, def:true},
  {key:'dnf',         path:'/var/log/dnf.log',                   label:'dnf.log',                desc:'Installations paquets DNF — RHEL 8+/Fedora',                   cat:'packages', hot:false, def:true},
  {key:'ufw',         path:'/var/log/ufw.log',                   label:'ufw.log',                desc:'Connexions bloquées UFW — scans, accès refusés',                cat:'firewall', hot:true,  def:true},
  {key:'firewalld',   path:'/var/log/firewalld',                 label:'firewalld',              desc:'Pare-feu firewalld — RHEL/CentOS',                              cat:'firewall', hot:true,  def:true},
  {key:'iptables',    path:'/var/log/iptables.log',              label:'iptables.log',           desc:'Règles iptables DROP/REJECT',                                   cat:'firewall', hot:false, def:false},
  {key:'openvpn',     path:'/var/log/openvpn.log',               label:'openvpn.log',            desc:'Connexions OpenVPN — accès VPN',                                cat:'firewall', hot:false, def:false},
  {key:'nginx_err',   path:'/var/log/nginx/error.log',           label:'nginx/error.log',        desc:'Erreurs Nginx — exploits, 4xx/5xx anormaux',                    cat:'web',      hot:false, def:true},
  {key:'nginx_acc',   path:'/var/log/nginx/access.log',          label:'nginx/access.log',       desc:'Accès Nginx — injections SQL, scans',                           cat:'web',      hot:true,  def:true},
  {key:'apache_err',  path:'/var/log/apache2/error.log',         label:'apache2/error.log',      desc:'Erreurs Apache — Debian/Ubuntu',                                cat:'web',      hot:false, def:true},
  {key:'apache_acc',  path:'/var/log/apache2/access.log',        label:'apache2/access.log',     desc:'Accès Apache — Debian/Ubuntu',                                  cat:'web',      hot:true,  def:true},
  {key:'httpd_err',   path:'/var/log/httpd/error_log',           label:'httpd/error_log',        desc:'Erreurs Apache — RHEL/CentOS',                                  cat:'web',      hot:false, def:false},
  {key:'httpd_acc',   path:'/var/log/httpd/access_log',          label:'httpd/access_log',       desc:'Accès Apache — RHEL/CentOS',                                    cat:'web',      hot:false, def:false},
  {key:'mysql_err',   path:'/var/log/mysql/error.log',           label:'mysql/error.log',        desc:'Erreurs MySQL/MariaDB — auth échouées, requêtes rejetées',       cat:'database', hot:false, def:true},
  {key:'pgsql',       path:'/var/log/postgresql/postgresql.log', label:'postgresql.log',         desc:'Journal PostgreSQL',                                            cat:'database', hot:false, def:true},
  {key:'mongodb',     path:'/var/log/mongodb/mongod.log',        label:'mongodb/mongod.log',     desc:'Journal MongoDB',                                               cat:'database', hot:false, def:false},
  {key:'redis',       path:'/var/log/redis/redis-server.log',    label:'redis-server.log',       desc:'Journal Redis',                                                 cat:'database', hot:false, def:false},
  {key:'samba',       path:'/var/log/samba/log.smbd',            label:'samba/log.smbd',         desc:'Partages Samba — accès fichiers Windows',                       cat:'system',   hot:false, def:false},
  {key:'rkhunter',    path:'/var/log/rkhunter.log',              label:'rkhunter.log',           desc:'RKHunter — détection rootkits et fichiers modifiés',             cat:'audit',    hot:false, def:false},
  {key:'letsencrypt', path:'/var/log/letsencrypt/letsencrypt.log',label:'letsencrypt.log',       desc:'Renouvellement certificats SSL Let\'s Encrypt',                  cat:'web',      hot:false, def:false},
],
windows: [
  {key:'logon_ok',           log:'Security',ids:[4624],             label:'Connexion réussie',              desc:'Type 2=local, 3=réseau (pass-hash), 10=RDP, 9=RunAs. Corrélé avec IP/heure suspects = mouvement latéral', cat:'auth',     hot:true,  def:true},
  {key:'logon_fail',         log:'Security',ids:[4625],             label:'Échec connexion',                desc:'Brute-force, password spraying. 0xC000006A=mauvais mdp, 0xC0000064=compte inconnu',                      cat:'auth',     hot:true,  def:true},
  {key:'logoff',             log:'Security',ids:[4634,4647],        label:'Déconnexion',                    desc:'Fin de session. Corrélé avec 4624 pour durée des sessions réseau',                                       cat:'auth',     hot:false, def:true},
  {key:'explicit_creds',     log:'Security',ids:[4648],             label:'Credentials explicites (runas)', desc:'RunAs, WMI avec creds alternatifs — indicateur fort de mouvement latéral',                              cat:'auth',     hot:true,  def:true},
  {key:'special_privs',      log:'Security',ids:[4672],             label:'Privilèges élevés',              desc:'SeDebugPrivilege, SeImpersonatePrivilege — connexion admin ou escalade',                                cat:'auth',     hot:true,  def:true},
  {key:'kerberos_tgt',       log:'Security',ids:[4768],             label:'Ticket Kerberos TGT',            desc:'RC4 dans un env. AES = Golden Ticket ou downgrade. Surveiller sur les DC',                              cat:'auth',     hot:true,  def:true},
  {key:'kerberos_tgs',       log:'Security',ids:[4769],             label:'Kerberoasting (TGS)',            desc:'RC4 pour des comptes de service = Kerberoasting T1558.003',                                             cat:'auth',     hot:true,  def:true},
  {key:'kerberos_preauth',   log:'Security',ids:[4771],             label:'AS-REP Roasting',                desc:'Pré-auth Kerberos échouée — AS-REP Roasting T1558.004',                                                 cat:'auth',     hot:true,  def:false},
  {key:'account_lockout',    log:'Security',ids:[4740],             label:'Compte verrouillé',              desc:'Brute-force ou password spraying. Verrouillages massifs = spray',                                       cat:'auth',     hot:true,  def:true},
  {key:'group_enum',         log:'Security',ids:[4798,4799],        label:'Énumération groupes',            desc:'Reconnaissance avant escalade — vérification membres admins',                                           cat:'auth',     hot:false, def:false},
  {key:'ad_trust',           log:'Security',ids:[4706,4713,4716],   label:'Confiances AD modifiées',        desc:'Compromission forêt AD entière',                                                                        cat:'auth',     hot:false, def:false},
  {key:'account_created',    log:'Security',ids:[4720],             label:'Compte créé',                    desc:'Persistance T1136 — surveiller créations hors processus RH',                                            cat:'audit',    hot:true,  def:true},
  {key:'account_modified',   log:'Security',ids:[4722,4723,4724,4725,4726], label:'Compte modifié/réactivé',desc:'Réactivation comptes dormants, reset mdp hors horaires = backdoor',                                    cat:'audit',    hot:true,  def:true},
  {key:'group_membership',   log:'Security',ids:[4728,4732,4756],   label:'Ajout groupe (escalade)',        desc:'Ajout Domain Admins, Enterprise Admins = escalade critique T1098',                                      cat:'audit',    hot:true,  def:true},
  {key:'process_create',     log:'Security',ids:[4688],             label:'Création de processus',          desc:'LOLBins, PowerShell encodé, malwares. Activer l\'audit ligne de commande',                              cat:'audit',    hot:true,  def:true},
  {key:'service_install',    log:'Security',ids:[4697],             label:'Service installé (Security)',    desc:'Persistance T1543.003 — PsExec, Cobalt Strike créent des services',                                     cat:'packages', hot:true,  def:true},
  {key:'service_sys',        log:'System',  ids:[7045],             label:'Nouveau service (System)',       desc:'Complément de 4697 — certains services n\'apparaissent que dans System',                                cat:'packages', hot:true,  def:true},
  {key:'scheduled_task',     log:'Security',ids:[4698,4699,4700,4701,4702], label:'Tâche planifiée créée', desc:'Persistance T1053.005. 4699 après 4698 = exécution unique pour effacer traces',                          cat:'packages', hot:true,  def:true},
  {key:'lsass_access',       log:'Security',ids:[4656],             label:'Accès LSASS (dump credentials)', desc:'Mimikatz, ProcDump, comsvcs.dll — vol de credentials T1003.001',                                       cat:'audit',    hot:true,  def:false},
  {key:'network_share',      log:'Security',ids:[5140,5145],        label:'Accès partages réseau',          desc:'ADMIN$, C$, IPC$ = outils de mouvement latéral (PsExec, Cobalt Strike)',                               cat:'audit',    hot:true,  def:false},
  {key:'audit_log_cleared',  log:'Security',ids:[1102],             label:'Journal effacé (anti-forensics)', desc:'Quasiment toujours malveillant — alerte immédiate requise',                                           cat:'audit',    hot:true,  def:true},
  {key:'audit_app_cleared',  log:'System',  ids:[104],              label:'Journal Application effacé',     desc:'Effacement journal Application — couverture de traces',                                                 cat:'audit',    hot:true,  def:true},
  {key:'audit_policy_change',log:'Security',ids:[4719,4907],        label:'Politique d\'audit modifiée',   desc:'Désactivation de l\'audit pour opérer sans traces — Defense Evasion T1562',                             cat:'audit',    hot:true,  def:true},
  {key:'firewall_change',    log:'Security',ids:[4946,4947,4950],   label:'Règles pare-feu modifiées',      desc:'Ouverture de port C2, désactivation du pare-feu',                                                      cat:'firewall', hot:false, def:false},
  {key:'powershell_logging', log:'Microsoft-Windows-PowerShell/Operational',ids:[4103,4104], label:'PowerShell Script Block Logging', desc:'Code déobfusqué après décodage Base64. 4104 capture Invoke-Mimikatz',       cat:'audit',    hot:true,  def:false},
  {key:'wmi_persistence',    log:'Microsoft-Windows-WMI-Activity/Operational',ids:[5861], label:'Persistance WMI', desc:'Event Consumer permanent T1546.003 — furtif, quasiment jamais légitime',                       cat:'audit',    hot:true,  def:false},
  {key:'sysmon_process',     log:'Microsoft-Windows-Sysmon/Operational',ids:[1],   label:'Sysmon — Processus + hash', desc:'Meilleur que 4688 : hash SHA256, ligne de commande complète, processus parent',             cat:'audit',    hot:true,  def:false},
  {key:'sysmon_network',     log:'Microsoft-Windows-Sysmon/Operational',ids:[3],   label:'Sysmon — Connexions réseau', desc:'Connexions réseau par processus — C2, exfiltration, mouvement latéral',                   cat:'audit',    hot:true,  def:false},
  {key:'sysmon_injection',   log:'Microsoft-Windows-Sysmon/Operational',ids:[8,10,25], label:'Sysmon — Injection processus', desc:'CreateRemoteThread, credential dump LSASS, process hollowing T1055',               cat:'audit',    hot:true,  def:false},
  {key:'sysmon_registry',    log:'Microsoft-Windows-Sysmon/Operational',ids:[12,13,14], label:'Sysmon — Registre persistance', desc:'Run keys, modifications de services via le registre T1547',                      cat:'audit',    hot:true,  def:false},
  {key:'sysmon_dns',         log:'Microsoft-Windows-Sysmon/Operational',ids:[22,30], label:'Sysmon — Requêtes DNS', desc:'C2 via DNS, DGA, tunnels DNS T1071.004',                                                    cat:'audit',    hot:true,  def:false},
  {key:'sysmon_driver',      log:'Microsoft-Windows-Sysmon/Operational',ids:[6,7],  label:'Sysmon — Drivers chargés', desc:'Drivers non signés, rootkits noyau T1014',                                                cat:'audit',    hot:true,  def:false},
  {key:'service_state',      log:'System',  ids:[7036,7040],        label:'Services démarrés/arrêtés',      desc:'Arrêt d\'antivirus/EDR = Defense Evasion T1562',                                                      cat:'system',   hot:true,  def:true},
  {key:'system_boot',        log:'System',  ids:[6005,6006,6008],   label:'Démarrage / Arrêt / Crash',      desc:'Redémarrages non planifiés : possible rootkit ou sabotage',                                            cat:'system',   hot:false, def:true},
],
macos: [
  {key:'auth_mac',            label:'Authentification (securityd)',     desc:'Sessions sécurité, auth PAM, connexions système',                                                             cat:'auth',     hot:true,  def:true},
  {key:'authd_mac',           label:'Authorization Services (authd)',   desc:'Autorisations accordées/refusées pour opérations privilégiées',                                               cat:'auth',     hot:true,  def:true},
  {key:'login_session',       label:'Connexion / Déconnexion session',  desc:'loginwindow — sessions locales, FastUserSwitching',                                                           cat:'auth',     hot:true,  def:true},
  {key:'sudo_mac',            label:'Escalade — sudo',                  desc:'Toutes les commandes sudo : utilisateur, commande, résultat',                                                cat:'auth',     hot:true,  def:true},
  {key:'ssh_mac',             label:'Authentification SSH',             desc:'Connexions SSH entrantes/sortantes réussies et échouées',                                                    cat:'auth',     hot:true,  def:true},
  {key:'opendirectory_mac',   label:'Gestion comptes OpenDirectory',    desc:'Création/suppression comptes via sysadminctl, dscl — persistance T1136',                                    cat:'auth',     hot:true,  def:true},
  {key:'screen_sharing_mac',  label:'Partage d\'écran / VNC',          desc:'Authentification au partage d\'écran — accès distants non autorisés',                                        cat:'auth',     hot:true,  def:false},
  {key:'keychain_mac',        label:'Accès au Keychain',                desc:'Vol de credentials stockés dans le Keychain T1555.001',                                                     cat:'auth',     hot:true,  def:false},
  {key:'tcc_mac',             label:'Permissions TCC (caméra, micro…)', desc:'Accès accordés/refusés : caméra, micro, photos, disque — détecte RAT/spyware',                              cat:'audit',    hot:true,  def:true},
  {key:'persistence_mac',     label:'Persistance — Login Items',        desc:'Exécution automatique au démarrage de session — malwares T1547.011',                                        cat:'audit',    hot:true,  def:true},
  {key:'launchd_mac',         label:'Persistance — LaunchDaemons/Agents', desc:'LaunchDaemons/Agents chargés — services persistants malveillants T1543.004',                             cat:'audit',    hot:true,  def:true},
  {key:'gatekeeper_mac',      label:'Gatekeeper / XProtect / Signing',  desc:'Vérifications notarisation, scans XProtect YARA, bypass Gatekeeper T1553',                                cat:'audit',    hot:true,  def:true},
  {key:'kext_mac',            label:'Chargement extensions noyau (kext)', desc:'Drivers/kext chargés — rootkits et drivers non autorisés T1547.006',                                    cat:'audit',    hot:true,  def:false},
  {key:'network_mac',         label:'Connexions réseau (TCP/UDP)',       desc:'Nouvelles connexions — C2, exfiltration, backdoors réseau',                                                cat:'system',   hot:true,  def:true},
  {key:'installer_mac',       label:'Installations logiciels',           desc:'com.apple.installer, packagekit — installations de logiciels',                                             cat:'packages', hot:true,  def:true},
  {key:'update_mac',          label:'Mises à jour système',              desc:'SoftwareUpdate — mises à jour OS et applications',                                                         cat:'packages', hot:false, def:true},
  {key:'system_changes_mac',  label:'Modifications SIP / politique',     desc:'Politique de sécurité, SIP — détecte les modifications système T1562',                                    cat:'system',   hot:false, def:true},
]
};

// ── État par OS ───────────────────────────────────────────────
const selectedSources = {
    linux:   new Set(SOURCE_CATALOG.linux.filter(s => s.def).map(s => s.key)),
    windows: new Set(SOURCE_CATALOG.windows.filter(s => s.def).map(s => s.key)),
    macos:   new Set(SOURCE_CATALOG.macos.filter(s => s.def).map(s => s.key)),
};
let currentOsTab = 'linux';

// ── Rendu grille des sources ──────────────────────────────────
function renderSourceGrid(os) {
    const container = document.getElementById('sources-grid-' + os);
    if (!container) return;
    const sources = SOURCE_CATALOG[os] || [];
    const sel = selectedSources[os];

    container.innerHTML = sources.map(src => {
        const active = sel.has(src.key);
        const catDef  = CATEGORIES.find(c => c.key === src.cat) || {};
        const color   = catDef.color || 'secondary';
        const ids     = src.ids ? src.ids.slice(0,4).join(', ') + (src.ids.length > 4 ? '…' : '') : null;
        const sub     = src.path || (ids ? 'EventIDs : ' + ids : '');
        return `<div class="col-md-6 col-lg-4 col-xl-3">
<div class="p-2 rounded border h-100 source-card ${active ? 'border-'+color+' bg-'+color+' bg-opacity-10' : 'border-secondary bg-dark'}"
     style="cursor:pointer;transition:all .15s" onclick="toggleSource('${os}','${src.key}')">
  <div class="d-flex align-items-start gap-1 mb-1">
    ${src.hot ? '<span class="badge bg-danger" style="font-size:.55rem;padding:1px 3px;flex-shrink:0">KEY</span>' : '<span style="width:28px;flex-shrink:0"></span>'}
    <span class="fw-semibold ${active ? 'text-'+color : 'text-muted'}" style="font-size:.78rem;line-height:1.2;flex:1">${src.label}</span>
    <i class="bi ${active ? 'bi-check-square-fill text-'+color : 'bi-square text-secondary'} ms-1" style="flex-shrink:0"></i>
  </div>
  <div class="text-secondary" style="font-size:.68rem;line-height:1.3">${src.desc}</div>
  ${sub ? `<div class="mt-1"><code style="font-size:.6rem;color:#666;word-break:break-all">${sub}</code></div>` : ''}
</div></div>`;
    }).join('');

    const cntEl = document.getElementById('src-count-' + os);
    if (cntEl) cntEl.textContent = sel.size + '/' + sources.length;
    const tabBadge = document.getElementById('tab-badge-' + os);
    if (tabBadge) {
        tabBadge.textContent = sel.size + '/' + sources.length;
        tabBadge.className = sel.size === sources.length ? 'badge bg-success ms-1'
                           : sel.size === 0 ? 'badge bg-secondary ms-1'
                           : 'badge bg-warning text-dark ms-1';
        tabBadge.style.fontSize = '.65rem';
    }
}

function toggleSource(os, key) {
    selectedSources[os].has(key) ? selectedSources[os].delete(key) : selectedSources[os].add(key);
    renderSourceGrid(os);
    renderCatGrid();
    updateCommands();
}

// ── Sélection rapide par OS ───────────────────────────────────
function selectAllSrc(os)  { SOURCE_CATALOG[os].forEach(s => selectedSources[os].add(s.key));                   renderAll(os); }
function selectNoneSrc(os) { selectedSources[os].clear();                                                         renderAll(os); }
function selectHotSrc(os)  { selectedSources[os] = new Set(SOURCE_CATALOG[os].filter(s => s.hot).map(s => s.key)); renderAll(os); }
function renderAll(os) { renderSourceGrid(os); renderCatGrid(); updateCommands(); }

// ── État catégorie pour un OS ─────────────────────────────────
function getCatState(catKey, os) {
    const sources = SOURCE_CATALOG[os].filter(s => s.cat === catKey);
    if (!sources.length) return 'none';
    const n = sources.filter(s => selectedSources[os].has(s.key)).length;
    if (n === 0) return 'none';
    if (n === sources.length) return 'all';
    return 'partial';
}

// ── Rendu grille des catégories ───────────────────────────────
function renderCatGrid() {
    const grid = document.getElementById('cat-grid');
    if (!grid) return;
    grid.innerHTML = '';
    const os = currentOsTab;

    CATEGORIES.forEach(cat => {
        const state   = getCatState(cat.key, os);
        const active  = state !== 'none';
        const bsColor = cat.color;
        const icon    = state === 'all' ? 'bi-check-circle-fill text-'+bsColor
                      : state === 'partial' ? 'bi-dash-circle-fill text-'+bsColor
                      : 'bi-circle text-secondary';
        const srcCount = SOURCE_CATALOG[os].filter(s => s.cat === cat.key).length;
        const selCount = SOURCE_CATALOG[os].filter(s => s.cat === cat.key && selectedSources[os].has(s.key)).length;

        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-3';
        col.innerHTML = `
        <div class="p-2 rounded border h-100 ${active
            ? `border-${bsColor} bg-${bsColor} bg-opacity-10`
            : 'border-secondary bg-dark'}"
             style="cursor:pointer;transition:all .15s" onclick="toggleCat('${cat.key}')">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi ${cat.icon} fs-5 text-${active ? bsColor : 'secondary'}"></i>
                <span class="fw-semibold small ${active ? 'text-'+bsColor : 'text-muted'}">${cat.label}</span>
                <i class="bi ${icon} ms-auto small"></i>
            </div>
            <div class="text-muted mb-1" style="font-size:.72rem;line-height:1.3">${cat.desc}</div>
            <div style="font-size:.65rem;color:#666">${selCount}/${srcCount} pour ${os}</div>
        </div>`;
        grid.appendChild(col);
    });

    const total = selectedSources[os].size;
    const totalEl = document.getElementById('cat-count');
    if (totalEl) totalEl.textContent = total + ' source' + (total !== 1 ? 's' : '');

    const summary = document.getElementById('cat-summary');
    const summaryText = document.getElementById('cat-summary-text');
    if (summary && summaryText) {
        const active = CATEGORIES.filter(c => getCatState(c.key, os) !== 'none').map(c => c.label);
        if (active.length) { summaryText.textContent = 'Catégories actives : ' + active.join(' · '); summary.style.display = ''; }
        else { summary.style.display = 'none'; }
    }

    updateCommands();
}

function toggleCat(key) {
    const os = currentOsTab;
    const state = getCatState(key, os);
    const sources = SOURCE_CATALOG[os].filter(s => s.cat === key);
    if (state === 'all') { sources.forEach(s => selectedSources[os].delete(s.key)); }
    else                 { sources.forEach(s => selectedSources[os].add(s.key)); }
    renderAll(os);
}

// Boutons "Tout/Aucun/Sécurité" (au-dessus de la grille cats)
function selectAllCats()      { selectAllSrc(currentOsTab); }
function selectNoneCats()     { selectNoneSrc(currentOsTab); }
function selectSecurityCats() {
    const os = currentOsTab;
    selectedSources[os] = new Set(SOURCE_CATALOG[os].filter(s => ['auth','audit','firewall','packages'].includes(s.cat)).map(s => s.key));
    renderAll(os);
}

// ── Paramètre sources pour l'URL ──────────────────────────────
function getSourcesParam(os) {
    const all = SOURCE_CATALOG[os];
    const sel = [...selectedSources[os]];
    if (sel.length === 0)        return 'none';
    if (sel.length === all.length) return 'all';
    return sel.join(',');
}

// ── Utilitaires ───────────────────────────────────────────────
function copyText(id) {
    navigator.clipboard.writeText(document.getElementById(id).textContent.trim())
        .then(() => toast('Copié !', 'success'));
}
function copyCode(id) {
    navigator.clipboard.writeText(document.getElementById(id).textContent.trim())
        .then(() => toast('Copié !', 'success'));
}
function toast(msg, type = 'info') {
    const t = document.createElement('div');
    t.className = `toast align-items-center text-bg-${type} border-0 show position-fixed bottom-0 end-0 m-3`;
    t.style.zIndex = 9999;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div></div>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2500);
}
function getSelectedKey() {
    const sel = document.getElementById('key-selector');
    if (!sel) return {id: API_ID, key: API_KEY};
    return {id: parseInt(sel.value), key: sel.options[sel.selectedIndex].dataset.key};
}

// ── Mise à jour des commandes ─────────────────────────────────
function activeToken() {
    const sel = document.getElementById('token-selector');
    return (sel && sel.value) ? sel.value : '';
}
function updateCommands() {
    const {key} = getSelectedKey();
    const url = SERVER_URL;
    const el  = (i) => document.getElementById(i);
    const tk  = activeToken();

    const lSrc = getSourcesParam('linux');
    const wSrc = getSourcesParam('windows');
    const mSrc = getSourcesParam('macos');

    const noTok = "⚠️ Générez un token d'installation ci-dessus pour obtenir la commande.";
    if (el('linux-oneliner'))
        el('linux-oneliner').textContent = tk ? `curl -fsSL "${url}/agents/download.php?os=linux&token=${tk}&sources=${lSrc}" | sudo bash` : noTok;
    if (el('win-oneliner'))
        el('win-oneliner').textContent = tk ? `irm "${url}/agents/download.php?os=windows&token=${tk}&sources=${wSrc}" | iex` : noTok;
    if (el('mac-oneliner'))
        el('mac-oneliner').textContent = tk ? `curl -fsSL "${url}/agents/download.php?os=macos&token=${tk}&sources=${mSrc}" | sudo bash` : noTok;
    if (el('test-curl'))
        el('test-curl').textContent = `curl -X POST "${url}/api/receive.php" \\\n  -H "X-Api-Key: ${key}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"host":"test-host","program":"test","severity":6,"message":"Test LogFlow OK","os":"linux"}'`;

    if (el('linux-dl-btn')) el('linux-dl-btn').href = `agents/download.php?os=linux&token=${tk}&sources=${lSrc}`;
    if (el('win-dl-btn'))   el('win-dl-btn').href   = `agents/download.php?os=windows&token=${tk}&sources=${wSrc}`;
    if (el('mac-dl-btn'))   el('mac-dl-btn').href   = `agents/download.php?os=macos&token=${tk}&sources=${mSrc}`;
}

function previewScript(os) {
    const tk = activeToken();
    if (!tk) { toast("Générez un token d'installation d'abord"); return; }
    const src = getSourcesParam(os);
    const modal = new bootstrap.Modal(document.getElementById('scriptModal'));
    document.getElementById('scriptModalTitle').textContent = `Script ${os} — ${selectedSources[os].size} sources`;
    document.getElementById('scriptModalContent').textContent = 'Chargement…';
    modal.show();
    fetch(`agents/download.php?os=${os}&token=${tk}&sources=${src}`)
        .then(r => r.text())
        .then(t => { document.getElementById('scriptModalContent').textContent = t; });
}

function sendTestLog() {
    const {key} = getSelectedKey();
    const div = document.getElementById('test-result');
    div.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Envoi…</span>';
    fetch(SERVER_URL + '/api/receive.php', {
        method: 'POST',
        headers: {'X-Api-Key': key, 'Content-Type': 'application/json'},
        body: JSON.stringify({host:'test-setup', program:'logflow-setup', severity:6,
                              message:'Test de connectivité LogFlow OK', os:'linux'})
    })
    .then(r => r.json())
    .then(d => {
        div.innerHTML = d.ok
            ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Log envoyé avec succès !</span>'
            : `<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>${JSON.stringify(d)}</span>`;
    })
    .catch(e => { div.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${e.message}</span>`; });
}

// ── Discord test & clear ──────────────────────────────────────
function testDiscord() {
    const url = document.querySelector('input[name="discord_webhook_url"]').value.trim();
    const res = document.getElementById('discord-test-result');
    if (!url) { res.innerHTML = '<span class="text-warning">Saisissez d\'abord l\'URL du webhook.</span>'; return; }
    res.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Envoi du message test…</span>';
    const fd = new FormData();
    fd.append('webhook', url);
    fetch('api/discord_test.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            res.innerHTML = d.ok
                ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Message envoyé ! Vérifiez votre salon Discord.</span>'
                : `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${d.error}</span>`;
        })
        .catch(() => { res.innerHTML = '<span class="text-danger">Erreur réseau.</span>'; });
}
function clearDiscord() {
    if (!confirm('Désactiver les alertes Discord ?')) return;
    document.querySelector('input[name="discord_webhook_url"]').value = '';
    document.querySelector('form [name="discord_settings"]').closest('form').submit();
}

function testEmail() {
    const res = document.getElementById('email-test-result');
    res.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Envoi en cours…</span>';
    fetch('api/email_test.php', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                res.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + d.message + '</span>';
            } else {
                res.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + d.error + '</span>';
            }
        })
        .catch(() => { res.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Erreur réseau.</span>'; });
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#osTabs button[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', e => {
            const t = e.target.dataset.bsTarget.replace('#tab-', '');
            if (['linux','windows','macos'].includes(t)) {
                currentOsTab = t;
                renderCatGrid();
                updateCommands();
            }
        });
    });

    renderCatGrid();
    ['linux','windows','macos'].forEach(os => renderSourceGrid(os));
    loadTokens();
    updateCommands();
});

// ── Tokens d'installation ─────────────────────────────────────
function escTok(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
async function loadTokens() {
    const body = document.getElementById('tok-body');
    const sel  = document.getElementById('token-selector');
    if (!body || !sel) return;
    try {
        const d = await (await fetch('api/install-tokens.php')).json();
        const prev = sel.value;
        const list = d.tokens || [];
        const active = list.filter(t => !parseInt(t.revoked));
        sel.innerHTML = active.length ? '' : '<option value="">— aucun token actif —</option>';
        active.forEach(t => {
            const o = document.createElement('option');
            o.value = t.token;
            o.textContent = (t.label || 'token') + ' …' + t.token.slice(-6);
            sel.appendChild(o);
        });
        if (prev) sel.value = prev;
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-2">Aucun token. Générez-en un.</td></tr>';
        } else {
            body.innerHTML = '';
            list.forEach(t => {
                const ok = !parseInt(t.revoked);
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${escTok(t.label || '—')}</td>`
                    + `<td class="font-monospace">…${escTok(t.token.slice(-8))}</td>`
                    + `<td>${escTok(t.created_at || '')}</td>`
                    + `<td>${escTok(t.last_used || '—')}</td>`
                    + `<td>${ok ? '<span class="badge bg-success">actif</span>' : '<span class="badge bg-secondary">révoqué</span>'}</td>`
                    + `<td>${ok ? `<button class="btn btn-sm btn-outline-danger border-0 py-0" onclick="revokeToken(${parseInt(t.id)})"><i class="bi bi-x-lg"></i></button>` : ''}</td>`;
                body.appendChild(tr);
            });
        }
        updateCommands();
    } catch (e) {}
}
async function genToken() {
    const label = (document.getElementById('tok-label') || {}).value || '';
    const keyId = getSelectedKey().id;
    const d = await (await fetch('api/install-tokens.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({label, api_key_id: keyId})})).json();
    if (!d.ok) { toast('Erreur lors de la création du token'); return; }
    await loadTokens();
    const sel = document.getElementById('token-selector');
    if (sel) sel.value = d.token;
    const fresh = document.getElementById('tok-fresh');
    if (fresh) { fresh.classList.remove('d-none'); fresh.innerHTML = "Token créé — il pilote les commandes ci-dessous : <code>" + escTok(d.token) + "</code>"; }
    updateCommands();
}
async function revokeToken(id) {
    if (!confirm("Révoquer ce token ? Les commandes qui l'utilisent cesseront de fonctionner.")) return;
    await fetch('api/install-tokens.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'revoke', id})});
    await loadTokens();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
