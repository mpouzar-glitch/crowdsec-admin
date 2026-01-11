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
            <p class="muted">Přehled všech incidentů v CrowdSec. Celkem <strong id="alertsTotalCount">0</strong> alertů.</p>
        </div>
        <div class="toolbar">
            <button class="btn" onclick="refreshAlerts()">Obnovit</button>
        </div>
    </section>

    <section class="table-filters">
        <div class="filter-group">
            <label for="alertFilterScenario"><i class="fa-solid fa-layer-group"></i> Scénář</label>
            <input type="text" id="alertFilterScenario" data-filter-key="scenario" list="alertScenarioList" placeholder="např. ssh-bf">
            <datalist id="alertScenarioList"></datalist>
        </div>
        <div class="filter-group">
            <label for="alertFilterIp"><i class="fa-solid fa-network-wired"></i> IP adresa</label>
            <input type="text" id="alertFilterIp" data-filter-key="ip" list="alertIpList" placeholder="např. 192.168.1.1">
            <datalist id="alertIpList"></datalist>
        </div>
        <div class="filter-group">
            <label for="alertFilterMachine"><i class="fa-solid fa-server"></i> Machine</label>
            <select id="alertFilterMachine" data-filter-key="machine">
                <option value="">Všechny machine</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="alertFilterCountry"><i class="fa-solid fa-flag"></i> Země</label>
            <input type="text" id="alertFilterCountry" data-filter-key="country" list="alertCountryList" placeholder="např. CZ">
            <datalist id="alertCountryList"></datalist>
        </div>
        <div class="filter-group checkbox">
            <label for="alertFilterRepeated">
                <input type="checkbox" id="alertFilterRepeated" data-filter-key="repeated">
                <i class="fa-solid fa-repeat"></i> Pouze opakující se alerty
            </label>
        </div>
        <div class="filter-group checkbox">
            <label for="alertFilterHasDecisions">
                <input type="checkbox" id="alertFilterHasDecisions" data-filter-key="has_decisions">
                <i class="fa-solid fa-gavel"></i> Pouze s rozhodnutím
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="button" onclick="clearAlertFilters()">Vyčistit filtry</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="alertsTable">
                <thead>
                    <tr>
                        <th data-sort-key="created_at">Čas <span class="sort-indicator"></span></th>
                        <th data-sort-key="scenario">Scénář <span class="sort-indicator"></span></th>
                        <th data-sort-key="machine">Machine <span class="sort-indicator"></span></th>
                        <th data-sort-key="source_ip">IP adresa <span class="sort-indicator"></span></th>
                        <th data-sort-key="source_country">Země <span class="sort-indicator"></span></th>
                        <th data-sort-key="events_count">Počet událostí <span class="sort-indicator"></span></th>
                        <th data-sort-key="decisions_count">Rozhodnutí <span class="sort-indicator"></span></th>
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
