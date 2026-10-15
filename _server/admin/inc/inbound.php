<?php
/**
 * Bayswater Common HOA admin: posting by email to news@bayswatercommon.org.
 *
 * Every few minutes a cron job reads the news@ mailbox (run_news()). For each message:
 *   - From someone on the dashboard user list (account on, not expired, not a forged sender):
 *       subject "Calendar: ..." -> add a Google Calendar event, anything else -> post an announcement.
 *       Success or failure is reported back to the sender.
 *   - From anyone else: forwarded to the board (HOABoard@) for approval; no reply to the sender.
 *   - Automatic mail (bounces, out-of-office, our own messages): ignored.
 * Handled messages are moved to folders Processed / Forwarded / Rejected / Ignored in the mailbox.
 */
declare(strict_types=1);

defined('BCHOA') or exit;

const NEWS_MAX_PER_RUN = 25;

// ---------- Parsing ----------

/** Parse a date written by a person. Returns Y-m-d or null. */
function parse_human_date(string $s): ?string
{
    $s = trim(preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?\s+/i', '', trim($s)) ?? $s);
    $s = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $s) ?? $s;
    foreach (['!Y-m-d', '!n/j/Y', '!n/j/y', '!n-j-Y', '!F j, Y', '!F j Y', '!M j, Y', '!M j Y', '!M. j, Y', '!j F Y', '!j M Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $y = (int)$d->format('Y');
            if ($y >= 2000 && $y <= 2100) {
                return $d->format('Y-m-d');
            }
        }
    }
    return null;
}

/** Parse "7 PM", "7:30pm", "19:00". Returns H:i or null. */
function parse_human_time(string $s): ?string
{
    $s = strtolower(str_replace(['.', ' '], '', trim($s)));
    if (in_array($s, ['noon', '12noon'], true)) {
        return '12:00';
    }
    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?(am|pm)?$/', $s, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
    $ap = $m[3] ?? '';
    if ($min > 59 || ($ap !== '' && ($h < 1 || $h > 12)) || ($ap === '' && $h > 23)) {
        return null;
    }
    if ($ap === 'pm' && $h < 12) {
        $h += 12;
    } elseif ($ap === 'am' && $h === 12) {
        $h = 0;
    }
    return sprintf('%02d:%02d', $h, $min);
}

/** Parse "2 - 4 PM", "2:00 PM to 4:00 PM", "6pm". Returns [start, end|''] or null. */
function parse_time_range(string $s): ?array
{
    $parts = preg_split('/\s*(?:-|–|—|\bto\b|\buntil\b)\s*/iu', trim($s)) ?: [];
    if (count($parts) === 1) {
        $t = parse_human_time($parts[0]);
        return $t === null ? null : [$t, ''];
    }
    if (count($parts) !== 2) {
        return null;
    }
    // "2 - 4 PM": the am/pm on the end time applies to a bare start time.
    if (preg_match('/(am|pm)$/i', str_replace(['.', ' '], '', $parts[1]), $m) && !preg_match('/(am|pm|:|noon)/i', $parts[0])) {
        $parts[0] .= $m[1];
    }
    $a = parse_human_time($parts[0]);
    $b = parse_human_time($parts[1]);
    if ($a === null || $b === null) {
        return null;
    }
    return [$a, $b];
}

/** Remove signatures, quoted replies and phone footers from an email body. */
function clean_email_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $out = [];
    foreach (explode("\n", $body) as $line) {
        $t = trim($line);
        if ($t === '--' || $line === '-- ' || preg_match('/^(sent from my|get outlook for|-{3,}\s*original message|_{10,}|on .+wrote:$|from:\s.+@)/i', $t)) {
            break;
        }
        if (str_starts_with($t, '>')) {
            continue;
        }
        $out[] = rtrim($line);
    }
    return trim(implode("\n", $out));
}

/** Split leading "Key: value" lines from the text. Returns [fields (lower-case keys), rest]. */
function split_fields(string $text, array $known): array
{
    $lines = explode("\n", $text);
    $fields = [];
    $i = 0;
    for (; $i < count($lines); $i++) {
        $t = trim($lines[$i]);
        if ($t === '' && !$fields) {
            continue;                      // skip blank lines before the fields
        }
        if (preg_match('/^([A-Za-z][A-Za-z ]{1,20}):\s*(.*)$/', $t, $m) && in_array(strtolower(trim($m[1])), $known, true)) {
            $fields[strtolower(trim($m[1]))] = trim($m[2]);
            continue;
        }
        break;
    }
    return [$fields, trim(implode("\n", array_slice($lines, $i)))];
}

