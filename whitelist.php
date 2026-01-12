<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

renderPageStart($appTitle . ' - Whitelist', 'whitelist', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Whitelist</h1>
            <p class="muted">Povolené IP adresy nebo subnety.</p>
        </div>
        <div class="toolbar">
            <button class="btn" onclick="openWhitelistModal()">Přidat položku</button>
            <button class="btn btn-ghost" onclick="refreshWhitelist()">Obnovit</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <p class="muted">
                Whitelist musí být nejdříve založen v tabulce allow_lists (doporučeno přes <code>cscli allowlist create</code>),
                následně se přidávají položky a propojí se přes allow_list_allowlist_items.
                Ukládáme je ve stejném formátu jako <code>cscli</code> (včetně IPv6), takže není potřeba volat další API příkaz
                a změny se okamžitě projeví v CrowdSec.
            </p>
        </div>
    </section>

    <section class="table-filters">
        <div class="filter-group">
            <label for="whitelistFilterCidr">CIDR</label>
            <input type="text" id="whitelistFilterCidr" placeholder="např. 10.0.0.0/24">
        </div>
        <div class="filter-group">
            <label for="whitelistFilterReason">Důvod</label>
            <input type="text" id="whitelistFilterReason" placeholder="např. interní servis">
        </div>
        <div class="filter-group">
            <label for="whitelistFilterList">Whitelist</label>
            <input type="text" id="whitelistFilterList" placeholder="např. my_whitelist">
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="button" onclick="clearWhitelistFilters()">Vyčistit filtry</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="whitelistTable">
                <thead>
                    <tr>
                        <th data-sort-key="id">ID <span class="sort-indicator"></span></th>
                        <th data-sort-key="list_name">Whitelist <span class="sort-indicator"></span></th>
                        <th data-sort-key="cidr">CIDR <span class="sort-indicator"></span></th>
                        <th data-sort-key="reason">Důvod <span class="sort-indicator"></span></th>
                        <th data-sort-key="expires_at">Platnost do <span class="sort-indicator"></span></th>
                        <th data-sort-key="created_at">Vytvořeno <span class="sort-indicator"></span></th>
                        <th data-sort-key="updated_at">Aktualizováno <span class="sort-indicator"></span></th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <div id="whitelistModal" class="modal">
        <div class="modal-content">
            <button class="modal-close">×</button>
            <h3 id="whitelistModalTitle">Přidat položku</h3>
            <form id="whitelistForm" class="form-grid">
                <input type="hidden" id="whitelistId">
                <div class="form-group">
                    <label>Whitelist</label>
                    <select id="whitelistList" required></select>
                </div>
                <div class="form-group">
                    <label>CIDR</label>
                    <input type="text" id="whitelistCidr" required placeholder="192.168.1.1 nebo 10.0.0.0/24">
                </div>
                <div class="form-group">
                    <label>Důvod</label>
                    <input type="text" id="whitelistReason" placeholder="např. monitoring">
                </div>
                <div class="form-group">
                    <label>Platnost do</label>
                    <input type="datetime-local" id="whitelistExpiresAt">
                </div>
                <button type="submit" class="btn">Uložit</button>
            </form>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
        loadAllowLists().then(loadWhitelist);
    </script>
<?php
renderPageEnd();
