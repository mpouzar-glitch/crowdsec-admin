<?php
/**
 * Alerts Helper Functions
 * SQL query building, filtering, sorting, and statistics for CrowdSec alerts
 */

require_once __DIR__ . '/filter_helper.php';

/**
 * Build WHERE clause for alerts table
 * @param array $filters Filters from request
 * @param array &$params Reference to PDO parameters array
 * @return string WHERE clause without WHERE keyword
 */
function buildAlertsWhereClause(array $filters, array &$params): string {
    $definitions = [
        [
            'key' => 'ip',
            'column' => 'source_ip',
            'operator' => 'equals'
        ],
        [
            'key' => 'scenario',
            'column' => 'scenario',
            'operator' => 'like',
            'lowercase' => false
        ],
        [
            'key' => 'country',
            'column' => 'source_country',
            'operator' => 'equals',
            'transform' => function($value) {
                return strtoupper($value);
            }
        ],
        [
            'key' => 'datefrom',
            'column' => 'created_at',
            'operator' => 'gte',
            'transform' => function ($value) {
                return $value . ' 00:00:00';
            }
        ],
        [
            'key' => 'dateto',
            'column' => 'created_at',
            'operator' => 'lte',
            'transform' => function ($value) {
                return $value . ' 23:59:59';
            }
        ],
        [
            'key' => 'simulated',
            'column' => 'simulated',
            'operator' => 'equals',
            'transform' => function ($value) {
                return (int) $value;
            }
        ],
    ];
    
    $conditions = buildFilterConditions($filters, $definitions, $params);
    return buildWhereClause($conditions);
}

/**
 * Build filter conditions from definitions
 * @param array $filters Current filter values
 * @param array $definitions Filter definitions
 * @param array &$params Reference to parameters array
 * @return array Array of WHERE conditions
 */
