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
