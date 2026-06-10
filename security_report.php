<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/security.php';

$pageTitle = 'Rapport de sécurité — ' . APP_NAME;

$period_map = [
    '24h' => ['label' => 'Dernières 24 heures', 'interval' => '24 HOUR'],
    '7d'  => ['label' => '7 derniers jours',    'interval' => '7 DAY'],
    '30d' => ['label' => '30 derniers jours',   'interval' => '30 DAY'],
    '90d' => ['label' => '90 derniers jours',   'interval' => '90 DAY'],
];
$period = isset($_GET['period'], $period_map[$_GET['period']]) ? $_GET['period'] : '7d';
$p      = $period_map[$period];
$db     = db();

// ── Fonctions utilitaires ──────────────────────────────────────
function q(string $sql, array $params = []): array {
    global $db;
    $s = $db->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}
function qval(string $sql, array $params = []) {
    global $db;
    $s = $db->prepare($sql);
    $s->execute($params);
    $r = $s->fetchColumn();
    return $r === false ? 0 : $r;
}

$intv = $p['interval'];

// ── Requêtes ───────────────────────────────────────────────────
$total_logs   = (int)qval("SELECT COUNT(*) FROM logs WHERE received_at > NOW() - INTERVAL $intv");
$total_sec    = (int)qval("SELECT COUNT(*) FROM logs WHERE received_at > NOW() - INTERVAL $intv AND sec_event IS NOT NULL");
$total_crit   = (int)qval("SELECT COUNT(*) FROM logs WHERE received_at > NOW() - INTERVAL $intv AND severity <= 2");
$total_err    = (int)qval("SELECT COUNT(*) FROM logs WHERE received_at > NOW() - INTERVAL $intv AND severity <= 3");
$total_hosts  = (int)qval("SELECT COUNT(DISTINCT host) FROM logs WHERE received_at > NOW() - INTERVAL $intv");

