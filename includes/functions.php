<?php
function parseLookbackToMs($lookbackPeriod) {
    if (preg_match('/^(\d+)([hmd])$/', $lookbackPeriod, $match)) {
        $val = (int)$match[1];
        $unit = $match[2];
        
        switch ($unit) {
            case 'h': return $val * 60 * 60 * 1000;
            case 'd': return $val * 24 * 60 * 60 * 1000;
            case 'm': return $val * 60 * 1000;
        }
    }
    
    return 7 * 24 * 60 * 60 * 1000; // Default 7 days
}

function toDuration($timestampMs) {
    $now = time() * 1000;
    $diffMs = $now - $timestampMs;
    
    $hours = floor($diffMs / 3600000);
    $minutes = floor(($diffMs % 3600000) / 60000);
    $seconds = floor(($diffMs % 60000) / 1000);
    
    return "{$hours}h{$minutes}m{$seconds}s";
}

function parseGoDuration($str) {
    if (!$str) return 0;
    
    $totalMs = 0;
    if (preg_match_all('/(\d+)(h|m|s)/', $str, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $val = (int)$match[1];
            $unit = $match[2];
            
            switch ($unit) {
                case 'h': $totalMs += $val * 3600000; break;
                case 'm': $totalMs += $val * 60000; break;
                case 's': $totalMs += $val * 1000; break;
            }
        }
    }
    
    return $totalMs;
}

function formatDateTime($value, $fallback = '-') {
    if (!$value) {
        return $fallback;
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$timestamp) {
        return $fallback;
    }

    return date('d.m.Y H:i', $timestamp);
}

function formatTime($value, $fallback = '-') {
    if (!$value) {
        return $fallback;
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$timestamp) {
        return $fallback;
    }

    return date('H:i', $timestamp);
}

function formatAlertDuration($startedAt, $stoppedAt) {
    if (!$startedAt) {
        return '-';
    }

    $startLabel = formatTime($startedAt);
    if ($startLabel === '-') {
        return '-';
    }

    if (!$stoppedAt) {
        return "start: {$startLabel} trvání -";
    }

    $start = strtotime((string) $startedAt);
    $stop = strtotime((string) $stoppedAt);
    if (!$start || !$stop || $stop < $start) {
        return "start: {$startLabel} trvání -";
    }

    $minutes = (int) round(($stop - $start) / 60);
    if ($minutes <= 0) {
        $minutes = 1;
    }

    if ($minutes >= 60) {
        $hours = (int) max(1, round($minutes / 60));
        return "start: {$startLabel} trvání {$hours} hod";
    }

    return "start: {$startLabel} trvání {$minutes} min";
}

function buildPaginationPages($current, $total) {
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1];
    $start = max(2, $current - 2);
    $end = min($total - 1, $current + 2);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($end < $total - 1) {
        $pages[] = '...';
    }

    $pages[] = $total;
    return $pages;
}

function setFlashMessage($type, $message) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $message;
}

function getAlertTarget($alert) {
    if (!$alert) return "Unknown";
    
    // Try to find target in events
    if (isset($alert['events']) && is_array($alert['events'])) {
        foreach ($alert['events'] as $event) {
            if (isset($event['meta']) && is_array($event['meta'])) {
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'target_fqdn' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
                
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'target_host' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
                
                foreach ($event['meta'] as $meta) {
                    if ($meta['key'] === 'service' && !empty($meta['value'])) {
                        return $meta['value'];
                    }
                }
            }
        }
    }
    
    return $alert['machine_alias'] ?? $alert['machine_id'] ?? "Unknown";
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function loadEnv() {
    $env = [];
    $envFile = __DIR__ . '/../.env';
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $env[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
    
    return $env;
}

$appEnv = loadEnv();
date_default_timezone_set($appEnv['TIMEZONE'] ?? 'Europe/Prague');
