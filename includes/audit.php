<?php
function auditLog($action, $details = [], $userOverride = null) {
    $entry = [
        'timestamp' => date('c'),
        'user' => $userOverride ?? (function_exists('getCurrentUser') ? (getCurrentUser()['username'] ?? 'system') : 'system'),
        'action' => $action,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli'
    ];

    $logFile = __DIR__ . '/../storage/audit.log';
    $dir = dirname($logFile);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($logFile, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function readAuditLog($limit = 200) {
    $logFile = __DIR__ . '/../storage/audit.log';
    if (!file_exists($logFile)) {
        return [];
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -$limit);

    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if ($decoded) {
            $entries[] = $decoded;
        }
    }

    return array_reverse($entries);
}