/** First present field among several names, or ''. */
function field_of(array $f, array $names): string
{
    foreach ($names as $n) {
        if (isset($f[$n]) && $f[$n] !== '') {
            return $f[$n];
        }
    }
    return '';
}

/**
 * Turn an email into an announcement or a calendar event.
 * Returns ['type' => 'announcement'|'calendar', 'data' => [...]] or ['error' => '...'].
 */
function parse_news_email(string $subject, string $body, string $today, int $defaultDays): array
{
    $subject = trim(preg_replace('/^\s*((re|fw|fwd)\s*:\s*)+/i', '', $subject) ?? $subject);
    $text = clean_email_body($body);

    if (preg_match('/^calendar\s*:\s*(.*)$/i', $subject, $m)) {
        $title = clean_line($m[1], 120);
        [$f, $desc] = split_fields($text, ['date', 'end date', 'time', 'location', 'where']);
        if ($title === '') {
            return ['error' => 'The subject needs an event name after "Calendar:", for example "Calendar: Pool Opening Party".'];
        }
        $dateRaw = field_of($f, ['date']);
        if ($dateRaw === '') {
            return ['error' => 'Please add a "Date:" line at the top of the message, for example "Date: 5/23/2026".'];
        }
        $date = parse_human_date($dateRaw);
        if ($date === null) {
            return ['error' => "The date \"$dateRaw\" was not understood. Please use a format like 5/23/2026 or May 23, 2026."];
        }
        $endDate = '';
        if (($endRaw = field_of($f, ['end date'])) !== '') {
            $endDate = parse_human_date($endRaw) ?? '';
            if ($endDate === '') {
                return ['error' => "The end date \"$endRaw\" was not understood. Please use a format like 5/24/2026."];
            }
            if ($endDate < $date) {
                return ['error' => 'The end date is before the event date.'];
            }
        }
        if (($endDate ?: $date) < $today) {
            return ['error' => "The event date ($date) is in the past."];
        }
        $start = $end = '';
        if (($timeRaw = field_of($f, ['time'])) !== '' && !preg_match('/^all\s*day$/i', $timeRaw)) {
            $r = parse_time_range($timeRaw);
            if ($r === null) {
                return ['error' => "The time \"$timeRaw\" was not understood. Please use a format like \"6:00 PM - 8:00 PM\", or leave the Time line out for an all-day event."];
            }
            [$start, $end] = $r;
            if ($end !== '' && $end <= $start && $endDate === '') {
                return ['error' => 'The end time is before the start time.'];
            }
        }
        return ['type' => 'calendar', 'data' => [
            'title' => $title, 'description' => mb_substr($desc, 0, 4000),
            'location' => clean_line(field_of($f, ['location', 'where']), 200),
            'date' => $date, 'end_date' => $endDate, 'start_time' => $start, 'end_time' => $end,
        ]];
    }

    $subject = trim(preg_replace('/^announcement\s*:\s*/i', '', $subject) ?? $subject);
    [$f, $msg] = split_fields($text, ['start', 'from', 'show from', 'end', 'until', 'show until', 'expires']);
    if ($subject === '') {
        return ['error' => 'The email needs a subject. The subject becomes the announcement title.'];
    }
    if (mb_strlen($subject) > MAX_TITLE) {
        return ['error' => 'The subject is too long for a title (' . MAX_TITLE . ' characters at most).'];
    }
    if ($msg === '') {
        return ['error' => 'The message is empty. Please write the announcement text in the body of the email.'];
    }
    if (mb_strlen($msg) > MAX_BODY) {
        return ['error' => 'The message is too long (' . MAX_BODY . ' characters at most). Please shorten it, or link to a document.'];
    }
    $start = $today;
    if (($raw = field_of($f, ['start', 'from', 'show from'])) !== '') {
        $start = parse_human_date($raw) ?? '';
        if ($start === '') {
            return ['error' => "The start date \"$raw\" was not understood. Please use a format like 5/23/2026 or May 23, 2026."];
        }
    }
    $end = date('Y-m-d', strtotime($start . " +$defaultDays days"));
    if (($raw = field_of($f, ['end', 'until', 'show until', 'expires'])) !== '') {
        $end = parse_human_date($raw) ?? '';
        if ($end === '') {
            return ['error' => "The end date \"$raw\" was not understood. Please use a format like 6/6/2026 or June 6, 2026."];
        }
    }
    if ($end < $start) {
        return ['error' => "The end date ($end) is before the start date ($start)."];
    }
    if ($end < $today) {
        return ['error' => "The end date ($end) is already in the past, so the announcement would never show."];
    }
    return ['type' => 'announcement', 'data' => [
        'title' => clean_line($subject, MAX_TITLE), 'body' => clean_text($msg, MAX_BODY),
        'start_date' => $start, 'expiry_date' => $end,
    ]];
}

