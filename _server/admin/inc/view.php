<?php
/** Bayswater Common HOA admin: page layout and small form helpers. */
declare(strict_types=1);

defined('BCHOA') or exit;

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function render(string $title, string $body, ?array $user = null, string $active = ''): void
{
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $nav = '';
    if ($user && empty($user['must_change'])) {
        $links = ['announcements' => 'Announcements', 'minutes' => 'Meeting minutes'];
        if (is_admin($user)) {
            $links['users'] = 'Users';
            $links['activity'] = 'Activity';
        }
        $links['password'] = 'My password';
        foreach ($links as $p => $label) {
            $cls = $p === $active ? ' class="on"' : '';
            $nav .= '<a href="?p=' . $p . '"' . $cls . '>' . h($label) . '</a>';
        }
    }
    $who = '';
    if ($user) {
        $who = '<div class="who"><span>' . h($user['name'] ?: $user['email']) . ' <small>(' . (is_admin($user) ? 'Admin' : 'Editor') . ')</small></span>'
             . '<form method="post" action="?p=logout">' . csrf_field() . '<button class="btn btn-quiet" type="submit">Sign out</button></form></div>';
    }
    $flashes = '';
    foreach (take_flashes() as [$type, $msg]) {
        $flashes .= '<div class="flash ' . h($type) . '" role="status">' . h($msg) . '</div>';
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . h($title) . ' | ' . APP_NAME . ' Admin</title>'
       . '<link rel="stylesheet" href="assets/admin.css?v=3">'
       . '</head><body>'
       . '<header class="top"><div class="wrap head"><div class="brand"><strong>' . APP_NAME . '</strong><span>Website admin</span></div>' . $who . '</div>'
       . ($nav ? '<nav class="wrap tabs" aria-label="Admin">' . $nav . '</nav>' : '')
       . '</header>'
       . '<main class="wrap">' . $flashes . $body . '</main>'
       . '<footer class="wrap"><a href="https://bayswatercommon.org/">View the public website</a></footer>'
       . '<script src="assets/admin.js?v=1"></script>'
       . '</body></html>';
    exit;
}

function field(string $label, string $input, string $help = ''): string
{
    return '<label class="field"><span class="lbl">' . h($label) . '</span>' . $input
         . ($help !== '' ? '<span class="help">' . $help . '</span>' : '') . '</label>';
}

function input(string $name, string $value = '', string $type = 'text', string $extra = ''): string
{
    return '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" ' . $extra . '>';
}

function post_button(string $page, array $hidden, string $label, string $class = 'btn', string $confirm = ''): string
{
    $f = '<form method="post" action="?p=' . h($page) . '" class="inline"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($hidden as $k => $v) {
        $f .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    return $f . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
}

function badge(string $text, string $kind): string
{
    return '<span class="badge ' . h($kind) . '">' . h($text) . '</span>';
}

function nice_date(string $ymd): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $dt ? $dt->format('M j, Y') : $ymd;
}
