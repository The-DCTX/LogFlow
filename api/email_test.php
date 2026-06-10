<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/email.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

$test_log = [
    'host'      => 'logflow-test',
    'program'   => 'email_test',
    'severity'  => 3,
    'message'   => 'Ceci est un email de test envoyé depuis LogFlow Setup. Si vous le recevez, la configuration SMTP est correcte.',
    'os'        => 'other',
    'source_ip' => '127.0.0.1',
    'sec_event' => null,
];

try {
    $to = get_setting('smtp_to', '');
    if (empty($to)) {
        exit(json_encode(['ok' => false, 'error' => "Adresse de destination (SMTP To) non configurée."]));
    }
    send_email($to, $test_log, true);
    echo json_encode(['ok' => true, 'message' => "Email de test envoyé à $to"]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
