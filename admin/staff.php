<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('staff.manage');
$pageTitle = 'Staff, Roles & Admin 2FA';

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_staff') {
        $username = Validator::username($_POST['username'] ?? '');
        $email = Validator::email($_POST['email'] ?? '');
        $fullName = Validator::str($_POST['full_name'] ?? '', 120);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');

        if (!$username || !$email || $fullName === '' || $roleId <= 0 || strlen($password) < 8) {
            Helpers::setFlash('error', 'Provide valid username, email, name, role, and a password (min 8 chars).');
            Helpers::redirect('/admin/staff.php');
        }

        $exists = Database::fetchColumn("SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?", [$username, $email]);
        if ($exists > 0) {
            Helpers::setFlash('error', 'An administrator with that username or email already exists.');
            Helpers::redirect('/admin/staff.php');
        }

        $roleExists = Database::fetchColumn("SELECT COUNT(*) FROM roles WHERE id = ?", [$roleId]);
        if ($roleExists <= 0) {
            Helpers::setFlash('error', 'Selected role does not exist.');
            Helpers::redirect('/admin/staff.php');
        }

        $newId = Database::insert(
            "INSERT INTO admins (role_id, username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, ?, 'active')",
            [$roleId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $fullName]
        );
        Logger::activity('admin', (int)$admin['id'], 'staff.create', 'admin', (string)$newId, "Created staff @{$username}");
        Helpers::setFlash('success', "Staff member @{$username} created.");
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'update_staff') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($staffId === (int)$admin['id'] && $status === 'suspended') {
            Helpers::setFlash('error', 'You cannot suspend your own administrator account.');
            Helpers::redirect('/admin/staff.php');
        }

        $target = Database::fetch("SELECT * FROM admins WHERE id = ?", [$staffId]);
        if (!$target) {
            Helpers::setFlash('error', 'Staff account not found.');
            Helpers::redirect('/admin/staff.php');
        }

        Database::execute("UPDATE admins SET role_id = ?, status = ? WHERE id = ?", [$roleId, $status, $staffId]);
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                Helpers::setFlash('error', 'New password must be at least 8 characters.');
                Helpers::redirect('/admin/staff.php');
            }
            Database::execute("UPDATE admins SET password_hash = ? WHERE id = ?", [password_hash($newPassword, PASSWORD_DEFAULT), $staffId]);
        }

        Logger::activity('admin', (int)$admin['id'], 'staff.update', 'admin', (string)$staffId, "Updated role/status for @{$target['username']}");
        Helpers::setFlash('success', "Updated staff member @{$target['username']}.");
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'save_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 80);
        $desc = Validator::str($_POST['description'] ?? '', 255);
        $perms = array_values(array_intersect(array_keys(ADMIN_PERMISSIONS), (array)($_POST['permissions'] ?? [])));

        if ($name === '') {
            Helpers::setFlash('error', 'Role name is required.');
            Helpers::redirect('/admin/staff.php');
        }

        if ($roleId > 0) {
            $role = Database::fetch("SELECT * FROM roles WHERE id = ?", [$roleId]);
            if (!$role) {
                Helpers::setFlash('error', 'Role not found.');
                Helpers::redirect('/admin/staff.php');
            }
            if ($role['slug'] === 'super_admin') {
                Helpers::setFlash('error', 'Super Admin wildcard role cannot be restricted.');
                Helpers::redirect('/admin/staff.php');
            }
            Database::execute(
                "UPDATE roles SET name = ?, description = ?, permissions_json = ? WHERE id = ?",
                [$name, $desc, json_encode($perms), $roleId]
            );
            Logger::activity('admin', (int)$admin['id'], 'role.update', 'role', (string)$roleId, "Updated role {$name}");
            Helpers::setFlash('success', "Role '{$name}' updated.");
        } else {
            $slug = Validator::slug($name);
            $exists = Database::fetchColumn("SELECT COUNT(*) FROM roles WHERE slug = ? OR name = ?", [$slug, $name]);
            if ($exists > 0) {
                Helpers::setFlash('error', 'Role with that name or slug already exists.');
                Helpers::redirect('/admin/staff.php');
            }
            $newRoleId = Database::insert(
                "INSERT INTO roles (name, slug, description, permissions_json, is_system) VALUES (?, ?, ?, ?, 0)",
                [$name, $slug, $desc, json_encode($perms)]
            );
            Logger::activity('admin', (int)$admin['id'], 'role.create', 'role', (string)$newRoleId, "Created role {$name}");
            Helpers::setFlash('success', "Role '{$name}' created.");
        }
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $role = Database::fetch("SELECT * FROM roles WHERE id = ?", [$roleId]);
        if (!$role || (int)$role['is_system'] === 1) {
            Helpers::setFlash('error', 'System roles cannot be deleted.');
            Helpers::redirect('/admin/staff.php');
        }
        $assigned = (int)Database::fetchColumn("SELECT COUNT(*) FROM admins WHERE role_id = ?", [$roleId]);
        if ($assigned > 0) {
            Helpers::setFlash('error', 'Reassign staff members away from this role before deleting.');
            Helpers::redirect('/admin/staff.php');
        }
        Database::execute("DELETE FROM roles WHERE id = ?", [$roleId]);
        Logger::activity('admin', (int)$admin['id'], 'role.delete', 'role', (string)$roleId, "Deleted role {$role['name']}");
        Helpers::setFlash('success', 'Role deleted.');
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'change_own_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $admin['password_hash'])) {
            Helpers::setFlash('error', 'Current password is incorrect.');
            Helpers::redirect('/admin/staff.php');
        }
        if (strlen($newPass) < 8 || $newPass !== $confirm) {
            Helpers::setFlash('error', 'New password must be at least 8 characters and match confirmation.');
            Helpers::redirect('/admin/staff.php');
        }
        Database::execute("UPDATE admins SET password_hash = ? WHERE id = ?", [password_hash($newPass, PASSWORD_DEFAULT), $admin['id']]);
        Logger::activity('admin', (int)$admin['id'], 'admin.password_change', 'admin', (string)$admin['id'], 'Changed own admin password');
        Helpers::setFlash('success', 'Your administrator password has been updated.');
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'enable_2fa') {
        $secret = trim((string)($_POST['totp_secret'] ?? ''));
        $code = trim((string)($_POST['totp_code'] ?? ''));
        if ($secret === '' || !Auth::verifyTotp($secret, $code)) {
            Helpers::setFlash('error', 'Invalid 6-digit verification code. Make sure your device clock is accurate.');
            Helpers::redirect('/admin/staff.php');
        }
        Database::execute("UPDATE admins SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?", [$secret, $admin['id']]);
        unset($_SESSION['pending_admin_2fa_secret']);
        Logger::activity('admin', (int)$admin['id'], 'admin.2fa_enable', 'admin', (string)$admin['id'], 'Enabled Admin TOTP 2FA');
        Helpers::setFlash('success', 'Two-Factor Authentication (2FA) is now active on your admin account.');
        Helpers::redirect('/admin/staff.php');
    }

    if ($action === 'disable_2fa') {
        $password = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($password, $admin['password_hash'])) {
            Helpers::setFlash('error', 'Password verification failed. 2FA remains enabled.');
            Helpers::redirect('/admin/staff.php');
        }
        Database::execute("UPDATE admins SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?", [$admin['id']]);
        Logger::activity('admin', (int)$admin['id'], 'admin.2fa_disable', 'admin', (string)$admin['id'], 'Disabled Admin TOTP 2FA');
        Helpers::setFlash('success', 'Two-Factor Authentication disabled.');
        Helpers::redirect('/admin/staff.php');
    }
}

