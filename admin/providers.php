<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('providers.manage');
$pageTitle = 'Provider API Management & Auto-Import';

$importReport = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $apiUrl = Validator::url($_POST['api_url'] ?? '');
        $rawKey = trim((string)($_POST['api_key'] ?? ''));
        $curr = strtoupper(Validator::str($_POST['currency_code'] ?? Currency::getBaseCode(), 10));
        $rateMode = ($_POST['rate_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
        $exRate = Currency::normalize((string)($_POST['exchange_rate'] ?? '1.0'), 6);
        if ($rateMode === 'auto') {
            $provCurrRow = Currency::getCurrency($curr);
            if ($provCurrRow && Currency::cmp((string)$provCurrRow['exchange_rate'], '0', 6) > 0) {
                $exRate = Currency::div('1', (string)$provCurrRow['exchange_rate'], 6);
            } else {
                $exRate = '1.000000';
            }
        }
        $margin = Currency::normalize((string)($_POST['default_profit_margin'] ?? '30.00'), 2);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || !$apiUrl) {
            Helpers::setFlash('error', 'Valid provider name and HTTPS/HTTP API URL are required.');
        } elseif ($id > 0) {
            if ($rawKey !== '') {
                Database::execute(
                    "UPDATE providers SET name=?, api_url=?, api_key_enc=?, currency_code=?, exchange_rate=?, rate_mode=?, default_profit_margin=?, status=? WHERE id=?",
                    [$name, $apiUrl, Helpers::encryptSecret($rawKey), $curr, $exRate, $rateMode, $margin, $status, $id]
                );
            } else {
                Database::execute(
                    "UPDATE providers SET name=?, api_url=?, currency_code=?, exchange_rate=?, rate_mode=?, default_profit_margin=?, status=? WHERE id=?",
                    [$name, $apiUrl, $curr, $exRate, $rateMode, $margin, $status, $id]
                );
            }
            Helpers::setFlash('success', 'Provider updated.');
        } else {
            if ($rawKey === '') {
                Helpers::setFlash('error', 'API Key is required for a new provider.');
            } else {
                Database::insert(
                    "INSERT INTO providers (name, api_url, api_key_enc, currency_code, exchange_rate, rate_mode, default_profit_margin, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $apiUrl, Helpers::encryptSecret($rawKey), $curr, $exRate, $rateMode, $margin, $status]
                );
                Helpers::setFlash('success', 'Provider added.');
            }
        }
        Helpers::redirect('/admin/providers.php');
    } elseif ($action === 'test_balance') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        $prov = Database::fetch("SELECT * FROM providers WHERE id = ? LIMIT 1", [$pid]);
        if ($prov) {
            $res = ProviderAPI::getBalance($prov);
            if ($res['ok']) {
                Helpers::setFlash('success', $prov['name'] . ' connected! Balance: ' . ($res['currency'] ?? $prov['currency_code']) . ' ' . ($res['balance'] ?? '0'));
            } else {
                Helpers::setFlash('error', 'Connection test failed: ' . ($res['error'] ?? 'Unknown error'));
            }
        }
        Helpers::redirect('/admin/providers.php');
    } elseif ($action === 'import_services') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        $dupMode = ($_POST['duplicate_mode'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $marginOverride = trim((string)($_POST['margin_override'] ?? ''));
        $fallbackCatId = (int)($_POST['fallback_category_id'] ?? 1);

        $prov = Database::fetch("SELECT * FROM providers WHERE id = ? LIMIT 1", [$pid]);
        if ($prov) {
            $res = CategoryMapper::importFromProvider(
                $prov,
                $dupMode,
                $marginOverride !== '' ? $marginOverride : null,
                $fallbackCatId
            );
            if ($res['ok']) {
                $_SESSION['_provider_import_report'] = ['provider' => $prov['name'], 'stats' => $res['stats']];
                Helpers::setFlash('success', 'Provider import completed for ' . $prov['name'] . '!');
            } else {
                Helpers::setFlash('error', 'Import failed: ' . ($res['error'] ?? 'Unknown error'));
            }
        }
        Helpers::redirect('/admin/providers.php');
    } elseif ($action === 'recalculate_prices') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        $prov = Database::fetch("SELECT * FROM providers WHERE id = ? LIMIT 1", [$pid]);
        if ($prov) {
            $svcs = Database::fetchAll("SELECT id, provider_rate, provider_currency FROM services WHERE provider_id = ?", [$pid]);
            $count = 0;
            $provCurr = strtoupper(trim((string)($prov['currency_code'] ?: Currency::getBaseCode())));
            $margin = (string)$prov['default_profit_margin'];
            $rateMode = (string)($prov['rate_mode'] ?? 'auto');
            $exRate = (string)$prov['exchange_rate'];
            foreach ($svcs as $s) {
                if (Currency::cmp((string)$s['provider_rate'], '0', 4) <= 0) continue;
                $calc = Currency::calculateProviderToBase((string)$s['provider_rate'], (string)($s['provider_currency'] ?: $provCurr), $margin, $rateMode, $exRate);
                Database::execute("UPDATE services SET rate = ?, provider_currency = ? WHERE id = ?", [$calc['selling_rate_base'], $provCurr, $s['id']]);
                $count++;
            }
            Helpers::setFlash('success', "Recalculated selling rates in " . Currency::getBaseCode() . " for {$count} service(s) from {$prov['name']}.");
        }
        Helpers::redirect('/admin/providers.php');
    } elseif ($action === 'delete_provider') {
        $pid = (int)($_POST['provider_id'] ?? 0);
        Database::execute("DELETE FROM providers WHERE id = ?", [$pid]);
        Helpers::setFlash('success', 'Provider deleted.');
        Helpers::redirect('/admin/providers.php');
    }
}

