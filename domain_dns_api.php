<?php
/**
 * domain_dns_api.php — Private cPanel Domains/DNS Operations API
 *
 * PURPOSE
 * - Expose controlled HTTPS JSON API for domains, subdomains, aliases, and DNS records.
 *
 * AUTH
 * - Authorization: Bearer <token>
 * - Token resolution order:
 *   1) EMAIL_API_TOKEN (preferred dedicated API token)
 *   2) CPANEL_TOKEN
 *   3) CPANEL_PASS
 *
 * CONFIG SOURCE
 * - Reads the same .env file/variable names used by existing scripts.
 *
 * REQUIRED ENV VARS
 * - CPANEL_HOST
 * - CPANEL_USER
 * - CPANEL_TOKEN or CPANEL_PASS
 *
 * OPTIONAL ENV VARS
 * - VERIFY_SSL=true|false (default true)
 * - EMAIL_API_ALLOWED_IPS=1.2.3.4,5.6.7.8
 * - EMAIL_API_HMAC_SECRET=long_secret  (if set, requires X-Timestamp + X-Signature)
 * - EMAIL_API_MAX_BODY_BYTES=1048576
 *
 * ACTIONS (POST JSON)
 * - {"action":"list_domains"}
 * - {"action":"create_subdomain","subdomain":"blog","rootdomain":"example.com","dir":"public_html/blog"}
 * - {"action":"delete_subdomain","subdomain":"blog","rootdomain":"example.com"}
 * - {"action":"create_alias","domain":"example.net"}
 * - {"action":"delete_alias","domain":"example.net"}
 * - {"action":"list_dns_records","domain":"example.com"}
 * - {"action":"add_dns_record","domain":"example.com","record":{"name":"test.example.com.","type":"A","address":"1.2.3.4","ttl":300}}
 * - {"action":"edit_dns_record","domain":"example.com","line":123,"record":{"ttl":600,"address":"5.6.7.8"}}
 * - {"action":"delete_dns_record","domain":"example.com","line":123}
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;

        $len = strlen($v);
        if ($len >= 2) {
            $first = $v[0];
            $last = $v[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        if (getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

load_env_file(__DIR__ . '/.env');

function envv(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === null || $v === '') {
        $v = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
    }
    if ($v === false || $v === null || $v === '') return $default;
    return (string)$v;
}

function bool_env(string $key, bool $default): bool {
    $v = envv($key, null);
    if ($v === null) return $default;
    return in_array(strtolower(trim($v)), ['1','true','yes','on'], true);
}

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function get_header_value(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function require_bearer_token(): void {
    $expected = envv('EMAIL_API_TOKEN');
    if (!$expected) $expected = envv('CPANEL_TOKEN');
    if (!$expected) $expected = envv('CPANEL_PASS');

    if (!$expected) {
        respond(500, ['ok' => false, 'error' => 'Server misconfigured: missing EMAIL_API_TOKEN/CPANEL_TOKEN/CPANEL_PASS for auth']);
    }

    $auth = get_header_value('Authorization') ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        respond(401, ['ok' => false, 'error' => 'Missing or invalid Authorization header']);
    }

    $provided = trim($m[1]);
    if (!hash_equals($expected, $provided)) {
        respond(403, ['ok' => false, 'error' => 'Invalid token']);
    }
}

function enforce_ip_allowlist_if_configured(): void {
    $raw = envv('EMAIL_API_ALLOWED_IPS', '');
    if ($raw === '') return;

    $allowed = array_filter(array_map('trim', explode(',', $raw)));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || !in_array($ip, $allowed, true)) {
        respond(403, ['ok' => false, 'error' => 'Source IP not allowed']);
    }
}

function enforce_hmac_if_configured(string $rawBody): void {
    $secret = envv('EMAIL_API_HMAC_SECRET', '');
    if ($secret === '') return;

    $ts = get_header_value('X-Timestamp') ?? '';
    $sig = get_header_value('X-Signature') ?? '';
    if ($ts === '' || $sig === '') {
        respond(401, ['ok' => false, 'error' => 'Missing X-Timestamp/X-Signature']);
    }

    if (!ctype_digit($ts)) {
        respond(401, ['ok' => false, 'error' => 'Invalid X-Timestamp']);
    }

    $now = time();
    $reqTs = (int)$ts;
    if (abs($now - $reqTs) > 300) {
        respond(401, ['ok' => false, 'error' => 'Stale request timestamp']);
    }

    $expected = hash_hmac('sha256', $ts . "\n" . $rawBody, $secret);
    if (!hash_equals($expected, $sig)) {
        respond(403, ['ok' => false, 'error' => 'Invalid signature']);
    }
}

function require_domain(array $data): string {
    $domain = trim((string)($data['domain'] ?? ''));
    if ($domain === '') respond(400, ['ok' => false, 'error' => 'Missing domain']);
    return $domain;
}

function cpanel_request(string $module, string $function, array $params = []): array {
    $host = envv('CPANEL_HOST');
    $user = envv('CPANEL_USER');
    $token = envv('CPANEL_TOKEN');
    $pass = envv('CPANEL_PASS');
    $verifySSL = bool_env('VERIFY_SSL', true);

    if (!$host || !$user || (!$token && !$pass)) {
        respond(500, ['ok' => false, 'error' => 'Server misconfigured: cPanel credentials missing']);
    }

    $url = 'https://' . $host . ':2083/execute/' . rawurlencode($module) . '/' . rawurlencode($function);
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json',
            $token ? ('Authorization: cpanel ' . $user . ':' . $token) : null,
        ]),
    ]);

    if (!$token) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL error: ' . $err];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Non-JSON response from cPanel', 'raw' => substr($body, 0, 500)];
    }

    if (($json['status'] ?? null) !== 1) {
        $msg = 'cPanel API call failed';
        if (!empty($json['errors']) && is_array($json['errors'])) {
            $msg = implode('; ', array_map('strval', $json['errors']));
        } elseif (!empty($json['messages']) && is_array($json['messages'])) {
            $msg = implode('; ', array_map('strval', $json['messages']));
        }
        return ['ok' => false, 'status' => $status, 'error' => $msg, 'cpanel' => $json];
    }

    return ['ok' => true, 'status' => $status, 'cpanel' => $json];
}

function cpanel_api2_request(string $module, string $function, array $params = []): array {
    $host = envv('CPANEL_HOST');
    $user = envv('CPANEL_USER');
    $token = envv('CPANEL_TOKEN');
    $pass = envv('CPANEL_PASS');
    $verifySSL = bool_env('VERIFY_SSL', true);

    if (!$host || !$user || (!$token && !$pass)) {
        respond(500, ['ok' => false, 'error' => 'Server misconfigured: cPanel credentials missing']);
    }

    $query = [
        'cpanel_jsonapi_user' => $user,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module' => $module,
        'cpanel_jsonapi_func' => $function,
    ] + $params;

    $url = 'https://' . $host . ':2083/json-api/cpanel?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json',
            $token ? ('Authorization: cpanel ' . $user . ':' . $token) : null,
        ]),
    ]);

    if (!$token) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL error: ' . $err];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Non-JSON response from cPanel API2', 'raw' => substr($body, 0, 500)];
    }

    $result = $json['cpanelresult'] ?? null;
    if (!is_array($result)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Malformed API2 response', 'cpanel' => $json];
    }

    $event = $result['event'] ?? [];
    $ok = (int)($event['result'] ?? 0) === 1;
    if (!$ok) {
        $msg = (string)($event['reason'] ?? 'cPanel API2 call failed');
        return ['ok' => false, 'status' => $status, 'error' => $msg, 'cpanel' => $json];
    }

    return ['ok' => true, 'status' => $status, 'cpanel' => $json];
}

function pick_data(array $res) {
    return $res['cpanel']['data'] ?? ($res['cpanel']['result']['data'] ?? null);
}

function pick_api2_data(array $res) {
    return $res['cpanel']['cpanelresult']['data'] ?? null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed. Use POST']);
}

$maxBytes = (int)(envv('EMAIL_API_MAX_BODY_BYTES', '1048576') ?? '1048576');
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > $maxBytes) {
    respond(413, ['ok' => false, 'error' => 'Payload too large']);
}

require_bearer_token();
enforce_ip_allowlist_if_configured();
enforce_hmac_if_configured($raw);

$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'error' => 'Invalid JSON body']);
}

$action = trim((string)($data['action'] ?? ''));
if ($action === '') {
    respond(400, ['ok' => false, 'error' => 'Missing action']);
}

switch ($action) {
    case 'list_domains': {
        $res = cpanel_request('DomainInfo', 'list_domains');
        if (!$res['ok']) respond(502, $res);

        $d = pick_data($res);
        respond(200, [
            'ok' => true,
            'domains' => [
                'main_domain' => $d['main_domain'] ?? null,
                'addon_domains' => $d['addon_domains'] ?? [],
                'sub_domains' => $d['sub_domains'] ?? [],
                'parked_domains' => $d['parked_domains'] ?? [],
            ],
            'raw' => $d,
        ]);
    }

    case 'create_subdomain': {
        $sub = trim((string)($data['subdomain'] ?? ''));
        $root = trim((string)($data['rootdomain'] ?? ''));
        $dir = trim((string)($data['dir'] ?? ''));

        if ($sub === '' || $root === '') {
            respond(400, ['ok' => false, 'error' => 'Missing subdomain or rootdomain']);
        }

        $params = ['domain' => $sub, 'rootdomain' => $root];
        if ($dir !== '') $params['dir'] = $dir;

        $res = cpanel_request('SubDomain', 'addsubdomain', $params);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Subdomain created', 'subdomain' => $sub . '.' . $root]);
    }

    case 'delete_subdomain': {
        $sub = trim((string)($data['subdomain'] ?? ''));
        $root = trim((string)($data['rootdomain'] ?? ''));
        $full = trim((string)($data['full_domain'] ?? ''));

        if ($full === '') {
            if ($sub === '' || $root === '') {
                respond(400, ['ok' => false, 'error' => 'Provide full_domain OR (subdomain + rootdomain)']);
            }
            $full = $sub . '.' . $root;
        }

        $res = cpanel_request('SubDomain', 'delsubdomain', ['domain' => $full]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Subdomain deleted', 'subdomain' => $full]);
    }

    case 'create_alias': {
        $domain = require_domain($data);
        $res = cpanel_request('Park', 'add_park', ['domain' => $domain]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Alias created', 'domain' => $domain]);
    }

    case 'delete_alias': {
        $domain = require_domain($data);
        $res = cpanel_request('Park', 'remove_park', ['domain' => $domain]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Alias removed', 'domain' => $domain]);
    }

    case 'list_dns_records': {
        $domain = require_domain($data);
        $res = cpanel_request('ZoneEdit', 'fetchzone_records', ['domain' => $domain]);

        if (!$res['ok']) {
            // Fallback for environments where UAPI ZoneEdit module is unavailable
            $res2 = cpanel_api2_request('ZoneEdit', 'fetchzone_records', ['domain' => $domain]);
            if (!$res2['ok']) respond(502, ['ok' => false, 'uapi_error' => $res, 'api2_error' => $res2]);

            $records = pick_api2_data($res2);
            if (!is_array($records)) $records = [];
            respond(200, ['ok' => true, 'domain' => $domain, 'count' => count($records), 'records' => $records, 'source' => 'api2']);
        }

        $records = pick_data($res);
        if (!is_array($records)) $records = [];

        respond(200, ['ok' => true, 'domain' => $domain, 'count' => count($records), 'records' => $records, 'source' => 'uapi']);
    }

    case 'add_dns_record': {
        $domain = require_domain($data);
        $record = $data['record'] ?? null;
        if (!is_array($record) || empty($record['type'])) {
            respond(400, ['ok' => false, 'error' => 'record object with at least type is required']);
        }

        $params = ['domain' => $domain] + $record;
        $res = cpanel_request('ZoneEdit', 'add_zone_record', $params);
        if (!$res['ok']) {
            $res2 = cpanel_api2_request('ZoneEdit', 'add_zone_record', $params);
            if (!$res2['ok']) respond(502, ['ok' => false, 'uapi_error' => $res, 'api2_error' => $res2]);
            respond(200, ['ok' => true, 'message' => 'DNS record added', 'domain' => $domain, 'result' => pick_api2_data($res2), 'source' => 'api2']);
        }

        respond(200, ['ok' => true, 'message' => 'DNS record added', 'domain' => $domain, 'result' => pick_data($res), 'source' => 'uapi']);
    }

    case 'edit_dns_record': {
        $domain = require_domain($data);
        $line = isset($data['line']) ? (int)$data['line'] : 0;
        $record = $data['record'] ?? null;

        if ($line <= 0) respond(400, ['ok' => false, 'error' => 'line must be > 0']);
        if (!is_array($record) || empty($record)) respond(400, ['ok' => false, 'error' => 'record object is required']);

        $params = ['domain' => $domain, 'line' => $line] + $record;
        $res = cpanel_request('ZoneEdit', 'edit_zone_record', $params);
        if (!$res['ok']) {
            $res2 = cpanel_api2_request('ZoneEdit', 'edit_zone_record', $params);
            if (!$res2['ok']) respond(502, ['ok' => false, 'uapi_error' => $res, 'api2_error' => $res2]);
            respond(200, ['ok' => true, 'message' => 'DNS record updated', 'domain' => $domain, 'line' => $line, 'result' => pick_api2_data($res2), 'source' => 'api2']);
        }

        respond(200, ['ok' => true, 'message' => 'DNS record updated', 'domain' => $domain, 'line' => $line, 'result' => pick_data($res), 'source' => 'uapi']);
    }

    case 'delete_dns_record': {
        $domain = require_domain($data);
        $line = isset($data['line']) ? (int)$data['line'] : 0;
        if ($line <= 0) respond(400, ['ok' => false, 'error' => 'line must be > 0']);

        $params = ['domain' => $domain, 'line' => $line];
        $res = cpanel_request('ZoneEdit', 'remove_zone_record', $params);
        if (!$res['ok']) {
            $res2 = cpanel_api2_request('ZoneEdit', 'remove_zone_record', $params);
            if (!$res2['ok']) respond(502, ['ok' => false, 'uapi_error' => $res, 'api2_error' => $res2]);
            respond(200, ['ok' => true, 'message' => 'DNS record deleted', 'domain' => $domain, 'line' => $line, 'source' => 'api2']);
        }

        respond(200, ['ok' => true, 'message' => 'DNS record deleted', 'domain' => $domain, 'line' => $line, 'source' => 'uapi']);
    }

    default:
        respond(400, ['ok' => false, 'error' => 'Unsupported action']);
}
