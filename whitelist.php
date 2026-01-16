<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

const UINT32_MAX = 0xFFFFFFFF;
const UINT32_BASE = 4294967296;
const SIGNED_INT64_MAX = 9223372036854775807;
const SIGNED_INT64_OFFSET = -9223372036854775807;

function ipToUnsignedLong($ip) {
    $long = ip2long($ip);
    if ($long === false) {
        return null;
    }
    return (int) sprintf('%u', $long);
}

function ipv6ToUint32Parts($ip) {
    $packed = inet_pton($ip);
    if ($packed === false) {
        return null;
    }
    $parts = unpack('N4', $packed);
    return array_values($parts);
}

function unsigned64ToBiasedSigned(int $high32, int $low32): int {
    if ($high32 === UINT32_MAX && $low32 === UINT32_MAX) {
        return SIGNED_INT64_MAX;
    }
    if ($high32 >= 0x80000000) {
        $highOffset = $high32 - 0x80000000;
        return (int) ($highOffset * UINT32_BASE + $low32 + 1);
    }
    return (int) ($high32 * UINT32_BASE + $low32 + SIGNED_INT64_OFFSET);
}

function applyIpv6PrefixMask(array $parts, int $suffix): array {
    $startParts = [];
    $endParts = [];
    $remaining = $suffix;

    foreach ($parts as $part) {
        if ($remaining >= 32) {
            $mask = UINT32_MAX;
        } elseif ($remaining <= 0) {
            $mask = 0;
        } else {
            $mask = (UINT32_MAX << (32 - $remaining)) & UINT32_MAX;
        }

        $startParts[] = $part & $mask;
        $endParts[] = $part | (~$mask & UINT32_MAX);
        $remaining -= 32;
    }

    return [$startParts, $endParts];
}

function parseAllowListValue($value) {
    $value = trim($value);
    if ($value === '') {
        return ['error' => 'Value is required'];
    }

    if (strpos($value, '/') !== false) {
        [$ip, $suffix] = array_pad(explode('/', $value, 2), 2, null);
        $ip = trim((string) $ip);
        $suffix = trim((string) $suffix);

        if ($suffix === '' || !ctype_digit($suffix)) {
            return ['error' => 'Invalid subnet suffix'];
        }

        $suffix = (int) $suffix;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($suffix < 0 || $suffix > 32) {
                return ['error' => 'Subnet suffix out of range'];
            }

            $ipLong = ipToUnsignedLong($ip);
            if ($ipLong === null) {
                return ['error' => 'Invalid IPv4 address'];
            }

            if ($suffix === 0) {
                $startIp = 0;
                $endIp = UINT32_MAX;
            } else {
                $mask = (UINT32_MAX << (32 - $suffix)) & UINT32_MAX;
                $startIp = $ipLong & $mask;
                $endIp = $startIp + (1 << (32 - $suffix)) - 1;
            }

            return [
                'start_ip' => unsigned64ToBiasedSigned(0, (int) $startIp),
                'end_ip' => unsigned64ToBiasedSigned(0, (int) $endIp),
                'start_suffix' => unsigned64ToBiasedSigned(0, 0),
                'end_suffix' => unsigned64ToBiasedSigned(0, 0),
                'ip_size' => 4
            ];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($suffix < 0 || $suffix > 128) {
                return ['error' => 'Subnet suffix out of range'];
            }

            $parts = ipv6ToUint32Parts($ip);
            if ($parts === null) {
                return ['error' => 'Invalid IPv6 address'];
            }

            [$startParts, $endParts] = applyIpv6PrefixMask($parts, $suffix);

            return [
                'start_ip' => unsigned64ToBiasedSigned($startParts[0], $startParts[1]),
                'end_ip' => unsigned64ToBiasedSigned($endParts[0], $endParts[1]),
                'start_suffix' => unsigned64ToBiasedSigned($startParts[2], $startParts[3]),
                'end_suffix' => unsigned64ToBiasedSigned($endParts[2], $endParts[3]),
                'ip_size' => 16
            ];
        }

        return ['error' => 'Invalid IP address'];
    }

    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ipToUnsignedLong($value);
        if ($ipLong === null) {
            return ['error' => 'Invalid IPv4 address'];
        }

        return [
            'start_ip' => unsigned64ToBiasedSigned(0, $ipLong),
            'end_ip' => unsigned64ToBiasedSigned(0, $ipLong),
            'start_suffix' => unsigned64ToBiasedSigned(0, 0),
            'end_suffix' => unsigned64ToBiasedSigned(0, 0),
            'ip_size' => 4
        ];
    }

    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = ipv6ToUint32Parts($value);
        if ($parts === null) {
            return ['error' => 'Invalid IPv6 address'];
        }

        return [
            'start_ip' => unsigned64ToBiasedSigned($parts[0], $parts[1]),
            'end_ip' => unsigned64ToBiasedSigned($parts[0], $parts[1]),
            'start_suffix' => unsigned64ToBiasedSigned($parts[2], $parts[3]),
            'end_suffix' => unsigned64ToBiasedSigned($parts[2], $parts[3]),
            'ip_size' => 16
        ];
    }

    return ['error' => 'Invalid IP address'];
}

