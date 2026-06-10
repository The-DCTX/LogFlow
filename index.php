<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';

$pageTitle = APP_NAME . ' — Dashboard';

// Layout par défaut (si aucun layout sauvegardé)
$default_layout = [
  ['id'=>'w1','type'=>'stat',     'title'=>'Total logs',     'cols'=>3,'order'=>0,'filters'=>['period'=>'all'],  'display'=>['aggregate'=>'count','color'=>'#58a6ff']],
  ['id'=>'w2','type'=>'stat',     'title'=>"Aujourd'hui",    'cols'=>3,'order'=>1,'filters'=>['period'=>'today'],'display'=>['aggregate'=>'count','color'=>'#3fb950']],
  ['id'=>'w3','type'=>'stat',     'title'=>'Erreurs 24h',    'cols'=>3,'order'=>2,'filters'=>['period'=>'24h','severity_max'=>3],'display'=>['aggregate'=>'count','color'=>'#f0883e']],
  ['id'=>'w4','type'=>'stat',     'title'=>'Hôtes actifs',   'cols'=>3,'order'=>3,'filters'=>['period'=>'24h'], 'display'=>['aggregate'=>'distinct_hosts','color'=>'#d29922']],
  ['id'=>'w5','type'=>'timeline', 'title'=>'Activité 24h',   'cols'=>8,'order'=>4,'filters'=>['period'=>'24h'], 'display'=>['time_grain'=>'hour']],
  ['id'=>'w6','type'=>'pie',      'title'=>'Sévérité 24h',   'cols'=>4,'order'=>5,'filters'=>['period'=>'24h'], 'display'=>['group_by'=>'severity','limit'=>8]],
  ['id'=>'w7','type'=>'topn',     'title'=>'Top hôtes 24h',  'cols'=>4,'order'=>6,'filters'=>['period'=>'24h'], 'display'=>['group_by'=>'host','limit'=>8]],
  ['id'=>'w8','type'=>'table',    'title'=>'Derniers logs',   'cols'=>8,'order'=>7,'filters'=>['period'=>'1h'],  'display'=>['limit'=>20,'columns'=>['received_at','host','severity','program','message']]],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
/* ── Widget Grid ───────────────────────────────────────────── */
body { overflow:auto }
.dash { padding:20px; max-width:1600px; margin:0 auto }
.widget-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:16px; min-height:100px }
.widget-grid.edit-mode { outline:2px dashed var(--border); outline-offset:4px; border-radius:6px; padding:8px }
.widget { background:var(--surface); border:1px solid var(--border); border-radius:8px; overflow:hidden; min-height:140px; display:flex; flex-direction:column; transition:box-shadow .15s,border-color .15s }
.widget.cols-3  { grid-column:span 3 }
.widget.cols-4  { grid-column:span 4 }
.widget.cols-6  { grid-column:span 6 }
.widget.cols-8  { grid-column:span 8 }
.widget.cols-12 { grid-column:span 12 }
.widget.dragging { opacity:.4 }
.widget.drag-over { border-color:var(--accent); box-shadow:0 0 0 2px rgba(88,166,255,.3) }
.widget-header { padding:10px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.02); flex-shrink:0 }
.widget-title { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.widget-acts { display:flex; gap:4px; align-items:center; opacity:0; pointer-events:none; transition:opacity .15s }
.edit-mode .widget-acts { opacity:1; pointer-events:auto }
.widget-acts button { width:22px; height:22px; border:1px solid var(--border); border-radius:4px; background:transparent; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:11px; padding:0; transition:all .12s }
.widget-acts button:hover { border-color:var(--accent); color:var(--accent) }
.widget-acts button.danger:hover { border-color:var(--red); color:var(--red) }
.drag-handle { cursor:grab; padding:0 2px; color:var(--text-dim); font-size:14px; line-height:1; user-select:none }
.drag-handle:active { cursor:grabbing }
.widget-body { flex:1; position:relative; overflow:hidden }
.widget-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:12px }
.widget-error  { padding:16px; color:var(--red); font-size:12px; text-align:center }
.widget-empty  { padding:24px 16px; color:var(--text-dim); font-size:12px; text-align:center }
/* Stat */
.widget-stat { padding:16px 20px; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; min-height:80px }
.stat-num { font-size:36px; font-weight:700; line-height:1 }
.stat-sub { font-size:10px; color:var(--text-muted); margin-top:6px }
/* TopN */
.topn-row { padding:5px 12px; display:flex; align-items:center; gap:8px }
.topn-rank { font-size:10px; color:var(--text-dim); width:16px; text-align:right; flex-shrink:0 }
.topn-label { flex:1; font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.topn-bar-wrap { width:70px; height:4px; background:var(--border); border-radius:2px; flex-shrink:0 }
.topn-bar { height:100%; border-radius:2px }
.topn-cnt { font-size:11px; color:var(--text-muted); width:48px; text-align:right; flex-shrink:0 }
/* Timeline */
.timeline-canvas-wrap { padding:10px; height:calc(100% - 0px) }
/* Table */
.widget-table-wrap { overflow:auto; max-height:320px }
/* Sec events */
.sec-event-grid { display:flex; flex-wrap:wrap; gap:8px; padding:12px }
.sec-event-card { border-radius:8px; padding:8px 12px; min-width:110px; cursor:pointer; transition:filter .12s }
.sec-event-card:hover { filter:brightness(1.15) }
.sec-event-icon { font-size:18px }
.sec-event-cnt  { font-weight:700; font-size:22px; margin:3px 0; line-height:1 }
.sec-event-lbl  { font-size:9px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em }

/* ── Topbar buttons ────────────────────────────────────────── */
.edit-btn { padding:4px 12px; border-radius:6px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:11px; cursor:pointer; transition:all .12s; display:flex; align-items:center; gap:5px }
.edit-btn:hover { border-color:var(--accent); color:var(--accent) }
.edit-btn.active { background:rgba(88,166,255,.15); border-color:var(--accent); color:var(--accent) }
.add-btn  { padding:4px 12px; border-radius:6px; border:1px solid var(--border); background:rgba(63,185,80,.1); color:var(--green); font-size:11px; cursor:pointer; transition:all .12s; display:none }
.edit-mode-on .add-btn { display:flex; align-items:center; gap:5px }

/* ── Modal ─────────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:1000; align-items:center; justify-content:center; padding:20px }
.modal-overlay.open { display:flex }
.modal-box { background:var(--surface); border:1px solid var(--border); border-radius:10px; width:min(680px,100%); max-height:calc(100vh-40px); display:flex; flex-direction:column; animation:modalIn .15s ease }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} }
.modal-head { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; font-weight:600; font-size:14px; flex-shrink:0 }
.modal-body { overflow-y:auto; padding:16px 18px; flex:1 }
.modal-foot { padding:10px 18px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px; flex-shrink:0 }
.close-btn { width:24px; height:24px; border:none; background:transparent; color:var(--text-muted); cursor:pointer; font-size:16px; border-radius:4px; display:flex; align-items:center; justify-content:center }
.close-btn:hover { background:var(--surface2); color:var(--text) }
/* Form */
.fg { margin-bottom:12px }
.fg label { display:block; font-size:11px; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase; letter-spacing:.05em }
.fi { width:100%; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:var(--text); padding:6px 10px; font-size:12px; outline:none; transition:border-color .15s; font-family:inherit }
.fi:focus { border-color:var(--accent) }
.fi[type=color] { height:34px; padding:2px 4px; cursor:pointer }
.fi-row { display:flex; gap:10px }
.fi-row .fg { flex:1 }
.fsec { margin:14px 0 8px; padding-top:12px; border-top:1px solid var(--border2); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--text-dim) }
/* Type picker */
.type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:4px }
.type-btn { padding:10px 8px; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text-muted); cursor:pointer; text-align:center; transition:all .12s; font-size:11px; font-family:inherit }
.type-btn:hover { border-color:var(--accent); color:var(--text) }
.type-btn.active { border-color:var(--accent); background:rgba(88,166,255,.1); color:var(--accent) }
.type-btn .ti { font-size:18px; display:block; margin-bottom:4px }
/* Checkboxes */
.check-row { display:flex; flex-wrap:wrap; gap:8px }
.check-row label { display:flex; align-items:center; gap:5px; font-size:12px; cursor:pointer; color:var(--text) }
.check-row input[type=checkbox] { accent-color:var(--accent) }
.check-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:4px }
/* Btn */
.btn-prim { padding:7px 18px; border-radius:6px; border:1px solid var(--accent); background:rgba(88,166,255,.15); color:var(--accent); font-size:12px; cursor:pointer; font-family:inherit; transition:all .12s }
.btn-prim:hover { background:rgba(88,166,255,.25) }
.btn-sec  { padding:7px 14px; border-radius:6px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:12px; cursor:pointer; font-family:inherit; transition:all .12s }
.btn-sec:hover { border-color:var(--text-muted); color:var(--text) }
/* Confirm dialog */
.confirm-box { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:20px; max-width:340px; text-align:center }
.confirm-box h4 { margin:0 0 8px; font-size:14px }
.confirm-box p { margin:0 0 16px; color:var(--text-muted); font-size:12px }
.confirm-box .confirm-btns { display:flex; gap:8px; justify-content:center }
</style>
</head>
<body>

