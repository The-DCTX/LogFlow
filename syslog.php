<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/devices.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/auth.php';

$db = db();

// ── Période sélectionnée pour les compteurs des cartes ──────────────────────
$PERIODS = ['1h' => 60, '6h' => 360, '24h' => 1440, '7d' => 10080, '30d' => 43200, 'all' => 0];
$period  = isset($_GET['period'], $PERIODS[$_GET['period']]) ? $_GET['period'] : '24h';
$mins    = $PERIODS[$period];
$cond    = $mins ? 'received_at >= NOW() - INTERVAL ? MINUTE' : '1=1';
$args    = $mins ? [$mins] : [];

$statCols = "COUNT(*) total, MAX(received_at) last_seen,
             SUM(severity<=3) crit, SUM(severity=4) warn, SUM(severity>=5) info";

$byHost = [];
$st = $db->prepare("SELECT host, MAX(source_ip) source_ip, $statCols, SUM(source='syslog') syslog_cnt FROM logs WHERE $cond GROUP BY host");
$st->execute($args);
foreach ($st->fetchAll() as $r) $byHost[$r['host']] = $r;

$byIp = [];
$st = $db->prepare("SELECT source_ip, $statCols FROM logs WHERE $cond GROUP BY source_ip");
$st->execute($args);
foreach ($st->fetchAll() as $r) $byIp[$r['source_ip']] = $r;

// Stats d'un appareil : on agrège par IP source si la valeur correspond à une IP
// vue (cas d'un NAS émettant sous plusieurs noms d'hôte), sinon par host.
function stat_for(array $d, array $byHost, array $byIp): array {
    $v = $d['match_value'];
    $row = $byIp[$v] ?? $byHost[$v] ?? null;
    return $row ?: ['total' => 0, 'last_seen' => null, 'crit' => 0, 'warn' => 0, 'info' => 0];
}

$devices = all_devices();

// Hôtes vus en syslog mais pas encore associés à un appareil → suggestions.
// On résout via host ET IP source (résolution tolérante) pour ne pas reproposer
// un hôte déjà rattaché par son IP.
$unnamed = [];
foreach ($byHost as $host => $s) {
    if ($host === '' || $s['syslog_cnt'] == 0) continue;            // focus sur les sources syslog réseau
    if (resolve_device($host, $s['source_ip'] ?? '')) continue;     // déjà rattaché (par host OU IP)
    $unnamed[] = ['host' => $host, 'stat' => $s];
}
usort($unnamed, fn($a, $b) => $b['stat']['total'] <=> $a['stat']['total']);

$nasCount = count(array_filter($devices, fn($d) => $d['type'] === 'nas'));

// Statut « en ligne » à partir de l'ancienneté du dernier log.
function dev_status(?string $last): array {
    if (!$last) return ['grey', 'jamais vu'];
    $age = time() - strtotime($last);
    if ($age < 600)   return ['green', 'en ligne · ' . time_ago($last)];
    if ($age < 7200)  return ['amber', 'il y a ' . time_ago($last)];
    return ['grey', 'il y a ' . time_ago($last)];
}

$pageTitle = APP_NAME . ' — Syslog & Appareils';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center flex-wrap mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700"><i class="bi bi-hdd-network me-2 text-info"></i>Syslog & Appareils</h4>
        <div class="text-muted" style="font-size:13px">
            Reconnaissez vos NAS et équipements réseau par leur nom, et explorez leurs logs.
        </div>
    </div>
    <div class="ms-auto d-flex align-items-center" style="gap:8px">
        <select id="period" class="form-select form-select-sm bg-dark text-light border-secondary" style="width:auto" onchange="location='?period='+this.value">
            <?php foreach (['1h'=>'1 h','6h'=>'6 h','24h'=>'24 h','7d'=>'7 j','30d'=>'30 j','all'=>'Tout'] as $k=>$lbl): ?>
                <option value="<?= $k ?>" <?= $period===$k?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-info btn-sm" onclick="openDevice()"><i class="bi bi-plus-lg me-1"></i>Ajouter un appareil</button>
    </div>
</div>

