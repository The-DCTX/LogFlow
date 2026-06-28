<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cve.php';
require_once __DIR__ . '/includes/auth.php';

$db = db();

// Métadonnées des signatures + ensemble des acquittements.
$sigs = $db->query("SELECT * FROM cve_signatures ORDER BY cvss DESC, cve_id")->fetchAll();
$meta = [];
foreach ($sigs as $s) $meta[$s['cve_id']] = $s;
$acks = [];
foreach ($db->query("SELECT cve_id, host FROM cve_acks")->fetchAll() as $a) $acks[$a['cve_id'] . "\0" . $a['host']] = true;

// Détections agrégées (par CVE + hôte) sur 30 jours.
$hits = $db->query(
    "SELECT cve, host, MAX(source_ip) source_ip, COUNT(*) c, MAX(received_at) last
     FROM logs WHERE cve IS NOT NULL AND received_at >= NOW() - INTERVAL 30 DAY
     GROUP BY cve, host ORDER BY last DESC"
)->fetchAll();

$openHits = 0;
foreach ($hits as $h) if (!isset($acks[$h['cve'] . "\0" . $h['host']])) $openHits++;

$pageTitle = APP_NAME . ' — CVE';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center flex-wrap mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700"><i class="bi bi-bug me-2 text-info"></i>Vulnérabilités (CVE)
            <?php if ($openHits): ?><span class="badge bg-danger ms-1"><?= $openHits ?></span><?php endif; ?></h4>
        <div class="text-muted" style="font-size:13px">
            Tentatives d'exploitation de CVE connues détectées dans les logs (signatures hors-ligne). Niveau de menace + remédiation.
        </div>
    </div>
</div>

<!-- Détections -->
<div class="dash-card mb-4">
    <div class="dash-card-header"><span><i class="bi bi-radar me-2"></i>Détections (30 derniers jours)</span></div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th style="width:170px">Menace</th><th>CVE</th><th style="width:130px">Hôte</th>
                <th style="width:80px">Occurr.</th><th style="width:120px">Dernière</th><th style="width:90px">État</th><th style="width:90px"></th></tr></thead>
            <tbody>
            <?php if (!$hits): ?><tr><td colspan="7" class="text-muted text-center py-4"><i class="bi bi-shield-check me-1 text-success"></i>Aucune tentative d'exploitation détectée.</td></tr><?php endif; ?>
            <?php foreach ($hits as $h):
                $m = $meta[$h['cve']] ?? null;
                [$lvl, $col] = cvss_meta((float)($m['cvss'] ?? 0));
                $acked = isset($acks[$h['cve'] . "\0" . $h['host']]); ?>
                <tr>
                    <td><span class="badge" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>">🧨 <?= h($lvl) ?> <?= $m ? h(number_format((float)$m['cvss'],1)) : '' ?></span></td>
                    <td><strong><?= h($h['cve']) ?></strong> <span class="text-muted"><?= h($m['name'] ?? '') ?></span>
                        <?php if (!empty($m['remediation'])): ?><div class="text-muted" style="font-size:11px"><i class="bi bi-wrench me-1"></i><?= h($m['remediation']) ?>
                            <?php if (!empty($m['reference_url'])): ?> · <a href="<?= h($m['reference_url']) ?>" target="_blank" rel="noopener">détails</a><?php endif; ?></div><?php endif; ?></td>
                    <td class="mono"><?= h($h['host'] ?: $h['source_ip']) ?></td>
                    <td><?= (int)$h['c'] ?></td>
                    <td class="text-muted" title="<?= h($h['last']) ?>"><?= h(time_ago($h['last'])) ?></td>
                    <td><?= $acked ? '<span class="badge bg-secondary">traité</span>' : '<span class="badge bg-danger">ouvert</span>' ?></td>
                    <td>
                        <?php if (!$acked): ?><button class="btn btn-sm btn-outline-info border-0 py-0" title="Marquer traité"
                            onclick='ackCve(<?= json_encode(["cve"=>$h["cve"],"host"=>$h["host"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-check-lg"></i></button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Bibliothèque de signatures -->
