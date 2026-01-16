<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$lookbackMs = parseLookbackToMs($env['LOOKBACK_PERIOD'] ?? '7d');
$since = date('Y-m-d H:i:s', (time() * 1000 - $lookbackMs) / 1000);

$stats = [
    'total_alerts' => 0,
    'active_decisions' => 0,
    'top_scenarios' => [],
    'top_countries' => [],
    'top_ips' => [],
    'alerts_by_host' => [],
    'timeline_24h' => []
];

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare('SELECT COUNT(*) FROM alerts WHERE created_at >= ?');
    $stmt->execute([$since]);
    $stats['total_alerts'] = (int) $stmt->fetchColumn();

    $stmt = $db->query('SELECT COUNT(*) FROM decisions WHERE until > NOW()');
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
    $stats['top_scenarios'] = $stmt->fetchAll();

    $stmt = $db->prepare('
        SELECT source_country as country, COUNT(*) as count
        FROM alerts
        WHERE created_at >= ? AND source_country IS NOT NULL
        GROUP BY source_country
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_countries'] = $stmt->fetchAll();

    $stmt = $db->prepare('
        SELECT source_ip as ip, COUNT(*) as count
        FROM alerts
        WHERE created_at >= ? AND source_ip IS NOT NULL
        GROUP BY source_ip
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['top_ips'] = $stmt->fetchAll();

    $stmt = $db->query('
        SELECT
            DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
            COUNT(*) as count
        FROM alerts
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY hour
        ORDER BY hour
    ');
    $stats['timeline_24h'] = $stmt->fetchAll();

    $stmt = $db->prepare('
        SELECT COALESCE(m.machine_id, "Neznámý") as host, COUNT(*) as count
        FROM alerts a
        LEFT JOIN machines m ON a.machine_alerts = m.id
        WHERE a.created_at >= ?
        GROUP BY m.machine_id
        ORDER BY count DESC
        LIMIT 10
    ');
    $stmt->execute([$since]);
    $stats['alerts_by_host'] = $stmt->fetchAll();
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
            <p class="stat-label">Celkem alertů</p>
            <div class="stat-value"><?= number_format($stats['total_alerts'], 0, ',', ' ') ?></div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Aktivní bany</p>
            <div class="stat-value"><?= number_format($stats['active_decisions'], 0, ',', ' ') ?></div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top scénář</p>
            <div class="stat-value stat-small">
                <?= htmlspecialchars((string) $topScenario) ?>
                <span class="muted">(<?= number_format((int) $topScenarioCount, 0, ',', ' ') ?>)</span>
            </div>
        </div>
        <div class="card stat-card">
            <p class="stat-label">Top země</p>
            <div class="stat-value">
                <?= htmlspecialchars((string) $topCountry) ?>
                <span class="muted">(<?= number_format((int) $topCountryCount, 0, ',', ' ') ?>)</span>
            </div>
        </div>
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Top 10 scénářů</h2>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Scénář</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['top_scenarios'])): ?>
                            <tr><td colspan="2" class="muted">Žádná data</td></tr>
                        <?php else: ?>
                            <?php foreach ($stats['top_scenarios'] as $scenario): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $scenario['scenario']) ?></td>
                                    <td><?= number_format((int) $scenario['count'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2>Top 10 zemí</h2>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Země</th>
                            <th>Vlajka</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['top_countries'])): ?>
                            <tr><td colspan="3" class="muted">Žádná data</td></tr>
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
    </section>

    <section class="grid-2">
        <div class="card">
            <div class="card-header">
                <h2>Top 10 IP adres</h2>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>IP adresa</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['top_ips'])): ?>
                            <tr><td colspan="2" class="muted">Žádná data</td></tr>
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
                <h2>Alerty podle hostů</h2>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Host</th>
                            <th>Počet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats['alerts_by_host'])): ?>
                            <tr><td colspan="2" class="muted">Žádná data</td></tr>
                        <?php else: ?>
                            <?php foreach ($stats['alerts_by_host'] as $host): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $host['host']) ?></td>
                                    <td><?= number_format((int) $host['count'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h2>Aktivita za posledních 24 hodin</h2>
        </div>
        <div class="card-body">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Hodina</th>
                        <th>Počet alertů</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stats['timeline_24h'])): ?>
                        <tr><td colspan="2" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($stats['timeline_24h'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $row['hour']) ?></td>
                                <td><?= number_format((int) $row['count'], 0, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
renderPageEnd();
