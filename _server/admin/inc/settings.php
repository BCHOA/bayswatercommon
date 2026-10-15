<?php
/**
 * Bayswater Common HOA admin: site settings for email posting (news@) and Google Calendar.
 * Stored in bchoa-data/settings.json (outside the web root). Secrets are never shown again once saved.
 */
declare(strict_types=1);

defined('BCHOA') or exit;

const SETTINGS_DEFAULT = [
    'news_enabled'  => false,
    'imap_host'     => 'imap.secureserver.net',
    'imap_port'     => 993,
    'mailbox'       => 'news@bayswatercommon.org',
    'mail_password' => '',
    'smtp_host'     => 'smtpout.secureserver.net',
    'smtp_port'     => 465,
    'smtp_secure'   => 'ssl',            // 'ssl' (port 465) or 'none' (local testing only)
    'board_email'   => 'HOABoard@BayswaterCommon.org',
    'calendar_id'   => 'b8aed431852b1d617f9ed0cba642cc9ac6ceadeccc63cb632f44943e90e2362f@group.calendar.google.com',
    'google_key'    => '',               // service-account JSON key (alternative to the Apps Script bridge)
    'gas_url'       => '',               // Google Apps Script web app URL (preferred; no Google Cloud needed)
    'gas_secret'    => '',               // shared code that the script checks
    'default_days'  => 14,               // how long an emailed announcement shows if no end date is given
];

function settings(): array
{
    return array_merge(SETTINGS_DEFAULT, store_read('settings', []));
}

function save_settings(array $changes): void
{
    store_update('settings', [], function (array &$d) use ($changes) {
        $d = array_merge($d, $changes);
        return true;
    });
}

/** The service account's email address, or '' if no valid key is saved. */
function google_client_email(array $cfg): string
{
    $k = json_decode((string)$cfg['google_key'], true);
    return is_array($k) && !empty($k['client_email']) && !empty($k['private_key']) ? (string)$k['client_email'] : '';
}

function news_status(): array
{
    return store_read('news-status', ['last_run' => '', 'last_result' => '', 'last_error' => '']);
}

function save_news_status(array $s): void
{
    store_update('news-status', [], function (array &$d) use ($s) {
        $d = array_merge($d, $s);
        return true;
    });
}
