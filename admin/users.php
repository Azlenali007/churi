<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('users.view');
$pageTitle = 'User Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    if (!Auth::adminHasPermission($admin, 'users.manage')) {
        Helpers::setFlash('error', 'You do not have permission to modify users.');
        Helpers::redirect('/admin/users.php');
    }

    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($action === 'add_user') {
        $uname = Validator::username($_POST['username'] ?? '');
        $email = Validator::email($_POST['email'] ?? '');
        $name = Validator::str($_POST['full_name'] ?? '', 120);
        $pass = (string)($_POST['password'] ?? '');
        if (!$uname || !$email || $name === '' || strlen($pass) < 8) {
            Helpers::setFlash('error', 'Valid username, email, name, and 8+ char password required.');
        } elseif (Database::fetch("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1", [$uname, $email])) {
            Helpers::setFlash('error', 'Username or email already exists.');
        } else {
            $newId = Database::insert(
                "INSERT INTO users (username, email, password_hash, full_name, api_key, referral_code, email_verified_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), 'active')",
                [$uname, $email, password_hash($pass, PASSWORD_DEFAULT), $name, Auth::generateApiKey(), Auth::generateReferralCode()]
            );
            Logger::activity('admin', (int)$admin['id'], 'user.create', 'user', (string)$newId);
            Helpers::setFlash('success', 'User created successfully.');
        }
    } elseif ($action === 'edit_user' && $uid > 0) {
        $name = Validator::str($_POST['full_name'] ?? '', 120);
        $email = Validator::email($_POST['email'] ?? '');
        $discount = Currency::normalize((string)($_POST['custom_rate_discount'] ?? '0'), 2);
        $notes = Validator::str($_POST['admin_notes'] ?? '', 2000);
        $status = in_array($_POST['status'] ?? '', ['active','suspended','pending'], true) ? $_POST['status'] : 'active';
        if ($email) {
            Database::execute(
                "UPDATE users SET full_name = ?, email = ?, custom_rate_discount = ?, admin_notes = ?, status = ? WHERE id = ?",
                [$name, $email, $discount, $notes, $status, $uid]
            );
            Logger::activity('admin', (int)$admin['id'], 'user.edit', 'user', (string)$uid);
            Helpers::setFlash('success', 'User #' . $uid . ' updated.');
        }
    } elseif ($action === 'wallet_adjust' && $uid > 0) {
        $mode = $_POST['mode'] ?? 'add';
        $amt = Currency::normalize((string)($_POST['amount'] ?? '0'), 4);
        $reason = Validator::str($_POST['reason'] ?? 'Admin balance adjustment', 200);
        $res = $mode === 'deduct'
            ? Wallet::debit($uid, $amt, 'admin_deduct', $reason, 'admin', (string)$admin['id'])
            : Wallet::credit($uid, $amt, 'admin_add', $reason, 'admin', (string)$admin['id']);
        Helpers::setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Wallet balance updated.' : ($res['error'] ?? 'Failed'));
        Logger::activity('admin', (int)$admin['id'], 'user.wallet_' . $mode, 'user', (string)$uid, $amt . ' - ' . $reason);
    } elseif ($action === 'reset_password' && $uid > 0) {
        $newPass = (string)($_POST['new_password'] ?? '');
        if (strlen($newPass) >= 8) {
            Database::execute("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($newPass, PASSWORD_DEFAULT), $uid]);
            Helpers::setFlash('success', 'User password reset.');
        } else {
            Helpers::setFlash('error', 'Password must be at least 8 characters.');
        }
    } elseif ($action === 'delete_user' && $uid > 0) {
        try {
            Database::execute("DELETE FROM users WHERE id = ?", [$uid]);
            Helpers::setFlash('success', 'User deleted.');
        } catch (Throwable $e) {
            Database::execute("UPDATE users SET status = 'suspended' WHERE id = ?", [$uid]);
            Helpers::setFlash('error', 'User has linked financial/order records and was suspended instead of deleted to preserve ledger integrity.');
        }
    }

    Helpers::redirect('/admin/users.php' . ($uid > 0 && $action !== 'delete_user' ? '?view=' . $uid : ''));
}

