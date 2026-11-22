<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('providers.manage');
CategoryMapper::ensureSchema();

$ajaxAction = (string)($_REQUEST['ajax_action'] ?? '');

if ($ajaxAction !== '') {
    ProviderErrorHandler::startAjaxGuard([
        'scope'    => 'smm',
        'endpoint' => '/admin/provider-import.php',
        'action'   => $ajaxAction,
        'admin_id' => (int)($admin['id'] ?? 0),
    ]);
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            CSRF::requireValid();
        }

        $providerId = (int)($_REQUEST['provider_id'] ?? 0);
        $provider = $providerId > 0 ? Database::fetch("SELECT * FROM providers WHERE id = ? LIMIT 1", [$providerId]) : null;

        if ($ajaxAction === 'fetch_catalog') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
            }
            if (!$provider) {
                Helpers::jsonResponse(['ok' => false, 'error' => 'Please select a valid provider.'], 404);
            }
            $res = CategoryMapper::fetchAndCacheCatalog($provider, (int)$admin['id']);
            Helpers::jsonResponse($res, 200);
        }

    if ($ajaxAction === 'query_catalog') {
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a provider.'], 404);
        }
        $search = trim((string)($_GET['search'] ?? ''));
        $provCat = trim((string)($_GET['provider_category'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? 'all'));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));

        $res = CategoryMapper::queryFetchedCatalog($providerId, $search, $provCat, $statusFilter, $page, $perPage);
        Helpers::jsonResponse($res, $res['ok'] ? 200 : 200);
    }

    if ($ajaxAction === 'select_ids') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a provider.'], 404);
        }
        $search = trim((string)($_POST['search'] ?? ''));
        $provCat = trim((string)($_POST['provider_category'] ?? ''));
        $statusFilter = trim((string)($_POST['status'] ?? 'all'));
        $provCatsJson = (string)($_POST['provider_categories_json'] ?? '[]');
        $provCats = json_decode($provCatsJson, true);
        if (!is_array($provCats)) {
            $provCats = [];
        }
        $res = CategoryMapper::getFilteredExternalIds($providerId, $search, $provCat, $statusFilter, $provCats);
        Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    if ($ajaxAction === 'preview_import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a provider.'], 404);
        }
        $ids = json_decode((string)($_POST['external_ids_json'] ?? '[]'), true) ?: [];
        $marginOverride = isset($_POST['margin_override']) && trim((string)$_POST['margin_override']) !== '' ? trim((string)$_POST['margin_override']) : null;
        $assignCatId = !empty($_POST['assign_category_id']) ? (int)$_POST['assign_category_id'] : null;
        $skipExisting = !empty($_POST['skip_existing']);

        $res = CategoryMapper::previewSelection($provider, $ids, $marginOverride, $assignCatId, $skipExisting);
        Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    if ($ajaxAction === 'run_batch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a provider.'], 404);
        }
        $op = (string)($_POST['bulk_op'] ?? '');
        $extIds = json_decode((string)($_POST['external_ids_json'] ?? '[]'), true);
        if (!is_array($extIds) || empty($extIds)) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'No service IDs in batch.'], 400);
        }

        $marginVal = trim((string)($_POST['margin_value'] ?? ''));
        $assignCatId = (int)($_POST['assign_category_id'] ?? 0);
        $fallbackCatId = (int)($_POST['fallback_category_id'] ?? 1);
        $targetStatus = ($_POST['target_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $priceMode = ($_POST['price_mode'] ?? 'fixed') === 'percent_adjust' ? 'percent_adjust' : 'fixed';
        $priceValue = trim((string)($_POST['price_value'] ?? ''));

        if ($op === 'bulk_import' || $op === 'bulk_import_categories') {
            $res = CategoryMapper::executeBulkImportBatch($provider, $extIds, [
                'duplicate_mode'       => 'update',
                'only_existing'        => false,
                'margin_override'      => $marginVal !== '' ? $marginVal : null,
                'assign_category_id'   => $assignCatId > 0 ? $assignCatId : 0,
                'fallback_category_id' => $fallbackCatId,
                'status'               => 'active',
            ], (int)$admin['id']);
            Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
        } elseif ($op === 'bulk_skip_existing') {
            $res = CategoryMapper::executeBulkImportBatch($provider, $extIds, [
                'duplicate_mode'       => 'skip',
                'only_existing'        => false,
                'margin_override'      => $marginVal !== '' ? $marginVal : null,
                'assign_category_id'   => $assignCatId > 0 ? $assignCatId : 0,
                'fallback_category_id' => $fallbackCatId,
                'status'               => 'active',
            ], (int)$admin['id']);
            Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
        } elseif ($op === 'bulk_update') {
            $res = CategoryMapper::executeBulkImportBatch($provider, $extIds, [
                'duplicate_mode'       => 'update',
                'only_existing'        => true,
                'margin_override'      => $marginVal !== '' ? $marginVal : null,
                'assign_category_id'   => $assignCatId > 0 ? $assignCatId : 0,
                'fallback_category_id' => $fallbackCatId,
                'status'               => 'active',
            ], (int)$admin['id']);
            Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
        } else {
            // Operations targeting already-imported local services linked to selected external IDs
            $localIds = CategoryMapper::resolveLocalServiceIdsForExternal($providerId, $extIds);
            if (empty($localIds)) {
                Helpers::jsonResponse([
                    'ok'    => true,
                    'stats' => [
                        'processed' => count($extIds),
                        'imported'  => 0,
                        'updated'   => 0,
                        'deleted'   => 0,
                        'skipped'   => count($extIds),
                        'failed'    => 0,
                        'categories_created' => 0,
                    ],
                ]);
            }

            $localOpMap = [
                'bulk_enable'          => ['op' => 'enable', 'params' => []],
                'bulk_disable'         => ['op' => 'disable', 'params' => []],
                'bulk_delete'          => ['op' => 'delete', 'params' => []],
                'bulk_assign_category' => ['op' => 'assign_category', 'params' => ['category_id' => $assignCatId]],
                'bulk_update_margin'   => ['op' => 'update_margin', 'params' => ['margin_percent' => $marginVal]],
                'bulk_update_price'    => ['op' => 'update_selling_price', 'params' => ['price_mode' => $priceMode, 'price_value' => $priceValue]],
                'bulk_update_status'   => ['op' => 'set_status', 'params' => ['status' => $targetStatus]],
            ];

            if (!isset($localOpMap[$op])) {
                Helpers::jsonResponse(['ok' => false, 'error' => 'Invalid bulk operation.'], 400);
            }
            $spec = $localOpMap[$op];
            $res = CategoryMapper::bulkOperateLocalServices($spec['op'], $localIds, $spec['params'], $providerId, (int)$admin['id']);
            if (!$res['ok']) {
                Helpers::jsonResponse($res, 400);
            }
            $unimportedSkipped = max(0, count($extIds) - count($localIds));
            Helpers::jsonResponse([
                'ok'    => true,
                'stats' => [
                    'processed'          => count($extIds),
                    'imported'           => 0,
                    'updated'            => (int)($res['updated'] ?? 0),
                    'deleted'            => (int)($res['deleted'] ?? 0),
                    'skipped'            => (int)($res['skipped'] ?? 0) + $unimportedSkipped,
                    'failed'             => 0,
                    'categories_created' => 0,
                ],
            ]);
        }
    }

    if ($ajaxAction === 'log_batch_finish') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        $op = Validator::str((string)($_POST['bulk_op'] ?? 'bulk_op'), 60);
        CategoryMapper::logImportOperation(
            $providerId ?: null,
            (int)$admin['id'],
            $op,
            (int)($_POST['total_selected'] ?? 0),
            (int)($_POST['imported'] ?? 0),
            (int)($_POST['updated'] ?? 0),
            (int)($_POST['skipped'] ?? 0),
            (int)($_POST['failed'] ?? 0),
            (int)($_POST['categories_created'] ?? 0),
            ['deleted' => (int)($_POST['deleted'] ?? 0)]
        );
        Helpers::jsonResponse(['ok' => true]);
    }

    if ($ajaxAction === 'recent_logs') {
        $logs = Database::fetchAll(
            "SELECT pil.*, p.name AS provider_name, a.username AS admin_username
             FROM provider_import_logs pil
             LEFT JOIN providers p ON p.id = pil.provider_id
             LEFT JOIN admins a ON a.id = pil.admin_id
             ORDER BY pil.id DESC LIMIT 25"
        );
        Helpers::jsonResponse(['ok' => true, 'logs' => $logs]);
    }

        Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        $handled = ProviderErrorHandler::handleException($e, [
            'scope'       => 'smm',
            'endpoint'    => '/admin/provider-import.php',
            'action'      => $ajaxAction,
            'provider_id' => (int)($_REQUEST['provider_id'] ?? 0),
            'admin_id'    => (int)($admin['id'] ?? 0),
        ]);
        Helpers::jsonResponse([
            'ok'             => false,
            'error'          => $handled['error'],
            'error_code'     => $handled['error_code'],
            'error_category' => $handled['error_category'],
        ], $handled['http_status']);
    }
}

