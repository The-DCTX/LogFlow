<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/discord.php';
require_once __DIR__ . '/../includes/email.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// Vérification clé API — header uniquement, pas de GET pour éviter les fuites dans les logs Apache
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($key)) {
    http_response_code(401);
    exit(json_encode(['error' => 'API key required']));
}

$db = db();
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ?");
$stmt->execute([$key]);
$keyRow = $stmt->fetch();
if (!$keyRow) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid API key']));
}

$db->prepare("UPDATE api_keys SET last_used = NOW() WHERE id = ?")->execute([$keyRow['id']]);

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON']));
}

// Support tableau de logs ou log unique
$logs = isset($data[0]) ? $data : [$data];
$xff       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
$xff_first = $xff ? trim(explode(',', $xff)[0]) : '';
$source_ip = ($xff_first && filter_var($xff_first, FILTER_VALIDATE_IP)) ? $xff_first : ($_SERVER['REMOTE_ADDR'] ?? '');

// ── Validation et préparation des lignes (max 500 par batch) ──
$rows    = [];
$discord = [];

foreach (array_slice($logs, 0, 500) as $log) {
    $message  = $log['message'] ?? '';
    $program  = substr($log['program'] ?? '', 0, 100);
    $severity = (int)($log['severity'] ?? 6);
    $os       = in_array($log['os'] ?? '', ['linux','windows','macos','other']) ? $log['os'] : null;
    $host     = substr($log['host'] ?? $source_ip, 0, 255);

    $sec       = detect_sec_event($message, $program);
    $sec_event = $sec ? $sec['event'] : null;
    if ($sec && $sec['severity'] !== null) $severity = $sec['severity'];

    // Valeurs positionnelles pour l'INSERT multi-lignes (10 params par ligne, 'http' est littéral SQL)
    $rows[] = [
        !empty($log['time']) ? date('Y-m-d H:i:s', strtotime($log['time'])) : null,
        $host,
        substr($source_ip, 0, 45),
        (int)($log['facility'] ?? 1),
        $severity,
        $program,
        !empty($log['pid']) ? (int)$log['pid'] : null,
        $message,
        $os,
        $sec_event,
    ];
    $discord[] = [
        'host'      => $host,
        'program'   => $program,
        'severity'  => $severity,
        'message'   => $message,
        'os'        => $os,
        'source_ip' => $source_ip,
        'sec_event' => $sec_event,
    ];
}

// ── INSERT multi-valeurs en transaction unique ─────────────────
if (!empty($rows)) {
    $ph  = implode(',', array_fill(0, count($rows), "(?,?,?,?,?,?,?,?,'http',?,?)"));
    $sql = "INSERT INTO logs (log_time,host,source_ip,facility,severity,program,pid,message,source,os,sec_event) VALUES $ph";
    $db->beginTransaction();
    try {
        $db->prepare($sql)->execute(array_merge(...$rows));
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Notifications Discord + Email ─────────────────────────────
foreach ($discord as $d) {
    maybe_notify_discord($d);
    maybe_notify_email($d);
}

echo json_encode(['ok' => true, 'inserted' => count($rows)]);

// ── Déclenchement asynchrone du worker Discord ─────────────────
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    process_discord_queue();
} elseif (function_exists('exec')) {
    @exec('php ' . escapeshellarg(realpath(__DIR__ . '/discord_worker.php')) . ' > /dev/null 2>&1 &');
}
