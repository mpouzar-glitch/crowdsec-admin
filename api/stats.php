<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$env = loadEnv();
$lookbackMs = parseLookbackToMs($env['LOOKBACK_PERIOD'] ?? '7d');
$since = date('Y-m-d H:i:s', (time() * 1000 - $lookbackMs) / 1000);

try {
    $db = Database::getInstance()->getConnection();
    $stats = [];

    // Total alerts
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM alerts WHERE created_at >= ?");
    $stmt->execute([$since]);
    $stats['total_alerts'] = $stmt->fetchColumn();

    // Active decisions
    $stmt = $db->query("SELECT COUNT(*) as count FROM decisions WHERE until > NOW()");
    $stats['active_decisions'] = $stmt->fetchColumn();

    // Top scenarios
    $stmt = $db->prepare("
        SELECT scenario, COUNT(*) as count 
        FROM alerts 
        WHERE created_at >= ?
        GROUP BY scenario 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stmt->execute([$since]);
    $stats['top_scenarios'] = $stmt->fetchAll();

    // Top countries
    $stmt = $db->prepare("
        SELECT source_country as country, COUNT(*) as count 
        FROM alerts 
        WHERE created_at >= ? AND source_country IS NOT NULL
        GROUP BY source_country 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stmt->execute([$since]);
    $stats['top_countries'] = $stmt->fetchAll();

    // Top IPs
    $stmt = $db->prepare("
        SELECT source_ip as ip, COUNT(*) as count 
        FROM alerts 
        WHERE created_at >= ? AND source_ip IS NOT NULL
        GROUP BY source_ip 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stmt->execute([$since]);
    $stats['top_ips'] = $stmt->fetchAll();

    // Alerts timeline (last 24h by hour)
    $stmt = $db->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
            COUNT(*) as count
        FROM alerts
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY hour
        ORDER BY hour
    ");
    $stats['timeline_24h'] = $stmt->fetchAll();

    jsonResponse($stats);

} catch (Exception $e) {
    error_log("Stats API Error: " . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
