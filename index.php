<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

renderPageStart($appTitle . ' - Dashboard', 'dashboard', $appTitle);
?>
    <section class="cards-grid">
        <div class="card stat-card">
            <p class="stat-label">Celkem alertů</p>
            <div class="stat-value" id="totalAlerts">-</div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Aktivní bany</p>
            <div class="stat-value" id="activeDecisions">-</div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top scénář</p>
            <div class="stat-value stat-small" id="topScenario">-</div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top země</p>
            <div class="stat-value" id="topCountry">-</div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Aktivita za posledních 24 hodin</h2>
            </div>
            <div class="card-body">
                <canvas id="timelineChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Top 10 scénářů</h2>
            </div>
            <div class="card-body">
                <canvas id="scenariosChart"></canvas>
            </div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Top 10 zemí</h2>
            </div>
            <div class="card-body">
                <table class="data-table" id="countriesTable">
                    <thead>
                        <tr>
                            <th>Země</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Top 10 IP adres</h2>
            </div>
            <div class="card-body">
                <table class="data-table" id="ipsTable">
                    <thead>
                        <tr>
                            <th>IP adresa</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        loadDashboard();
        setInterval(loadDashboard, 30000);
    </script>
<?php
renderPageEnd();
