<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$lookbackMs = parseLookbackToMs($env['LOOKBACK_PERIOD'] ?? '7d');
$since = gmdate('Y-m-d H:i:s', (time() * 1000 - $lookbackMs) / 1000);

$stats = [
    'total_alerts' => 0,
    'active_decisions' => 0,
    'top_scenarios' => [],
    'top_countries' => [],
    'top_ips' => [],
    'alerts_by_host' => [],
    'events_by_host' => [],
    'timeline_24h' => [],
    'top_decision_countries' => [],
];

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare('SELECT COUNT(*) FROM alerts WHERE created_at >= ?');
    $stmt->execute([$since]);
    $stats['total_alerts'] = (int) $stmt->fetchColumn();

    $stmt = $db->query('SELECT COUNT(*) FROM decisions WHERE until > UTC_TIMESTAMP()');
    $stats['active_decisions'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare('
        SELECT scenario, COUNT(*) as count
        FROM alerts
        WHERE created_at >= ?
        GROUP BY scenario
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_scenarios'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT source_country as country, COUNT(*) as count
        FROM alerts
        WHERE created_at >= ? AND source_country IS NOT NULL AND source_country != ""
        GROUP BY source_country
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_countries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT a.source_country as country, COUNT(*) as count
        FROM decisions d
        INNER JOIN alerts a ON d.alert_decisions = a.id
        WHERE d.created_at >= ? AND a.source_country IS NOT NULL AND a.source_country != ""
        GROUP BY a.source_country
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_decision_countries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT source_ip as ip, COUNT(*) as count
        FROM alerts
        WHERE created_at >= ? AND source_ip IS NOT NULL AND source_ip != ""
        GROUP BY source_ip
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_ips'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query('
        SELECT
            DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
            COUNT(*) as count
        FROM alerts
        WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
        GROUP BY hour
        ORDER BY hour
    ');
    $stats['timeline_24h'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT COALESCE(m.machine_id, "Neznamy") as host, COUNT(*) as count
        FROM alerts a
        LEFT JOIN machines m ON a.machine_alerts = m.id
        WHERE a.created_at >= ?
        GROUP BY m.machine_id
        ORDER BY count DESC
        LIMIT 7
    ');
    $stmt->execute([$since]);
    $stats['alerts_by_host'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
        SELECT COALESCE(m.machine_id, "Neznamy") as host, COALESCE(SUM(a.events_count), 0) as count
        FROM alerts a
        LEFT JOIN machines m ON a.machine_alerts = m.id
        WHERE a.created_at >= ?
        GROUP BY m.machine_id
        ORDER BY count DESC
        LIMIT 7
    ');
    $stmt->execute([$since]);
    $stats['events_by_host'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
}

$topScenario = $stats['top_scenarios'][0]['scenario'] ?? '-';
$topScenarioCount = $stats['top_scenarios'][0]['count'] ?? 0;
$topCountry = $stats['top_countries'][0]['country'] ?? '-';
$topCountryCount = $stats['top_countries'][0]['count'] ?? 0;

renderPageStart($appTitle . ' - Dashboard', 'dashboard', $appTitle);
?>
    <section class="cards-grid">
        <div class="card stat-card">
            <p class="stat-label">Celkem alertu</p>
            <div class="stat-value" id="totalAlerts"><?= number_format($stats['total_alerts'], 0, ',', ' ') ?></div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Aktivni bany</p>
            <div class="stat-value" id="activeDecisions"><?= number_format($stats['active_decisions'], 0, ',', ' ') ?></div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top scenar</p>
            <div class="stat-value stat-small" id="topScenario">
                <?= htmlspecialchars((string) $topScenario) ?>
                <span class="muted">(<?= number_format((int) $topScenarioCount, 0, ',', ' ') ?>)</span>
            </div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top zeme</p>
            <div class="stat-value" id="topCountry">
                <?= htmlspecialchars((string) $topCountry) ?>
                <span class="muted">(<?= number_format((int) $topCountryCount, 0, ',', ' ') ?>)</span>
            </div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Aktivita alertu za poslednich 24 hodin</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Top scenare alertu</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="scenariosChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Alerty podle machines</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="sourcesChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Eventy podle machines</h2>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="eventsByHostChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <section class="card map-card">
        <div class="card-header map-header">
            <h2>Mapa zdroju utoku</h2>
            <div class="map-toolbar">
                <label for="mapMode">Zobrazit</label>
                <select id="mapMode">
                    <option value="alerts" selected>Alerty</option>
                    <option value="decisions">Bany</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <div class="map-split">
                <div class="map-panel">
                    <div id="worldMap" class="world-map"></div>
                </div>
                <div class="map-panel">
                    <div id="mapLegend" class="map-legend"></div>
                    <table class="data-table" id="countriesTable">
                        <thead>
                            <tr>
                                <th>Zeme</th>
                                <th>Vlajka</th>
                                <th>Pocet</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stats['top_countries'])): ?>
                                <tr><td colspan="3" class="muted">Zadna data</td></tr>
                            <?php else: ?>
                                <?php foreach ($stats['top_countries'] as $country): ?>
                                    <?php
                                    $code = strtolower((string) $country['country']);
                                    $flag = strlen($code) === 2 ? "<span class=\"fi fi-{$code}\"></span>" : '-';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $country['country']) ?></td>
                                        <td><?= $flag ?></td>
                                        <td><?= number_format((int) $country['count'], 0, ',', ' ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Top IP adresy</h2>
            </div>
            <div class="card-body">
                <table class="data-table" id="ipsTable">
                    <thead>
                        <tr>
                            <th>IP adresa</th>
                            <th>Pocet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['top_ips'])): ?>
                            <tr><td colspan="2" class="muted">Zadna data</td></tr>
                        <?php else: ?>
                            <?php foreach ($stats['top_ips'] as $ip): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $ip['ip']) ?></td>
                                    <td><?= number_format((int) $ip['count'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Prehled dashboardu</h2>
            </div>
            <div class="card-body">
                <p class="muted">
                    Dashboard zobrazuje alerty za nastaveny lookback, rozpad podle machines, scenaru a geografickeho puvodu utoku.
                    Kliknuti na graf nebo mapu pouzije interaktivni filtr.
                </p>
            </div>
        </div>
    </section>

    <script>
    window.dashboardBootstrap = <?= json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof loadDashboard === 'function') {
            loadDashboard();
        }
    });
    </script>
<?php
renderPageEnd();