<!-- Bandeau état récepteur syslog -->
<div class="dash-card mb-4">
    <div class="p-3 d-flex flex-wrap align-items-center" style="gap:22px;font-size:13px">
        <span class="text-muted"><i class="bi bi-broadcast me-1 text-info"></i>Récepteur syslog
            <span class="badge bg-secondary ms-1"><?= h(strtoupper(get_setting('syslog_listen_proto','udp'))) ?>
                :<?= (int)get_setting('syslog_listen_port','1514') ?></span>
        </span>
        <span class="text-muted"><i class="bi bi-hdd-network me-1"></i><?= count($devices) ?> appareil(s) · <?= $nasCount ?> NAS</span>
        <span class="text-muted"><i class="bi bi-question-circle me-1"></i><?= count($unnamed) ?> hôte(s) syslog non reconnu(s)</span>
        <a href="/setup.php#tab-syslog" class="ms-auto text-decoration-none small"><i class="bi bi-gear me-1"></i>Configurer un Synology</a>
    </div>
</div>

<!-- ── Appareils enregistrés ─────────────────────────────────────────────── -->
<?php if ($devices): ?>
<div class="dev-grid mb-4">
    <?php foreach ($devices as $d):
        $s = stat_for($d, $byHost, $byIp);
        [$dot, $txt] = dev_status($s['last_seen']);
        $tot = (int)$s['total']; $crit=(int)$s['crit']; $warn=(int)$s['warn']; $info=(int)$s['info'];
        $pc = fn($n) => $tot ? round($n*100/$tot) : 0;
    ?>
    <div class="dev-card" role="button" tabindex="0"
         data-match="<?= h($d['match_type']) ?>" data-value="<?= h($d['match_value']) ?>" data-name="<?= h($d['name']) ?>"
         onclick="drill(this)" onkeydown="if(event.key==='Enter')drill(this)">
        <div class="d-flex align-items-start">
            <div class="dev-ico"><i class="bi <?= h(device_icon($d)) ?>"></i></div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center" style="gap:6px">
                    <span class="dev-name text-truncate"><?= h($d['name']) ?></span>
                    <?php if ($d['vendor']): ?><span class="badge bg-info-subtle text-info" style="font-size:9px"><?= h($d['vendor']) ?></span><?php endif; ?>
                </div>
                <div class="text-muted mono text-truncate" style="font-size:11px"><?= h($d['match_value']) ?> · <?= h(device_type_label($d['type'])) ?></div>
            </div>
            <div class="dev-actions" onclick="event.stopPropagation()">
                <button class="btn btn-sm btn-outline-secondary border-0 p-1" title="Modifier"
                    onclick='openDevice(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger border-0 p-1" title="Supprimer"
                    onclick="delDevice(<?= (int)$d['id'] ?>,'<?= h(addslashes($d['name'])) ?>')"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="d-flex align-items-center mt-2" style="gap:8px;font-size:11px">
            <span class="dot dot-<?= $dot ?>"></span><span class="text-muted"><?= h($txt) ?></span>
            <span class="ms-auto text-muted"><?= $tot ?> log<?= $tot>1?'s':'' ?></span>
        </div>
        <div class="dev-bar mt-2" title="<?= $crit ?> critiques · <?= $warn ?> alertes · <?= $info ?> infos">
            <span style="width:<?= $pc($crit) ?>%" class="seg-crit"></span>
            <span style="width:<?= $pc($warn) ?>%" class="seg-warn"></span>
            <span style="width:<?= $pc($info) ?>%" class="seg-info"></span>
        </div>
        <div class="d-flex justify-content-between mt-1 text-muted" style="font-size:10px">
            <span><?= $crit ?> crit.</span><span><?= $warn ?> alert.</span><span><?= $info ?> info</span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="dash-card mb-4"><div class="p-4 text-center text-muted">
    <i class="bi bi-hdd-network" style="font-size:28px;opacity:.4"></i>
    <div class="mt-2">Aucun appareil enregistré. Ajoutez-en un, ou nommez un hôte syslog détecté ci-dessous.</div>
</div></div>
<?php endif; ?>