function news_how_to(string $mailbox): string
{
    return "HOW TO POST BY EMAIL to $mailbox\n\n"
         . "Announcement:\n"
         . "  Subject: the announcement title\n"
         . "  Body: optional date lines first, then the message, e.g.\n"
         . "    Start: 5/23/2026\n"
         . "    End: 6/6/2026\n"
         . "    The pool opens Saturday at 10 am. See the **calendar** for hours.\n"
         . "  Without dates, it shows from today for two weeks.\n\n"
         . "Calendar event:\n"
         . "  Subject: Calendar: event name\n"
         . "  Body:\n"
         . "    Date: 5/23/2026\n"
         . "    Time: 6:00 PM - 8:00 PM   (leave out for an all-day event)\n"
         . "    Location: Pavilion        (optional)\n"
         . "    Any description goes here.\n";
}

// ---------- Deciding what to do with one message ----------

/** Automatic or looping mail that should never get a reply. */
function is_automatic_mail(string $from, string $rawHeaders, string $mailbox): bool
{
    $from = strtolower($from);
    if ($from === strtolower($mailbox) || preg_match('/^(mailer-daemon|postmaster|no-?reply|bounce)/', $from)) {
        return true;
    }
    return (bool)preg_match('/^(auto-submitted:\s*(?!no\b)\S|precedence:\s*(bulk|junk|list)|x-bchoa-generated:|x-autoreply:|x-autorespond:)/im', $rawHeaders);
}

/** The receiving mail server says the sender address was forged. */
function sender_forged(string $rawHeaders): bool
{
    return (bool)preg_match('/^authentication-results:.*\bdmarc=fail\b/im', preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders) ?? '');
}

/**
 * Decide and act on one message. $msg: from, from_name, subject, body, headers, message_id.
 * $io: send(to, subject, text, opts) -> error|'', add_calendar(event) -> [link, error],
 *      add_announcement(data, email) -> void, today (Y-m-d).
 * Returns ['folder' => Processed|Forwarded|Rejected|Ignored, 'log' => text].
 */
function handle_news_message(array $msg, array $cfg, array $io): array
{
    $from = strtolower(trim($msg['from']));
    $subject = $msg['subject'];
    $postAddr = posting_address($cfg);
    if ($from === '' || is_automatic_mail($from, $msg['headers'], $cfg['mailbox'])) {
        return ['folder' => 'Ignored', 'log' => "ignored automatic message from $from: $subject"];
    }
    $reply = function (string $subj, string $text) use ($io, $msg, $from): string {
        return ($io['send'])($from, $subj, $text, ['in_reply_to' => $msg['message_id'], 'auto' => true]);
    };
    $user = user_by_email($from);
    $forged = sender_forged($msg['headers']);
    if (!$user || user_blocked_reason($user) !== '' || $forged) {
        $why = $forged ? 'WARNING: the mail system reports this sender address may be FORGED (DMARC failed).'
             : ($user ? 'The sender is on the website user list, but their access is turned off or expired.'
                      : 'The sender is not on the website user list.');
        $text = "A message was sent to {$postAddr} and needs a board member's approval before it is posted.\n\n"
              . "$why\n\nTo post it, sign in at https://admin.bayswatercommon.org/ and add it as an announcement, "
              . "or forward it to {$postAddr} from your own (listed) address.\n\n"
              . "----- Original message -----\nFrom: " . trim($msg['from_name'] . " <$from>") . "\nSubject: $subject\n\n" . trim($msg['body']) . "\n";
        $err = ($io['send'])($cfg['board_email'], "[Needs approval] $subject", $text, ['reply_to' => $from]);
        return ['folder' => $err === '' ? 'Forwarded' : 'Rejected',
                'log' => $err === '' ? "forwarded to the board for approval: $from \"$subject\"" : "could not forward $from \"$subject\" to the board: $err"];
    }

    $p = parse_news_email($subject, $msg['body'], $io['today'], (int)$cfg['default_days']);
    if (isset($p['error'])) {
        $reply("Not posted: $subject", "Your message to {$postAddr} was NOT posted.\n\nProblem: {$p['error']}\n\n"
            . "Please correct it and send it again.\n\n" . news_how_to($postAddr));
        return ['folder' => 'Rejected', 'log' => "rejected from $from \"$subject\": {$p['error']}"];
    }

    if ($p['type'] === 'calendar') {
        $e = $p['data'];
        [$link, $err] = ($io['add_calendar'])($e);
        if ($err !== '') {
            $reply("Not added to calendar: $subject", "Your calendar event was NOT added.\n\nProblem: $err\n\n"
                . "If this keeps happening, please contact a website admin.\n\n" . news_how_to($postAddr));
            return ['folder' => 'Rejected', 'log' => "calendar event from $from \"{$e['title']}\" failed: $err"];
        }
        $when = nice_date($e['date']) . ($e['end_date'] && $e['end_date'] !== $e['date'] ? ' to ' . nice_date($e['end_date']) : '')
              . ($e['start_time'] ? ', ' . date('g:i A', strtotime($e['start_time'])) . ($e['end_time'] ? ' - ' . date('g:i A', strtotime($e['end_time'])) : '') : ', all day');
        $reply("Added to calendar: {$e['title']}", "Your event was added to the Bayswater Common calendar.\n\n"
            . "Event: {$e['title']}\nWhen: $when\n" . ($e['location'] ? "Where: {$e['location']}\n" : '')
            . "\nSee it at https://bayswatercommon.org/calendar.html" . ($link ? "\nOr in Google Calendar: $link" : '') . "\n");
        return ['folder' => 'Processed', 'log' => "calendar event added by $from: \"{$e['title']}\" on {$e['date']}"];
    }

    $a = $p['data'];
    ($io['add_announcement'])($a, $from);
    $reply("Posted: {$a['title']}", "Your announcement is on the website.\n\n"
        . "Title: {$a['title']}\nShows: " . nice_date($a['start_date']) . ' to ' . nice_date($a['expiry_date']) . "\n\n"
        . "See it at https://bayswatercommon.org/\n"
        . "To change or remove it, sign in at https://admin.bayswatercommon.org/\n");
    return ['folder' => 'Processed', 'log' => "announcement posted by $from: \"{$a['title']}\" ({$a['start_date']} to {$a['expiry_date']})"];
}

