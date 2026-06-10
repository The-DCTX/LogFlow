<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

$db = db();
$ov = [];
foreach ($db->query("SELECT match_val, severity FROM severity_rules WHERE kind='sec_event'")->fetchAll() as $r)
    $ov[$r['match_val']] = (int)$r['severity'];
$rules = $db->query("SELECT * FROM severity_rules WHERE kind IN ('program','message') ORDER BY sort_order, id")->fetchAll();

$pageTitle = APP_NAME . ' — Sévérité';
include __DIR__ . '/includes/header.php';

function sev_opts(int $cur): string {
    $o = '';
    foreach (SEVERITIES as $i => $name) {
        $o .= '<option value="' . $i . '"' . ($i === $cur ? ' selected' : '') . '>' . $i . ' — ' . h($name) . '</option>';
    }
    return $o;
}
?>
<div class="d-flex align-items-center mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700">Règles de sévérité</h4>
        <div class="text-muted" style="font-size:13px">Recatégorisez la criticité des événements et de vos motifs personnalisés.</div>
    </div>
    <button class="btn btn-outline-info btn-sm ms-auto" onclick="recalc(this)">
        <i class="bi bi-arrow-repeat me-1"></i>Recalculer l'historique
    </button>
</div>

<div class="dash-card mb-4">
    <div class="dash-card-header"><span><i class="bi bi-shield-lock me-2"></i>Événements de sécurité</span><span style="text-transform:none;font-weight:400">18 types détectés automatiquement</span></div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th>Événement</th><th style="width:140px">Défaut</th><th style="width:230px">Sévérité appliquée</th></tr></thead>
            <tbody>
            <?php foreach (SEC_EVENTS as $k => $d):
                $def = $d['severity'] ?? 6; $cur = $ov[$k] ?? $def; ?>
                <tr>
                    <td><?= h($d['icon'] ?? '') ?> <strong><?= h($d['label']) ?></strong> <span class="text-muted mono" style="font-size:11px"><?= h($k) ?></span></td>
                    <td><?= severity_badge((int)$def) ?></td>
                    <td>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" style="max-width:200px;display:inline-block"
                                onchange="setSec('<?= h($k) ?>', this.value, this)">
                            <?= sev_opts((int)$cur) ?>
                        </select>
                        <?php if (isset($ov[$k])): ?><span class="badge bg-info-subtle text-info ms-1" style="font-size:9px">modifié</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="dash-card">
    <div class="dash-card-header"><span><i class="bi bi-sliders me-2"></i>Règles personnalisées</span></div>
    <div class="p-3">
        <form class="row g-2 align-items-end mb-3" onsubmit="addRule(event)">
            <div class="col-auto"><label class="form-label small text-muted mb-1">Type</label>
                <select id="r-kind" class="form-select form-select-sm bg-dark text-light border-secondary">
                    <option value="program">Programme contient</option>
                    <option value="message">Message (regex)</option>
                </select></div>
            <div class="col"><label class="form-label small text-muted mb-1">Critère</label>
                <input id="r-val" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="ex. sshd  ·  ou  (?i)brute.?force" required></div>
            <div class="col-auto"><label class="form-label small text-muted mb-1">Sévérité</label>
                <select id="r-sev" class="form-select form-select-sm bg-dark text-light border-secondary"><?= sev_opts(2) ?></select></div>
            <div class="col-auto"><label class="form-label small text-muted mb-1">Libellé</label>
                <input id="r-label" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="optionnel"></div>
            <div class="col-auto"><button class="btn btn-info btn-sm"><i class="bi bi-plus-lg me-1"></i>Ajouter</button></div>
        </form>
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th style="width:120px">Type</th><th>Critère</th><th style="width:160px">Sévérité</th><th style="width:60px"></th></tr></thead>
            <tbody id="rules-body">
            <?php if (!$rules): ?><tr><td colspan="4" class="text-muted text-center py-3">Aucune règle personnalisée.</td></tr><?php endif; ?>
            <?php foreach ($rules as $r): ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= $r['kind'] === 'program' ? 'Programme' : 'Regex' ?></span></td>
                    <td class="mono"><?= h($r['match_val']) ?><?= $r['label'] ? ' <span class="text-muted">— ' . h($r['label']) . '</span>' : '' ?></td>
                    <td><?= severity_badge((int)$r['severity']) ?></td>
                    <td><button class="btn btn-sm btn-outline-danger border-0" onclick="delRule(<?= $r['id'] ?>)"><i class="bi bi-trash"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function sapi(url, opts) {
    const r = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}
function toast(msg, ok = true) {
    let w = document.querySelector('.toast-container');
    if (!w) { w = document.createElement('div'); w.className = 'toast-container'; document.body.appendChild(w); }
    const t = document.createElement('div'); t.className = 'toast'; t.style.borderColor = ok ? 'var(--green)' : 'var(--red)';
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 3000);
}
async function setSec(key, sev, el) {
    try { await sapi('/api/severity-rules.php?action=set_sec_event', { method: 'POST', body: JSON.stringify({ key, severity: sev }) });
        toast('Sévérité mise à jour'); } catch (e) { toast(e.message, false); }
}
async function addRule(e) {
    e.preventDefault();
    const body = { kind: document.getElementById('r-kind').value, match_val: document.getElementById('r-val').value.trim(),
        severity: document.getElementById('r-sev').value, label: document.getElementById('r-label').value.trim() };
    if (!body.match_val) { toast('Critère requis', false); return; }
    try { await sapi('/api/severity-rules.php', { method: 'POST', body: JSON.stringify(body) }); location.reload(); }
    catch (e) { toast(e.message, false); }
}
async function delRule(id) {
    if (!confirm('Supprimer cette règle ?')) return;
    try { await sapi('/api/severity-rules.php', { method: 'DELETE', body: JSON.stringify({ id }) }); location.reload(); }
    catch (e) { toast(e.message, false); }
}
async function recalc(btn) {
    if (!confirm("Recalculer la sévérité de tout l'historique selon les règles actuelles ?")) return;
    btn.disabled = true; const old = btn.innerHTML; btn.innerHTML = 'Recalcul…';
    try { const r = await sapi('/api/severity-rules.php?action=recalc', { method: 'POST', body: '{}' }); toast(r.message); }
    catch (e) { toast(e.message, false); } finally { btn.disabled = false; btn.innerHTML = old; }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