function getDefaultAllowListId($db) {
    $stmt = $db->query('SELECT id FROM allow_lists ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int) $row['id'] : null;
}

function allowListExists($db, $allowListId) {
    $stmt = $db->prepare('SELECT id FROM allow_lists WHERE id = ?');
    $stmt->execute([$allowListId]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$flash = getFlashMessage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $cidr = trim((string) ($_POST['cidr'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $expiresAtRaw = trim((string) ($_POST['expires_at'] ?? ''));
        $allowListId = $_POST['allow_list_id'] ?? null;

        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAtRaw));
        }

        try {
            $db = Database::getInstance()->getConnection();
            $parsed = parseAllowListValue($cidr);
            if (isset($parsed['error'])) {
                throw new Exception($parsed['error']);
            }

            if ($allowListId === null || $allowListId === '') {
                $allowListId = getDefaultAllowListId($db);
            }
            if (!$allowListId || !allowListExists($db, $allowListId)) {
                throw new Exception('Neexistuje žádný whitelist. Vytvořte ho nejdříve (např. cscli allowlist create).');
            }

            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO allow_list_items (created_at, updated_at, expires_at, comment, value, start_ip, end_ip, start_suffix, end_suffix, ip_size)
                VALUES (NOW(), NOW(), :expires_at, :comment, :value, :start_ip, :end_ip, :start_suffix, :end_suffix, :ip_size)
            ');
            $stmt->execute([
                ':expires_at' => $expiresAt ?: null,
                ':comment' => $reason !== '' ? $reason : null,
                ':value' => $cidr,
                ':start_ip' => $parsed['start_ip'],
                ':end_ip' => $parsed['end_ip'],
                ':start_suffix' => $parsed['start_suffix'],
                ':end_suffix' => $parsed['end_suffix'],
                ':ip_size' => $parsed['ip_size']
            ]);

            $id = $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO allow_list_allowlist_items (allow_list_id, allow_list_item_id)
                VALUES (:allow_list_id, :allow_list_item_id)
            ');
            $stmt->execute([
                ':allow_list_id' => $allowListId,
                ':allow_list_item_id' => $id
            ]);

            $db->commit();
            auditLog('whitelist.add', [
                'id' => $id,
                'cidr' => $cidr,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'allow_list_id' => $allowListId
            ]);
            setFlashMessage('success', 'Whitelist položka byla přidána.');
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            setFlashMessage('error', 'Nepodařilo se uložit položku: ' . $e->getMessage());
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('
                    SELECT i.id, i.value AS cidr, i.comment AS reason, i.expires_at, ali.allow_list_id
                    FROM allow_list_allowlist_items ali
                    JOIN allow_list_items i ON i.id = ali.allow_list_item_id
                    WHERE i.id = ?
                ');
                $stmt->execute([$id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $stmt = $db->prepare('DELETE FROM allow_list_items WHERE id = ?');
                $stmt->execute([$id]);

                auditLog('whitelist.delete', [
                    'id' => $id,
                    'item' => $item
                ]);
                setFlashMessage('success', 'Whitelist položka byla odstraněna.');
            } catch (Exception $e) {
                setFlashMessage('error', 'Nepodařilo se odstranit položku: ' . $e->getMessage());
            }
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$filters = [
    'cidr' => trim((string) ($_GET['cidr'] ?? '')),
    'reason' => trim((string) ($_GET['reason'] ?? '')),
    'list' => trim((string) ($_GET['list'] ?? ''))
];

$perPage = (int) ($_GET['per_page'] ?? 50);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));

$items = [];
$totalItems = 0;
$totalPages = 1;
$allowLists = [];

try {
    $db = Database::getInstance()->getConnection();
    $allowLists = $db->query('SELECT id, name FROM allow_lists ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    $conditions = [];
    $params = [];

    if ($filters['cidr'] !== '') {
        $conditions[] = 'LOWER(i.value) LIKE :cidr';
        $params[':cidr'] = '%' . strtolower($filters['cidr']) . '%';
    }

    if ($filters['reason'] !== '') {
        $conditions[] = 'LOWER(i.comment) LIKE :reason';
        $params[':reason'] = '%' . strtolower($filters['reason']) . '%';
    }

    if ($filters['list'] !== '') {
        $conditions[] = 'LOWER(l.name) LIKE :list';
        $params[':list'] = '%' . strtolower($filters['list']) . '%';
    }

    $whereSql = '';
    if (!empty($conditions)) {
        $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM allow_list_allowlist_items ali
        JOIN allow_list_items i ON i.id = ali.allow_list_item_id
        JOIN allow_lists l ON l.id = ali.allow_list_id
        {$whereSql}
    ");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("
        SELECT
            i.id,
            i.created_at,
            i.updated_at,
            i.expires_at,
            i.comment AS reason,
            i.value AS cidr,
            l.name AS list_name
        FROM allow_list_allowlist_items ali
        JOIN allow_list_items i ON i.id = ali.allow_list_item_id
        JOIN allow_lists l ON l.id = ali.allow_list_id
        {$whereSql}
        ORDER BY i.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Whitelist page error: ' . $e->getMessage());
}

$buildQuery = function (array $overrides = []) use ($filters, $perPage) {
    $query = array_merge([
        'cidr' => $filters['cidr'],
        'reason' => $filters['reason'],
        'list' => $filters['list'],
        'per_page' => $perPage
    ], $overrides);

    $query = array_filter($query, function ($value) {
        return $value !== null && $value !== '';
    });

    return http_build_query($query);
};

renderPageStart($appTitle . ' - Whitelist', 'whitelist', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Whitelist</h1>
            <p class="muted">Povolené IP adresy nebo subnety. Celkem <strong><?= $totalItems ?></strong> položek.</p>
        </div>
        <div class="toolbar">
            <a class="btn" href="/whitelist.php?<?= htmlspecialchars($buildQuery()) ?>">Obnovit</a>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="flash-message <?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <div class="card-body">
            <p class="muted">
                Whitelist musí být nejdříve založen v tabulce allow_lists (doporučeno přes <code>cscli allowlist create</code>),
                následně se přidávají položky a propojí se přes allow_list_allowlist_items.
                Ukládáme je ve stejném formátu jako <code>cscli</code> (včetně IPv6), takže není potřeba volat další API příkaz
                a změny se okamžitě projeví v CrowdSec.
            </p>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <h2>Přidat položku</h2>
        </div>
        <div class="card-body">
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Whitelist</label>
                    <select name="allow_list_id" required>
                        <?php foreach ($allowLists as $list): ?>
                            <option value="<?= (int) $list['id'] ?>">
                                <?= htmlspecialchars($list['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>CIDR</label>
                    <input type="text" name="cidr" required placeholder="192.168.1.1 nebo 10.0.0.0/24">
                </div>
                <div class="form-group">
                    <label>Důvod</label>
                    <input type="text" name="reason" placeholder="např. monitoring">
                </div>
                <div class="form-group">
                    <label>Platnost do</label>
                    <input type="datetime-local" name="expires_at">
                </div>
                <button type="submit" class="btn">Uložit</button>
            </form>
        </div>
    </section>

    <form class="table-filters" method="get">
        <div class="filter-group">
            <label for="whitelistFilterCidr">CIDR</label>
            <input type="text" id="whitelistFilterCidr" name="cidr" placeholder="např. 10.0.0.0/24" value="<?= htmlspecialchars($filters['cidr']) ?>">
        </div>
        <div class="filter-group">
            <label for="whitelistFilterReason">Důvod</label>
            <input type="text" id="whitelistFilterReason" name="reason" placeholder="např. interní servis" value="<?= htmlspecialchars($filters['reason']) ?>">
        </div>
        <div class="filter-group">
            <label for="whitelistFilterList">Whitelist</label>
            <input type="text" id="whitelistFilterList" name="list" placeholder="např. my_whitelist" value="<?= htmlspecialchars($filters['list']) ?>">
        </div>
        <div class="filter-actions">
            <button class="btn btn-ghost" type="submit">Filtrovat</button>
            <a class="btn btn-ghost" href="/whitelist.php">Vyčistit filtry</a>
        </div>
    </form>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="whitelistTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Whitelist</th>
                        <th>CIDR</th>
                        <th>Důvod</th>
                        <th>Platnost do</th>
                        <th>Vytvořeno</th>
                        <th>Aktualizováno</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="8" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= (int) $item['id'] ?></td>
                                <td><?= htmlspecialchars((string) $item['list_name']) ?></td>
                                <td><?= htmlspecialchars((string) $item['cidr']) ?></td>
                                <td><?= htmlspecialchars((string) ($item['reason'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($item['expires_at'])) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($item['created_at'])) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($item['updated_at'])) ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Opravdu chcete odstranit tuto položku?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-small">Smazat</button>
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
            <a class="pagination-link <?= $page === 1 ? 'disabled' : '' ?>" href="/whitelist.php?<?= htmlspecialchars($buildQuery(['page' => $prevPage])) ?>">&laquo; Předchozí</a>
            <?php foreach ($pages as $pageNumber): ?>
                <?php if ($pageNumber === '...'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php else: ?>
                    <a class="pagination-link <?= (int) $pageNumber === $page ? 'active' : '' ?>" href="/whitelist.php?<?= htmlspecialchars($buildQuery(['page' => $pageNumber])) ?>">
                        <?= $pageNumber ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="pagination-link <?= $page === $totalPages ? 'disabled' : '' ?>" href="/whitelist.php?<?= htmlspecialchars($buildQuery(['page' => $nextPage])) ?>">Další &raquo;</a>
        </div>
    <?php endif; ?>
<?php
renderPageEnd();