if (!empty($_SESSION['_provider_import_report'])) {
    $importReport = $_SESSION['_provider_import_report'];
    unset($_SESSION['_provider_import_report']);
}

$providers = Database::fetchAll("SELECT * FROM providers ORDER BY id DESC");
$categories = Database::fetchAll("SELECT id, name FROM categories ORDER BY sort_order ASC, id ASC");
$mappings = Database::fetchAll(
    "SELECT psm.*, p.name AS provider_name, s.name AS local_service_name, s.rate AS local_rate, c.name AS local_category_name
     FROM provider_service_mappings psm
     JOIN providers p ON p.id = psm.provider_id
     JOIN services s ON s.id = psm.service_id
     JOIN categories c ON c.id = s.category_id
     ORDER BY psm.id DESC LIMIT 60"
);
$providerLogs = Database::fetchAll(
    "SELECT al.*, p.name AS provider_name
     FROM api_logs al
     LEFT JOIN providers p ON p.id = al.provider_id
     WHERE al.log_type = 'provider_out'
     ORDER BY al.id DESC LIMIT 25"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<?php if ($importReport): $st = $importReport['stats']; ?>
<div class="mb-6 smm-card rounded-3xl p-6 border-2 border-purple-400">
    <h2 class="text-base font-extrabold text-purple-900 mb-3">Import Summary — <?= Validator::e($importReport['provider']) ?></h2>
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 text-center text-xs">
        <div class="p-3 rounded-xl bg-slate-50"><div class="text-slate-500">Fetched</div><div class="text-lg font-extrabold"><?= (int)$st['total_fetched'] ?></div></div>
        <div class="p-3 rounded-xl bg-emerald-50"><div class="text-emerald-700">New Imported</div><div class="text-lg font-extrabold text-emerald-700"><?= (int)$st['imported'] ?></div></div>
        <div class="p-3 rounded-xl bg-indigo-50"><div class="text-indigo-700">Updated</div><div class="text-lg font-extrabold text-indigo-700"><?= (int)$st['updated'] ?></div></div>
        <div class="p-3 rounded-xl bg-amber-50"><div class="text-amber-700">Skipped</div><div class="text-lg font-extrabold text-amber-700"><?= (int)$st['skipped'] ?></div></div>
        <div class="p-3 rounded-xl bg-purple-50"><div class="text-purple-700">Cats Created</div><div class="text-lg font-extrabold text-purple-700"><?= (int)$st['categories_created'] ?></div></div>
        <div class="p-3 rounded-xl bg-purple-50"><div class="text-purple-700">Cats Reused</div><div class="text-lg font-extrabold text-purple-700"><?= (int)$st['categories_reused'] ?></div></div>
    </div>
    <?php if ($st['missing_cat_warning']): ?>
        <p class="mt-3 text-xs text-amber-700 font-semibold">Note: <?= (int)$st['used_fallback_cat'] ?> service(s) did not include an original provider category and were assigned to your chosen fallback category.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6" x-data="{ editProv: null, importProv: null }">
    <!-- Add / Edit Provider -->
    <div class="lg:col-span-5 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4" x-text="editProv ? 'Edit Provider' : 'Add Upstream SMM Provider'"></h2>
        <form method="POST" class="space-y-3 text-xs">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_provider">
            <input type="hidden" name="id" :value="editProv?.id || 0">
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Provider Name</label>
                <input type="text" name="name" :value="editProv?.name || ''" required placeholder="e.g. JustAnotherPanel / Peakerr" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">API v2 Endpoint URL</label>
                <input type="url" name="api_url" :value="editProv?.api_url || ''" required placeholder="https://provider.com/api/v2" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
            </div>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">API Key <span x-show="editProv" class="text-slate-400 font-normal">(leave blank to keep current)</span></label>
                <input type="password" name="api_key" :required="!editProv" placeholder="Provider API Secret Key" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm">
            </div>
            <?php
            $baseCode = Currency::getBaseCode();
            $knownCurrencies = Currency::getAllCurrencies();
            foreach (['INR' => 'Indian Rupee', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'RUB' => 'Russian Ruble', 'BRL' => 'Brazilian Real', 'TRY' => 'Turkish Lira', 'AED' => 'UAE Dirham'] as $kc => $kn) {
                if (!isset($knownCurrencies[$kc])) {
                    $knownCurrencies[$kc] = ['code' => $kc, 'name' => $kn, 'symbol' => Currency::getSymbol($kc)];
                }
            }
            ?>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Provider Currency</label>
                    <select name="currency_code" :value="editProv?.currency_code || '<?= Validator::e($baseCode) ?>'" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white font-bold">
                        <?php foreach ($knownCurrencies as $kc => $krow): ?>
                            <option value="<?= Validator::e($kc) ?>"><?= Validator::e($kc . ' (' . $krow['symbol'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Rate Mode</label>
                    <select name="rate_mode" :value="editProv?.rate_mode || 'auto'" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white">
                        <option value="auto">Auto (Live API)</option>
                        <option value="manual">Manual Factor</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Manual 1 Prov = <?= Validator::e($baseCode) ?></label>
                    <input type="number" step="0.000001" name="exchange_rate" :value="editProv?.exchange_rate || '1.000000'" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Margin %</label>
                    <input type="number" step="0.01" name="default_profit_margin" :value="editProv?.default_profit_margin || '30.00'" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
            </div>
            <p class="text-[11px] text-slate-500 bg-purple-50/60 p-2.5 rounded-xl border border-purple-100">
                <strong>Currency Accuracy:</strong> In <strong>Auto</strong> mode, provider rates (e.g., ₹5 in INR or $5 in USD) convert automatically to platform base currency (<strong><?= Validator::e($baseCode) ?></strong>) using <a href="/admin/currencies.php" class="text-purple-700 underline font-bold">Currencies &amp; Rates</a> before applying Profit Margin %.
            </p>
            <div>
                <label class="block font-bold uppercase text-slate-500 mb-1">Status</label>
                <select name="status" :value="editProv?.status || 'active'" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="flex gap-2 pt-1">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white font-bold">Save Provider</button>
                <button type="button" x-show="editProv" @click="editProv = null" class="px-4 py-2.5 rounded-xl bg-slate-100 font-bold">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Connected Providers List -->
    <div class="lg:col-span-7 smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Connected Providers (<?= count($providers) ?>)</h2>
        <?php if (empty($providers)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">No providers added yet. Add a provider on the left to test API connection and auto-import categories &amp; services.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($providers as $p): ?>
                <div class="p-4 rounded-2xl bg-[#F6F4FC] border border-purple-100">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div>
                            <div class="font-extrabold text-slate-900 text-sm"><?= Validator::e($p['name']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= Validator::e($p['api_url']) ?></div>
                        </div>
                        <?= Helpers::statusBadge((string)$p['status']) ?>
                    </div>
                    <?php
                    $provCurrCode = strtoupper(trim((string)($p['currency_code'] ?: Currency::getBaseCode())));
                    $provRateMode = (string)($p['rate_mode'] ?? 'auto');
                    $effInfo = Currency::calculateProviderToBase('1.0000', $provCurrCode, '0', $provRateMode, (string)$p['exchange_rate']);
                    ?>
                    <div class="grid grid-cols-3 gap-2 text-xs mb-3">
                        <div>Balance: <strong><?= Currency::formatRaw((string)$p['balance'], $provCurrCode, 2, true) ?></strong></div>
                        <div>Conv: <strong>1 <?= Validator::e($provCurrCode) ?> = <?= Validator::e($effInfo['effective_exchange_rate']) ?> <?= Validator::e($baseCode) ?></strong> <span class="text-[10px] uppercase text-purple-600 font-bold">(<?= Validator::e($provRateMode) ?>)</span></div>
                        <div>Margin: <strong><?= Validator::e((string)$p['default_profit_margin']) ?>%</strong></div>
                    </div>
                    <?php if ($p['last_error']): ?>
                        <div class="mb-3 p-2 rounded-lg bg-rose-50 text-rose-700 text-[11px] font-semibold">Last API Error: <?= Validator::e($p['last_error']) ?></div>
                    <?php endif; ?>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="test_balance">
                            <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-white border border-purple-200 text-purple-800 font-bold">Test &amp; Balance</button>
                        </form>
                        <a href="/admin/provider-categories.php?provider_id=<?= (int)$p['id'] ?>"
                           class="px-3.5 py-1.5 rounded-xl bg-violet-100 hover:bg-violet-200 text-violet-800 font-bold transition">
                            Import Categories &rarr;
                        </a>
                        <a href="/admin/provider-import.php?provider_id=<?= (int)$p['id'] ?>"
                           class="px-3.5 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold shadow-sm transition">
                            Fetch &amp; Select Services &rarr;
                        </a>
                        <form method="POST">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="recalculate_prices">
                            <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-indigo-100 text-indigo-800 font-bold">Recalculate Rates</button>
                        </form>
                        <button type="button" @click="editProv = <?= Validator::e(json_encode($p)) ?>" class="px-3 py-1.5 rounded-xl bg-slate-200 text-slate-800 font-bold">Edit</button>
                        <form method="POST" onsubmit="return confirm('Delete this provider?')">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete_provider">
                            <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-100 text-rose-700 font-bold">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Import & Category Auto-Mapping Modal -->
    <div x-show="importProv" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="importProv = null" class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-purple-100">
            <h3 class="text-base font-extrabold text-slate-900 mb-1">Import Services — <span x-text="importProv?.name"></span></h3>
            <p class="text-xs text-slate-500 mb-4">Automatically matches existing system categories and creates missing categories from the provider's original category names.</p>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="import_services">
                <input type="hidden" name="provider_id" :value="importProv?.id">
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Duplicate Service Handling</label>
                    <select name="duplicate_mode" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                        <option value="update">Update Existing (Update Category, Price &amp; Info)</option>
                        <option value="skip">Skip Existing Services</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Profit Margin % (Optional Override)</label>
                    <input type="number" step="0.01" name="margin_override" :placeholder="`Default: ${importProv?.margin}%`" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                </div>
                <div>
                    <label class="block font-bold uppercase text-slate-500 mb-1">Fallback Category (Only if provider omits category)</label>
                    <select name="fallback_category_id" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                        <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="importProv = null" class="px-4 py-2 rounded-xl bg-slate-100 font-bold">Cancel</button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-purple-600 text-white font-bold">Start Auto-Import &rarr;</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Service Mappings & API Logs -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Provider-Wise Service Mappings</h2>
        <div class="overflow-x-auto max-h-80">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b uppercase text-slate-400"><th class="py-2">Provider</th><th class="py-2">Ext ID</th><th class="py-2">Mapped Category &rarr; Service</th><th class="py-2 text-right">Prov Cost</th><th class="py-2 text-right">Sell Rate</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td class="py-2 font-bold"><?= Validator::e($m['provider_name']) ?></td>
                        <td class="py-2">#<?= Validator::e($m['external_service_id']) ?></td>
                        <td class="py-2">
                            <div class="font-semibold text-purple-700"><?= Validator::e($m['local_category_name']) ?></div>
                            <div class="text-slate-600 truncate max-w-[160px]"><?= Validator::e($m['local_service_name']) ?></div>
                        </td>
                        <td class="py-2 text-right text-slate-500 tabular-nums"><?= Currency::formatRaw((string)$m['external_rate'], (string)($m['external_currency'] ?: $baseCode), 2, true) ?></td>
                        <td class="py-2 text-right font-bold tabular-nums"><?= Currency::formatBase((string)$m['local_rate'], 2, true) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mappings)) echo '<tr><td colspan="4" class="py-6 text-center text-slate-400">No mappings yet.</td></tr>'; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-6">
        <h2 class="text-base font-extrabold text-slate-900 mb-4">Provider API Logs</h2>
        <div class="space-y-2 max-h-80 overflow-y-auto text-xs">
            <?php foreach ($providerLogs as $pl): ?>
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div class="flex justify-between font-bold">
                    <span><?= Validator::e($pl['provider_name'] ?: 'Provider') ?> (HTTP <?= (int)$pl['http_code'] ?>)</span>
                    <span class="<?= $pl['status'] === 'ok' ? 'text-emerald-600' : 'text-rose-600' ?>"><?= Validator::e($pl['status']) ?></span>
                </div>
                <div class="text-[11px] text-slate-500 truncate"><?= Validator::e($pl['endpoint']) ?></div>
                <div class="text-[11px] text-slate-600 mt-1 truncate"><?= Validator::e($pl['response_payload']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($providerLogs)) echo '<p class="text-slate-400 text-center py-6">No provider API calls logged yet.</p>'; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
