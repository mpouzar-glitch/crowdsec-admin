<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

renderPageStart($appTitle . ' - Machines', 'machines', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Machines</h1>
            <p class="muted">Přehled připojených strojů a jejich stavu.</p>
        </div>
        <div class="toolbar">
            <button class="btn" onclick="loadMachines()">Obnovit</button>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="machinesTable">
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>IP adresa</th>
                        <th>Stav</th>
                        <th>Poslední heartbeat</th>
                        <th>Poslední push</th>
                        <th>Alerty</th>
                        <th>Rozhodnutí</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <script src="/assets/js/app.js"></script>
    <script>
        loadMachines();
        setInterval(loadMachines, 60000);
    </script>
<?php
renderPageEnd();
