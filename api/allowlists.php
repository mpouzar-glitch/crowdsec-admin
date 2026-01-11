<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $db = Database::getInstance()->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->query('SELECT id, name, description, from_console, created_at, updated_at FROM allow_lists ORDER BY name ASC');
        $lists = $stmt->fetchAll();
        jsonResponse($lists);
    }

    jsonResponse(['error' => 'Not Found'], 404);
} catch (Exception $e) {
    error_log('Allowlist API Error: ' . $e->getMessage());
    jsonResponse(['error' => $e->getMessage()], 500);
}
