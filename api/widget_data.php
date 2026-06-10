<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

$input  = json_decode(file_get_contents('php://input'), true);
$widget = $input['widget'] ?? null;
if (!is_array($widget)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Widget config required']));
}

$db      = db();
$type    = $widget['type']    ?? 'stat';
$filters = is_array($widget['filters'] ?? null) ? $widget['filters'] : [];
$display = is_array($widget['display']  ?? null) ? $widget['display']  : [];

// ── WHERE clause builder ──────────────────────────────────────
function buildWhere(array $f): array
{
    $clauses = [];
    $params  = [];

    $PERIOD_INT = [
        '1h' =>'1 HOUR',  '6h' =>'6 HOUR',  '24h'=>'24 HOUR',
        '7d' =>'7 DAY',   '30d'=>'30 DAY',   '90d'=>'90 DAY',
    ];
    $period = $f['period'] ?? '24h';
    if ($period === 'today') {
        $clauses[] = 'DATE(received_at) = CURDATE()';
    } elseif (isset($PERIOD_INT[$period])) {
        $iv = $PERIOD_INT[$period];
        $clauses[] = "received_at > NOW() - INTERVAL $iv";
    }
    // 'all' → pas de filtre temporel

    if (isset($f['severity_max']) && $f['severity_max'] !== null && $f['severity_max'] !== '') {
        $clauses[] = 'severity <= ?';  $params[] = (int)$f['severity_max'];
    }
    if (isset($f['severity_min']) && $f['severity_min'] !== null && $f['severity_min'] !== '') {
        $clauses[] = 'severity >= ?';  $params[] = (int)$f['severity_min'];
    }
    if (!empty($f['os']) && is_array($f['os'])) {
        $valid = array_values(array_filter($f['os'], fn($o) => in_array($o, ['linux','windows','macos','other'])));
        if ($valid) {
            $phs     = implode(',', array_fill(0, count($valid), '?'));
            $clauses[] = "os IN ($phs)";
            $params  = array_merge($params, $valid);
        }
    }
    foreach (['host', 'program', 'source_ip'] as $fld) {
        if (!empty($f[$fld])) { $clauses[] = "$fld LIKE ?"; $params[] = '%'.$f[$fld].'%'; }
    }
    if (isset($f['sec_event']) && $f['sec_event'] !== '') {
        if ($f['sec_event'] === '__any__')   $clauses[] = 'sec_event IS NOT NULL';
        elseif ($f['sec_event'] === '__none__') $clauses[] = 'sec_event IS NULL';
        else { $clauses[] = 'sec_event = ?'; $params[] = $f['sec_event']; }
    }
    if (isset($f['facility']) && $f['facility'] !== null && $f['facility'] !== '') {
        $clauses[] = 'facility = ?'; $params[] = (int)$f['facility'];
    }
    return [empty($clauses) ? '1=1' : implode(' AND ', $clauses), $params];
}

[$where, $params] = buildWhere($filters);
$ALLOWED_GRP = ['host','program','os','sec_event','source_ip','facility','severity','source'];
$data = null;

