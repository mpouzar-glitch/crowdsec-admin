<?php
require_once __DIR__ . '/auth.php';

function renderPageStart($pageTitle, $activeMenu, $appTitle = 'CrowdSec Admin') {
    $user = getCurrentUser();
    $username = $user['username'] ?? 'unknown';
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/index.php'],
        'alerts' => ['label' => 'Alerts', 'href' => '/alerts.php'],
        'decisions' => ['label' => 'Decisions', 'href' => '/decisions.php'],
        'users' => ['label' => 'Users', 'href' => '/users.php'],
        'audit' => ['label' => 'Audit Log', 'href' => '/auditlog.php']
    ];

    echo "<!DOCTYPE html>\n";
    echo "<html lang=\"cs\">\n";
    echo "<head>\n";
    echo "    <meta charset=\"UTF-8\">\n";
    echo "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    echo "    <title>{$pageTitle}</title>\n";
    echo "    <link rel=\"stylesheet\" href=\"/assets/css/style.css\">\n";
    echo "    <link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
    echo "    <link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
    echo "    <link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "    <div class=\"app-shell\">\n";
    echo "        <aside class=\"sidebar\">\n";
    echo "            <div class=\"sidebar-brand\">{$appTitle}</div>\n";
    echo "            <nav class=\"sidebar-nav\">\n";
    foreach ($menuItems as $key => $item) {
        $activeClass = $key === $activeMenu ? 'active' : '';
        echo "                <a class=\"nav-link {$activeClass}\" href=\"{$item['href']}\">{$item['label']}</a>\n";
    }
    echo "            </nav>\n";
    echo "            <div class=\"sidebar-footer\">\n";
    echo "                <div class=\"user-pill\">{$username}</div>\n";
    echo "                <a class=\"btn btn-ghost\" href=\"/logout.php\">Logout</a>\n";
    echo "            </div>\n";
    echo "        </aside>\n";
    echo "        <div class=\"app-content\">\n";
    echo "            <header class=\"topbar\">\n";
    echo "                <div class=\"topbar-title\">{$pageTitle}</div>\n";
    echo "            </header>\n";
    echo "            <main class=\"page\">\n";
}

function renderPageEnd() {
    echo "            </main>\n";
    echo "        </div>\n";
    echo "    </div>\n";
    echo "</body>\n";
    echo "</html>\n";
}
