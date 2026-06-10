<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$db = db();

$host     = trim($_GET['host'] ?? '');
$severity = $_GET['severity'] ?? '';
$program  = trim($_GET['program'] ?? '');
$search   = trim($_GET['search'] ?? '');
$os       = trim($_GET['os'] ?? '');
$from     = $_GET['from'] ?? '';
$to       = $_GET['to'] ?? '';
$period   = $_GET['period'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = min(500, max(10, (int)($_GET['limit'] ?? LOGS_PER_PAGE)));
$offset   = ($page - 1) * $limit;

$where = ['1=1'];
$params = [];

if ($host !== '') { $where[] = 'host = ?'; $params[] = $host; }
if ($severity !== '' && ctype_digit($severity)) { $where[] = 'severity = ?'; $params[] = (int)$severity; }
if ($os !== '') { $where[] = 'os = ?'; $params[] = $os; }
if ($program !== '') { $where[] = 'program LIKE ?'; $params[] = '%' . $program . '%'; }
if ($search !== '') {
    $where[] = 'MATCH(host, program, message) AGAINST(? IN BOOLEAN MODE)';
    $params[] = $search . '*';
}
if ($period !== '') {
    $map = ['5m'=>5,'15m'=>15,'1h'=>60,'6h'=>360,'24h'=>1440,'7d'=>10080];
    if (isset($map[$period])) { $where[] = 'received_at >= NOW() - INTERVAL ? MINUTE'; $params[] = $map[$period]; }
} elseif ($from !== '') {
    $where[] = 'received_at >= ?'; $params[] = $from . ' 00:00:00';
}
if ($to !== '') { $where[] = 'received_at <= ?'; $params[] = $to . ' 23:59:59'; }

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE {$whereStr}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$logsStmt = $db->prepare("SELECT * FROM logs WHERE {$whereStr} ORDER BY received_at DESC LIMIT {$limit} OFFSET {$offset}");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

// Hosts avec counts (pour sidebar)
$hostsRaw = $db->query("SELECT host, COUNT(*) as cnt, MAX(received_at) as last_seen FROM logs GROUP BY host ORDER BY cnt DESC")->fetchAll();

echo json_encode([
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / $limit),
    'logs'    => $logs,
    'hosts'   => $hostsRaw,
]);
