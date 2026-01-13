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
$repeatedWindowSeconds = 5 * 60;

function buildAlertFilters($since) {
    $conditions = ['a.created_at >= ?'];
    $params = [$since];

    $scenario = strtolower(trim($_GET['scenario'] ?? ''));
    if ($scenario !== '') {
        $conditions[] = 'LOWER(a.scenario) LIKE ?';
        $params[] = '%' . $scenario . '%';
    }

    $ip = strtolower(trim($_GET['ip'] ?? ''));
    if ($ip !== '') {
        $conditions[] = 'LOWER(a.source_ip) LIKE ?';
        $params[] = '%' . $ip . '%';
    }

    $machine = strtolower(trim($_GET['machine'] ?? ''));
    if ($machine !== '') {
        $conditions[] = 'LOWER(m.machine_id) LIKE ?';
        $params[] = '%' . $machine . '%';
    }

    $country = strtolower(trim($_GET['country'] ?? ''));
    if ($country !== '') {
        $conditions[] = 'LOWER(a.source_country) LIKE ?';
        $params[] = '%' . $country . '%';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $conditions);

    return [$whereSql, $params];
}

function getAlertFilterOptions($db) {
    $scenarios = $db->query("
        SELECT DISTINCT scenario
        FROM alerts
        WHERE scenario IS NOT NULL AND scenario != ''
        ORDER BY scenario
    ")->fetchAll(PDO::FETCH_COLUMN);

    $ips = $db->query("
        SELECT DISTINCT source_ip
        FROM alerts
        WHERE source_ip IS NOT NULL AND source_ip != ''
        ORDER BY source_ip
    ")->fetchAll(PDO::FETCH_COLUMN);

    $countries = $db->query("
        SELECT DISTINCT source_country
        FROM alerts
        WHERE source_country IS NOT NULL AND source_country != ''
        ORDER BY source_country
    ")->fetchAll(PDO::FETCH_COLUMN);

    $machines = $db->query("
        SELECT DISTINCT m.machine_id
        FROM alerts a
        LEFT JOIN machines m ON m.id = a.machine_alerts
        WHERE m.machine_id IS NOT NULL AND m.machine_id != ''
        ORDER BY m.machine_id
    ")->fetchAll(PDO::FETCH_COLUMN);

    return [
        'scenarios' => $scenarios,
        'ips' => $ips,
        'countries' => $countries,
        'machines' => $machines
    ];
}

function getAlertLimit() {
    if (!isset($_GET['limit'])) {
        return 100;
    }

    $limitRaw = trim((string) $_GET['limit']);
    if ($limitRaw === '' || strtolower($limitRaw) === 'all' || $limitRaw === '0') {
        return null;
    }

    $limit = filter_var($limitRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $limit ?: 100;
}

function getAlertSort() {
    $allowed = [
        'created_at' => 'a.created_at',
        'duration' => 'duration_seconds',
        'scenario' => 'a.scenario',
        'machine' => 'm.machine_id',
        'source_ip' => 'a.source_ip',
        'source_country' => 'a.source_country',
        'events_count' => 'a.events_count',
        'decision_until' => 'decision_until'
    ];

    $key = trim((string) ($_GET['sort'] ?? 'created_at'));
    $directionRaw = strtolower(trim((string) ($_GET['direction'] ?? 'desc')));
    $direction = $directionRaw === 'asc' ? 'ASC' : 'DESC';
    $orderBy = $allowed[$key] ?? $allowed['created_at'];

    return [$orderBy, $direction];
}

function decodeEventSerialized($serialized) {
    if ($serialized === null || $serialized === '') {
        return [];
    }

    $decoded = json_decode($serialized, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

try {
    $db = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    if ($method === 'POST' && isset($_GET['filters'])) {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            jsonResponse(['error' => 'Invalid payload'], 400);
        }

        $_SESSION['alert_filters'] = [
            'scenario' => trim((string) ($payload['scenario'] ?? '')),
            'ip' => trim((string) ($payload['ip'] ?? '')),
            'machine' => trim((string) ($payload['machine'] ?? '')),
            'country' => trim((string) ($payload['country'] ?? '')),
            'repeatedOnly' => (bool) ($payload['repeatedOnly'] ?? false)
        ];

        jsonResponse(['status' => 'ok']);
    }

    if ($method === 'GET' && isset($_GET['filters'])) {
        jsonResponse(getAlertFilterOptions($db));
    }

    // GET /api/alerts?summary=1 - total count
    elseif ($method === 'GET' && isset($_GET['summary'])) {
        [$whereSql, $params] = buildAlertFilters($since);
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM (
                SELECT a.id
                FROM alerts a
                LEFT JOIN decisions d ON d.alert_decisions = a.id
                LEFT JOIN machines m ON m.id = a.machine_alerts
                {$whereSql}
                GROUP BY a.id
            ) as filtered_alerts
        ");
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        jsonResponse(['total_alerts' => (int) $total]);
    }

    // GET /api/alerts?id=:id - detail
    elseif ($method === 'GET' && isset($_GET['id'])) {
        $id = $_GET['id'];

        $api = new CrowdSecAPI();
        $alertData = $api->getAlertById($id);

        $stmt = $db->prepare("
            SELECT 
                a.source_as_name,
                a.source_as_number,
                a.source_latitude,
                a.source_longitude
            FROM alerts a
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $sourceMeta = $stmt->fetch();
        if ($sourceMeta) {
            $alertData['source_as_name'] = $sourceMeta['source_as_name'] ?? null;
            $alertData['source_as_number'] = $sourceMeta['source_as_number'] ?? null;
            $alertData['source_latitude'] = $sourceMeta['source_latitude'] ?? null;
            $alertData['source_longitude'] = $sourceMeta['source_longitude'] ?? null;
        }

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
        $stmt->execute([$id]);
        $alertData['decisions'] = $stmt->fetchAll();

        foreach ($alertData['decisions'] as &$decision) {
            $decision['expired'] = strtotime($decision['until']) < time();
        }

        $stmt = $db->prepare("
            SELECT 
                e.id,
                e.time,
                e.serialized
            FROM events e
            WHERE e.alert_events = ?
            ORDER BY e.time ASC
        ");
        $stmt->execute([$id]);
        $events = $stmt->fetchAll();

        $alertData['events_detail'] = array_map(function ($event) {
            return [
                'id' => $event['id'],
                'time' => $event['time'],
                'meta' => decodeEventSerialized($event['serialized'])
            ];
        }, $events);

        jsonResponse($alertData);
    }

    // GET /api/alerts - list
    elseif ($method === 'GET' && strpos($uri, '/api/alerts/') === false) {
        [$whereSql, $params] = buildAlertFilters($since);
        $limit = getAlertLimit();
        $limitSql = $limit ? 'LIMIT ' . (int) $limit : '';
        [$orderBy, $direction] = getAlertSort();
        $stmt = $db->prepare("
            SELECT 
                a.id,
                a.uuid,
                a.created_at,
                a.started_at,
                a.stopped_at,
                a.scenario,
                a.message,
                a.events_count,
                a.source_ip,
                a.source_country,
                a.source_as_name,
                a.source_as_number,
                a.simulated,
                m.machine_id as machine_id,
                COUNT(d.id) as decisions_count,
                MAX(d.until) as decision_until,
                CASE
                    WHEN a.started_at IS NULL OR a.stopped_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, a.started_at, a.stopped_at)
                END as duration_seconds,
                CASE WHEN repeated.scenario IS NULL THEN 0 ELSE 1 END as is_repeated
            FROM alerts a
            LEFT JOIN decisions d ON d.alert_decisions = a.id
            LEFT JOIN machines m ON m.id = a.machine_alerts
            LEFT JOIN (
                SELECT scenario, source_ip
                FROM alerts
                WHERE scenario IS NOT NULL AND source_ip IS NOT NULL
                GROUP BY scenario, source_ip
                HAVING COUNT(*) > 1
                   AND TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) >= ?
            ) repeated
                ON repeated.scenario = a.scenario
                AND repeated.source_ip = a.source_ip
            {$whereSql}
            GROUP BY a.id
            ORDER BY {$orderBy} {$direction}, a.created_at DESC
            {$limitSql}
        ");

        $stmt->execute(array_merge([$repeatedWindowSeconds], $params));
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
