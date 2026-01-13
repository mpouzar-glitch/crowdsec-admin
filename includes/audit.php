<?php
require_once __DIR__ . '/../config/database.php';

function auditLog($action, $details = [], $userOverride = null) {
    $username = $userOverride ?? (function_exists('getCurrentUser') ? (getCurrentUser()['username'] ?? 'system') : 'system');
    $detailsPayload = null;

    if (is_array($details)) {
        $detailsPayload = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($details !== null) {
        $detailsPayload = (string)$details;
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare('
            INSERT INTO audit_log (user_id, username, action, details, ip_address, user_agent)
            VALUES (:user_id, :username, :action, :details, :ip_address, :user_agent)
        ');
        $stmt->execute([
            ':user_id' => null,
            ':username' => $username,
            ':action' => $action,
            ':details' => $detailsPayload,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    } catch (Exception $e) {
        error_log('Audit log insert failed: ' . $e->getMessage());
    }
}

function readAuditLog($limit = 200) {
    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare('
            SELECT timestamp, username, action, details, ip_address
            FROM audit_log
            ORDER BY timestamp DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = null;
            if ($row['details'] !== null) {
                $decoded = json_decode($row['details'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = $row['details'];
                }
            }

            $entries[] = [
                'timestamp' => $row['timestamp'],
                'user' => $row['username'] ?? 'system',
                'action' => $row['action'],
                'details' => $decoded,
                'ip' => $row['ip_address'] ?? 'cli'
            ];
        }

        return $entries;
    } catch (Exception $e) {
        error_log('Audit log read failed: ' . $e->getMessage());
        return [];
    }
}

function buildAuditWhereClause(array $filters, array &$params) {
    $clauses = [];
    $search = trim((string) ($filters['search'] ?? ''));
    $action = trim((string) ($filters['action'] ?? ''));
    $user = trim((string) ($filters['user'] ?? ''));
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));

    if ($search !== '') {
        $clauses[] = '(username LIKE :search OR action LIKE :search OR ip_address LIKE :search OR details LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($action !== '') {
        $clauses[] = 'action = :action';
        $params[':action'] = $action;
    }

    if ($user !== '') {
        $clauses[] = 'username LIKE :user';
        $params[':user'] = '%' . $user . '%';
    }

    if ($dateFrom !== '') {
        $clauses[] = 'timestamp >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $clauses[] = 'timestamp <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    if (empty($clauses)) {
        return '';
    }

    return 'WHERE ' . implode(' AND ', $clauses);
}

function readAuditLogPage(array $filters, int $page, int $perPage) {
    try {
        $conn = Database::getInstance()->getConnection();
        $params = [];
        $whereClause = buildAuditWhereClause($filters, $params);

        $countStmt = $conn->prepare('SELECT COUNT(*) FROM audit_log ' . $whereClause);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $stmt = $conn->prepare('
            SELECT timestamp, username, action, details, ip_address
            FROM audit_log
            ' . $whereClause . '
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset
        ');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = null;
            if ($row['details'] !== null) {
                $decoded = json_decode($row['details'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = $row['details'];
                }
            }

            $entries[] = [
                'timestamp' => $row['timestamp'],
                'user' => $row['username'] ?? 'system',
                'action' => $row['action'],
                'details' => $decoded,
                'ip' => $row['ip_address'] ?? 'cli'
            ];
        }

        return [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages
        ];
    } catch (Exception $e) {
        error_log('Audit log read failed: ' . $e->getMessage());
        return [
            'entries' => [],
            'total' => 0,
            'page' => 1,
            'total_pages' => 1
        ];
    }
}

function readAuditActions() {
    try {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC');
        $actions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $action) {
            if ($action !== null && $action !== '') {
                $actions[] = $action;
            }
        }

        return $actions;
    } catch (Exception $e) {
        error_log('Audit log actions read failed: ' . $e->getMessage());
        return [];
    }
}
