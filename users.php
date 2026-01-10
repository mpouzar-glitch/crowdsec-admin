<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$env = loadEnv();
$appTitle = $env['APP_TITLE'] ?? 'CrowdSec Admin';
$users = getAuthUsers();
$current = getCurrentUser();

renderPageStart($appTitle . ' - Users', 'users', $appTitle);
?>
    <section class="page-header">
        <div>
            <h1>Uživatelé</h1>
            <p class="muted">Přehled lokálních uživatelských účtů.</p>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Uživatel</th>
                        <th>Role</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td>Administrator</td>
                            <td>
                                <?php if ($current && $current['username'] === $user['username']): ?>
                                    <span class="badge badge-active">Aktivní relace</span>
                                <?php else: ?>
                                    <span class="badge badge-muted">Bez relace</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php
renderPageEnd();
