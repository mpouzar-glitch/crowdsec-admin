<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';

$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = max(10, min($perPage, 200));
$page = max(1, (int) ($_GET['page'] ?? 1));

$machines = [];
$totalMachines = 0;
$totalPages = 1;

try {
    $db = Database::getInstance()->getConnection();

    $countStmt = $db->query('SELECT COUNT(*) FROM machines');
    $totalMachines = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalMachines / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare('
        SELECT
            m.id,
            m.machine_id,
            m.ip_address,
            m.last_heartbeat,
            m.last_push,
            m.is_validated,
            m.version,
            COUNT(DISTINCT a.id) as alerts_count,
            COUNT(DISTINCT d.id) as decisions_count
        FROM machines m
        LEFT JOIN alerts a ON a.machine_alerts = m.id
        LEFT JOIN decisions d ON d.alert_decisions = a.id
        GROUP BY m.id
        ORDER BY m.machine_id ASC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $machines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = time();
    foreach ($machines as &$machine) {
        $heartbeat = $machine['last_heartbeat'] ? strtotime($machine['last_heartbeat']) : null;
        $isValidated = filter_var($machine['is_validated'], FILTER_VALIDATE_BOOLEAN);
        $isOnline = $isValidated && $heartbeat && ($now - $heartbeat <= 120);
        $machine['status'] = $isOnline ? 'Online' : 'Offline';
    }
    unset($machine);
} catch (Exception $e) {
    error_log('Machines page error: ' . $e->getMessage());
}

$buildQuery = function (array $overrides = []) use ($perPage) {
    $query = array_merge([
        'per_page' => $perPage
    ], $overrides);

    $query = array_filter($query, function ($value) {
        return $value !== null && $value !== '';
    });

    return http_build_query($query);
};

renderPageStart($appTitle . ' - Machines', 'machines', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Machines</h1>
            <p class="muted">Přehled připojených strojů a jejich stavu. Celkem <strong><?= $totalMachines ?></strong> strojů.</p>
        </div>
        <div class="toolbar">
            <a class="btn" href="/machines.php?<?= htmlspecialchars($buildQuery()) ?>">Obnovit</a>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table data-table-compact" id="machinesTable">
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>IP adresa</th>
                        <th>Stav</th>
                        <th>Poslední heartbeat</th>
                        <th>Poslední push</th>
                        <th>Alerty</th>
                        <th>Rozhodnutí</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($machines)): ?>
                        <tr><td colspan="7" class="muted">Žádná data</td></tr>
                    <?php else: ?>
                        <?php foreach ($machines as $machine): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $machine['machine_id']) ?></td>
                                <td><?= htmlspecialchars((string) ($machine['ip_address'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) $machine['status']) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($machine['last_heartbeat'])) ?></td>
                                <td><?= htmlspecialchars(formatDateTime($machine['last_push'])) ?></td>
                                <td><?= (int) $machine['alerts_count'] ?></td>
                                <td><?= (int) $machine['decisions_count'] ?></td>
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
            <a class="pagination-link <?= $page === 1 ? 'disabled' : '' ?>" href="/machines.php?<?= htmlspecialchars($buildQuery(['page' => $prevPage])) ?>">&laquo; Předchozí</a>
            <?php foreach ($pages as $pageNumber): ?>
                <?php if ($pageNumber === '...'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php else: ?>
                    <a class="pagination-link <?= (int) $pageNumber === $page ? 'active' : '' ?>" href="/machines.php?<?= htmlspecialchars($buildQuery(['page' => $pageNumber])) ?>">
                        <?= $pageNumber ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="pagination-link <?= $page === $totalPages ? 'disabled' : '' ?>" href="/machines.php?<?= htmlspecialchars($buildQuery(['page' => $nextPage])) ?>">Další &raquo;</a>
        </div>
    <?php endif; ?>
<?php
renderPageEnd();
