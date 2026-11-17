<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('reports.view');
$pageTitle = 'Reports, Analytics & CSV Export';

if (isset($_GET['export'])) {
    $type = $_GET['export'];
    if ($type === 'orders') {
        $rows = Database::fetchAll("SELECT o.id, u.username, s.name, o.quantity, o.charge, o.provider_cost, o.profit, o.status, o.created_at FROM orders o JOIN users u ON u.id=o.user_id JOIN services s ON s.id=o.service_id ORDER BY o.id DESC");
        Helpers::streamCsv('orders_report_' . date('Ymd') . '.csv', ['Order ID','User','Service','Qty','Charge','Provider Cost','Profit','Status','Created At'], $rows);
    } elseif ($type === 'payments') {
        $rows = Database::fetchAll("SELECT p.id, u.username, p.gateway_code, p.transaction_ref, p.amount, p.net_amount, p.status, p.created_at FROM payments p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC");
        Helpers::streamCsv('payments_report_' . date('Ymd') . '.csv', ['ID','User','Gateway','Reference','Amount','Net Amount','Status','Date'], $rows);
    }
}

$dailySales = Database::fetchAll(
    "SELECT DATE(created_at) AS day, COUNT(*) AS orders_cnt, COALESCE(SUM(charge - refunded_amount), 0) AS revenue, COALESCE(SUM(profit), 0) AS profit
     FROM orders WHERE status NOT IN ('canceled','failed')
     GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 30"
);

$providerProfit = Database::fetchAll(
    "SELECT COALESCE(p.name, 'Local / Manual') AS provider_name, COUNT(o.id) AS orders_cnt,
            COALESCE(SUM(o.charge - o.refunded_amount), 0) AS revenue, COALESCE(SUM(o.profit), 0) AS profit
     FROM orders o LEFT JOIN providers p ON p.id = o.provider_id
     WHERE o.status NOT IN ('canceled','failed')
     GROUP BY o.provider_id ORDER BY revenue DESC"
);

$topServices = Database::fetchAll(
    "SELECT s.name, COUNT(o.id) AS orders_cnt, COALESCE(SUM(o.charge - o.refunded_amount), 0) AS revenue, COALESCE(SUM(o.profit), 0) AS profit
     FROM orders o JOIN services s ON s.id = o.service_id
     WHERE o.status NOT IN ('canceled','failed')
     GROUP BY o.service_id ORDER BY revenue DESC LIMIT 15"
);

$topUsers = Database::fetchAll(
    "SELECT username, email, wallet_balance, total_spent FROM users ORDER BY total_spent DESC LIMIT 15"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap gap-3 mb-6">
    <a href="/admin/reports.php?export=orders" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white text-xs font-bold shadow">Export Orders CSV</a>
    <a href="/admin/reports.php?export=payments" class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white text-xs font-bold shadow">Export Payments CSV</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Daily Sales &amp; Profit (Last 30 Days)</h2>
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Date</th><th class="py-2">Orders</th><th class="py-2">Revenue</th><th class="py-2 text-right">Profit</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($dailySales as $ds): ?>
                <tr><td class="py-2 font-bold"><?= Validator::e($ds['day']) ?></td><td class="py-2"><?= (int)$ds['orders_cnt'] ?></td><td class="py-2"><?= Currency::format((string)$ds['revenue']) ?></td><td class="py-2 text-right font-bold text-emerald-600"><?= Currency::format((string)$ds['profit']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($dailySales)) echo '<tr><td colspan="4" class="py-6 text-center text-slate-400">No sales recorded yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Provider-Wise Profit</h2>
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Provider</th><th class="py-2">Orders</th><th class="py-2">Revenue</th><th class="py-2 text-right">Profit</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($providerProfit as $pp): ?>
                <tr><td class="py-2 font-bold"><?= Validator::e($pp['provider_name']) ?></td><td class="py-2"><?= (int)$pp['orders_cnt'] ?></td><td class="py-2"><?= Currency::format((string)$pp['revenue']) ?></td><td class="py-2 text-right font-bold text-emerald-600"><?= Currency::format((string)$pp['profit']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($providerProfit)) echo '<tr><td colspan="4" class="py-6 text-center text-slate-400">No data yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Service-Wise Revenue &amp; Profit</h2>
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Service</th><th class="py-2">Orders</th><th class="py-2 text-right">Profit</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($topServices as $ts): ?>
                <tr><td class="py-2 font-semibold truncate max-w-[220px]"><?= Validator::e($ts['name']) ?></td><td class="py-2"><?= (int)$ts['orders_cnt'] ?></td><td class="py-2 text-right font-bold text-emerald-600"><?= Currency::format((string)$ts['profit']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($topServices)) echo '<tr><td colspan="3" class="py-6 text-center text-slate-400">No data yet.</td></tr>'; ?>
            </tbody>
        </table>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">User-Wise Spending</h2>
        <table class="w-full text-left text-xs">
            <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">User</th><th class="py-2">Wallet</th><th class="py-2 text-right">Total Spent</th></tr></thead>
            <tbody class="divide-y">
                <?php foreach ($topUsers as $tu): ?>
                <tr><td class="py-2 font-bold">@<?= Validator::e($tu['username']) ?></td><td class="py-2"><?= Currency::format((string)$tu['wallet_balance']) ?></td><td class="py-2 text-right font-bold text-purple-700"><?= Currency::format((string)$tu['total_spent']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
