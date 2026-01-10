<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_client.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    // GET /api/decisions - list
    if ($method === 'GET') {
        $includeExpired = isset($_GET['include_expired']) && $_GET['include_expired'] === 'true';

        if ($includeExpired) {
            $sql = "
                SELECT 
                    d.id,
                    d.uuid,
                    d.created_at,
                    d.until,
                    d.scenario,
                    d.type,
                    d.value,
                    d.origin,
                    a.source_country,
                    a.source_as_name,
                    a.events_count,
                    a.id as alert_id
                FROM decisions d
                LEFT JOIN alerts a ON d.alert_decisions = a.id
                ORDER BY d.created_at DESC
                LIMIT 10000
            ";
        } else {
            $sql = "
                SELECT 
                    d.id,
                    d.uuid,
                    d.created_at,
                    d.until,
                    d.scenario,
                    d.type,
                    d.value,
                    d.origin,
                    a.source_country,
                    a.source_as_name,
                    a.events_count,
                    a.id as alert_id
                FROM decisions d
                LEFT JOIN alerts a ON d.alert_decisions = a.id
                WHERE d.until > NOW()
                ORDER BY d.created_at DESC
                LIMIT 10000
            ";
        }

        $stmt = $db->query($sql);
        $decisions = $stmt->fetchAll();

        // Format for frontend
        $formatted = [];
        foreach ($decisions as $decision) {
            $expired = strtotime($decision['until']) < time();

            $formatted[] = [
                'id' => $decision['id'],
                'created_at' => $decision['created_at'],
                'scenario' => $decision['scenario'],
                'value' => $decision['value'],
                'expired' => $expired,
                'is_duplicate' => false,
                'detail' => [
                    'origin' => $decision['origin'] ?? 'manual',
                    'type' => $decision['type'],
                    'reason' => $decision['scenario'],
                    'action' => $decision['type'],
                    'country' => $decision['source_country'] ?? 'Unknown',
                    'as' => $decision['source_as_name'] ?? 'Unknown',
                    'events_count' => $decision['events_count'] ?? 0,
                    'expiration' => $decision['until'],
                    'alert_id' => $decision['alert_id']
                ]
            ];
        }

        // Detect duplicates (same IP, keep only oldest)
        $ipMap = [];
        foreach ($formatted as &$decision) {
            if ($decision['expired']) continue;

            $ip = $decision['value'];
            if (!isset($ipMap[$ip])) {
                $ipMap[$ip] = $decision['id'];
            } elseif ($decision['id'] > $ipMap[$ip]) {
                $decision['is_duplicate'] = true;
            }
        }

        jsonResponse($formatted);
    }

    // POST /api/decisions - add
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['ip'])) {
            jsonResponse(['error' => 'IP address is required'], 400);
        }

        $ip = $input['ip'];
        $duration = $input['duration'] ?? '4h';
        $reason = $input['reason'] ?? 'manual';
        $type = $input['type'] ?? 'ban';

        $api = new CrowdSecAPI();
        $result = $api->addDecision($ip, $type, $duration, $reason);
        auditLog('decision.add', ['ip' => $ip, 'duration' => $duration, 'reason' => $reason, 'type' => $type]);

        jsonResponse(['message' => 'Decision added successfully', 'result' => $result]);
    }

    // DELETE /api/decisions/:id - delete
    elseif ($method === 'DELETE' && (isset($_GET['id']) || preg_match('#/api/decisions/(\d+)#', $uri, $matches))) {
        $id = $_GET['id'] ?? $matches[1];

        $api = new CrowdSecAPI();
        $api->deleteDecision($id);
        auditLog('decision.delete', ['id' => $id]);

        jsonResponse(['message' => 'Decision deleted successfully']);
    }

    else {
        jsonResponse(['error' => 'Not Found'], 404);
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
