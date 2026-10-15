<?php
/**
 * Cron job: check the news@ mailbox and post announcements / calendar events.
 * cPanel > Cron Jobs, every 10 minutes:
 *   /usr/local/bin/php /home/s98n5mbj5tcl/public_html/admin.bayswatercommon.org/inc/cron-news.php >/dev/null 2>&1
 * Results show on the dashboard's Settings page and in Activity.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BCHOA = true;
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/gcal.php';
require __DIR__ . '/inbound.php';

$r = run_news();
echo $r['summary'], "\n";
foreach ($r['log'] as $line) {
    echo ' - ', $line, "\n";
}
exit($r['ok'] ? 0 : 1);
