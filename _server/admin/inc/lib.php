<?php
/**
 * Bayswater Common HOA admin: shared helpers (storage, sessions, auth, formatting).
 *
 * Data lives OUTSIDE the web root, in /home/<account>/bchoa-data/ by default,
 * so it can never be downloaded. Set the BCHOA_DATA_DIR environment variable
 * to use another folder (for local testing).
 */
declare(strict_types=1);

defined('BCHOA') or exit;

ini_set('display_errors', '0');
date_default_timezone_set('America/New_York');

const APP_NAME       = 'Bayswater Common HOA';
const MIN_PASSWORD   = 10;
const SESSION_IDLE   = 7200;   // sign out after 2 hours without activity
const LOGIN_MAX_FAIL = 5;      // failed sign-ins allowed...
const LOGIN_WINDOW   = 900;    // ...per 15 minutes, per IP and per email
const MAX_TITLE      = 120;
const MAX_BODY       = 2000;
const PUBLIC_ORIGINS = [
    'https://bayswatercommon.org',
    'https://www.bayswatercommon.org',
    'https://bchoa.github.io',
];

// ---------- General ----------

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function clean_line(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function clean_text(string $s, int $max): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

// ---------- Storage (JSON files with locking) ----------

function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = getenv('BCHOA_DATA_DIR') ?: dirname(__DIR__, 3) . '/bchoa-data';
    foreach (['', '/sessions', '/throttle'] as $sub) {
        if (!is_dir($dir . $sub) && !@mkdir($dir . $sub, 0700, true)) {
            http_response_code(500);
            exit('The data folder could not be created. Please contact the site administrator.');
        }
    }
    // Belt and braces: if the folder ever ends up inside the web root, refuse web access to it.
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function store_path(string $name): string
{
    return data_dir() . "/$name.json";
}

function store_read(string $name, array $default): array
{
    $file = store_path($name);
    if (!is_file($file)) {
        return $default;
    }
    $fh = fopen($file, 'r');
    if (!$fh) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($raw === '' || $raw === false) {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(500);
        exit("The $name data file is damaged. Please contact the site administrator.");
    }
    return $data;
}

/**
 * Read-modify-write under an exclusive lock. $fn receives the data by reference;
 * whatever it returns is passed back. Return false from $fn to skip saving.
 */
function store_update(string $name, array $default, callable $fn)
{
    $file = store_path($name);
    $fh = fopen($file, 'c+');
    if (!$fh) {
        http_response_code(500);
        exit('The data file could not be opened. Please contact the site administrator.');
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = ($raw === '' || $raw === false) ? $default : json_decode($raw, true);
    if (!is_array($data)) {
        flock($fh, LOCK_UN);
        fclose($fh);
        http_response_code(500);
        exit("The $name data file is damaged. Please contact the site administrator.");
    }
    $result = $fn($data);
    if ($result !== false) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json . "\n");
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($file, 0600);
    return $result;
}

const USERS_DEFAULT = ['next_id' => 1, 'users' => []];
const ANN_DEFAULT   = ['next_id' => 1, 'announcements' => []];

function audit(string $who, string $what): void
{
    $line = now() . "\t" . str_replace(["\t", "\n"], ' ', $who) . "\t" . str_replace(["\t", "\n"], ' ', $what) . "\n";
    @file_put_contents(data_dir() . '/audit.log', $line, FILE_APPEND | LOCK_EX);
}

function audit_tail(int $n): array
{
    $file = data_dir() . '/audit.log';
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_reverse(array_slice($lines, -$n));
}

// ---------- Users ----------

function users_all(): array
{
    return store_read('users', USERS_DEFAULT)['users'];
}

function user_by_id(int $id): ?array
{
    foreach (users_all() as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    foreach (users_all() as $u) {
        if ($u['email'] === $email) {
            return $u;
        }
    }
    return null;
}

function is_admin(array $u): bool
{
    return $u['role'] === 'admin';
}

/** Why this user can't sign in right now, or '' if they can. */
function user_blocked_reason(array $u): string
{
    if (empty($u['active'])) {
        return 'This account is turned off. Please ask an administrator.';
    }
    if (!empty($u['expires_on']) && $u['expires_on'] < today()) {
        return 'Your access expired on ' . $u['expires_on'] . '. Please ask an administrator to renew it.';
    }
    return '';
}

function count_active_admins(array $users, ?int $exceptId = null): int
{
    $n = 0;
    foreach ($users as $u) {
        if ($u['id'] !== $exceptId && $u['role'] === 'admin' && !empty($u['active'])
            && (empty($u['expires_on']) || $u['expires_on'] >= today())) {
            $n++;
        }
    }
    return $n;
}

function password_problem(string $pw, string $confirm): string
{
    if ($pw !== $confirm) {
        return 'The two passwords do not match.';
    }
    if (mb_strlen($pw) < MIN_PASSWORD) {
        return 'Passwords must be at least ' . MIN_PASSWORD . ' characters long.';
    }
    if (mb_strlen($pw) > 200) {
        return 'That password is too long.';
    }
    return '';
}

function random_password(): string
{
    // Easy to read aloud or type: no 0/O, 1/l/I.
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
        if ($i === 3 || $i === 7) {
            $out .= '-';
        }
    }
    return $out;
}

// ---------- Sessions & CSRF ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_save_path(data_dir() . '/sessions');
    session_name('bchoa_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    start_session();
    $sent = (string)($_POST['csrf'] ?? '');
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/** The signed-in user, re-read from storage on every request so changes apply at once. */
function current_user(): ?array
{
    start_session();
    $id = $_SESSION['uid'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    if (time() - (int)($_SESSION['seen'] ?? 0) > SESSION_IDLE) {
        logout();
        return null;
    }
    $u = user_by_id($id);
    if (!$u || user_blocked_reason($u) !== '' || (int)$u['session_version'] !== (int)($_SESSION['ver'] ?? -1)) {
        logout();
        return null;
    }
    $_SESSION['seen'] = time();
    return $u;
}

function login_as(array $u): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid']  = $u['id'];
    $_SESSION['ver']  = (int)$u['session_version'];
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_regenerate_id(true);
}

function flash(string $type, string $msg): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $query = ''): void
{
    header('Location: ./' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

// ---------- Sign-in throttling ----------

function throttle_file(string $key): string
{
    return data_dir() . '/throttle/' . hash('sha256', $key);
}

function throttle_hits(string $key): array
{
    $f = throttle_file($key);
    if (!is_file($f)) {
        return [];
    }
    $cut = time() - LOGIN_WINDOW;
    return array_values(array_filter(
        array_map('intval', explode(',', (string)file_get_contents($f))),
        fn($t) => $t > $cut
    ));
}

function throttled(string $email): bool
{
    return count(throttle_hits('ip:' . client_ip())) >= LOGIN_MAX_FAIL * 4
        || count(throttle_hits('email:' . strtolower($email))) >= LOGIN_MAX_FAIL;
}

function throttle_fail(string $email): void
{
    foreach (['ip:' . client_ip(), 'email:' . strtolower($email)] as $key) {
        $hits = throttle_hits($key);
        $hits[] = time();
        @file_put_contents(throttle_file($key), implode(',', $hits), LOCK_EX);
    }
}

function throttle_clear(string $email): void
{
    @unlink(throttle_file('email:' . strtolower($email)));
}

// ---------- Announcements ----------

function announcements_all(): array
{
    $list = store_read('announcements', ANN_DEFAULT)['announcements'];
    usort($list, fn($a, $b) => [$a['start_date'], $a['id']] <=> [$b['start_date'], $b['id']]);
    return $list;
}

function announcement_status(array $a): string
{
    $t = today();
    if ($a['start_date'] > $t) {
        return 'scheduled';
    }
    if ($a['expiry_date'] < $t) {
        return 'expired';
    }
    return 'active';
}

/**
 * Turn an announcement's plain text into safe HTML.
 * Everything is escaped first; then only these are recognised:
 *   **bold**   *italic*   [link text](https://...)   bare https:// links
 *   a blank line starts a new paragraph; a single line break is kept.
 */
function format_announcement(string $text): string
{
    $s = h($text);
    $tokens = [];
    $keep = function (string $html) use (&$tokens): string {
        $tokens[] = $html;
        return "\x01" . (count($tokens) - 1) . "\x02";
    };
    $safeUrl = function (string $url): ?string {
        // Absolute http(s)/mailto links, or a relative page on the public site (e.g. calendar.html).
        if (preg_match('#^(https?://|mailto:)[^\s<>"]+$#i', $url)
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]*(\#[A-Za-z0-9_\-]*)?$#', $url)) {
            return $url;
        }
        return null;
    };
    $linkHtml = function (string $url, string $label): string {
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '"' . $ext . '>' . $label . '</a>';
    };

    // [text](url)
    $s = preg_replace_callback('/\[([^\]\n]{1,200})\]\(([^)\s]{1,500})\)/u', function ($m) use ($keep, $safeUrl, $linkHtml) {
        $url = $safeUrl($m[2]);
        return $url === null ? $m[0] : $keep($linkHtml($url, $m[1]));
    }, $s) ?? $s;

    // Bare web addresses
    $s = preg_replace_callback('#\bhttps?://[^\s<>"\x01\x02]+#iu', function ($m) use ($keep, $linkHtml) {
        $url = rtrim($m[0], '.,;:!?)');
        $tail = substr($m[0], strlen($url));
        return $keep($linkHtml($url, $url)) . $tail;
    }, $s) ?? $s;

    // **bold** then *italic*
    $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/us', '<em>$1</em>', $s) ?? $s;

    // Paragraphs and line breaks
    $paras = preg_split('/\n\s*\n/', $s) ?: [];
    $s = implode('', array_map(fn($p) => '<p>' . nl2br(trim($p), false) . '</p>', array_filter($paras, fn($p) => trim($p) !== '')));

    return preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $tokens[(int)$m[1]], $s) ?? $s;
}

// ---------- Meeting minutes ----------

const MIN_DEFAULT = ['next_id' => 1, 'next_item' => 1, 'meetings' => []];

// Allowed upload types, and how each is shown on the public site.
const MINUTES_EXT = [
    'pdf' => 'pdf',
    'mp3' => 'audio', 'm4a' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'aac' => 'audio',
    'doc' => 'document', 'docx' => 'document', 'txt' => 'document', 'vtt' => 'transcript',
];

/** Public address of this admin site, used to build file links (not taken from the request). */
function site_url(): string
{
    return rtrim(getenv('BCHOA_SITE_URL') ?: 'https://admin.bayswatercommon.org', '/');
}

/** Folder for uploaded minutes files (public, but code can never run there). */
function minutes_dir(): string
{
    $files = dirname(__DIR__) . '/files';
    $dir = $files . '/minutes';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        http_response_code(500);
        exit('The uploads folder could not be created. Please contact the site administrator.');
    }
    // Keep the folder's rules in step with inc/uploads.htaccess (re-copied whenever it changes).
    $rules = __DIR__ . '/uploads.htaccess';
    if (!is_file("$files/.htaccess") || md5_file("$files/.htaccess") !== md5_file($rules)) {
        @copy($rules, "$files/.htaccess");
    }
    return $dir;
}

function meetings_all(): array
{
    $list = store_read('minutes', MIN_DEFAULT)['meetings'];
    usort($list, fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
    return $list;
}

function meeting_by_id(int $id): ?array
{
    foreach (store_read('minutes', MIN_DEFAULT)['meetings'] as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

function item_url(array $it): string
{
    return $it['file'] !== '' ? site_url() . '/files/minutes/' . rawurlencode($it['file']) : $it['url'];
}

function human_size(int $bytes): string
{
    return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Largest upload PHP will accept, in bytes. */
function upload_limit(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1073741824;
            case 'm': return $n * 1048576;
            case 'k': return $n * 1024;
        }
        return $n;
    };
    return min($toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size')));
}

/** May this website origin read the public JSON feeds? (BCHOA_TEST_ORIGIN adds one for local testing.) */
function origin_allowed(string $origin): bool
{
    return in_array($origin, PUBLIC_ORIGINS, true)
        || ($origin !== '' && $origin === getenv('BCHOA_TEST_ORIGIN'));
}
