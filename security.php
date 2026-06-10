<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

$pageTitle = APP_NAME . ' — Sécurité';
$db = db();

$period = $_GET['p'] ?? '24h';
$periodMap = ['1h'=>60,'6h'=>360,'24h'=>1440,'7d'=>10080,'30d'=>43200];
$minutes = $periodMap[$period] ?? 1440;

$stats = [];
foreach (SEC_EVENTS as $key => $def) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE sec_event=? AND received_at >= NOW() - INTERVAL ? MINUTE");
    $stmt->execute([$key, $minutes]);
    $stats[$key] = (int)$stmt->fetchColumn();
}

$total_sec   = array_sum($stats);
$total_fail  = ($stats['auth_fail'] ?? 0) + ($stats['auth_brute'] ?? 0) + ($stats['sudo_fail'] ?? 0);
$total_crit  = ($stats['auth_brute'] ?? 0) + ($stats['log_cleared'] ?? 0) + ($stats['account_lockout'] ?? 0);
$hosts_count = (int)$db->query("SELECT COUNT(DISTINCT host) FROM logs WHERE sec_event IS NOT NULL")->fetchColumn();

// Timeline sécurité
$timeline = $db->prepare("
    SELECT DATE_FORMAT(received_at,'%H:00') as h, sec_event, COUNT(*) as c
    FROM logs WHERE sec_event IS NOT NULL AND received_at >= NOW() - INTERVAL ? MINUTE
    GROUP BY h, sec_event ORDER BY h
");
$timeline->execute([$minutes]);
$timelineRows = $timeline->fetchAll();

// Derniers events de sécurité
$recent = $db->prepare("
    SELECT * FROM logs WHERE sec_event IS NOT NULL
    ORDER BY received_at DESC LIMIT 50
");
$recent->execute();
$recentLogs = $recent->fetchAll();

// Top hosts par incidents
$topHosts = $db->prepare("
    SELECT host, sec_event, COUNT(*) as c FROM logs
    WHERE sec_event IS NOT NULL AND received_at >= NOW() - INTERVAL ? MINUTE
    GROUP BY host, sec_event ORDER BY c DESC LIMIT 20
");
$topHosts->execute([$minutes]);
$topHostsRows = $topHosts->fetchAll();
?><!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { overflow: auto; }
        .dash { padding: 20px; max-width: 1400px; margin: 0 auto; }
        .dash-topbar { display:flex;align-items:center;padding:0 20px;height:48px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50;gap:20px; }
        .sec-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
        .sec-card {
            background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
            padding: 12px 14px; cursor: pointer; transition: border-color .15s, transform .1s;
        }
        .sec-card:hover { transform: translateY(-1px); }
        .sec-card.active { border-color: var(--accent); }
        .sec-card .cnt  { font-size: 22px; font-weight: 700; line-height: 1; margin: 4px 0; }
        .sec-card .lbl  { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing:.05em; }
        .sec-card .icon { font-size: 16px; }
        .period-btns { display:flex; gap:4px; }
        .period-btn { padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:11px;cursor:pointer;transition:all .12s;text-decoration:none; }
        .period-btn:hover,.period-btn.active { border-color:var(--accent);color:var(--accent);background:rgba(88,166,255,.1); }
    </style>
</head>
<body style="overflow:auto">
<header class="dash-topbar">
    <div class="topbar-brand">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?= APP_NAME ?> Sécurité
    </div>
    <nav class="topbar-nav">
        <a href="/index.php">Dashboard</a>
        <a href="/logs.php">Logs</a>
        <a href="/security.php" class="active">Sécurité</a>
        <a href="/setup.php">Setup</a>
    </nav>
    <div class="topbar-spacer"></div>
    <div class="period-btns">
        <?php foreach (['1h'=>'1h','6h'=>'6h','24h'=>'24h','7d'=>'7j','30d'=>'30j'] as $k=>$l): ?>
        <a href="?p=<?= $k ?>" class="period-btn <?= $period===$k?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
        <a href="/security_report.php?period=7d" class="period-btn" style="border-color:rgba(88,166,255,.4);color:#58a6ff" title="Rapport PDF">
            <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="margin-right:3px"><path d="M5.5 7a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1h-5zM5 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5z"/><path d="M9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0zm0 1v2A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5z"/></svg>Rapport
        </a>
    </div>
</header>

<div class="dash">

    <!-- KPIs -->
    <div class="stat-grid" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="stat-label">Événements sécurité</div>
            <div class="stat-value" style="color:var(--accent)"><?= number_format($total_sec) ?></div>
            <div class="stat-sub">sur <?= $period ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Échecs auth</div>
            <div class="stat-value" style="color:<?= $total_fail>0?'var(--red)':'var(--green)' ?>"><?= number_format($total_fail) ?></div>
            <div class="stat-sub">fail + brute + sudo</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Incidents critiques</div>
            <div class="stat-value" style="color:<?= $total_crit>0?'var(--red)':'var(--green)' ?>"><?= number_format($total_crit) ?></div>
            <div class="stat-sub">lockout, brute, cleared</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Hôtes concernés</div>
            <div class="stat-value" style="color:var(--yellow)"><?= $hosts_count ?></div>
            <div class="stat-sub">avec events sécurité</div>
        </div>
    </div>

    <!-- Event type cards -->
    <div class="sec-grid" style="margin-bottom:20px">
        <?php foreach (SEC_EVENTS as $key => $def): $cnt = $stats[$key] ?? 0; ?>
        <div class="sec-card <?= $cnt>0?'':'opacity-50' ?>" onclick="filterEvent('<?= h($key) ?>')" style="border-color:<?= $cnt>0 ? $def['color'].'33' : 'var(--border)' ?>">
            <div class="icon"><?= $def['icon'] ?></div>
            <div class="cnt" style="color:<?= $def['color'] ?>"><?= number_format($cnt) ?></div>
            <div class="lbl"><?= h($def['label']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px">
        <!-- Timeline -->
        <div class="dash-card">
            <div class="dash-card-header">Timeline des événements de sécurité</div>
            <div style="padding:16px"><canvas id="tlChart" style="height:180px"></canvas></div>
        </div>
        <!-- Top hosts -->
        <div class="dash-card">
            <div class="dash-card-header">Hôtes les plus actifs</div>
            <div style="padding:8px 0;max-height:220px;overflow-y:auto">
                <?php
                $hostSums = [];
                foreach ($topHostsRows as $r) $hostSums[$r['host']] = ($hostSums[$r['host']] ?? 0) + $r['c'];
                arsort($hostSums); $maxH = max(1, reset($hostSums));
                foreach ($hostSums as $host => $cnt): ?>
                <div style="padding:7px 16px;display:flex;align-items:center;gap:10px">
                    <a href="/logs.php?host=<?= urlencode($host) ?>" class="tag tag-host" style="flex:0 0 auto;max-width:110px;overflow:hidden;text-overflow:ellipsis"><?= h($host) ?></a>
                    <div style="flex:1;height:4px;background:var(--border);border-radius:2px">
                        <div style="height:100%;width:<?= round($cnt/$maxH*100) ?>%;background:var(--red);border-radius:2px"></div>
                    </div>
                    <span style="font-size:11px;color:var(--text-muted);width:30px;text-align:right"><?= $cnt ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Recent security events -->
    <div class="dash-card">
        <div class="dash-card-header">
            <span>Derniers événements de sécurité</span>
            <input type="text" id="sec-search" placeholder="Filtrer…" style="background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:5px;padding:3px 8px;font-size:11px;width:180px">
        </div>
        <div style="overflow-x:auto">
            <table class="log-table" id="sec-table">
                <thead>
                    <tr>
                        <th style="width:140px">Horodatage</th>
                        <th style="width:120px">Événement</th>
                        <th style="width:110px">Hôte</th>
                        <th style="width:60px">OS</th>
                        <th style="width:110px">Programme</th>
                        <th style="width:90px">Sévérité</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentLogs as $log):
                    $sev = (int)$log['severity'];
                    $def = SEC_EVENTS[$log['sec_event']] ?? null;
                ?>
                <tr class="sev-<?= $sev ?>">
                    <td class="td-time"><?= h(substr($log['received_at'],0,19)) ?></td>
                    <td>
                        <?php if ($def): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 7px;border-radius:4px;background:<?= $def['color'] ?>22;color:<?= $def['color'] ?>">
                            <?= $def['icon'] ?> <?= h($def['label']) ?>
                        </span>
                        <?php else: ?>
                        <span class="tag" style="background:var(--surface2);color:var(--text-muted)"><?= h($log['sec_event']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><a href="/logs.php?host=<?= urlencode($log['host']) ?>" class="tag tag-host"><?= h($log['host']) ?></a></td>
                    <td style="font-size:10px;color:var(--text-dim)"><?= h($log['os'] ?? '—') ?></td>
                    <td><span class="tag tag-program"><?= h($log['program']) ?></span></td>
                    <td><?= severity_badge($sev) ?></td>
                    <td class="td-msg"><div class="msg-text" title="<?= h($log['message']) ?>"><?= h($log['message']) ?></div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentLogs)): ?>
                <tr><td colspan="7"><div class="empty-state">Aucun événement de sécurité détecté</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#8b949e';
Chart.defaults.borderColor = '#30363d';

// Build timeline datasets
const tlRaw = <?= json_encode($timelineRows) ?>;
const secDefs = <?= json_encode(array_map(fn($d)=>['label'=>$d['label'],'color'=>$d['color']], SEC_EVENTS), JSON_UNESCAPED_UNICODE) ?>;
const hours = [...new Set(tlRaw.map(r => r.h))].sort();
const datasets = {};
tlRaw.forEach(r => {
    if (!datasets[r.sec_event]) {
        const def = secDefs[r.sec_event] ?? {label: r.sec_event, color: '#8b949e'};
        datasets[r.sec_event] = { label: def.label, data: Object.fromEntries(hours.map(h=>[h,0])), color: def.color };
    }
    datasets[r.sec_event].data[r.h] = parseInt(r.c);
});

new Chart(document.getElementById('tlChart'), {
    type: 'bar',
    data: {
        labels: hours,
        datasets: Object.values(datasets).map(d => ({
            label: d.label,
            data: hours.map(h => d.data[h] ?? 0),
            backgroundColor: d.color + '99',
            borderColor: d.color,
            borderWidth: 1,
            borderRadius: 2,
        }))
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, font: { size: 10 } } } },
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { stacked: true, grid: { color: '#21262d' } }
        }
    }
});

// Filter events
function filterEvent(key) {
    window.location = '/logs.php?search=' + encodeURIComponent(key);
}

// Search in table
document.getElementById('sec-search').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#sec-table tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
