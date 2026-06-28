<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$db = db();
$status = in_array($_GET['status'] ?? 'open', ['open', 'all'], true) ? $_GET['status'] : 'open';
$where  = $status === 'all' ? '1=1' : "status = 'open'";
$rows   = $db->query("SELECT * FROM anomalies WHERE $where ORDER BY (status='open') DESC, updated_at DESC LIMIT 300")->fetchAll();
$openN  = (int) $db->query("SELECT COUNT(*) FROM anomalies WHERE status='open'")->fetchColumn();

const ANO_META = [
    'brute_force' => ['Brute-force',  'bi-shield-exclamation', 'danger'],
    'silence'     => ['Silence radio','bi-volume-mute',        'warning'],
    'new_host'    => ['Nouvel hôte',  'bi-pc-display',         'info'],
];

$pageTitle = APP_NAME . ' — Anomalies';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center flex-wrap mb-4" style="gap:14px">
    <div>
        <h4 class="mb-1" style="font-weight:700"><i class="bi bi-activity me-2 text-info"></i>Anomalies
            <?php if ($openN): ?><span class="badge bg-danger ms-1"><?= $openN ?></span><?php endif; ?></h4>
        <div class="text-muted" style="font-size:13px">
            Détectées automatiquement (cron) : brute-force, silence d'un équipement, nouvel hôte.
        </div>
    </div>
    <div class="ms-auto d-flex align-items-center" style="gap:8px">
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $status==='open'?'active':'' ?>" href="?status=open">Ouvertes</a>
            <a class="btn btn-outline-secondary <?= $status==='all'?'active':'' ?>" href="?status=all">Toutes</a>
        </div>
        <?php if ($openN): ?><button class="btn btn-outline-info btn-sm" onclick="ackAll()"><i class="bi bi-check2-all me-1"></i>Tout acquitter</button><?php endif; ?>
    </div>
</div>

<div class="dash-card">
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="font-size:13px">
            <thead><tr><th style="width:140px">Type</th><th>Détail</th><th style="width:130px">Hôte / IP</th>
                <th style="width:120px">Dernière</th><th style="width:90px">État</th><th style="width:100px"></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-4"><i class="bi bi-check-circle me-1 text-success"></i>Aucune anomalie. Tout est calme.</td></tr><?php endif; ?>
            <?php foreach ($rows as $a): $m = ANO_META[$a['type']] ?? [$a['type'],'bi-question','secondary']; ?>
                <tr data-id="<?= (int)$a['id'] ?>">
                    <td><span class="badge bg-<?= $m[2] ?>-subtle text-<?= $m[2] ?>"><i class="bi <?= $m[1] ?> me-1"></i><?= h($m[0]) ?></span></td>
                    <td><?= h($a['detail']) ?></td>
                    <td class="mono"><?= h($a['host'] ?: $a['source_ip'] ?: '—') ?></td>
                    <td class="text-muted" title="<?= h($a['updated_at']) ?>"><?= h(time_ago($a['updated_at'])) ?></td>
                    <td>
                        <?php if ($a['status']==='open'): ?><span class="badge bg-danger">ouverte</span>
                        <?php elseif ($a['status']==='resolved'): ?><span class="badge bg-success-subtle text-success">résolue</span>
                        <?php else: ?><span class="badge bg-secondary">acquittée</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['status']==='open'): ?>
                            <button class="btn btn-sm btn-outline-info border-0 py-0" title="Acquitter" onclick="ack(<?= (int)$a['id'] ?>)"><i class="bi bi-check-lg"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger border-0 py-0" title="Supprimer" onclick="del(<?= (int)$a['id'] ?>)"><i class="bi bi-trash"></i></button>
                    </td>
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
    t.textContent = msg; w.appendChild(t); setTimeout(() => t.remove(), 3000);
}
async function api(method, body) {
    const r = await fetch('/api/anomalies.php', { method, headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF}, body: body?JSON.stringify(body):undefined });
    const d = await r.json(); if (!d.success) throw new Error(d.error || 'Erreur'); return d;
}
async function ack(id)    { try { await api('POST', { action:'ack', id }); location.reload(); } catch(e){ toast(e.message,false); } }
async function ackAll()   { try { await api('POST', { action:'ack_all' }); location.reload(); } catch(e){ toast(e.message,false); } }
async function del(id)    { if(!confirm('Supprimer cette anomalie ?'))return; try { await api('DELETE', { id }); location.reload(); } catch(e){ toast(e.message,false); } }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
