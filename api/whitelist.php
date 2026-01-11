<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function ipToUnsignedLong($ip) {
    $long = ip2long($ip);
    if ($long === false) {
        return null;
    }
    return (int)sprintf('%u', $long);
}

function parseAllowListValue($value) {
    $value = trim($value);
    if ($value === '') {
        return ['error' => 'Value is required'];
    }

    if (strpos($value, '/') !== false) {
        [$ip, $suffix] = array_pad(explode('/', $value, 2), 2, null);
        $ip = trim((string)$ip);
        $suffix = trim((string)$suffix);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['error' => 'Invalid IPv4 address'];
        }
        if ($suffix === '' || !ctype_digit($suffix)) {
            return ['error' => 'Invalid subnet suffix'];
        }

        $suffix = (int)$suffix;
        if ($suffix < 0 || $suffix > 32) {
            return ['error' => 'Subnet suffix out of range'];
        }

        $ipLong = ipToUnsignedLong($ip);
        if ($ipLong === null) {
            return ['error' => 'Invalid IPv4 address'];
        }

        if ($suffix === 0) {
            $startIp = 0;
            $endIp = 0xFFFFFFFF;
            $ipSize = 0xFFFFFFFF + 1;
        } else {
            $mask = (0xFFFFFFFF << (32 - $suffix)) & 0xFFFFFFFF;
            $startIp = $ipLong & $mask;
            $endIp = $startIp + (1 << (32 - $suffix)) - 1;
            $ipSize = $endIp - $startIp + 1;
        }

        return [
            'start_ip' => $startIp,
            'end_ip' => $endIp,
            'start_suffix' => $suffix,
            'end_suffix' => $suffix,
            'ip_size' => $ipSize
        ];
    }

    if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['error' => 'Invalid IPv4 address'];
    }

    $ipLong = ipToUnsignedLong($value);
    if ($ipLong === null) {
        return ['error' => 'Invalid IPv4 address'];
    }

    return [
        'start_ip' => $ipLong,
        'end_ip' => $ipLong,
        'start_suffix' => 32,
        'end_suffix' => 32,
        'ip_size' => 1
    ];
}