<header class="dash-topbar">
    <div class="topbar-brand">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        <?= APP_NAME ?>
    </div>
    <nav class="topbar-nav">
        <a href="/index.php" class="active">Dashboard</a>
        <a href="/logs.php">Logs</a>
        <a href="/security.php">Sécurité</a>
        <a href="/setup.php">Setup</a>
    </nav>
    <div class="topbar-spacer"></div>
    <div style="display:flex;gap:8px;align-items:center" id="topbar-actions">
        <button class="add-btn" onclick="openModal()" id="add-btn" title="Ajouter un widget">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="1" x2="6" y2="11"/><line x1="1" y1="6" x2="11" y2="6"/></svg>
            Ajouter
        </button>
        <button class="edit-btn" id="edit-btn" onclick="toggleEditMode()" title="Modifier le dashboard">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Modifier
        </button>
        <div class="live-indicator"><span class="live-dot"></span> Live</div>
        <div class="vr" style="width:1px;height:18px;background:var(--border);margin:0 4px"></div>
        <span style="font-size:11px;color:var(--text-muted)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg><?= h($_SESSION['logflow_user'] ?? '') ?></span>
        <a href="/logout.php" style="font-size:11px;color:var(--red);text-decoration:none" title="Déconnexion">⏻</a>
    </div>
