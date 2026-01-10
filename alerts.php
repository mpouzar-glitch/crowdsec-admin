<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

renderPageStart($appTitle . ' - Alerts', 'alerts', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Alerty</h1>
            <p class="muted">Přehled všech incidentů v CrowdSec.</p>
        </div>
        <div class="toolbar">
            <div class="input-group">
                <span class="input-icon">🔍</span>
                <input type="text" id="searchAlerts" placeholder="Hledat..." />
            </div>
            <button class="btn" onclick="refreshAlerts()">Obnovit</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table" id="alertsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Čas</th>
                        <th>Scénář</th>
                        <th>IP adresa</th>
                        <th>Země</th>
                        <th>Počet událostí</th>
                        <th>Rozhodnutí</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <div id="alertModal" class="modal">
        <div class="modal-content">
            <button class="modal-close">×</button>
            <div id="alertDetail"></div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
        loadAlerts();
        setInterval(loadAlerts, 30000);
    </script>
<?php
renderPageEnd();
