<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'action' => trim((string) ($_GET['action'] ?? '')),
    'user' => trim((string) ($_GET['user'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? ''))
];
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$auditResult = readAuditLogPage($filters, $page, $perPage);
$entries = $auditResult['entries'];
$totalEntries = $auditResult['total'];
$page = $auditResult['page'];
$totalPages = $auditResult['total_pages'];
$actions = readAuditActions();

$queryFilters = array_filter($filters, function ($value) {
    return $value !== '' && $value !== null;
});

$buildAuditQuery = function (array $overrides = []) use ($queryFilters) {
    return http_build_query(array_merge($queryFilters, $overrides));
};

function auditActionMeta($action) {
    $actionLower = strtolower((string) $action);
    $meta = [
        'class' => 'info',
        'icon' => 'fa-circle-info'
    ];

    if (str_contains($actionLower, 'delete') || str_contains($actionLower, 'remove')) {
        $meta['class'] = 'danger';
        $meta['icon'] = 'fa-trash-can';
    } elseif (str_contains($actionLower, 'create') || str_contains($actionLower, 'add')) {
        $meta['class'] = 'success';
        $meta['icon'] = 'fa-circle-plus';
    } elseif (str_contains($actionLower, 'update') || str_contains($actionLower, 'edit')) {
        $meta['class'] = 'warning';
        $meta['icon'] = 'fa-pen-to-square';
    } elseif (str_contains($actionLower, 'login')) {
        $meta['class'] = 'success';
        $meta['icon'] = 'fa-right-to-bracket';
    } elseif (str_contains($actionLower, 'logout')) {
        $meta['class'] = 'warning';
        $meta['icon'] = 'fa-right-from-bracket';
    } elseif (str_contains($actionLower, 'maintenance')) {
        $meta['class'] = 'info';
        $meta['icon'] = 'fa-screwdriver-wrench';
    }

    return $meta;
}

function auditDetailIcon($key) {
    $keyLower = strtolower((string) $key);
    $icons = [
        'username' => 'fa-user',
        'user' => 'fa-user',
        'reason' => 'fa-message',
        'ip' => 'fa-globe',
        'ip_address' => 'fa-globe',
        'decision' => 'fa-gavel',
        'alert' => 'fa-bell',
        'count' => 'fa-hashtag',
        'deleted' => 'fa-trash-can',
        'removed' => 'fa-trash-can',
        'created' => 'fa-circle-plus',
        'source' => 'fa-database',
        'target' => 'fa-bullseye'
    ];

    return $icons[$keyLower] ?? 'fa-circle-info';
}

function formatAuditValue($value) {
    if (is_array($value)) {
        return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if (is_bool($value)) {
        return $value ? 'Ano' : 'Ne';
    }

    if ($value === null || $value === '') {
        return '-';
    }

    return htmlspecialchars((string) $value);
}

function renderAuditDetails($details) {
    if ($details === null || $details === '') {
        return '<span class="muted">-</span>';
    }

    if (is_array($details)) {
        $items = [];
        foreach ($details as $key => $value) {
            $label = htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key)));
            $icon = auditDetailIcon($key);
            $formatted = formatAuditValue($value);
            $items[] = "<li><span class=\"audit-detail-key\"><i class=\"fas {$icon}\"></i>{$label}:</span><span class=\"audit-detail-value\">{$formatted}</span></li>";
        }

        return '<ul class="audit-detail-list">' . implode('', $items) . '</ul>';
    }

    return '<div class="audit-detail-plain">' . htmlspecialchars((string) $details) . '</div>';
}

function buildPaginationPages($current, $total) {
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1];
    $start = max(2, $current - 2);
    $end = min($total - 1, $current + 2);

    if ($start > 2) {
        $pages[] = '...';
    }

    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($end < $total - 1) {
        $pages[] = '...';
    }

    $pages[] = $total;
    return $pages;
}

renderPageStart($appTitle . ' - Audit Log', 'audit', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1><i class="fa-solid fa-clipboard-check"></i> Audit log</h1>
            <p class="muted">Aktivity uživatelů a zásahy do systému.</p>
        </div>
    </section>

    <form class="table-filters audit-filters" method="get">
        <div class="filter-group">
            <label for="auditSearch">Hledat</label>
            <div class="input-with-icon">
                <i class="fas fa-search"></i>
                <input type="text" id="auditSearch" name="search" placeholder="Uživatel, akce, IP, detail..." value="<?= htmlspecialchars($filters['search']) ?>">
            </div>
        </div>
        <div class="filter-group">
            <label for="auditAction">Akce</label>
            <select id="auditAction" name="action">
                <option value="">Všechny akce</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= htmlspecialchars($action) ?>" <?= $action === $filters['action'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($action) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="auditUser">Uživatel</label>
            <input type="text" id="auditUser" name="user" placeholder="Uživatelské jméno" value="<?= htmlspecialchars($filters['user']) ?>">
        </div>
        <div class="filter-group">
            <label for="auditDateFrom">Datum od</label>
            <input type="date" id="auditDateFrom" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div class="filter-group">
            <label for="auditDateTo">Datum do</label>
            <input type="date" id="auditDateTo" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <div class="filter-actions">
            <button class="btn" type="submit"><i class="fas fa-magnifying-glass"></i> Hledat</button>
            <a class="btn btn-ghost" href="/auditlog.php">Vyčistit</a>
        </div>
    </form>

    <div class="audit-toolbar">
        <span>
            Zobrazeno <strong><?= count($entries) ?></strong> z <strong><?= $totalEntries ?></strong> záznamů
            | Stránka <strong><?= $page ?></strong> z <strong><?= $totalPages ?></strong>
        </span>
    </div>

    <section class="card">
        <div class="card-body">
            <table class="data-table audit-log-table">
                <thead>
                    <tr>
                        <th>Čas</th>
                        <th>Uživatel</th>
                        <th>Akce</th>
                        <th>Detail</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="5" class="table-empty">Zatím nejsou žádné záznamy.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <?php $actionMeta = auditActionMeta($entry['action']); ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($entry['timestamp']))) ?></td>
                                <td><?= htmlspecialchars($entry['user']) ?></td>
                                <td>
                                    <span class="audit-action-text <?= htmlspecialchars($actionMeta['class']) ?>">
                                        <i class="fas <?= htmlspecialchars($actionMeta['icon']) ?>"></i>
                                        <?= htmlspecialchars($entry['action']) ?>
                                    </span>
                                </td>
                                <td class="table-details">
                                    <div class="audit-details">
                                        <?= renderAuditDetails($entry['details']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($entry['ip']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Stránkování">
                    <?php if ($page > 1): ?>
                        <a class="pagination-link" href="?<?= $buildAuditQuery(['page' => $page - 1]) ?>">
                            <i class="fas fa-chevron-left"></i> Předchozí
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled"><i class="fas fa-chevron-left"></i> Předchozí</span>
                    <?php endif; ?>

                    <?php foreach (buildPaginationPages($page, $totalPages) as $pageNumber): ?>
                        <?php if ($pageNumber === '...'): ?>
                            <span class="pagination-ellipsis">…</span>
                        <?php elseif ($pageNumber === $page): ?>
                            <span class="pagination-link active"><?= $pageNumber ?></span>
                        <?php else: ?>
                            <a class="pagination-link" href="?<?= $buildAuditQuery(['page' => $pageNumber]) ?>"><?= $pageNumber ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="pagination-link" href="?<?= $buildAuditQuery(['page' => $page + 1]) ?>">
                            Další <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-link disabled">Další <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php
renderPageEnd();