</header>

<div class="dash" id="dash-root">
    <div class="widget-grid" id="widget-grid"></div>
</div>

<!-- ── Modal Add/Edit ─────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-overlay" onclick="if(event.target===this)closeModal()">
<div class="modal-box">
    <div class="modal-head">
        <span id="modal-title">Ajouter un widget</span>
        <button class="close-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
        <!-- Type -->
        <div class="fg"><label>Type</label>
            <div class="type-grid" id="type-grid"></div>
        </div>

        <!-- Général -->
        <div class="fi-row">
            <div class="fg" style="flex:2"><label>Titre</label>
                <input type="text" id="mf-title" class="fi" placeholder="Nom du widget" maxlength="80">
            </div>
            <div class="fg" style="flex:1"><label>Largeur</label>
                <select id="mf-cols" class="fi">
                    <option value="3">Petite (25%)</option>
                    <option value="4">Normale (33%)</option>
                    <option value="6" selected>Moyenne (50%)</option>
                    <option value="8">Large (66%)</option>
                    <option value="12">Pleine (100%)</option>
                </select>
            </div>
        </div>

        <!-- Filtres -->
        <div class="fsec">Filtres</div>
        <div class="fi-row">
            <div class="fg"><label>Période</label>
                <select id="mf-period" class="fi">
                    <option value="all">Toutes les données</option>
                    <option value="today">Aujourd'hui</option>
                    <option value="1h">Dernière heure</option>
                    <option value="6h">6 dernières heures</option>
                    <option value="24h" selected>24 dernières heures</option>
                    <option value="7d">7 derniers jours</option>
                    <option value="30d">30 derniers jours</option>
                    <option value="90d">90 derniers jours</option>
                </select>
            </div>
            <div class="fg"><label>Sévérité max</label>
                <select id="mf-sev-max" class="fi">
                    <option value="">Toutes</option>
                    <option value="0">0 Emergency</option><option value="1">1 Alert</option>
                    <option value="2">2 Critical</option><option value="3">3 Error</option>
                    <option value="4">4 Warning</option><option value="5">5 Notice</option>
                    <option value="6">6 Info</option><option value="7">7 Debug</option>
                </select>
            </div>
            <div class="fg"><label>Sévérité min</label>
                <select id="mf-sev-min" class="fi">
                    <option value="">Toutes</option>
                    <option value="0">0 Emergency</option><option value="1">1 Alert</option>
                    <option value="2">2 Critical</option><option value="3">3 Error</option>
                    <option value="4">4 Warning</option><option value="5">5 Notice</option>
                    <option value="6">6 Info</option><option value="7">7 Debug</option>
                </select>
            </div>
        </div>
        <div class="fi-row">
            <div class="fg"><label>Hôte (contient)</label>
                <input type="text" id="mf-host" class="fi" placeholder="ex: server1">
            </div>
            <div class="fg"><label>Programme (contient)</label>
                <input type="text" id="mf-program" class="fi" placeholder="ex: sshd">
            </div>
            <div class="fg"><label>IP source (contient)</label>
                <input type="text" id="mf-source-ip" class="fi" placeholder="ex: 192.168">
            </div>
        </div>
        <div class="fi-row">
            <div class="fg"><label>Événement sécurité</label>
                <select id="mf-sec-event" class="fi">
                    <option value="">Tous</option>
                    <option value="__any__">Avec SEC_EVENT (tous)</option>
                    <option value="__none__">Sans SEC_EVENT</option>
                    <optgroup label="Type spécifique" id="mf-sec-event-opts"></optgroup>
                </select>
            </div>
            <div class="fg"><label>OS</label>
                <div class="check-row" style="margin-top:6px">
                    <label><input type="checkbox" class="mf-os" value="linux"> 🐧 Linux</label>
                    <label><input type="checkbox" class="mf-os" value="windows"> 🪟 Win</label>
                    <label><input type="checkbox" class="mf-os" value="macos"> 🍎 macOS</label>
                    <label><input type="checkbox" class="mf-os" value="other"> 💻 Autre</label>
                </div>
            </div>
        </div>

        <!-- Affichage (conditionnel) -->
        <div class="fsec">Affichage</div>

        <div id="mf-row-groupby" class="fi-row" style="display:none">
            <div class="fg"><label>Grouper par</label>
                <select id="mf-group-by" class="fi">
                    <option value="host">Hôte</option>
                    <option value="program">Programme</option>
                    <option value="os">OS</option>
                    <option value="sec_event">Événement sécurité</option>
                    <option value="source_ip">IP source</option>
                    <option value="severity">Sévérité</option>
                    <option value="source">Source (rsyslog/http)</option>
                    <option value="facility">Facilité</option>
                </select>
            </div>
        </div>

        <div id="mf-row-limit" class="fi-row" style="display:none">
            <div class="fg" style="max-width:160px"><label>Nombre de lignes / valeurs</label>
                <input type="number" id="mf-limit" class="fi" min="3" max="100" value="10">
            </div>
        </div>

        <div id="mf-row-grain" class="fi-row" style="display:none">
            <div class="fg" style="max-width:200px"><label>Granularité</label>
                <select id="mf-time-grain" class="fi">
                    <option value="hour">Par heure</option>
                    <option value="day">Par jour</option>
                </select>
            </div>
        </div>

        <div id="mf-row-aggregate" class="fi-row" style="display:none">
            <div class="fg"><label>Agréger</label>
                <select id="mf-aggregate" class="fi">
                    <option value="count">Nombre de logs</option>
                    <option value="distinct_hosts">Hôtes distincts</option>
                    <option value="distinct_programs">Programmes distincts</option>
                    <option value="distinct_ips">IP sources distinctes</option>
                </select>
            </div>
            <div class="fg" style="max-width:120px"><label>Couleur</label>
                <input type="color" id="mf-color" class="fi" value="#58a6ff">
            </div>
        </div>

        <div id="mf-row-columns" style="display:none">
            <div class="fg"><label>Colonnes à afficher</label>
                <div class="check-grid" id="mf-columns-grid"></div>
            </div>
        </div>

    </div>
    <div class="modal-foot">
        <button class="btn-sec" onclick="closeModal()">Annuler</button>
        <button class="btn-prim" onclick="saveWidgetFromModal()">Enregistrer</button>
    </div>