<div class="dash-card">
    <div class="dash-card-header"><span><i class="bi bi-journal-code me-2"></i>Signatures CVE</span>
        <button class="btn btn-info btn-sm" onclick="document.getElementById('addsig').classList.toggle('d-none')"><i class="bi bi-plus-lg me-1"></i>Ajouter</button></div>
    <div id="addsig" class="p-3 border-bottom border-secondary d-none">
        <form class="row g-2 align-items-end" onsubmit="addSig(event)">
            <div class="col-auto"><label class="form-label small text-muted mb-0">CVE</label><input id="s-cve" class="form-control form-control-sm bg-dark text-light border-secondary mono" placeholder="CVE-2024-1234" required></div>
            <div class="col-auto"><label class="form-label small text-muted mb-0">Nom</label><input id="s-name" class="form-control form-control-sm bg-dark text-light border-secondary"></div>
            <div class="col"><label class="form-label small text-muted mb-0">Motif (regex)</label><input id="s-pat" class="form-control form-control-sm bg-dark text-light border-secondary mono" required></div>
            <div class="col-auto"><label class="form-label small text-muted mb-0">CVSS</label><input id="s-cvss" type="number" step="0.1" min="0" max="10" value="9.8" class="form-control form-control-sm bg-dark text-light border-secondary" style="width:80px"></div>
            <div class="col-12"><label class="form-label small text-muted mb-0">Remédiation</label><input id="s-rem" class="form-control form-control-sm bg-dark text-light border-secondary"></div>
            <div class="col-auto"><button class="btn btn-info btn-sm">Enregistrer</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th>CVE</th><th>Motif</th><th style="width:90px">CVSS</th><th style="width:80px">Actif</th><th style="width:50px"></th></tr></thead>
            <tbody>
            <?php foreach ($sigs as $s): [$lvl,$col]=cvss_meta((float)$s['cvss']); ?>
                <tr>
                    <td><strong><?= h($s['cve_id']) ?></strong> <span class="text-muted" style="font-size:11px"><?= h($s['name']) ?></span>
                        <?php if ($s['source']==='builtin'): ?><span class="badge bg-secondary" style="font-size:9px">intégrée</span><?php endif; ?></td>
                    <td class="mono" style="font-size:11px"><?= h($s['pattern']) ?></td>
                    <td><span class="badge" style="background:<?= $col ?>22;color:<?= $col ?>"><?= h(number_format((float)$s['cvss'],1)) ?> <?= h($lvl) ?></span></td>
                    <td><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" <?= $s['enabled']?'checked':'' ?> onchange="toggleSig(<?= (int)$s['id'] ?>)"></div></td>
                    <td><?php if ($s['source']==='manual'): ?><button class="btn btn-sm btn-outline-danger border-0 p-1" onclick="delSig(<?= (int)$s['id'] ?>)"><i class="bi bi-trash"></i></button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toast(msg, ok = true) {
    let w = document.querySelector('.toast-container');
    if (!w) { w = document.createElement('div'); w.className = 'toast-container'; document.body.appendChild(w); }
    const t = document.createElement('div'); t.className = 'toast show';
    t.style.cssText = 'background:var(--surface);border:1px solid '+(ok?'var(--green)':'var(--red)')+';color:var(--text);padding:10px 14px;border-radius:8px';
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 3500);
}
async function api(method, body) {
    const r = await fetch('/api/cve.php', { method, headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF}, body: body?JSON.stringify(body):undefined });
    const d = await r.json(); if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}
async function ackCve(o)    { try { await api('POST', { action:'ack', cve_id:o.cve, host:o.host }); location.reload(); } catch(e){ toast(e.message,false); } }
async function toggleSig(id){ try { await api('POST', { action:'toggle', id }); toast('Mis à jour'); } catch(e){ toast(e.message,false); } }
async function delSig(id)   { if(!confirm('Supprimer cette signature ?'))return; try { await api('DELETE', { id }); location.reload(); } catch(e){ toast(e.message,false); } }
async function addSig(e) {
    e.preventDefault();
    const body = { action:'add', cve_id:document.getElementById('s-cve').value.trim(), name:document.getElementById('s-name').value.trim(),
        pattern:document.getElementById('s-pat').value.trim(), cvss:document.getElementById('s-cvss').value, remediation:document.getElementById('s-rem').value.trim() };
    if (!body.cve_id || !body.pattern) { toast('CVE et motif requis', false); return; }
    try { await api('POST', body); toast('Signature ajoutée'); setTimeout(()=>location.reload(),500); } catch(err){ toast(err.message,false); }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