<!-- ── Hôtes syslog non reconnus ─────────────────────────────────────────── -->
<?php if ($unnamed): ?>
<div class="dash-card">
    <div class="dash-card-header"><span><i class="bi bi-question-circle me-2"></i>Hôtes syslog non reconnus</span>
        <span style="text-transform:none;font-weight:400"><?= count($unnamed) ?> détecté(s) sur la période</span></div>
    <div class="table-responsive"><table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
        <thead><tr><th>Hôte</th><th style="width:110px">Logs</th><th style="width:150px">Dernier log</th><th style="width:120px"></th></tr></thead>
        <tbody>
        <?php foreach ($unnamed as $u): [$dot,$txt]=dev_status($u['stat']['last_seen']); ?>
            <tr>
                <td class="mono"><?= h($u['host']) ?></td>
                <td><?= (int)$u['stat']['total'] ?></td>
                <td><span class="dot dot-<?= $dot ?> me-1"></span><span class="text-muted" style="font-size:12px"><?= h($txt) ?></span></td>
                <td>
                    <button class="btn btn-sm btn-outline-info py-0" onclick='nameHost(<?= json_encode($u['host'], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <i class="bi bi-tag me-1"></i>Nommer</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<!-- ══ Drill-down : flux de logs filtré d'un appareil ══════════════════════ -->
<div class="offcanvas offcanvas-end text-bg-dark" tabindex="-1" id="drillPanel" style="width:min(720px,95vw)">
    <div class="offcanvas-header border-bottom border-secondary">
        <div>
            <h5 class="offcanvas-title mb-0" id="drillTitle">—</h5>
            <small class="text-muted mono" id="drillSub"></small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="p-2 border-bottom border-secondary d-flex flex-wrap align-items-end" style="gap:8px">
        <div><label class="form-label small text-muted mb-1">Période</label>
            <select id="f-period" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="1h">1 h</option><option value="6h">6 h</option>
                <option value="24h" selected>24 h</option><option value="7d">7 j</option><option value="">Tout</option>
            </select></div>
        <div><label class="form-label small text-muted mb-1">Sévérité ≤</label>
            <select id="f-sev" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Toutes</option>
                <option value="3">Erreur+ (≤3)</option><option value="4">Alerte+ (≤4)</option><option value="5">Notice+ (≤5)</option>
            </select></div>
        <div><label class="form-label small text-muted mb-1">Facility</label>
            <select id="f-fac" class="form-select form-select-sm bg-dark text-light border-secondary" style="max-width:130px"></select></div>
        <div class="flex-grow-1"><label class="form-label small text-muted mb-1">Recherche</label>
            <input id="f-search" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="texte…"></div>
        <button class="btn btn-sm btn-outline-info" onclick="loadDrill(1)"><i class="bi bi-funnel"></i></button>
    </div>
    <div class="offcanvas-body p-0">
        <div id="drillMeta" class="px-3 py-2 text-muted small border-bottom border-secondary"></div>
        <div id="drillLogs"></div>
        <div id="drillMore" class="p-3 text-center"></div>
    </div>
</div>

<!-- ══ Modale ajout / édition d'appareil ══════════════════════════════════ -->
<div class="modal fade" id="devModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content bg-dark text-light border-secondary">
    <form onsubmit="saveDevice(event)">
      <div class="modal-header border-secondary"><h5 class="modal-title" id="devModalTitle">Ajouter un appareil</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="d-id">
        <div class="row g-3">
          <div class="col-12"><label class="form-label small text-muted">Nom convivial</label>
            <input id="d-name" class="form-control bg-dark text-light border-secondary" placeholder="ex. NAS Bureau" required></div>
          <div class="col-5"><label class="form-label small text-muted">Identifier par</label>
            <select id="d-mtype" class="form-select bg-dark text-light border-secondary">
              <option value="host">Nom d'hôte</option><option value="ip">Adresse IP</option></select></div>
          <div class="col-7"><label class="form-label small text-muted">Valeur</label>
            <input id="d-mvalue" class="form-control bg-dark text-light border-secondary mono" placeholder="DiskStation / 192.168.1.x" required></div>
          <div class="col-6"><label class="form-label small text-muted">Type</label>
            <select id="d-type" class="form-select bg-dark text-light border-secondary"></select></div>
          <div class="col-6"><label class="form-label small text-muted">Marque (optionnel)</label>
            <input id="d-vendor" class="form-control bg-dark text-light border-secondary" placeholder="Synology…"></div>
          <div class="col-12"><label class="form-label small text-muted">Notes (optionnel)</label>
            <input id="d-notes" class="form-control bg-dark text-light border-secondary"></div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-info">Enregistrer</button>
      </div>
    </form>
  </div></div>
