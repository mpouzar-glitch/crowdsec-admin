<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$lookbackMs = parseLookbackToMs($env['LOOKBACK_PERIOD'] ?? '7d');
$since = date('Y-m-d H:i:s', (time() * 1000 - $lookbackMs) / 1000);
$repeatedWindowSeconds = 5 * 60;

$filters = [
    'scenario' => trim((string) ($_GET['scenario'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'machine' => trim((string) ($_GET['machine'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'repeated' => isset($_GET['repeated']) && $_GET['repeated'] === '1'
];

$sortKey = trim((string) ($_GET['sort'] ?? 'created_at'));
$sortDir = strtolower(trim((string) ($_GET['direction'] ?? 'desc')));
$direction = $sortDir === 'asc' ? 'ASC' : 'DESC';
$sortMap = [
    'created_at' => 'a.created_at',
    'duration' => 'duration_seconds',
    'decision_until' => 'decision_until_active',
    'scenario' => 'a.scenario',
    'machine' => 'm.machine_id',
    'source_ip' => 'a.source_ip',
    'source_country' => 'a.source_country',
    'events_count' => 'a.events_count'
];
$orderBy = $sortMap[$sortKey] ?? 'a.created_at';

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));

$alerts = [];
$totalAlerts = 0;
$totalPages = 1;
$filterOptions = [
    'scenarios' => [],
    'ips' => [],
    'countries' => [],
    'machines' => []
];

try {
    $db = Database::getInstance()->getConnection();

    $filterOptions['scenarios'] = $db->query("
        SELECT DISTINCT scenario
        FROM alerts
        WHERE scenario IS NOT NULL AND scenario != ''
        ORDER BY scenario
    ")->fetchAll(PDO::FETCH_COLUMN);

    $filterOptions['ips'] = $db->query("
        SELECT DISTINCT source_ip
        FROM alerts
        WHERE source_ip IS NOT NULL AND source_ip != ''
        ORDER BY source_ip
    ")->fetchAll(PDO::FETCH_COLUMN);

    $filterOptions['countries'] = $db->query("
        SELECT DISTINCT source_country
        FROM alerts
        WHERE source_country IS NOT NULL AND source_country != ''
        ORDER BY source_country
    ")->fetchAll(PDO::FETCH_COLUMN);

    $filterOptions['machines'] = $db->query("
        SELECT DISTINCT m.machine_id
        FROM alerts a
        LEFT JOIN machines m ON m.id = a.machine_alerts
        WHERE m.machine_id IS NOT NULL AND m.machine_id != ''
        ORDER BY m.machine_id
    ")->fetchAll(PDO::FETCH_COLUMN);

    $conditions = ['a.created_at >= :since'];
    $params = [
        ':since' => $since,
        ':repeated_window' => $repeatedWindowSeconds
    ];

    if ($filters['scenario'] !== '') {
        $conditions[] = 'LOWER(a.scenario) LIKE :scenario';
        $params[':scenario'] = '%' . strtolower($filters['scenario']) . '%';
    }

    if ($filters['ip'] !== '') {
        $conditions[] = 'LOWER(a.source_ip) LIKE :ip';
        $params[':ip'] = '%' . strtolower($filters['ip']) . '%';
    }

    if ($filters['machine'] !== '') {
        $conditions[] = 'LOWER(m.machine_id) LIKE :machine';
        $params[':machine'] = '%' . strtolower($filters['machine']) . '%';
    }

    if ($filters['country'] !== '') {
        $conditions[] = 'LOWER(a.source_country) LIKE :country';
        $params[':country'] = '%' . strtolower($filters['country']) . '%';
    }

    if ($filters['repeated']) {
        $conditions[] = 'repeated.scenario IS NOT NULL';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $conditions);

    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT a.id
            FROM alerts a
            LEFT JOIN decisions d ON d.alert_decisions = a.id
            LEFT JOIN machines m ON m.id = a.machine_alerts
            LEFT JOIN (
                SELECT scenario, source_ip
                FROM alerts
                WHERE scenario IS NOT NULL AND source_ip IS NOT NULL
                GROUP BY scenario, source_ip
                HAVING COUNT(*) > 1
                   AND TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) >= :repeated_window
            ) repeated
                ON repeated.scenario = a.scenario
                AND repeated.source_ip = a.source_ip
            {$whereSql}
            GROUP BY a.id
        ) as filtered_alerts
    ");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalAlerts = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalAlerts / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("
        SELECT
            a.id,
            a.created_at,
            a.started_at,
            a.stopped_at,
            a.scenario,
            a.events_count,
            a.source_ip,
            a.source_country,
            m.machine_id as machine_id,
            MIN(CASE WHEN d.until >= NOW() THEN d.until END) as decision_until_active,
            MAX(d.until) as decision_until,
            CASE
                WHEN a.started_at IS NULL OR a.stopped_at IS NULL THEN NULL
                ELSE TIMESTAMPDIFF(SECOND, a.started_at, a.stopped_at)
            END as duration_seconds,
            CASE WHEN repeated.scenario IS NULL THEN 0 ELSE 1 END as is_repeated
        FROM alerts a
        LEFT JOIN decisions d ON d.alert_decisions = a.id
        LEFT JOIN machines m ON m.id = a.machine_alerts
        LEFT JOIN (
            SELECT scenario, source_ip
            FROM alerts
            WHERE scenario IS NOT NULL AND source_ip IS NOT NULL
            GROUP BY scenario, source_ip
            HAVING COUNT(*) > 1
               AND TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) >= :repeated_window
        ) repeated
            ON repeated.scenario = a.scenario
            AND repeated.source_ip = a.source_ip
        {$whereSql}
        GROUP BY a.id
        ORDER BY {$orderBy} {$direction}, a.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Alerts page error: ' . $e->getMessage());
}

$buildQuery = function (array $overrides = []) use ($filters, $sortKey, $direction, $perPage) {
    $query = array_merge([
        'scenario' => $filters['scenario'],
        'ip' => $filters['ip'],
        'machine' => $filters['machine'],
        'country' => $filters['country'],
        'repeated' => $filters['repeated'] ? '1' : null,
        'sort' => $sortKey,
        'direction' => strtolower($direction),
        'per_page' => $perPage
    ], $overrides);

    $query = array_filter($query, function ($value) {
        return $value !== null && $value !== '';
    });

    return http_build_query($query);
};

renderPageStart($appTitle . ' - Alerts', 'alerts', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Alerty</h1>
            <p class="muted">Přehled všech incidentů v CrowdSec. Celkem <strong><?= $totalAlerts ?></strong> alertů.</p>
        </div>
        <div class="toolbar">
            <a class="btn" href="/alerts.php?<?= htmlspecialchars($buildQuery()) ?>">Obnovit</a>
        </div>
    </section>

    <form class="table-filters" method="get">
        <div class="filter-group">
            <label for="alertFilterScenario"><i class="fa-solid fa-layer-group"></i> Scénář</label>
            <input type="text" id="alertFilterScenario" name="scenario" list="alertScenarioList" placeholder="např. ssh-bf" value="<?= htmlspecialchars($filters['scenario']) ?>">
            <datalist id="alertScenarioList">
                <?php foreach ($filterOptions['scenarios'] as $scenario): ?>
                    <option value="<?= htmlspecialchars($scenario) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="alertFilterIp"><i class="fa-solid fa-network-wired"></i> IP adresa</label>
            <input type="text" id="alertFilterIp" name="ip" list="alertIpList" placeholder="např. 192.168.1.1" value="<?= htmlspecialchars($filters['ip']) ?>">
            <datalist id="alertIpList">
                <?php foreach ($filterOptions['ips'] as $ip): ?>
                    <option value="<?= htmlspecialchars($ip) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="alertFilterMachine"><i class="fa-solid fa-server"></i> Machine</label>
            <select id="alertFilterMachine" name="machine">
                <option value="">Všechny machine</option>
                <?php foreach ($filterOptions['machines'] as $machine): ?>
                    <option value="<?= htmlspecialchars($machine) ?>" <?= $filters['machine'] === $machine ? 'selected' : '' ?>>
                        <?= htmlspecialchars($machine) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="alertFilterCountry"><i class="fa-solid fa-flag"></i> Země</label>
            <input type="text" id="alertFilterCountry" name="country" list="alertCountryList" placeholder="např. CZ" value="<?= htmlspecialchars($filters['country']) ?>">
            <datalist id="alertCountryList">
                <?php foreach ($filterOptions['countries'] as $country): ?>
                    <option value="<?= htmlspecialchars($country) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group checkbox">
            <label for="alertFilterRepeated">
                <input type="checkbox" id="alertFilterRepeated" name="repeated" value="1" <?= $filters['repeated'] ? 'checked' : '' ?>>
                <i class="fa-solid fa-repeat"></i> Pouze opakující se alerty
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="submit">Filtrovat</button>
            <a class="btn btn-ghost" href="/alerts.php">Vyčistit filtry</a>
        </div>
    </form>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact alerts-table" id="alertsTable">
                <thead>
                    <tr>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'created_at', 'direction' => $sortKey === 'created_at' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Čas</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'duration', 'direction' => $sortKey === 'duration' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Trvání útoku</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'decision_until', 'direction' => $sortKey === 'decision_until' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Konec decision</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'scenario', 'direction' => $sortKey === 'scenario' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Scénář</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'machine', 'direction' => $sortKey === 'machine' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Machine</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'source_ip', 'direction' => $sortKey === 'source_ip' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">IP adresa</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'source_country', 'direction' => $sortKey === 'source_country' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Země</a></th>
                        <th><a href="/alerts.php?<?= htmlspecialchars($buildQuery(['sort' => 'events_count', 'direction' => $sortKey === 'events_count' && strtolower($direction) === 'asc' ? 'desc' : 'asc'])) ?>">Počet událostí</a></th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alerts)): ?>
                        <tr><td colspan="9" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                            <?php
                                $decisionUntil = $alert['decision_until_active'] ?? null;
                                $decisionLabel = $decisionUntil ? formatDateTime($decisionUntil) : '-';
                                $decisionClass = 'decision-until decision-until-none';
                                if ($decisionUntil) {
                                    $remaining = strtotime((string) $decisionUntil) - time();
                                    if ($remaining <= 0) {
                                        $decisionClass = 'decision-until decision-until-expired';
                                    } elseif ($remaining <= 3600) {
                                        $decisionClass = 'decision-until decision-until-danger';
                                    } elseif ($remaining <= 6 * 3600) {
                                        $decisionClass = 'decision-until decision-until-warning';
                                    } else {
                                        $decisionClass = 'decision-until decision-until-ok';
                                    }
                                }
                            ?>
                            <tr class="<?= $alert['is_repeated'] ? 'alert-repeated' : '' ?>">
                                <td><?= htmlspecialchars(formatDateTime($alert['created_at'])) ?></td>
                                <td><?= htmlspecialchars(formatAlertDuration($alert['started_at'], $alert['stopped_at'])) ?></td>
                                <td><span class="<?= $decisionClass ?>"><?= htmlspecialchars($decisionLabel) ?></span></td>
                                <td><?= htmlspecialchars((string) $alert['scenario']) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['machine_id'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['source_ip'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['source_country'] ?? '-')) ?></td>
                                <td><?= (int) $alert['events_count'] ?></td>
                                <td><a class="btn btn-ghost btn-small" href="/api/alerts?id=<?= urlencode((string) $alert['id']) ?>" target="_blank" rel="noopener">Detail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
                $pages = buildPaginationPages($page, $totalPages);
                $prevPage = max(1, $page - 1);
                $nextPage = min($totalPages, $page + 1);
            ?>
            <a class="pagination-link <?= $page === 1 ? 'disabled' : '' ?>" href="/alerts.php?<?= htmlspecialchars($buildQuery(['page' => $prevPage])) ?>">&laquo; Předchozí</a>
            <?php foreach ($pages as $pageNumber): ?>
                <?php if ($pageNumber === '...'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php else: ?>
                    <a class="pagination-link <?= (int) $pageNumber === $page ? 'active' : '' ?>" href="/alerts.php?<?= htmlspecialchars($buildQuery(['page' => $pageNumber])) ?>">
                        <?= $pageNumber ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="pagination-link <?= $page === $totalPages ? 'disabled' : '' ?>" href="/alerts.php?<?= htmlspecialchars($buildQuery(['page' => $nextPage])) ?>">Další &raquo;</a>
        </div>
    <?php endif; ?>
<?php
renderPageEnd();
