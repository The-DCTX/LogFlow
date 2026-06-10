<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = get_setting('dashboard_layout', '');
    echo json_encode(['ok' => true, 'layout' => $raw ? json_decode($raw, true) : null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $layout = $input['layout'] ?? null;
    if (!is_array($layout)) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Invalid layout']));
    }
    $VALID_TYPES = ['stat','topn','timeline','table','pie','secevents'];
    $VALID_COLS  = [3, 4, 6, 8, 12];
    $clean = [];
    foreach (array_values($layout) as $i => $w) {
        if (!is_array($w)) continue;
        $clean[] = [
            'id'      => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($w['id'] ?? 'w'.$i)),
            'type'    => in_array($w['type'] ?? '', $VALID_TYPES) ? $w['type'] : 'stat',
            'title'   => substr(strip_tags((string)($w['title'] ?? '')), 0, 100),
            'cols'    => in_array((int)($w['cols'] ?? 6), $VALID_COLS) ? (int)$w['cols'] : 6,
            'order'   => $i,
            'filters' => is_array($w['filters'] ?? null) ? $w['filters'] : [],
            'display' => is_array($w['display'] ?? null) ? $w['display'] : [],
        ];
    }
    set_setting('dashboard_layout', json_encode($clean));
    echo json_encode(['ok' => true, 'saved' => count($clean)]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
