<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_client.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$flash = getFlashMessage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $duration = trim((string) ($_POST['duration'] ?? '4h'));
        $reason = trim((string) ($_POST['reason'] ?? 'manual'));
        $type = trim((string) ($_POST['type'] ?? 'ban'));

        if ($ip === '') {
            setFlashMessage('error', 'IP adresa je povinná.');
        } else {
            try {
                $api = new CrowdSecAPI();
                $result = $api->addDecision($ip, $type, $duration, $reason);
                auditLog('decision.ban', [
                    'decision' => [
                        'value' => $ip,
                        'type' => $type,
                        'duration' => $duration,
                        'reason' => $reason
                    ],
                    'result' => $result
                ]);
                setFlashMessage('success', 'Ban byl úspěšně přidán.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodařilo se přidat ban: ' . $e->getMessage());
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
                    'decision' => $decisionDetails
                ]);
                setFlashMessage('success', 'Ban byl odstraněn.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodařilo se odebrat ban: ' . $e->getMessage());
            }
        } else {
            setFlashMessage('error', 'Neplatné ID rozhodnutí.');
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$filters = [
    'value' => trim((string) ($_GET['value'] ?? '')),
    'scenario' => trim((string) ($_GET['scenario'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'include_expired' => isset($_GET['include_expired']) && $_GET['include_expired'] === '1',
    'hide_duplicates' => !isset($_GET['hide_duplicates']) || $_GET['hide_duplicates'] === '1'
];

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));

$decisions = [];
$totalDecisions = 0;
$totalPages = 1;
$filterOptions = [
    'values' => [],
    'scenarios' => [],
    'types' => [],
    'countries' => []
];

try {
    $db = Database::getInstance()->getConnection();

    $filterOptions['values'] = $db->query('SELECT DISTINCT value FROM decisions WHERE value IS NOT NULL AND value != "" ORDER BY value')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['scenarios'] = $db->query('SELECT DISTINCT scenario FROM decisions WHERE scenario IS NOT NULL AND scenario != "" ORDER BY scenario')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['types'] = $db->query('SELECT DISTINCT type FROM decisions WHERE type IS NOT NULL AND type != "" ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);
    $filterOptions['countries'] = $db->query('SELECT DISTINCT source_country FROM alerts WHERE source_country IS NOT NULL AND source_country != "" ORDER BY source_country')->fetchAll(PDO::FETCH_COLUMN);

    $conditions = [];
    $params = [];

    if (!$filters['include_expired']) {
        $conditions[] = 'd.until > NOW()';
    }

    if ($filters['value'] !== '') {
        $conditions[] = 'LOWER(d.value) LIKE :value';
        $params[':value'] = '%' . strtolower($filters['value']) . '%';
    }

    if ($filters['scenario'] !== '') {
        $conditions[] = 'LOWER(d.scenario) LIKE :scenario';
        $params[':scenario'] = '%' . strtolower($filters['scenario']) . '%';
    }

    if ($filters['type'] !== '') {
        $conditions[] = 'LOWER(d.type) LIKE :type';
        $params[':type'] = '%' . strtolower($filters['type']) . '%';
    }

    if ($filters['country'] !== '') {
        $conditions[] = 'LOWER(a.source_country) LIKE :country';
        $params[':country'] = '%' . strtolower($filters['country']) . '%';
    }

    $statusFilter = strtolower($filters['status']);
    if ($statusFilter !== '') {
        if (str_contains($statusFilter, 'expi')) {
            $conditions[] = 'd.until < NOW()';
        } elseif (str_contains($statusFilter, 'aktiv') || str_contains($statusFilter, 'active')) {
            $conditions[] = 'd.until >= NOW()';
        }
    }

    $whereSql = '';
    if (!empty($conditions)) {
        $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    }

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
        {$whereSql}
        ORDER BY d.created_at DESC
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($decisions as $decision) {
        $expired = $decision['until'] ? strtotime($decision['until']) < time() : false;
        $formatted[] = [
            'id' => $decision['id'],
            'created_at' => $decision['created_at'],
            'value' => $decision['value'],
            'type' => $decision['type'],
            'scenario' => $decision['scenario'],
            'country' => $decision['source_country'] ?? 'Unknown',
            'expiration' => $decision['until'],
            'status' => $expired ? 'Expirované' : 'Aktivní',
            'expired' => $expired
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
        $formatted = array_values(array_filter($formatted, function ($decision) {
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
        'status' => $filters['status'],
        'include_expired' => $filters['include_expired'] ? '1' : null,
        'hide_duplicates' => $filters['hide_duplicates'] ? '1' : null,
        'per_page' => $perPage
    ], $overrides);

    $query = array_filter($query, function ($value) {
        return $value !== null && $value !== '';
    });

    return http_build_query($query);
};

renderPageStart($appTitle . ' - Decisions', 'decisions', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Rozhodnutí</h1>
            <p class="muted">Aktivní a expirované bany.</p>
        </div>
        <div class="toolbar">
            <a class="btn" href="/decisions.php?<?= htmlspecialchars($buildQuery()) ?>">Obnovit</a>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="flash-message <?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-header">
            <h2>Přidat nový ban</h2>
        </div>
        <div class="card-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>IP adresa</label>
                    <input type="text" name="ip" required placeholder="192.168.1.1">
                </div>
                <div class="form-group">
                    <label>Doba trvání</label>
                    <select name="duration">
                        <option value="1h">1 hodina</option>
                        <option value="4h" selected>4 hodiny</option>
                        <option value="24h">24 hodin</option>
                        <option value="168h">7 dnů</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Důvod</label>
                    <input type="text" name="reason" value="manual" placeholder="manual">
                </div>
                <button type="submit" class="btn">Přidat ban</button>
            </form>
        </div>
    </section>

    <form class="table-filters" method="get">
        <div class="filter-group">
            <label for="decisionFilterValue">IP / Hodnota</label>
            <input type="text" id="decisionFilterValue" name="value" list="decisionValueList" placeholder="např. 10.0.0.1" value="<?= htmlspecialchars($filters['value']) ?>">
            <datalist id="decisionValueList">
                <?php foreach ($filterOptions['values'] as $value): ?>
                    <option value="<?= htmlspecialchars($value) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterScenario">Scénář</label>
            <input type="text" id="decisionFilterScenario" name="scenario" list="decisionScenarioList" placeholder="např. ssh-bf" value="<?= htmlspecialchars($filters['scenario']) ?>">
            <datalist id="decisionScenarioList">
                <?php foreach ($filterOptions['scenarios'] as $scenario): ?>
                    <option value="<?= htmlspecialchars($scenario) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterType">Typ</label>
            <input type="text" id="decisionFilterType" name="type" list="decisionTypeList" placeholder="např. ban" value="<?= htmlspecialchars($filters['type']) ?>">
            <datalist id="decisionTypeList">
                <?php foreach ($filterOptions['types'] as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterCountry">Země</label>
            <input type="text" id="decisionFilterCountry" name="country" list="decisionCountryList" placeholder="např. CZ" value="<?= htmlspecialchars($filters['country']) ?>">
            <datalist id="decisionCountryList">
                <?php foreach ($filterOptions['countries'] as $country): ?>
                    <option value="<?= htmlspecialchars($country) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="filter-group">
            <label for="decisionFilterStatus">Status</label>
            <input type="text" id="decisionFilterStatus" name="status" list="decisionStatusList" placeholder="Aktivní / Expirované" value="<?= htmlspecialchars($filters['status']) ?>">
            <datalist id="decisionStatusList">
                <option value="Aktivní"></option>
                <option value="Expirované"></option>
            </datalist>
        </div>
        <div class="filter-group checkbox">
            <label>
                <input type="checkbox" name="include_expired" value="1" <?= $filters['include_expired'] ? 'checked' : '' ?>>
                <span>Zobrazit expirované</span>
            </label>
        </div>
        <div class="filter-group checkbox">
            <label>
                <input type="checkbox" name="hide_duplicates" value="1" <?= $filters['hide_duplicates'] ? 'checked' : '' ?>>
                <span>Skrýt duplikáty</span>
            </label>
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="submit">Filtrovat</button>
            <a class="btn btn-ghost" href="/decisions.php">Vyčistit filtry</a>
        </div>
    </form>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="decisionsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Čas</th>
                        <th>IP adresa</th>
                        <th>Typ</th>
                        <th>Scénář</th>
                        <th>Země</th>
                        <th>Expirace</th>
                        <th>Status</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($formatted)): ?>
                        <tr><td colspan="9" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($formatted as $decision): ?>
                            <tr>
                                <td><?= (int) $decision['id'] ?></td>
                                <td><?= htmlspecialchars(formatDateTime($decision['created_at'])) ?></td>
                                <td><?= htmlspecialchars((string) $decision['value']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['type']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['scenario']) ?></td>
                                <td><?= htmlspecialchars((string) $decision['country']) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($decision['expiration'])) ?></td>
                                <td><?= htmlspecialchars($decision['status']) ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Opravdu chcete odstranit tento ban?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $decision['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-small">Odebrat</button>
                                    </form>
                                </td>
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
            <a class="pagination-link <?= $page === 1 ? 'disabled' : '' ?>" href="/decisions.php?<?= htmlspecialchars($buildQuery(['page' => $prevPage])) ?>">&laquo; Předchozí</a>
            <?php foreach ($pages as $pageNumber): ?>
                <?php if ($pageNumber === '...'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php else: ?>
                    <a class="pagination-link <?= (int) $pageNumber === $page ? 'active' : '' ?>" href="/decisions.php?<?= htmlspecialchars($buildQuery(['page' => $pageNumber])) ?>">
                        <?= $pageNumber ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="pagination-link <?= $page === $totalPages ? 'disabled' : '' ?>" href="/decisions.php?<?= htmlspecialchars($buildQuery(['page' => $nextPage])) ?>">Další &raquo;</a>
        </div>
    <?php endif; ?>
<?php
renderPageEnd();
