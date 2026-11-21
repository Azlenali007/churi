<?php
/**
 * Admin OTP Reseller API Management
 * 5-Tab Control Center: Overview KPIs, Manage API Users, Reseller Pricing Rules, Global Settings, Sanitized Usage Logs.
 * Completely independent from SMM Reseller API (/api/v2.php); disabling a user's OTP API never disables SMM or Direct OTP.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Reseller API';

$tab = Validator::str($_GET['tab'] ?? 'overview', 30);
if (!in_array($tab, ['overview', 'users', 'pricing', 'settings', 'logs'], true)) {
    $tab = 'overview';
}

$allOps = [
    'balance'   => 'GET /balance',
    'services'  => 'GET /services',
    'countries' => 'GET /countries',
    'prices'    => 'GET /prices',
    'create'    => 'POST /activation/create',
    'status'    => 'GET /activation/status',
    'cancel'    => 'POST /activation/cancel',
    'complete'  => 'POST /activation/complete',
    'history'   => 'GET /activation/history',
];

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = Validator::str($_POST['action'] ?? '', 50);

    if ($action === 'issue_user_key') {
        $identifier = trim((string)($_POST['user_identifier'] ?? ''));
        $targetUser = Database::fetch(
            "SELECT id, username, email FROM users WHERE username = ? OR email = ? OR id = ? LIMIT 1",
            [$identifier, $identifier, ctype_digit($identifier) ? (int)$identifier : 0]
        );
        if (!$targetUser) {
            Helpers::setFlash('error', 'User not found by username, email, or ID.');
        } else {
            $gen = OtpResellerApi::generateKeyForUser((int)$targetUser['id'], true);
            if (!empty($gen['ok'])) {
                $_SESSION['_admin_otp_api_new_key'] = [
                    'username' => (string)$targetUser['username'],
                    'raw_key'  => (string)$gen['raw_key'],
                ];
                Logger::activity('admin', (int)$admin['id'], 'otp_api.issue_key', 'user', (string)$targetUser['id'], 'Issued OTP Reseller API key');
                Helpers::setFlash('success', 'OTP Reseller API key issued for @' . $targetUser['username'] . '. Copy it below — shown once.');
            } else {
                Helpers::setFlash('error', $gen['error'] ?? 'Could not issue API key.');
            }
        }
        Helpers::redirect('/admin/otp-api.php?tab=users');
    }

    if ($action === 'toggle_user_status') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $row = Database::fetch("SELECT id, user_id, status FROM otp_api_keys WHERE id = ? LIMIT 1", [$keyId]);
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'disabled' : 'active';
            Database::execute("UPDATE otp_api_keys SET status = ? WHERE id = ?", [$newStatus, $keyId]);
            Logger::activity('admin', (int)$admin['id'], 'otp_api.toggle_user', 'user', (string)$row['user_id'], 'Set OTP API status to ' . $newStatus);
            Helpers::setFlash('success', 'User OTP API access set to ' . strtoupper($newStatus) . ' (SMM & Direct OTP unaffected).');
        }
        Helpers::redirect('/admin/otp-api.php?tab=users');
    }

    if ($action === 'regenerate_user_key') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $targetUser = Database::fetch("SELECT id, username FROM users WHERE id = ? LIMIT 1", [$userId]);
        if ($targetUser) {
            $gen = OtpResellerApi::generateKeyForUser($userId, true);
            if (!empty($gen['ok'])) {
                $_SESSION['_admin_otp_api_new_key'] = [
                    'username' => (string)$targetUser['username'],
                    'raw_key'  => (string)$gen['raw_key'],
                ];
                Logger::activity('admin', (int)$admin['id'], 'otp_api.regenerate_key', 'user', (string)$userId, 'Regenerated OTP API key');
                Helpers::setFlash('success', 'Regenerated OTP API key for @' . $targetUser['username'] . '. Previous key invalidated immediately.');
            }
        }
        Helpers::redirect('/admin/otp-api.php?tab=users');
    }

    if ($action === 'revoke_user_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $row = Database::fetch("SELECT id, user_id FROM otp_api_keys WHERE id = ? LIMIT 1", [$keyId]);
        if ($row) {
            Database::execute("DELETE FROM otp_api_keys WHERE id = ?", [$keyId]);
            Logger::activity('admin', (int)$admin['id'], 'otp_api.revoke_key', 'user', (string)$row['user_id'], 'Revoked OTP API key');
            Helpers::setFlash('success', 'OTP Reseller API key revoked.');
        }
        Helpers::redirect('/admin/otp-api.php?tab=users');
    }

    if ($action === 'save_user_limits') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $rateRaw = trim((string)($_POST['rate_limit_per_min'] ?? ''));
        $dailyRaw = trim((string)($_POST['daily_purchase_limit'] ?? ''));
        $activeRaw = trim((string)($_POST['max_active_activations'] ?? ''));
        $markupRaw = trim((string)($_POST['custom_markup_percent'] ?? ''));
        $ipsRaw = trim((string)($_POST['allowed_ips'] ?? ''));

        $rateLimit = ($rateRaw !== '' && is_numeric($rateRaw) && (int)$rateRaw > 0) ? min(10000, (int)$rateRaw) : null;
        $dailyLimit = ($dailyRaw !== '' && is_numeric($dailyRaw) && (int)$dailyRaw > 0) ? min(1000000, (int)$dailyRaw) : null;
        $maxActive = ($activeRaw !== '' && is_numeric($activeRaw) && (int)$activeRaw > 0) ? min(10000, (int)$activeRaw) : null;
        $customMarkup = ($markupRaw !== '' && is_numeric($markupRaw)) ? number_format(max(-90, min(1000, (float)$markupRaw)), 2, '.', '') : null;

        $validIps = OtpResellerApi::parseIpList($ipsRaw);
        $allowedIpsText = !empty($validIps) ? implode("\n", $validIps) : null;

        Database::execute(
            "UPDATE otp_api_keys
             SET rate_limit_per_min = ?, daily_purchase_limit = ?, max_active_activations = ?,
                 custom_markup_percent = ?, allowed_ips = ?
             WHERE id = ?",
            [$rateLimit, $dailyLimit, $maxActive, $customMarkup, $allowedIpsText, $keyId]
        );
        Logger::activity('admin', (int)$admin['id'], 'otp_api.update_limits', 'otp_api_key', (string)$keyId, 'Updated per-user OTP API limits');
        Helpers::setFlash('success', 'Per-user OTP API limits, markup, and IP allowlist updated.');
        Helpers::redirect('/admin/otp-api.php?tab=users');
    }

    if ($action === 'save_pricing_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $scope = Validator::str($_POST['scope'] ?? 'global', 20);
        if (!in_array($scope, ['global', 'service', 'country', 'service_country'], true)) {
            $scope = 'global';
        }
        $serviceId = in_array($scope, ['service', 'service_country'], true) ? max(0, (int)($_POST['service_id'] ?? 0)) : null;
        $countryId = in_array($scope, ['country', 'service_country'], true) ? max(0, (int)($_POST['country_id'] ?? 0)) : null;
        if ($serviceId === 0) $serviceId = null;
        if ($countryId === 0) $countryId = null;

        $pricingType = Validator::str($_POST['pricing_type'] ?? 'percent', 20);
        if (!in_array($pricingType, ['percent', 'fixed_profit', 'fixed_price'], true)) {
            $pricingType = 'percent';
        }
        $markupPct = Currency::normalize((string)($_POST['markup_percent'] ?? '0'), 2);
        $fixedProfit = Currency::normalize((string)($_POST['fixed_profit'] ?? '0'), 4);
        $fixedPriceRaw = trim((string)($_POST['fixed_price'] ?? ''));
        $fixedPrice = ($fixedPriceRaw !== '' && is_numeric($fixedPriceRaw) && (float)$fixedPriceRaw > 0)
            ? Currency::normalize($fixedPriceRaw, 4)
            : null;
        $minPriceRaw = trim((string)($_POST['min_selling_price'] ?? ''));
        $minPrice = ($minPriceRaw !== '' && is_numeric($minPriceRaw) && (float)$minPriceRaw > 0)
            ? Currency::normalize($minPriceRaw, 4)
            : null;
        $ruleStatus = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (($scope === 'service' && !$serviceId) || ($scope === 'country' && !$countryId) || ($scope === 'service_country' && (!$serviceId || !$countryId))) {
            Helpers::setFlash('error', 'Please select the required service and/or country for the selected scope.');
            Helpers::redirect('/admin/otp-api.php?tab=pricing');
        }
        if ($pricingType === 'fixed_price' && $fixedPrice === null) {
            Helpers::setFlash('error', 'Fixed Price rules require a positive Fixed Price value.');
            Helpers::redirect('/admin/otp-api.php?tab=pricing');
        }

        if ($ruleId > 0) {
            Database::execute(
                "UPDATE otp_api_pricing_rules
                 SET scope = ?, service_id = ?, country_id = ?, pricing_type = ?,
                     markup_percent = ?, fixed_profit = ?, fixed_price = ?, min_selling_price = ?, status = ?
                 WHERE id = ?",
                [$scope, $serviceId, $countryId, $pricingType, $markupPct, $fixedProfit, $fixedPrice, $minPrice, $ruleStatus, $ruleId]
            );
            Helpers::setFlash('success', 'Reseller pricing rule updated.');
        } else {
            Database::insert(
                "INSERT INTO otp_api_pricing_rules
                 (scope, service_id, country_id, pricing_type, markup_percent, fixed_profit, fixed_price, min_selling_price, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$scope, $serviceId, $countryId, $pricingType, $markupPct, $fixedProfit, $fixedPrice, $minPrice, $ruleStatus]
            );
            Helpers::setFlash('success', 'Reseller pricing rule created.');
        }
        Logger::activity('admin', (int)$admin['id'], 'otp_api.pricing_rule', 'otp_api_pricing_rules', (string)$ruleId, "Saved {$scope} {$pricingType} rule");
        Helpers::redirect('/admin/otp-api.php?tab=pricing');
    }

    if ($action === 'toggle_pricing_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $r = Database::fetch("SELECT id, status FROM otp_api_pricing_rules WHERE id = ? LIMIT 1", [$ruleId]);
        if ($r) {
            $next = $r['status'] === 'active' ? 'inactive' : 'active';
            Database::execute("UPDATE otp_api_pricing_rules SET status = ? WHERE id = ?", [$next, $ruleId]);
            Helpers::setFlash('success', 'Pricing rule set to ' . $next . '.');
        }
        Helpers::redirect('/admin/otp-api.php?tab=pricing');
    }

    if ($action === 'delete_pricing_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            Database::execute("DELETE FROM otp_api_pricing_rules WHERE id = ?", [$ruleId]);
            Helpers::setFlash('success', 'Reseller pricing rule deleted.');
        }
        Helpers::redirect('/admin/otp-api.php?tab=pricing');
    }

    if ($action === 'save_global_settings') {
        $enabled = !empty($_POST['otp_api_enabled']) ? '1' : '0';
        $ipRequired = !empty($_POST['otp_api_ip_restriction_enabled']) ? '1' : '0';
        $version = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($_POST['otp_api_version'] ?? 'v1')) ?: 'v1';
        $globalRate = (string)max(1, min(100000, (int)($_POST['otp_api_global_rate_limit'] ?? 120)));
        $defaultUserRate = (string)max(1, min(10000, (int)($_POST['otp_api_default_user_rate_limit'] ?? 60)));
        $maxDaily = (string)max(1, min(1000000, (int)($_POST['otp_api_max_daily_orders'] ?? 500)));
        $maxActive = (string)max(1, min(10000, (int)($_POST['otp_api_max_active_activations'] ?? 25)));
        $retention = (string)max(1, min(365, (int)($_POST['otp_api_log_retention_days'] ?? 30)));

        $selectedOps = $_POST['allowed_ops'] ?? [];
        if (!is_array($selectedOps)) $selectedOps = [];
        $validOps = array_values(array_intersect(array_keys($allOps), $selectedOps));

        $gMarkupType = ($_POST['otp_api_global_markup_type'] ?? 'percent') === 'fixed_profit' ? 'fixed_profit' : 'percent';
        $gMarkupPct = Currency::normalize((string)($_POST['otp_api_global_markup_percent'] ?? '0'), 2);
        $gFixedProfit = Currency::normalize((string)($_POST['otp_api_global_fixed_profit'] ?? '0'), 4);
        $gMinPrice = Currency::normalize((string)($_POST['otp_api_global_min_price'] ?? '0'), 4);

        $settingsToSave = [
            'otp_api_enabled'                 => $enabled,
            'otp_api_version'                 => $version,
            'otp_api_global_rate_limit'       => $globalRate,
            'otp_api_default_user_rate_limit' => $defaultUserRate,
            'otp_api_max_daily_orders'        => $maxDaily,
            'otp_api_max_active_activations'  => $maxActive,
            'otp_api_allowed_operations'      => implode(',', $validOps),
            'otp_api_ip_restriction_enabled'  => $ipRequired,
            'otp_api_log_retention_days'      => $retention,
            'otp_api_global_markup_type'      => $gMarkupType,
            'otp_api_global_markup_percent'   => $gMarkupPct,
            'otp_api_global_fixed_profit'     => $gFixedProfit,
            'otp_api_global_min_price'        => $gMinPrice,
        ];
        foreach ($settingsToSave as $sk => $sv) {
            Helpers::setSetting($sk, $sv);
        }
        Logger::activity('admin', (int)$admin['id'], 'otp_api.settings', 'settings', 'otp_api', 'Updated global OTP Reseller API settings');
        Helpers::setFlash('success', 'Global OTP Reseller API settings saved.');
        Helpers::redirect('/admin/otp-api.php?tab=settings');
    }

    if ($action === 'prune_logs') {
        $deleted = OtpResellerApi::pruneLogs();
        Logger::activity('admin', (int)$admin['id'], 'otp_api.prune_logs', 'otp_api_logs', '', "Pruned {$deleted} log rows");
        Helpers::setFlash('success', "Pruned {$deleted} expired OTP API log entries and expired idempotency records.");
        Helpers::redirect('/admin/otp-api.php?tab=logs');
    }
}

// One-time admin key reveal banner
$newAdminKeyBanner = $_SESSION['_admin_otp_api_new_key'] ?? null;
unset($_SESSION['_admin_otp_api_new_key']);

// Load tab-specific data
$baseCurrency = Currency::getBase();
$apiGlobalEnabled = Helpers::setting('otp_api_enabled', '1') === '1';

// Overview KPIs (always quick aggregates)
$kpi = [
    'total_users'     => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_api_keys"),
    'active_keys'     => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_api_keys WHERE status = 'active'"),
    'disabled_keys'   => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_api_keys WHERE status = 'disabled'"),
    'total_orders'    => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE source = 'api'"),
    'today_orders'    => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE source = 'api' AND DATE(created_at) = CURDATE()"),
    'active_orders'   => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE source = 'api' AND status IN ('processing','active','waiting_otp','otp_received')"),
    'completed_orders'=> (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE source = 'api' AND status = 'completed'"),
    'total_revenue'   => (string)(Database::fetchColumn("SELECT COALESCE(SUM(selling_price),0) FROM otp_activations WHERE source = 'api' AND status NOT IN ('failed','canceled','refunded')") ?: '0'),
    'total_profit'    => (string)(Database::fetchColumn("SELECT COALESCE(SUM(selling_price - provider_cost),0) FROM otp_activations WHERE source = 'api' AND status NOT IN ('failed','canceled','refunded')") ?: '0'),
    'failed_requests' => (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_api_logs WHERE success = 0"),
];

$recentApiOrders = [];
$recentFailedLogs = [];
if ($tab === 'overview') {
    $recentApiOrders = Database::fetchAll(
        "SELECT a.*, u.username, s.name AS service_name, c.name AS country_name, c.flag_emoji
         FROM otp_activations a
         JOIN users u ON u.id = a.user_id
         JOIN otp_services s ON s.id = a.service_id
         JOIN otp_countries c ON c.id = a.country_id
         WHERE a.source = 'api'
         ORDER BY a.id DESC LIMIT 12"
    );
    $recentFailedLogs = Database::fetchAll(
        "SELECT l.*, u.username
         FROM otp_api_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.success = 0
         ORDER BY l.id DESC LIMIT 12"
    );
}

// Users tab data
$apiUsers = [];
$userSearch = Validator::str($_GET['uq'] ?? '', 80);
$userStatusFilter = Validator::str($_GET['ustatus'] ?? 'all', 20);
$editKeyRow = null;
$inspectUser = null;
$inspectOrders = [];
$inspectTxs = [];
$inspectLogs = [];

if ($tab === 'users') {
    $uWhere = ["1=1"];
    $uParams = [];
    if ($userSearch !== '') {
        $uWhere[] = "(u.username LIKE ? OR u.email LIKE ? OR k.key_prefix LIKE ?)";
        $like = '%' . $userSearch . '%';
        array_push($uParams, $like, $like, $like);
    }
    if (in_array($userStatusFilter, ['active', 'disabled'], true)) {
        $uWhere[] = "k.status = ?";
        $uParams[] = $userStatusFilter;
    }
    $apiUsers = Database::fetchAll(
        "SELECT k.*, u.username, u.email, u.wallet_balance, u.status AS account_status,
                (SELECT COUNT(*) FROM otp_activations a WHERE a.user_id = k.user_id AND a.source = 'api') AS api_order_count
         FROM otp_api_keys k
         JOIN users u ON u.id = k.user_id
         WHERE " . implode(' AND ', $uWhere) . "
         ORDER BY k.id DESC LIMIT 100",
        $uParams
    );

    $editKeyId = (int)($_GET['edit_key'] ?? 0);
    if ($editKeyId > 0) {
        $editKeyRow = Database::fetch(
            "SELECT k.*, u.username, u.email FROM otp_api_keys k JOIN users u ON u.id = k.user_id WHERE k.id = ? LIMIT 1",
            [$editKeyId]
        );
    }

    $inspectUid = (int)($_GET['inspect_user'] ?? 0);
    if ($inspectUid > 0) {
        $inspectUser = Database::fetch("SELECT id, username, email, wallet_balance, status FROM users WHERE id = ? LIMIT 1", [$inspectUid]);
        if ($inspectUser) {
            $inspectOrders = Database::fetchAll(
                "SELECT a.*, s.name AS service_name, c.name AS country_name
                 FROM otp_activations a
                 JOIN otp_services s ON s.id = a.service_id
                 JOIN otp_countries c ON c.id = a.country_id
                 WHERE a.user_id = ? AND a.source = 'api'
                 ORDER BY a.id DESC LIMIT 20",
                [$inspectUid]
            );
            $inspectTxs = Database::fetchAll(
                "SELECT * FROM wallet_transactions
                 WHERE user_id = ? AND reference_type = 'otp_activation' AND description LIKE 'OTP API%'
                 ORDER BY id DESC LIMIT 20",
                [$inspectUid]
            );
            $inspectLogs = Database::fetchAll(
                "SELECT * FROM otp_api_logs WHERE user_id = ? ORDER BY id DESC LIMIT 25",
                [$inspectUid]
            );
        }
    }
}

// Pricing rules tab data
$pricingRules = [];
$servicesList = [];
$countriesList = [];
$editRule = null;
if ($tab === 'pricing') {
    $pricingRules = Database::fetchAll(
        "SELECT r.*, s.name AS service_name, c.name AS country_name, c.flag_emoji
         FROM otp_api_pricing_rules r
         LEFT JOIN otp_services s ON s.id = r.service_id
         LEFT JOIN otp_countries c ON c.id = r.country_id
         ORDER BY FIELD(r.scope, 'service_country', 'service', 'country', 'global'), r.id DESC"
    );
    $servicesList = Database::fetchAll("SELECT id, name FROM otp_services ORDER BY name ASC");
    $countriesList = Database::fetchAll("SELECT id, name, flag_emoji FROM otp_countries ORDER BY name ASC");
    $editRuleId = (int)($_GET['edit_rule'] ?? 0);
    if ($editRuleId > 0) {
        $editRule = Database::fetch("SELECT * FROM otp_api_pricing_rules WHERE id = ? LIMIT 1", [$editRuleId]);
    }
}

// Logs tab data
$logs = [];
$logTotal = 0;
$logPage = max(1, (int)($_GET['page'] ?? 1));
$logPerPage = 30;
$logTotalPages = 1;
$logUser = Validator::str($_GET['luser'] ?? '', 60);
$logEndpoint = Validator::str($_GET['lendpoint'] ?? '', 80);
$logStatus = Validator::str($_GET['lstatus'] ?? 'all', 20);
$logErrCode = Validator::str($_GET['lerr'] ?? '', 60);

if ($tab === 'logs') {
    $lw = ["1=1"];
    $lp = [];
    if ($logUser !== '') {
        $lw[] = "u.username LIKE ?";
        $lp[] = '%' . $logUser . '%';
    }
    if ($logEndpoint !== '') {
        $lw[] = "l.endpoint LIKE ?";
        $lp[] = '%' . $logEndpoint . '%';
    }
    if ($logStatus === 'success') {
        $lw[] = "l.success = 1";
    } elseif ($logStatus === 'error') {
        $lw[] = "l.success = 0";
    }
    if ($logErrCode !== '') {
        $lw[] = "l.error_code LIKE ?";
        $lp[] = '%' . $logErrCode . '%';
    }
    $lwSql = implode(' AND ', $lw);
    $logTotal = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_api_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$lwSql}", $lp);
    $logTotalPages = max(1, (int)ceil($logTotal / $logPerPage));
    $offset = ($logPage - 1) * $logPerPage;
    $logs = Database::fetchAll(
        "SELECT l.*, u.username
         FROM otp_api_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE {$lwSql}
         ORDER BY l.id DESC LIMIT {$logPerPage} OFFSET {$offset}",
        $lp
    );
}

$allowedOpsActive = array_values(array_filter(array_map('trim', explode(',', strtolower((string)Helpers::setting('otp_api_allowed_operations', 'balance,services,countries,prices,create,status,cancel,complete,history'))))));

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<!-- Top Banner & Tabs -->
<div class="smm-card rounded-3xl p-5 mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <div class="flex items-center gap-2.5">
            <h1 class="text-lg font-extrabold text-slate-900">OTP Reseller API Management</h1>
            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase <?= $apiGlobalEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>">
                <?= $apiGlobalEnabled ? 'API Online' : 'API Disabled' ?>
            </span>
            <span class="px-2.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[11px] font-mono font-extrabold">
                /api/otp/<?= Validator::e(Helpers::setting('otp_api_version', 'v1')) ?>/
            </span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Independent from SMM Reseller API (<code class="font-mono">/api/v2.php</code>). Shares user accounts, unified wallet, and live OTP activation engine.</p>
    </div>
    <div class="flex flex-wrap items-center gap-1.5 text-xs">
        <?php
        $tabs = [
            'overview' => 'Overview',
            'users'    => 'API Users (' . $kpi['total_users'] . ')',
            'pricing'  => 'Pricing Rules',
            'settings' => 'Global Settings',
            'logs'     => 'Usage Logs',
        ];
        foreach ($tabs as $tk => $tl):
        ?>
        <a href="?tab=<?= $tk ?>" class="px-3.5 py-2 rounded-xl font-extrabold transition <?= $tab === $tk ? 'bg-purple-600 text-white shadow-sm' : 'bg-purple-50 text-purple-700 hover:bg-purple-100' ?>">
            <?= Validator::e($tl) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($newAdminKeyBanner): ?>
<div class="mb-6 p-5 rounded-3xl bg-emerald-50 border-2 border-emerald-300 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="text-xs font-extrabold uppercase tracking-wider text-emerald-800">One-Time OTP Reseller API Key Issued for @<?= Validator::e($newAdminKeyBanner['username']) ?></div>
            <p class="text-xs text-emerald-700 mt-0.5">This raw key is shown only once and is stored exclusively as a SHA-256 hash in MySQL.</p>
        </div>
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('adminNewApiKey').textContent); this.textContent='Copied!';"
                class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold">
            Copy Key
        </button>
    </div>
    <pre id="adminNewApiKey" class="mt-3 p-3.5 rounded-2xl bg-slate-950 text-emerald-300 font-mono text-xs overflow-x-auto select-all"><?= Validator::e($newAdminKeyBanner['raw_key']) ?></pre>
</div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
<!-- TAB 1: OVERVIEW KPIs -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[11px] font-bold uppercase text-slate-400">Total API Users</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= number_format($kpi['total_users']) ?></div>
        <div class="text-[11px] text-slate-500 mt-1"><?= $kpi['active_keys'] ?> active &middot; <?= $kpi['disabled_keys'] ?> disabled</div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[11px] font-bold uppercase text-slate-400">API Orders</div>
        <div class="text-2xl font-extrabold text-purple-700 mt-1 tabular-nums"><?= number_format($kpi['total_orders']) ?></div>
        <div class="text-[11px] text-slate-500 mt-1">Today: <strong><?= number_format($kpi['today_orders']) ?></strong></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[11px] font-bold uppercase text-slate-400">Active / Completed</div>
        <div class="text-2xl font-extrabold text-indigo-700 mt-1 tabular-nums"><?= number_format($kpi['active_orders']) ?> / <?= number_format($kpi['completed_orders']) ?></div>
        <div class="text-[11px] text-slate-500 mt-1">Live API activations</div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[11px] font-bold uppercase text-slate-400">API Revenue / Profit</div>
        <div class="text-xl font-extrabold text-emerald-700 mt-1 tabular-nums"><?= Currency::display($kpi['total_revenue'], null, 2, true) ?></div>
        <div class="text-[11px] text-emerald-700 mt-1 font-bold">Profit: <?= Currency::display($kpi['total_profit'], null, 2, true) ?></div>
    </div>
    <div class="smm-card rounded-2xl p-4">
        <div class="text-[11px] font-bold uppercase text-slate-400">Failed API Requests</div>
        <div class="text-2xl font-extrabold <?= $kpi['failed_requests'] > 0 ? 'text-rose-600' : 'text-slate-900' ?> mt-1 tabular-nums"><?= number_format($kpi['failed_requests']) ?></div>
        <div class="text-[11px] text-slate-500 mt-1"><a href="?tab=logs&lstatus=error" class="text-purple-600 font-bold hover:underline">Inspect logs &rarr;</a></div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <!-- Recent API Orders -->
    <div class="smm-card rounded-3xl overflow-hidden">
        <div class="px-5 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Recent OTP API Activations</h2>
            <a href="/admin/otp-orders.php?source=api" class="text-xs font-bold text-purple-600 hover:underline">View All &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-2.5">Code / User</th>
                        <th class="px-4 py-2.5">Service</th>
                        <th class="px-4 py-2.5">Price</th>
                        <th class="px-4 py-2.5">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($recentApiOrders)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">No OTP API activations recorded yet.</td></tr>
                    <?php else: foreach ($recentApiOrders as $o): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3">
                            <a href="/admin/otp-orders.php?view=<?= (int)$o['id'] ?>" class="font-mono font-bold text-purple-700 hover:underline"><?= Validator::e($o['activation_code']) ?></a>
                            <div class="text-[10px] text-slate-500">@<?= Validator::e($o['username']) ?> &middot; <?= date('M d H:i', strtotime($o['created_at'])) ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-800"><?= Validator::e($o['service_name']) ?></div>
                            <div class="text-[10px] text-slate-500"><?= Validator::e(($o['flag_emoji'] ? $o['flag_emoji'] . ' ' : '') . $o['country_name']) ?></div>
                        </td>
                        <td class="px-4 py-3 font-bold tabular-nums"><?= Currency::display((string)$o['selling_price'], null, 2, true) ?></td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$o['status']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Failed API Requests -->
    <div class="smm-card rounded-3xl overflow-hidden">
        <div class="px-5 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Recent Failed API Requests</h2>
            <a href="?tab=logs&lstatus=error" class="text-xs font-bold text-purple-600 hover:underline">All Errors &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-2.5">Endpoint</th>
                        <th class="px-4 py-2.5">Error Code</th>
                        <th class="px-4 py-2.5">User / IP</th>
                        <th class="px-4 py-2.5">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($recentFailedLogs)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">No failed API requests logged.</td></tr>
                    <?php else: foreach ($recentFailedLogs as $fl): ?>
                    <tr class="hover:bg-rose-50/30">
                        <td class="px-4 py-3 font-mono font-bold text-slate-800">
                            <span class="text-[10px] text-slate-400"><?= Validator::e($fl['http_method']) ?></span>
                            <?= Validator::e($fl['endpoint']) ?>
                            <span class="ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[10px]"><?= (int)$fl['http_status'] ?></span>
                        </td>
                        <td class="px-4 py-3 font-mono text-[11px] font-bold text-rose-700"><?= Validator::e($fl['error_code'] ?: 'ERROR') ?></td>
                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-700"><?= $fl['username'] ? '@' . Validator::e($fl['username']) : 'Unauthenticated' ?></div>
                            <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($fl['ip_address'] ?: '—') ?></div>
                        </td>
                        <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= date('M d H:i', strtotime($fl['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'users'): ?>
<!-- TAB 2: MANAGE API USERS -->
<div class="grid lg:grid-cols-3 gap-5 mb-6">
    <!-- Issue Key to Any User -->
    <form method="POST" class="smm-card rounded-2xl p-5 lg:col-span-1 space-y-3">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="issue_user_key">
        <div class="text-xs font-extrabold uppercase tracking-wider text-purple-700">Issue / Enable Key for User</div>
        <p class="text-[11px] text-slate-500">Enter a user's username, email, or numeric ID to generate an OTP Reseller API key.</p>
        <div class="flex gap-2">
            <input type="text" name="user_identifier" required placeholder="username or email..." class="flex-1 px-3 py-2 rounded-xl border border-purple-200 text-xs">
            <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold">Issue Key</button>
        </div>
    </form>

    <!-- Search Filter -->
    <form method="GET" class="smm-card rounded-2xl p-5 lg:col-span-2 flex flex-wrap items-end gap-3 text-xs">
        <input type="hidden" name="tab" value="users">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-[11px] font-bold text-slate-500 mb-1">Search API Users</label>
            <input type="text" name="uq" value="<?= Validator::e($userSearch) ?>" placeholder="Username, email, or key prefix..." class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div class="w-40">
            <label class="block text-[11px] font-bold text-slate-500 mb-1">API Status</label>
            <select name="ustatus" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                <option value="all" <?= $userStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $userStatusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="disabled" <?= $userStatusFilter === 'disabled' ? 'selected' : '' ?>>Disabled Only</option>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-extrabold">Filter</button>
        <a href="?tab=users" class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Reset</a>
    </form>
</div>

<?php if ($editKeyRow): ?>
<!-- Edit Per-User Limits Card -->
<form method="POST" class="smm-card rounded-3xl p-6 mb-6 border-2 border-purple-200 space-y-4 text-xs">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="save_user_limits">
    <input type="hidden" name="key_id" value="<?= (int)$editKeyRow['id'] ?>">
    <div class="flex items-center justify-between border-b border-purple-100 pb-3">
        <div>
            <h2 class="text-sm font-extrabold text-slate-900">Edit Per-User OTP API Limits — @<?= Validator::e($editKeyRow['username']) ?></h2>
            <p class="text-[11px] text-slate-500">Leave numeric fields empty to inherit Global API Settings.</p>
        </div>
        <a href="?tab=users" class="px-3 py-1.5 rounded-xl bg-slate-100 text-slate-700 font-bold">Cancel</a>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block font-bold text-slate-700 mb-1">Rate Limit / Minute</label>
            <input type="number" min="1" max="10000" name="rate_limit_per_min" value="<?= Validator::e((string)($editKeyRow['rate_limit_per_min'] ?? '')) ?>" placeholder="Default (<?= Validator::e(Helpers::setting('otp_api_default_user_rate_limit', '60')) ?>)" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div>
            <label class="block font-bold text-slate-700 mb-1">Daily Purchase Limit</label>
            <input type="number" min="1" max="1000000" name="daily_purchase_limit" value="<?= Validator::e((string)($editKeyRow['daily_purchase_limit'] ?? '')) ?>" placeholder="Default (<?= Validator::e(Helpers::setting('otp_api_max_daily_orders', '500')) ?>)" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div>
            <label class="block font-bold text-slate-700 mb-1">Max Active Activations</label>
            <input type="number" min="1" max="10000" name="max_active_activations" value="<?= Validator::e((string)($editKeyRow['max_active_activations'] ?? '')) ?>" placeholder="Default (<?= Validator::e(Helpers::setting('otp_api_max_active_activations', '25')) ?>)" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
        <div>
            <label class="block font-bold text-slate-700 mb-1">Custom Markup % (e.g. -5 or +10)</label>
            <input type="number" step="0.01" min="-90" max="1000" name="custom_markup_percent" value="<?= Validator::e((string)($editKeyRow['custom_markup_percent'] ?? '')) ?>" placeholder="0.00 (no override)" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>
    </div>
    <div>
        <label class="block font-bold text-slate-700 mb-1">Allowed IP Addresses (one IPv4/IPv6 per line; leave blank for unrestricted unless globally required)</label>
        <textarea name="allowed_ips" rows="3" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono text-xs" placeholder="203.0.113.10&#10;198.51.100.42"><?= Validator::e((string)($editKeyRow['allowed_ips'] ?? '')) ?></textarea>
    </div>
    <div class="flex justify-end gap-2">
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold">Save User API Settings</button>
    </div>
</form>
<?php endif; ?>

<?php if ($inspectUser): ?>
<!-- User Inspector Drawer -->
<div class="smm-card rounded-3xl p-6 mb-6 border-2 border-indigo-200 space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-indigo-100">
        <div>
            <h2 class="text-base font-extrabold text-slate-900">Inspecting Reseller: @<?= Validator::e($inspectUser['username']) ?></h2>
            <p class="text-xs text-slate-500"><?= Validator::e($inspectUser['email']) ?> &middot; Shared Wallet: <strong class="text-emerald-700"><?= Currency::display((string)$inspectUser['wallet_balance'], null, 2, true) ?></strong></p>
        </div>
        <a href="?tab=users" class="px-3.5 py-1.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold">Close Inspector</a>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 text-xs">
        <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="font-extrabold text-slate-800 mb-2">Recent API Orders (<?= count($inspectOrders) ?>)</div>
            <div class="max-h-64 overflow-y-auto space-y-1.5">
                <?php if (empty($inspectOrders)): ?><div class="text-slate-400">None yet.</div>
                <?php else: foreach ($inspectOrders as $io): ?>
                <div class="p-2.5 rounded-xl bg-white border border-purple-100 flex justify-between gap-2">
                    <div>
                        <a href="/admin/otp-orders.php?view=<?= (int)$io['id'] ?>" class="font-mono font-bold text-purple-700 hover:underline"><?= Validator::e($io['activation_code']) ?></a>
                        <div class="text-[10px] text-slate-500"><?= Validator::e($io['service_name'] . ' / ' . $io['country_name']) ?></div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold tabular-nums"><?= Currency::display((string)$io['selling_price'], null, 2, true) ?></div>
                        <div class="text-[10px] uppercase font-bold text-slate-500"><?= Validator::e($io['status']) ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="font-extrabold text-slate-800 mb-2">API Wallet Transactions (<?= count($inspectTxs) ?>)</div>
            <div class="max-h-64 overflow-y-auto space-y-1.5">
                <?php if (empty($inspectTxs)): ?><div class="text-slate-400">None yet.</div>
                <?php else: foreach ($inspectTxs as $it): ?>
                <div class="p-2.5 rounded-xl bg-white border border-purple-100 flex justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-bold text-slate-800 truncate"><?= Validator::e($it['description']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= date('M d H:i', strtotime($it['created_at'])) ?></div>
                    </div>
                    <div class="font-extrabold tabular-nums shrink-0 <?= $it['type'] === 'refund' ? 'text-emerald-600' : 'text-rose-600' ?>">
                        <?= $it['type'] === 'refund' ? '+' : '-' ?><?= Currency::display((string)$it['amount'], null, 2, true) ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
            <div class="font-extrabold text-slate-800 mb-2">Recent API Logs (<?= count($inspectLogs) ?>)</div>
            <div class="max-h-64 overflow-y-auto space-y-1.5">
                <?php if (empty($inspectLogs)): ?><div class="text-slate-400">None yet.</div>
                <?php else: foreach ($inspectLogs as $il): ?>
                <div class="p-2.5 rounded-xl bg-white border <?= !empty($il['success']) ? 'border-emerald-200' : 'border-rose-200' ?> flex justify-between gap-2">
                    <div class="font-mono">
                        <div class="font-bold text-slate-800"><?= Validator::e($il['http_method'] . ' ' . $il['endpoint']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= Validator::e($il['error_code'] ?: 'OK') ?> &middot; <?= (int)$il['response_time_ms'] ?>ms</div>
                    </div>
                    <span class="px-1.5 py-0.5 h-fit rounded text-[10px] font-extrabold <?= !empty($il['success']) ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>"><?= (int)$il['http_status'] ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- API Users Table -->
<div class="smm-card rounded-3xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-4 py-3">User &amp; Wallet</th>
                    <th class="px-4 py-3">Key Prefix &amp; Status</th>
                    <th class="px-4 py-3">Limits / Markup</th>
                    <th class="px-4 py-3">Usage &amp; Orders</th>
                    <th class="px-4 py-3 text-right">Controls</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php if (empty($apiUsers)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No OTP Reseller API keys issued yet.</td></tr>
                <?php else: foreach ($apiUsers as $au):
                    $ipCount = count(OtpResellerApi::parseIpList((string)($au['allowed_ips'] ?? '')));
                ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-4 py-3">
                        <div class="font-extrabold text-slate-900">@<?= Validator::e($au['username']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= Validator::e($au['email']) ?></div>
                        <div class="text-[11px] font-bold text-emerald-700 mt-0.5 tabular-nums">Wallet: <?= Currency::display((string)$au['wallet_balance'], null, 2, true) ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-mono font-bold text-slate-800"><?= Validator::e($au['key_prefix']) ?>••••</div>
                        <div class="mt-1 flex items-center gap-1.5">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase <?= $au['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>">
                                OTP API: <?= Validator::e($au['status']) ?>
                            </span>
                            <?php if ($ipCount > 0): ?>
                            <span class="px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold"><?= $ipCount ?> IP<?= $ipCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-[11px] text-slate-600">
                        <div>Rate: <strong><?= $au['rate_limit_per_min'] ?: 'Default' ?></strong>/min &middot; Daily: <strong><?= $au['daily_purchase_limit'] ?: 'Default' ?></strong></div>
                        <div>Active Cap: <strong><?= $au['max_active_activations'] ?: 'Default' ?></strong> &middot; Markup: <strong><?= $au['custom_markup_percent'] !== null ? Validator::e($au['custom_markup_percent']) . '%' : 'Default' ?></strong></div>
                    </td>
                    <td class="px-4 py-3 text-[11px]">
                        <div class="font-bold text-slate-800"><?= number_format((int)$au['total_requests']) ?> reqs &middot; <?= number_format((int)$au['api_order_count']) ?> orders</div>
                        <div class="text-[10px] text-slate-400">Last: <?= $au['last_used_at'] ? date('M d H:i', strtotime($au['last_used_at'])) . ' (' . Validator::e($au['last_used_ip'] ?: '—') . ')' : 'Never' ?></div>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex flex-wrap justify-end gap-1.5">
                            <a href="?tab=users&inspect_user=<?= (int)$au['user_id'] ?>" class="px-2.5 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold">Inspect</a>
                            <a href="?tab=users&edit_key=<?= (int)$au['id'] ?>" class="px-2.5 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold">Limits</a>
                            <form method="POST" class="inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_user_status">
                                <input type="hidden" name="key_id" value="<?= (int)$au['id'] ?>">
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg font-bold <?= $au['status'] === 'active' ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' ?>">
                                    <?= $au['status'] === 'active' ? 'Disable API' : 'Enable API' ?>
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Regenerate OTP API key for @<?= Validator::e($au['username']) ?>? Their current key will stop working immediately.');">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="regenerate_user_key">
                                <input type="hidden" name="user_id" value="<?= (int)$au['user_id'] ?>">
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold">Regen</button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Revoke and delete this OTP API key?');">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="revoke_user_key">
                                <input type="hidden" name="key_id" value="<?= (int)$au['id'] ?>">
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold">Revoke</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'pricing'): ?>
<!-- TAB 3: RESELLER PRICING RULES -->
<div class="mb-5 p-4 rounded-2xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-900">
    <strong>Server-Side Pricing Precedence:</strong>
    <span class="font-mono">Service + Country Rule</span> &gt;
    <span class="font-mono">Service Rule</span> &gt;
    <span class="font-mono">Country Rule</span> &gt;
    <span class="font-mono">Global Rule</span> &gt;
    <span class="font-mono">Global Settings</span> — followed by optional per-user <span class="font-mono">Custom Markup %</span> (e.g. <span class="font-mono">-5%</span> for VIP resellers or <span class="font-mono">+10%</span> surcharge). Reseller API clients can never submit or override prices.
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- Add/Edit Rule Form -->
    <form method="POST" class="smm-card rounded-3xl p-5 space-y-3.5 text-xs h-fit"
          x-data="{ scope: '<?= Validator::e($editRule['scope'] ?? 'global') ?>', pType: '<?= Validator::e($editRule['pricing_type'] ?? 'percent') ?>' }">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="save_pricing_rule">
        <input type="hidden" name="rule_id" value="<?= (int)($editRule['id'] ?? 0) ?>">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900"><?= $editRule ? 'Edit Pricing Rule #' . (int)$editRule['id'] : 'Add Reseller Pricing Rule' ?></h2>
            <?php if ($editRule): ?><a href="?tab=pricing" class="text-slate-500 font-bold hover:underline">New</a><?php endif; ?>
        </div>

        <div>
            <label class="block font-bold text-slate-700 mb-1">Rule Scope</label>
            <select name="scope" x-model="scope" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                <option value="global">Global (All Services &amp; Countries)</option>
                <option value="service">Specific Service</option>
                <option value="country">Specific Country</option>
                <option value="service_country">Specific Service + Country</option>
            </select>
        </div>

        <div x-show="scope === 'service' || scope === 'service_country'">
            <label class="block font-bold text-slate-700 mb-1">OTP Service</label>
            <select name="service_id" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                <option value="0">— Select Service —</option>
                <?php foreach ($servicesList as $sv): ?>
                <option value="<?= (int)$sv['id'] ?>" <?= (int)($editRule['service_id'] ?? 0) === (int)$sv['id'] ? 'selected' : '' ?>><?= Validator::e($sv['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div x-show="scope === 'country' || scope === 'service_country'">
            <label class="block font-bold text-slate-700 mb-1">Country</label>
            <select name="country_id" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                <option value="0">— Select Country —</option>
                <?php foreach ($countriesList as $ct): ?>
                <option value="<?= (int)$ct['id'] ?>" <?= (int)($editRule['country_id'] ?? 0) === (int)$ct['id'] ? 'selected' : '' ?>><?= Validator::e(($ct['flag_emoji'] ? $ct['flag_emoji'] . ' ' : '') . $ct['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block font-bold text-slate-700 mb-1">Pricing Strategy</label>
            <select name="pricing_type" x-model="pType" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                <option value="percent">Percentage Markup / Discount on Base Price (%)</option>
                <option value="fixed_profit">Fixed Profit Added to Provider Cost (<?= Validator::e($baseCurrency['code']) ?>)</option>
                <option value="fixed_price">Fixed Reseller Selling Price (<?= Validator::e($baseCurrency['code']) ?>)</option>
            </select>
        </div>

        <div x-show="pType === 'percent'">
            <label class="block font-bold text-slate-700 mb-1">Markup / Discount % (negative for reseller discount)</label>
            <input type="number" step="0.01" name="markup_percent" value="<?= Validator::e((string)($editRule['markup_percent'] ?? '0.00')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>

        <div x-show="pType === 'fixed_profit' || pType === 'percent'">
            <label class="block font-bold text-slate-700 mb-1">Fixed Profit Addition (<?= Validator::e($baseCurrency['code']) ?>)</label>
            <input type="number" step="0.0001" name="fixed_profit" value="<?= Validator::e((string)($editRule['fixed_profit'] ?? '0.0000')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>

        <div x-show="pType === 'fixed_price'">
            <label class="block font-bold text-slate-700 mb-1">Fixed Reseller Price (<?= Validator::e($baseCurrency['code']) ?>)</label>
            <input type="number" step="0.0001" min="0.0001" name="fixed_price" value="<?= Validator::e((string)($editRule['fixed_price'] ?? '')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Min Selling Price</label>
                <input type="number" step="0.0001" min="0" name="min_selling_price" value="<?= Validator::e((string)($editRule['min_selling_price'] ?? '')) ?>" placeholder="Optional" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                    <option value="active" <?= ($editRule['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editRule['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold">
            <?= $editRule ? 'Update Pricing Rule' : 'Create Pricing Rule' ?>
        </button>
    </form>

    <!-- Rules Table -->
    <div class="smm-card rounded-3xl overflow-hidden lg:col-span-2">
        <div class="px-5 py-4 border-b border-purple-50 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-slate-900">Active &amp; Configured Rules (<?= count($pricingRules) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Scope &amp; Target</th>
                        <th class="px-4 py-3">Pricing Formula</th>
                        <th class="px-4 py-3">Min Floor</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($pricingRules)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No custom reseller pricing rules yet. Using Global API Settings &amp; Store Base Prices.</td></tr>
                    <?php else: foreach ($pricingRules as $pr): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded bg-purple-100 text-purple-800 text-[10px] font-extrabold uppercase"><?= Validator::e(str_replace('_', ' + ', $pr['scope'])) ?></span>
                            <div class="font-bold text-slate-800 mt-1">
                                <?php
                                if ($pr['scope'] === 'global') echo 'All Services & Countries';
                                elseif ($pr['scope'] === 'service') echo Validator::e($pr['service_name'] ?: 'Service #' . $pr['service_id']);
                                elseif ($pr['scope'] === 'country') echo Validator::e(($pr['flag_emoji'] ? $pr['flag_emoji'] . ' ' : '') . ($pr['country_name'] ?: 'Country #' . $pr['country_id']));
                                else echo Validator::e(($pr['service_name'] ?: '#' . $pr['service_id']) . ' — ' . ($pr['flag_emoji'] ? $pr['flag_emoji'] . ' ' : '') . ($pr['country_name'] ?: '#' . $pr['country_id']));
                                ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono font-bold text-slate-800">
                            <?php if ($pr['pricing_type'] === 'fixed_price'): ?>
                                Fixed: <?= Currency::display((string)$pr['fixed_price'], null, 4, true) ?>
                            <?php elseif ($pr['pricing_type'] === 'fixed_profit'): ?>
                                Cost + <?= Currency::display((string)$pr['fixed_profit'], null, 4, true) ?>
                            <?php else: ?>
                                <?= Validator::e((string)$pr['markup_percent']) ?>%
                                <?php if (Currency::cmp((string)$pr['fixed_profit'], '0', 4) !== 0): ?>
                                    + <?= Currency::display((string)$pr['fixed_profit'], null, 4, true) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 tabular-nums"><?= $pr['min_selling_price'] ? Currency::display((string)$pr['min_selling_price'], null, 4, true) : '—' ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase <?= $pr['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500' ?>">
                                <?= Validator::e($pr['status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex gap-1.5">
                                <a href="?tab=pricing&edit_rule=<?= (int)$pr['id'] ?>" class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">Edit</a>
                                <form method="POST" class="inline">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_pricing_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int)$pr['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold"><?= $pr['status'] === 'active' ? 'Pause' : 'Enable' ?></button>
                                </form>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this pricing rule?');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete_pricing_rule">
                                    <input type="hidden" name="rule_id" value="<?= (int)$pr['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'settings'): ?>
<!-- TAB 4: GLOBAL API SETTINGS -->
<form method="POST" class="smm-card rounded-3xl p-6 space-y-6 text-xs">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="save_global_settings">

    <div class="grid md:grid-cols-2 gap-6">
        <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100 space-y-3">
            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-700">Global Availability &amp; Security</div>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="otp_api_enabled" value="1" <?= $apiGlobalEnabled ? 'checked' : '' ?> class="mt-0.5 rounded text-purple-600">
                <span>
                    <strong class="block text-slate-900">Enable OTP Reseller API Globally</strong>
                    <span class="text-[11px] text-slate-500">Turning this OFF blocks all <code class="font-mono">/api/otp/v1/*</code> requests with HTTP 503 (<code class="font-mono">API_DISABLED</code>), while keeping Direct OTP Store, SMM Store, and existing activation tracking running normally.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer pt-2 border-t border-purple-100">
                <input type="checkbox" name="otp_api_ip_restriction_enabled" value="1" <?= Helpers::setting('otp_api_ip_restriction_enabled', '0') === '1' ? 'checked' : '' ?> class="mt-0.5 rounded text-purple-600">
                <span>
                    <strong class="block text-slate-900">Require IP Allowlist Globally</strong>
                    <span class="text-[11px] text-slate-500">When enabled, every reseller must configure at least one allowed IP address before API calls are accepted.</span>
                </span>
            </label>
            <div class="grid grid-cols-2 gap-3 pt-2">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">API Version Label</label>
                    <input type="text" name="otp_api_version" value="<?= Validator::e(Helpers::setting('otp_api_version', 'v1')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Log Retention (Days)</label>
                    <input type="number" min="1" max="365" name="otp_api_log_retention_days" value="<?= Validator::e(Helpers::setting('otp_api_log_retention_days', '30')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100 space-y-3">
            <div class="text-xs font-extrabold uppercase tracking-wider text-purple-700">Rate Limits &amp; Order Quotas</div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Global Rate Limit / Min</label>
                    <input type="number" min="1" name="otp_api_global_rate_limit" value="<?= Validator::e(Helpers::setting('otp_api_global_rate_limit', '120')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default User Limit / Min</label>
                    <input type="number" min="1" name="otp_api_default_user_rate_limit" value="<?= Validator::e(Helpers::setting('otp_api_default_user_rate_limit', '60')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default Max Daily API Orders</label>
                    <input type="number" min="1" name="otp_api_max_daily_orders" value="<?= Validator::e(Helpers::setting('otp_api_max_daily_orders', '500')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default Max Active API Orders</label>
                    <input type="number" min="1" name="otp_api_max_active_activations" value="<?= Validator::e(Helpers::setting('otp_api_max_active_activations', '25')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
        </div>
    </div>

    <!-- Allowed Operations -->
    <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
        <div class="text-xs font-extrabold uppercase tracking-wider text-purple-700 mb-3">Allowed API Operations</div>
        <div class="grid sm:grid-cols-3 gap-2.5">
            <?php foreach ($allOps as $opKey => $opLabel): ?>
            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-white border border-purple-100 cursor-pointer">
                <input type="checkbox" name="allowed_ops[]" value="<?= Validator::e($opKey) ?>" <?= in_array($opKey, $allowedOpsActive, true) ? 'checked' : '' ?> class="rounded text-purple-600">
                <span class="font-mono font-bold text-slate-800"><?= Validator::e($opLabel) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Global Fallback Reseller Markup -->
    <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100 space-y-3">
        <div class="text-xs font-extrabold uppercase tracking-wider text-purple-700">Global Default Reseller Markup (Fallback When No Custom Rule Matches)</div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Markup Type</label>
                <select name="otp_api_global_markup_type" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-semibold">
                    <option value="percent" <?= Helpers::setting('otp_api_global_markup_type', 'percent') === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                    <option value="fixed_profit" <?= Helpers::setting('otp_api_global_markup_type', 'percent') === 'fixed_profit' ? 'selected' : '' ?>>Fixed Profit (<?= Validator::e($baseCurrency['code']) ?>)</option>
                </select>
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Markup %</label>
                <input type="number" step="0.01" name="otp_api_global_markup_percent" value="<?= Validator::e(Helpers::setting('otp_api_global_markup_percent', '0.00')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Fixed Profit (<?= Validator::e($baseCurrency['code']) ?>)</label>
                <input type="number" step="0.0001" name="otp_api_global_fixed_profit" value="<?= Validator::e(Helpers::setting('otp_api_global_fixed_profit', '0.0000')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Global Minimum Price Floor (<?= Validator::e($baseCurrency['code']) ?>)</label>
                <input type="number" step="0.0001" min="0" name="otp_api_global_min_price" value="<?= Validator::e(Helpers::setting('otp_api_global_min_price', '0.0000')) ?>" class="w-full px-3 py-2 rounded-xl border border-purple-200">
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow-sm">Save Global API Settings</button>
    </div>
</form>

<?php else: ?>
<!-- TAB 5: SANITIZED API USAGE LOGS -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <form method="GET" class="flex-1 smm-card rounded-2xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2.5 text-xs">
        <input type="hidden" name="tab" value="logs">
        <input type="text" name="luser" value="<?= Validator::e($logUser) ?>" placeholder="Username..." class="px-3 py-2 rounded-xl border border-purple-200">
        <input type="text" name="lendpoint" value="<?= Validator::e($logEndpoint) ?>" placeholder="Endpoint (e.g. /activation/create)" class="px-3 py-2 rounded-xl border border-purple-200">
        <select name="lstatus" class="px-3 py-2 rounded-xl border border-purple-200 font-semibold">
            <option value="all" <?= $logStatus === 'all' ? 'selected' : '' ?>>All Results</option>
            <option value="success" <?= $logStatus === 'success' ? 'selected' : '' ?>>Success Only</option>
            <option value="error" <?= $logStatus === 'error' ? 'selected' : '' ?>>Errors Only</option>
        </select>
        <input type="text" name="lerr" value="<?= Validator::e($logErrCode) ?>" placeholder="Error code..." class="px-3 py-2 rounded-xl border border-purple-200 font-mono">
        <div class="flex gap-1.5">
            <button type="submit" class="flex-1 px-4 py-2 rounded-xl bg-purple-600 text-white font-extrabold">Filter</button>
            <a href="?tab=logs" class="px-3 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Reset</a>
        </div>
    </form>
    <form method="POST" onsubmit="return confirm('Prune OTP API logs older than configured retention days (<?= Validator::e(Helpers::setting('otp_api_log_retention_days', '30')) ?> days)?');">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="prune_logs">
        <button type="submit" class="px-4 py-3 rounded-2xl bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-700 text-xs font-extrabold">
            Prune Old Logs
        </button>
    </form>
</div>

<div class="smm-card rounded-3xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-purple-50 flex items-center justify-between text-xs">
        <span class="font-extrabold text-slate-900">Sanitized OTP API Request Logs (<?= number_format($logTotal) ?>)</span>
        <span class="text-slate-400">API keys, Bearer headers, and SMS OTP codes are automatically stripped before logging.</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                <tr>
                    <th class="px-4 py-3">Time</th>
                    <th class="px-4 py-3">User / IP</th>
                    <th class="px-4 py-3">Request</th>
                    <th class="px-4 py-3">HTTP / Latency</th>
                    <th class="px-4 py-3">Outcome</th>
                    <th class="px-4 py-3">Metadata</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-purple-50">
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No OTP Reseller API logs matched your filter.</td></tr>
                <?php else: foreach ($logs as $lg): ?>
                <tr class="hover:bg-purple-50/30">
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= date('M d, H:i:s', strtotime($lg['created_at'])) ?></td>
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-800"><?= $lg['username'] ? '@' . Validator::e($lg['username']) : 'Unauthenticated' ?></div>
                        <div class="text-[10px] font-mono text-slate-400"><?= Validator::e($lg['ip_address'] ?: '—') ?></div>
                    </td>
                    <td class="px-4 py-3 font-mono font-bold text-slate-900">
                        <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px]"><?= Validator::e($lg['http_method']) ?></span>
                        <?= Validator::e($lg['endpoint']) ?>
                    </td>
                    <td class="px-4 py-3 font-mono">
                        <span class="font-extrabold <?= !empty($lg['success']) ? 'text-emerald-700' : 'text-rose-700' ?>"><?= (int)$lg['http_status'] ?></span>
                        <span class="text-[10px] text-slate-400 ml-1"><?= (int)$lg['response_time_ms'] ?>ms</span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (!empty($lg['success'])): ?>
                        <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-extrabold uppercase">Success</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[10px] font-mono font-extrabold"><?= Validator::e($lg['error_code'] ?: 'ERROR') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 font-mono text-[10px] text-slate-500 max-w-xs truncate" title="<?= Validator::e((string)$lg['request_meta_json']) ?>">
                        <?= Validator::e((string)$lg['request_meta_json'] ?: '{}') ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($logTotalPages > 1): ?>
    <div class="px-5 py-3.5 border-t border-purple-50 flex items-center justify-between text-xs">
        <span class="text-slate-500">Page <?= $logPage ?> of <?= $logTotalPages ?></span>
        <div class="flex gap-2">
            <?php if ($logPage > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $logPage - 1])) ?>" class="px-3 py-1.5 rounded-lg bg-slate-100 font-bold">Previous</a>
            <?php endif; ?>
            <?php if ($logPage < $logTotalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $logPage + 1])) ?>" class="px-3 py-1.5 rounded-lg bg-purple-600 text-white font-bold">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
