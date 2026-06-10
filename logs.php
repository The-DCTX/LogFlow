<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = APP_NAME . ' — Logs';
?><!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
    /* ── Log Stream ───────────────────────────────────────────── */
    .log-stream { flex: 1; overflow-y: auto; }

    .log-row {
        display: flex;
        flex-direction: column;
        padding: 6px 14px 6px 0;
        border-bottom: 1px solid var(--border2);
        border-left: 3px solid transparent;
        cursor: pointer;
        transition: background .08s;
        position: relative;
    }
    .log-row:hover { background: var(--surface2); }
    .log-row.sev-0,.log-row.sev-1,.log-row.sev-2 { border-left-color: var(--red); background: rgba(248,81,73,.025); }
    .log-row.sev-3 { border-left-color: var(--orange); background: rgba(240,136,62,.02); }
    .log-row.sev-4 { border-left-color: var(--yellow); background: rgba(210,153,34,.02); }
    .log-row.sev-5 { border-left-color: var(--accent); }
    .log-row.sev-6 { border-left-color: transparent; }
    .log-row.sev-7 { border-left-color: var(--border2); }
    .log-row.sev-0:hover,.log-row.sev-1:hover,.log-row.sev-2:hover { background: rgba(248,81,73,.07); }
    .log-row.sev-3:hover { background: rgba(240,136,62,.07); }
    .log-row.sev-4:hover { background: rgba(210,153,34,.06); }

    .log-row-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        padding-left: 11px;
        min-height: 22px;
        flex-wrap: nowrap;
        overflow: hidden;
    }
    .log-sev-chip {
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .07em;
        text-transform: uppercase;
        padding: 1px 5px;
        border-radius: 3px;
        flex-shrink: 0;
        line-height: 16px;
    }
    .sev-chip-0,.sev-chip-1,.sev-chip-2 { background: rgba(248,81,73,.2); color: #f85149; }
    .sev-chip-3  { background: rgba(240,136,62,.2); color: #f0883e; }
    .sev-chip-4  { background: rgba(210,153,34,.2); color: #d29922; }
    .sev-chip-5  { background: rgba(88,166,255,.15); color: #58a6ff; }
    .sev-chip-6  { background: rgba(63,185,80,.12); color: #3fb950; }
    .sev-chip-7  { background: rgba(139,148,158,.1); color: #8b949e; }

    .log-ts {
        font-family: 'SF Mono','Consolas','Menlo',monospace;
        font-size: 11px;
        color: var(--text-muted);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .log-rel {
        font-size: 10px;
        color: var(--text-dim);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .log-sep { color: var(--border); font-size: 10px; flex-shrink: 0; }
    .log-os  { font-size: 12px; flex-shrink: 0; opacity: .7; }
    .log-meta-tags { display: flex; align-items: center; gap: 5px; flex: 1; overflow: hidden; min-width: 0; }

    .log-row-msg {
        padding: 2px 0 0 11px;
        font-family: 'SF Mono','Consolas','Menlo',monospace;
        font-size: 11.5px;
        color: var(--text);
        line-height: 1.45;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-all;
    }
    .log-row:hover .log-row-msg { -webkit-line-clamp: 3; }

    .log-copy-btn {
        position: absolute;
        right: 10px;
        top: 6px;
        opacity: 0;
        background: var(--surface2);
        border: 1px solid var(--border);
        color: var(--text-muted);
        border-radius: 4px;
        padding: 2px 6px;
        cursor: pointer;
        font-size: 10px;
        transition: opacity .1s, color .1s;
    }
    .log-row:hover .log-copy-btn { opacity: 1; }
    .log-copy-btn:hover { color: var(--accent); }

    /* ── Detail panel ─────────────────────────────────────────── */
    .log-detail-panel {
        background: var(--surface);
        border-left: 3px solid var(--border);
        border-bottom: 1px solid var(--border);
        padding: 12px 16px 12px 14px;
        animation: slideDown .1s ease;
    }
    @keyframes slideDown { from { opacity:0; transform:translateY(-4px); } }
    .log-detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px 16px;
        margin-bottom: 8px;
        font-size: 11.5px;
    }
    .ldf { display: flex; gap: 8px; align-items: baseline; line-height: 1.6; }
    .ldf-k { color: var(--text-dim); font-size: 10px; font-family: monospace; letter-spacing:.04em; white-space:nowrap; min-width: 72px; }
    .ldf-v { color: var(--text); font-family: monospace; word-break: break-all; }
    .log-detail-msg {
        margin-top: 8px;
        padding: 8px 10px;
        background: var(--bg);
        border: 1px solid var(--border2);
        border-radius: 5px;
        font-family: 'SF Mono','Consolas','Menlo',monospace;
        font-size: 11.5px;
        color: var(--text);
        line-height: 1.55;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .log-detail-actions { display: flex; gap: 6px; margin-top: 8px; }
    .btn-detail {
        font-size: 11px; padding: 3px 10px; border-radius: 4px;
        border: 1px solid var(--border); background: transparent;
        color: var(--text-muted); cursor: pointer; transition: all .12s;
    }
    .btn-detail:hover { border-color: var(--accent); color: var(--accent); }

    /* ── Sidebar enrichi ──────────────────────────────────────── */
    .sev-bar-wrap { padding: 0 12px 8px; }
    .sev-bar { display: flex; height: 4px; border-radius: 4px; overflow: hidden; gap: 1px; }
    .sev-bar-seg { transition: flex .3s; }

    .sev-filter-item { position: relative; }
    .sev-filter-item .sev-count {
        margin-left: auto;
        font-size: 10px; color: var(--text-dim);
        min-width: 28px; text-align: right;
    }

    /* OS filter */
    .os-filter-item {
        display: flex; align-items: center; gap: 8px;
        padding: 5px 8px; border-radius: 6px;
        cursor: pointer; transition: background .12s; user-select: none;
    }
    .os-filter-item:hover { background: var(--surface2); }
    .os-filter-item.active { background: var(--surface2); }
    .os-icon { font-size: 13px; width: 18px; text-align: center; }
    .os-name { flex: 1; font-size: 12px; }
    .os-count { font-size: 10px; color: var(--text-dim); }

    /* ── Filter bar améliorée ─────────────────────────────────── */
    .active-filters {
        display: flex; gap: 4px; flex-wrap: wrap; align-items: center;
    }
    .filter-chip {
        display: inline-flex; align-items: center; gap: 4px;
        background: rgba(88,166,255,.12); color: var(--accent);
        border: 1px solid rgba(88,166,255,.25);
        border-radius: 12px; padding: 2px 8px 2px 10px;
        font-size: 11px; cursor: pointer; white-space: nowrap;
    }
    .filter-chip:hover { background: rgba(248,81,73,.15); color: var(--red); border-color: rgba(248,81,73,.3); }
    .filter-chip svg { opacity: .7; }

    /* ── New logs banner ──────────────────────────────────────── */
    .new-logs-bar {
        position: sticky; top: 0; z-index: 40;
        display: none; align-items: center; justify-content: center;
        background: rgba(88,166,255,.15); border-bottom: 1px solid rgba(88,166,255,.3);
        color: var(--accent); font-size: 12px; padding: 6px;
        cursor: pointer; gap: 6px;
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.7} }
    .new-logs-bar.visible { display: flex; }

    /* ── Compact header columns ───────────────────────────────── */
    .stream-header {
        display: flex; align-items: center; gap: 6px;
        padding: 5px 14px 5px 14px;
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        font-size: 10px; font-weight: 600; letter-spacing: .06em;
        text-transform: uppercase; color: var(--text-dim);
        position: sticky; top: 0; z-index: 20;
    }
    .sh-sev  { width: 36px; flex-shrink:0 }
    .sh-time { width: 108px; flex-shrink:0 }
    .sh-rel  { width: 36px; flex-shrink:0 }
    .sh-sep  { width: 6px; }
    .sh-os   { width: 16px; flex-shrink:0 }
    .sh-meta { flex:1 }
    .sh-msg  { color: var(--text-dim); }
    </style>
</head>
<body class="spa">
<div class="app-layout">

    <!-- Topbar -->
    <header class="app-topbar">
        <div class="topbar-brand">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
            <?= APP_NAME ?>
        </div>
        <nav class="topbar-nav">
            <a href="/index.php">Dashboard</a>
            <a href="/logs.php" class="active">Logs</a>
            <a href="/security.php">Sécurité</a>
            <a href="/setup.php">Setup</a>
        </nav>
        <div class="topbar-spacer"></div>
        <div class="live-indicator" id="live-indicator">
            <span class="live-dot"></span> Live
        </div>
    </header>

    <!-- Sidebar -->
    <aside class="app-sidebar">

        <!-- Hôtes -->
        <div class="sidebar-section">
            <div class="sidebar-label">Hôtes</div>
            <div id="host-list">
                <div class="host-item active" data-host="">
                    <div class="host-dot" style="background:var(--text-muted)"></div>
                    <span class="host-name">Tous les hôtes</span>
                    <span class="host-count" id="total-count">—</span>
                </div>
            </div>
        </div>

        <!-- OS -->
        <div class="sidebar-section">
            <div class="sidebar-label">Système</div>
            <div id="os-list">
                <div class="os-filter-item active" data-os="">
                    <span class="os-icon">🌐</span>
                    <span class="os-name">Tous</span>
                    <span class="os-count" id="os-count-all">—</span>
                </div>
                <div class="os-filter-item" data-os="linux">
                    <span class="os-icon">🐧</span>
                    <span class="os-name">Linux</span>
                    <span class="os-count" id="os-count-linux">0</span>
                </div>
                <div class="os-filter-item" data-os="windows">
                    <span class="os-icon">🪟</span>
                    <span class="os-name">Windows</span>
                    <span class="os-count" id="os-count-windows">0</span>
                </div>
                <div class="os-filter-item" data-os="macos">
                    <span class="os-icon">🍎</span>
                    <span class="os-name">macOS</span>
                    <span class="os-count" id="os-count-macos">0</span>
                </div>
            </div>
        </div>

        <!-- Sévérité -->
        <div class="sidebar-section">
            <div class="sidebar-label">Sévérité</div>
            <div class="sev-bar-wrap">
                <div class="sev-bar" id="sev-bar" title="Distribution des sévérités"></div>
            </div>
            <div id="sev-list">
                <?php
                $sevColors = ['#f85149','#f85149','#f85149','#f0883e','#d29922','#58a6ff','#3fb950','#8b949e'];
                foreach (SEVERITIES as $k => $v): ?>
                <div class="sev-filter-item" data-sev="<?= $k ?>">
                    <div class="sev-dot" style="background:<?= $sevColors[$k] ?>"></div>
                    <span class="sev-name"><?= $v ?></span>
                    <span class="sev-count" id="sev-count-<?= $k ?>"></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </aside>

    <!-- Main -->
    <main class="app-main" id="app-main">

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-search-wrap">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" class="filter-search" id="filter-search" placeholder="Rechercher…  ( / )" autocomplete="off">
            </div>
            <input type="text" id="filter-program" placeholder="Programme…" style="width:120px" autocomplete="off">
            <div class="filter-sep"></div>
            <div class="time-btns">
                <button class="time-btn" data-period="5m">5m</button>
                <button class="time-btn" data-period="15m">15m</button>
                <button class="time-btn" data-period="1h">1h</button>
                <button class="time-btn" data-period="6h">6h</button>
                <button class="time-btn active" data-period="24h">24h</button>
                <button class="time-btn" data-period="7d">7j</button>
                <button class="time-btn" data-period="">Tout</button>
            </div>
            <div class="filter-sep"></div>
            <div class="active-filters" id="active-filters"></div>
            <div class="topbar-spacer"></div>
            <button class="btn-icon" id="btn-refresh" title="Actualiser (r)">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            </button>
            <button class="btn-icon" id="btn-pause" title="Pause auto-refresh">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
            </button>
            <button class="btn-icon" id="btn-export" title="Exporter CSV">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </button>
            <span class="filter-count" id="filter-count">—</span>
        </div>

        <!-- New logs notification -->
        <div class="new-logs-bar" id="new-logs-bar" onclick="refreshAndDismiss()">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            <span id="new-logs-text">Nouveaux logs disponibles — cliquer pour actualiser</span>
        </div>

        <!-- Stream header -->
        <div class="stream-header">
            <span class="sh-sev">SEV</span>
            <span class="sh-time">Horodatage</span>
            <span class="sh-rel">Âge</span>
            <span class="sh-sep"></span>
            <span class="sh-os"></span>
            <span class="sh-meta sh-msg">Hôte · Programme · Message</span>
        </div>

        <!-- Log Stream -->
        <div class="log-stream" id="log-stream">
            <div id="log-tbody">
                <div style="padding:60px 20px;text-align:center;color:var(--text-dim)">
                    <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
                    <div style="margin-top:8px">Chargement…</div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div id="pagination" style="display:flex;align-items:center;justify-content:center;gap:4px;padding:10px 16px;border-top:1px solid var(--border);flex-shrink:0"></div>

    </main>
</div>

<!-- Toast container -->
<div class="toast-container" id="toasts"></div>

<script>
const SEVERITIES  = <?= json_encode(SEVERITIES) ?>;
const SEV_COLORS  = ['#f85149','#f85149','#f85149','#f0883e','#d29922','#58a6ff','#3fb950','#8b949e'];
const SEV_SHORT   = ['EMRG','ALRT','CRIT','ERR','WARN','NOTC','INFO','DBG'];
const SEV_FULL    = ['Emergency','Alert','Critical','Error','Warning','Notice','Info','Debug'];
const OS_ICONS    = {linux:'🐧', windows:'🪟', macos:'🍎', other:'💻'};

let state = {
    host: '', severity: '', program: '', search: '',
    os: '', period: '24h', page: 1, paused: false,
    expandedId: null, expandedIdx: null,
};
let autoRefreshTimer = null;
let searchDebounce   = null;
let currentLogs      = [];
let lastTopId        = null;
let newLogsCount     = 0;
let failCount        = 0;

// ── Fetch ─────────────────────────────────────────────────────
async function fetchLogs(silent = false) {
    const p = new URLSearchParams();
    if (state.host)         p.set('host',     state.host);
    if (state.severity !== '') p.set('severity', state.severity);
    if (state.program)      p.set('program',  state.program);
    if (state.search)       p.set('search',   state.search);
    if (state.period)       p.set('period',   state.period);
    if (state.os)           p.set('os',       state.os);
    p.set('page',  state.page);
    p.set('limit', 100);

    try {
        const res  = await fetch('/api/logs.php?' + p);
        const data = await res.json();
        currentLogs = data.logs;
        if (failCount >= 3) setLiveStatus(true);
        failCount = 0;

        if (silent && state.page === 1 && data.logs.length > 0) {
            const topId = data.logs[0]?.id;
            if (lastTopId && topId && topId !== lastTopId) {
                newLogsCount = data.logs.filter(l => l.id > lastTopId).length;
                showNewLogsBanner(newLogsCount);
                return;
            }
            lastTopId = topId;
        } else {
            lastTopId = data.logs[0]?.id ?? null;
            hideNewLogsBanner();
        }

        renderLogs(data.logs, data.total);
        renderHosts(data.hosts, data.total);
        renderSevCounts(data.logs);
        renderOsCounts(data.logs);
        renderActiveFilters();
        renderPagination(data.page, data.pages, data.total);
        updateURL();
    } catch(e) {
        failCount++;
        if (failCount >= 3) setLiveStatus(false);
        if (!silent) toast('Erreur de chargement', 'error');
    }
}

function refreshAndDismiss() {
    hideNewLogsBanner();
    state.page = 1;
    fetchLogs();
}

function showNewLogsBanner(n) {
    const bar = document.getElementById('new-logs-bar');
    document.getElementById('new-logs-text').textContent =
        `↑ ${n} nouveau${n>1?'x':''} log${n>1?'s':''} — cliquer pour actualiser`;
    bar.classList.add('visible');
}
function hideNewLogsBanner() {
    document.getElementById('new-logs-bar').classList.remove('visible');
    newLogsCount = 0;
}

// ── Render logs ───────────────────────────────────────────────
function renderLogs(logs, total) {
    document.getElementById('filter-count').textContent =
        total.toLocaleString('fr') + ' log' + (total > 1 ? 's' : '');

    const tbody = document.getElementById('log-tbody');
    if (!logs.length) {
        tbody.innerHTML = `<div style="padding:60px 20px;text-align:center;color:var(--text-dim)">
            <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div style="margin-top:8px">Aucun log trouvé</div></div>`;
        return;
    }

    const srch = state.search.toLowerCase();
    tbody.innerHTML = logs.map((log, i) => {
        const sev   = parseInt(log.severity);
        const ts    = fmtTs(log.received_at);
        const rel   = fmtRel(log.received_at);
        const osIco = OS_ICONS[log.os] || OS_ICONS.other;
        let   msg   = escHtml(log.message);
        if (srch) msg = msg.replace(new RegExp(escRe(escHtml(srch)), 'gi'),
            m => `<mark class="highlight">${m}</mark>`);

        return `<div class="log-row sev-${sev}" data-idx="${i}" data-id="${log.id}" onclick="toggleExpand(this,${i})">
    <div class="log-row-meta">
        <span class="log-sev-chip sev-chip-${sev}">${SEV_SHORT[sev] ?? sev}</span>
        <span class="log-ts" title="${escHtml(log.received_at)}">${ts}</span>
        <span class="log-rel">${rel}</span>
        <span class="log-sep">│</span>
        <span class="log-os" title="${escHtml(log.os)}">${osIco}</span>
        <div class="log-meta-tags">
            <span class="tag tag-host" onclick="filterHost('${escHtml(log.host)}',event)">${escHtml(log.host)}</span>
            <span class="log-sep" style="opacity:.4">›</span>
            <span class="tag tag-program" onclick="filterProgram('${escHtml(log.program)}',event)">${escHtml(log.program) || '—'}</span>
        </div>
    </div>
    <div class="log-row-msg">${msg}</div>
    <button class="log-copy-btn" onclick="copyLog(${i},event)" title="Copier">⧉ copier</button>
</div>`;
    }).join('');
}

// ── Expand ────────────────────────────────────────────────────
function toggleExpand(row, idx) {
    const existing = row.nextElementSibling;
    if (existing?.classList.contains('log-detail-panel')) {
        existing.remove();
        state.expandedId = null; state.expandedIdx = null;
        return;
    }
    document.querySelectorAll('.log-detail-panel').forEach(p => p.remove());

    const log = currentLogs[idx];
    if (!log) return;
    state.expandedId  = log.id;
    state.expandedIdx = idx;

    const sev   = parseInt(log.severity);
    const panel = document.createElement('div');
    panel.className = 'log-detail-panel';

    // Champs : [key, value, colorStyle?] — pas de HTML brut, escHtml sur toutes les valeurs
    const fields = [
        ['timestamp', log.received_at],
        ['host',      log.host],
        ['source_ip', log.source_ip || '—'],
        ['os',        (OS_ICONS[log.os] || '') + ' ' + (log.os || '—')],
        ['severity',  (SEV_FULL[sev] ?? String(sev)) + ' (' + sev + ')', SEV_COLORS[sev]],
        ['facility',  String(log.facility ?? '—')],
        ['program',   log.program + (log.pid ? ' [' + log.pid + ']' : '') || '—'],
        ['source',    log.source || '—'],
    ];

    panel.innerHTML = `
    <div class="log-detail-grid">${
        fields.map(([k, v, col]) =>
            `<div class="ldf"><span class="ldf-k">${k}</span><span class="ldf-v"${col ? ` style="color:${col}"` : ''}>${escHtml(String(v))}</span></div>`
        ).join('')
    }</div>
    <div class="log-detail-msg">${escHtml(log.message)}</div>
    <div class="log-detail-actions">
        <button class="btn-detail" data-action="copy">⧉ Copier ligne</button>
        <button class="btn-detail" data-action="filter-host">⬦ Filtrer hôte</button>
        <button class="btn-detail" data-action="filter-prog">⬦ Filtrer programme</button>
        <span style="margin-left:auto;font-size:10px;color:var(--text-dim)">↑↓ naviguer</span>
    </div>`;

    // Event listeners via data-action (évite les injections via onclick)
    panel.querySelector('[data-action="copy"]').addEventListener('click', () => copyFullLog(idx));
    panel.querySelector('[data-action="filter-host"]').addEventListener('click', e => { e.stopPropagation(); filterHost(log.host, e); });
    panel.querySelector('[data-action="filter-prog"]').addEventListener('click', e => { e.stopPropagation(); filterProgram(log.program, e); });

    row.after(panel);
}

// ── Navigation clavier dans le panneau de détail ──────────────
function navigateDetail(dir) {
    const next = (state.expandedIdx ?? 0) + dir;
    if (next < 0 || next >= currentLogs.length) return;
    document.querySelectorAll('.log-detail-panel').forEach(p => p.remove());
    state.expandedId = null; state.expandedIdx = null;
    const nextRow = document.querySelector(`.log-row[data-idx="${next}"]`);
    if (nextRow) {
        toggleExpand(nextRow, next);
        nextRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// ── Counts sidebar ────────────────────────────────────────────
function renderSevCounts(logs) {
    const counts = {};
    logs.forEach(l => { counts[l.severity] = (counts[l.severity] || 0) + 1; });
    Object.keys(SEVERITIES).forEach(k => {
        const el = document.getElementById('sev-count-' + k);
        if (el) el.textContent = counts[k] ? counts[k].toLocaleString('fr') : '';
    });

    // Severity bar
    const total = logs.length || 1;
    const segs  = SEV_COLORS.map((c, k) => {
        const pct = ((counts[k] || 0) / total * 100).toFixed(1);
        return pct > 0 ? `<div class="sev-bar-seg" style="flex:${pct};background:${c}" title="${SEV_FULL[k]}: ${counts[k]||0}"></div>` : '';
    }).join('');
    const bar = document.getElementById('sev-bar');
    if (bar) bar.innerHTML = segs || '<div style="flex:1;background:var(--border);border-radius:4px"></div>';
}

function renderOsCounts(logs) {
    const counts = {};
    logs.forEach(l => { const o = l.os || 'other'; counts[o] = (counts[o] || 0) + 1; });
    ['linux','windows','macos'].forEach(os => {
        const el = document.getElementById('os-count-' + os);
        if (el) el.textContent = (counts[os] || 0).toLocaleString('fr');
    });
    const allEl = document.getElementById('os-count-all');
    if (allEl) allEl.textContent = logs.length.toLocaleString('fr');
}

// ── Hosts sidebar ─────────────────────────────────────────────
function renderHosts(hosts, total) {
    document.getElementById('total-count').textContent = total.toLocaleString('fr');
    const palette = ['#58a6ff','#3fb950','#a371f7','#f0883e','#d29922','#79c0ff','#56d364'];
    const list    = document.getElementById('host-list');
    list.querySelectorAll('[data-host]:not([data-host=""])').forEach(e => e.remove());

    hosts.forEach((h, i) => {
        const div = document.createElement('div');
        div.className  = 'host-item' + (state.host === h.host ? ' active' : '');
        div.dataset.host = h.host;
        const color    = palette[i % palette.length];
        div.innerHTML  = `
            <div class="host-dot" style="background:${color}"></div>
            <span class="host-name" title="${escHtml(h.host)}">${escHtml(h.host)}</span>
            <span class="host-count">${Number(h.cnt).toLocaleString('fr')}</span>`;
        div.addEventListener('click', () => {
            state.host = state.host === h.host ? '' : h.host;
            state.page = 1; updateSidebar(); fetchLogs();
        });
        list.appendChild(div);
    });
    list.querySelector('[data-host=""]').classList.toggle('active', state.host === '');
}

function updateSidebar() {
    document.querySelectorAll('.host-item').forEach(el =>
        el.classList.toggle('active', el.dataset.host === state.host));
    document.querySelectorAll('.sev-filter-item').forEach(el =>
        el.classList.toggle('active', el.dataset.sev == state.severity));
    document.querySelectorAll('.os-filter-item').forEach(el =>
        el.classList.toggle('active', el.dataset.os === state.os));
}

// ── Active filter chips ───────────────────────────────────────
function renderActiveFilters() {
    const wrap = document.getElementById('active-filters');
    if (!wrap) return;
    const chips = [];
    if (state.host)     chips.push(['🖥 ' + state.host,   () => { state.host=''; state.page=1; updateSidebar(); fetchLogs(); }]);
    if (state.program)  chips.push(['⚙ ' + state.program, () => { state.program=''; document.getElementById('filter-program').value=''; state.page=1; fetchLogs(); }]);
    if (state.os)       chips.push([(OS_ICONS[state.os]||'') + ' ' + state.os, () => { state.os=''; state.page=1; updateSidebar(); fetchLogs(); }]);
    if (state.severity !== '') chips.push(['⚠ ' + (SEV_FULL[state.severity]||state.severity), () => { state.severity=''; state.page=1; updateSidebar(); fetchLogs(); }]);

    wrap.innerHTML = chips.map(([label], i) =>
        `<span class="filter-chip" onclick="clearChip(${i})">
            ${escHtml(label)}
            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </span>`).join('');

    wrap._handlers = chips.map(([,fn]) => fn);
}
function clearChip(i) {
    const fn = document.getElementById('active-filters')._handlers?.[i];
    if (fn) fn();
}

// ── Sidebar events ────────────────────────────────────────────
document.querySelectorAll('.sev-filter-item').forEach(el =>
    el.addEventListener('click', () => {
        state.severity = state.severity == el.dataset.sev ? '' : el.dataset.sev;
        state.page = 1; updateSidebar(); fetchLogs();
    }));

document.querySelector('.host-item[data-host=""]').addEventListener('click', () => {
    state.host = ''; state.page = 1; updateSidebar(); fetchLogs();
});

document.querySelectorAll('.os-filter-item').forEach(el =>
    el.addEventListener('click', () => {
        state.os = state.os === el.dataset.os ? '' : el.dataset.os;
        state.page = 1; updateSidebar(); fetchLogs();
    }));

// ── Filter bar ────────────────────────────────────────────────
document.getElementById('filter-search').addEventListener('input', e => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { state.search = e.target.value.trim(); state.page = 1; fetchLogs(); }, 350);
});

document.getElementById('filter-program').addEventListener('input', e => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { state.program = e.target.value.trim(); state.page = 1; fetchLogs(); }, 350);
});

document.querySelectorAll('.time-btn').forEach(btn =>
    btn.addEventListener('click', () => {
        document.querySelectorAll('.time-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.period = btn.dataset.period; state.page = 1; fetchLogs();
    }));

document.getElementById('btn-refresh').addEventListener('click', () => { hideNewLogsBanner(); fetchLogs(); });

document.getElementById('btn-pause').addEventListener('click', function() {
    state.paused = !state.paused;
    const lbl = document.getElementById('live-indicator');
    if (state.paused) {
        clearInterval(autoRefreshTimer);
        lbl.innerHTML = '<span class="live-dot" style="background:var(--text-dim);animation:none"></span> Pausé';
        this.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>`;
        this.title = 'Reprendre';
    } else {
        startAutoRefresh();
        lbl.innerHTML = '<span class="live-dot"></span> Live';
        this.innerHTML = `<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>`;
        this.title = 'Pause';
    }
});

document.getElementById('btn-export').addEventListener('click', exportCSV);

// ── Click-to-filter ───────────────────────────────────────────
function filterHost(host, e) {
    e.stopPropagation();
    state.host = state.host === host ? '' : host;
    state.page = 1; updateSidebar(); fetchLogs();
}
function filterProgram(prog, e) {
    e.stopPropagation();
    const input = document.getElementById('filter-program');
    state.program = state.program === prog ? '' : prog;
    input.value = state.program;
    state.page = 1; fetchLogs();
}

// ── Pagination ────────────────────────────────────────────────
function renderPagination(page, pages, total) {
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    const btn = (p, label, active=false, disabled=false) =>
        `<button onclick="goPage(${p})" style="padding:4px 10px;border-radius:5px;border:1px solid var(--border);
         background:${active?'rgba(88,166,255,.15)':'transparent'};
         color:${active?'var(--accent)':'var(--text-muted)'};
         cursor:${disabled?'default':'pointer'};font-size:12px;
         ${disabled?'opacity:.4;pointer-events:none':''}">${label}</button>`;
    let html = btn(page-1, '← Préc', false, page===1);
    const start = Math.max(1, page-2), end = Math.min(pages, page+2);
    if (start > 1) html += btn(1,'1') + (start>2?'<span style="color:var(--text-dim);padding:0 4px">…</span>':'');
    for (let i=start; i<=end; i++) html += btn(i, i, i===page);
    if (end < pages) html += (end<pages-1?'<span style="color:var(--text-dim);padding:0 4px">…</span>':'') + btn(pages, pages);
    html += btn(page+1, 'Suiv →', false, page===pages);
    html += `<span style="margin-left:8px;font-size:11px;color:var(--text-dim)">Page ${page}/${pages} · ${total.toLocaleString('fr')} entrées</span>`;
    el.innerHTML = html;
}
function goPage(p) { state.page = p; fetchLogs(); window.scrollTo(0,0); }

// ── Copy ──────────────────────────────────────────────────────
function copyLog(idx, e) {
    e.stopPropagation();
    copyFullLog(idx);
}
function copyFullLog(idx) {
    const log = currentLogs[idx];
    if (!log) return;
    const txt = `[${log.received_at}] ${log.host} ${SEV_FULL[log.severity]??log.severity} ${log.program}: ${log.message}`;
    navigator.clipboard.writeText(txt).then(() => toast('Copié !'));
}

// ── Export CSV ────────────────────────────────────────────────
function exportCSV() {
    const p = new URLSearchParams();
    if (state.host)         p.set('host', state.host);
    if (state.severity !== '') p.set('severity', state.severity);
    if (state.program)      p.set('program', state.program);
    if (state.search)       p.set('search', state.search);
    if (state.period)       p.set('period', state.period);
    if (state.os)           p.set('os', state.os);
    p.set('limit', 5000);
    fetch('/api/logs.php?' + p).then(r => r.json()).then(data => {
        const rows = [['timestamp','host','source_ip','os','severity','facility','program','pid','message']];
        data.logs.forEach(l => rows.push([l.received_at,l.host,l.source_ip,l.os,l.severity,l.facility,l.program,l.pid||'',`"${(l.message||'').replace(/"/g,'""')}"`]));
        const csv = rows.map(r => r.join(',')).join('\n');
        const a = document.createElement('a');
        a.href = 'data:text/csv,' + encodeURIComponent(csv);
        a.download = 'logflow_export.csv';
        a.click();
        toast('Export CSV téléchargé');
    });
}

// ── URL state ─────────────────────────────────────────────────
function updateURL() {
    const p = new URLSearchParams();
    if (state.host)         p.set('host', state.host);
    if (state.severity !== '') p.set('sev', state.severity);
    if (state.program)      p.set('prog', state.program);
    if (state.os)           p.set('os', state.os);
    if (state.period)       p.set('p', state.period);
    if (state.page > 1)     p.set('pg', state.page);
    history.replaceState({}, '', '?' + p);
}
function loadFromURL() {
    const p = new URLSearchParams(location.search);
    if (p.has('host')) state.host = p.get('host');
    if (p.has('sev'))  state.severity = p.get('sev');
    if (p.has('prog')) { state.program = p.get('prog'); document.getElementById('filter-program').value = state.program; }
    if (p.has('os'))   state.os = p.get('os');
    if (p.has('p')) {
        state.period = p.get('p');
        document.querySelectorAll('.time-btn').forEach(b => b.classList.toggle('active', b.dataset.period === state.period));
    }
    if (p.has('pg')) state.page = parseInt(p.get('pg'));
}

// ── Live status indicator ─────────────────────────────────────
function setLiveStatus(ok) {
    if (state.paused) return;
    const lbl = document.getElementById('live-indicator');
    if (!lbl) return;
    if (ok) {
        lbl.innerHTML = '<span class="live-dot"></span> Live';
    } else {
        lbl.innerHTML = '<span class="live-dot" style="background:#f0883e;animation:none"></span> Reconnexion…';
    }
}

// ── Auto-refresh ──────────────────────────────────────────────
function startAutoRefresh() {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = setInterval(() => {
        if (!state.paused) fetchLogs(state.page === 1);
    }, 15000);
}

// ── Timestamps ───────────────────────────────────────────────
function fmtTs(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ','T'));
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    const h = String(d.getHours()).padStart(2,'0');
    const m = String(d.getMinutes()).padStart(2,'0');
    const sec = String(d.getSeconds()).padStart(2,'0');
    const time = `${h}:${m}:${sec}`;
    if (sameDay) return time;
    const mo = String(d.getMonth()+1).padStart(2,'0');
    const dy = String(d.getDate()).padStart(2,'0');
    return `${dy}/${mo} ${time}`;
}
function fmtRel(s) {
    if (!s) return '';
    const diff = Math.floor((Date.now() - new Date(s.replace(' ','T')).getTime()) / 1000);
    if (diff < 0)    return 'now';
    if (diff < 60)   return diff + 's';
    if (diff < 3600) return Math.floor(diff/60) + 'm';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    return Math.floor(diff/86400) + 'j';
}

// ── Keyboard shortcuts ────────────────────────────────────────
document.addEventListener('keydown', e => {
    const inInput = document.activeElement.tagName === 'INPUT';
    if (e.key === '/' && !inInput) {
        e.preventDefault(); document.getElementById('filter-search').focus();
    }
    if (e.key === 'Escape') {
        document.getElementById('filter-search').value = '';
        state.search = ''; state.page = 1; fetchLogs();
        document.querySelectorAll('.log-detail-panel').forEach(p => p.remove());
        state.expandedId = null; state.expandedIdx = null;
    }
    if (e.key === 'r' && !inInput) { hideNewLogsBanner(); fetchLogs(); }
    if (e.key === 'p' && !inInput) document.getElementById('btn-pause').click();
    if (e.key === 'ArrowDown' && !inInput && state.expandedIdx !== null) { e.preventDefault(); navigateDetail(1); }
    if (e.key === 'ArrowUp'   && !inInput && state.expandedIdx !== null) { e.preventDefault(); navigateDetail(-1); }
});

// ── Utils ─────────────────────────────────────────────────────
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function escRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
function toast(msg, type='success') {
    const el = document.createElement('div');
    el.className = 'toast';
    el.style.borderLeft = `3px solid ${type==='error'?'var(--red)':'var(--green)'}`;
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 2500);
}

// ── Init ──────────────────────────────────────────────────────
loadFromURL();
updateSidebar();
fetchLogs();
startAutoRefresh();
</script>
</body>
</html>
