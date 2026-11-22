<?php
/**
 * Shared Wallet Transaction Ledger inside OTP Store Portal
 * Displays unified wallet transactions across Deposits, OTP Activations, SMM Orders, and Refunds.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];
$pageTitle = 'Wallet Transactions';

$filter = Validator::str($_GET['filter'] ?? 'all', 30);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ["user_id = ?"];
$params = [$userId];

if ($filter === 'otp') {
    $where[] = "reference_type = 'otp_activation'";
} elseif ($filter === 'otp_api') {
    $where[] = "reference_type = 'otp_activation' AND description LIKE 'OTP API%'";
} elseif ($filter === 'smm') {
    $where[] = "reference_type = 'order'";
} elseif ($filter === 'deposits') {
    $where[] = "type IN ('deposit','bonus','admin_credit')";
} elseif ($filter === 'refunds') {
    $where[] = "type = 'refund'";
}

$whereSql = implode(' AND ', $where);
$total = (int)Database::fetchColumn("SELECT COUNT(*) FROM wallet_transactions WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$txs = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex flex-wrap items-center gap-2">
        <?php foreach (['all' => 'All Activity', 'otp' => 'OTP Store', 'otp_api' => 'OTP API', 'smm' => 'SMM Store', 'deposits' => 'Deposits', 'refunds' => 'Refunds'] as $k => $lbl): ?>
        <a href="?filter=<?= $k ?>" class="px-4 py-2 rounded-xl text-xs font-extrabold transition <?= $filter === $k ? 'bg-purple-600 text-white shadow-sm' : 'bg-white border border-purple-100 text-slate-700 hover:bg-purple-50' ?>">
            <?= Validator::e($lbl) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <a href="/user/add-funds.php?store=otp" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-sm">+ Add Funds</a>
</div>

<div class="bg-white rounded-3xl border border-purple-100 shadow-xs overflow-hidden">
    <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between">
        <h2 class="text-sm font-extrabold text-slate-900">Shared Wallet Ledger</h2>
        <span class="text-xs font-extrabold text-purple-700 tabular-nums">Balance: <?= Currency::renderMoney((string)$user['wallet_balance'], null, 2, true) ?></span>
    </div>

    <?php if (empty($txs)): ?>
    <div class="p-10 text-center text-xs font-semibold text-slate-500">No wallet transactions found for this filter.</div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-5 py-3">ID</th>
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Description</th>
                    <th class="px-5 py-3">Amount</th>
                    <th class="px-5 py-3">Balance After</th>
                    <th class="px-5 py-3">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php foreach ($txs as $t):
                    $isCredit = in_array($t['type'], ['deposit', 'refund', 'bonus', 'admin_credit', 'referral'], true);
                ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-5 py-3.5 font-mono text-slate-500">#<?= (int)$t['id'] ?></td>
                    <td class="px-5 py-3.5">
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase <?= $isCredit ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>">
                            <?= Validator::e(str_replace('_', ' ', $t['type'])) ?>
                        </span>
                    </td>
                    <td class="px-5 py-3.5 font-semibold text-slate-800"><?= Validator::e($t['description'] ?: 'Wallet transaction') ?></td>
                    <td class="px-5 py-3.5 font-extrabold tabular-nums <?= $isCredit ? 'text-emerald-600' : 'text-rose-600' ?>">
                        <?= $isCredit ? '+' : '-' ?><?= Currency::renderMoney((string)$t['amount'], null, 2, true) ?>
                    </td>
                    <td class="px-5 py-3.5 font-bold text-slate-700 tabular-nums"><?= Currency::renderMoney((string)$t['balance_after'], null, 2, true) ?></td>
                    <td class="px-5 py-3.5 text-slate-500 whitespace-nowrap"><?= date('M d, Y H:i', strtotime($t['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 border-t border-purple-50 flex items-center justify-between text-xs">
        <span class="text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="?filter=<?= urlencode($filter) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-lg bg-slate-100 font-bold">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?filter=<?= urlencode($filter) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
