<?php
require_once __DIR__ . '/auth.php';

function renderPageStart($pageTitle, $activeMenu, $appTitle = 'CrowdSec Admin') {
    $user = getCurrentUser();
    $username = $user['username'] ?? 'unknown';
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/index.php', 'icon' => 'fa-chart-line'],
        'alerts' => ['label' => 'Alerts', 'href' => '/alerts.php', 'icon' => 'fa-bell'],
        'decisions' => ['label' => 'Decisions', 'href' => '/decisions.php', 'icon' => 'fa-gavel'],
        'audit' => ['label' => 'Audit Log', 'href' => '/auditlog.php', 'icon' => 'fa-clipboard-list']
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
    echo "    <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css\">\n";
    if ($activeMenu === 'dashboard') {
        echo "    <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/jsvectormap/1.5.3/css/jsvectormap.min.css\">\n";
        echo "    <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/7.5.0/css/flag-icons.min.css\">\n";
    }
    echo "</head>\n";
    echo "<body>\n";
    echo "    <div class=\"app-shell\">\n";
    echo "        <nav class=\"top-nav\">\n";
    echo "            <div class=\"nav-container\">\n";
    echo "                <div class=\"nav-brand\">\n";
    echo "                    <i class=\"fas fa-shield-alt\"></i>\n";
    echo "                    <span>{$appTitle}</span>\n";
    echo "                </div>\n";
    echo "                <button class=\"menu-toggle\" id=\"menuToggle\" aria-label=\"Toggle menu\">\n";
    echo "                    <i class=\"fas fa-bars\"></i>\n";
    echo "                </button>\n";
    echo "                <div class=\"nav-menu\" id=\"navMenu\">\n";
    foreach ($menuItems as $key => $item) {
        $activeClass = $key === $activeMenu ? 'active' : '';
        $icon = $item['icon'] ?? 'fa-circle';
        echo "                    <a class=\"nav-item {$activeClass}\" href=\"{$item['href']}\"><i class=\"fas {$icon}\"></i>{$item['label']}</a>\n";
    }
    echo "                    <div class=\"nav-user\">\n";
    echo "                        <span class=\"user-name\">{$username}</span>\n";
    echo "                        <a class=\"btn btn-ghost nav-logout\" href=\"/logout.php\">Logout</a>\n";
    echo "                    </div>\n";
    echo "                </div>\n";
    echo "            </div>\n";
    echo "        </nav>\n";
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
    echo "    <script>\n";
    echo "        document.addEventListener('DOMContentLoaded', function () {\n";
    echo "            const menuToggle = document.getElementById('menuToggle');\n";
    echo "            const navMenu = document.getElementById('navMenu');\n";
    echo "            if (!menuToggle || !navMenu) {\n";
    echo "                return;\n";
    echo "            }\n";
    echo "            menuToggle.addEventListener('click', function () {\n";
    echo "                navMenu.classList.toggle('active');\n";
    echo "                const icon = this.querySelector('i');\n";
    echo "                if (icon) {\n";
    echo "                    icon.classList.toggle('fa-bars');\n";
    echo "                    icon.classList.toggle('fa-times');\n";
    echo "                }\n";
    echo "            });\n";
    echo "            const navLinks = navMenu.querySelectorAll('.nav-item');\n";
    echo "            navLinks.forEach((link) => {\n";
    echo "                link.addEventListener('click', function () {\n";
    echo "                    if (window.innerWidth <= 768) {\n";
    echo "                        navMenu.classList.remove('active');\n";
    echo "                        const icon = menuToggle.querySelector('i');\n";
    echo "                        if (icon) {\n";
    echo "                            icon.classList.add('fa-bars');\n";
    echo "                            icon.classList.remove('fa-times');\n";
    echo "                        }\n";
    echo "                    }\n";
    echo "                });\n";
    echo "            });\n";
    echo "            document.addEventListener('click', function (event) {\n";
    echo "                if (window.innerWidth <= 768) {\n";
    echo "                    const isClickInside = navMenu.contains(event.target) || menuToggle.contains(event.target);\n";
    echo "                    if (!isClickInside && navMenu.classList.contains('active')) {\n";
    echo "                        navMenu.classList.remove('active');\n";
    echo "                        const icon = menuToggle.querySelector('i');\n";
    echo "                        if (icon) {\n";
    echo "                            icon.classList.add('fa-bars');\n";
    echo "                            icon.classList.remove('fa-times');\n";
    echo "                        }\n";
    echo "                    }\n";
    echo "                }\n";
    echo "            });\n";
    echo "        });\n";
    echo "    </script>\n";
    echo "</body>\n";
    echo "</html>\n";
}
