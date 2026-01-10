<?php
require_once __DIR__ . '/includes/auth.php';

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (loginUser($username, $password)) {
        header('Location: /index.php');
        exit;
    }

    $error = 'Nesprávné přihlašovací údaje.';
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appTitle) ?> - Login</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <span class="auth-kicker">CrowdSec</span>
            <h1 class="auth-title"><?= htmlspecialchars($appTitle) ?></h1>
            <p class="auth-subtitle">Přihlaste se pro pokračování.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <div class="form-group">
                <label>Uživatel</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Heslo</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Přihlásit</button>
        </form>
    </div>
</body>
</html>