function getDefaultAllowListId($db) {
    $stmt = $db->query('SELECT id FROM allow_lists ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function allowListExists($db, $allowListId) {
    $stmt = $db->prepare('SELECT id FROM allow_lists WHERE id = ?');
    $stmt->execute([$allowListId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $db = Database::getInstance()->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    if ($method === 'GET') {
        $stmt = $db->query("
            SELECT
                ali.allow_list_id,
                ali.allow_list_item_id,
                i.id,
                i.created_at,
                i.updated_at,
                i.expires_at,
                i.comment AS reason,
                i.value AS cidr,
                i.start_ip,
                i.end_ip,
                i.start_suffix,
                i.end_suffix,
                i.ip_size,
                l.name AS list_name
            FROM allow_list_allowlist_items ali
            JOIN allow_list_items i ON i.id = ali.allow_list_item_id
            JOIN allow_lists l ON l.id = ali.allow_list_id
            ORDER BY i.created_at DESC
        ");
        $items = $stmt->fetchAll();
        jsonResponse($items);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $value = trim($input['cidr'] ?? ($input['value'] ?? ''));
        $comment = trim($input['reason'] ?? ($input['comment'] ?? ''));
        $expiresAt = $input['expires_at'] ?? null;
        $allowListId = $input['allow_list_id'] ?? null;

        $parsed = parseAllowListValue($value);
        if (isset($parsed['error'])) {
            jsonResponse(['error' => $parsed['error']], 400);
        }

        if ($allowListId === null || $allowListId === '') {
            $allowListId = getDefaultAllowListId($db);
        }
        if (!$allowListId || !allowListExists($db, $allowListId)) {
            jsonResponse(['error' => 'No allow list available. Create one first (e.g. cscli allowlist create).'], 400);
        }

        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO allow_list_items (created_at, updated_at, expires_at, comment, value, start_ip, end_ip, start_suffix, end_suffix, ip_size)
            VALUES (NOW(), NOW(), :expires_at, :comment, :value, :start_ip, :end_ip, :start_suffix, :end_suffix, :ip_size)
        ");
        $stmt->execute([
            ':expires_at' => $expiresAt ?: null,
            ':comment' => $comment !== '' ? $comment : null,
            ':value' => $value,
            ':start_ip' => $parsed['start_ip'],
            ':end_ip' => $parsed['end_ip'],
            ':start_suffix' => $parsed['start_suffix'],
            ':end_suffix' => $parsed['end_suffix'],
            ':ip_size' => $parsed['ip_size']
        ]);

        $id = $db->lastInsertId();

        $stmt = $db->prepare("
            INSERT INTO allow_list_allowlist_items (allow_list_id, allow_list_item_id)
            VALUES (:allow_list_id, :allow_list_item_id)
        ");
        $stmt->execute([
            ':allow_list_id' => $allowListId,
            ':allow_list_item_id' => $id
        ]);

        $db->commit();
        auditLog('whitelist.add', [
            'id' => $id,
            'cidr' => $value,
            'reason' => $comment,
            'expires_at' => $expiresAt,
            'allow_list_id' => $allowListId
        ]);

        jsonResponse(['message' => 'Whitelist item created', 'id' => $id], 201);
    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $value = trim($input['cidr'] ?? ($input['value'] ?? ''));
        $comment = trim($input['reason'] ?? ($input['comment'] ?? ''));
        $expiresAt = $input['expires_at'] ?? null;
        $allowListId = $input['allow_list_id'] ?? null;

        if (!$id) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        $parsed = parseAllowListValue($value);
        if (isset($parsed['error'])) {
            jsonResponse(['error' => $parsed['error']], 400);
        }

        $stmt = $db->prepare("
            UPDATE allow_list_items
            SET updated_at = NOW(),
                expires_at = :expires_at,
                comment = :comment,
                value = :value,
                start_ip = :start_ip,
                end_ip = :end_ip,
                start_suffix = :start_suffix,
                end_suffix = :end_suffix,
                ip_size = :ip_size
            WHERE id = :id
        ");
        $stmt->execute([
            ':expires_at' => $expiresAt ?: null,
            ':comment' => $comment !== '' ? $comment : null,
            ':value' => $value,
            ':start_ip' => $parsed['start_ip'],
            ':end_ip' => $parsed['end_ip'],
            ':start_suffix' => $parsed['start_suffix'],
            ':end_suffix' => $parsed['end_suffix'],
            ':ip_size' => $parsed['ip_size'],
            ':id' => $id
        ]);

        if ($allowListId !== null && $allowListId !== '') {
            if (!allowListExists($db, $allowListId)) {
                jsonResponse(['error' => 'Allow list not found'], 400);
            }
            $stmt = $db->prepare("
                UPDATE allow_list_allowlist_items
                SET allow_list_id = :allow_list_id
                WHERE allow_list_item_id = :allow_list_item_id
            ");
            $stmt->execute([
                ':allow_list_id' => $allowListId,
                ':allow_list_item_id' => $id
            ]);
        }

        auditLog('whitelist.update', [
            'id' => $id,
            'cidr' => $value,
            'reason' => $comment,
            'expires_at' => $expiresAt,
            'allow_list_id' => $allowListId
        ]);

        jsonResponse(['message' => 'Whitelist item updated']);
    } elseif ($method === 'DELETE' && (isset($_GET['id']) || preg_match('#/api/whitelist/(\d+)#', $uri, $matches))) {
        $id = $_GET['id'] ?? $matches[1];

        $stmt = $db->prepare("
            SELECT i.id, i.value AS cidr, i.comment AS reason, i.expires_at, ali.allow_list_id
            FROM allow_list_allowlist_items ali
            JOIN allow_list_items i ON i.id = ali.allow_list_item_id
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $db->prepare("DELETE FROM allow_list_items WHERE id = ?");
        $stmt->execute([$id]);

        auditLog('whitelist.delete', [
            'id' => $id,
            'item' => $item
        ]);

        jsonResponse(['message' => 'Whitelist item deleted']);
    } else {
        jsonResponse(['error' => 'Not Found'], 404);
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Whitelist API Error: ' . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