$pageTitle = 'Provider Service Import & Bulk Operations';
$providers = Database::fetchAll("SELECT * FROM providers ORDER BY id DESC");
$categories = Database::fetchAll("SELECT id, name, platform FROM categories ORDER BY sort_order ASC, id ASC");
$selectedProviderId = (int)($_GET['provider_id'] ?? ($providers[0]['id'] ?? 0));
$baseCode = Currency::getBaseCode();
$baseSymbol = Currency::getSymbol($baseCode);

$importLogs = Database::fetchAll(
    "SELECT pil.*, p.name AS provider_name, a.username AS admin_username
     FROM provider_import_logs pil
     LEFT JOIN providers p ON p.id = pil.provider_id
     LEFT JOIN admins a ON a.id = pil.admin_id
     ORDER BY pil.id DESC LIMIT 25"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$workbenchConfig = [
    'providerId'           => (int)$selectedProviderId,
    'csrfToken'            => CSRF::token(),
    'baseCode'             => $baseCode,
    'baseSymbol'           => $baseSymbol,
    'defaultFallbackCatId' => (int)($categories[0]['id'] ?? 1),
    'initialLogs'          => $importLogs,
];
?>
<script>
window.__SMM_PROVIDER_IMPORT_CFG = <?= json_encode($workbenchConfig, $jsonFlags) ?>;
</script>