function add_email_announcement(array $a, string $email): void
{
    store_update('announcements', ANN_DEFAULT, function (array &$d) use ($a, $email) {
        $d['announcements'][] = [
            'id' => $d['next_id']++, 'title' => $a['title'], 'body' => $a['body'],
            'start_date' => $a['start_date'], 'expiry_date' => $a['expiry_date'],
            'created_by' => $email, 'created_at' => now(), 'updated_by' => $email . ' (by email)', 'updated_at' => now(),
        ];
        return true;
    });
}

// ---------- Reading the mailbox ----------

function imap_decode_part(string $data, int $encoding, string $charset): string
{
    if ($encoding === ENCBASE64) {
        $data = (string)base64_decode($data);
    } elseif ($encoding === ENCQUOTEDPRINTABLE) {
        $data = quoted_printable_decode($data);
    }
    $charset = strtoupper($charset ?: 'UTF-8');
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $conv = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($conv !== false) {
            $data = $conv;
        }
    }
    return $data;
}

function html_to_text(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h\d)>#i', "\n", $html) ?? $html;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** The readable text of a message: the first text/plain part, else text/html converted to text. */
function imap_message_text($im, int $n): string
{
    $st = imap_fetchstructure($im, $n);
    $found = ['plain' => null, 'html' => null];
    $walk = function ($part, string $num) use (&$walk, &$found) {
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                $walk($sub, $num === '' ? (string)($i + 1) : $num . '.' . ($i + 1));
            }
            return;
        }
        $disp = strtolower((string)($part->disposition ?? ''));
        if ((int)$part->type !== TYPETEXT || $disp === 'attachment') {
            return;
        }
        $charset = '';
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $p) {
            if (strtolower($p->attribute) === 'charset') {
                $charset = $p->value;
            }
        }
        $kind = strtolower((string)$part->subtype) === 'html' ? 'html' : 'plain';
        if ($found[$kind] === null) {
            $found[$kind] = [$num === '' ? '1' : $num, (int)$part->encoding, $charset];
        }
    };
    $walk($st, '');
    foreach (['plain', 'html'] as $kind) {
        if ($found[$kind] !== null) {
            [$num, $enc, $cs] = $found[$kind];
            $t = imap_decode_part((string)imap_fetchbody($im, $n, $num, FT_PEEK), $enc, $cs);
            return $kind === 'html' ? html_to_text($t) : $t;
        }
    }
    return '';
}

