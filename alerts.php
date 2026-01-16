<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$userRole = $_SESSION['user_role'] ?? 'viewer';
$user = $_SESSION['username'] ?? 'unknown';

$sortableColumns = [
    'created_at' => 'created_at',
    'scenario' => 'scenario',
    'source_ip' => 'source_ip',
    'source_country' => 'source_country',
    'events_count' => 'events_count',
    'started_at' => 'started_at',
    'stopped_at' => 'stopped_at'
];

$sort = $_GET['sort'] ?? 'created_at';
$sortDir = strtolower($_GET['dir'] ?? 'desc');

if (!isset($sortableColumns[$sort])) {
    $sort = 'created_at';
}

$sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
$orderBy = $sortableColumns[$sort] . ' ' . strtoupper($sortDir);

$sortParams = array_diff_key($_GET, ['page' => '', 'sort' => '', 'dir' => '']);

$buildSortLink = function (string $column) use ($sort, $sortDir, $sortParams): string {
    $nextDir = ($sort === $column && $sortDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($sortParams, [
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1
    ]);
    return '?' . http_build_query($params);
};

$getSortIcon = function (string $column) use ($sort, $sortDir): string {
    if ($sort !== $column) {
        return 'fa-sort';
    }
    return $sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
};

$filters = [
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'scenario' => trim((string) ($_GET['scenario'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'simulated' => isset($_GET['simulated']) ? (string) $_GET['simulated'] : ''
];

$whereConditions = [];
$params = [];

if ($filters['ip'] !== '') {
    $whereConditions[] = 'source_ip = :ip';
    $params[':ip'] = $filters['ip'];
}

if ($filters['scenario'] !== '') {
    $whereConditions[] = 'scenario LIKE :scenario';
    $params[':scenario'] = '%' . $filters['scenario'] . '%';
}

if ($filters['country'] !== '') {
    $whereConditions[] = 'source_country = :country';
    $params[':country'] = $filters['country'];
}

if ($filters['date_from'] !== '') {
    $whereConditions[] = 'created_at >= :date_from';
    $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
}

if ($filters['date_to'] !== '') {
    $whereConditions[] = 'created_at <= :date_to';
    $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
}

if ($filters['simulated'] !== '') {
    $whereConditions[] = 'simulated = :simulated';
    $params[':simulated'] = (int) $filters['simulated'];
}

define('ITEMS_PER_PAGE', 50);
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * ITEMS_PER_PAGE;

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

$alerts = [];
$totalItems = 0;
$totalPages = 1;
$activeDecisions = [];
$stats = [
    'total_alerts' => 0,
    'unique_ips' => 0,
    'unique_scenarios' => 0,
    'total_events' => 0,
    'simulated_alerts' => 0
];

try {
    $db = Database::getInstance()->getConnection();

    $countSql = "SELECT COUNT(*) as total FROM alerts {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = max(1, (int) ceil($totalItems / ITEMS_PER_PAGE));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * ITEMS_PER_PAGE;

    $sql = "SELECT * FROM alerts
        {$whereClause}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', ITEMS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activeDecisions = [];
    if (!empty($alerts)) {
        $alertIds = array_map('intval', array_column($alerts, 'id'));
        $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
        $decisionStmt = $db->prepare("
            SELECT id, alert_decisions, `until`
            FROM decisions
            WHERE alert_decisions IN ($placeholders)
              AND `until` > NOW()
            ORDER BY `until` DESC
        ");
        $decisionStmt->execute($alertIds);
        $decisions = $decisionStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($decisions as $decision) {
            $alertId = (int) $decision['alert_decisions'];
            if (!isset($activeDecisions[$alertId])) {
                $activeDecisions[$alertId] = (int) $decision['id'];
            }
        }
    }

    $statsSql = "SELECT
        COUNT(*) as total_alerts,
        COUNT(DISTINCT source_ip) as unique_ips,
        COUNT(DISTINCT scenario) as unique_scenarios,
        SUM(events_count) as total_events,
        COUNT(CASE WHEN simulated = 1 THEN 1 END) as simulated_alerts
        FROM alerts {$whereClause}";

    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Alerts page error: ' . $e->getMessage());
}

$buildQuery = function (array $overrides = []) use ($filters, $sort, $sortDir) {
    $query = array_merge([
        'ip' => $filters['ip'],
        'scenario' => $filters['scenario'],
        'country' => $filters['country'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'simulated' => $filters['simulated'],
        'sort' => $sort,
        'dir' => $sortDir
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
            <p class="muted">Přehled všech incidentů v CrowdSec. Celkem <strong><?= $totalItems ?></strong> alertů.</p>
        </div>
        <div class="toolbar">
            <a class="btn" href="/alerts.php?<?= htmlspecialchars($buildQuery()) ?>">Obnovit</a>
        </div>
    </section>

    <?= renderFilterForm([
        'resetUrl' => '/alerts.php',
        'fields' => [
            [
                'name' => 'scenario',
                'id' => 'alertFilterScenario',
                'labelHtml' => '<i class="fa-solid fa-layer-group"></i> Scénář',
                'placeholder' => 'např. ssh-bf',
                'value' => $filters['scenario'],
            ],
            [
                'name' => 'ip',
                'id' => 'alertFilterIp',
                'labelHtml' => '<i class="fa-solid fa-network-wired"></i> IP adresa',
                'placeholder' => 'např. 192.168.1.1',
                'value' => $filters['ip'],
            ],
            [
                'name' => 'country',
                'id' => 'alertFilterCountry',
                'labelHtml' => '<i class="fa-solid fa-flag"></i> Země',
                'placeholder' => 'např. CZ',
                'value' => $filters['country'],
            ],
            [
                'type' => 'date',
                'name' => 'date_from',
                'id' => 'alertFilterDateFrom',
                'labelHtml' => '<i class="fa-solid fa-calendar"></i> Datum od',
                'value' => $filters['date_from'],
            ],
            [
                'type' => 'date',
                'name' => 'date_to',
                'id' => 'alertFilterDateTo',
                'labelHtml' => '<i class="fa-solid fa-calendar"></i> Datum do',
                'value' => $filters['date_to'],
            ],
            [
                'type' => 'select',
                'name' => 'simulated',
                'id' => 'alertFilterSimulated',
                'labelHtml' => '<i class="fa-solid fa-flask"></i> Simulované',
                'options' => [
                    '' => 'Všechny',
                    '1' => 'Ano',
                    '0' => 'Ne',
                ],
                'value' => $filters['simulated'],
            ],
        ],
    ]) ?>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact alerts-table" id="alertsTable">
                <thead>
                    <tr>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('created_at')) ?>">Čas <i class="fa-solid <?= $getSortIcon('created_at') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('started_at')) ?>">Začátek <i class="fa-solid <?= $getSortIcon('started_at') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('stopped_at')) ?>">Konec <i class="fa-solid <?= $getSortIcon('stopped_at') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('scenario')) ?>">Scénář <i class="fa-solid <?= $getSortIcon('scenario') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('source_ip')) ?>">IP adresa <i class="fa-solid <?= $getSortIcon('source_ip') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('source_country')) ?>">Země <i class="fa-solid <?= $getSortIcon('source_country') ?>"></i></a></th>
                        <th><a href="/alerts.php<?= htmlspecialchars($buildSortLink('events_count')) ?>">Počet událostí <i class="fa-solid <?= $getSortIcon('events_count') ?>"></i></a></th>
                        <th>Simulované</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alerts)): ?>
                        <tr><td colspan="9" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                            <?php
                            $alertId = (int) $alert['id'];
                            $sourceIp = (string) ($alert['source_ip'] ?? '');
                            $decisionId = $activeDecisions[$alertId] ?? null;
                            $hasDecision = $decisionId !== null;
                            $banLabel = $hasDecision ? 'Odebrat ban' : 'Ban';
                            $banClass = $hasDecision ? 'icon-btn-warning' : 'icon-btn-danger';
                            $banIcon = $hasDecision ? 'fa-unlock' : 'fa-ban';
                            $ipDisabled = $sourceIp === '';
                            $extendDisabled = !$hasDecision;
                            ?>
                            <tr data-alert-id="<?= $alertId ?>" data-decision-id="<?= $decisionId ? (int) $decisionId : '' ?>" data-source-ip="<?= htmlspecialchars($sourceIp, ENT_QUOTES) ?>">
                                <td><?= htmlspecialchars(formatDateTime($alert['created_at'])) ?></td>
                                <td><?= htmlspecialchars($alert['started_at'] ? formatDateTime($alert['started_at']) : '-') ?></td>
                                <td><?= htmlspecialchars($alert['stopped_at'] ? formatDateTime($alert['stopped_at']) : '-') ?></td>
                                <td><?= htmlspecialchars((string) $alert['scenario']) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['source_ip'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($alert['source_country'] ?? '-')) ?></td>
                                <td><?= (int) $alert['events_count'] ?></td>
                                <td><?= (int) $alert['simulated'] === 1 ? 'Ano' : 'Ne' ?></td>
                                <td>
                                    <div class="table-actions">
                                        <button class="icon-btn icon-btn-primary" onclick="viewAlert(<?= $alertId ?>)" aria-label="Detail" title="Detail">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button class="icon-btn <?= $banClass ?>" onclick="toggleAlertDecision(<?= $alertId ?>)" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($banLabel) ?>" title="<?= htmlspecialchars($banLabel) ?>">
                                            <i class="fa-solid <?= $banIcon ?>"></i>
                                        </button>
                                        <button class="icon-btn icon-btn-primary" onclick="extendAlertDecision(<?= $alertId ?>)" <?= $extendDisabled ? 'disabled' : '' ?> aria-label="Prodloužit ban" title="Prodloužit ban">
                                            <i class="fa-solid fa-clock"></i>
                                        </button>
                                        <button class="icon-btn icon-btn-success" onclick="addAlertIpToWhitelist('<?= htmlspecialchars($sourceIp, ENT_QUOTES) ?>')" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="Whitelist" title="Whitelist">
                                            <i class="fa-solid fa-shield"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script>
        const alertDecisionDefaults = {
            banDuration: '4h',
            extendDuration: '4h'
        };

        function getAlertRow(alertId) {
            return document.querySelector(`tr[data-alert-id="${alertId}"]`);
        }

        function viewAlert(alertId) {
            const url = `/api/alerts?id=${encodeURIComponent(alertId)}`;
            window.open(url, '_blank', 'noopener');
        }

        async function toggleAlertDecision(alertId) {
            const row = getAlertRow(alertId);
            if (!row) {
                return;
            }

            const decisionId = row.dataset.decisionId;
            const sourceIp = row.dataset.sourceIp;

            try {
                if (decisionId) {
                    if (!confirm('Opravdu chcete odebrat ban?')) {
                        return;
                    }
                    const response = await fetch(`/api/decisions/${decisionId}`, {
                        method: 'DELETE'
                    });
                    if (!response.ok) {
                        throw new Error('Ban removal failed.');
                    }
                } else {
                    if (!sourceIp) {
                        return;
                    }
                    if (!confirm('Opravdu chcete přidat ban?')) {
                        return;
                    }
                    const response = await fetch('/api/decisions', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            ip: sourceIp,
                            duration: alertDecisionDefaults.banDuration,
                            reason: 'manual',
                            type: 'ban'
                        })
                    });
                    if (!response.ok) {
                        throw new Error('Ban creation failed.');
                    }
                }

                window.location.reload();
            } catch (error) {
                alert('Nepodařilo se změnit ban.');
            }
        }

        async function extendAlertDecision(alertId) {
            const row = getAlertRow(alertId);
            if (!row) {
                return;
            }

            const sourceIp = row.dataset.sourceIp;
            if (!sourceIp) {
                return;
            }

            if (!confirm('Opravdu chcete prodloužit ban?')) {
                return;
            }

            try {
                const response = await fetch('/api/decisions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        ip: sourceIp,
                        duration: alertDecisionDefaults.extendDuration,
                        reason: 'extend',
                        type: 'ban'
                    })
                });
                if (!response.ok) {
                    throw new Error('Ban extend failed.');
                }

                window.location.reload();
            } catch (error) {
                alert('Nepodařilo se prodloužit ban.');
            }
        }

        async function addAlertIpToWhitelist(ip) {
            if (!ip) {
                return;
            }

            if (!confirm('Opravdu chcete přidat IP do whitelistu?')) {
                return;
            }

            try {
                const response = await fetch('/api/whitelist', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        cidr: ip,
                        reason: 'manual'
                    })
                });
                if (!response.ok) {
                    throw new Error('Whitelist add failed.');
                }

                window.location.reload();
            } catch (error) {
                alert('Nepodařilo se přidat IP do whitelistu.');
            }
        }
    </script>

    <?= renderPagination([
        'current' => $page,
        'total' => $totalPages,
        'buildQuery' => $buildQuery,
        'baseUrl' => '/alerts.php',
    ]) ?>
<?php
renderPageEnd();
