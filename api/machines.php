<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT
            m.id,
            m.machine_id,
            m.ip_address,
            m.status,
            m.last_heartbeat,
            m.last_push,
            m.is_validated,
            m.version,
            COUNT(DISTINCT a.id) as alerts_count,
            COUNT(DISTINCT d.id) as decisions_count
        FROM machines m
        LEFT JOIN alerts a ON a.machine_alerts = m.id
        LEFT JOIN decisions d ON d.alert_decisions = a.id
        GROUP BY m.id
        ORDER BY m.machine_id ASC
    ");
    $stmt->execute();

    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    error_log("Machines API Error: " . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
