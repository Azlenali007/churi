<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('logs.view');
$pageTitle = 'System Logs & Health Monitor';

$tab = $_GET['tab'] ?? 'admin_activity';
$allowedTabs = ['admin_activity', 'user_activity', 'reseller_api', 'provider_api', 'webhooks', 'failed_orders', 'php_errors', 'health'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'admin_activity';
}

$errorLogFile = LOGS_PATH . '/php_errors.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_php_log') {
    CSRF::requireValid();
    if (file_exists($errorLogFile)) {
        @file_put_contents($errorLogFile, '');
    }
    Logger::activity('admin', (int)$admin['id'], 'logs.clear_php_error', 'log', 'php_errors.log', 'Cleared PHP error log');
    Helpers::setFlash('success', 'PHP error log cleared.');
    Helpers::redirect('/admin/logs.php?tab=php_errors');
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap gap-2 mb-6 text-xs font-bold">
    <?php
    $tabLabels = [
        'admin_activity' => 'Admin Activity',
        'user_activity'  => 'User Activity',
        'reseller_api'   => 'Reseller API Logs',
        'provider_api'   => 'Provider API Logs',
        'webhooks'       => 'Webhook Logs',
        'failed_orders'  => 'Failed Orders',
        'php_errors'     => 'PHP Error Log',
        'health'         => 'System Health & DB',
    ];
    foreach ($tabLabels as $k => $lbl):
        $active = $tab === $k;
    ?>
    <a href="/admin/logs.php?tab=<?= $k ?>" class="px-4 py-2 rounded-xl border transition <?= $active ? 'bg-purple-600 text-white border-purple-600 shadow' : 'bg-white text-slate-600 border-purple-100 hover:bg-purple-50' ?>">
        <?= Validator::e($lbl) ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="smm-card rounded-3xl p-6">
    <?php if ($tab === 'admin_activity' || $tab === 'user_activity'):
        $actorType = $tab === 'admin_activity' ? 'admin' : 'user';
        $rows = $actorType === 'admin'
            ? Database::fetchAll("SELECT l.*, a.username AS actor_name FROM activity_logs l LEFT JOIN admins a ON a.id = l.actor_id WHERE l.actor_type = 'admin' ORDER BY l.id DESC LIMIT 100")
            : Database::fetchAll("SELECT l.*, u.username AS actor_name FROM activity_logs l LEFT JOIN users u ON u.id = l.actor_id WHERE l.actor_type = 'user' ORDER BY l.id DESC LIMIT 100");
    ?>
        <h2 class="text-base font-extrabold text-slate-900 mb-4"><?= Validator::e($tabLabels[$tab]) ?> (Latest 100)</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Time</th><th class="py-2">Actor</th><th class="py-2">Action</th><th class="py-2">Entity</th><th class="py-2">Details</th><th class="py-2">IP</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="py-2.5 text-slate-500 whitespace-nowrap"><?= Validator::e($r['created_at']) ?></td>
                        <td class="py-2.5 font-bold"><?= $r['actor_name'] ? '@' . Validator::e($r['actor_name']) : '#' . (int)$r['actor_id'] ?></td>
                        <td class="py-2.5 font-mono text-purple-700"><?= Validator::e($r['action']) ?></td>
                        <td class="py-2.5"><?= Validator::e(($r['entity_type'] ?? '') . ' #' . ($r['entity_id'] ?? '')) ?></td>
                        <td class="py-2.5 text-slate-600"><?= Validator::e((string)$r['details']) ?></td>
                        <td class="py-2.5 font-mono text-slate-400"><?= Validator::e((string)$r['ip_address']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)) echo '<tr><td colspan="6" class="py-8 text-center text-slate-400">No activity logs recorded yet.</td></tr>'; ?>
                </tbody>
            </table>
        </div>

    <?php elseif (in_array($tab, ['reseller_api', 'provider_api', 'webhooks'], true)):
        $typeMap = ['reseller_api' => 'reseller_in', 'provider_api' => 'provider_out', 'webhooks' => 'payment_webhook'];
        $logType = $typeMap[$tab];
        $rows = Database::fetchAll("SELECT * FROM api_logs WHERE log_type = ? ORDER BY id DESC LIMIT 100", [$logType]);
    ?>
        <h2 class="text-base font-extrabold text-slate-900 mb-4"><?= Validator::e($tabLabels[$tab]) ?> (Latest 100)</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Time</th><th class="py-2">Endpoint</th><th class="py-2">HTTP</th><th class="py-2">Status</th><th class="py-2">Request</th><th class="py-2">Response</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($rows as $r): ?>
                    <tr class="align-top">
                        <td class="py-2.5 text-slate-500 whitespace-nowrap"><?= Validator::e($r['created_at']) ?></td>
                        <td class="py-2.5 font-mono text-slate-800 max-w-[180px] break-all"><?= Validator::e((string)$r['endpoint']) ?></td>
                        <td class="py-2.5 font-bold"><?= (int)$r['http_code'] ?></td>
                        <td class="py-2.5"><?= Helpers::statusBadge((string)$r['status']) ?></td>
                        <td class="py-2.5 font-mono text-[11px] max-w-[260px] break-all text-slate-600"><?= Validator::e(mb_substr((string)$r['request_payload'], 0, 260, 'UTF-8')) ?></td>
                        <td class="py-2.5 font-mono text-[11px] max-w-[260px] break-all text-slate-600"><?= Validator::e(mb_substr((string)$r['response_payload'], 0, 260, 'UTF-8')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)) echo '<tr><td colspan="6" class="py-8 text-center text-slate-400">No API logs recorded yet.</td></tr>'; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'failed_orders'):
        $rows = Database::fetchAll(
            "SELECT o.*, u.username, s.name AS service_name, p.name AS provider_name
             FROM orders o
             JOIN users u ON u.id = o.user_id
             JOIN services s ON s.id = o.service_id
             LEFT JOIN providers p ON p.id = o.provider_id
             WHERE o.status = 'failed'
             ORDER BY o.id DESC LIMIT 100"
        );
    ?>
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Failed Order Logs</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Order</th><th class="py-2">User</th><th class="py-2">Service / Provider</th><th class="py-2">Provider Response</th><th class="py-2">Date</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="py-2.5 font-bold">#<?= (int)$r['id'] ?></td>
                        <td class="py-2.5">@<?= Validator::e($r['username']) ?></td>
                        <td class="py-2.5"><div class="font-semibold"><?= Validator::e($r['service_name']) ?></div><div class="text-slate-400"><?= Validator::e($r['provider_name'] ?? 'Local') ?></div></td>
                        <td class="py-2.5 font-mono text-rose-600 max-w-md break-all"><?= Validator::e((string)$r['provider_response']) ?></td>
                        <td class="py-2.5 text-slate-500"><?= Validator::e($r['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)) echo '<tr><td colspan="5" class="py-8 text-center text-slate-400">No failed orders recorded.</td></tr>'; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'php_errors'):
        $logContent = file_exists($errorLogFile) ? (string)file_get_contents($errorLogFile) : '';
        $lines = $logContent !== '' ? array_slice(explode("\n", trim($logContent)), -300) : [];
    ?>
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">PHP Error Log (`/storage/logs/php_errors.log`)</h2>
            <form method="POST">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="clear_php_log">
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold">Clear Log File</button>
            </form>
        </div>
        <pre class="p-4 rounded-2xl bg-slate-900 text-emerald-300 text-[11px] font-mono overflow-x-auto max-h-[500px] leading-relaxed"><?= empty($lines) ? 'No PHP errors logged.' : Validator::e(implode("\n", $lines)) ?></pre>

    <?php else:
        $tables = Database::pdo()->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Database Table Status &amp; Cron Endpoints</h2>
        <div class="p-4 rounded-2xl bg-purple-50 border border-purple-100 text-xs mb-5 space-y-1">
            <div class="font-bold text-purple-900">Recommended cPanel Cron Commands:</div>
            <div class="font-mono text-slate-700">*/2 * * * * php <?= Validator::e(APP_ROOT) ?>/cron/sync_orders.php</div>
            <div class="font-mono text-slate-700">0 */6 * * * php <?= Validator::e(APP_ROOT) ?>/cron/sync_providers.php</div>
            <div class="font-mono text-slate-700">*/5 * * * * php <?= Validator::e(APP_ROOT) ?>/cron/expire_sales.php</div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Table</th><th class="py-2">Engine</th><th class="py-2">Rows</th><th class="py-2">Data Size</th><th class="py-2">Collation</th></tr></thead>
                <tbody class="divide-y">
                    <?php foreach ($tables as $t): ?>
                    <tr>
                        <td class="py-2 font-mono font-bold text-slate-800"><?= Validator::e($t['Name']) ?></td>
                        <td class="py-2"><?= Validator::e((string)$t['Engine']) ?></td>
                        <td class="py-2"><?= number_format((int)$t['Rows']) ?></td>
                        <td class="py-2"><?= round(((int)$t['Data_length'] + (int)$t['Index_length']) / 1024, 1) ?> KB</td>
                        <td class="py-2 text-slate-500"><?= Validator::e((string)$t['Collation']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