function buildFilterConditions(array $filters, array $definitions, array &$params): array {
    $conditions = [];
    
    foreach ($definitions as $def) {
        $key = $def['key'];
        
        // Skip if filter is not set or empty (except for numeric 0)
        if (!isset($filters[$key]) || ($filters[$key] === '' && $filters[$key] !== '0')) {
            continue;
        }
        
        $value = $filters[$key];
        $column = $def['column'];
        $operator = $def['operator'] ?? 'equals';
        
        // Apply transform if defined
        if (isset($def['transform']) && is_callable($def['transform'])) {
            $value = $def['transform']($value);
        }
        
        // Apply lowercase if needed
        if (isset($def['lowercase']) && $def['lowercase']) {
            $value = strtolower($value);
        }
        
        // Build condition based on operator
        switch ($operator) {
            case 'equals':
                $conditions[] = "{$column} = ?";
                $params[] = $value;
                break;
                
            case 'like':
                $conditions[] = "{$column} LIKE ?";
                $params[] = '%' . $value . '%';
                break;
                
            case 'startswith':
                $conditions[] = "{$column} LIKE ?";
                $params[] = $value . '%';
                break;
                
            case 'endswith':
                $conditions[] = "{$column} LIKE ?";
                $params[] = '%' . $value;
                break;
                
            case 'gte':
                $conditions[] = "{$column} >= ?";
                $params[] = $value;
                break;
                
            case 'lte':
                $conditions[] = "{$column} <= ?";
                $params[] = $value;
                break;
                
            case 'gt':
                $conditions[] = "{$column} > ?";
                $params[] = $value;
                break;
                
            case 'lt':
                $conditions[] = "{$column} < ?";
                $params[] = $value;
                break;
                
            case 'in':
                if (is_array($value) && !empty($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "{$column} IN ({$placeholders})";
                    $params = array_merge($params, $value);
                }
                break;
                
            case 'notin':
                if (is_array($value) && !empty($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $conditions[] = "{$column} NOT IN ({$placeholders})";
                    $params = array_merge($params, $value);
                }
                break;
        }
    }
    
    return $conditions;
}

/**
 * Build WHERE clause from conditions array
 * @param array $conditions Array of WHERE conditions
 * @return string WHERE clause without WHERE keyword
 */
function buildWhereClause(array $conditions): string {
    if (empty($conditions)) {
        return '1=1';
    }
    
    return implode(' AND ', $conditions);
}

/**
 * Build sorting configuration
 * @param string $sort Sort column from request
 * @param string $sortDir Sort direction from request
 * @param array $sortableColumns Allowed sortable columns
 * @param string $defaultSort Default sort column
 * @return array Sort configuration
 */
function buildSortConfig(string $sort, string $sortDir, array $sortableColumns, string $defaultSort = 'created_at'): array {
    // Validate sort column
    if (!isset($sortableColumns[$sort])) {
        $sort = $defaultSort;
    }
    
    // Validate sort direction
    $sortDir = strtolower($sortDir);
    if (!in_array($sortDir, ['asc', 'desc'])) {
        $sortDir = 'desc';
    }
    
    $column = $sortableColumns[$sort];
    
    return [
        'sort' => $sort,
        'dir' => $sortDir,
        'orderby' => $column . ' ' . strtoupper($sortDir)
    ];
}

/**
 * Build sort configuration for alerts
 * @param string $sort Sort column
 * @param string $sortDir Sort direction
 * @param array $sortableColumns Sortable columns mapping
 * @return array Sort configuration
 */
function buildAlertsSort(string $sort, string $sortDir, array $sortableColumns): array {
    return buildSortConfig($sort, $sortDir, $sortableColumns, 'created_at');
}

/**
 * Build complete SELECT query for alerts
 * @param array $filters Filters from request
 * @param array &$params Reference to parameters array
 * @param array $options Additional query options
 * @return string Complete SQL query
 */
function buildAlertsQuery(array $filters, array &$params, array $options = []): string {
    $defaults = [
        'select' => '*',
        'orderby' => 'created_at DESC',
        'limit' => null,
        'offset' => 0
    ];
    
    $options = array_merge($defaults, $options);
    $whereClause = buildAlertsWhereClause($filters, $params);
    
    $sql = "SELECT {$options['select']} FROM alerts WHERE {$whereClause}";
    
    if ($options['orderby']) {
        $sql .= " ORDER BY {$options['orderby']}";
    }
    
    if ($options['limit']) {
        $sql .= " LIMIT " . (int)$options['limit'];
    }
    
    if ($options['offset']) {
        $sql .= " OFFSET " . (int)$options['offset'];
    }
    
    return $sql;
}

/**
 * Count total alerts matching filters
 * @param PDO $db Database connection
 * @param array $filters Filters
 * @return int Total count
 */
function countAlerts(PDO $db, array $filters): int {
    $params = [];
    $whereClause = buildAlertsWhereClause($filters, $params);
    
    $sql = "SELECT COUNT(*) FROM alerts WHERE {$whereClause}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    return (int)$stmt->fetchColumn();
}

/**
 * Get extended statistics for alerts
 * @param PDO $db Database connection
 * @param array $filters Current filters
 * @return array Statistics data
 */
function getAlertsStats(PDO $db, array $filters): array {
    $stats = [
        'totalalerts' => 0,
        'uniqueips' => 0,
        'uniquescenarios' => 0,
        'totalevents' => 0,
        'simulatedalerts' => 0,
        'activedecisions' => 0,
        'avgevents' => 0
    ];
    
    try {
        $params = [];
        $whereClause = buildAlertsWhereClause($filters, $params);
        
        // Get basic alert statistics
        $sql = "SELECT 
            COUNT(*) AS totalalerts,
            COUNT(DISTINCT source_ip) AS uniqueips,
            COUNT(DISTINCT scenario) AS uniquescenarios,
            SUM(events_count) AS totalevents,
            COUNT(CASE WHEN simulated = 1 THEN 1 END) AS simulatedalerts
        FROM alerts 
        WHERE {$whereClause}";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $stats['totalalerts'] = (int)($row['totalalerts'] ?? 0);
            $stats['uniqueips'] = (int)($row['uniqueips'] ?? 0);
            $stats['uniquescenarios'] = (int)($row['uniquescenarios'] ?? 0);
            $stats['totalevents'] = (int)($row['totalevents'] ?? 0);
            $stats['simulatedalerts'] = (int)($row['simulatedalerts'] ?? 0);
        }
        
        // Get active decisions count
        $decisionsSql = "SELECT COUNT(*) AS activedecisions 
            FROM decisions 
            WHERE (until IS NULL OR until > NOW()) 
            AND alert_decisions IN (
                SELECT id FROM alerts WHERE {$whereClause}
            )";
        
        $decisionsStmt = $db->prepare($decisionsSql);
        $decisionsStmt->execute($params);
        $decisionRow = $decisionsStmt->fetch(PDO::FETCH_ASSOC);
        $stats['activedecisions'] = (int)($decisionRow['activedecisions'] ?? 0);
        
        // Calculate average events
        if ($stats['totalalerts'] > 0) {
            $stats['avgevents'] = round($stats['totalevents'] / $stats['totalalerts'], 2);
        }
        
    } catch (Throwable $e) {
        error_log("Alert stats error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get alert by ID
 * @param PDO $db Database connection
 * @param int $id Alert ID
 * @return array|null Alert data or null if not found
 */
function getAlertById(PDO $db, int $id): ?array {
    $sql = "SELECT * FROM alerts WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    
    $alert = $stmt->fetch(PDO::FETCH_ASSOC);
    return $alert ?: null;
}

/**
 * Get active decisions for alerts
 * @param PDO $db Database connection
 * @param array $alertIds Array of alert IDs
 * @return array Map of alert_id => decision_id
 */
function getActiveDecisionsForAlerts(PDO $db, array $alertIds): array {
    if (empty($alertIds)) {
        return [];
    }
    
    $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
    
    $sql = "SELECT id, alert_decisions, until 
        FROM decisions 
        WHERE alert_decisions IN ({$placeholders}) 
        AND (until IS NULL OR until > NOW())
        ORDER BY until DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($alertIds);
    $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $activeDecisions = [];
    foreach ($decisions as $decision) {
        $alertId = (int)$decision['alert_decisions'];
        if (!isset($activeDecisions[$alertId])) {
            $activeDecisions[$alertId] = (int)$decision['id'];
        }
    }
    
    return $activeDecisions;
}

/**
 * Render alerts statistics inline
 * @param array $stats Statistics data
 * @param array $options Display options
 * @return string HTML output
 */
function renderAlertsStatsInline(array $stats, array $options = []): string {
    return renderStatsInline($stats, array_merge([
        'showtotal' => true,
        'showactivedecisions' => true,
        'showuniqueips' => true,
        'showuniquescenarios' => true,
        'showtotalevents' => true,
        'showsimulated' => true,
        'showavgevents' => true
    ], $options));
}