function decode_mime_header(string $s): string
{
    $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
    return trim($d !== false ? $d : mb_decode_mimeheader($s));
}

/**
 * Check the news@ mailbox once. Returns ['ok' => bool, 'summary' => text, 'log' => [lines]].
 * Safe to run from cron or from the dashboard; a lock stops two runs overlapping.
 */
/** IMAP search: all messages, or only those sent to the posting address. */
function news_search_criteria(array $cfg): string
{
    return $cfg['only_to'] !== '' ? 'TO "' . str_replace(['"', '\\'], '', $cfg['only_to']) . '"' : 'ALL';
}

function run_news(): array
{
    $cfg = settings();
    $postAddr = posting_address($cfg);
    if (!$cfg['news_enabled']) {
        return ['ok' => true, 'summary' => 'Email posting is turned off in Settings.', 'log' => []];
    }
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'summary' => 'This server\'s PHP cannot read mailboxes (the IMAP extension is missing).', 'log' => []];
    }
    if ($cfg['mail_password'] === '') {
        return ['ok' => false, 'summary' => 'The news@ mailbox password has not been entered in Settings.', 'log' => []];
    }
    $lock = fopen(data_dir() . '/news.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => true, 'summary' => 'Another check is already running.', 'log' => []];
    }

    $server = '{' . $cfg['imap_host'] . ':' . (int)$cfg['imap_port'] . '/imap/ssl}';
    $im = @imap_open($server . 'INBOX', $cfg['mailbox'], $cfg['mail_password'], 0, 1);
    if (!$im) {
        $err = imap_last_error() ?: 'unknown error';
        imap_errors();
        save_news_status(['last_run' => now(), 'last_result' => 'failed', 'last_error' => "Could not open the mailbox: $err"]);
        flock($lock, LOCK_UN);
        return ['ok' => false, 'summary' => "Could not open the news@ mailbox: $err", 'log' => []];
    }

    // Folder names: some servers need an "INBOX." prefix for sub-folders.
    $boxes = imap_list($im, $server, '*') ?: [];
    $prefix = '';
    foreach ($boxes as $b) {
        if (stripos($b, $server . 'INBOX.') === 0) {
            $prefix = 'INBOX.';
            break;
        }
    }
    $ensured = [];
    $folder = function (string $name) use ($im, $server, $boxes, $prefix, &$ensured): string {
        $full = $prefix . $name;
        if (!isset($ensured[$full])) {
            if (!in_array($server . $full, $boxes, true)) {
                @imap_createmailbox($im, imap_utf7_encode($server . $full));
            }
            $ensured[$full] = true;
        }
        return $full;
    };

    $io = [
        'today' => today(),
        'send' => fn(string $to, string $subj, string $text, array $opts) => send_mail($cfg, $to, $subj, $text, $opts),
        'add_calendar' => fn(array $ev) => gcal_add_event($cfg, $ev),
        'add_announcement' => fn(array $a, string $email) => add_email_announcement($a, $email),
    ];

    $log = [];
    // With a posting address set (e.g. bayswatercommon+news@gmail.com), leave all other mail alone.
    $nums = imap_search($im, news_search_criteria($cfg)) ?: [];
    foreach (array_slice($nums, 0, NEWS_MAX_PER_RUN) as $n) {
        $h = imap_headerinfo($im, $n);
        $f = $h->from[0] ?? null;
        $msg = [
            'from' => $f ? ($f->mailbox . '@' . ($f->host ?? '')) : '',
            'from_name' => $f && isset($f->personal) ? decode_mime_header($f->personal) : '',
            'subject' => decode_mime_header((string)($h->subject ?? '')),
            'message_id' => (string)($h->message_id ?? ''),
            'headers' => (string)imap_fetchheader($im, $n),
            'body' => imap_message_text($im, $n),
        ];
        $r = handle_news_message($msg, $cfg, $io);
        $log[] = $r['log'];
        audit($postAddr, $r['log']);
        if (!@imap_mail_move($im, (string)$n, $folder($r['folder']))) {
            imap_delete($im, (string)$n);     // could not file it; delete so it is not handled twice
        }
    }
    imap_expunge($im);
    imap_close($im);
    imap_errors();
    imap_alerts();

    $summary = count($log) ? count($log) . ' message(s) handled.' : 'No new messages.';
    save_news_status(['last_run' => now(), 'last_result' => $summary, 'last_error' => '']);
    flock($lock, LOCK_UN);
    return ['ok' => true, 'summary' => $summary, 'log' => $log];
}
