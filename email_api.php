<?php
/**
 * email_api.php — Private cPanel Email Operations API
 *
 * PURPOSE
 * - Expose a controlled HTTPS JSON API for e-mail operations from trusted automation.
 *
 * SAFETY CONTROLS
 * - Bearer token auth via Authorization header (required)
 * - Optional source IP allowlist
 * - Optional HMAC request signing
 * - POST + JSON only
 * - Strict action allowlist (no shell command execution)
 *
 * REQUIRED ENV VARS
 * - CPANEL_HOST=your-cpanel-host.com
 * - CPANEL_USER=your-cpanel-username
 * - CPANEL_TOKEN=your_cpanel_api_token   (preferred)
 *   or CPANEL_PASS=your_cpanel_password
 *
 * AUTH TOKEN SOURCE (Authorization: Bearer ...)
 * - EMAIL_API_TOKEN (preferred dedicated API token)
 * - Fallback: CPANEL_TOKEN (compatible with existing .env.example)
 * - Last resort fallback: CPANEL_PASS
 *
 * OPTIONAL ENV VARS
 * - VERIFY_SSL=true|false            (default true)
 * - EMAIL_API_ALLOWED_IPS=1.2.3.4,5.6.7.8
 * - EMAIL_API_HMAC_SECRET=long_secret  (if set, requires X-Timestamp + X-Signature)
 * - EMAIL_API_MAX_BODY_BYTES=1048576   (default 1MB)
 *
 * ACTIONS (POST JSON body)
 * - {"action":"list_emails"}
 * - {"action":"create_email","email":"user@example.com","password":"StrongPass123","quota_mb":1024}
 * - {"action":"change_password","email":"user@example.com","password":"NewStrongPass123"}
 * - {"action":"delete_email","email":"user@example.com"}
 * - {"action":"set_quota","email":"user@example.com","quota_mb":2048}
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

        // Remove matching wrapping quotes
        $len = strlen($v);
        if ($len >= 2) {
            $first = $v[0];
            $last = $v[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }

        // Do not override already-defined environment variables
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
    // Backward-compatible token resolution:
    // 1) EMAIL_API_TOKEN (preferred dedicated API token)
    // 2) CPANEL_TOKEN (fallback so existing .env.example works unchanged)
    // 3) CPANEL_PASS (last-resort fallback when token is unavailable)
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

    // Reject stale requests (>5 min)
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

function parse_email(string $email): array {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['ok' => false, 'error' => 'Invalid email']);
    }

    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        respond(400, ['ok' => false, 'error' => 'Invalid email format']);
    }

    return [$parts[0], $parts[1]];
}

function cpanel_request(string $function, array $params = []): array {
    $host = envv('CPANEL_HOST');
    $user = envv('CPANEL_USER');
    $token = envv('CPANEL_TOKEN');
    $pass = envv('CPANEL_PASS');
    $verifySSL = bool_env('VERIFY_SSL', true);

    if (!$host || !$user || (!$token && !$pass)) {
        respond(500, ['ok' => false, 'error' => 'Server misconfigured: cPanel credentials missing']);
    }

    $url = 'https://' . $host . ':2083/execute/Email/' . rawurlencode($function);
    if ($params) {
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

// --- Request guards ---
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

action:
$action = trim((string)($data['action'] ?? ''));
if ($action === '') {
    respond(400, ['ok' => false, 'error' => 'Missing action']);
}

switch ($action) {
    case 'list_emails': {
        $res = cpanel_request('list_pops');
        if (!$res['ok']) respond(502, $res);

        $items = $res['cpanel']['data'] ?? ($res['cpanel']['result']['data'] ?? []);
        if (!is_array($items)) $items = [];

        $emails = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $email = (string)($it['email'] ?? '');
            if ($email === '' && isset($it['user'], $it['domain'])) {
                $email = $it['user'] . '@' . $it['domain'];
            }
            if ($email !== '') {
                $emails[] = [
                    'email' => $email,
                    'user' => $it['user'] ?? null,
                    'domain' => $it['domain'] ?? null,
                    'suspended_login' => $it['suspended_login'] ?? null,
                    'suspended_incoming' => $it['suspended_incoming'] ?? null,
                    'diskused_mb' => $it['diskusedpercent_float'] ?? ($it['diskused'] ?? null),
                    'quota_mb' => $it['quota'] ?? null,
                ];
            }
        }

        usort($emails, fn($a, $b) => strcasecmp((string)$a['email'], (string)$b['email']));
        respond(200, ['ok' => true, 'count' => count($emails), 'emails' => $emails]);
    }

    case 'create_email': {
        $email = (string)($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $quota = isset($data['quota_mb']) ? (int)$data['quota_mb'] : 0;

        if ($password === '' || strlen($password) < 8) {
            respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters']);
        }

        [$local, $domain] = parse_email($email);
        $res = cpanel_request('add_pop', [
            'email' => $local,
            'domain' => $domain,
            'password' => $password,
            'quota' => $quota,
        ]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Email created', 'email' => $email, 'quota_mb' => $quota]);
    }

    case 'change_password': {
        $email = (string)($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');

        if ($password === '' || strlen($password) < 8) {
            respond(400, ['ok' => false, 'error' => 'Password must be at least 8 characters']);
        }

        [$local, $domain] = parse_email($email);
        $res = cpanel_request('passwd_pop', [
            'email' => $local,
            'domain' => $domain,
            'password' => $password,
        ]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Password updated', 'email' => $email]);
    }

    case 'set_quota': {
        $email = (string)($data['email'] ?? '');
        if (!isset($data['quota_mb'])) {
            respond(400, ['ok' => false, 'error' => 'Missing quota_mb']);
        }
        $quota = (int)$data['quota_mb'];
        if ($quota < 0) {
            respond(400, ['ok' => false, 'error' => 'quota_mb must be >= 0']);
        }

        [$local, $domain] = parse_email($email);
        $res = cpanel_request('edit_pop_quota', [
            'email' => $local,
            'domain' => $domain,
            'quota' => $quota,
        ]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Quota updated', 'email' => $email, 'quota_mb' => $quota]);
    }

    case 'delete_email': {
        $email = (string)($data['email'] ?? '');
        [$local, $domain] = parse_email($email);

        $res = cpanel_request('delete_pop', [
            'email' => $local,
            'domain' => $domain,
        ]);
        if (!$res['ok']) respond(502, $res);

        respond(200, ['ok' => true, 'message' => 'Email deleted', 'email' => $email]);
    }

    default:
        respond(400, ['ok' => false, 'error' => 'Unsupported action']);
}
