<?php
/** Bayswater Common HOA admin: Settings page (admins only) for email posting and Google Calendar. */
declare(strict_types=1);

defined('BCHOA') or exit;

function page_settings(array $me): void
{
    $cfg = settings();
    $st = news_status();
    $gEmail = google_client_email($cfg);
    $imapOk = function_exists('imap_open');
    $cron = '/usr/local/bin/php ' . __DIR__ . '/cron-news.php >/dev/null 2>&1';

    $status = '<p class="help">Last mailbox check: <strong>' . h($st['last_run'] ?: 'never') . '</strong>'
            . ($st['last_result'] ? ' — ' . h($st['last_result']) : '')
            . ($st['last_error'] ? '<br><span class="err">' . h($st['last_error']) . '</span>' : '') . '</p>';

    $body = '<h1>Settings</h1>'
          . '<section class="card"><h2>Posting by email</h2>'
          . '<p class="help">People on the Users list can email <strong>' . h($cfg['mailbox']) . '</strong> to post an announcement, or to add a Google Calendar event (subject starting with "Calendar:"). '
          . 'Mail from anyone else is forwarded to the board for approval. Problems are reported back to the sender.</p>'
          . $status
          . ($imapOk ? '' : '<div class="flash error">This server\'s PHP cannot read mailboxes (IMAP extension missing).</div>')
          . '<form method="post" action="?p=settings_save">' . csrf_field()
          . '<label class="check"><input type="checkbox" name="news_enabled" value="1"' . ($cfg['news_enabled'] ? ' checked' : '') . '> Email posting is on</label>'
          . '<div class="row">'
          . field('Mailbox address', input('mailbox', $cfg['mailbox'], 'email', 'required'))
          . field('Mailbox password', input('mail_password', '', 'password', 'autocomplete="new-password" placeholder="' . ($cfg['mail_password'] !== '' ? '•••••• saved (leave blank to keep)' : 'not set') . '"'))
          . '</div><div class="row">'
          . field('Board email (approvals go here)', input('board_email', $cfg['board_email'], 'email', 'required'))
          . field('Days to show if no end date', input('default_days', (string)$cfg['default_days'], 'number', 'min="1" max="90"'))
          . '</div>'
          . '<details><summary>Mail server details (normally unchanged)</summary><div class="row">'
          . field('IMAP server', input('imap_host', $cfg['imap_host']))
          . field('IMAP port', input('imap_port', (string)$cfg['imap_port'], 'number'))
          . '</div><div class="row">'
          . field('SMTP server', input('smtp_host', $cfg['smtp_host']))
          . field('SMTP port', input('smtp_port', (string)$cfg['smtp_port'], 'number'))
          . '</div></details>'
          . '<h2 class="gap">Google Calendar</h2>'
          . field('Calendar ID', input('calendar_id', $cfg['calendar_id'], 'text', 'required'), 'From Google Calendar &gt; Settings for this calendar &gt; Integrate calendar.')
          . field('Service-account key (JSON)', '<textarea name="google_key" rows="3" placeholder="' . ($gEmail !== '' ? 'Key saved for ' . h($gEmail) . ' (leave blank to keep)' : 'Paste the whole JSON key file here') . '"></textarea>',
                  $gEmail !== '' ? 'Share the calendar with <strong>' . h($gEmail) . '</strong> and choose "Make changes to events".' : 'Not set up yet.')
          . '<div class="buttons"><button class="btn" type="submit">Save settings</button></div></form></section>'
          . '<section class="card"><h2>Tests</h2><p class="help">Save your settings first.</p><div class="buttons">'
          . post_button('settings_test', ['what' => 'mailbox'], 'Test mailbox login', 'btn btn-quiet')
          . post_button('settings_test', ['what' => 'email'], 'Send me a test email', 'btn btn-quiet')
          . post_button('settings_test', ['what' => 'calendar'], 'Test calendar access', 'btn btn-quiet')
          . post_button('news_run', [], 'Check mailbox now', 'btn')
          . '</div></section>'
          . '<section class="card"><h2>Cron job</h2><p class="help">In cPanel &gt; Cron Jobs, add this command to run every 10 minutes (Minute: <code>*/10</code>, all other fields <code>*</code>):</p>'
          . '<pre class="code">' . h($cron) . '</pre></section>'
          . '<section class="card"><h2>Instructions for posters</h2><pre class="code">' . h(news_how_to($cfg['mailbox'])) . '</pre></section>';
    render('Settings', $body, $me, 'settings');
}