</div>
</div>

<!-- ── Confirm overlay ────────────────────────────────────────── -->
<div class="modal-overlay" id="confirm-overlay" onclick="if(event.target===this)closeConfirm()">
<div class="confirm-box">
    <h4>Supprimer ce widget ?</h4>
    <p>Cette action ne peut pas être annulée.</p>
    <div class="confirm-btns">
        <button class="btn-sec" onclick="closeConfirm()">Annuler</button>
        <button class="btn-prim" style="border-color:var(--red);color:var(--red)" id="confirm-ok-btn">Supprimer</button>
    </div>
</div>
</div>

<!-- ── Toast container ───────────────────────────────────────── -->
<div class="toast-container" id="toasts"></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color        = '#8b949e';
Chart.defaults.borderColor  = '#30363d';

// ── Constants ─────────────────────────────────────────────────
const DEFAULT_LAYOUT = <?= json_encode($default_layout, JSON_UNESCAPED_UNICODE) ?>;

const SEV_COLORS = ['#f85149','#f85149','#f85149','#f0883e','#d29922','#58a6ff','#3fb950','#8b949e'];
const SEV_NAMES  = ['Emergency','Alert','Critical','Error','Warning','Notice','Info','Debug'];
const OS_ICONS   = {linux:'🐧',windows:'🪟',macos:'🍎',other:'💻'};
const OS_COLORS  = {linux:'#f0883e',windows:'#58a6ff',macos:'#a371f7',other:'#8b949e'};
const PALETTE    = ['#58a6ff','#3fb950','#d29922','#f0883e','#f85149','#a371f7','#39c5bb','#ff79c6','#8be9fd','#50fa7b'];

const SEC_EVENTS_LIST = [
  {k:'auth_fail',l:'Échec auth'},{k:'auth_success',l:'Auth réussie'},{k:'auth_brute',l:'Brute-force'},
  {k:'sudo',l:'Sudo'},{k:'sudo_fail',l:'Sudo échoué'},{k:'account_create',l:'Compte créé'},
  {k:'account_delete',l:'Compte supprimé'},{k:'account_lockout',l:'Compte verrouillé'},
  {k:'account_change',l:'Changement compte'},{k:'group_change',l:'Changement groupe'},
  {k:'service_install',l:'Service installé'},{k:'log_cleared',l:'Logs effacés'},
  {k:'ssh_connect',l:'Connexion SSH'},{k:'firewall_block',l:'Blocage firewall'},
  {k:'rdp_connect',l:'Connexion RDP'},{k:'process_suspicious',l:'Processus suspect'},
];

const WIDGET_TYPES = [
  {key:'stat',      icon:'🔢', label:'Compteur KPI'},
  {key:'topn',      icon:'📊', label:'Top N (barres)'},
  {key:'timeline',  icon:'📈', label:'Chronologie'},
  {key:'table',     icon:'📋', label:'Table de logs'},
  {key:'pie',       icon:'🥧', label:'Camembert'},
  {key:'secevents', icon:'🛡️', label:'Évén. sécurité'},
];

const COLUMN_OPTIONS = [
  {v:'received_at',l:'Heure réception'},{v:'log_time',l:'Heure log'},
  {v:'host',l:'Hôte'},{v:'source_ip',l:'IP source'},{v:'facility',l:'Facilité'},
  {v:'severity',l:'Sévérité'},{v:'program',l:'Programme'},{v:'pid',l:'PID'},
  {v:'message',l:'Message'},{v:'os',l:'OS'},{v:'sec_event',l:'Évén. sécurité'},
];

// ── State ─────────────────────────────────────────────────────
let layout      = [];
let editMode    = false;
let dragSrcId   = null;
let editingId   = null;
const charts    = {};
let refreshTmr  = null;

