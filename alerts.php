<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/alerts_helper.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$userRole = $_SESSION['user_role'] ?? 'viewer';
$user = $_SESSION['username'] ?? 'unknown';

$filterSessionKey = 'alertsfilters';
initFilterSession($filterSessionKey);

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
$sortDir = $_GET['dir'] ?? 'desc';
$sortConfig = buildAlertsSort($sort, $sortDir, $sortableColumns);
$sort = $sortConfig['sort'];
$sortDir = $sortConfig['dir'];
$orderBy = $sortConfig['orderby'];

$getSortIcon = function (string $column) use ($sort, $sortDir): string {
    if ($sort !== $column) {
        return 'fa-sort';
    }
    return $sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
};

$filters = [
    'ip' => trim((string) getFilterValue('ip', $filterSessionKey)),
    'scenario' => trim((string) getFilterValue('scenario', $filterSessionKey)),
    'country' => trim((string) getFilterValue('country', $filterSessionKey)),
    'datefrom' => trim((string) getFilterValue('datefrom', $filterSessionKey)),
    'dateto' => trim((string) getFilterValue('dateto', $filterSessionKey)),
    'simulated' => (string) getFilterValue('simulated', $filterSessionKey)
];

$params = [];

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $appEnv['ITEMS_PER_PAGE'];

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

    $totalItems = countAlerts($db, $filters);
    $totalPages = max(1, (int) ceil($totalItems / $appEnv['ITEMS_PER_PAGE']));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $appEnv['ITEMS_PER_PAGE'];

    $params = [];
    $sql = buildAlertsQuery($filters, $params, [
        'orderby' => $orderBy,
        'limit' => $appEnv['ITEMS_PER_PAGE'],
        'offset' => $offset,
    ]);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activeDecisions = getActiveDecisionsForAlerts($db, array_map('intval', array_column($alerts, 'id')));
    $stats = getAlertsStats($db, $filters);
} catch (Exception $e) {
    error_log('Alerts page error: ' . $e->getMessage());
}

$filterQuery = array_filter([
    'scenario' => $filters['scenario'],
    'ip' => $filters['ip'],
    'country' => $filters['country'],
    'datefrom' => $filters['datefrom'],
    'dateto' => $filters['dateto'],
    'simulated' => $filters['simulated'],
]);

