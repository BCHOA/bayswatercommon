<?php
/**
 * Bayswater Common HOA admin: send plain-text email through the HOA's GoDaddy mailbox (SMTP with login),
 * so messages are signed by GoDaddy and pass the domain's strict DMARC policy.
 */
declare(strict_types=1);

defined('BCHOA') or exit;

/**
 * Send one email from the news@ mailbox. Returns '' on success, or an error message.
 * $opts: reply_to, in_reply_to, auto (true = automatic reply, adds Auto-Submitted).
 */
function send_mail(array $cfg, string $to, string $subject, string $text, array $opts = []): string
{
    // Preferred: send through the Google Apps Script (runs in the HOA Gmail account). GoDaddy's shared
    // hosting blocks connections to outside mail servers, so direct SMTP usually can't work from there.
    if ((string)$cfg['gas_url'] !== '') {
        [, $err] = gas_call($cfg, ['action' => 'mail', 'mail' => [
            'to' => $to, 'subject' => $subject, 'body' => $text, 'reply_to' => (string)($opts['reply_to'] ?? ''),
        ]]);
        return $err;
    }
    if ($cfg['mail_password'] === '') {
        return 'The news@ mailbox password has not been entered in Settings.';
    }
    $from = $cfg['mailbox'];
    $scheme = $cfg['smtp_secure'] === 'none' ? 'tcp' : 'ssl';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("$scheme://{$cfg['smtp_host']}:{$cfg['smtp_port']}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return "Could not connect to the mail server ({$cfg['smtp_host']}:{$cfg['smtp_port']}): $errstr";
    }
    stream_set_timeout($fp, 30);

    $read = function () use ($fp): string {
        $all = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $all;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        if ($c !== '') {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        return in_array((int)substr($r, 0, 3), $ok, true) ? '' : trim($r ?: 'no answer');
    };

    $headers = [
        'From: ' . mime_header('Bayswater Common HOA') . " <$from>",
        "To: <$to>",
        'Subject: ' . mime_header($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@bayswatercommon.org>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-BCHOA-Generated: yes',
    ];
    if (!empty($opts['reply_to'])) {
        $headers[] = 'Reply-To: <' . $opts['reply_to'] . '>';
    }
    if (!empty($opts['in_reply_to'])) {
        $headers[] = 'In-Reply-To: ' . $opts['in_reply_to'];
        $headers[] = 'References: ' . $opts['in_reply_to'];
    }
    if (!empty($opts['auto'])) {
        $headers[] = 'Auto-Submitted: auto-replied';
    }
    $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n")) . "\r\n";

    $steps = [
        ['', [220]],
        ['EHLO admin.bayswatercommon.org', [250]],
        ['AUTH LOGIN', [334]],
        [base64_encode($from), [334]],
        [base64_encode($cfg['mail_password']), [235]],
        ["MAIL FROM:<$from>", [250]],
        ["RCPT TO:<$to>", [250, 251]],
        ['DATA', [354]],
        [$data . '.', [250]],
    ];
    foreach ($steps as $i => [$c, $ok]) {
        $err = $cmd($c, $ok);
        if ($err !== '') {
            fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return $i === 4 ? 'The mail server did not accept the news@ password.' : "The mail server refused the message: $err";
        }
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return '';
}

function mime_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
