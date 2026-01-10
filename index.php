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
        <div class="card map-card">
            <div class="card-header map-header">
                <h2>Mapa světa</h2>
                <div class="map-toolbar">
                    <label for="mapMode">Vybarvit podle</label>
                    <select id="mapMode">
                        <option value="alerts" selected>Alertů</option>
                        <option value="decisions">Banů</option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div class="world-map" id="worldMap"></div>
                <div class="map-legend" id="mapLegend"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Alerty podle hostů</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="sourcesChart"></canvas>
                </div>
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
                            <th>Vlajka</th>
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

    <div id="longTermBanModal" class="modal">
        <div class="modal-content">
            <button class="modal-close">×</button>
            <h3>Dlouhodobý ban</h3>
            <form id="longTermBanForm" class="form-grid">
                <div class="form-group">
                    <label>IP adresa</label>
                    <input type="text" id="longTermBanIp" required placeholder="192.168.1.1">
                </div>
                <div class="form-group">
                    <label>Doba trvání</label>
                    <select id="longTermBanDuration">
                        <option value="168h">7 dnů</option>
                        <option value="720h" selected>30 dnů</option>
                        <option value="2160h">90 dnů</option>
                        <option value="4320h">180 dnů</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Důvod</label>
                    <input type="text" id="longTermBanReason" value="manual" placeholder="manual">
                </div>
                <button type="submit" class="btn">Přidat dlouhodobý ban</button>
            </form>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsvectormap/1.5.3/js/jsvectormap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsvectormap/1.5.3/maps/world.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        loadDashboard();
        setInterval(loadDashboard, 30000);
    </script>
<?php
renderPageEnd();
