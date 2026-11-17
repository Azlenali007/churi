<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('settings.manage');
$pageTitle = 'Currencies & Exchange Rates';

Currency::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'sync_now') {
        $res = ExchangeRateService::syncRates('manual', (int)$admin['id'], true);
        if ($res['ok']) {
            $updCount = count($res['updated'] ?? []);
            $skipCount = count($res['skipped_manual'] ?? []);
            $msg = "Exchange rates updated via " . ($res['source'] ?? 'API') . " ({$updCount} updated";
            if ($skipCount > 0) {
                $msg .= ", {$skipCount} manual override(s) preserved";
            }
            $msg .= ").";
            Helpers::setFlash('success', $msg);
        } else {
            Helpers::setFlash('error', 'Rate sync failed (cached rates preserved): ' . ($res['error'] ?? 'Unknown error'));
        }
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'save_automation') {
        $autoEnabled = isset($_POST['exchange_rate_auto_update']) ? '1' : '0';
        $interval = max(1, min(168, (int)($_POST['exchange_rate_interval_hours'] ?? 12)));
        $provider = in_array($_POST['exchange_rate_provider'] ?? '', [ExchangeRateService::SOURCE_FRANKFURTER, ExchangeRateService::SOURCE_OPEN_ER_API], true)
            ? $_POST['exchange_rate_provider']
            : ExchangeRateService::SOURCE_FRANKFURTER;

        Helpers::setSetting('exchange_rate_auto_update', $autoEnabled, 'currency');
        Helpers::setSetting('exchange_rate_interval_hours', (string)$interval, 'currency');
        Helpers::setSetting('exchange_rate_provider', $provider, 'currency');

        Logger::activity('admin', (int)$admin['id'], 'currency.automation', 'currency', 'settings', "Auto={$autoEnabled}, Interval={$interval}h, API={$provider}");
        Helpers::setFlash('success', 'Auto-update schedule & API settings saved.');
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'switch_base') {
        $newBase = strtoupper(trim((string)($_POST['base_currency'] ?? 'INR')));
        $res = ExchangeRateService::switchBaseCurrency($newBase, (int)$admin['id']);
        Helpers::setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? ($res['message'] ?? "Platform base currency switched to {$newBase}.") : ($res['error'] ?? 'Failed to switch base currency.'));
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'toggle_status') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $curr = Currency::getCurrency($code);
        if (!$curr) {
            Helpers::setFlash('error', 'Currency not found.');
        } elseif ((int)$curr['is_base'] === 1) {
            Helpers::setFlash('error', 'The platform base currency cannot be disabled.');
        } else {
            $nextStatus = ($curr['status'] === 'active') ? 'inactive' : 'active';
            Database::execute("UPDATE currencies SET status = ? WHERE code = ?", [$nextStatus, $code]);
            Currency::clearCache();
            Logger::activity('admin', (int)$admin['id'], 'currency.toggle', 'currency', $code, "Set {$code} status to {$nextStatus}");
            Helpers::setFlash('success', "{$code} marked as {$nextStatus}.");
        }
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'reset_auto') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $curr = Currency::getCurrency($code);
        if ($curr && (int)$curr['is_base'] === 0) {
            Database::execute("UPDATE currencies SET is_manual_override = 0, rate_source = 'pending_auto', update_status = 'ok' WHERE code = ?", [$code]);
            Currency::clearCache();
            $syncRes = ExchangeRateService::syncRates('manual', (int)$admin['id'], true);
            if ($syncRes['ok']) {
                Helpers::setFlash('success', "{$code} switched back to automatic exchange rate updates and synced.");
            } else {
                Helpers::setFlash('success', "{$code} manual override cleared. Will update on next successful API sync.");
            }
        }
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'save_currency') {
        $code = strtoupper(preg_replace('/[^A-Z]/', '', (string)($_POST['code'] ?? '')));
        $name = Validator::str($_POST['name'] ?? '', 60);
        $symbol = Validator::str($_POST['symbol'] ?? '', 10);
        $rateRaw = (string)($_POST['exchange_rate'] ?? '1.000000');
        $rate = Validator::decimal($rateRaw, 6);
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $isManual = isset($_POST['is_manual_override']) ? 1 : 0;

        if (strlen($code) < 2 || $name === '' || $symbol === '' || !$rate || Currency::cmp($rate, '0', 6) <= 0) {
            Helpers::setFlash('error', 'Provide a valid currency code, name, symbol, and strictly positive exchange rate.');
            Helpers::redirect('/admin/currencies.php');
        }

        $existing = Currency::getCurrency($code);
        $isBase = $existing && (int)$existing['is_base'] === 1;
        if ($isBase) {
            $rate = '1.000000';
            $status = 'active';
            $isManual = 0;
            $rateSource = 'base';
            $updStatus = 'ok';
            Helpers::setSetting('currency_symbol', $symbol, 'currency');
        } else {
            $rateSource = $isManual ? 'manual' : ($existing['rate_source'] ?? 'manual');
            $updStatus = $isManual ? 'manual' : ($existing['update_status'] ?? 'manual');
        }

        Database::execute(
            "INSERT INTO currencies (code, name, symbol, exchange_rate, is_base, is_manual_override, rate_source, update_status, last_updated_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
               name = VALUES(name),
               symbol = VALUES(symbol),
               exchange_rate = VALUES(exchange_rate),
               is_manual_override = VALUES(is_manual_override),
               rate_source = VALUES(rate_source),
               update_status = VALUES(update_status),
               last_updated_at = NOW(),
               status = VALUES(status)",
            [$code, $name, $symbol, $rate, $isBase ? 1 : 0, $isManual, $rateSource, $updStatus, $status]
        );
        Currency::clearCache();
        Logger::activity('admin', (int)$admin['id'], 'currency.save', 'currency', $code, "Saved {$code} ({$symbol}) rate={$rate} manual={$isManual}");
        Helpers::setFlash('success', "Currency {$code} saved.");
        Helpers::redirect('/admin/currencies.php');
    }

    if ($action === 'recalculate_auto_services') {
        $autoProviders = Database::fetchAll("SELECT * FROM providers WHERE rate_mode = 'auto'");
        $updatedCount = 0;
        $defaultMargin = Currency::normalize(Helpers::setting('default_profit_margin', '30.00'), 2);
        foreach ($autoProviders as $prov) {
            $margin = Currency::cmp((string)$prov['default_profit_margin'], '0', 2) >= 0
                ? (string)$prov['default_profit_margin']
                : $defaultMargin;
            $provCurr = strtoupper(trim((string)($prov['currency_code'] ?: Currency::getBaseCode())));
            $svcs = Database::fetchAll("SELECT id, provider_rate, provider_currency FROM services WHERE provider_id = ?", [$prov['id']]);
            foreach ($svcs as $s) {
                if (Currency::cmp((string)$s['provider_rate'], '0', 4) <= 0) {
                    continue;
                }
                $calc = Currency::calculateProviderToBase(
                    (string)$s['provider_rate'],
                    (string)($s['provider_currency'] ?: $provCurr),
                    $margin,
                    'auto',
                    (string)$prov['exchange_rate']
                );
                Database::execute("UPDATE services SET rate = ? WHERE id = ?", [$calc['selling_rate_base'], $s['id']]);
                $updatedCount++;
            }
        }
        Logger::activity('admin', (int)$admin['id'], 'services.recalculate_rates', 'services', 'auto', "Recalculated {$updatedCount} service selling rates");
        Helpers::setFlash('success', "Recalculated {$updatedCount} auto-mode provider service price(s) in base currency. Historical orders were untouched.");
        Helpers::redirect('/admin/currencies.php');
    }
}