</div>

<style>
.dev-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
.dev-card { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:14px; cursor:pointer; transition:border-color .15s,transform .15s; }
.dev-card:hover { border-color:var(--accent); transform:translateY(-2px); }
.dev-ico { width:40px; height:40px; border-radius:9px; background:var(--surface2); display:flex; align-items:center; justify-content:center; font-size:20px; color:var(--accent); margin-right:11px; flex-shrink:0; }
.dev-name { font-weight:600; font-size:14px; }
.dev-actions { display:flex; gap:2px; opacity:0; transition:opacity .15s; }
.dev-card:hover .dev-actions { opacity:1; }
.min-w-0 { min-width:0; }
.dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.dot-green { background:var(--green); box-shadow:0 0 5px var(--green); }
.dot-amber { background:var(--yellow); }
.dot-grey  { background:var(--text-dim); }
.dev-bar { display:flex; height:5px; border-radius:3px; overflow:hidden; background:var(--surface2); }
.dev-bar span { display:block; height:100%; }
.seg-crit { background:var(--red); } .seg-warn { background:var(--yellow); } .seg-info { background:var(--green); }
.dl-row { padding:8px 14px; border-bottom:1px solid var(--border2); border-left:3px solid transparent; }
.dl-row:hover { background:var(--surface2); }
.dl-row.s0,.dl-row.s1,.dl-row.s2 { border-left-color:var(--red); }
.dl-row.s3 { border-left-color:var(--orange); } .dl-row.s4 { border-left-color:var(--yellow); }
.dl-msg { font-family:'SF Mono','Consolas',monospace; font-size:12px; color:var(--text); word-break:break-word; white-space:pre-wrap; }
.dl-meta { font-size:11px; color:var(--text-muted); display:flex; gap:8px; flex-wrap:wrap; margin-bottom:2px; }
</style>

<script>
const SEV   = <?= json_encode(SEVERITIES) ?>;
const SEVC  = <?= json_encode(SEVERITY_CLASSES) ?>;
const FAC   = <?= json_encode(FACILITIES) ?>;
const TYPES = <?= json_encode(array_map(fn($t)=>$t[0], DEVICE_TYPES)) ?>;
let drillCtx = null, drillPage = 1;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function toast(msg, ok = true) {
    let w = document.querySelector('.toast-container');
    if (!w) { w = document.createElement('div'); w.className = 'toast-container'; document.body.appendChild(w); }
    const t = document.createElement('div'); t.className = 'toast show'; t.style.cssText = 'background:var(--surface);border:1px solid '+(ok?'var(--green)':'var(--red)')+';color:var(--text);padding:10px 14px;border-radius:8px';
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 3000);
}
async function api(method, body) {
    const r = await fetch('/api/devices.php', { method, headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF}, body: body?JSON.stringify(body):undefined });
    const d = await r.json(); if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}

// ── Modale appareil ──
function fillSelect(sel, map, val) { sel.innerHTML = Object.entries(map).map(([k,v])=>`<option value="${k}"${k===val?' selected':''}>${esc(v)}</option>`).join(''); }
const devModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('devModal'));
function openDevice(d) {
    fillSelect(document.getElementById('d-type'), TYPES, d?.type || 'nas');
    document.getElementById('devModalTitle').textContent = d ? 'Modifier l\'appareil' : 'Ajouter un appareil';
    document.getElementById('d-id').value     = d?.id || '';
    document.getElementById('d-name').value   = d?.name || '';
    document.getElementById('d-mtype').value  = d?.match_type || 'host';
    document.getElementById('d-mvalue').value = d?.match_value || '';
    document.getElementById('d-vendor').value = d?.vendor || '';
    document.getElementById('d-notes').value  = d?.notes || '';
    devModal().show();
}
function nameHost(host) {
    const syno = /(syno|diskstation|nas)/i.test(host);
    openDevice({ match_type:'host', match_value:host, name:host, type:'nas', vendor: syno?'Synology':'' });
}
async function saveDevice(e) {
    e.preventDefault();
    const id = document.getElementById('d-id').value;
    const body = {
        id: id || undefined,
        name: document.getElementById('d-name').value.trim(),
        match_type: document.getElementById('d-mtype').value,
        match_value: document.getElementById('d-mvalue').value.trim(),
        type: document.getElementById('d-type').value,
        vendor: document.getElementById('d-vendor').value.trim(),
        notes: document.getElementById('d-notes').value.trim(),
    };
    try { await api(id?'PUT':'POST', body); toast('Appareil enregistré'); setTimeout(()=>location.reload(), 400); }
    catch (err) { toast(err.message, false); }
}
async function delDevice(id, name) {
    if (!confirm('Supprimer l\'appareil « '+name+' » ? (les logs sont conservés)')) return;
    try { await api('DELETE', { id }); toast('Supprimé'); setTimeout(()=>location.reload(), 400); }
    catch (err) { toast(err.message, false); }
}

