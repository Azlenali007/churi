<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('child_panels.manage');
$pageTitle = 'Child Panel Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $cpId = (int)($_POST['panel_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($cpId > 0 && in_array($status, ['active','suspended','rejected','pending','expired'], true)) {
        Database::execute("UPDATE child_panels SET status = ? WHERE id = ?", [$status, $cpId]);
        Logger::activity('admin', (int)$admin['id'], 'child_panel.' . $status, 'child_panel', (string)$cpId);
        Helpers::setFlash('success', 'Child panel #' . $cpId . ' set to ' . $status . '.');
    } elseif (($_POST['action'] ?? '') === 'extend_30d' && $cpId > 0) {
        Database::execute("UPDATE child_panels SET expires_at = DATE_ADD(COALESCE(expires_at, NOW()), INTERVAL 30 DAY) WHERE id = ?", [$cpId]);
        Helpers::setFlash('success', 'Child panel expiry extended by 30 days.');
    }
    Helpers::redirect('/admin/child-panels.php');
}

$panels = Database::fetchAll(
    "SELECT cp.*, u.username AS owner_username,
            (SELECT COUNT(*) FROM child_panel_users cpu WHERE cpu.child_panel_id = cp.id) AS users_count,
            (SELECT COUNT(*) FROM orders o WHERE o.child_panel_id = cp.id) AS orders_count
     FROM child_panels cp
     JOIN users u ON u.id = cp.owner_user_id
     ORDER BY cp.id DESC"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="smm-card rounded-3xl p-6">
    <h2 class="text-base font-extrabold text-slate-900 mb-4">All Child Panels (<?= count($panels) ?>)</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b border-purple-100 uppercase text-slate-400"><th class="py-2.5 pr-3">Panel / Domain</th><th class="py-2.5 pr-3">Owner</th><th class="py-2.5 pr-3">Margin</th><th class="py-2.5 pr-3">Users / Orders</th><th class="py-2.5 pr-3">Expires</th><th class="py-2.5 pr-3">Status</th><th class="py-2.5 text-right">Manage</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($panels as $cp): ?>
                <tr>
                    <td class="py-3 pr-3"><div class="font-bold text-slate-900"><?= Validator::e($cp['panel_name']) ?></div><div class="text-purple-700"><?= Validator::e($cp['domain']) ?> <?= $cp['domain_verified'] ? '✓' : '(DNS pending)' ?></div></td>
                    <td class="py-3 pr-3 font-semibold">@<?= Validator::e($cp['owner_username']) ?></td>
                    <td class="py-3 pr-3"><?= Validator::e((string)$cp['profit_margin']) ?>%</td>
                    <td class="py-3 pr-3"><?= (int)$cp['users_count'] ?> users / <?= (int)$cp['orders_count'] ?> orders</td>
                    <td class="py-3 pr-3 text-slate-500"><?= $cp['expires_at'] ? date('M j, Y', strtotime($cp['expires_at'])) : 'Never' ?></td>
                    <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$cp['status']) ?></td>
                    <td class="py-3 text-right whitespace-nowrap">
                        <form method="POST" class="inline-flex gap-1">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="panel_id" value="<?= (int)$cp['id'] ?>">
                            <button type="submit" name="status" value="active" class="px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-bold">Activate</button>
                            <button type="submit" name="status" value="suspended" class="px-2.5 py-1 rounded-lg bg-amber-100 text-amber-800 font-bold">Suspend</button>
                            <button type="submit" name="action" value="extend_30d" class="px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 font-bold">+30d</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($panels)) echo '<tr><td colspan="7" class="py-8 text-center text-slate-500">No child panels created yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