$search = Validator::str($_GET['search'] ?? '', 100);
$viewId = (int)($_GET['view'] ?? 0);

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ? OR id = ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = ctype_digit($search) ? (int)$search : 0;
}
$sql .= " ORDER BY id DESC LIMIT 100";
$users = Database::fetchAll($sql, $params);

$viewUser = $viewId > 0 ? Database::fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$viewId]) : null;
$viewOrders = $viewUser ? Database::fetchAll("SELECT o.*, s.name AS service_name FROM orders o JOIN services s ON s.id = o.service_id WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 20", [$viewId]) : [];
$viewTx = $viewUser ? Database::fetchAll("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 20", [$viewId]) : [];
$viewLogins = $viewUser ? Database::fetchAll("SELECT * FROM login_attempts WHERE user_type = 'user' AND (identifier = ? OR identifier = ?) ORDER BY id DESC LIMIT 15", [strtolower($viewUser['email']), strtolower($viewUser['username'])]) : [];

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
    <div class="<?= $viewUser ? 'xl:col-span-7' : 'xl:col-span-12' ?> space-y-6">
        <div class="smm-card rounded-3xl p-6" x-data="{ showAdd: false }">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <form method="GET" class="flex gap-2">
                    <input type="text" name="search" value="<?= Validator::e($search) ?>" placeholder="Search user, email, ID..." class="px-4 py-2 rounded-xl border border-purple-200 text-sm outline-none w-56">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold">Search</button>
                </form>
                <button type="button" @click="showAdd = !showAdd" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold">+ Add User</button>
            </div>

            <form x-show="showAdd" x-cloak method="POST" class="mb-6 p-4 rounded-2xl bg-[#F6F4FC] grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_user">
                <input type="text" name="full_name" required placeholder="Full Name" class="px-3 py-2 rounded-xl border border-purple-200 text-xs">
                <input type="text" name="username" required placeholder="Username" class="px-3 py-2 rounded-xl border border-purple-200 text-xs">
                <input type="email" name="email" required placeholder="Email" class="px-3 py-2 rounded-xl border border-purple-200 text-xs">
                <input type="password" name="password" required minlength="8" placeholder="Password (8+)" class="px-3 py-2 rounded-xl border border-purple-200 text-xs">
                <button type="submit" class="py-2 px-4 rounded-xl bg-purple-600 text-white text-xs font-bold">Create</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2.5 pr-3">ID</th>
                            <th class="py-2.5 pr-3">User</th>
                            <th class="py-2.5 pr-3">Balance</th>
                            <th class="py-2.5 pr-3">Spent</th>
                            <th class="py-2.5 pr-3">Status</th>
                            <th class="py-2.5 text-right">Manage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-purple-50/40">
                            <td class="py-3 pr-3 font-bold">#<?= (int)$u['id'] ?></td>
                            <td class="py-3 pr-3">
                                <div class="font-bold text-slate-900"><?= Validator::e($u['full_name']) ?> (@<?= Validator::e($u['username']) ?>)</div>
                                <div class="text-slate-500"><?= Validator::e($u['email']) ?></div>
                            </td>
                            <td class="py-3 pr-3 font-bold text-purple-700"><?= Currency::format((string)$u['wallet_balance']) ?></td>
                            <td class="py-3 pr-3"><?= Currency::format((string)$u['total_spent']) ?></td>
                            <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$u['status']) ?></td>
                            <td class="py-3 text-right">
                                <a href="/admin/users.php?view=<?= (int)$u['id'] ?>" class="px-3 py-1.5 rounded-lg bg-purple-100 text-purple-800 font-bold">Manage</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">No users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($viewUser): ?>
    <div class="xl:col-span-5 space-y-5">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Edit User #<?= (int)$viewUser['id'] ?></h2>
                <a href="/admin/users.php" class="text-xs font-bold text-slate-500">Close &times;</a>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?= (int)$viewUser['id'] ?>">
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" name="full_name" value="<?= Validator::e($viewUser['full_name']) ?>" required class="px-3 py-2 rounded-xl border border-purple-200">
                    <input type="email" name="email" value="<?= Validator::e($viewUser['email']) ?>" required class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <select name="status" class="px-3 py-2 rounded-xl border border-purple-200">
                        <option value="active" <?= $viewUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $viewUser['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        <option value="pending" <?= $viewUser['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                    <input type="number" step="0.01" name="custom_rate_discount" value="<?= Validator::e((string)$viewUser['custom_rate_discount']) ?>" placeholder="Discount %" class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <textarea name="admin_notes" rows="2" placeholder="Internal admin notes..." class="w-full px-3 py-2 rounded-xl border border-purple-200"><?= Validator::e((string)$viewUser['admin_notes']) ?></textarea>
                <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Save User</button>
            </form>

            <!-- Wallet Adjust -->
            <form method="POST" class="mt-5 pt-4 border-t border-slate-100 space-y-2.5 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="wallet_adjust">
                <input type="hidden" name="user_id" value="<?= (int)$viewUser['id'] ?>">
                <div class="font-bold text-slate-800">Wallet Adjustment (Current: <?= Currency::format((string)$viewUser['wallet_balance']) ?>)</div>
                <div class="grid grid-cols-3 gap-2">
                    <select name="mode" class="px-3 py-2 rounded-xl border border-purple-200">
                        <option value="add">+ Add Funds</option>
                        <option value="deduct">- Deduct</option>
                    </select>
                    <input type="number" step="0.01" min="0.01" name="amount" required placeholder="Amount" class="px-3 py-2 rounded-xl border border-purple-200">
                    <input type="text" name="reason" placeholder="Reason" class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-bold">Apply Wallet Change</button>
            </form>

            <!-- Password Reset & Delete -->
            <div class="mt-5 pt-4 border-t border-slate-100 flex flex-wrap gap-2 justify-between items-center text-xs">
                <form method="POST" class="flex gap-2">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= (int)$viewUser['id'] ?>">
                    <input type="password" name="new_password" required minlength="8" placeholder="New password" class="px-3 py-1.5 rounded-xl border border-purple-200">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-800 text-white font-bold">Reset Pass</button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this user?');">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$viewUser['id'] ?>">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-100 text-rose-700 font-bold">Delete</button>
                </form>
            </div>
        </div>

        <div class="smm-card rounded-3xl p-5 text-xs space-y-3">
            <div class="font-extrabold text-slate-900">User Orders (<?= count($viewOrders) ?>) &amp; Logins (<?= count($viewLogins) ?>)</div>
            <div class="max-h-40 overflow-y-auto divide-y divide-slate-100">
                <?php $baseCode = Currency::getBaseCode(); ?>
                <?php foreach ($viewOrders as $vo): $voCurr = (string)($vo['currency_code'] ?: $baseCode); ?>
                <div class="py-1.5 flex justify-between gap-2">
                    <span class="truncate">#<?= (int)$vo['id'] ?> <?= Validator::e($vo['service_name']) ?></span>
                    <span class="font-bold tabular-nums shrink-0"><?= Currency::formatRaw((string)$vo['charge'], $voCurr, 2, true) ?> (<?= Validator::e($vo['status']) ?>)</span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($viewOrders)) echo '<div class="text-slate-400">No orders</div>'; ?>
            </div>
            <div class="font-extrabold text-slate-900 pt-2 border-t">Recent Wallet Ledger</div>
            <div class="max-h-36 overflow-y-auto divide-y divide-slate-100">
                <?php foreach ($viewTx as $vt): $vtCurr = (string)($vt['currency_code'] ?: $baseCode); ?>
                <div class="py-1.5 flex justify-between gap-2">
                    <span class="truncate max-w-[200px]"><?= Validator::e($vt['description']) ?></span>
                    <span class="font-bold tabular-nums shrink-0"><?= Currency::formatRaw((string)$vt['amount'], $vtCurr, 2, true) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
