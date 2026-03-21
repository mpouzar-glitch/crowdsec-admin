<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_client.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/filter_helper.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$flash = getFlashMessage();
$filterSessionKey = 'decisionsfilters';
initFilterSession($filterSessionKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $duration = trim((string) ($_POST['duration'] ?? '4h'));
        $reason = trim((string) ($_POST['reason'] ?? 'manual'));
        $type = trim((string) ($_POST['type'] ?? 'ban'));

        if ($ip === '') {
            setFlashMessage('error', 'IP adresa je povinna.');
        } else {
            try {
                $api = new CrowdSecAPI();
                $result = $api->addDecision($ip, $type, $duration, $reason);
                auditLog('decision.ban', [
                    'decision' => [
                        'value' => $ip,
                        'type' => $type,
                        'duration' => $duration,
                        'reason' => $reason,
                    ],
                    'result' => $result,
                ]);
                setFlashMessage('success', 'Ban byl uspesne pridan.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodarilo se pridat ban: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('
                    SELECT id, scenario, type, value, origin, scope, `until`, created_at
                    FROM decisions
                    WHERE id = ?
                ');
                $stmt->execute([$id]);
                $decisionDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $api = new CrowdSecAPI();
                $api->deleteDecision($id);
                auditLog('decision.unban', [
                    'id' => $id,
                    'decision' => $decisionDetails,
                ]);
                setFlashMessage('success', 'Ban byl odstraněn.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodarilo se odebrat ban: ' . $e->getMessage());
            }
        } else {
            setFlashMessage('error', 'Neplatne ID rozhodnuti.');
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$filters = [
    'value' => trim((string) getFilterValue('value', $filterSessionKey)),
    'scenario' => trim((string) getFilterValue('scenario', $filterSessionKey)),
    'type' => trim((string) getFilterValue('type', $filterSessionKey)),
    'country' => trim((string) getFilterValue('country', $filterSessionKey)),
    'include_expired' => getFilterValue('include_expired', $filterSessionKey) === '1',
    'hide_duplicates' => getFilterValue('hide_duplicates', $filterSessionKey) !== '0',
];

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));

$decisions = [];
$formatted = [];
$totalDecisions = 0;
$totalPages = 1;
$filterOptions = [
    'values' => [],
    'scenarios' => [],
    'types' => [],
    'countries' => [],
];

try {
    $db = Database::getInstance()->getConnection();

    $filterOptions['values'] = $db->query('SELECT DISTINCT value FROM decisions WHERE value IS NOT NULL AND value != "" ORDER BY value')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['scenarios'] = $db->query('SELECT DISTINCT scenario FROM decisions WHERE scenario IS NOT NULL AND scenario != "" ORDER BY scenario')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['types'] = $db->query('SELECT DISTINCT type FROM decisions WHERE type IS NOT NULL AND type != "" ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['countries'] = $db->query('SELECT DISTINCT source_country FROM alerts WHERE source_country IS NOT NULL AND source_country != "" ORDER BY source_country')->fetchAll(PDO::FETCH_COLUMN);

    $conditions = [];
    $params = [];

    if ($filters['include_expired']) {
        $conditions[] = 'd.until < UTC_TIMESTAMP()';
    } else {
        $conditions[] = 'd.until >= UTC_TIMESTAMP()';
    }

    $filterDefinitions = [
        [
            'key' => 'value',
            'column' => 'd.value',
            'operator' => 'like',
            'lowercase' => true,
        ],
        [
            'key' => 'scenario',
            'column' => 'd.scenario',
            'operator' => 'like',
            'lowercase' => true,
        ],
        [
            'key' => 'type',
            'column' => 'd.type',
            'operator' => 'like',
            'lowercase' => true,
        ],
        [
            'key' => 'country',
            'column' => 'a.source_country',
            'operator' => 'like',
            'lowercase' => true,
        ],
    ];

    $conditions = array_merge($conditions, buildFilterConditions($filters, $filterDefinitions, $params));

    $whereSql = buildWhereClause($conditions);

    $stmt = $db->prepare("
        SELECT
            d.id,
            d.uuid,
            d.created_at,
            d.until,
            d.scenario,
            d.type,
            d.value,
            d.origin,
            a.source_country,
            a.source_as_name,
            a.events_count,
            a.id as alert_id
        FROM decisions d
        LEFT JOIN alerts a ON d.alert_decisions = a.id
        WHERE {$whereSql}
        ORDER BY d.created_at DESC
    ");
    $stmt->execute($params);
    $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($decisions as $decision) {
        $untilTs = parseCrowdSecTimestamp($decision['until'] ?? null);
        $expired = $untilTs !== null && $untilTs < time();
        $formatted[] = [
            'id' => (int) $decision['id'],
            'created_at' => $decision['created_at'],
            'value' => (string) ($decision['value'] ?? ''),
            'type' => (string) ($decision['type'] ?? ''),
            'scenario' => (string) ($decision['scenario'] ?? ''),
            'country' => (string) ($decision['source_country'] ?? ''),
            'expiration' => $decision['until'],
            'status' => $expired ? 'Expirovane' : 'Aktivni',
            'expired' => $expired,
            'origin' => (string) ($decision['origin'] ?? ''),
            'alert_id' => isset($decision['alert_id']) ? (int) $decision['alert_id'] : null,
        ];
    }

    $ipMap = [];
    foreach ($formatted as &$decision) {
        if ($decision['expired']) {
            $decision['is_duplicate'] = false;
            continue;
        }

        $ip = $decision['value'];
        if (!isset($ipMap[$ip])) {
            $ipMap[$ip] = $decision['id'];
            $decision['is_duplicate'] = false;
        } elseif ($decision['id'] > $ipMap[$ip]) {
            $decision['is_duplicate'] = true;
        } else {
            $decision['is_duplicate'] = false;
        }
    }
    unset($decision);

    if ($filters['hide_duplicates']) {
        $formatted = array_values(array_filter($formatted, static function (array $decision): bool {
            return empty($decision['is_duplicate']);
        }));
    }

    $totalDecisions = count($formatted);
    $totalPages = max(1, (int) ceil($totalDecisions / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $formatted = array_slice($formatted, $offset, $perPage);
} catch (Exception $e) {
    error_log('Decisions page error: ' . $e->getMessage());
}

$buildQuery = function (array $overrides = []) use ($filters, $perPage) {
    $query = array_merge([
        'value' => $filters['value'],
        'scenario' => $filters['scenario'],
        'type' => $filters['type'],
        'country' => $filters['country'],
        'include_expired' => $filters['include_expired'] ? '1' : null,
        'hide_duplicates' => $filters['hide_duplicates'] ? '1' : '0',
        'per_page' => $perPage,
    ], $overrides);

    return buildQueryString($query);
};

$decisionFilterDefinitions = [
    'value' => [
        'key' => 'value',
        'type' => 'text',
        'label' => 'IP / hodnota',
        'icon' => 'fas fa-network-wired',
        'placeholder' => 'napr. 185.197.9.26',
        'value' => $filters['value'],
        'class' => 'filter-group',
        'max_width' => 170,
    ],
    'scenario' => [
        'key' => 'scenario',
        'type' => 'text',
        'label' => 'Scenar',
        'icon' => 'fas fa-layer-group',
        'placeholder' => 'napr. generic:scan',
        'value' => $filters['scenario'],
        'class' => 'filter-group',
        'max_width' => 220,
    ],
    'type' => [
        'key' => 'type',
        'type' => 'text',
        'label' => 'Typ',
        'icon' => 'fas fa-gavel',
        'placeholder' => 'napr. ban',
        'value' => $filters['type'],
        'class' => 'filter-group',
        'max_width' => 120,
    ],
    'country' => [
        'key' => 'country',
        'type' => 'text',
        'label' => 'Zeme',
        'icon' => 'fas fa-flag',
        'placeholder' => 'napr. CZ',
        'value' => $filters['country'],
        'class' => 'filter-group',
        'max_width' => 100,
    ],
    'include_expired' => [
        'key' => 'include_expired',
        'type' => 'checkbox',
        'label' => 'Jen expirovane',
        'icon' => 'fas fa-clock-rotate-left',
        'value' => $filters['include_expired'] ? '1' : '',
        'class' => 'filter-group',
        'max_width' => 150,
    ],
    'hide_duplicates' => [
        'key' => 'hide_duplicates',
        'type' => 'checkbox',
        'label' => 'Skryt duplikaty',
        'icon' => 'fas fa-copy',
        'value' => $filters['hide_duplicates'] ? '1' : '',
        'class' => 'filter-group',
        'max_width' => 150,
    ],
    '_meta' => [
        'form_id' => 'decisionFilterForm',
        'reset_url' => '/decisions.php?reset_filters=1',
    ],
];

renderPageStart($appTitle . ' - Decisions', 'decisions', $appTitle);
?>
    <div class="container">
        <section class="page-header">
            <div>
                <h1>Rozhodnuti</h1>
                <p class="muted">Prehled aktivnich i expirovanych decisionu v CrowdSec. Celkem <strong><?= $totalDecisions ?></strong> zaznamu.</p>
            </div>
            <div class="toolbar">
                <button type="button" class="btn" onclick="void showAddDecisionModal()">Pridat novy ban</button>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash-message <?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?= renderSearchFilters($decisionFilterDefinitions) ?>

        <section class="card">
            <div class="card-body">
                <table class="data-table data-table-compact alerts-table" id="decisionsTable">
                    <?php
                    echo renderMessagesTableHeader([
                        'columns' => [
                            'created_at',
                            'scenario',
                            'source_ip',
                            ['key' => 'type', 'label' => 'Typ', 'sortable' => false],
                            ['key' => 'country', 'label' => 'Zeme', 'sortable' => false],
                            ['key' => 'expiration', 'label' => 'Expirace', 'sortable' => false],
                            ['key' => 'status', 'label' => 'Status', 'sortable' => false],
                            ['key' => 'actions', 'label' => 'Akce', 'sortable' => false],
                        ],
                    ]);
                    ?>
                    <tbody>
                        <?php if (empty($formatted)): ?>
                            <tr><td colspan="8" class="muted">Zadna data</td></tr>
                        <?php else: ?>
                            <?php foreach ($formatted as $decision): ?>
                                <?php
                                $ipValue = (string) $decision['value'];
                                $scenarioValue = (string) $decision['scenario'];
                                $countryCode = strtolower((string) $decision['country']);
                                $countryTitle = $countryCode !== '' ? strtoupper($countryCode) : '-';
                                $flag = $countryCode !== '' && preg_match('/^[a-z]{2}$/', $countryCode)
                                    ? '<span class="fi fi-' . htmlspecialchars($countryCode) . '" title="' . htmlspecialchars($countryTitle) . '"></span>'
                                    : '-';
                                $statusClass = $decision['expired'] ? 'badge-expired' : 'badge-active';
                                $statusLabel = $decision['expired'] ? 'Expirovane' : 'Aktivni';
                                $duplicateBadge = !empty($decision['is_duplicate'])
                                    ? ' <span class="badge badge-duplicate">Duplikat</span>'
                                    : '';
                                $scenarioLink = $scenarioValue !== ''
                                    ? '/decisions.php?' . buildQueryString(array_merge($filters, ['scenario' => $scenarioValue, 'page' => 1]))
                                    : '';
                                $ipLink = $ipValue !== ''
                                    ? '/decisions.php?' . buildQueryString(array_merge($filters, ['value' => $ipValue, 'page' => 1]))
                                    : '';
                                $countryLink = $countryCode !== ''
                                    ? '/decisions.php?' . buildQueryString(array_merge($filters, ['country' => strtoupper($countryCode), 'page' => 1]))
                                    : '';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(formatDateTime($decision['created_at'])) ?></td>
                                    <td>
                                        <?php if ($scenarioLink !== ''): ?>
                                            <a href="<?= htmlspecialchars($scenarioLink) ?>" class="filter-link" title="Filtrovat podle scenare">
                                                <?= htmlspecialchars($scenarioValue) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($scenarioValue !== '' ? $scenarioValue : '-') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ipLink !== ''): ?>
                                            <span class="ip-cell">
                                                <a href="<?= htmlspecialchars($ipLink) ?>" class="filter-link" title="Filtrovat podle IP">
                                                    <?= htmlspecialchars($ipValue) ?>
                                                </a>
                                                <button
                                                    type="button"
                                                    class="icon-btn icon-btn-mini icon-btn-primary"
                                                    onclick="void showIpIntelModal(event, '<?= htmlspecialchars($ipValue, ENT_QUOTES) ?>')"
                                                    aria-label="WHOIS detail IP <?= htmlspecialchars($ipValue) ?>"
                                                    title="WHOIS detail">
                                                    <i class="fa-solid fa-circle-info"></i>
                                                </button>
                                            </span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($ipValue !== '' ? $ipValue : '-') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($decision['type']) ?></td>
                                    <td class="text-center">
                                        <?php if ($countryLink !== ''): ?>
                                            <a href="<?= htmlspecialchars($countryLink) ?>" class="country-link" title="Filtrovat podle zeme">
                                                <?= $flag ?>
                                            </a>
                                        <?php else: ?>
                                            <?= $flag ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(formatDateTime($decision['expiration'])) ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span><?= $duplicateBadge ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <?php if (!empty($decision['alert_id'])): ?>
                                                <button type="button" class="icon-btn icon-btn-primary" onclick="void viewAlert(<?= (int) $decision['alert_id'] ?>)" aria-label="Detail alertu" title="Detail alertu">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm('Opravdu chcete odstranit tento ban?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $decision['id'] ?>">
                                                <button type="submit" class="icon-btn icon-btn-danger" aria-label="Odebrat ban" title="Odebrat ban">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?= renderPagination([
            'current' => $page,
            'total' => $totalPages,
            'buildQuery' => fn(array $params) => $buildQuery($params),
            'baseUrl' => '/decisions.php',
        ]) ?>

        <div class="modal" id="ipIntelModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavrit detail IP">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div id="ipIntelDetail"></div>
            </div>
        </div>

        <div class="modal" id="alertModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavrit detail alertu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div id="alertDetail"></div>
            </div>
        </div>

        <div class="modal" id="addDecisionModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavrit formular noveho banu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <h3>Pridat novy ban</h3>
                <form id="addDecisionForm" class="form-grid">
                    <div class="form-group">
                        <label for="banIp">IP adresa</label>
                        <input type="text" id="banIp" required placeholder="192.168.1.1">
                    </div>
                    <div class="form-group">
                        <label for="banDuration">Doba trvani</label>
                        <select id="banDuration">
                            <option value="1h">1 hodina</option>
                            <option value="4h" selected>4 hodiny</option>
                            <option value="24h">24 hodin</option>
                            <option value="168h">7 dnu</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="banReason">Duvod</label>
                        <input type="text" id="banReason" value="manual" placeholder="manual">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Ulozit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    window.setTimeout(function() {
        window.location.reload();
    }, 30000);
    </script>
<?php
renderPageEnd();