// Répartition par sévérité
$sev_rows = q("SELECT severity, COUNT(*) cnt FROM logs
               WHERE received_at > NOW() - INTERVAL $intv
               GROUP BY severity ORDER BY severity");
$sev_counts = array_fill(0, 8, 0);
foreach ($sev_rows as $r) $sev_counts[(int)$r['severity']] = (int)$r['cnt'];

// Répartition par événement de sécurité
$sec_events_rows = q("SELECT sec_event, COUNT(*) cnt,
                        MIN(received_at) first_seen, MAX(received_at) last_seen
                      FROM logs
                      WHERE received_at > NOW() - INTERVAL $intv AND sec_event IS NOT NULL
                      GROUP BY sec_event ORDER BY cnt DESC");

// Top 10 hôtes avec événements de sécurité
$top_hosts_sec = q("SELECT host, COUNT(*) cnt, COUNT(DISTINCT sec_event) types,
                           MIN(CASE WHEN severity<=2 THEN severity END) min_sev
                    FROM logs
                    WHERE received_at > NOW() - INTERVAL $intv AND sec_event IS NOT NULL
                    GROUP BY host ORDER BY cnt DESC LIMIT 10");

// Top 10 IP sources avec événements de sécurité
$top_ips_sec = q("SELECT source_ip, COUNT(*) cnt, COUNT(DISTINCT host) hosts,
                         COUNT(DISTINCT sec_event) types
                  FROM logs
                  WHERE received_at > NOW() - INTERVAL $intv AND sec_event IS NOT NULL
                        AND source_ip <> ''
                  GROUP BY source_ip ORDER BY cnt DESC LIMIT 10");

// Évolution quotidienne des événements de sécurité (max 30 jours)
$timeline_days = min(30, (int)qval("SELECT DATEDIFF(NOW(), NOW() - INTERVAL $intv)") + 1);
$timeline = q("SELECT DATE(received_at) day, COUNT(*) cnt
               FROM logs
               WHERE received_at > NOW() - INTERVAL $intv AND sec_event IS NOT NULL
               GROUP BY DATE(received_at) ORDER BY day");

// 25 derniers événements critiques (sev ≤ 2) ou alertes sécurité (sev ≤ 3)
$recent_critical = q("SELECT received_at, host, source_ip, severity, program, sec_event, message, os
                      FROM logs
                      WHERE received_at > NOW() - INTERVAL $intv
                            AND (severity <= 3 OR sec_event IS NOT NULL)
                      ORDER BY received_at DESC LIMIT 25");

// Sévérité globale du rapport
$report_sev = 'ok';
if ($total_crit > 0) $report_sev = 'critical';
elseif ($total_err > 0) $report_sev = 'warning';
elseif ($total_sec > 0) $report_sev = 'info';

$sev_labels = [0=>'Emergency',1=>'Alert',2=>'Critical',3=>'Error',4=>'Warning',5=>'Notice',6=>'Info',7=>'Debug'];
$sev_colors = [0=>'#f85149',1=>'#f85149',2=>'#ff7b72',3=>'#ffa657',4=>'#d29922',5=>'#79c0ff',6=>'#3fb950',7=>'#8b949e'];
$os_icons   = ['linux'=>'🐧','windows'=>'🪟','macos'=>'🍎','other'=>'💻'];

$generated_at = date('d/m/Y à H:i:s');
$server_name  = get_setting('server_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --bg: #0d1117; --bg2: #161b22; --border: #30363d; --text: #c9d1d9; --muted: #8b949e; }
        body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .report-header { background: var(--bg2); border-bottom: 2px solid var(--border); padding: 1.5rem 2rem; }
        .kpi-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; text-align: center; }
        .kpi-value { font-size: 2rem; font-weight: 700; line-height: 1; }
        .kpi-label { font-size: .8rem; color: var(--muted); margin-top: .4rem; }
        .section { background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; margin-bottom: 1.5rem; overflow: hidden; }
        .section-header { background: rgba(255,255,255,.04); border-bottom: 1px solid var(--border); padding: .75rem 1.25rem; font-weight: 600; }
        .section-body { padding: 1.25rem; }
        table { border-collapse: collapse; width: 100%; }
        th { background: rgba(255,255,255,.05); color: var(--muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; padding: .5rem .75rem; text-align: left; }
        td { padding: .5rem .75rem; border-bottom: 1px solid rgba(255,255,255,.06); font-size: .85rem; }
        tr:last-child td { border-bottom: none; }
        .badge-sev { display: inline-block; padding: .15rem .5rem; border-radius: 4px; font-size: .75rem; font-weight: 600; }
        .sev-bar { height: 6px; border-radius: 3px; }
        .timeline-bar { display: flex; align-items: flex-end; gap: 2px; height: 60px; padding: .25rem 0; }
        .tbar { flex: 1; border-radius: 2px 2px 0 0; min-height: 2px; background: #58a6ff; opacity: .7; transition: opacity .15s; }
        .tbar:hover { opacity: 1; }
        .bar-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
        .bar-fill { height: 12px; border-radius: 2px; }
        .status-ok { color: #3fb950; }
        .status-warn { color: #d29922; }
        .status-crit { color: #f85149; }
        .print-btn { position: fixed; bottom: 2rem; right: 2rem; z-index: 1000; box-shadow: 0 4px 24px rgba(0,0,0,.4); }
        .period-sel { background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: .25rem; display: inline-flex; gap: 2px; }
        .period-sel a { padding: .3rem .75rem; border-radius: 4px; font-size: .8rem; text-decoration: none; color: var(--muted); }
        .period-sel a.active { background: #1f6feb; color: #fff; }
        .msg-cell { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: .78rem; }
        .sec-badge { display: inline-block; padding: .1rem .45rem; border-radius: 4px; font-size: .7rem; font-weight: 600; }

        @media print {
            body { background: #fff; color: #111; font-size: 10pt; }
            .print-btn, .period-sel, .no-print { display: none !important; }
            .report-header { background: #f8f9fa; border-bottom: 2px solid #dee2e6; color: #111; }
            :root { --bg: #fff; --bg2: #f8f9fa; --border: #dee2e6; --text: #111; --muted: #6c757d; }
            .section { break-inside: avoid; border: 1px solid #dee2e6; }
            table { font-size: 8pt; }
            .kpi-value { font-size: 1.5rem; }
            a { color: inherit; text-decoration: none; }
            .timeline-bar { display: none; }
        }

        @page { margin: 15mm; size: A4; }
    </style>
</head>
<body>

<!-- ── En-tête du rapport ─────────────────────────────────────── -->
<div class="report-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-shield-fill-check fs-4 text-info"></i>
                <h1 class="h4 mb-0 text-light">Rapport de sécurité</h1>
                <?php
                $badge_class = match($report_sev) {
                    'critical' => 'bg-danger', 'warning' => 'bg-warning text-dark', 'info' => 'bg-info text-dark', default => 'bg-success'
                };
                $badge_label = match($report_sev) {
                    'critical' => 'Critique', 'warning' => 'Avertissements', 'info' => 'Informations', default => 'Nominal'
                };
                ?>
                <span class="badge <?= $badge_class ?> ms-1"><?= $badge_label ?></span>
            </div>
            <div class="text-muted small">
                <?= h($server_name) ?> · <?= h($p['label']) ?> · Généré le <?= $generated_at ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 no-print">
            <div class="period-sel">
                <?php foreach ($period_map as $k => $v): ?>
                <a href="security_report.php?period=<?= $k ?>" class="<?= $period === $k ? 'active' : '' ?>"><?= $k ?></a>
                <?php endforeach; ?>
            </div>
            <a href="/security.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>
</div>

<div class="container-fluid py-4" style="max-width:1100px">

<!-- ── KPIs ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="kpi-card">
            <div class="kpi-value text-light"><?= number_format($total_logs) ?></div>
            <div class="kpi-label"><i class="bi bi-journals me-1"></i>Logs total</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi-card">
            <div class="kpi-value <?= $total_sec > 0 ? 'text-warning' : 'text-success' ?>"><?= number_format($total_sec) ?></div>
            <div class="kpi-label"><i class="bi bi-shield-exclamation me-1"></i>Événements sécurité</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi-card">
            <div class="kpi-value <?= $total_crit > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($total_crit) ?></div>
            <div class="kpi-label"><i class="bi bi-exclamation-octagon me-1"></i>Critiques / Alertes</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kpi-card">
            <div class="kpi-value text-info"><?= number_format($total_hosts) ?></div>
            <div class="kpi-label"><i class="bi bi-pc-display me-1"></i>Hôtes actifs</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">

    <!-- Répartition par sévérité -->
    <div class="col-md-5">
        <div class="section h-100">
            <div class="section-header"><i class="bi bi-bar-chart-fill me-2 text-warning"></i>Répartition par sévérité</div>
            <div class="section-body">
                <?php
                $max_sev = max(1, max($sev_counts));
                foreach ([0,1,2,3,4,5,6,7] as $s):
                    if ($sev_counts[$s] === 0) continue;
                    $pct = round($sev_counts[$s] / $max_sev * 100);
                    $col = $sev_colors[$s];
                ?>
                <div class="bar-row">
                    <div style="width:70px;font-size:.78rem;color:var(--muted)"><?= $sev_labels[$s] ?></div>
                    <div class="flex-grow-1"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                    <div style="width:55px;text-align:right;font-size:.78rem;font-variant-numeric:tabular-nums">
                        <span style="color:<?= $col ?>"><?= number_format($sev_counts[$s]) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Évolution temporelle -->
    <div class="col-md-7">
        <div class="section h-100">
            <div class="section-header"><i class="bi bi-graph-up me-2 text-info"></i>Évolution des événements de sécurité</div>
            <div class="section-body">
                <?php if (empty($timeline)): ?>
                <p class="text-muted mb-0 small">Aucun événement de sécurité sur la période.</p>
                <?php else:
                    $tl_data = [];
                    foreach ($timeline as $t) $tl_data[$t['day']] = (int)$t['cnt'];
                    $tl_max = max(1, max($tl_data));
                ?>
                <div class="timeline-bar no-print">
                    <?php foreach ($tl_data as $day => $cnt): ?>
                    <div class="tbar" style="height:<?= round($cnt/$tl_max*100) ?>%" title="<?= $day ?> : <?= $cnt ?> événements"></div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1" style="font-size:.7rem">
                    <?php
                    $days_list = array_keys($tl_data);
                    $n = count($days_list);
                    if ($n <= 7) {
                        foreach ($days_list as $d) echo '<span>' . substr($d, 5) . '</span>';
                    } else {
                        echo '<span>' . substr($days_list[0], 5) . '</span>';
                        echo '<span>' . substr($days_list[intval($n/2)], 5) . '</span>';
                        echo '<span>' . substr($days_list[$n-1], 5) . '</span>';
                    }
                    ?>
                </div>
                <!-- Table pour l'impression -->
                <table class="mt-2 d-none d-print-table" style="font-size:8pt">
                    <tr><?php foreach ($tl_data as $d => $c) echo "<td style='padding:2px 4px'>$d</td>"; ?></tr>
                    <tr><?php foreach ($tl_data as $d => $c) echo "<td style='padding:2px 4px;text-align:center'>$c</td>"; ?></tr>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Événements de sécurité détectés ───────────────────────── -->
<?php if (!empty($sec_events_rows)): ?>
<div class="section mb-4">
    <div class="section-header"><i class="bi bi-shield-exclamation me-2 text-warning"></i>Événements de sécurité détectés</div>
    <div class="section-body p-0">
        <table>
            <thead><tr>
                <th>Type</th><th>Label</th><th>Occurrences</th><th>1ère détection</th><th>Dernière détection</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sec_events_rows as $r):
                $ev    = SEC_EVENTS[$r['sec_event']] ?? null;
                $label = $ev ? $ev['label'] : $r['sec_event'];
                $icon  = $ev ? $ev['icon'] : '🔍';
                $color = $ev ? $ev['color'] : '#8b949e';
                $pct   = $total_sec > 0 ? round($r['cnt'] / $total_sec * 100) : 0;
            ?>
            <tr>
                <td>
                    <span class="sec-badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>44">
                        <?= $icon ?> <?= h($r['sec_event']) ?>
                    </span>
                </td>
                <td class="text-muted"><?= h($label) ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold" style="color:<?= $color ?>"><?= number_format((int)$r['cnt']) ?></span>
                        <div class="flex-grow-1"><div class="sev-bar" style="width:<?= $pct ?>%;background:<?= $color ?>;opacity:.6"></div></div>
                        <span class="text-muted" style="font-size:.72rem;width:30px;text-align:right"><?= $pct ?>%</span>
                    </div>
                </td>
                <td class="text-muted" style="font-size:.78rem"><?= h(substr($r['first_seen'], 0, 16)) ?></td>
                <td class="text-muted" style="font-size:.78rem"><?= h(substr($r['last_seen'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">

    <!-- Top hôtes -->
    <div class="col-md-6">
        <div class="section h-100">
            <div class="section-header"><i class="bi bi-pc-display me-2 text-info"></i>Top hôtes — événements de sécurité</div>
            <div class="section-body p-0">
                <?php if (empty($top_hosts_sec)): ?>
                <p class="text-muted p-3 mb-0 small">Aucun événement de sécurité.</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>Hôte</th><th>Événements</th><th>Types distincts</th></tr></thead>
                    <tbody>
                    <?php foreach ($top_hosts_sec as $r):
                        $ms = $r['min_sev'];
                        $dot = $ms !== null ? "<span style='color:{$sev_colors[(int)$ms]};margin-right:4px'>●</span>" : '';
                    ?>
                    <tr>
                        <td><?= $dot ?><span class="font-monospace"><?= h($r['host']) ?></span></td>
                        <td><span class="fw-semibold text-warning"><?= number_format((int)$r['cnt']) ?></span></td>
                        <td><span class="badge bg-secondary"><?= (int)$r['types'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top IP sources -->
    <div class="col-md-6">
        <div class="section h-100">
            <div class="section-header"><i class="bi bi-globe2 me-2 text-danger"></i>Top IP sources — événements de sécurité</div>
            <div class="section-body p-0">
                <?php if (empty($top_ips_sec)): ?>
                <p class="text-muted p-3 mb-0 small">Aucune IP source détectée.</p>
                <?php else: ?>
                <table>
                    <thead><tr><th>IP source</th><th>Événements</th><th>Hôtes ciblés</th></tr></thead>
                    <tbody>
                    <?php foreach ($top_ips_sec as $r): ?>
                    <tr>
                        <td class="font-monospace"><?= h($r['source_ip']) ?></td>
                        <td><span class="fw-semibold text-danger"><?= number_format((int)$r['cnt']) ?></span></td>
                        <td><span class="badge bg-secondary"><?= (int)$r['hosts'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Derniers événements critiques ─────────────────────────── -->
<?php if (!empty($recent_critical)): ?>
<div class="section mb-4">
    <div class="section-header"><i class="bi bi-list-ul me-2 text-danger"></i>Derniers événements critiques / alertes de sécurité</div>
    <div class="section-body p-0">
        <table>
            <thead><tr>
                <th>Date</th><th>Hôte</th><th>Sévérité</th><th>Programme</th><th>Évén. SEC</th><th>Message</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_critical as $r):
                $sev = (int)$r['severity'];
                $col = $sev_colors[$sev] ?? '#8b949e';
                $ev  = $r['sec_event'] ? (SEC_EVENTS[$r['sec_event']] ?? null) : null;
                $ev_color = $ev ? $ev['color'] : '#8b949e';
            ?>
            <tr>
                <td style="font-size:.75rem;white-space:nowrap;color:var(--muted)"><?= h(substr($r['received_at'], 0, 16)) ?></td>
                <td class="font-monospace" style="font-size:.78rem"><?= h($r['host']) ?></td>
                <td>
                    <span class="badge-sev" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                        <?= $sev_labels[$sev] ?>
                    </span>
                </td>
                <td style="font-size:.78rem"><?= h($r['program']) ?></td>
                <td>
                    <?php if ($r['sec_event']): ?>
                    <span class="sec-badge" style="background:<?= $ev_color ?>22;color:<?= $ev_color ?>">
                        <?= $ev ? $ev['icon'] : '' ?> <?= h($r['sec_event']) ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="msg-cell" title="<?= h($r['message']) ?>"><?= h($r['message']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Pied de page ───────────────────────────────────────────── -->
<div class="text-center text-muted small py-3 border-top" style="border-color:var(--border)!important">
    <i class="bi bi-shield-lock me-1"></i>
    <?= h($server_name) ?> — Rapport généré le <?= $generated_at ?> · Période : <?= h($p['label']) ?>
</div>

</div><!-- /container -->

<!-- ── Bouton imprimer ────────────────────────────────────────── -->
<div class="no-print">
    <button class="btn btn-primary print-btn" onclick="window.print()">
        <i class="bi bi-printer me-2"></i>Imprimer / PDF
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
