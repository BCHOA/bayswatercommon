<?php
/**
 * Bayswater Common HOA admin: add events to the HOA's Google Calendar using a Google
 * "service account" (a robot account the calendar is shared with). No user login is involved.
 */
declare(strict_types=1);

defined('BCHOA') or exit;

const GCAL_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Make an HTTPS request. Returns [status, body]. */
function http_request(string $method, string $url, array $headers, string $body = ''): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $resp === false ? $err : (string)$resp];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 30, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return [$code, (string)$resp];
}

/** Get a short-lived access token for the service account. Returns [token, error]. */
function gcal_token(array $cfg): array
{
    $key = json_decode((string)$cfg['google_key'], true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        return ['', 'Google Calendar is not set up yet (no service-account key in Settings).'];
    }
    $now = time();
    $jwt = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64url(json_encode([
        'iss' => $key['client_email'], 'scope' => GCAL_SCOPE,
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3000,
    ]));
    if (!openssl_sign($jwt, $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        return ['', 'The Google service-account key could not be used. Please paste it again in Settings.'];
    }
    [$code, $body] = http_request('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . b64url($sig)]));
    $j = json_decode($body, true);
    if ($code !== 200 || empty($j['access_token'])) {
        return ['', 'Google refused the service account (' . ($j['error_description'] ?? $j['error'] ?? "HTTP $code") . ').'];
    }
    return [$j['access_token'], ''];
}

/**
 * Add an event. $ev: title, description, location, date (Y-m-d), end_date (Y-m-d, optional),
 * start_time / end_time ('H:i', optional; none = all-day). Returns [htmlLink, error].
 */
function gcal_add_event(array $cfg, array $ev): array
{
    [$token, $err] = gcal_token($cfg);
    if ($err !== '') {
        return ['', $err];
    }
    $tz = 'America/New_York';
    $endDate = $ev['end_date'] ?: $ev['date'];
    if ($ev['start_time'] === '') {
        $start = ['date' => $ev['date']];
        $end = ['date' => date('Y-m-d', strtotime($endDate . ' +1 day'))];   // all-day end is exclusive
    } else {
        $endTime = $ev['end_time'] ?: date('H:i', strtotime($ev['start_time'] . ' +1 hour'));
        $start = ['dateTime' => $ev['date'] . 'T' . $ev['start_time'] . ':00', 'timeZone' => $tz];
        $end = ['dateTime' => $endDate . 'T' . $endTime . ':00', 'timeZone' => $tz];
    }
    $payload = ['summary' => $ev['title'], 'start' => $start, 'end' => $end];
    if ($ev['description'] !== '') {
        $payload['description'] = $ev['description'];
    }
    if ($ev['location'] !== '') {
        $payload['location'] = $ev['location'];
    }
    [$code, $body] = http_request('POST',
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cfg['calendar_id']) . '/events',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode($payload));
    $j = json_decode($body, true);
    if ($code < 200 || $code > 299) {
        $msg = $j['error']['message'] ?? "HTTP $code";
        if ($code === 403 || $code === 404) {
            $msg .= ' (Is the calendar shared with ' . google_client_email($cfg) . ' with "Make changes to events"?)';
        }
        return ['', 'Google Calendar did not accept the event: ' . $msg];
    }
    return [(string)($j['htmlLink'] ?? ''), ''];
}

/** Check that the service account can read the calendar. Returns '' or an error. */
function gcal_test(array $cfg): string
{
    [$token, $err] = gcal_token($cfg);
    if ($err !== '') {
        return $err;
    }
    [$code, $body] = http_request('GET',
        'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cfg['calendar_id']) . '/events?maxResults=1',
        ['Authorization: Bearer ' . $token]);
    if ($code !== 200) {
        $j = json_decode($body, true);
        return 'Google Calendar: ' . ($j['error']['message'] ?? "HTTP $code")
             . ' (Is the calendar shared with ' . google_client_email($cfg) . ' with "Make changes to events"?)';
    }
    return '';
}
