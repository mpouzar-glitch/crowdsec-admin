<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    requireLogin();
}

function readMaintenanceParams() {
    $env = loadEnv();
    $params = [
        'retentionHours' => null,
        'retentionDays' => null,
        'alertsRetentionHours' => isset($env['MAINTENANCE_ALERTS_RETENTION_HOURS']) ? (int) $env['MAINTENANCE_ALERTS_RETENTION_HOURS'] : null,
        'alertsRetentionDays' => isset($env['MAINTENANCE_ALERTS_RETENTION_DAYS']) ? (int) $env['MAINTENANCE_ALERTS_RETENTION_DAYS'] : null,
        'decisionsRetentionHours' => isset($env['MAINTENANCE_DECISIONS_RETENTION_HOURS']) ? (int) $env['MAINTENANCE_DECISIONS_RETENTION_HOURS'] : null,
        'decisionsRetentionDays' => isset($env['MAINTENANCE_DECISIONS_RETENTION_DAYS']) ? (int) $env['MAINTENANCE_DECISIONS_RETENTION_DAYS'] : null
    ];

    if (php_sapi_name() === 'cli') {
        $options = getopt('', [
            'retention-hours::',
            'retention-days::',
            'alerts-retention-hours::',
            'alerts-retention-days::',
            'decisions-retention-hours::',
            'decisions-retention-days::'
        ]);
        if (isset($options['retention-hours'])) {
            $params['retentionHours'] = (int) $options['retention-hours'];
        }
        if (isset($options['retention-days'])) {
            $params['retentionDays'] = (int) $options['retention-days'];
        }
        if (isset($options['alerts-retention-hours'])) {
            $params['alertsRetentionHours'] = (int) $options['alerts-retention-hours'];
        }
        if (isset($options['alerts-retention-days'])) {
            $params['alertsRetentionDays'] = (int) $options['alerts-retention-days'];
        }
        if (isset($options['decisions-retention-hours'])) {
            $params['decisionsRetentionHours'] = (int) $options['decisions-retention-hours'];
        }
        if (isset($options['decisions-retention-days'])) {
            $params['decisionsRetentionDays'] = (int) $options['decisions-retention-days'];
        }
    } else {
        $params['retentionHours'] = filter_input(INPUT_GET, 'retention_hours', FILTER_VALIDATE_INT);
        $params['retentionDays'] = filter_input(INPUT_GET, 'retention_days', FILTER_VALIDATE_INT);
        $alertsRetentionHours = filter_input(INPUT_GET, 'alerts_retention_hours', FILTER_VALIDATE_INT);
        $alertsRetentionDays = filter_input(INPUT_GET, 'alerts_retention_days', FILTER_VALIDATE_INT);
        $decisionsRetentionHours = filter_input(INPUT_GET, 'decisions_retention_hours', FILTER_VALIDATE_INT);
        $decisionsRetentionDays = filter_input(INPUT_GET, 'decisions_retention_days', FILTER_VALIDATE_INT);

        if ($alertsRetentionHours !== null && $alertsRetentionHours !== false) {
            $params['alertsRetentionHours'] = $alertsRetentionHours;
        }
        if ($alertsRetentionDays !== null && $alertsRetentionDays !== false) {
            $params['alertsRetentionDays'] = $alertsRetentionDays;
        }
        if ($decisionsRetentionHours !== null && $decisionsRetentionHours !== false) {
            $params['decisionsRetentionHours'] = $decisionsRetentionHours;
        }
        if ($decisionsRetentionDays !== null && $decisionsRetentionDays !== false) {
            $params['decisionsRetentionDays'] = $decisionsRetentionDays;
        }
    }

    return $params;
}

function resolveRetentionHours(array $params, string $type, int $defaultHours) {
    $typeHoursKey = $type . 'RetentionHours';
    $typeDaysKey = $type . 'RetentionDays';
    if (!empty($params[$typeHoursKey])) {
        return max(0, (int) $params[$typeHoursKey]);
    }
    if (!empty($params[$typeDaysKey])) {
        return max(0, (int) $params[$typeDaysKey]) * 24;
    }
    if (!empty($params['retentionHours'])) {
        return max(0, (int) $params['retentionHours']);
    }
    if (!empty($params['retentionDays'])) {
        return max(0, (int) $params['retentionDays']) * 24;
    }

    return $defaultHours;
}

function calculateCutoffDate(array $params, string $type, int $defaultHours) {
    $retentionHours = resolveRetentionHours($params, $type, $defaultHours);

    $cutoff = (new DateTimeImmutable())->sub(new DateInterval('PT' . $retentionHours . 'H'));

    return [
        'cutoff' => $cutoff,
        'retentionHours' => $retentionHours
    ];
}

$params = readMaintenanceParams();
$alertsCutoffMeta = calculateCutoffDate($params, 'alerts', 120);
$alertsCutoff = $alertsCutoffMeta['cutoff'];
$alertsCutoffFormatted = $alertsCutoff->format('Y-m-d H:i:s');
$decisionsCutoffMeta = calculateCutoffDate($params, 'decisions', 72);
$decisionsCutoff = $decisionsCutoffMeta['cutoff'];
$decisionsCutoffFormatted = $decisionsCutoff->format('Y-m-d H:i:s');

$result = [
    'cutoff_alerts' => $alertsCutoffFormatted,
    'cutoff_decisions' => $decisionsCutoffFormatted,
    'alerts_retention_hours' => $alertsCutoffMeta['retentionHours'],
    'decisions_retention_hours' => $decisionsCutoffMeta['retentionHours'],
    'retention_hours' => $alertsCutoffMeta['retentionHours'],
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
    $alertStmt->execute([':cutoff' => $alertsCutoffFormatted]);
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
    $deleteDecisionsStmt->execute([':cutoff' => $decisionsCutoffFormatted]);
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