$buildQuery = function (string $column) use ($sort, $sortDir, $filterQuery): string {
    $nextDir = ($sort === $column && $sortDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($filterQuery, [
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1
    ]);
    return '?' . buildQueryString($params);
};

$filterDefinitions = [
    'scenario' => [
        'key' => 'scenario',
        'type' => 'text',
        'label' => 'Scénář',
        'icon' => 'fas fa-layer-group',
        'placeholder' => 'např. ssh-bf',
        'value' => $filters['scenario'],
        'class' => 'filter-group',
        'max_width' => 200,
    ],
    'ip' => [
        'key' => 'ip',
        'type' => 'text',
        'label' => 'IP adresa',
        'icon' => 'fas fa-network-wired',
        'placeholder' => 'např. 192.168.1.1',
        'value' => $filters['ip'],
        'class' => 'filter-group',
        'max_width' => 140,
    ],
    'country' => [
        'key' => 'country',
        'type' => 'text',
        'label' => 'Země',
        'icon' => 'fas fa-flag',
        'placeholder' => 'např. CZ',
        'value' => $filters['country'],
        'class' => 'filter-group',
        'max_width' => 80,
    ],
    'date_from' => [
        'key' => 'datefrom',
        'type' => 'date',
        'label' => 'Datum od',
        'icon' => 'fas fa-calendar',
        'value' => $filters['datefrom'],
        'class' => 'filter-group',
        'max_width' => 160,
    ],
    'date_to' => [
        'key' => 'dateto',
        'type' => 'date',
        'label' => 'Datum do',
        'icon' => 'fas fa-calendar',
        'value' => $filters['dateto'],
        'class' => 'filter-group',
        'max_width' => 160,
    ],
    'simulated' => [
        'key' => 'simulated',
        'type' => 'select',
        'label' => 'Simulované',
        'icon' => 'fas fa-flask',
        'value' => $filters['simulated'],
        'class' => 'filter-group',
        'max_width' => 140,
        'options' => [
            '' => 'Všechny',
            '1' => 'Ano',
            '0' => 'Ne',
        ],
    ],
    '_meta' => [
        'form_id' => 'alertFilterForm',
        'reset_url' => '/alerts.php?reset_filters=1',
    ],
];

renderPageStart($appTitle . ' - Alerts', 'alerts', $appTitle);
//$refreshQuery = $buildQuery();
//$refreshUrl = $refreshQuery === '' ? '/alerts.php' : '/alerts.php?' . $refreshQuery;
?>
    <div class="container">
        <section class="page-header">
            <div>
                <h1>Alerty</h1>
                <p class="muted">Přehled všech incidentů v CrowdSec. Celkem <strong><?= $totalItems ?></strong> alertů.</p>
            </div>
        </section>

        <?= renderSearchFilters($filterDefinitions) ?>

        <section class="card">
            <table class="data-table data-table-compact alerts-table" id="alertsTable">
                <?php
                echo renderMessagesTableHeader([
                    'sort' => $sort,
                    'buildSortLink' => fn(string $column) => '/alerts.php' . $buildQuery($column),
                    'getSortIcon' => $getSortIcon,
                    'columns' => [
                        'created_at',
                        'started_at',
                        'stopped_at',
                        'scenario',
                        'source_ip',
                        'source_country',
                        'events_count',
                        ['key' => 'simulated', 'sortable' => false],
                        ['key' => 'actions', 'label' => 'Akce', 'sortable' => false],
                    ],
                ]);
                ?>
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
                            $createdAt = formatDateTime($alert['created_at'], '-');
                            $startedAt = formatDateTime($alert['started_at'], '-');
                            $stoppedAt = formatDateTime($alert['stopped_at'], '-');
                            $countryCode = strtolower( $alert['source_country']);
                            $countryTitle = $countryCode !== '' ? strtoupper($countryCode) : '-';
                            $countryLink = $countryCode !== ''
                                ? '?' . buildQueryString(array_merge($filters, ['country' => $countryCode, 'page' => 1]))
                                : '';
                            $flag = $countryCode !== ''
                                ? '<span class="fi fi-' . htmlspecialchars($countryCode) . '" title="' . htmlspecialchars($countryTitle) . '"></span>'
                                : '-';

                            ?>
                            <tr data-alert-id="<?= $alertId ?>" data-decision-id="<?= $decisionId ? (int) $decisionId : '' ?>" data-source-ip="<?= safe_html($sourceIp) ?>">
                                <td><?= safe_html($createdAt) ?></td>
                                <td><?= safe_html($startedAt) ?></td>
                                <td><?= safe_html($stoppedAt) ?></td>
                                <td><?= safe_html((string) $alert['scenario']) ?></td>
                                <td><?= safe_html((string) ($alert['source_ip'] ?? '-')) ?></td>
                                <td class="text-center">
                                    <?php if ($countryLink !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($countryLink); ?>" class="country-link" title="<?php echo htmlspecialchars(__('filter_by_country', ['country' => $countryTitle])); ?>">
                                            <?php echo $flag; ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $flag; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $alert['events_count'] ?></td>
                                <td><?= (int) $alert['simulated'] === 1 ? 'Ano' : 'Ne' ?></td>
                                <td>
                                    <div class="table-actions">
                                        <button type="button" class="icon-btn icon-btn-primary" onclick="void viewAlert(<?= $alertId ?>)" aria-label="Detail" title="Detail">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button type="button" class="icon-btn <?= $banClass ?>" onclick="toggleAlertDecision(<?= $alertId ?>)" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($banLabel) ?>" title="<?= htmlspecialchars($banLabel) ?>">
                                            <i class="fa-solid <?= $banIcon ?>"></i>
                                        </button>
                                        <button type="button" class="icon-btn icon-btn-primary" onclick="extendAlertDecision(<?= $alertId ?>)" <?= $extendDisabled ? 'disabled' : '' ?> aria-label="Prodloužit ban" title="Prodloužit ban">
                                            <i class="fa-solid fa-clock"></i>
                                        </button>
                                        <button type="button" class="icon-btn icon-btn-success" onclick="addAlertIpToWhitelist('<?= htmlspecialchars($sourceIp, ENT_QUOTES) ?>')" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="Whitelist" title="Whitelist">
                                            <i class="fa-solid fa-shield"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <div class="modal" id="alertModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavřít detail alertu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div id="alertDetail"></div>
            </div>
        </div>

        <div class="modal" id="ipIntelModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavřít detail IP">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div id="ipIntelDetail"></div>
            </div>
        </div>
    </div>
    <?= renderPagination([
        'current' => $page,
        'total' => $totalPages,
        'buildQuery' => $buildQuery,
        'baseUrl' => '/alerts.php',
    ]) ?>
<?php
renderPageEnd();
