<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$db = db();
$agents = $db->query("
    SELECT k.id, k.name, k.api_key, k.created_at, k.last_used,
           (SELECT COUNT(*) FROM install_tokens t WHERE t.api_key_id = k.id) AS tokens_total,
           (SELECT COUNT(*) FROM install_tokens t WHERE t.api_key_id = k.id
                   AND t.revoked = 0 AND (t.expires_at IS NULL OR t.expires_at > NOW())) AS tokens_active
    FROM api_keys k ORDER BY k.id")->fetchAll();

$pageTitle = APP_NAME . ' — Agents';
include __DIR__ . '/includes/header.php';

function mask_key(string $k): string {
    return strlen($k) > 16 ? substr($k, 0, 8) . '…' . substr($k, -4) : $k;
}
?>
<div class="d-flex align-items-center flex-wrap mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700"><i class="bi bi-robot me-2 text-info"></i>Agents enregistrés</h4>
        <div class="text-muted" style="font-size:13px">
            Chaque agent s'authentifie par une clé API. La suppression révoque et purge tous ses
            tokens d'enrôlement — aucun ne peut être réutilisé ensuite.
        </div>
    </div>
    <div class="ms-auto d-flex align-items-center" style="gap:8px">
        <a href="/setup.php#step-3" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Installer un agent</a>
        <button class="btn btn-info btn-sm" onclick="openAgent()"><i class="bi bi-plus-lg me-1"></i>Nouvel agent</button>
    </div>
</div>

<div class="dash-card">
    <div class="dash-card-header"><span><i class="bi bi-key me-2"></i>Clés API & enrôlements</span>
        <span style="text-transform:none;font-weight:400"><?= count($agents) ?> agent(s)</span></div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr>
                <th>Nom</th><th>Clé API</th><th style="width:150px">Créé le</th>
                <th style="width:150px">Dernier usage</th><th style="width:130px">Tokens</th><th style="width:110px"></th>
            </tr></thead>
            <tbody id="agents-body">
            <?php if (!$agents): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">Aucun agent. Créez-en un pour générer une clé API.</td></tr>
            <?php endif; ?>
            <?php foreach ($agents as $a): ?>
                <tr data-id="<?= (int)$a['id'] ?>">
                    <td class="fw-semibold"><?= h($a['name']) ?></td>
                    <td>
                        <code class="text-info mono key-mask" data-full="<?= h($a['api_key']) ?>"><?= h(mask_key($a['api_key'])) ?></code>
                        <button class="btn btn-sm btn-outline-secondary border-0 p-1 ms-1" title="Afficher/Copier" onclick="copyKey(this)"><i class="bi bi-clipboard"></i></button>
                    </td>
                    <td class="text-muted"><?= h(substr((string)$a['created_at'], 0, 16)) ?></td>
                    <td class="text-muted"><?= $a['last_used'] ? h(substr((string)$a['last_used'], 0, 16)) : '<span class="text-dim">jamais</span>' ?></td>
                    <td>
                        <?php $tt = (int)$a['tokens_total']; $ta = (int)$a['tokens_active']; ?>
                        <?php if ($tt === 0): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success" title="actifs"><?= $ta ?> actif<?= $ta>1?'s':'' ?></span>
                            <?php if ($tt - $ta > 0): ?><span class="badge bg-secondary" title="révoqués/expirés"><?= $tt-$ta ?></span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary border-0 p-1" title="Renommer"
                                onclick='openAgent(<?= json_encode(['id'=>(int)$a['id'],'name'=>$a['name']], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger border-0 p-1" title="Supprimer"
                                onclick="delAgent(<?= (int)$a['id'] ?>, '<?= h(addslashes($a['name'])) ?>', <?= (int)$a['tokens_total'] ?>)"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modale créer / renommer -->
<div class="modal fade" id="agentModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content bg-dark text-light border-secondary">
    <form onsubmit="saveAgent(event)">
      <div class="modal-header border-secondary"><h5 class="modal-title" id="agentModalTitle">Nouvel agent</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="a-id">
        <label class="form-label small text-muted">Nom de l'agent</label>
        <input id="a-name" class="form-control bg-dark text-light border-secondary" placeholder="ex. Serveurs Paris, VM web…" required>
        <div class="form-text text-muted" id="a-hint">Une nouvelle clé API sera générée.</div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-info">Enregistrer</button>
      </div>
    </form>
  </div></div>
</div>

<script>
const agentModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('agentModal'));
function toast(msg, ok = true) {
    let w = document.querySelector('.toast-container');
    if (!w) { w = document.createElement('div'); w.className = 'toast-container'; document.body.appendChild(w); }
    const t = document.createElement('div'); t.className = 'toast show';
    t.style.cssText = 'background:var(--surface);border:1px solid '+(ok?'var(--green)':'var(--red)')+';color:var(--text);padding:10px 14px;border-radius:8px';
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 4000);
}
async function api(method, body) {
    const r = await fetch('/api/agents.php', { method, headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF}, body: body?JSON.stringify(body):undefined });
    const d = await r.json(); if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}
function openAgent(a) {
    document.getElementById('a-id').value   = a?.id || '';
    document.getElementById('a-name').value = a?.name || '';
    document.getElementById('agentModalTitle').textContent = a ? 'Renommer l\'agent' : 'Nouvel agent';
    document.getElementById('a-hint').style.display = a ? 'none' : '';
    agentModal().show();
}
async function saveAgent(e) {
    e.preventDefault();
    const id = document.getElementById('a-id').value;
    const name = document.getElementById('a-name').value.trim();
    try {
        const d = await api(id ? 'PUT' : 'POST', { id: id || undefined, name });
        if (!id && d.data?.api_key) toast('Agent créé — clé : ' + d.data.api_key);
        else toast('Enregistré');
        setTimeout(() => location.reload(), 600);
    } catch (err) { toast(err.message, false); }
}
async function delAgent(id, name, tokens) {
    const warn = 'Supprimer l\'agent « ' + name + ' » ?\n\n'
        + '• Sa clé API sera supprimée : l\'agent déployé ne pourra plus envoyer de logs.\n'
        + '• ' + tokens + ' token(s) d\'enrôlement seront révoqués et purgés : aucun ne sera réutilisable.\n'
        + '• Les logs déjà reçus sont conservés.';
    if (!confirm(warn)) return;
    try { const d = await api('DELETE', { id }); toast(d.message); setTimeout(() => location.reload(), 600); }
    catch (err) { toast(err.message, false); }
}
function copyKey(btn) {
    const code = btn.parentElement.querySelector('.key-mask');
    code.textContent = code.dataset.full;                       // dévoile la clé complète
    navigator.clipboard?.writeText(code.dataset.full)
        .then(() => toast('Clé copiée dans le presse-papier'))
        .catch(() => toast('Clé dévoilée (copie manuelle)'));
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