$staffMembers = Database::fetchAll(
    "SELECT a.*, r.name AS role_name FROM admins a JOIN roles r ON r.id = a.role_id ORDER BY a.id ASC"
);
$roles = Database::fetchAll("SELECT * FROM roles ORDER BY id ASC");

if ((int)$admin['two_factor_enabled'] === 0) {
    if (empty($_SESSION['pending_admin_2fa_secret'])) {
        $_SESSION['pending_admin_2fa_secret'] = Auth::generateTotpSecret(16);
    }
    $pendingSecret = $_SESSION['pending_admin_2fa_secret'];
    $issuer = rawurlencode(Helpers::setting('site_name', 'SMM PRO') . ' Admin');
    $account = rawurlencode($admin['email']);
    $otpAuthUri = "otpauth://totp/{$issuer}:{$account}?secret={$pendingSecret}&issuer={$issuer}&digits=6&period=30";
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <!-- Add Staff -->
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Add Staff Member</h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create_staff">
            <div>
                <label class="block font-bold text-slate-600 mb-1">Full Name</label>
                <input type="text" name="full_name" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Username</label>
                    <input type="text" name="username" required pattern="[a-zA-Z0-9_.-]{3,50}" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Role</label>
                    <select name="role_id" required class="w-full px-3 py-2.5 rounded-xl border border-purple-100">
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= Validator::e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">Password (min 8 chars)</label>
                <input type="password" name="password" required minlength="8" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 text-white font-bold shadow">Create Staff Account</button>
        </form>
    </div>

    <!-- Staff Table -->
    <div class="xl:col-span-2 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Administrator &amp; Staff Accounts</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 text-slate-400 uppercase">
                        <th class="py-2.5">Admin</th>
                        <th class="py-2.5">Role</th>
                        <th class="py-2.5">2FA</th>
                        <th class="py-2.5">Last Login</th>
                        <th class="py-2.5">Manage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($staffMembers as $s): ?>
                    <tr>
                        <td class="py-3">
                            <div class="font-bold text-slate-900"><?= Validator::e($s['full_name']) ?></div>
                            <div class="text-slate-500">@<?= Validator::e($s['username']) ?> &middot; <?= Validator::e($s['email']) ?></div>
                        </td>
                        <td class="py-3 font-semibold text-purple-700"><?= Validator::e($s['role_name']) ?></td>
                        <td class="py-3">
                            <?php if ((int)$s['two_factor_enabled'] === 1): ?>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold">Enabled</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 font-semibold">Off</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-slate-500">
                            <?= $s['last_login_at'] ? Validator::e($s['last_login_at']) . '<br><span class="text-[10px]">' . Validator::e($s['last_login_ip']) . '</span>' : 'Never' ?>
                        </td>
                        <td class="py-3">
                            <form method="POST" class="flex flex-wrap items-center gap-1.5">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="update_staff">
                                <input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>">
                                <select name="role_id" class="px-2 py-1 rounded-lg border border-slate-200">
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= (int)$s['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= Validator::e($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="status" class="px-2 py-1 rounded-lg border border-slate-200">
                                    <option value="active" <?= $s['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="suspended" <?= $s['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                                <input type="password" name="new_password" placeholder="Reset pass" class="w-24 px-2 py-1 rounded-lg border border-slate-200">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-purple-600 text-white font-bold">Save</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Own Password & 2FA Security -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Change My Admin Password</h2>
        <form method="POST" class="space-y-3 text-xs max-w-md">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="change_own_password">
            <div>
                <label class="block font-bold text-slate-600 mb-1">Current Password</label>
                <input type="password" name="current_password" required class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">New Password (min 8 chars)</label>
                <input type="password" name="new_password" required minlength="8" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1">Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="8" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-100">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-slate-900 text-white font-bold">Update Password</button>
        </form>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-extrabold text-slate-900">Admin Two-Factor Authentication (TOTP 2FA)</h2>
            <?= (int)$admin['two_factor_enabled'] === 1 ? '<span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">Active</span>' : '<span class="px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">Not Enabled</span>' ?>
        </div>

        <?php if ((int)$admin['two_factor_enabled'] === 1): ?>
            <p class="text-xs text-slate-600 mb-4">Your admin sign-in requires a 6-digit RFC 6238 TOTP code from your authenticator app.</p>
            <form method="POST" class="space-y-3 text-xs max-w-sm">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="disable_2fa">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Confirm Admin Password to Disable 2FA</label>
                    <input type="password" name="confirm_password" required class="w-full px-3.5 py-2.5 rounded-xl border border-rose-200">
                </div>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-rose-600 text-white font-bold">Disable 2FA</button>
            </form>
        <?php else: ?>
            <p class="text-xs text-slate-600 mb-3">Add this setup key manually into Google Authenticator, Authy, or 1Password, then enter the generated 6-digit code below to activate 2FA.</p>
            <div class="p-3.5 rounded-2xl bg-purple-50 border border-purple-100 mb-3 text-xs space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-bold text-purple-900">Secret Key (Base32):</span>
                    <code class="px-2.5 py-1 rounded-lg bg-white font-mono font-bold text-purple-700 select-all"><?= Validator::e($pendingSecret) ?></code>
                </div>
                <div class="text-[11px] text-slate-500 break-all">
                    <span class="font-bold">OTPAuth URI:</span> <?= Validator::e($otpAuthUri) ?>
                </div>
            </div>
            <form method="POST" class="flex flex-wrap items-end gap-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="enable_2fa">
                <input type="hidden" name="totp_secret" value="<?= Validator::e($pendingSecret) ?>">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">6-Digit Code</label>
                    <input type="text" name="totp_code" required pattern="\d{6}" maxlength="6" placeholder="123456" class="w-36 px-3.5 py-2.5 rounded-xl border border-purple-100 font-mono text-center tracking-widest">
                </div>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Verify &amp; Enable 2FA</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Roles & Permissions Matrix -->
<div class="smm-card rounded-3xl p-6">
    <h2 class="text-base font-extrabold text-slate-900 mb-4">Roles &amp; Permissions Matrix</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($roles as $role):
            $rPerms = json_decode((string)$role['permissions_json'], true) ?: [];
            $isWildcard = in_array('*', $rPerms, true);
        ?>
        <div class="p-5 rounded-2xl border border-purple-100 bg-slate-50/60">
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                <div class="flex items-center justify-between gap-2">
                    <input type="text" name="name" value="<?= Validator::e($role['name']) ?>" <?= $isWildcard ? 'readonly' : '' ?> class="font-extrabold text-sm text-slate-900 bg-white px-3 py-1.5 rounded-xl border border-slate-200 flex-1">
                    <?php if ((int)$role['is_system'] === 1): ?>
                        <span class="px-2.5 py-1 rounded-full bg-purple-100 text-purple-700 text-[11px] font-bold">System</span>
                    <?php endif; ?>
                </div>
                <input type="text" name="description" value="<?= Validator::e((string)$role['description']) ?>" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 bg-white" placeholder="Role description">

                <?php if ($isWildcard): ?>
                    <p class="text-xs font-semibold text-purple-700">Full Unrestricted Access (`*` Wildcard Permission)</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-56 overflow-y-auto p-2 bg-white rounded-xl border border-slate-100">
                        <?php foreach (ADMIN_PERMISSIONS as $permKey => $permLabel): ?>
                        <label class="flex items-start gap-2 text-[11px] text-slate-700 cursor-pointer">
                            <input type="checkbox" name="permissions[]" value="<?= Validator::e($permKey) ?>" <?= in_array($permKey, $rPerms, true) ? 'checked' : '' ?> class="mt-0.5 rounded text-purple-600">
                            <span><?= Validator::e($permLabel) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex items-center justify-between pt-2">
                        <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold">Save Role Permissions</button>
                    </div>
                <?php endif; ?>
            </form>
            <?php if ((int)$role['is_system'] === 0): ?>
            <form method="POST" class="mt-2" onsubmit="return confirm('Delete this custom role?')">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                <button type="submit" class="text-[11px] font-bold text-rose-600 hover:underline">Delete Custom Role</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Create Custom Role -->
        <div class="p-5 rounded-2xl border-2 border-dashed border-purple-200 bg-white">
            <h3 class="text-sm font-extrabold text-slate-900 mb-3">Create New Custom Role</h3>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_id" value="0">
                <input type="text" name="name" required placeholder="Role Name (e.g. Catalog Manager)" class="w-full px-3.5 py-2 rounded-xl border border-purple-100">
                <input type="text" name="description" placeholder="Short description" class="w-full px-3.5 py-2 rounded-xl border border-purple-100">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-48 overflow-y-auto p-2 bg-slate-50 rounded-xl border border-slate-100">
                    <?php foreach (ADMIN_PERMISSIONS as $permKey => $permLabel): ?>
                    <label class="flex items-start gap-2 text-[11px] text-slate-700 cursor-pointer">
                        <input type="checkbox" name="permissions[]" value="<?= Validator::e($permKey) ?>" class="mt-0.5 rounded text-purple-600">
                        <span><?= Validator::e($permLabel) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="px-5 py-2 rounded-xl bg-slate-900 text-white font-bold">Create Custom Role</button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
