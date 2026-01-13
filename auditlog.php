<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$entries = readAuditLog(200);

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

renderPageStart($appTitle . ' - Audit Log', 'audit', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1><i class="fa-solid fa-clipboard-check"></i> Audit log</h1>
            <p class="muted">Aktivity uživatelů a zásahy do systému.</p>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table">
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
                                    <span class="audit-action <?= htmlspecialchars($actionMeta['class']) ?>">
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
        </div>
    </section>
<?php
renderPageEnd();