<div class="space-y-6" x-data="providerImportWorkbench(window.__SMM_PROVIDER_IMPORT_CFG)" x-init="init()">

    <!-- Top Provider & Fetch Bar -->
    <div class="smm-card rounded-3xl p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-extrabold text-slate-900">Provider Services Selection &amp; Bulk Operations</h1>
                    <span class="px-2.5 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[11px] font-bold">Fetch-First Safe Mode</span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    Fetching services previews the upstream catalog without importing anything into your database. Review, filter, select services or categories, preview conversions, and run bulk actions cleanly.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <select x-model.number="providerId" @change="onProviderChange()" class="px-3.5 py-2.5 rounded-xl border border-purple-200 bg-white text-xs sm:text-sm font-bold text-slate-800 min-w-[200px]">
                    <?php if (empty($providers)): ?>
                        <option value="0">No providers configured</option>
                    <?php else: foreach ($providers as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= Validator::e($p['name']) ?> (<?= Validator::e($p['currency_code'] ?: $baseCode) ?>)</option>
                    <?php endforeach; endif; ?>
                </select>
                <button type="button" @click="fetchCatalog()" :disabled="fetching || !providerId"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:opacity-95 disabled:opacity-50 text-white text-xs sm:text-sm font-bold shadow-md shadow-purple-500/20 transition">
                    <svg class="w-4 h-4" :class="fetching ? 'animate-spin' : ''" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                    <span x-text="fetching ? 'Fetching Services...' : 'Fetch Services'"></span>
                </button>
                <a :href="providerId ? `/admin/provider-categories.php?provider_id=${providerId}` : '/admin/provider-categories.php'" class="px-4 py-2.5 rounded-xl bg-violet-50 hover:bg-violet-100 text-violet-800 text-xs font-bold">Import Categories</a>
                <a href="/admin/providers.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold">Manage Providers</a>
            </div>
        </div>

        <!-- Catalog Metrics & Quick Status Filter Pills -->
        <div x-show="hasCatalog" x-cloak class="mt-5 pt-5 border-t border-purple-100 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" @click="setStatusFilter('all')" :class="statusFilter === 'all' ? 'bg-purple-600 text-white' : 'bg-[#F3F1FC] text-slate-700 hover:bg-purple-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    All (<span x-text="counts.all">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('new')" :class="statusFilter === 'new' || statusFilter === 'not_imported' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    New / Not Imported (<span x-text="counts.new">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('updated')" :class="statusFilter === 'updated' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    Updated Upstream (<span x-text="counts.updated">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('imported')" :class="statusFilter === 'imported' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    Already Imported (<span x-text="counts.imported">0</span>)
                </button>
            </div>
            <div class="text-xs text-slate-500">
                Last fetched: <strong class="text-slate-700" x-text="fetchedAt || 'Never'"></strong>
            </div>
        </div>
    </div>

    <!-- Inline Status / Error Feedback Banner -->
    <div x-show="inlineNotice" x-cloak
         :class="inlineNotice?.type === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : (inlineNotice?.type === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800' : (inlineNotice?.type === 'info' ? 'bg-indigo-50 border-indigo-200 text-indigo-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'))"
         class="rounded-2xl border px-4 py-3 text-xs font-bold flex items-center justify-between gap-3">
        <span x-text="inlineNotice?.message"></span>
        <button type="button" @click="inlineNotice = null" class="opacity-70 hover:opacity-100 text-base leading-none">&times;</button>
    </div>

    <!-- Search, Category Filter & Selection Controls -->
    <div x-show="hasCatalog" x-cloak class="smm-card rounded-3xl p-5 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
            <div class="md:col-span-4">
                <label class="block font-bold uppercase text-slate-400 mb-1">Search Name, ID, or Category</label>
                <input type="text" x-model="search" @input.debounce.300ms="page = 1; queryCatalog()" placeholder="Type service name or ID..."
                       class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm outline-none focus:border-purple-600">
            </div>
            <div class="md:col-span-4">
                <label class="block font-bold uppercase text-slate-400 mb-1">Filter by Provider Category</label>
                <select x-model="providerCategory" @change="page = 1; queryCatalog()" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm bg-white outline-none focus:border-purple-600">
                    <option value="">All Provider Categories</option>
                    <template x-for="cat in categories" :key="cat.name">
                        <option :value="cat.name" x-text="`${cat.name} (${cat.total} total, ${cat.new} new)`"></option>
                    </template>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block font-bold uppercase text-slate-400 mb-1">Import Status</label>
                <select x-model="statusFilter" @change="page = 1; queryCatalog()" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm bg-white outline-none focus:border-purple-600">
                    <option value="all">All Services</option>
                    <option value="new">New Only</option>
                    <option value="not_imported">Not Imported Only</option>
                    <option value="updated">Updated Upstream</option>
                    <option value="imported">Already Imported</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block font-bold uppercase text-slate-400 mb-1">Per Page</label>
                <select x-model.number="perPage" @change="page = 1; queryCatalog()" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm bg-white outline-none focus:border-purple-600">
                    <option value="25">25 rows</option>
                    <option value="50">50 rows</option>
                    <option value="100">100 rows</option>
                </select>
            </div>
        </div>

        <!-- Granular Selection Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-purple-50 text-xs">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="selectCurrentPage()" class="px-3 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-800 font-bold border border-purple-200">Select Current Page</button>
                <button type="button" @click="selectFiltered('all')" class="px-3 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-800 font-bold border border-purple-200">Select All Filtered</button>
                <button type="button" @click="selectFiltered('new')" class="px-3 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-bold border border-emerald-200">Select Only New</button>
                <button type="button" @click="selectFiltered('updated')" class="px-3 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 font-bold border border-amber-200">Select Only Updated</button>
                <button type="button" @click="openCategoryModal = true" class="px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-800 font-bold border border-indigo-200">Select / Import by Category</button>
                <button type="button" @click="deselectAll()" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold">Deselect All</button>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <div class="px-3.5 py-1.5 rounded-xl bg-slate-900 text-white font-extrabold tabular-nums">
                    Selected: <span x-text="selectedIds.length">0</span> services
                </div>
                <button type="button"
                        @click="importSelectedDirect()"
                        :disabled="runningBatch || selectedIds.length === 0"
                        class="px-4 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white font-extrabold shadow-sm transition">
                    <span x-text="runningBatch ? 'Importing...' : `Import Selected (${selectedIds.length})`"></span>
                </button>
            </div>
        </div>

        <!-- Bulk Action Toolbar (All 11 Bulk Operations) -->
        <div class="p-4 rounded-2xl bg-[#F6F4FC] border border-purple-200/80 space-y-3">
            <div class="flex flex-wrap items-end gap-3 text-xs">
                <div class="min-w-[230px] flex-1">
                    <label class="block font-bold uppercase text-purple-800 mb-1">Bulk Operation</label>
                    <select x-model="bulkOp" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-300 bg-white font-bold text-slate-900">
                        <option value="bulk_import">1. Bulk Import Selected Services</option>
                        <option value="bulk_update">2. Bulk Update Selected Services</option>
                        <option value="bulk_enable">3. Bulk Enable Selected Services</option>
                        <option value="bulk_disable">4. Bulk Disable Selected Services</option>
                        <option value="bulk_delete">5. Bulk Delete Selected Services</option>
                        <option value="bulk_assign_category">6. Bulk Assign Platform Category</option>
                        <option value="bulk_update_margin">7. Bulk Update Profit Margin (%)</option>
                        <option value="bulk_update_price">8. Bulk Update Selling Price</option>
                        <option value="bulk_update_status">9. Bulk Update Service Status</option>
                        <option value="bulk_skip_existing">10. Bulk Import (Skip Existing Services)</option>
                        <option value="bulk_import_categories">11. Bulk Import by Selected Provider Categories</option>
                    </select>
                </div>

                <!-- Dynamic contextual option fields -->
                <div x-show="['bulk_import','bulk_update','bulk_skip_existing','bulk_import_categories','bulk_update_margin'].includes(bulkOp)" class="w-40">
                    <label class="block font-bold uppercase text-slate-500 mb-1" x-text="bulkOp === 'bulk_update_margin' ? 'New Margin % *' : 'Margin % (Opt)'"></label>
                    <input type="number" step="0.01" min="0" x-model="bulkMarginValue" placeholder="e.g. 35"
                           class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white font-bold">
                </div>

                <div x-show="['bulk_import','bulk_update','bulk_skip_existing','bulk_import_categories','bulk_assign_category'].includes(bulkOp)" class="min-w-[210px]">
                    <label class="block font-bold uppercase text-slate-500 mb-1" x-text="bulkOp === 'bulk_assign_category' ? 'Target Platform Category *' : 'Override Category (Opt)'"></label>
                    <select x-model.number="bulkAssignCatId" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white">
                        <option value="0" x-text="bulkOp === 'bulk_assign_category' ? '-- Select Category --' : 'Auto-Match / Auto-Create'"></option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= Validator::e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div x-show="bulkOp === 'bulk_update_price'" class="w-40">
                    <label class="block font-bold uppercase text-slate-500 mb-1">Price Mode</label>
                    <select x-model="bulkPriceMode" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white">
                        <option value="fixed">Fixed Price (<?= Validator::e($baseCode) ?>)</option>
                        <option value="percent_adjust">% Adjustment (+/-)</option>
                    </select>
                </div>

                <div x-show="bulkOp === 'bulk_update_price'" class="w-36">
                    <label class="block font-bold uppercase text-slate-500 mb-1" x-text="bulkPriceMode === 'fixed' ? 'Price / 1k *' : 'Percent % *'"></label>
                    <input type="number" step="0.0001" x-model="bulkPriceValue" placeholder="e.g. 50.00"
                           class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white font-bold">
                </div>

                <div x-show="bulkOp === 'bulk_update_status'" class="w-36">
                    <label class="block font-bold uppercase text-slate-500 mb-1">Target Status</label>
                    <select x-model="bulkTargetStatus" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white font-bold">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            x-show="['bulk_import','bulk_update','bulk_skip_existing','bulk_import_categories'].includes(bulkOp)"
                            @click="openImportPreview()"
                            :disabled="runningBatch || (bulkOp !== 'bulk_import_categories' && selectedIds.length === 0) || (bulkOp === 'bulk_import_categories' && selectedCategories.length === 0)"
                            class="px-4 py-2.5 rounded-xl bg-white border border-purple-300 hover:bg-purple-50 disabled:opacity-50 text-purple-800 font-bold">
                        Preview Before Import
                    </button>
                    <button type="button"
                            @click="bulkOp === 'bulk_import_categories' ? openCategoryModal = true : executeBulkOperation()"
                            :disabled="runningBatch || (bulkOp !== 'bulk_import_categories' && selectedIds.length === 0)"
                            :class="bulkOp === 'bulk_delete' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-purple-600 hover:bg-purple-700'"
                            class="px-5 py-2.5 rounded-xl disabled:opacity-50 text-white font-bold shadow-sm transition">
                        <span x-text="runningBatch ? 'Processing Batch...' : (bulkOp === 'bulk_import_categories' ? 'Choose Categories' : 'Execute Bulk Action')"></span>
                    </button>
                </div>
            </div>

            <!-- Batch Progress Indicator -->
            <div x-show="runningBatch || lastBatchSummary" x-cloak class="pt-2 border-t border-purple-200/70">
                <div x-show="runningBatch" class="space-y-1.5">
                    <div class="flex justify-between text-xs font-bold text-purple-900">
                        <span>Processing batches (shared-hosting safe)...</span>
                        <span x-text="`${batchProgress.done} / ${batchProgress.total}`"></span>
                    </div>
                    <div class="w-full h-2.5 rounded-full bg-purple-100 overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-purple-600 to-indigo-600 transition-all"
                             :style="`width: ${batchProgress.total > 0 ? Math.round((batchProgress.done / batchProgress.total) * 100) : 0}%`"></div>
                    </div>
                </div>
                <div x-show="!runningBatch && lastBatchSummary" class="p-3 rounded-xl bg-white border border-emerald-200 text-xs text-slate-700 flex flex-wrap gap-4 items-center justify-between">
                    <div>
                        <strong class="text-emerald-700">Batch Complete:</strong>
                        Processed <strong x-text="lastBatchSummary?.processed"></strong> &bull;
                        Imported <strong class="text-emerald-700" x-text="lastBatchSummary?.imported"></strong> &bull;
                        Updated <strong class="text-indigo-700" x-text="lastBatchSummary?.updated"></strong> &bull;
                        Deleted <strong class="text-rose-700" x-text="lastBatchSummary?.deleted"></strong> &bull;
                        Skipped <strong class="text-amber-700" x-text="lastBatchSummary?.skipped"></strong> &bull;
                        Failed <strong class="text-rose-700" x-text="lastBatchSummary?.failed || 0"></strong> &bull;
                        Categories Created <strong class="text-purple-700" x-text="lastBatchSummary?.categories_created"></strong>
                    </div>
                    <button type="button" @click="lastBatchSummary = null" class="text-slate-400 hover:text-slate-700 font-bold">&times;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty Prompt When Not Yet Fetched -->
    <div x-show="!hasCatalog && !loadingCatalog" x-cloak class="smm-card rounded-3xl p-10 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center mb-3">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </div>
        <h2 class="text-base font-extrabold text-slate-900">Ready to Fetch Provider Services</h2>
        <p class="text-xs text-slate-500 max-w-md mx-auto mt-1 mb-4">
            Click <strong>Fetch Services</strong> above to retrieve the provider's live catalog into the preview table. Nothing will be imported into your database until you select services and confirm an action.
        </p>
    </div>

    <!-- Paginated Catalog Table -->
    <div x-show="hasCatalog" x-cloak class="smm-card rounded-3xl p-5">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 uppercase text-slate-400">
                        <th class="py-2.5 pr-2 w-8">
                            <input type="checkbox" :checked="allCurrentPageSelected()" @change="toggleCurrentPage($event.target.checked)" class="rounded text-purple-600">
                        </th>
                        <th class="py-2.5 pr-3">Prov ID</th>
                        <th class="py-2.5 pr-3">Service Name &amp; Description</th>
                        <th class="py-2.5 pr-3">Provider Category &rarr; Mapped</th>
                        <th class="py-2.5 pr-3">Provider Rate</th>
                        <th class="py-2.5 pr-3">Est. Sell (<?= Validator::e($baseCode) ?>)</th>
                        <th class="py-2.5 pr-3">Min / Max</th>
                        <th class="py-2.5 pr-3">Support</th>
                        <th class="py-2.5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="item in rows" :key="item.external_id">
                        <tr class="hover:bg-purple-50/40" :class="isSelected(item.external_id) ? 'bg-purple-50/60' : ''">
                            <td class="py-3 pr-2 align-top">
                                <input type="checkbox" :checked="isSelected(item.external_id)" @change="toggleOne(item.external_id)" class="rounded text-purple-600">
                            </td>
                            <td class="py-3 pr-3 font-extrabold text-slate-700 align-top whitespace-nowrap">
                                #<span x-text="item.external_id"></span>
                                <template x-if="item.local_service_id">
                                    <div class="text-[10px] font-semibold text-indigo-600">Local #<span x-text="item.local_service_id"></span></div>
                                </template>
                            </td>
                            <td class="py-3 pr-3 align-top max-w-[300px]">
                                <div class="font-bold text-slate-900 break-words" x-text="item.name"></div>
                                <template x-if="item.description">
                                    <div class="text-[11px] text-slate-500 line-clamp-2 mt-0.5" x-text="item.description"></div>
                                </template>
                                <template x-if="item.change_reasons && item.change_reasons.length">
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <template x-for="r in item.change_reasons" :key="r">
                                            <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-bold" x-text="r"></span>
                                        </template>
                                    </div>
                                </template>
                            </td>
                            <td class="py-3 pr-3 align-top max-w-[220px]">
                                <div class="text-[11px] font-semibold text-slate-700 break-words" x-text="item.raw_category"></div>
                                <div class="text-[10px] mt-0.5">
                                    <span class="text-slate-400">&rarr;</span>
                                    <span class="font-bold" :class="item.will_create_category ? 'text-emerald-700' : 'text-purple-700'" x-text="item.mapped_category_name"></span>
                                    <span x-show="item.will_create_category" class="ml-1 px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold">Auto-Create</span>
                                </div>
                            </td>
                            <td class="py-3 pr-3 align-top tabular-nums whitespace-nowrap font-bold text-slate-700">
                                <span x-text="`${item.provider_rate} ${item.provider_currency}`"></span>
                            </td>
                            <td class="py-3 pr-3 align-top tabular-nums whitespace-nowrap font-extrabold text-purple-700">
                                <span x-text="`${baseSymbol}${Number(item.preview_rate_base).toFixed(2)}`"></span>
                                <template x-if="item.local_selling_rate">
                                    <div class="text-[10px] text-slate-400 font-semibold">Live: <span x-text="`${baseSymbol}${Number(item.local_selling_rate).toFixed(2)}`"></span></div>
                                </template>
                            </td>
                            <td class="py-3 pr-3 align-top whitespace-nowrap text-slate-600 tabular-nums">
                                <span x-text="`${Number(item.min_qty).toLocaleString()} / ${Number(item.max_qty).toLocaleString()}`"></span>
                            </td>
                            <td class="py-3 pr-3 align-top">
                                <div class="flex flex-wrap gap-1">
                                    <span x-show="item.refill_support == 1" class="px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold">Refill</span>
                                    <span x-show="item.cancel_support == 1" class="px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 text-[10px] font-bold">Cancel</span>
                                    <span x-show="item.refill_support == 0 && item.cancel_support == 0" class="text-slate-400 text-[10px]">&mdash;</span>
                                </div>
                            </td>
                            <td class="py-3 align-top text-right whitespace-nowrap">
                                <template x-if="item.import_state === 'new'">
                                    <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-extrabold">New</span>
                                </template>
                                <template x-if="item.import_state === 'updated'">
                                    <span class="px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 text-[10px] font-extrabold">Updated</span>
                                </template>
                                <template x-if="item.import_state === 'imported'">
                                    <span class="px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-800 text-[10px] font-extrabold">Imported</span>
                                </template>
                                <template x-if="item.local_status">
                                    <div class="text-[10px] mt-1 font-bold" :class="item.local_status === 'active' ? 'text-emerald-600' : 'text-rose-600'" x-text="item.local_status"></div>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="9" class="py-8 text-center text-slate-400">No services matched your filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <div class="mt-4 pt-4 border-t border-purple-100 flex flex-wrap items-center justify-between gap-3 text-xs">
            <div class="text-slate-500">
                Showing page <strong x-text="page">1</strong> of <strong x-text="totalPages">1</strong> (<strong x-text="totalFiltered">0</strong> matching services)
            </div>
            <div class="flex items-center gap-1.5">
                <button type="button" @click="goPage(page - 1)" :disabled="page <= 1" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-bold">Prev</button>
                <button type="button" @click="goPage(page + 1)" :disabled="page >= totalPages" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-bold">Next</button>
            </div>
        </div>
    </div>

    <!-- Import Logs Section -->
    <div class="smm-card rounded-3xl p-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-extrabold text-slate-900">Provider Import &amp; Bulk Operation Logs</h2>
            <button type="button" @click="refreshLogs()" class="px-3 py-1.5 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100">Refresh Logs</button>
        </div>
        <div class="overflow-x-auto max-h-72">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 uppercase text-slate-400">
                        <th class="py-2 pr-3">When</th>
                        <th class="py-2 pr-3">Provider</th>
                        <th class="py-2 pr-3">Operation</th>
                        <th class="py-2 pr-3">Selected</th>
                        <th class="py-2 pr-3">Imported</th>
                        <th class="py-2 pr-3">Updated</th>
                        <th class="py-2 pr-3">Skipped</th>
                        <th class="py-2 pr-3">Cats Created</th>
                        <th class="py-2 text-right">Admin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="lg in importLogs" :key="lg.id">
                        <tr>
                            <td class="py-2.5 pr-3 text-slate-500 whitespace-nowrap" x-text="lg.created_at"></td>
                            <td class="py-2.5 pr-3 font-bold text-slate-800" x-text="lg.provider_name || '-'"></td>
                            <td class="py-2.5 pr-3"><span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-800 font-bold" x-text="lg.operation_type"></span></td>
                            <td class="py-2.5 pr-3 tabular-nums" x-text="lg.total_selected"></td>
                            <td class="py-2.5 pr-3 font-bold text-emerald-600 tabular-nums" x-text="lg.imported_count"></td>
                            <td class="py-2.5 pr-3 font-bold text-indigo-600 tabular-nums" x-text="lg.updated_count"></td>
                            <td class="py-2.5 pr-3 text-amber-600 tabular-nums" x-text="lg.skipped_count"></td>
                            <td class="py-2.5 pr-3 text-purple-700 tabular-nums" x-text="lg.categories_created"></td>
                            <td class="py-2.5 text-right text-slate-500" x-text="lg.admin_username || 'system'"></td>
                        </tr>
                    </template>
                    <tr x-show="importLogs.length === 0">
                        <td colspan="9" class="py-6 text-center text-slate-400">No import logs recorded yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal 1: Select / Bulk Import by Provider Categories -->
    <div x-show="openCategoryModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="openCategoryModal = false" class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-purple-100 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Select or Bulk Import Provider Categories</h3>
                    <p class="text-xs text-slate-500">Pick one or more provider categories to select their services or import them directly in batches.</p>
                </div>
                <button type="button" @click="openCategoryModal = false" class="text-slate-400 hover:text-slate-700 font-bold text-lg">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto divide-y divide-slate-100 border border-purple-100 rounded-2xl mb-4">
                <template x-for="cat in categories" :key="cat.name">
                    <label class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-purple-50/50 cursor-pointer text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <input type="checkbox" :value="cat.name" x-model="selectedCategories" class="rounded text-purple-600">
                            <span class="font-bold text-slate-800 truncate" x-text="cat.name"></span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-bold" x-text="`${cat.total} svcs`"></span>
                            <span x-show="cat.new > 0" class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold" x-text="`${cat.new} new`"></span>
                        </div>
                    </label>
                </template>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex gap-2">
                    <button type="button" @click="selectedCategories = categories.map(c => c.name)" class="px-3 py-1.5 rounded-xl bg-slate-100 font-bold">All Categories</button>
                    <button type="button" @click="selectedCategories = []" class="px-3 py-1.5 rounded-xl bg-slate-100 font-bold">Clear</button>
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="selectByCategories(false)" :disabled="selectedCategories.length === 0" class="px-4 py-2 rounded-xl bg-purple-100 text-purple-800 font-bold disabled:opacity-50">Select Services Only</button>
                    <button type="button" @click="selectByCategories(true)" :disabled="selectedCategories.length === 0" class="px-4 py-2 rounded-xl bg-purple-600 text-white font-bold disabled:opacity-50">Bulk Import Selected Categories &rarr;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal 2: Pre-Import Summary Preview -->
    <div x-show="previewData" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="previewData = null" class="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-purple-100 max-h-[85vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-extrabold text-slate-900">Import Preview Summary</h3>
                <button type="button" @click="previewData = null" class="text-slate-400 hover:text-slate-700 font-bold text-lg">&times;</button>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs mb-4">
                <div class="p-3 rounded-xl bg-slate-50"><div class="text-slate-500">Selected</div><div class="text-lg font-extrabold" x-text="previewData?.selected_total"></div></div>
                <div class="p-3 rounded-xl bg-emerald-50"><div class="text-emerald-700">New Imports</div><div class="text-lg font-extrabold text-emerald-700" x-text="previewData?.new_count"></div></div>
                <div class="p-3 rounded-xl bg-indigo-50"><div class="text-indigo-700">Existing</div><div class="text-lg font-extrabold text-indigo-700" x-text="previewData?.existing_count"></div></div>
                <div class="p-3 rounded-xl bg-purple-50"><div class="text-purple-700">New Categories</div><div class="text-lg font-extrabold text-purple-700" x-text="previewData?.new_categories?.length || 0"></div></div>
            </div>
            <template x-if="previewData?.new_categories?.length">
                <div class="mb-4 p-3 rounded-2xl bg-emerald-50/70 border border-emerald-200 text-xs">
                    <div class="font-bold text-emerald-800 mb-1">Missing Platform Categories to Auto-Create:</div>
                    <div class="text-emerald-700" x-text="previewData.new_categories.join(', ')"></div>
                </div>
            </template>
            <div class="text-xs font-bold text-slate-500 uppercase mb-2">Sample Pricing Check (Margin: <span x-text="previewData?.margin_percent"></span>%)</div>
            <div class="overflow-x-auto border border-purple-100 rounded-2xl mb-4">
                <table class="w-full text-left text-xs">
                    <thead><tr class="border-b bg-slate-50 text-slate-500"><th class="p-2.5">ID</th><th class="p-2.5">Service</th><th class="p-2.5">Category</th><th class="p-2.5">Prov Rate</th><th class="p-2.5 text-right">Sell Rate</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="sp in (previewData?.sample_items || [])" :key="sp.external_id">
                            <tr>
                                <td class="p-2.5 font-bold">#<span x-text="sp.external_id"></span></td>
                                <td class="p-2.5 max-w-[200px] truncate font-semibold" x-text="sp.name"></td>
                                <td class="p-2.5 text-purple-700" x-text="sp.category"></td>
                                <td class="p-2.5 tabular-nums" x-text="sp.provider_rate"></td>
                                <td class="p-2.5 text-right font-extrabold text-purple-800 tabular-nums" x-text="sp.selling_rate"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end gap-2 text-xs">
                <button type="button" @click="previewData = null" class="px-4 py-2 rounded-xl bg-slate-100 font-bold">Close</button>
                <button type="button" @click="previewData = null; executeBulkOperation(true)" class="px-5 py-2 rounded-xl bg-purple-600 text-white font-bold">Confirm &amp; Execute Import &rarr;</button>
            </div>
        </div>
    </div>
</div>

<script>
function providerImportWorkbench(cfg) {
  return {
    providerId: cfg.providerId || 0,
    csrfToken: cfg.csrfToken || '',
    baseCode: cfg.baseCode || 'INR',
    baseSymbol: cfg.baseSymbol || '₹',
    defaultFallbackCatId: cfg.defaultFallbackCatId || 1,
    importLogs: cfg.initialLogs || [],

    hasCatalog: false,
    loadingCatalog: false,
    fetching: false,
    fetchedAt: '',
    counts: { all: 0, new: 0, updated: 0, imported: 0, not_imported: 0 },
    categories: [],
    rows: [],
    totalFiltered: 0,
    page: 1,
    perPage: 25,
    totalPages: 1,

    search: '',
    providerCategory: '',
    statusFilter: 'all',

    selectedIds: [],
    selectedCategories: [],
    openCategoryModal: false,
    previewData: null,

    bulkOp: 'bulk_import',
    bulkMarginValue: '',
    bulkAssignCatId: 0,
    bulkPriceMode: 'fixed',
    bulkPriceValue: '',
    bulkTargetStatus: 'active',

    runningBatch: false,
    batchProgress: { done: 0, total: 0 },
    lastBatchSummary: null,
    inlineNotice: null,

    notify(message, type = 'info') {
      this.inlineNotice = { message, type };
      if (typeof window.showToast === 'function') {
        window.showToast(message, type);
      }
    },

    init() {
      if (this.providerId > 0) {
        this.queryCatalog();
      }
    },

    onProviderChange() {
      this.selectedIds = [];
      this.selectedCategories = [];
      this.page = 1;
      this.lastBatchSummary = null;
      this.inlineNotice = null;
      this.queryCatalog();
    },

    async safeFetchJson(url, options = {}) {
      const resp = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
      const text = await resp.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (e) {
        return {
          ok: false,
          status: resp.status,
          error: resp.status === 401 || resp.status === 403
            ? 'Your admin session expired. Please refresh and sign in again.'
            : `Unexpected server response (HTTP ${resp.status}). Please check server logs.`,
        };
      }
      if (data && data.csrf_token) {
        this.csrfToken = data.csrf_token;
      }
      if (!resp.ok && data && typeof data.ok === 'undefined') {
        data.ok = false;
      }
      return data || { ok: false, error: `Request failed (HTTP ${resp.status}).` };
    },

    async postAjax(action, paramsObj = {}) {
      const fd = new URLSearchParams({ ajax_action: action, provider_id: String(this.providerId), _csrf_token: this.csrfToken });
      for (const [k, v] of Object.entries(paramsObj)) {
        fd.append(k, String(v));
      }
      return this.safeFetchJson('/admin/provider-import.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: fd.toString(),
      });
    },

    async fetchCatalog() {
      if (!this.providerId || this.fetching) return;
      this.fetching = true;
      this.inlineNotice = null;
      try {
        const res = await this.postAjax('fetch_catalog');
        if (res.ok) {
          this.notify(`Fetched ${res.total_fetched} services into preview catalog (nothing imported yet).`, 'success');
          this.page = 1;
          await this.queryCatalog();
          await this.refreshLogs();
        } else {
          this.notify(res.error || 'Failed to fetch provider catalog.', 'error');
        }
      } catch (e) {
        this.notify('Network error while fetching provider catalog.', 'error');
      } finally {
        this.fetching = false;
      }
    },

    async queryCatalog() {
      if (!this.providerId) return;
      this.loadingCatalog = true;
      try {
        const qs = new URLSearchParams({
          ajax_action: 'query_catalog',
          provider_id: String(this.providerId),
          search: this.search,
          provider_category: this.providerCategory,
          status: this.statusFilter,
          page: String(this.page),
          per_page: String(this.perPage),
        });
        const res = await this.safeFetchJson(`/admin/provider-import.php?${qs.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) {
          this.hasCatalog = true;
          this.fetchedAt = res.fetched_at || '';
          this.counts = res.counts || this.counts;
          this.categories = res.categories || [];
          this.rows = res.items || [];
          this.totalFiltered = res.total_filtered || 0;
          this.page = res.page || 1;
          this.totalPages = res.total_pages || 1;
        } else {
          this.hasCatalog = false;
          this.rows = [];
        }
      } catch (e) {
        this.hasCatalog = false;
      } finally {
        this.loadingCatalog = false;
      }
    },

    setStatusFilter(st) {
      this.statusFilter = st;
      this.page = 1;
      this.queryCatalog();
    },

    goPage(p) {
      if (p < 1 || p > this.totalPages) return;
      this.page = p;
      this.queryCatalog();
    },

    isSelected(extId) {
      return this.selectedIds.includes(String(extId));
    },

    toggleOne(extId) {
      const id = String(extId);
      if (this.isSelected(id)) {
        this.selectedIds = this.selectedIds.filter(x => x !== id);
      } else {
        this.selectedIds.push(id);
      }
    },

    allCurrentPageSelected() {
      return this.rows.length > 0 && this.rows.every(r => this.isSelected(r.external_id));
    },

    toggleCurrentPage(checked) {
      const pageIds = this.rows.map(r => String(r.external_id));
      if (checked) {
        const set = new Set([...this.selectedIds, ...pageIds]);
        this.selectedIds = Array.from(set);
      } else {
        const pageSet = new Set(pageIds);
        this.selectedIds = this.selectedIds.filter(id => !pageSet.has(id));
      }
    },

    selectCurrentPage() {
      this.toggleCurrentPage(true);
    },

    deselectAll() {
      this.selectedIds = [];
    },

    async selectFiltered(mode) {
      const st = mode === 'new' ? 'new' : (mode === 'updated' ? 'updated' : this.statusFilter);
      const res = await this.postAjax('select_ids', {
        search: mode === 'all' ? this.search : '',
        provider_category: mode === 'all' ? this.providerCategory : '',
        status: st,
      });
      if (res.ok) {
        const set = new Set([...this.selectedIds, ...(res.ids || [])]);
        this.selectedIds = Array.from(set);
        window.showToast(`Selected ${res.count} matching service(s). Total selected: ${this.selectedIds.length}`, 'info');
      }
    },

    async selectByCategories(importImmediately = false) {
      if (this.selectedCategories.length === 0) return;
      const res = await this.postAjax('select_ids', {
        provider_categories_json: JSON.stringify(this.selectedCategories),
      });
      if (res.ok) {
        this.selectedIds = Array.from(new Set(res.ids || []));
        this.openCategoryModal = false;
        window.showToast(`Selected ${this.selectedIds.length} service(s) across ${this.selectedCategories.length} categories.`, 'info');
        if (importImmediately) {
          this.bulkOp = 'bulk_import_categories';
          await this.executeBulkOperation(true);
        }
      }
    },

    async openImportPreview() {
      let targetIds = [...this.selectedIds];
      if (this.bulkOp === 'bulk_import_categories' && this.selectedCategories.length > 0) {
        const r = await this.postAjax('select_ids', { provider_categories_json: JSON.stringify(this.selectedCategories) });
        if (r.ok) targetIds = r.ids || [];
      }
      if (targetIds.length === 0) {
        window.showToast('Please select at least one service first.', 'warning');
        return;
      }
      const res = await this.postAjax('preview_import', {
        external_ids_json: JSON.stringify(targetIds),
        margin_override: this.bulkMarginValue,
        assign_category_id: this.bulkAssignCatId,
        skip_existing: this.bulkOp === 'bulk_skip_existing' ? '1' : '',
      });
      if (res.ok) {
        this.previewData = res.preview;
      } else {
        window.showToast(res.error || 'Could not generate preview.', 'error');
      }
    },

    async importSelectedDirect() {
      if (this.runningBatch) return;
      if (this.selectedIds.length === 0) {
        this.notify('Please select at least one service to import.', 'warning');
        return;
      }
      if (!['bulk_import', 'bulk_skip_existing', 'bulk_update'].includes(this.bulkOp)) {
        this.bulkOp = 'bulk_import';
      }
      await this.executeBulkOperation(true);
    },

    async executeBulkOperation(skipConfirm = false) {
      if (this.runningBatch) return;
      if (this.selectedIds.length === 0) {
        this.notify('Please select at least one service first.', 'warning');
        return;
      }
      if (this.bulkOp === 'bulk_assign_category' && !this.bulkAssignCatId) {
        this.notify('Please select a target platform category.', 'error');
        return;
      }
      if (this.bulkOp === 'bulk_update_margin' && this.bulkMarginValue === '') {
        this.notify('Please enter the new profit margin percentage.', 'error');
        return;
      }
      if (this.bulkOp === 'bulk_update_price' && this.bulkPriceValue === '') {
        this.notify('Please enter the selling price or percentage adjustment.', 'error');
        return;
      }
      if (this.bulkOp === 'bulk_delete' && !skipConfirm) {
        if (!confirm(`Confirm deleting ${this.selectedIds.length} selected local service(s)? (Services with existing orders will be safely disabled instead.)`)) {
          return;
        }
      }

      this.runningBatch = true;
      this.lastBatchSummary = null;
      this.inlineNotice = null;
      const chunkSize = 50;
      const allIds = [...this.selectedIds];
      this.batchProgress = { done: 0, total: allIds.length };

      const totals = { processed: 0, imported: 0, updated: 0, deleted: 0, skipped: 0, failed: 0, categories_created: 0 };
      let batchFailedMsg = null;

      try {
        for (let i = 0; i < allIds.length; i += chunkSize) {
          const chunk = allIds.slice(i, i + chunkSize);
          const res = await this.postAjax('run_batch', {
            bulk_op: this.bulkOp,
            external_ids_json: JSON.stringify(chunk),
            margin_value: this.bulkMarginValue,
            assign_category_id: this.bulkAssignCatId,
            fallback_category_id: this.defaultFallbackCatId,
            target_status: this.bulkTargetStatus,
            price_mode: this.bulkPriceMode,
            price_value: this.bulkPriceValue,
          });
          if (!res.ok) {
            batchFailedMsg = res.error || 'Batch operation encountered an error.';
            if (res.stats) {
              totals.processed += Number(res.stats.processed || 0);
              totals.failed += Number(res.stats.failed || 0);
            }
            break;
          }
          const st = res.stats || {};
          totals.processed += Number(st.processed || 0);
          totals.imported += Number(st.imported || 0);
          totals.updated += Number(st.updated || 0);
          totals.deleted += Number(st.deleted || 0);
          totals.skipped += Number(st.skipped || 0);
          totals.failed += Number(st.failed || 0);
          totals.categories_created += Number(st.categories_created || 0);
          this.batchProgress.done = Math.min(allIds.length, i + chunk.length);
        }

        await this.postAjax('log_batch_finish', {
          bulk_op: this.bulkOp,
          total_selected: allIds.length,
          imported: totals.imported,
          updated: totals.updated,
          skipped: totals.skipped,
          failed: totals.failed,
          categories_created: totals.categories_created,
          deleted: totals.deleted,
        });

        this.lastBatchSummary = totals;
        if (batchFailedMsg) {
          this.notify(batchFailedMsg, 'error');
        } else if (totals.failed > 0 && totals.imported === 0 && totals.updated === 0) {
          this.notify(`Import failed for ${totals.failed} service(s). Check database or workflow logs.`, 'error');
        } else {
          this.notify(`Operation complete: Imported ${totals.imported}, Updated ${totals.updated}, Skipped ${totals.skipped}, Failed ${totals.failed}.`, 'success');
        }
        await this.queryCatalog();
        await this.refreshLogs();
      } catch (e) {
        this.notify('Unexpected network error during batch processing.', 'error');
      } finally {
        this.runningBatch = false;
      }
    },

    async refreshLogs() {
      const res = await this.safeFetchJson('/admin/provider-import.php?ajax_action=recent_logs', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (res.ok && res.logs) {
        this.importLogs = res.logs;
      }
    },
  };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
