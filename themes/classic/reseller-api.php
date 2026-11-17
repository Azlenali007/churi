<?php
$pageTitle = 'Reseller API';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/partials/header.php';

$apiUrl = rtrim((string)($GLOBALS['APP_CONFIG']['app']['url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/') . '/api/v2.php';
$apiOrders = Database::fetchAll(
    "SELECT o.*, s.name AS service_name FROM orders o JOIN services s ON s.id = o.service_id WHERE o.user_id = ? AND o.is_api_order = 1 ORDER BY o.id DESC LIMIT 20",
    [$user['id']]
);
?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <div class="lg:col-span-5 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-lg font-extrabold text-slate-900">API Credentials</h1>
                <?= Helpers::statusBadge((string)$user['api_status']) ?>
            </div>
            <div class="space-y-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-500 uppercase mb-1">API Endpoint URL</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="<?= Validator::e($apiUrl) ?>" class="flex-1 px-3 py-2 rounded-xl bg-slate-50 border border-purple-100 font-mono text-xs">
                        <button type="button" onclick="copyText('<?= Validator::e($apiUrl) ?>', 'API URL copied')" class="px-3 py-2 rounded-xl bg-purple-100 text-purple-800 font-bold">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="block font-bold text-slate-500 uppercase mb-1">Your Secret API Key</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="<?= Validator::e($user['api_key'] ?: 'Not generated yet') ?>" class="flex-1 px-3 py-2 rounded-xl bg-slate-50 border border-purple-100 font-mono text-xs">
                        <?php if ($user['api_key']): ?>
                        <button type="button" onclick="copyText('<?= Validator::e($user['api_key']) ?>', 'API Key copied')" class="px-3 py-2 rounded-xl bg-purple-100 text-purple-800 font-bold">Copy</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="p-3.5 rounded-2xl bg-[#F3F1FC] flex items-center justify-between">
                    <span class="font-semibold text-slate-600">API Wallet Balance</span>
                    <span class="text-base font-extrabold text-purple-800 tabular-nums"><?= Currency::display((string)$user['wallet_balance'], null, 2, true) ?></span>
                </div>

                <form method="POST" action="/user/reseller-api.php">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="regenerate_key">
                    <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-md">
                        <?= $user['api_key'] ? 'Regenerate API Key' : 'Generate API Key' ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Recent API Orders</h2>
            <?php if (empty($apiOrders)): ?>
                <p class="text-xs text-slate-500">No orders placed via API yet.</p>
            <?php else: ?>
                <div class="space-y-2 text-xs">
                    <?php foreach ($apiOrders as $ao): ?>
                    <div class="p-3 rounded-xl bg-slate-50 flex items-center justify-between">
                        <div class="min-w-0">
                            <div class="font-bold text-slate-800">#<?= (int)$ao['id'] ?> — <?= Validator::e($ao['service_name']) ?></div>
                            <div class="text-slate-500 truncate"><?= Validator::e($ao['link']) ?></div>
                        </div>
                        <?= Helpers::statusBadge((string)$ao['status']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg:col-span-7 smm-card rounded-3xl p-6">
        <h2 class="text-lg font-extrabold text-slate-900 mb-4">SMM Reseller API v2 Documentation</h2>
        <div class="space-y-4 text-xs text-slate-600">
            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                <div class="font-bold text-slate-900 mb-1">HTTP Method: POST (application/x-www-form-urlencoded or JSON)</div>
                <div>Endpoint: <code><?= Validator::e($apiUrl) ?></code></div>
            </div>

            <div>
                <h3 class="font-bold text-slate-900 mb-1">1. Services List (<code>action=services</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=services</pre>
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-1">2. Add Order (<code>action=add</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=add&amp;service=1&amp;link=https://instagram.com/user&amp;quantity=1000</pre>
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-1">3. Order Status (<code>action=status</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=status&amp;order=101</pre>
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-1">4. Multiple Orders Status (<code>action=status</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=status&amp;orders=101,102,103</pre>
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-1">5. Account Balance (<code>action=balance</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=balance</pre>
            </div>
            <div>
                <h3 class="font-bold text-slate-900 mb-1">6. Refill / Cancel (<code>action=refill</code> / <code>action=cancel</code>)</h3>
                <pre class="p-3 rounded-xl bg-[#1B1145] text-purple-100 overflow-x-auto">POST key=YOUR_API_KEY&amp;action=refill&amp;order=101</pre>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
