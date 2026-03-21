<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_client.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getExternalBanApiKey(): string {
    $env = loadEnv();
    return trim((string) ($env['EXTERNAL_BAN_API_KEY'] ?? ($env['EXTERNAL_API_KEY'] ?? '')));
}

function getRequestApiKey(): string {
    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($headerKey !== '') {
        return trim((string) $headerKey);
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function requireExternalBanApiAuth(): void {
    $expectedKey = getExternalBanApiKey();
    if ($expectedKey === '') {
        jsonResponse(['error' => 'External ban API key is not configured.'], 500);
    }

    $providedKey = getRequestApiKey();
    if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

function getClientIpAddress(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $parts = array_map('trim', explode(',', (string) $candidate));
        foreach ($parts as $part) {
            if ($part !== '') {
                return $part;
            }
        }
    }

    return 'unknown';
}

try {
    requireExternalBanApiAuth();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method Not Allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['error' => 'Invalid JSON payload'], 400);
    }

    $ip = trim((string) ($input['ip'] ?? $input['value'] ?? ''));
    $duration = trim((string) ($input['duration'] ?? '4h'));
    $reason = trim((string) ($input['reason'] ?? 'external'));
    $type = trim((string) ($input['type'] ?? 'ban'));
    $source = trim((string) ($input['source'] ?? 'external-system'));

    if ($ip === '') {
        jsonResponse(['error' => 'IP address is required'], 400);
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        jsonResponse(['error' => 'Invalid IP address'], 400);
    }

    $allowedTypes = ['ban', 'captcha', 'throttle'];
    if (!in_array($type, $allowedTypes, true)) {
        jsonResponse(['error' => 'Invalid decision type'], 400);
    }

    $api = new CrowdSecAPI();
    $result = $api->addDecision($ip, $type, $duration, $reason);

    auditLog('decision.external_ban', [
        'decision' => [
            'value' => $ip,
            'type' => $type,
            'duration' => $duration,
            'reason' => $reason,
            'source' => $source,
        ],
        'request' => [
            'client_ip' => getClientIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ],
        'result' => $result,
    ], $source);

    jsonResponse([
        'message' => 'Decision added successfully',
        'decision' => [
            'ip' => $ip,
            'type' => $type,
            'duration' => $duration,
            'reason' => $reason,
            'source' => $source,
        ],
        'result' => $result,
    ], 201);
} catch (Exception $e) {
    error_log('External ban API error: ' . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
