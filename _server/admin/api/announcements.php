<?php
/**
 * Public, read-only list of the announcements showing today, for the home page
 * at https://bayswatercommon.org/. Returns JSON:
 *   {"announcements":[{"id":1,"title":"...","html":"<p>...</p>","start_date":"2026-10-01","expiry_date":"2026-10-31"}]}
 * "html" is produced by format_announcement(), which escapes everything except a few safe tags.
 */
declare(strict_types=1);

const BCHOA = true;
require dirname(__DIR__) . '/inc/lib.php';

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, PUBLIC_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET');
    http_response_code(204);
    exit;
}

$out = [];
foreach (announcements_all() as $a) {
    if (announcement_status($a) === 'active') {
        $out[] = [
            'id'          => $a['id'],
            'title'       => $a['title'],
            'html'        => format_announcement($a['body']),
            'start_date'  => $a['start_date'],
            'expiry_date' => $a['expiry_date'],
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
echo json_encode(['announcements' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