// ── Init ──────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
    populateTypeGrid();
    populateSecEventOpts();
    populateColumnsGrid();

    try {
        const r = await fetch('api/widget_layout.php');
        const j = await r.json();
        layout  = j.layout || DEFAULT_LAYOUT;
    } catch { layout = DEFAULT_LAYOUT; }

    renderGrid();
    startRefresh();
});

// ── Layout ────────────────────────────────────────────────────
async function saveLayout() {
    try {
        await fetch('api/widget_layout.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({layout}),
        });
        toast('Dashboard sauvegardé');
    } catch { toast('Erreur sauvegarde', 'error'); }
}

// ── Grid ──────────────────────────────────────────────────────
function renderGrid() {
    // Détruire anciens charts
    Object.keys(charts).forEach(id => { charts[id].destroy(); delete charts[id]; });

    const grid = document.getElementById('widget-grid');
    grid.innerHTML = '';
    grid.className = 'widget-grid' + (editMode ? ' edit-mode' : '');

    const sorted = [...layout].sort((a, b) => a.order - b.order);
    sorted.forEach(w => {
        const el = createWidgetEl(w);
        grid.appendChild(el);
        fetchWidgetData(w);
    });

    document.getElementById('dash-root').className = editMode ? 'dash edit-mode-on' : 'dash';
    const eb = document.getElementById('edit-btn');
    eb.classList.toggle('active', editMode);
}

function createWidgetEl(w) {
    const div = document.createElement('div');
    div.className = `widget cols-${w.cols || 6}`;
    div.dataset.id = w.id;
    div.draggable  = editMode;

    div.innerHTML = `
        <div class="widget-header">
            <span class="widget-title">${escHtml(w.title || w.type)}</span>
            <div class="widget-acts">
                <button title="Modifier" onclick="openModal('${w.id}')">✎</button>
                <button class="danger" title="Supprimer" onclick="confirmDelete('${w.id}')">✕</button>
                <span class="drag-handle" title="Déplacer">⠿</span>
            </div>
        </div>
        <div class="widget-body" id="wb-${w.id}">
            <div class="widget-loading">Chargement…</div>
        </div>
    `;

    div.addEventListener('dragstart', handleDragStart);
    div.addEventListener('dragover',  handleDragOver);
    div.addEventListener('drop',      handleDrop);
    div.addEventListener('dragend',   handleDragEnd);
    return div;
}

// ── Fetch ─────────────────────────────────────────────────────
async function fetchWidgetData(widget) {
    const body = document.getElementById('wb-' + widget.id);
    if (!body) return;
    try {
        const r = await fetch('api/widget_data.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({widget}),
        });
        const j = await r.json();
        if (j.ok) renderWidgetContent(widget, j.data, body);
        else body.innerHTML = `<div class="widget-error">⚠ ${escHtml(j.error || 'Erreur')}</div>`;
    } catch {
        body.innerHTML = '<div class="widget-error">⚠ Erreur réseau</div>';
    }
}

function renderWidgetContent(w, data, el) {
    switch (w.type) {
        case 'stat':      renderStat(w, data, el);      break;
        case 'topn':      renderTopN(w, data, el);      break;
        case 'timeline':  renderTimeline(w, data, el);  break;
        case 'table':     renderTable(w, data, el);     break;
        case 'pie':       renderPie(w, data, el);       break;
        case 'secevents': renderSecEvents(w, data, el); break;
        default: el.innerHTML = '<div class="widget-error">Type inconnu</div>';
    }
}

// ── Renderers ─────────────────────────────────────────────────
function renderStat(w, data, el) {
    const val   = (data.value ?? 0).toLocaleString('fr');
    const color = w.display?.color || '#58a6ff';
    el.innerHTML = `<div class="widget-stat">
        <div class="stat-num" style="color:${escHtml(color)}">${val}</div>
        <div class="stat-sub">${escHtml(aggrLabel(w.display?.aggregate))} · ${escHtml(periodLabel(w.filters?.period))}</div>
    </div>`;
}

