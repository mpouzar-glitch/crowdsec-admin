<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$entries = readAuditLog(200);

renderPageStart($appTitle . ' - Audit Log', 'audit', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Audit log</h1>
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
                            <tr>
                                <td><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($entry['timestamp']))) ?></td>
                                <td><?= htmlspecialchars($entry['user']) ?></td>
                                <td><?= htmlspecialchars($entry['action']) ?></td>
                                <td class="table-details"><?= htmlspecialchars(json_encode($entry['details'])) ?></td>
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
