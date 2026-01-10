<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_client.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$env = loadEnv();
$lookbackMs = parseLookbackToMs($env['LOOKBACK_PERIOD'] ?? '7d');
$since = date('Y-m-d H:i:s', (time() * 1000 - $lookbackMs) / 1000);

try {
    $db = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    // GET /api/alerts?id=:id - detail
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = $_GET['id'];

        $api = new CrowdSecAPI();
        $alertData = $api->getAlertById($id);

        jsonResponse($alertData);
    }

    // GET /api/alerts - list
    elseif ($method === 'GET' && strpos($uri, '/api/alerts/') === false) {
        $stmt = $db->prepare("
            SELECT 
                a.id,
                a.uuid,
                a.created_at,
                a.scenario,
                a.message,
                a.events_count,
                a.source_ip,
                a.source_country,
                a.source_as_name,
                a.source_as_number,
                a.simulated,
                m.machine_id as machine_id,
                COUNT(d.id) as decisions_count
            FROM alerts a
            LEFT JOIN decisions d ON d.alert_decisions = a.id
            LEFT JOIN machines m ON m.id = a.machine_alerts
            WHERE a.created_at >= ?
            GROUP BY a.id
            ORDER BY a.created_at DESC
            LIMIT 10000
        ");

        $stmt->execute([$since]);
        $alerts = $stmt->fetchAll();

        // Enrich with decisions
        foreach ($alerts as &$alert) {
            $stmt = $db->prepare("
                SELECT 
                    d.id,
                    d.type,
                    d.value,
                    d.until,
                    d.origin,
                    d.scenario
                FROM decisions d
                WHERE d.alert_decisions = ?
            ");
            $stmt->execute([$alert['id']]);
            $alert['decisions'] = $stmt->fetchAll();

            // Check if decisions are expired
            foreach ($alert['decisions'] as &$decision) {
                $decision['expired'] = strtotime($decision['until']) < time();
            }
        }

        jsonResponse($alerts);
    }

    // GET /api/alerts/:id - detail
    elseif ($method === 'GET' && preg_match('#/api/alerts/(\d+)#', $uri, $matches)) {
        $id = $matches[1];

        $api = new CrowdSecAPI();
        $alertData = $api->getAlertById($id);

        jsonResponse($alertData);
    }

    // DELETE /api/alerts/:id - delete
    elseif ($method === 'DELETE' && (isset($_GET['id']) || preg_match('#/api/alerts/(\d+)#', $uri, $matches))) {
        $id = $_GET['id'] ?? $matches[1];

        $api = new CrowdSecAPI();
        $api->deleteAlert($id);

        // Delete from local database
        $stmt = $db->prepare("DELETE FROM alerts WHERE id = ?");
        $stmt->execute([$id]);

        $stmt = $db->prepare("DELETE FROM decisions WHERE alert_decisions = ?");
        $stmt->execute([$id]);

        auditLog('alert.delete', ['id' => $id]);

        jsonResponse(['message' => 'Alert deleted successfully']);
    }

    else {
        jsonResponse(['error' => 'Not Found'], 404);
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