// ── Drill-down ──
const drillPanel = () => bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('drillPanel'));
function drill(card) {
    drillCtx = { match: card.dataset.match, value: card.dataset.value, name: card.dataset.name };
    document.getElementById('drillTitle').textContent = drillCtx.name;
    document.getElementById('drillSub').textContent = (drillCtx.match==='ip'?'IP ':'host ') + drillCtx.value;
    if (!document.getElementById('f-fac').options.length)
        fillSelect(document.getElementById('f-fac'), Object.assign({'':'Toutes'}, FAC), '');
    drillPanel().show();
    loadDrill(1);
}
async function loadDrill(page) {
    drillPage = page;
    const q = new URLSearchParams();
    q.set(drillCtx.match === 'ip' ? 'source_ip' : 'host', drillCtx.value);
    const per = document.getElementById('f-period').value; if (per) q.set('period', per);
    const sev = document.getElementById('f-sev').value;    if (sev) q.set('sev_max', sev);
    const fac = document.getElementById('f-fac').value;     if (fac) q.set('facility', fac);
    const se  = document.getElementById('f-search').value.trim(); if (se) q.set('search', se);
    q.set('page', page); q.set('limit', 100);
    const box = document.getElementById('drillLogs');
    if (page === 1) box.innerHTML = '<div class="p-4 text-center text-muted">Chargement…</div>';
    try {
        const r = await fetch('/api/logs.php?' + q); const d = await r.json();
        const rows = (d.logs || []).map(rowHtml).join('');
        if (page === 1) box.innerHTML = rows || '<div class="p-4 text-center text-muted">Aucun log sur ces critères.</div>';
        else box.insertAdjacentHTML('beforeend', rows);
        document.getElementById('drillMeta').textContent = d.total + ' log(s) · page ' + d.page + '/' + (d.pages || 1);
        document.getElementById('drillMore').innerHTML = (d.page < d.pages)
            ? '<button class="btn btn-sm btn-outline-secondary" onclick="loadDrill('+(page+1)+')">Charger plus</button>' : '';
    } catch (e) { box.innerHTML = '<div class="p-4 text-center text-danger">Erreur de chargement.</div>'; }
}
function rowHtml(l) {
    const sev = +l.severity;
    const t = (l.log_time || l.received_at || '').replace('T',' ').slice(0,19);
    const tags = [];
    if (l.program) tags.push('<span class="badge bg-secondary">'+esc(l.program)+(l.pid?(':'+esc(l.pid)):'')+'</span>');
    if (l.sec_event) tags.push('<span class="badge bg-danger-subtle text-danger">'+esc(l.sec_event)+'</span>');
    tags.push('<span>fac '+esc(FAC[l.facility] ?? l.facility)+'</span>');
    return '<div class="dl-row s'+sev+'">'
        + '<div class="dl-meta"><span class="badge bg-'+(SEVC[sev]||'secondary')+'">'+esc(SEV[sev]||sev)+'</span>'
        + '<span>'+esc(t)+'</span>'+tags.join('')+'</div>'
        + '<div class="dl-msg">'+esc(l.message)+'</div></div>';
}
// Recherche : déclenche au Enter
document.getElementById('f-search').addEventListener('keydown', e => { if (e.key === 'Enter') loadDrill(1); });
['f-period','f-sev','f-fac'].forEach(id => document.getElementById(id).addEventListener('change', () => loadDrill(1)));
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
