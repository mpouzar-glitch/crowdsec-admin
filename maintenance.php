<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    requireLogin();
}

function readMaintenanceParams() {
    $params = [
        'retentionHours' => null,
        'retentionDays' => null
    ];

    if (php_sapi_name() === 'cli') {
        $options = getopt('', ['retention-hours::', 'retention-days::']);
        if (isset($options['retention-hours'])) {
            $params['retentionHours'] = (int) $options['retention-hours'];
        }
        if (isset($options['retention-days'])) {
            $params['retentionDays'] = (int) $options['retention-days'];
        }
    } else {
        $params['retentionHours'] = filter_input(INPUT_GET, 'retention_hours', FILTER_VALIDATE_INT);
        $params['retentionDays'] = filter_input(INPUT_GET, 'retention_days', FILTER_VALIDATE_INT);
    }

    return $params;
}

function calculateCutoffDate(array $params) {
    $retentionHours = null;
    if (!empty($params['retentionHours'])) {
        $retentionHours = max(0, (int) $params['retentionHours']);
    } elseif (!empty($params['retentionDays'])) {
        $retentionHours = max(0, (int) $params['retentionDays']) * 24;
    } else {
        $retentionHours = 168;
    }

    $cutoff = (new DateTimeImmutable())->sub(new DateInterval('PT' . $retentionHours . 'H'));

    return [
        'cutoff' => $cutoff,
        'retentionHours' => $retentionHours
    ];
}

$params = readMaintenanceParams();
$cutoffMeta = calculateCutoffDate($params);
$cutoff = $cutoffMeta['cutoff'];
$cutoffFormatted = $cutoff->format('Y-m-d H:i:s');

$result = [
    'cutoff' => $cutoffFormatted,
    'retention_hours' => $cutoffMeta['retentionHours'],
    'alerts_deleted' => 0,
    'decisions_deleted' => 0,
    'events_deleted' => 0
];

try {
    $conn = Database::getInstance()->getConnection();
    $conn->beginTransaction();

    $alertStmt = $conn->prepare('
        SELECT DISTINCT alert_decisions
        FROM decisions
        WHERE `until` IS NOT NULL
          AND `until` < :cutoff
          AND alert_decisions IS NOT NULL
    ');
    $alertStmt->execute([':cutoff' => $cutoffFormatted]);
    $alertIds = array_filter(array_map('intval', $alertStmt->fetchAll(PDO::FETCH_COLUMN)));

    if (!empty($alertIds)) {
        $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
        $eventCountStmt = $conn->prepare("SELECT COUNT(*) FROM events WHERE alert_events IN ({$placeholders})");
        $eventCountStmt->execute($alertIds);
        $result['events_deleted'] = (int) $eventCountStmt->fetchColumn();

        $deleteAlertsStmt = $conn->prepare("DELETE FROM alerts WHERE id IN ({$placeholders})");
        $deleteAlertsStmt->execute($alertIds);
        $result['alerts_deleted'] = $deleteAlertsStmt->rowCount();
    }

    $deleteDecisionsStmt = $conn->prepare('
        DELETE FROM decisions
        WHERE `until` IS NOT NULL
          AND `until` < :cutoff
          AND (alert_decisions IS NULL OR alert_decisions = 0)
    ');
    $deleteDecisionsStmt->execute([':cutoff' => $cutoffFormatted]);
    $result['decisions_deleted'] = $deleteDecisionsStmt->rowCount();

    $conn->commit();

    auditLog('maintenance.cleanup', $result);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $result['error'] = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
