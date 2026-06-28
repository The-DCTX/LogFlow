<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/templating.php';
require_once __DIR__ . '/includes/auth.php';

$db = db();
$templates = $db->query(
    "SELECT id, program, template, example, count, sec_event, status, last_seen
     FROM log_templates WHERE status != 'ignored'
     ORDER BY (sec_event IS NULL) DESC, count DESC LIMIT 300"
)->fetchAll();
$rules = $db->query("SELECT * FROM parse_rules ORDER BY id DESC")->fetchAll();
$processed = (int) get_setting('templating_last_id', '0');
$lastLogId = (int) $db->query("SELECT COALESCE(MAX(id),0) FROM logs")->fetchColumn();
$pending   = max(0, $lastLogId - $processed);

$pageTitle = APP_NAME . ' — Apprentissage';
include __DIR__ . '/includes/header.php';

function sec_badge_for(?string $k): string {
    if (!$k || !isset(SEC_EVENTS[$k])) return '<span class="badge bg-secondary">non classé</span>';
    $d = SEC_EVENTS[$k];
    return '<span class="badge" style="background:' . h($d['color'] ?? '#6c757d') . '20;color:' . h($d['color'] ?? '#aaa') . '">'
         . h($d['icon'] ?? '') . ' ' . h($d['label']) . '</span>';
}
?>
<div class="d-flex align-items-center flex-wrap mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700"><i class="bi bi-mortarboard me-2 text-info"></i>Apprentissage du parser</h4>
        <div class="text-muted" style="font-size:13px">
            LogFlow regroupe les messages par <strong>gabarit</strong> et te propose de classer ceux qu'il ne
            reconnaît pas encore. Tu valides — rien n'est appliqué automatiquement.
        </div>
    </div>
    <div class="ms-auto d-flex align-items-center" style="gap:8px">
        <span class="text-muted small"><?= $pending ?> log(s) en attente d'analyse</span>
        <button class="btn btn-info btn-sm" id="btn-analyze" onclick="analyze(this)"><i class="bi bi-cpu me-1"></i>Analyser maintenant</button>
    </div>
</div>

<!-- Règles de classification apprises -->
<div class="dash-card mb-4">
    <div class="dash-card-header"><span><i class="bi bi-magic me-2"></i>Règles de classification</span>
        <span style="text-transform:none;font-weight:400"><?= count($rules) ?> règle(s) approuvée(s)</span></div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th>Libellé</th><th>Programme</th><th>Motif</th><th>Classé comme</th><th style="width:90px">Actif</th><th style="width:50px"></th></tr></thead>
            <tbody>
            <?php if (!$rules): ?><tr><td colspan="6" class="text-muted text-center py-3">Aucune règle apprise pour l'instant.</td></tr><?php endif; ?>
            <?php foreach ($rules as $r): ?>
                <tr>
                    <td><?= h($r['label']) ?> <?php if ($r['source']==='suggested'): ?><span class="badge bg-info-subtle text-info" style="font-size:9px">suggérée</span><?php endif; ?></td>
                    <td class="mono text-muted"><?= $r['program_match'] ? h($r['program_match']) : '<span class="text-dim">tous</span>' ?></td>
                    <td class="mono" style="font-size:11px"><?= h($r['pattern']) ?></td>
                    <td><?= sec_badge_for($r['sec_event']) ?></td>
                    <td>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" <?= $r['enabled']?'checked':'' ?> onchange="toggleRule(<?= (int)$r['id'] ?>)">
                        </div>
                    </td>
                    <td><button class="btn btn-sm btn-outline-danger border-0 p-1" onclick="delRule(<?= (int)$r['id'] ?>)"><i class="bi bi-trash"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Gabarits découverts -->
<div class="dash-card">
    <div class="dash-card-header"><span><i class="bi bi-diagram-2 me-2"></i>Gabarits découverts</span>
        <span style="text-transform:none;font-weight:400">les non classés d'abord</span></div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th style="width:90px">Occurr.</th><th>Exemple</th><th style="width:110px">Programme</th><th style="width:150px">Classé</th><th style="width:150px"></th></tr></thead>
            <tbody>
            <?php if (!$templates): ?><tr><td colspan="5" class="text-muted text-center py-4">Aucun gabarit. Clique « Analyser maintenant ».</td></tr><?php endif; ?>
            <?php foreach ($templates as $t): $sugg = suggest_pattern($t['template']); ?>
                <tr>
                    <td><span class="badge bg-dark border border-secondary"><?= number_format((int)$t['count'], 0, ',', ' ') ?></span></td>
                    <td class="mono" style="font-size:11px;max-width:520px;word-break:break-word"><?= h($t['example']) ?>
                        <div class="text-dim" style="font-size:10px" title="gabarit"><?= h($t['template']) ?></div></td>
                    <td class="mono text-muted"><?= h($t['program']) ?: '<span class="text-dim">—</span>' ?></td>
                    <td><?= sec_badge_for($t['sec_event']) ?></td>
                    <td>
                        <?php if (!$t['sec_event']): ?>
                            <button class="btn btn-sm btn-outline-info py-0"
                                onclick='promote(<?= json_encode(["id"=>(int)$t["id"],"program"=>$t["program"],"pattern"=>$sugg,"example"=>$t["example"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-magic me-1"></i>Créer une règle</button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-secondary border-0 py-0" title="Ignorer" onclick="ignore(<?= (int)$t['id'] ?>)"><i class="bi bi-eye-slash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modale : créer une règle -->