try {
    switch ($type) {

        case 'stat': {
            $agg  = $display['aggregate'] ?? 'count';
            $expr = match($agg) {
                'distinct_hosts'    => 'COUNT(DISTINCT host)',
                'distinct_programs' => 'COUNT(DISTINCT program)',
                'distinct_ips'      => 'COUNT(DISTINCT source_ip)',
                default             => 'COUNT(*)',
            };
            $s = $db->prepare("SELECT $expr FROM logs WHERE $where");
            $s->execute($params);
            $data = ['value' => (int)$s->fetchColumn()];
            break;
        }

        case 'topn':
        case 'pie': {
            $grp   = in_array($display['group_by'] ?? 'host', $ALLOWED_GRP) ? ($display['group_by'] ?? 'host') : 'host';
            $limit = min(50, max(3, (int)($display['limit'] ?? 10)));
            $s = $db->prepare(
                "SELECT $grp AS label, COUNT(*) cnt
                 FROM logs WHERE $where AND $grp IS NOT NULL AND $grp != ''
                 GROUP BY $grp ORDER BY cnt DESC LIMIT ?"
            );
            $s->execute([...$params, $limit]);
            $rows = $s->fetchAll();
            $ts   = $db->prepare("SELECT COUNT(*) FROM logs WHERE $where");
            $ts->execute($params);
            $data = ['rows' => $rows, 'total' => (int)$ts->fetchColumn(), 'group_by' => $grp];
            break;
        }

        case 'timeline': {
            $period = $filters['period'] ?? '24h';
            // Auto grain selon la période
            $auto   = in_array($period, ['7d','30d','90d','all']) ? 'day' : 'hour';
            $grain  = in_array($display['time_grain'] ?? '', ['hour','day']) ? $display['time_grain'] : $auto;
            $fmt    = $grain === 'day' ? '%Y-%m-%d' : '%Y-%m-%d %H:00';

            $now = time();
            $buckets = [];
            if ($grain === 'hour') {
                $n = match($period) { '1h'=>1,'6h'=>6,'24h'=>24,'today'=>(int)date('H')+1,default=>24 };
                for ($i = $n-1; $i >= 0; $i--) $buckets[date('Y-m-d H:00', $now - $i*3600)] = 0;
            } else {
                $n = match($period) { '1h'=>1,'6h'=>1,'24h'=>1,'today'=>1,'7d'=>7,'30d'=>30,'90d'=>90,'all'=>30,default=>7 };
                for ($i = $n-1; $i >= 0; $i--) $buckets[date('Y-m-d', $now - $i*86400)] = 0;
            }

            $s = $db->prepare("SELECT DATE_FORMAT(received_at,'$fmt') ts, COUNT(*) cnt FROM logs WHERE $where GROUP BY ts");
            $s->execute($params);
            foreach ($s->fetchAll() as $r) {
                if (array_key_exists($r['ts'], $buckets)) $buckets[$r['ts']] = (int)$r['cnt'];
            }
            $data = ['labels' => array_keys($buckets), 'values' => array_values($buckets), 'grain' => $grain];
            break;
        }

        case 'table': {
            $safe   = ['received_at','log_time','host','source_ip','facility','severity','program','pid','message','source','os','sec_event'];
            $cols   = array_values(array_filter(is_array($display['columns'] ?? null) ? $display['columns'] : [], fn($c) => in_array($c, $safe)));
            if (empty($cols)) $cols = ['received_at','host','severity','program','message'];
            $limit  = min(100, max(5, (int)($display['limit'] ?? 20)));
            $selcol = implode(',', $cols);
            $s = $db->prepare("SELECT $selcol FROM logs WHERE $where ORDER BY received_at DESC LIMIT ?");
            $s->execute([...$params, $limit]);
            $data = ['rows' => $s->fetchAll(), 'columns' => $cols];
            break;
        }

        case 'secevents': {
            $s = $db->prepare(
                "SELECT sec_event, COUNT(*) cnt FROM logs
                 WHERE $where AND sec_event IS NOT NULL
                 GROUP BY sec_event ORDER BY cnt DESC"
            );
            $s->execute($params);
            $enriched = [];
            foreach ($s->fetchAll() as $r) {
                $ev = SEC_EVENTS[$r['sec_event']] ?? null;
                $enriched[] = [
                    'type'  => $r['sec_event'],
                    'cnt'   => (int)$r['cnt'],
                    'label' => $ev ? $ev['label'] : $r['sec_event'],
                    'color' => $ev ? $ev['color'] : '#8b949e',
                    'icon'  => $ev ? $ev['icon']  : '🔍',
                ];
            }
            $data = ['events' => $enriched];
            break;
        }

        default: $data = [];
    }

    echo json_encode(['ok' => true, 'data' => $data]);

} catch (\Throwable $e) {
    error_log('[LogFlow/widget_data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