function action_settings_save(array $me): void
{
    $cfg = settings();
    $ch = [
        'news_enabled' => !empty($_POST['news_enabled']),
        'mailbox'      => strtolower(clean_line((string)($_POST['mailbox'] ?? ''), 200)),
        'board_email'  => clean_line((string)($_POST['board_email'] ?? ''), 200),
        'default_days' => max(1, min(90, (int)($_POST['default_days'] ?? 14))),
        'imap_host'    => clean_line((string)($_POST['imap_host'] ?? $cfg['imap_host']), 200),
        'imap_port'    => (int)($_POST['imap_port'] ?? $cfg['imap_port']) ?: 993,
        'smtp_host'    => clean_line((string)($_POST['smtp_host'] ?? $cfg['smtp_host']), 200),
        'smtp_port'    => (int)($_POST['smtp_port'] ?? $cfg['smtp_port']) ?: 465,
        'calendar_id'  => clean_line((string)($_POST['calendar_id'] ?? ''), 300),
    ];
    if (!filter_var($ch['mailbox'], FILTER_VALIDATE_EMAIL) || !filter_var($ch['board_email'], FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter valid mailbox and board email addresses.');
        redirect('p=settings');
    }
    $pw = (string)($_POST['mail_password'] ?? '');
    if ($pw !== '') {
        $ch['mail_password'] = $pw;
    }
    $key = trim((string)($_POST['google_key'] ?? ''));
    if ($key !== '') {
        $k = json_decode($key, true);
        if (!is_array($k) || empty($k['client_email']) || empty($k['private_key']) || !openssl_pkey_get_private($k['private_key'])) {
            flash('error', 'That does not look like a Google service-account JSON key. Nothing was saved.');
            redirect('p=settings');
        }
        $ch['google_key'] = $key;
    }
    save_settings($ch);
    audit($me['email'], 'updated Settings' . ($pw !== '' ? ' (mailbox password changed)' : '') . ($key !== '' ? ' (Google key changed)' : ''));
    flash('ok', 'Settings saved.');
    redirect('p=settings');
}

function action_settings_test(array $me): void
{
    $cfg = settings();
    $what = (string)($_POST['what'] ?? '');
    if ($what === 'mailbox') {
        if (!function_exists('imap_open')) {
            $err = 'The IMAP extension is missing.';
        } elseif ($cfg['mail_password'] === '') {
            $err = 'No mailbox password saved yet.';
        } else {
            $im = @imap_open('{' . $cfg['imap_host'] . ':' . (int)$cfg['imap_port'] . '/imap/ssl}INBOX', $cfg['mailbox'], $cfg['mail_password'], OP_READONLY, 1);
            $err = $im ? '' : (imap_last_error() ?: 'login failed');
            $count = $im ? imap_num_msg($im) : 0;
            if ($im) {
                imap_close($im);
            }
            imap_errors();
        }
        flash($err === '' ? 'ok' : 'error', $err === '' ? "Mailbox login works. $count message(s) waiting in the inbox." : "Mailbox test failed: $err");
    } elseif ($what === 'email') {
        $err = send_mail($cfg, $me['email'], 'Test email from the Bayswater Common website',
            "This is a test message sent from {$cfg['mailbox']} by the website admin.\n\nIf you received it, outgoing email works.\n");
        flash($err === '' ? 'ok' : 'error', $err === '' ? "Test email sent to {$me['email']}. Check your inbox (and spam folder)." : "Sending failed: $err");
    } elseif ($what === 'calendar') {
        $err = gcal_test($cfg);
        flash($err === '' ? 'ok' : 'error', $err === '' ? 'Google Calendar access works.' : $err);
    }
    redirect('p=settings');
}

function action_news_run(array $me): void
{
    $r = run_news();
    flash($r['ok'] ? 'ok' : 'error', $r['summary'] . ($r['log'] ? ' ' . implode(' | ', $r['log']) : ''));
    redirect('p=settings');
}
