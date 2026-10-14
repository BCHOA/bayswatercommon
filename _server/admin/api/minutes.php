<?php
/**
 * Public, read-only list of HOA meeting minutes for the Documents page at
 * https://bayswatercommon.org/documents.html. Newest meeting first. Returns JSON:
 *   {"meetings":[{"id":1,"date":"2026-10-01","title":"...","notes":"...",
 *                 "items":[{"kind":"pdf|audio|document|transcript|link","label":"...","url":"https://..."}]}]}
 * All text is plain text; the page inserts it with textContent.
 */
declare(strict_types=1);

const BCHOA = true;
require dirname(__DIR__) . '/inc/lib.php';

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (origin_allowed($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET');
    http_response_code(204);
    exit;
}

$out = [];
foreach (meetings_all() as $m) {
    $items = [];
    foreach ($m['items'] as $it) {
        $items[] = ['kind' => $it['kind'], 'label' => $it['label'], 'url' => item_url($it)];
    }
    $out[] = ['id' => $m['id'], 'date' => $m['date'], 'title' => $m['title'], 'notes' => $m['notes'], 'items' => $items];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
echo json_encode(['meetings' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