// Safe admin-only opportunistic sync fallback (respects interval)
ExchangeRateService::maybeOpportunisticSync();

$currencies = array_values(Currency::getAllCurrencies(true));
$baseCurr = Currency::getBaseCurrency();
$baseCode = $baseCurr['code'];
$isOutdated = ExchangeRateService::isRatesOutdated();
$lastSuccess = Helpers::setting('exchange_rate_last_success_at', '');
$lastAttempt = Helpers::setting('exchange_rate_last_attempt_at', '');
$lastStatus = Helpers::setting('exchange_rate_last_status', 'never');
$lastError = Helpers::setting('exchange_rate_last_error', '');
$autoUpdate = Helpers::setting('exchange_rate_auto_update', '1');
$intervalHours = (int)Helpers::setting('exchange_rate_interval_hours', '12');
$preferredApi = Helpers::setting('exchange_rate_provider', ExchangeRateService::SOURCE_FRANKFURTER);

$rateLogs = [];
try {
    $rateLogs = Database::fetchAll("SELECT * FROM exchange_rate_logs ORDER BY id DESC LIMIT 15");
} catch (Throwable $e) {
    $rateLogs = [];
}

$cronCommand = 'php ' . APP_ROOT . '/cron/update_exchange_rates.php';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<?php if ($lastStatus === 'failed' || $isOutdated): ?>
<div class="mb-6 rounded-3xl p-4 sm:p-5 border <?= $lastStatus === 'failed' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-amber-50 border-amber-200 text-amber-800' ?> flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-2xl <?= $lastStatus === 'failed' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600' ?> flex items-center justify-center shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        </div>
        <div class="text-xs">
            <div class="font-extrabold text-sm">
                <?= $lastStatus === 'failed' ? 'Latest Automatic Exchange Rate Sync Failed — Using Cached Rates' : 'Cached Exchange Rates May Be Outdated' ?>
            </div>
            <p class="mt-0.5 opacity-90">
                Last successful sync: <strong><?= $lastSuccess ? Validator::e($lastSuccess) : 'Never synced via API yet (initial cached rates active)' ?></strong>.
                <?php if ($lastError !== ''): ?> Last error: <strong><?= Validator::e($lastError) ?></strong><?php endif; ?>
            </p>
        </div>
    </div>
    <form method="POST">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold shadow hover:bg-purple-700">Retry Live Sync Now</button>
    </form>