<div class="modal fade" id="ruleModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content bg-dark text-light border-secondary">
    <form onsubmit="saveRule(event)">
      <div class="modal-header border-secondary"><h5 class="modal-title">Créer une règle de classification</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="r-tid">
        <div class="mb-2"><label class="form-label small text-muted">Exemple de message</label>
          <div class="mono p-2 rounded" style="background:var(--surface2);font-size:11px;word-break:break-word" id="r-example"></div></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label small text-muted">Classer comme</label>
            <select id="r-sec" class="form-select bg-dark text-light border-secondary"></select></div>
          <div class="col-md-6"><label class="form-label small text-muted">Programme (optionnel, contient)</label>
            <input id="r-prog" class="form-control bg-dark text-light border-secondary mono"></div>
          <div class="col-12"><label class="form-label small text-muted">Motif (regex, échappé automatiquement)</label>
            <input id="r-pat" class="form-control bg-dark text-light border-secondary mono" required>
            <div class="form-text text-muted">Par défaut : la plus longue partie fixe du message (sans risque). Modifiable.</div></div>
          <div class="col-12"><label class="form-label small text-muted">Libellé</label>
            <input id="r-label" class="form-control bg-dark text-light border-secondary" placeholder="optionnel"></div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-info">Créer la règle</button>
      </div>
    </form>
  </div></div>
</div>

<script>
const SECEV = <?php
    $s = [];
    foreach (SEC_EVENTS as $k => $d) $s[$k] = ($d['icon'] ?? '') . ' ' . $d['label'];
    echo json_encode($s);
?>;
const ruleModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleModal'));
function toast(msg, ok = true) {
    let w = document.querySelector('.toast-container');
    if (!w) { w = document.createElement('div'); w.className = 'toast-container'; document.body.appendChild(w); }
    const t = document.createElement('div'); t.className = 'toast show';
    t.style.cssText = 'background:var(--surface);border:1px solid '+(ok?'var(--green)':'var(--red)')+';color:var(--text);padding:10px 14px;border-radius:8px';
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 4000);
}
async function api(method, body) {
    const r = await fetch('/api/templates.php', { method, headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF}, body: body?JSON.stringify(body):undefined });
    const d = await r.json(); if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}
async function analyze(btn) {
    btn.disabled = true; const o = btn.innerHTML; btn.innerHTML = 'Analyse…';
    try { const d = await api('POST', { action:'analyze', limit:5000 }); toast(d.message); setTimeout(()=>location.reload(), 700); }
    catch (e) { toast(e.message, false); btn.disabled = false; btn.innerHTML = o; }
}
function promote(t) {
    document.getElementById('r-tid').value = t.id;
    document.getElementById('r-example').textContent = t.example;
    document.getElementById('r-prog').value = t.program || '';
    document.getElementById('r-pat').value = t.pattern || '';
    document.getElementById('r-sec').innerHTML = Object.entries(SECEV).map(([k,v])=>`<option value="${k}">${v}</option>`).join('');
    document.getElementById('r-label').value = '';
    ruleModal().show();
}
async function saveRule(e) {
    e.preventDefault();
    const body = { action:'promote', template_id: document.getElementById('r-tid').value,
        sec_event: document.getElementById('r-sec').value, pattern: document.getElementById('r-pat').value.trim(),
        program_match: document.getElementById('r-prog').value.trim(), label: document.getElementById('r-label').value.trim() };
    if (!body.pattern) { toast('Motif requis', false); return; }
    try { await api('POST', body); toast('Règle créée'); setTimeout(()=>location.reload(), 600); }
    catch (err) { toast(err.message, false); }
}
async function ignore(id) { try { await api('POST', { action:'ignore', id }); location.reload(); } catch(e){ toast(e.message,false); } }
async function toggleRule(id) { try { await api('POST', { action:'toggle_rule', id }); toast('Mise à jour'); } catch(e){ toast(e.message,false); } }
async function delRule(id) { if(!confirm('Supprimer cette règle ?'))return; try { await api('DELETE', { id }); location.reload(); } catch(e){ toast(e.message,false); } }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
