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
    'created_at' => 'alerts.created_at',
    'scenario' => 'alerts.scenario',
    'source_ip' => 'alerts.source_ip',
    'ip_repeat_count' => 'ip_repeats.repeat_count',
    'hostname' => 'm.machine_id',
    'source_country' => 'alerts.source_country',
    'events_count' => 'alerts.events_count',
    'started_at' => 'alerts.started_at'
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
    'hostname' => trim((string) getFilterValue('hostname', $filterSessionKey)),
    'repeat_count' => trim((string) getFilterValue('repeat_count', $filterSessionKey)),
    'decision_state' => trim((string) getFilterValue('decision_state', $filterSessionKey)),
    'datefrom' => trim((string) getFilterValue('datefrom', $filterSessionKey)),
    'dateto' => trim((string) getFilterValue('dateto', $filterSessionKey)),
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
    'hostname' => $filters['hostname'],
    'repeat_count' => $filters['repeat_count'],
    'decision_state' => $filters['decision_state'],
    'datefrom' => $filters['datefrom'],
    'dateto' => $filters['dateto'],
]);

$buildSortQuery = function (string $column) use ($sort, $sortDir, $filterQuery): string {
    $nextDir = ($sort === $column && $sortDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($filterQuery, [
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1
    ]);
    return '?' . buildQueryString($params);
};

$buildPaginationQuery = function (array $params) use ($filterQuery, $sort, $sortDir): string {
    $queryParams = array_merge($filterQuery, [
        'sort' => $sort,
        'dir' => $sortDir,
    ], $params);

    return buildQueryString($queryParams);
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
    'hostname' => [
        'key' => 'hostname',
        'type' => 'text',
        'label' => 'Hostname',
        'icon' => 'fas fa-server',
        'placeholder' => 'např. mail.example.cz',
        'value' => $filters['hostname'],
        'class' => 'filter-group',
        'max_width' => 180,
    ],
    'repeat_count' => [
        'key' => 'repeat_count',
        'type' => 'select',
        'label' => 'Opakování',
        'icon' => 'fas fa-repeat',
        'value' => $filters['repeat_count'],
        'class' => 'filter-group',
        'max_width' => 140,
        'options' => [
            '' => 'Všechny',
            '2+' => '2× a více',
            '3+' => '3× a více',
            '4+' => '4× a více',
            '5+' => '5× a více',
            '6+' => '6× a více',
            '7+' => '7× a více',
            '8+' => '8× a více',
            '9+' => '9× a více',
            '10+' => '10× a více',
        ],
    ],
    'decision_state' => [
        'key' => 'decision_state',
        'type' => 'select',
        'label' => 'Decision',
        'icon' => 'fas fa-gavel',
        'value' => $filters['decision_state'],
        'class' => 'filter-group',
        'max_width' => 180,
        'options' => [
            '' => 'Všechny',
            'active' => 'Aktivní decision',
            'inactive' => 'Neaktivní decision',
        ],
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
                    'buildSortLink' => fn(string $column) => '/alerts.php' . $buildSortQuery($column),
                    'getSortIcon' => $getSortIcon,
                    'columns' => [
                        'created_at',
                        'started_at',
                        'scenario',
                        'source_ip',
                        'ip_repeat_count',
                        'hostname',
                        'source_country',
                        'events_count',
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
                            $scenario = (string) ($alert['scenario'] ?? '');
                            $hostname = (string) ($alert['hostname'] ?? '');
                            $repeatCount = (int) ($alert['ip_repeat_count'] ?? 0);
                            $isRepeated = $repeatCount >= 2;
                            $repeatCountLabel = $repeatCount > 0 ? $repeatCount . '×' : '-';
                            $decisionId = $activeDecisions[$alertId] ?? null;
                            $hasDecision = $decisionId !== null;
                            $banLabel = $hasDecision ? 'Odebrat ban' : 'Ban';
                            $banClass = $hasDecision ? 'icon-btn-warning' : 'icon-btn-danger';
                            $banIcon = $hasDecision ? 'fa-unlock' : 'fa-ban';
                            $ipDisabled = $sourceIp === '';
                            $extendDisabled = !$hasDecision;
                            $createdAt = formatDateTime($alert['created_at'], '-');
                            $startedAt = formatDateTime($alert['started_at'], '-');
                            $durationLabel = formatAlertDurationLabel($alert['started_at'] ?? null, $alert['stopped_at'] ?? null);
                            $startedAtLabel = $startedAt === '-' ? '-' : $startedAt . ' - ' . $durationLabel;
                            $countryCode = strtolower( $alert['source_country']);
                            $countryTitle = $countryCode !== '' ? strtoupper($countryCode) : '-';
                            $countryLink = $countryCode !== ''
                                ? '?' . buildQueryString(array_merge($filters, ['country' => $countryCode, 'page' => 1]))
                                : '';
                            $flag = $countryCode !== ''
                                ? '<span class="fi fi-' . htmlspecialchars($countryCode) . '" title="' . htmlspecialchars($countryTitle) . '"></span>'
                                : '-';
                            $scenarioLink = $scenario !== ''
                                ? '?' . buildQueryString(array_merge($filters, ['scenario' => $scenario, 'page' => 1]))
                                : '';
                            $ipLink = $sourceIp !== ''
                                ? '?' . buildQueryString(array_merge($filters, ['ip' => $sourceIp, 'page' => 1]))
                                : '';
                            $hostnameLink = $hostname !== ''
                                ? '?' . buildQueryString(array_merge($filters, ['hostname' => $hostname, 'page' => 1]))
                                : '';
                            $ipHighlightClass = $isRepeated ? 'ip-repeat' : '';
                            $ipHighlightStyle = '';
                            if ($isRepeated) {
                                $repeatIntensity = min(max(($repeatCount - 2) / 8, 0), 1);
                                $ipHighlightStyle = ' style="--repeat-intensity: ' . number_format($repeatIntensity, 2, '.', '') . ';"';
                            }

                            ?>
                            <tr data-alert-id="<?= $alertId ?>" data-decision-id="<?= $decisionId ? (int) $decisionId : '' ?>" data-source-ip="<?= safe_html($sourceIp) ?>">
                                <td><?= safe_html($createdAt) ?></td>
                                <td><?= safe_html($startedAtLabel) ?></td>
                                <td>
                                    <?php if ($scenarioLink !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($scenarioLink); ?>" class="filter-link" title="Filtrovat podle scénáře">
                                            <?php echo safe_html($scenario); ?>
                                        </a>
                                    <?php else: ?>
                                        <?= safe_html($scenario !== '' ? $scenario : '-') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ipLink !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($ipLink); ?>" class="filter-link <?= $ipHighlightClass ?>"<?= $ipHighlightStyle ?> title="Filtrovat podle IP">
                                            <?php echo safe_html($sourceIp); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php if ($sourceIp !== ''): ?>
                                            <span class="<?= $ipHighlightClass ?>"<?= $ipHighlightStyle ?>><?= safe_html($sourceIp) ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= safe_html($repeatCountLabel) ?></td>
                                <td>
                                    <?php if ($hostnameLink !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($hostnameLink); ?>" class="filter-link" title="Filtrovat podle hostname">
                                            <?php echo safe_html($hostname); ?>
                                        </a>
                                    <?php else: ?>
                                        <?= safe_html($hostname !== '' ? $hostname : '-') ?>
                                    <?php endif; ?>
                                </td>
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
                                <td>
                                    <div class="table-actions">
                                        <button type="button" class="icon-btn icon-btn-primary" onclick="void viewAlert(<?= $alertId ?>)" aria-label="Detail" title="Detail">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button type="button" class="icon-btn <?= $banClass ?>" onclick="void toggleAlertDecision(<?= $alertId ?>)" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($banLabel) ?>" title="<?= htmlspecialchars($banLabel) ?>">
                                            <i class="fa-solid <?= $banIcon ?>"></i>
                                        </button>
                                        <button type="button" class="icon-btn icon-btn-primary" onclick="void extendAlertDecision(<?= $alertId ?>)" <?= $extendDisabled ? 'disabled' : '' ?> aria-label="Prodloužit ban" title="Prodloužit ban">
                                            <i class="fa-solid fa-clock"></i>
                                        </button>
                                        <button type="button" class="icon-btn icon-btn-success" onclick="void addAlertIpToWhitelist('<?= htmlspecialchars($sourceIp, ENT_QUOTES) ?>')" <?= $ipDisabled ? 'disabled' : '' ?> aria-label="Whitelist" title="Whitelist">
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
        'buildQuery' => $buildPaginationQuery,
        'baseUrl' => '/alerts.php',
    ]) ?>
<?php
renderPageEnd();
