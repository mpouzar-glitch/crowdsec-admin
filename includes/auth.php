<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function getAuthUsers() {
    $env = loadEnv();
    $users = [];

    if (!empty($env['APP_USERS'])) {
        $items = array_filter(array_map('trim', explode(',', $env['APP_USERS'])));
        foreach ($items as $item) {
            $parts = explode(':', $item, 2);
            if (count($parts) === 2) {
                $users[] = ['username' => $parts[0], 'password' => $parts[1]];
            }
        }
    } elseif (!empty($env['APP_USER'])) {
        $users[] = ['username' => $env['APP_USER'], 'password' => $env['APP_PASSWORD'] ?? ''];
    } else {
        $users[] = ['username' => 'admin', 'password' => 'admin'];
    }

    return $users;
}

function verifyPassword($input, $stored) {
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
        return password_verify($input, $stored);
    }

    return hash_equals($stored, $input);
}

function loginUser($username, $password) {
    foreach (getAuthUsers() as $user) {
        if ($user['username'] === $username && verifyPassword($password, $user['password'])) {
            $_SESSION['user'] = [
                'username' => $username,
                'login_at' => time()
            ];
            auditLog('login', ['username' => $username], $username);
            return true;
        }
    }

    return false;
}

function logoutUser() {
    $user = getCurrentUser();
    if ($user) {
        auditLog('logout', ['username' => $user['username']], $user['username']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

function requireLogin() {
    if (!getCurrentUser()) {
        header('Location: /login.php');
        exit;
    }
}