function renderTopN(w, data, el) {
    const rows = data.rows || [];
    if (!rows.length) { el.innerHTML = '<div class="widget-empty">Aucune donnée</div>'; return; }
    const max  = parseInt(rows[0].cnt) || 1;
    const grp  = data.group_by || 'host';
    let html = '<div style="padding:6px 0">';
    rows.forEach((r, i) => {
        const cnt  = parseInt(r.cnt);
        const pct  = Math.round(cnt / max * 100);
        const col  = getColor(grp, r.label, i);
        html += `<div class="topn-row">
            <span class="topn-rank">${i+1}</span>
            <span class="topn-label" title="${escHtml(r.label)}">${formatVal(grp, r.label)}</span>
            <div class="topn-bar-wrap"><div class="topn-bar" style="width:${pct}%;background:${col}"></div></div>
            <span class="topn-cnt">${cnt.toLocaleString('fr')}</span>
        </div>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

function renderTimeline(w, data, el) {
    destroyChart(w.id);
    const cid = 'ch_' + w.id;
    el.innerHTML = `<div class="timeline-canvas-wrap" style="height:100%;min-height:120px"><canvas id="${cid}"></canvas></div>`;
    const labels = (data.labels || []).map(l => data.grain === 'day' ? l.slice(5) : l.slice(11,16));
    charts[w.id] = new Chart(document.getElementById(cid), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: data.values || [],
                backgroundColor: 'rgba(88,166,255,.3)',
                borderColor: '#58a6ff',
                borderWidth: 1,
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {legend:{display:false}},
            scales: {
                x:{grid:{display:false}, ticks:{font:{size:9}, maxTicksLimit:12}},
                y:{grid:{color:'#21262d'}, ticks:{font:{size:9}}},
            }
        }
    });
}

function renderTable(w, data, el) {
    const cols = data.columns || ['received_at','host','severity','program','message'];
    const rows = data.rows || [];
    if (!rows.length) { el.innerHTML = '<div class="widget-empty">Aucun log</div>'; return; }
    const COL_LBL = {received_at:'Heure',log_time:'Log time',host:'Hôte',source_ip:'IP',facility:'Fac.',severity:'Sév.',program:'Programme',pid:'PID',message:'Message',source:'Source',os:'OS',sec_event:'SEC'};
    let html = '<div class="widget-table-wrap"><table class="log-table"><thead><tr>';
    cols.forEach(c => { html += `<th>${escHtml(COL_LBL[c]||c)}</th>`; });
    html += '</tr></thead><tbody>';
    rows.forEach(row => {
        const sev = parseInt(row.severity ?? 6);
        html += `<tr class="sev-${sev}" style="cursor:pointer" onclick="location.href='/logs.php?host=${encodeURIComponent(row.host||'')}'">`;
        cols.forEach(c => {
            let v = row[c] ?? '';
            if (c === 'severity') {
                v = `<span class="sev-badge sev-${sev}">${escHtml(SEV_NAMES[sev]||sev)}</span>`;
            } else if (c === 'received_at' || c === 'log_time') {
                v = `<span class="td-time">${escHtml(String(v).slice(0,16))}</span>`;
            } else if (c === 'host') {
                v = `<span class="tag tag-host">${escHtml(String(v))}</span>`;
            } else if (c === 'program') {
                v = `<span class="tag tag-program">${escHtml(String(v))}</span>`;
            } else if (c === 'source_ip') {
                v = `<span class="tag tag-ip">${escHtml(String(v))}</span>`;
            } else if (c === 'message') {
                v = `<div class="msg-text">${escHtml(String(v))}</div>`;
            } else if (c === 'os') {
                v = OS_ICONS[v] || escHtml(String(v));
            } else if (c === 'sec_event' && v) {
                v = `<span style="background:rgba(248,81,73,.15);color:#f85149;padding:1px 6px;border-radius:4px;font-size:10px;white-space:nowrap">${escHtml(String(v))}</span>`;
            } else {
                v = escHtml(String(v));
            }
            html += `<td>${v}</td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderPie(w, data, el) {
    destroyChart(w.id);
    const rows = data.rows || [];
    if (!rows.length) { el.innerHTML = '<div class="widget-empty">Aucune donnée</div>'; return; }
    const grp  = data.group_by || 'host';
    const cid  = 'ch_' + w.id;
    el.innerHTML = `<div style="padding:8px;display:flex;align-items:center;justify-content:center;height:100%;min-height:140px"><canvas id="${cid}" style="max-height:220px"></canvas></div>`;
    const labels = rows.map((r, i) => formatVal(grp, r.label));
    const values = rows.map(r  => parseInt(r.cnt));
    const colors = rows.map((r, i) => getColor(grp, r.label, i));
    charts[w.id] = new Chart(document.getElementById(cid), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 4 }] },
        options: {
            responsive: true,
            plugins: { legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } } }
        }
    });
}

