<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/filter_helper.php';

requireLogin();

function executeCscliCommand(array $arguments): array {
    $escapedArguments = array_map(static fn(string $argument): string => escapeshellarg($argument), $arguments);
    $command = 'sudo cscli ' . implode(' ', $escapedArguments) . ' 2>&1';

    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return [
        'command' => $command,
        'output' => trim(implode(PHP_EOL, $output)),
        'exit_code' => $exitCode,
    ];
}

function getAllowListById(PDO $db, int $allowListId): ?array {
    $stmt = $db->prepare('SELECT id, name FROM allow_lists WHERE id = ?');
    $stmt->execute([$allowListId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function executeCscliAllowlistAdd(string $listName, string $cidr, string $reason = ''): array {
    $description = $reason !== '' ? $reason : 'manual';
    return executeCscliCommand(['allowlists', 'add', $listName, $cidr, '-d', $description]);
}

function executeCscliAllowlistDelete(string $listName, string $cidr): array {
    return executeCscliCommand(['allowlists', 'remove', $listName, $cidr]);
}

function fetchAllowListItemsFromCscli(string $listName): array {
    $result = executeCscliCommand(['allowlists', 'inspect', $listName, '-o', 'json']);
    if ($result['exit_code'] !== 0) {
        $message = $result['output'] !== '' ? $result['output'] : 'cscli inspect vratilo chybu bez vystupu.';
        throw new Exception($message);
    }

    $decoded = json_decode($result['output'], true);
    if (!is_array($decoded)) {
        throw new Exception('Nepodarilo se zpracovat JSON vystup z cscli allowlists inspect.');
    }

    $records = $decoded['items'] ?? [];
    $resolvedListName = (string) ($decoded['name'] ?? $listName);
    $listUpdatedAt = (string) ($decoded['updated_at'] ?? '');

    $items = [];
    foreach ($records as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $rawValue = trim((string) ($record['value'] ?? ''));
        if ($rawValue === '') {
            continue;
        }

        $items[] = [
            'id' => $index + 1,
            'created_at' => (string) ($record['created_at'] ?? ''),
            'updated_at' => $listUpdatedAt,
            'expires_at' => (string) ($record['expiration'] ?? ''),
            'reason' => (string) ($record['description'] ?? ''),
            'cidr' => $rawValue,
            'list_name' => $resolvedListName,
        ];
    }

    return [
        'items' => $items,
        'debug' => $result,
    ];
}

function formatWhitelistExpiration(?string $value): string {
    $value = trim((string) $value);
    if ($value === '' || strtolower($value) === 'never' || str_starts_with($value, '0001-01-01')) {
        return 'never';
    }

    return formatDateTime($value);
}

function formatWhitelistCreatedAt(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    return formatDateTime($value);
}

function formatWhitelistUpdatedAt(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    return formatDateTime($value);
}

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$flash = getFlashMessage();
$filterSessionKey = 'whitelistfilters';
initFilterSession($filterSessionKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cidr = trim((string) ($_POST['cidr'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $allowListId = (int) ($_POST['allow_list_id'] ?? 0);

        if ($cidr === '') {
            setFlashMessage('error', 'CIDR nebo IP adresa je povinna.');
        } elseif (!filter_var(str_contains($cidr, '/') ? strtok($cidr, '/') : $cidr, FILTER_VALIDATE_IP)) {
            setFlashMessage('error', 'Zadejte platnou IP adresu nebo CIDR rozsah.');
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $allowList = getAllowListById($db, $allowListId);
                if ($allowList === null) {
                    throw new Exception('Vybrany whitelist nebyl nalezen.');
                }

                $result = executeCscliAllowlistAdd((string) $allowList['name'], $cidr, $reason);
                if ($result['exit_code'] !== 0) {
                    $message = $result['output'] !== '' ? $result['output'] : 'cscli vratilo chybu bez vystupu.';
                    throw new Exception($message);
                }

                auditLog('whitelist.add', [
                    'cidr' => $cidr,
                    'reason' => $reason !== '' ? $reason : 'manual',
                    'allow_list_id' => (int) $allowList['id'],
                    'allow_list_name' => (string) $allowList['name'],
                    'result' => $result['output'],
                ]);
                setFlashMessage('success', 'Whitelist polozka byla pridana pres cscli.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodarilo se pridat polozku: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'delete') {
        $cidr = trim((string) ($_POST['cidr'] ?? ''));
        $listName = trim((string) ($_POST['list_name'] ?? ''));
        if ($cidr !== '' && $listName !== '') {
            try {
                $result = executeCscliAllowlistDelete($listName, $cidr);
                if ($result['exit_code'] !== 0) {
                    $message = $result['output'] !== '' ? $result['output'] : 'cscli delete vratilo chybu bez vystupu.';
                    throw new Exception($message);
                }

                auditLog('whitelist.delete', [
                    'cidr' => $cidr,
                    'list_name' => $listName,
                    'result' => $result['output'],
                ]);
                setFlashMessage('success', 'Whitelist polozka byla odstranena pres cscli.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodarilo se odstranit polozku: ' . $e->getMessage());
            }
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$filters = [
    'cidr' => trim((string) getFilterValue('cidr', $filterSessionKey)),
    'reason' => trim((string) getFilterValue('reason', $filterSessionKey)),
    'list' => trim((string) getFilterValue('list', $filterSessionKey)),
];

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));
$debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';

$items = [];
$totalItems = 0;
$totalPages = 1;
$allowLists = [];
$debugInfo = null;
$loadError = '';

try {
    $db = Database::getInstance()->getConnection();
    $allowLists = $db->query('SELECT id, name FROM allow_lists ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    $selectedListName = $filters['list'] !== '' ? $filters['list'] : 'local_whitelist';
    $inspectResult = fetchAllowListItemsFromCscli($selectedListName);
    $items = $inspectResult['items'];
    $debugInfo = $inspectResult['debug'];

    $items = array_values(array_filter($items, static function (array $item) use ($filters): bool {
        $cidrMatch = $filters['cidr'] === '' || str_contains(mb_strtolower((string) $item['cidr']), mb_strtolower($filters['cidr']));
        $reasonMatch = $filters['reason'] === '' || str_contains(mb_strtolower((string) ($item['reason'] ?? '')), mb_strtolower($filters['reason']));
        $listMatch = $filters['list'] === '' || str_contains(mb_strtolower((string) $item['list_name']), mb_strtolower($filters['list']));
        return $cidrMatch && $reasonMatch && $listMatch;
    }));

    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $items = array_slice($items, $offset, $perPage);
} catch (Exception $e) {
    $loadError = $e->getMessage();
    error_log('Whitelist page error: ' . $loadError);
}

$buildQuery = function (array $overrides = []) use ($filters, $perPage) {
    $query = array_merge([
        'cidr' => $filters['cidr'],
        'reason' => $filters['reason'],
        'list' => $filters['list'],
        'per_page' => $perPage,
    ], $overrides);

    return buildQueryString($query);
};

$whitelistFilterDefinitions = [
    'cidr' => [
        'key' => 'cidr',
        'type' => 'text',
        'label' => 'IP / CIDR',
        'icon' => 'fas fa-network-wired',
        'placeholder' => 'napr. 85.163.83.0/24',
        'value' => $filters['cidr'],
        'class' => 'filter-group',
        'max_width' => 190,
    ],
    'reason' => [
        'key' => 'reason',
        'type' => 'text',
        'label' => 'Duvod',
        'icon' => 'fas fa-note-sticky',
        'placeholder' => 'napr. MILNET /24',
        'value' => $filters['reason'],
        'class' => 'filter-group',
        'max_width' => 220,
    ],
    'list' => [
        'key' => 'list',
        'type' => 'text',
        'label' => 'Whitelist',
        'icon' => 'fas fa-list-check',
        'placeholder' => 'napr. local_whitelist',
        'value' => $filters['list'],
        'class' => 'filter-group',
        'max_width' => 190,
    ],
    '_meta' => [
        'form_id' => 'whitelistFilterForm',
        'reset_url' => '/whitelist.php?reset_filters=1',
    ],
];

renderPageStart($appTitle . ' - Whitelist', 'whitelist', $appTitle);
?>
    <div class="container">
        <section class="page-header">
            <div>
                <h1>Whitelist</h1>
                <p class="muted">Prehled povolenych IP adres a subnetu. Celkem <strong><?= $totalItems ?></strong> polozek.</p>
            </div>
            <div class="toolbar">
                <button type="button" class="btn" onclick="void showWhitelistAddModal()">Pridat polozku</button>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash-message <?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="flash-message error">
                <?= htmlspecialchars('Whitelist se nepodarilo nacist: ' . $loadError) ?>
            </div>
        <?php endif; ?>

        <?php if ($debugEnabled && $debugInfo !== null): ?>
            <section class="card">
                <div class="card-header">
                    <h2>Debug cscli</h2>
                </div>
                <div class="card-body">
                    <p><strong>Prikaz:</strong> <code><?= htmlspecialchars((string) ($debugInfo['command'] ?? '')) ?></code></p>
                    <p><strong>Exit code:</strong> <?= (int) ($debugInfo['exit_code'] ?? -1) ?></p>
                    <pre style="white-space: pre-wrap; word-break: break-word; margin: 0;"><?= htmlspecialchars((string) ($debugInfo['output'] ?? '')) ?></pre>
                </div>
            </section>
        <?php endif; ?>

        <?= renderSearchFilters($whitelistFilterDefinitions) ?>

        <section class="card">
            <div class="card-body">
                <table class="data-table data-table-compact alerts-table" id="whitelistTable">
                    <?php
                    echo renderMessagesTableHeader([
                        'columns' => [
                            ['key' => 'created_at', 'label' => 'Vytvoreno', 'sortable' => false],
                            ['key' => 'list_name', 'label' => 'Whitelist', 'sortable' => false],
                            ['key' => 'cidr', 'label' => 'IP / CIDR', 'sortable' => false],
                            ['key' => 'reason', 'label' => 'Duvod', 'sortable' => false],
                            ['key' => 'expires_at', 'label' => 'Expirace', 'sortable' => false],
                            ['key' => 'updated_at', 'label' => 'Aktualizovano', 'sortable' => false],
                            ['key' => 'actions', 'label' => 'Akce', 'sortable' => false],
                        ],
                    ]);
                    ?>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="7" class="muted">Zadna data</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $cidrValue = (string) ($item['cidr'] ?? '');
                                $ipForWhois = $cidrValue;
                                if (str_contains($cidrValue, '/')) {
                                    $ipForWhois = (string) strtok($cidrValue, '/');
                                }
                                $listName = (string) ($item['list_name'] ?? '');
                                $reason = trim((string) ($item['reason'] ?? ''));
                                $listLink = $listName !== ''
                                    ? '/whitelist.php?' . buildQueryString(array_merge($filters, ['list' => $listName, 'page' => 1]))
                                    : '';
                                $cidrLink = $cidrValue !== ''
                                    ? '/whitelist.php?' . buildQueryString(array_merge($filters, ['cidr' => $cidrValue, 'page' => 1]))
                                    : '';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(formatWhitelistCreatedAt($item['created_at'])) ?></td>
                                    <td>
                                        <?php if ($listLink !== ''): ?>
                                            <a href="<?= htmlspecialchars($listLink) ?>" class="filter-link" title="Filtrovat podle whitelistu">
                                                <?= htmlspecialchars($listName) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($listName !== '' ? $listName : '-') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cidrLink !== ''): ?>
                                            <span class="ip-cell">
                                                <a href="<?= htmlspecialchars($cidrLink) ?>" class="filter-link" title="Filtrovat podle IP nebo CIDR">
                                                    <?= htmlspecialchars($cidrValue) ?>
                                                </a>
                                                <?php if ($ipForWhois !== '' && filter_var($ipForWhois, FILTER_VALIDATE_IP)): ?>
                                                    <button
                                                        type="button"
                                                        class="icon-btn icon-btn-mini icon-btn-primary"
                                                        onclick="void showIpIntelModal(event, '<?= htmlspecialchars($ipForWhois, ENT_QUOTES) ?>')"
                                                        aria-label="WHOIS detail IP <?= htmlspecialchars($ipForWhois) ?>"
                                                        title="WHOIS detail">
                                                        <i class="fa-solid fa-circle-info"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($cidrValue !== '' ? $cidrValue : '-') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($reason !== '' ? $reason : '-') ?></td>
                                    <td><?= htmlspecialchars(formatWhitelistExpiration($item['expires_at'])) ?></td>
                                    <td><?= htmlspecialchars(formatWhitelistUpdatedAt($item['updated_at'])) ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <form method="post" onsubmit="return confirm('Opravdu chcete odstranit tuto polozku?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="cidr" value="<?= htmlspecialchars($cidrValue) ?>">
                                                <input type="hidden" name="list_name" value="<?= htmlspecialchars($listName) ?>">
                                                <button type="submit" class="icon-btn icon-btn-danger" aria-label="Odstranit polozku" title="Odstranit polozku">
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
            'baseUrl' => '/whitelist.php',
        ]) ?>

        <div class="modal" id="ipIntelModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavrit detail IP">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div id="ipIntelDetail"></div>
            </div>
        </div>

        <div class="modal" id="whitelistAddModal" aria-hidden="true">
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Zavrit formular whitelistu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <h3>Pridat whitelist polozku</h3>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="allowListId">Whitelist</label>
                        <select id="allowListId" name="allow_list_id" required>
                            <?php foreach ($allowLists as $list): ?>
                                <option value="<?= (int) $list['id'] ?>"<?= (string) $list['name'] === 'local_whitelist' ? ' selected' : '' ?>>
                                    <?= htmlspecialchars((string) $list['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="whitelistCidr">IP nebo CIDR</label>
                        <input type="text" id="whitelistCidr" name="cidr" required placeholder="85.163.83.0/24">
                    </div>
                    <div class="form-group">
                        <label for="whitelistReason">Duvod</label>
                        <input type="text" id="whitelistReason" name="reason" placeholder="MILNET /24">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Ulozit pres cscli</button>
                    </div>
                </form>
                <p class="muted">
                    Pouzije se prikaz ve stylu <code>cscli allowlists add local_whitelist 85.163.83.0/24 -d "MILNET /24"</code>.
                </p>
            </div>
        </div>
    </div>
    <script>
    function showWhitelistAddModal() {
        const modal = document.getElementById('whitelistAddModal');
        if (!modal) return;
        modal.classList.add('active');
    }
    </script>
<?php
renderPageEnd();