</div>
<?php endif; ?>

<!-- Top Summary + Manual Sync & Recalculate -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="smm-card rounded-3xl p-5 flex items-center justify-between">
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Platform Base Currency</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= Validator::e($baseCurr['symbol']) ?> <?= Validator::e($baseCode) ?></div>
            <div class="text-xs text-slate-500 mt-0.5"><?= Validator::e($baseCurr['name']) ?> (Rate: 1.000000)</div>
        </div>
        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center font-extrabold text-xl">
            <?= Validator::e($baseCurr['symbol']) ?>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-5">
        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Free Exchange API Status</div>
        <div class="flex items-center gap-2 mt-1.5">
            <?= Helpers::statusBadge($lastStatus === 'ok' ? 'active' : ($lastStatus === 'failed' ? 'failed' : 'pending')) ?>
            <span class="text-xs font-bold text-slate-700"><?= $preferredApi === ExchangeRateService::SOURCE_OPEN_ER_API ? 'Open ExchangeRate-API' : 'Frankfurter (ECB)' ?></span>
        </div>
        <div class="text-[11px] text-slate-500 mt-2">
            Last Sync: <?= $lastSuccess ? Validator::e(Helpers::timeAgo($lastSuccess)) . ' (' . Validator::e($lastSuccess) . ')' : 'Not yet synced' ?>
        </div>
    </div>

    <div class="smm-card rounded-3xl p-5 flex flex-col justify-between gap-2">
        <form method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="sync_now">
            <button type="submit" class="w-full py-2.5 px-4 rounded-2xl bg-gradient-to-r from-purple-600 to-violet-600 text-white text-xs font-extrabold shadow hover:opacity-95 transition">
                &#x21bb; Update Exchange Rates Now
            </button>
        </form>
        <form method="POST" onsubmit="return confirm('Recalculate selling prices in <?= Validator::e($baseCode) ?> for all auto-mode provider services? Existing orders are never modified.')">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="recalculate_auto_services">
            <button type="submit" class="w-full py-2 px-4 rounded-2xl bg-purple-50 text-purple-800 border border-purple-200 text-xs font-bold hover:bg-purple-100 transition">
                Recalculate Auto-Mode Service Prices
            </button>
        </form>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mb-6">
    <!-- Supported Currencies Table -->
    <div class="xl:col-span-8 smm-card rounded-3xl p-6" x-data="{ editCurr: null }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="text-base font-extrabold text-slate-900">Supported Currencies</h2>
                <p class="text-xs text-slate-500">1 <?= Validator::e($baseCode) ?> = Exchange Rate units of target currency. Manual overrides stay locked during automatic syncs.</p>
            </div>
        </div>

        <!-- Inline Edit Drawer -->
        <form x-show="editCurr" x-cloak method="POST" class="mb-5 p-4 rounded-2xl bg-[#F6F4FC] border border-purple-200 text-xs space-y-3">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_currency">
            <input type="hidden" name="code" :value="editCurr?.code">
            <div class="flex items-center justify-between">
                <div class="font-extrabold text-purple-900">Edit Currency: <span x-text="editCurr?.code"></span></div>
                <button type="button" @click="editCurr = null" class="text-slate-500 hover:text-slate-800 font-bold">&times; Close</button>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Display Name</label>
                    <input type="text" name="name" :value="editCurr?.name" required class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Symbol</label>
                    <input type="text" name="symbol" :value="editCurr?.symbol" required class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Rate (1 <?= Validator::e($baseCode) ?> =)</label>
                    <input type="number" step="0.000001" min="0.000001" name="exchange_rate" :value="editCurr?.exchange_rate" :readonly="editCurr?.is_base == 1" required class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white tabular-nums">
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Status</label>
                    <select name="status" :value="editCurr?.status" :disabled="editCurr?.is_base == 1" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white">
                        <option value="active">Active (Visible to Users)</option>
                        <option value="inactive">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                <label class="inline-flex items-center gap-2 font-semibold text-slate-700" x-show="editCurr?.is_base != 1">
                    <input type="checkbox" name="is_manual_override" value="1" :checked="editCurr?.is_manual_override == 1" class="rounded text-purple-600">
                    Lock as Manual Override (do not overwrite during automatic API updates)
                </label>
                <button type="submit" class="px-5 py-2 rounded-xl bg-purple-600 text-white font-bold shadow">Save Currency</button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 uppercase text-slate-400">
                        <th class="py-2.5 pr-3">Currency</th>
                        <th class="py-2.5 pr-3">Rate (1 <?= Validator::e($baseCode) ?> =)</th>
                        <th class="py-2.5 pr-3">Inverse</th>
                        <th class="py-2.5 pr-3">Source / Mode</th>
                        <th class="py-2.5 pr-3">Status</th>
                        <th class="py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($currencies as $c):
                        $isBase = (int)$c['is_base'] === 1;
                        $isManual = (int)($c['is_manual_override'] ?? 0) === 1;
                        $inverse = Currency::cmp((string)$c['exchange_rate'], '0', 6) > 0
                            ? Currency::div('1', (string)$c['exchange_rate'], 4)
                            : '1.0000';
                    ?>
                    <tr class="hover:bg-purple-50/40">
                        <td class="py-3 pr-3">
                            <div class="flex items-center gap-2.5">
                                <span class="w-8 h-8 rounded-xl bg-purple-100 text-purple-800 font-extrabold flex items-center justify-center shrink-0"><?= Validator::e($c['symbol']) ?></span>
                                <div>
                                    <div class="font-extrabold text-slate-900">
                                        <?= Validator::e($c['code']) ?>
                                        <?php if ($isBase): ?><span class="ml-1 px-2 py-0.5 rounded-full text-[10px] bg-purple-600 text-white">BASE</span><?php endif; ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500"><?= Validator::e($c['name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 pr-3 font-extrabold text-slate-900 tabular-nums"><?= Validator::e((string)$c['exchange_rate']) ?></td>
                        <td class="py-3 pr-3 text-slate-500 tabular-nums">1 <?= Validator::e($c['code']) ?> = <?= Validator::e($inverse) ?> <?= Validator::e($baseCode) ?></td>
                        <td class="py-3 pr-3">
                            <?php if ($isBase): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">Platform Base</span>
                            <?php elseif ($isManual): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Manual Lock</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">Auto (<?= Validator::e((string)($c['rate_source'] ?? 'api')) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($c['last_updated_at'])): ?>
                                <div class="text-[10px] text-slate-400 mt-0.5"><?= Validator::e(Helpers::timeAgo((string)$c['last_updated_at'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 pr-3"><?= Helpers::statusBadge((string)$c['status']) ?></td>
                        <td class="py-3 text-right whitespace-nowrap space-x-1">
                            <button type="button" @click="editCurr = <?= Validator::e(json_encode($c)) ?>" class="px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 font-bold hover:bg-purple-200">Edit</button>
                            <?php if (!$isBase): ?>
                                <?php if ($isManual): ?>
                                <form method="POST" class="inline">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="reset_auto">
                                    <input type="hidden" name="code" value="<?= Validator::e($c['code']) ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-bold hover:bg-indigo-200">Use Auto</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="inline">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="code" value="<?= Validator::e($c['code']) ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg <?= $c['status'] === 'active' ? 'bg-slate-200 text-slate-700' : 'bg-emerald-600 text-white' ?> font-bold">
                                        <?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Base Currency, Automation & Add Currency -->
    <div class="xl:col-span-4 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Auto-Update &amp; Free API Settings</h2>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_automation">
                <label class="flex items-center gap-2 p-3 rounded-2xl bg-[#F6F4FC] border border-purple-100 cursor-pointer">
                    <input type="checkbox" name="exchange_rate_auto_update" value="1" <?= $autoUpdate === '1' ? 'checked' : '' ?> class="rounded text-purple-600">
                    <span class="font-bold text-slate-800">Enable Automatic Rate Updates</span>
                </label>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Primary Free Exchange Rate API</label>
                    <select name="exchange_rate_provider" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                        <option value="<?= ExchangeRateService::SOURCE_FRANKFURTER ?>" <?= $preferredApi === ExchangeRateService::SOURCE_FRANKFURTER ? 'selected' : '' ?>>Frankfurter API (ECB Reference — No Key)</option>
                        <option value="<?= ExchangeRateService::SOURCE_OPEN_ER_API ?>" <?= $preferredApi === ExchangeRateService::SOURCE_OPEN_ER_API ? 'selected' : '' ?>>Open ExchangeRate-API (v6 — No Key)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Update Interval (Hours)</label>
                    <select name="exchange_rate_interval_hours" class="w-full px-3 py-2.5 rounded-xl border border-purple-200">
                        <?php foreach ([6 => 'Every 6 Hours', 12 => 'Every 12 Hours (Recommended)', 24 => 'Every 24 Hours'] as $h => $lbl): ?>
                        <option value="<?= $h ?>" <?= $intervalHours === $h ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 text-white font-bold shadow">Save Automation Settings</button>
            </form>

            <div class="mt-4 p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 space-y-1.5">
                <div class="font-bold text-slate-800">API Limits &amp; Caching Info</div>
                <p><strong>Frankfurter (api.frankfurter.app):</strong> 100% free, zero API keys, European Central Bank daily reference rates with automatic fallback to <strong>open.er-api.com</strong>.</p>
                <p><strong>cPanel Cron Command (Daily / Every 12h):</strong></p>
                <code class="block p-2 rounded-lg bg-slate-900 text-purple-200 text-[10px] break-all"><?= Validator::e($cronCommand) ?></code>
            </div>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Platform Base Currency</h2>
            <p class="text-xs text-slate-500 mb-3">Base currency is used to store accounting balances and selling rates. Switching re-normalizes exchange factors cleanly.</p>
            <form method="POST" class="space-y-3 text-xs" onsubmit="return confirm('Switch platform base currency? Exchange factors will be rebased automatically.')">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="switch_base">
                <select name="base_currency" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-bold">
                    <?php foreach ($currencies as $c): ?>
                    <option value="<?= Validator::e($c['code']) ?>" <?= (int)$c['is_base'] === 1 ? 'selected' : '' ?>><?= Validator::e($c['symbol'] . ' ' . $c['code'] . ' — ' . $c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-slate-900 text-white font-bold">Set as Base Currency</button>
            </form>
        </div>

        <div class="smm-card rounded-3xl p-6">
            <h2 class="text-base font-extrabold text-slate-900 mb-3">Add New Currency</h2>
            <form method="POST" class="space-y-2.5 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_currency">
                <div class="grid grid-cols-2 gap-2">
                    <input type="text" name="code" placeholder="CAD" required maxlength="10" class="px-3 py-2 rounded-xl border border-purple-200 uppercase">
                    <input type="text" name="symbol" placeholder="C$" required maxlength="10" class="px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <input type="text" name="name" placeholder="Canadian Dollar" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                <input type="number" step="0.000001" min="0.000001" name="exchange_rate" placeholder="Rate per 1 <?= Validator::e($baseCode) ?>" required class="w-full px-3 py-2 rounded-xl border border-purple-200">
                <label class="flex items-center gap-2 text-slate-600">
                    <input type="checkbox" name="is_manual_override" value="1" class="rounded text-purple-600">
                    <span>Lock manual rate (optional)</span>
                </label>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-100 text-purple-800 font-bold hover:bg-purple-200">+ Add Currency</button>
            </form>
        </div>
    </div>
</div>

<!-- Sync Audit History -->
<div class="smm-card rounded-3xl p-6">
    <h2 class="text-base font-extrabold text-slate-900 mb-4">Exchange Rate Sync History</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-purple-100 uppercase text-slate-400">
                    <th class="py-2 pr-3">Time</th>
                    <th class="py-2 pr-3">Trigger</th>
                    <th class="py-2 pr-3">API Source</th>
                    <th class="py-2 pr-3">Base</th>
                    <th class="py-2 pr-3">Status</th>
                    <th class="py-2">Rates / Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rateLogs as $log): ?>
                <tr>
                    <td class="py-2.5 pr-3 text-slate-500 whitespace-nowrap"><?= Validator::e($log['created_at']) ?></td>
                    <td class="py-2.5 pr-3 font-bold uppercase text-slate-700"><?= Validator::e($log['triggered_by']) ?></td>
                    <td class="py-2.5 pr-3 font-semibold text-purple-700"><?= Validator::e($log['provider_source']) ?></td>
                    <td class="py-2.5 pr-3 font-bold"><?= Validator::e($log['base_currency']) ?></td>
                    <td class="py-2.5 pr-3"><?= Helpers::statusBadge($log['status'] === 'success' ? 'completed' : 'failed') ?></td>
                    <td class="py-2.5 text-slate-600 max-w-md truncate">
                        <?php if (!empty($log['error_message'])): ?>
                            <span class="text-rose-600 font-semibold"><?= Validator::e($log['error_message']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($log['rates_json'])): ?>
                            <span class="font-mono text-[11px]"><?= Validator::e($log['rates_json']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rateLogs)): ?>
                <tr><td colspan="6" class="py-6 text-center text-slate-400">No exchange rate sync logs recorded yet. Click "Update Exchange Rates Now" above to run your first live sync.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
