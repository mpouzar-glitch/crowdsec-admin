<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

renderPageStart($appTitle . ' - Decisions', 'decisions', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Rozhodnutí</h1>
            <p class="muted">Aktivní a expirované bany.</p>
        </div>
        <div class="toolbar">
            <label class="checkbox">
                <input type="checkbox" id="includeExpired" onchange="loadDecisions()">
                <span>Zobrazit expirované</span>
            </label>
            <label class="checkbox">
                <input type="checkbox" id="hideDuplicates" checked onchange="filterDuplicates()">
                <span>Skrýt duplikáty</span>
            </label>
            <button class="btn" onclick="showAddDecisionModal()">Přidat ban</button>
            <button class="btn btn-ghost" onclick="refreshDecisions()">Obnovit</button>
        </div>
    </section>

    <section class="table-filters">
        <div class="filter-group">
            <label for="decisionFilterValue">IP / Hodnota</label>
            <input type="text" id="decisionFilterValue" data-filter-key="value" list="decisionValueList" placeholder="např. 10.0.0.1">
            <datalist id="decisionValueList"></datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterScenario">Scénář</label>
            <input type="text" id="decisionFilterScenario" data-filter-key="scenario" list="decisionScenarioList" placeholder="např. ssh-bf">
            <datalist id="decisionScenarioList"></datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterType">Typ</label>
            <input type="text" id="decisionFilterType" data-filter-key="type" list="decisionTypeList" placeholder="např. ban">
            <datalist id="decisionTypeList"></datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterCountry">Země</label>
            <input type="text" id="decisionFilterCountry" data-filter-key="country" list="decisionCountryList" placeholder="např. CZ">
            <datalist id="decisionCountryList"></datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterStatus">Status</label>
            <input type="text" id="decisionFilterStatus" data-filter-key="status" list="decisionStatusList" placeholder="Aktivní / Expirované">
            <datalist id="decisionStatusList">
                <option value="Aktivní"></option>
                <option value="Expirované"></option>
            </datalist>
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="button" onclick="clearDecisionFilters()">Vyčistit filtry</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="decisionsTable">
                <thead>
                    <tr>
                        <th data-sort-key="id">ID <span class="sort-indicator"></span></th>
                        <th data-sort-key="created_at">Čas <span class="sort-indicator"></span></th>
                        <th data-sort-key="value">IP adresa <span class="sort-indicator"></span></th>
                        <th data-sort-key="type">Typ <span class="sort-indicator"></span></th>
                        <th data-sort-key="scenario">Scénář <span class="sort-indicator"></span></th>
                        <th data-sort-key="country">Země <span class="sort-indicator"></span></th>
                        <th data-sort-key="expiration">Expirace <span class="sort-indicator"></span></th>
                        <th data-sort-key="status">Status <span class="sort-indicator"></span></th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <div id="addDecisionModal" class="modal">
        <div class="modal-content">
            <button class="modal-close">×</button>
            <h3>Přidat nový ban</h3>
            <form id="addDecisionForm" class="form-grid">
                <div class="form-group">
                    <label>IP adresa</label>
                    <input type="text" id="banIp" required placeholder="192.168.1.1">
                </div>
                <div class="form-group">
                    <label>Doba trvání</label>
                    <select id="banDuration">
                        <option value="1h">1 hodina</option>
                        <option value="4h" selected>4 hodiny</option>
                        <option value="24h">24 hodin</option>
                        <option value="168h">7 dnů</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Důvod</label>
                    <input type="text" id="banReason" value="manual" placeholder="manual">
                </div>
                <button type="submit" class="btn">Přidat ban</button>
            </form>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
        loadDecisions();
        setInterval(loadDecisions, 30000);
    </script>
<?php
renderPageEnd();