function renderSecEvents(w, data, el) {
    const events = data.events || [];
    if (!events.length) { el.innerHTML = '<div class="widget-empty">Aucun événement de sécurité sur la période</div>'; return; }
    let html = '<div class="sec-event-grid">';
    events.forEach(ev => {
        const rgb = hexToRgb(ev.color);
        html += `<div class="sec-event-card"
                     style="background:rgba(${rgb},0.1);border:1px solid rgba(${rgb},0.3)"
                     onclick="location.href='/security.php'" title="${escHtml(ev.type)}">
            <div class="sec-event-icon">${ev.icon}</div>
            <div class="sec-event-cnt" style="color:${escHtml(ev.color)}">${ev.cnt.toLocaleString('fr')}</div>
            <div class="sec-event-lbl">${escHtml(ev.label)}</div>
        </div>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

// ── Edit mode ─────────────────────────────────────────────────
function toggleEditMode() {
    editMode = !editMode;
    renderGrid();
}

// ── Modal ─────────────────────────────────────────────────────
function openModal(widgetId = null) {
    editingId = widgetId;
    const overlay = document.getElementById('modal-overlay');
    document.getElementById('modal-title').textContent = widgetId ? 'Modifier le widget' : 'Ajouter un widget';

    if (widgetId) {
        const w = layout.find(x => x.id === widgetId);
        if (!w) return;
        populateModalForm(w);
    } else {
        resetModalForm();
    }

    overlay.classList.add('open');
    syncTypeFields();
}

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
    editingId = null;
}

function resetModalForm() {
    setActiveType('stat');
    document.getElementById('mf-title').value        = '';
    document.getElementById('mf-cols').value          = '6';
    document.getElementById('mf-period').value        = '24h';
    document.getElementById('mf-sev-max').value       = '';
    document.getElementById('mf-sev-min').value       = '';
    document.getElementById('mf-host').value          = '';
    document.getElementById('mf-program').value       = '';
    document.getElementById('mf-source-ip').value     = '';
    document.getElementById('mf-sec-event').value     = '';
    document.getElementById('mf-group-by').value      = 'host';
    document.getElementById('mf-limit').value         = '10';
    document.getElementById('mf-time-grain').value    = 'hour';
    document.getElementById('mf-aggregate').value     = 'count';
    document.getElementById('mf-color').value         = '#58a6ff';
    document.querySelectorAll('.mf-os').forEach(cb => cb.checked = false);
    document.querySelectorAll('.mf-col').forEach(cb => cb.checked = ['received_at','host','severity','program','message'].includes(cb.value));
}

function populateModalForm(w) {
    setActiveType(w.type || 'stat');
    document.getElementById('mf-title').value        = w.title || '';
    document.getElementById('mf-cols').value          = w.cols || 6;
    const f = w.filters || {}, d = w.display || {};
    document.getElementById('mf-period').value        = f.period        || '24h';
    document.getElementById('mf-sev-max').value       = f.severity_max != null ? f.severity_max : '';
    document.getElementById('mf-sev-min').value       = f.severity_min != null ? f.severity_min : '';
    document.getElementById('mf-host').value          = f.host          || '';
    document.getElementById('mf-program').value       = f.program       || '';
    document.getElementById('mf-source-ip').value     = f.source_ip     || '';
    document.getElementById('mf-sec-event').value     = f.sec_event     || '';
    document.getElementById('mf-group-by').value      = d.group_by      || 'host';
    document.getElementById('mf-limit').value         = d.limit         || 10;
    document.getElementById('mf-time-grain').value    = d.time_grain    || 'hour';
    document.getElementById('mf-aggregate').value     = d.aggregate     || 'count';
    document.getElementById('mf-color').value         = d.color         || '#58a6ff';
    const os = f.os || [];
    document.querySelectorAll('.mf-os').forEach(cb => cb.checked = os.includes(cb.value));
    const cols = d.columns || ['received_at','host','severity','program','message'];
    document.querySelectorAll('.mf-col').forEach(cb => cb.checked = cols.includes(cb.value));
}

function readModalForm() {
    const type  = document.querySelector('.type-btn.active')?.dataset.type || 'stat';
    const title = document.getElementById('mf-title').value.trim() || WIDGET_TYPES.find(t=>t.key===type)?.label || type;
    const f = {
        period:       document.getElementById('mf-period').value,
        severity_max: document.getElementById('mf-sev-max').value !== '' ? parseInt(document.getElementById('mf-sev-max').value) : null,
        severity_min: document.getElementById('mf-sev-min').value !== '' ? parseInt(document.getElementById('mf-sev-min').value) : null,
        host:         document.getElementById('mf-host').value.trim(),
        program:      document.getElementById('mf-program').value.trim(),
        source_ip:    document.getElementById('mf-source-ip').value.trim(),
        sec_event:    document.getElementById('mf-sec-event').value,
        os:           [...document.querySelectorAll('.mf-os:checked')].map(cb => cb.value),
    };
    const d = {
        group_by:   document.getElementById('mf-group-by').value,
        limit:      parseInt(document.getElementById('mf-limit').value) || 10,
        time_grain: document.getElementById('mf-time-grain').value,
        aggregate:  document.getElementById('mf-aggregate').value,
        color:      document.getElementById('mf-color').value,
        columns:    [...document.querySelectorAll('.mf-col:checked')].map(cb => cb.value),
    };
    return { type, title, cols: parseInt(document.getElementById('mf-cols').value), filters: f, display: d };
}

function saveWidgetFromModal() {
    const config = readModalForm();
    if (editingId) {
        const idx = layout.findIndex(w => w.id === editingId);
        if (idx !== -1) layout[idx] = { ...layout[idx], ...config };
    } else {
        config.id    = 'w_' + Date.now().toString(36);
        config.order = layout.length;
        layout.push(config);
    }
    closeModal();
    renderGrid();
    saveLayout();
}

function syncTypeFields() {
    const type = document.querySelector('.type-btn.active')?.dataset.type || 'stat';
    const show = (id, cond) => { const el = document.getElementById(id); if (el) el.style.display = cond ? '' : 'none'; };
    show('mf-row-groupby',   type === 'topn' || type === 'pie');
    show('mf-row-limit',     type === 'topn' || type === 'table');
    show('mf-row-grain',     type === 'timeline');
    show('mf-row-aggregate', type === 'stat');
    show('mf-row-columns',   type === 'table');
}

function setActiveType(type) {
    document.querySelectorAll('.type-btn').forEach(b => b.classList.toggle('active', b.dataset.type === type));
    syncTypeFields();
}

// ── Confirm delete ────────────────────────────────────────────
function confirmDelete(widgetId) {
    document.getElementById('confirm-overlay').classList.add('open');
    document.getElementById('confirm-ok-btn').onclick = () => { deleteWidget(widgetId); closeConfirm(); };
}
function closeConfirm() { document.getElementById('confirm-overlay').classList.remove('open'); }
function deleteWidget(id) {
    layout = layout.filter(w => w.id !== id);
    layout.forEach((w, i) => w.order = i);
    renderGrid();
    saveLayout();
}

// ── Drag & Drop ───────────────────────────────────────────────
function handleDragStart(e) {
    if (!editMode) { e.preventDefault(); return; }
    dragSrcId = this.dataset.id;
    e.dataTransfer.effectAllowed = 'move';
    setTimeout(() => this.classList.add('dragging'), 0);
}
function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (this.dataset.id !== dragSrcId) {
        document.querySelectorAll('.widget.drag-over').forEach(w => w.classList.remove('drag-over'));
        this.classList.add('drag-over');
    }
}
function handleDrop(e) {
    e.stopPropagation();
    const targetId = this.dataset.id;
    if (!dragSrcId || dragSrcId === targetId) return;
    const si = layout.findIndex(w => w.id === dragSrcId);
    const ti = layout.findIndex(w => w.id === targetId);
    if (si === -1 || ti === -1) return;
    const [removed] = layout.splice(si, 1);
    layout.splice(ti, 0, removed);
    layout.forEach((w, i) => w.order = i);
    renderGrid();
    saveLayout();
}
function handleDragEnd() {
    document.querySelectorAll('.widget').forEach(w => w.classList.remove('dragging','drag-over'));
    dragSrcId = null;
}

// ── Auto-refresh ──────────────────────────────────────────────
function startRefresh() {
    refreshTmr = setInterval(() => {
        if (!editMode) layout.forEach(w => fetchWidgetData(w));
    }, 30000);
}

// ── DOM builders ──────────────────────────────────────────────
function populateTypeGrid() {
    const grid = document.getElementById('type-grid');
    WIDGET_TYPES.forEach(t => {
        const btn = document.createElement('button');
        btn.className   = 'type-btn' + (t.key === 'stat' ? ' active' : '');
        btn.dataset.type = t.key;
        btn.innerHTML   = `<span class="ti">${t.icon}</span>${t.label}`;
        btn.onclick     = () => setActiveType(t.key);
        grid.appendChild(btn);
    });
}
function populateSecEventOpts() {
    const grp = document.getElementById('mf-sec-event-opts');
    SEC_EVENTS_LIST.forEach(e => {
        const opt = document.createElement('option');
        opt.value = e.k; opt.textContent = e.l + ' (' + e.k + ')';
        grp.appendChild(opt);
    });
}
function populateColumnsGrid() {
    const grid = document.getElementById('mf-columns-grid');
    const def  = ['received_at','host','severity','program','message'];
    COLUMN_OPTIONS.forEach(c => {
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;color:var(--text)';
        label.innerHTML = `<input type="checkbox" class="mf-col fi" value="${c.v}" ${def.includes(c.v)?'checked':''} style="accent-color:var(--accent)"> ${c.l}`;
        grid.appendChild(label);
    });
}

// ── Helpers ───────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function destroyChart(id) {
    if (charts[id]) { charts[id].destroy(); delete charts[id]; }
}
function hexToRgb(hex) {
    const h = hex.replace('#','');
    return `${parseInt(h.slice(0,2),16)},${parseInt(h.slice(2,4),16)},${parseInt(h.slice(4,6),16)}`;
}
function getColor(grp, val, idx) {
    if (grp === 'severity') return SEV_COLORS[parseInt(val)] || SEV_COLORS[7];
    if (grp === 'os')       return OS_COLORS[val] || PALETTE[idx % PALETTE.length];
    return PALETTE[idx % PALETTE.length];
}
function formatVal(grp, val) {
    if (grp === 'severity') return `<span class="sev-badge sev-${val}">${escHtml(SEV_NAMES[val]||val)}</span>`;
    if (grp === 'os')       return (OS_ICONS[val]||'') + ' ' + escHtml(val||'null');
    if (!val || val === 'null') return '<span style="color:var(--text-dim)">—</span>';
    return escHtml(String(val));
}
function aggrLabel(a) {
    return {count:'logs',distinct_hosts:'hôtes',distinct_programs:'programmes',distinct_ips:'IP'}[a] || 'logs';
}
function periodLabel(p) {
    return {all:'tous',today:"aujourd'hui",'1h':'1h','6h':'6h','24h':'24h','7d':'7j','30d':'30j','90d':'90j'}[p] || p || '24h';
}
function toast(msg, type = 'ok') {
    const c = document.getElementById('toasts');
    const d = document.createElement('div');
    d.className = 'toast';
    d.textContent = msg;
    if (type === 'error') d.style.borderColor = 'var(--red)';
    c.appendChild(d);
    setTimeout(() => d.remove(), 3000);
}
</script>
</body>
</html>
