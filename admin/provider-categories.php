<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('providers.manage');
CategoryMapper::ensureSchema();

$ajaxAction = (string)($_REQUEST['ajax_action'] ?? '');

if ($ajaxAction !== '') {
    ProviderErrorHandler::startAjaxGuard([
        'scope'    => 'smm',
        'endpoint' => '/admin/provider-categories.php',
        'action'   => $ajaxAction,
        'admin_id' => (int)($admin['id'] ?? 0),
    ]);
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            CSRF::requireValid();
        }

        $providerId = (int)($_REQUEST['provider_id'] ?? 0);
        $provider = $providerId > 0 ? Database::fetch("SELECT * FROM providers WHERE id = ? LIMIT 1", [$providerId]) : null;

        if ($ajaxAction === 'fetch_categories') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
            }
            if (!$provider) {
                Helpers::jsonResponse(['ok' => false, 'error' => 'Please select a valid provider.'], 404);
            }
            $res = CategoryMapper::fetchAndCacheCategories($provider, (int)$admin['id']);
            Helpers::jsonResponse($res, 200);
        }

    if ($ajaxAction === 'query_categories') {
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a provider.'], 404);
        }
        $search = trim((string)($_GET['search'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? 'all'));
        $res = CategoryMapper::getEnrichedCategories($providerId, $search, $statusFilter);
        Helpers::jsonResponse($res, 200);
    }

    if ($ajaxAction === 'import_selected_categories') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        if (!$provider) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Select a valid provider.'], 404);
        }
        $selections = json_decode((string)($_POST['selections_json'] ?? '[]'), true);
        if (!is_array($selections) || empty($selections)) {
            Helpers::jsonResponse(['ok' => false, 'error' => 'Please select at least one category to import.'], 400);
        }
        $existingMode = ($_POST['existing_mode'] ?? 'skip') === 'update' ? 'update' : 'skip';
        $res = CategoryMapper::importSelectedCategories($provider, $selections, $existingMode, (int)$admin['id']);
        $platformCategories = Database::fetchAll("SELECT id, name, platform FROM categories ORDER BY sort_order ASC, id ASC");
        $res['platform_categories'] = $platformCategories;
        Helpers::jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    if ($ajaxAction === 'delete_mapping') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::jsonResponse(['ok' => false, 'error' => 'POST required.'], 405);
        }
        $mapId = (int)($_POST['mapping_id'] ?? 0);
        if ($mapId > 0) {
            Database::execute("DELETE FROM provider_category_mappings WHERE id = ?", [$mapId]);
        }
        Helpers::jsonResponse(['ok' => true]);
    }

    if ($ajaxAction === 'recent_data') {
        $savedMappings = Database::fetchAll(
            "SELECT pcm.*, p.name AS provider_name, c.name AS platform_category_name
             FROM provider_category_mappings pcm
             JOIN providers p ON p.id = pcm.provider_id
             JOIN categories c ON c.id = pcm.platform_category_id
             ORDER BY pcm.id DESC LIMIT 80"
        );
        $logs = Database::fetchAll(
            "SELECT pil.*, p.name AS provider_name, a.username AS admin_username
             FROM provider_import_logs pil
             LEFT JOIN providers p ON p.id = pil.provider_id
             LEFT JOIN admins a ON a.id = pil.admin_id
             WHERE pil.operation_type IN ('fetch_categories', 'import_categories')
             ORDER BY pil.id DESC LIMIT 25"
        );
        Helpers::jsonResponse(['ok' => true, 'mappings' => $savedMappings, 'logs' => $logs]);
    }

        Helpers::jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        $handled = ProviderErrorHandler::handleException($e, [
            'scope'       => 'smm',
            'endpoint'    => '/admin/provider-categories.php',
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

$pageTitle = 'Provider Service Category Import';
$providers = Database::fetchAll("SELECT * FROM providers ORDER BY id DESC");
$platformCategories = Database::fetchAll("SELECT id, name, platform FROM categories ORDER BY sort_order ASC, id ASC");
$selectedProviderId = (int)($_GET['provider_id'] ?? ($providers[0]['id'] ?? 0));

$savedMappings = Database::fetchAll(
    "SELECT pcm.*, p.name AS provider_name, c.name AS platform_category_name
     FROM provider_category_mappings pcm
     JOIN providers p ON p.id = pcm.provider_id
     JOIN categories c ON c.id = pcm.platform_category_id
     ORDER BY pcm.id DESC LIMIT 80"
);

$categoryLogs = Database::fetchAll(
    "SELECT pil.*, p.name AS provider_name, a.username AS admin_username
     FROM provider_import_logs pil
     LEFT JOIN providers p ON p.id = pil.provider_id
     LEFT JOIN admins a ON a.id = pil.admin_id
     WHERE pil.operation_type IN ('fetch_categories', 'import_categories')
     ORDER BY pil.id DESC LIMIT 25"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$catWorkbenchCfg = [
    'providerId'         => (int)$selectedProviderId,
    'csrfToken'          => CSRF::token(),
    'platformCategories' => $platformCategories,
    'initialMappings'    => $savedMappings,
    'initialLogs'        => $categoryLogs,
];
?>
<script>
window.__SMM_PROVIDER_CAT_CFG = <?= json_encode($catWorkbenchCfg, $jsonFlags) ?>;
</script>

<div class="space-y-6" x-data="providerCategoryWorkbench(window.__SMM_PROVIDER_CAT_CFG)" x-init="init()">

    <!-- Header & Provider Fetch Bar -->
    <div class="smm-card rounded-3xl p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-extrabold text-slate-900">Provider Service Category Import</h1>
                    <span class="px-2.5 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[11px] font-bold">Categories Only &bull; No Auto-Import</span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    Fetch upstream categories, inspect service counts &amp; provider IDs, map them to existing platform categories or create missing ones cleanly, and import only selected categories without importing services.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <select x-model.number="providerId" @change="onProviderChange()" class="px-3.5 py-2.5 rounded-xl border border-purple-200 bg-white text-xs sm:text-sm font-bold text-slate-800 min-w-[200px]">
                    <?php if (empty($providers)): ?>
                        <option value="0">No providers configured</option>
                    <?php else: foreach ($providers as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= Validator::e($p['name']) ?> (<?= Validator::e($p['currency_code'] ?: Currency::getBaseCode()) ?>)</option>
                    <?php endforeach; endif; ?>
                </select>
                <button type="button" @click="fetchCategories()" :disabled="fetching || !providerId"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-violet-600 hover:opacity-95 disabled:opacity-50 text-white text-xs sm:text-sm font-bold shadow-md shadow-purple-500/20 transition">
                    <svg class="w-4 h-4" :class="fetching ? 'animate-spin' : ''" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                    <span x-text="fetching ? 'Fetching Categories...' : 'Fetch Categories'"></span>
                </button>
                <a :href="providerId ? `/admin/provider-import.php?provider_id=${providerId}` : '/admin/provider-import.php'"
                   class="px-4 py-2.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-800 text-xs font-bold">
                    Service Import &rarr;
                </a>
            </div>
        </div>

        <!-- Status Pills & Meta -->
        <div x-show="hasCatalog" x-cloak class="mt-5 pt-5 border-t border-purple-100 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" @click="setStatusFilter('all')" :class="statusFilter === 'all' ? 'bg-purple-600 text-white' : 'bg-[#F3F1FC] text-slate-700 hover:bg-purple-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    All (<span x-text="counts.all">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('unmapped')" :class="statusFilter === 'unmapped' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    Unmapped (<span x-text="(counts.new || 0) + (counts.auto_match || 0)">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('new')" :class="statusFilter === 'new' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    Missing Locally (<span x-text="counts.new">0</span>)
                </button>
                <button type="button" @click="setStatusFilter('mapped')" :class="statusFilter === 'mapped' ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'" class="px-3 py-1.5 rounded-xl font-bold transition">
                    Mapped (<span x-text="counts.mapped">0</span>)
                </button>
            </div>
            <div class="text-xs text-slate-500">
                <span x-show="uncategorizedCount > 0" class="mr-3 text-amber-700 font-semibold" x-text="`${uncategorizedCount} uncategorized upstream service(s)`"></span>
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

    <!-- Search, Selection & Bulk Mapping Controls -->
    <div x-show="hasCatalog" x-cloak class="smm-card rounded-3xl p-5 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs items-end">
            <div class="md:col-span-5">
                <label class="block font-bold uppercase text-slate-400 mb-1">Search Provider Category Name or ID</label>
                <input type="text" x-model="search" @input.debounce.250ms="queryCategories()" placeholder="Search category name, provider ID, or mapped category..."
                       class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm outline-none focus:border-purple-600">
            </div>
            <div class="md:col-span-3">
                <label class="block font-bold uppercase text-slate-400 mb-1">Mapping Filter</label>
                <select x-model="statusFilter" @change="queryCategories()" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 text-sm bg-white outline-none focus:border-purple-600">
                    <option value="all">All Fetched Categories</option>
                    <option value="unmapped">Unmapped Only</option>
                    <option value="new">New (Missing Platform Category)</option>
                    <option value="mapped">Already Mapped</option>
                </select>
            </div>
            <div class="md:col-span-4 flex flex-wrap items-center justify-end gap-2">
                <button type="button" @click="selectAllFiltered()" class="px-3 py-2 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-800 font-bold border border-purple-200">Select All</button>
                <button type="button" @click="selectUnmapped()" class="px-3 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-bold border border-emerald-200">Select Unmapped</button>
                <button type="button" @click="deselectAll()" class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold">Deselect All</button>
                <span class="px-3.5 py-2 rounded-xl bg-slate-900 text-white font-extrabold tabular-nums">
                    <span x-text="selectedKeys.length">0</span> selected
                </span>
            </div>
        </div>

        <!-- Bulk Action & Confirmation Bar -->
        <div class="p-4 rounded-2xl bg-[#F6F4FC] border border-purple-200/80 flex flex-wrap items-end justify-between gap-3 text-xs">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[230px]">
                    <label class="block font-bold uppercase text-slate-500 mb-1">Bulk Target for Selected Rows</label>
                    <div class="flex gap-2">
                        <select x-model.number="bulkAssignTargetId" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white font-semibold">
                            <option value="0">Auto-Create Missing / Match by Name</option>
                            <template x-for="pc in platformCategories" :key="pc.id">
                                <option :value="pc.id" x-text="`Map to: ${pc.name}`"></option>
                            </template>
                        </select>
                        <button type="button" @click="applyBulkTargetToSelected()" :disabled="selectedKeys.length === 0"
                                class="px-3.5 py-2.5 rounded-xl bg-white border border-purple-300 hover:bg-purple-50 disabled:opacity-50 text-purple-800 font-bold shrink-0">
                            Apply Target
                        </button>
                    </div>
                </div>

                <div class="min-w-[210px]">
                    <label class="block font-bold uppercase text-slate-500 mb-1">Existing Mapping Handling</label>
                    <select x-model="existingMode" class="w-full px-3 py-2.5 rounded-xl border border-purple-200 bg-white font-bold">
                        <option value="skip">Skip Existing Mappings</option>
                        <option value="update">Update Existing Mappings</option>
                    </select>
                </div>
            </div>

            <button type="button" @click="openConfirmModal()" :disabled="importing || selectedKeys.length === 0"
                    class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-bold shadow-sm transition">
                <span x-text="importing ? 'Importing Categories...' : `Import Selected Categories (${selectedKeys.length})`"></span>
            </button>
        </div>

        <!-- Summary Result Banner -->
        <div x-show="lastImportStats" x-cloak class="p-3.5 rounded-xl bg-white border border-emerald-200 text-xs text-slate-700 flex flex-wrap items-center justify-between gap-3">
            <div>
                <strong class="text-emerald-700">Category Import Complete:</strong>
                Selected <strong x-text="lastImportStats?.selected_total"></strong> &bull;
                Imported <strong class="text-emerald-700" x-text="lastImportStats?.imported"></strong> &bull;
                Updated <strong class="text-indigo-700" x-text="lastImportStats?.updated"></strong> &bull;
                Skipped <strong class="text-amber-700" x-text="lastImportStats?.skipped"></strong> &bull;
                Failed <strong class="text-rose-700" x-text="lastImportStats?.failed"></strong> &bull;
                Platform Categories Created <strong class="text-purple-700" x-text="lastImportStats?.categories_created"></strong>
            </div>
            <button type="button" @click="lastImportStats = null" class="text-slate-400 hover:text-slate-700 font-bold">&times;</button>
        </div>
    </div>

    <!-- Empty Prompt When Not Yet Fetched -->
    <div x-show="!hasCatalog && !loadingCatalog" x-cloak class="smm-card rounded-3xl p-10 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center mb-3">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/><circle cx="19" cy="17" r="2"/></svg>
        </div>
        <h2 class="text-base font-extrabold text-slate-900">Ready to Fetch Provider Categories</h2>
        <p class="text-xs text-slate-500 max-w-md mx-auto mt-1">
            Select a provider above and click <strong>Fetch Categories</strong> to load its original categories, category IDs, and service counts. Nothing is imported until you select categories and confirm.
        </p>
    </div>

    <!-- Fetched Categories Table -->
    <div x-show="hasCatalog" x-cloak class="smm-card rounded-3xl p-5">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-purple-100 uppercase text-slate-400">
                        <th class="py-2.5 pr-2 w-8">
                            <input type="checkbox" :checked="allVisibleSelected()" @change="toggleAllVisible($event.target.checked)" class="rounded text-purple-600">
                        </th>
                        <th class="py-2.5 pr-3">Provider Category ID</th>
                        <th class="py-2.5 pr-3">Provider Category Name</th>
                        <th class="py-2.5 pr-3">Associated Services</th>
                        <th class="py-2.5 pr-3">Target Platform Category Mapping</th>
                        <th class="py-2.5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in rows" :key="row.external_key">
                        <tr class="hover:bg-purple-50/40" :class="isSelected(row.external_key) ? 'bg-purple-50/60' : ''">
                            <td class="py-3 pr-2 align-middle">
                                <input type="checkbox" :checked="isSelected(row.external_key)" @change="toggleOne(row.external_key)" class="rounded text-purple-600">
                            </td>
                            <td class="py-3 pr-3 font-mono font-bold text-slate-600 whitespace-nowrap" x-text="row.external_category_id ? `#${row.external_category_id}` : '—'"></td>
                            <td class="py-3 pr-3 font-bold text-slate-900" x-text="row.external_category_name"></td>
                            <td class="py-3 pr-3 tabular-nums">
                                <span class="px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700 font-bold" x-text="`${row.service_count} service(s)`"></span>
                            </td>
                            <td class="py-3 pr-3 min-w-[230px]">
                                <select :value="getTargetForRow(row)" @change="setTargetForRow(row.external_key, Number($event.target.value))"
                                        class="w-full px-3 py-1.5 rounded-xl border border-purple-200 bg-white text-xs font-semibold text-slate-800">
                                    <option value="0">+ Create / Auto-Match Platform Category</option>
                                    <template x-for="pc in platformCategories" :key="pc.id">
                                        <option :value="pc.id" x-text="pc.name"></option>
                                    </template>
                                </select>
                            </td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <template x-if="row.mapping_status === 'mapped'">
                                    <span class="px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-800 text-[10px] font-extrabold" x-text="`Mapped: ${row.mapped_category_name}`"></span>
                                </template>
                                <template x-if="row.mapping_status === 'auto_match'">
                                    <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-extrabold" x-text="`Matches: ${row.mapped_category_name}`"></span>
                                </template>
                                <template x-if="row.mapping_status === 'new'">
                                    <span class="px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 text-[10px] font-extrabold">New Category</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="6" class="py-8 text-center text-slate-400">No provider categories matched your filter.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Saved Category Mappings & Category Import Logs -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="smm-card rounded-3xl p-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Saved Provider Category Mappings</h2>
                <span class="text-xs text-slate-400 font-bold" x-text="`${savedMappings.length} saved`"></span>
            </div>
            <div class="overflow-x-auto max-h-72">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2 pr-2">Provider</th>
                            <th class="py-2 pr-2">Upstream Category</th>
                            <th class="py-2 pr-2">Platform Category</th>
                            <th class="py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="mp in savedMappings" :key="mp.id">
                            <tr>
                                <td class="py-2.5 pr-2 font-bold text-slate-700" x-text="mp.provider_name"></td>
                                <td class="py-2.5 pr-2">
                                    <div class="font-semibold text-slate-900" x-text="mp.external_category_name"></div>
                                    <div class="text-[10px] text-slate-400 font-mono" x-text="mp.external_category_id ? `ID: #${mp.external_category_id}` : 'Name-keyed'"></div>
                                </td>
                                <td class="py-2.5 pr-2">
                                    <span class="font-bold text-purple-700" x-text="mp.platform_category_name"></span>
                                    <span class="ml-1 px-1.5 py-0.5 rounded bg-slate-100 text-[10px] text-slate-600 font-semibold" x-text="mp.mapping_mode"></span>
                                </td>
                                <td class="py-2.5 text-right">
                                    <button type="button" @click="deleteMapping(mp.id)" class="px-2.5 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold">Remove</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="savedMappings.length === 0">
                            <td colspan="4" class="py-6 text-center text-slate-400">No category mappings saved yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="smm-card rounded-3xl p-5 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900">Category Import Logs</h2>
                <button type="button" @click="refreshSavedAndLogs()" class="px-3 py-1.5 rounded-xl bg-purple-50 text-purple-700 text-xs font-bold hover:bg-purple-100">Refresh</button>
            </div>
            <div class="overflow-x-auto max-h-72">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-purple-100 uppercase text-slate-400">
                            <th class="py-2 pr-2">When</th>
                            <th class="py-2 pr-2">Operation</th>
                            <th class="py-2 pr-2">Imp / Upd / Skip / Fail</th>
                            <th class="py-2 text-right">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="lg in categoryLogs" :key="lg.id">
                            <tr>
                                <td class="py-2.5 pr-2 text-slate-500 whitespace-nowrap" x-text="lg.created_at"></td>
                                <td class="py-2.5 pr-2">
                                    <span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-800 font-bold" x-text="lg.operation_type"></span>
                                    <div class="text-[10px] text-slate-500" x-text="lg.provider_name || '-'"></div>
                                </td>
                                <td class="py-2.5 pr-2 tabular-nums">
                                    <span class="text-emerald-700 font-bold" x-text="lg.imported_count"></span> /
                                    <span class="text-indigo-700 font-bold" x-text="lg.updated_count"></span> /
                                    <span class="text-amber-700 font-bold" x-text="lg.skipped_count"></span> /
                                    <span class="text-rose-700 font-bold" x-text="lg.failed_count"></span>
                                </td>
                                <td class="py-2.5 text-right font-bold text-purple-700 tabular-nums" x-text="lg.categories_created"></td>
                            </tr>
                        </template>
                        <tr x-show="categoryLogs.length === 0">
                            <td colspan="4" class="py-6 text-center text-slate-400">No category import logs recorded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div x-show="confirmModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
        <div @click.away="confirmModalOpen = false" class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-purple-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-extrabold text-slate-900">Confirm Category Import</h3>
                <button type="button" @click="confirmModalOpen = false" class="text-slate-400 hover:text-slate-700 font-bold text-lg">&times;</button>
            </div>
            <p class="text-xs text-slate-600 mb-4">
                Only the selected provider categories will be mapped or created in your platform categories table. <strong>No services will be imported.</strong>
            </p>
            <div class="grid grid-cols-3 gap-3 text-center text-xs mb-5">
                <div class="p-3 rounded-2xl bg-slate-50">
                    <div class="text-slate-500">Selected</div>
                    <div class="text-lg font-extrabold text-slate-900" x-text="confirmSummary.selected"></div>
                </div>
                <div class="p-3 rounded-2xl bg-emerald-50">
                    <div class="text-emerald-700">Create / Match</div>
                    <div class="text-lg font-extrabold text-emerald-700" x-text="confirmSummary.createOrMatch"></div>
                </div>
                <div class="p-3 rounded-2xl bg-indigo-50">
                    <div class="text-indigo-700">Map Existing</div>
                    <div class="text-lg font-extrabold text-indigo-700" x-text="confirmSummary.mapExisting"></div>
                </div>
            </div>
            <div class="p-3 rounded-xl bg-purple-50 text-xs text-purple-900 mb-5">
                Existing mapping mode: <strong x-text="existingMode === 'update' ? 'Update existing category mappings' : 'Skip already-mapped categories'"></strong>
            </div>
            <div class="flex justify-end gap-2 text-xs">
                <button type="button" @click="confirmModalOpen = false" class="px-4 py-2 rounded-xl bg-slate-100 font-bold">Cancel</button>
                <button type="button" @click="executeCategoryImport()" :disabled="importing" class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold">
                    <span x-text="importing ? 'Importing...' : 'Confirm & Import Categories'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function providerCategoryWorkbench(cfg) {
  return {
    providerId: cfg.providerId || 0,
    csrfToken: cfg.csrfToken || '',
    platformCategories: cfg.platformCategories || [],
    savedMappings: cfg.initialMappings || [],
    categoryLogs: cfg.initialLogs || [],

    hasCatalog: false,
    loadingCatalog: false,
    fetching: false,
    importing: false,
    fetchedAt: '',
    uncategorizedCount: 0,
    counts: { all: 0, mapped: 0, auto_match: 0, new: 0 },
    rows: [],
    allRowsByKey: {},

    search: '',
    statusFilter: 'all',
    selectedKeys: [],
    targetOverrides: {},
    bulkAssignTargetId: 0,
    existingMode: 'skip',
    confirmModalOpen: false,
    confirmSummary: { selected: 0, createOrMatch: 0, mapExisting: 0 },
    lastImportStats: null,
    inlineNotice: null,

    notify(message, type = 'info') {
      this.inlineNotice = { message, type };
      if (typeof window.showToast === 'function') {
        window.showToast(message, type);
      }
    },

    init() {
      if (this.providerId > 0) {
        this.queryCategories();
      }
    },

    onProviderChange() {
      this.selectedKeys = [];
      this.targetOverrides = {};
      this.lastImportStats = null;
      this.inlineNotice = null;
      this.queryCategories();
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
      return this.safeFetchJson('/admin/provider-categories.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: fd.toString(),
      });
    },

    async fetchCategories() {
      if (!this.providerId || this.fetching) return;
      this.fetching = true;
      this.inlineNotice = null;
      try {
        const res = await this.postAjax('fetch_categories');
        if (res.ok) {
          this.notify(`Fetched ${res.total_categories} categories from ${res.total_services_seen} services (nothing imported yet).`, 'success');
          await this.queryCategories();
          await this.refreshSavedAndLogs();
        } else {
          this.notify(res.error || 'Failed to fetch categories.', 'error');
        }
      } catch (e) {
        this.notify('Network error while fetching provider categories.', 'error');
      } finally {
        this.fetching = false;
      }
    },

    async queryCategories() {
      if (!this.providerId) return;
      this.loadingCatalog = true;
      try {
        const qs = new URLSearchParams({
          ajax_action: 'query_categories',
          provider_id: String(this.providerId),
          search: this.search,
          status: this.statusFilter,
        });
        const res = await this.safeFetchJson(`/admin/provider-categories.php?${qs.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) {
          this.hasCatalog = true;
          this.fetchedAt = res.fetched_at || '';
          this.uncategorizedCount = Number(res.uncategorized_count || 0);
          this.counts = res.counts || this.counts;
          this.rows = res.items || [];
          for (const r of this.rows) {
            this.allRowsByKey[r.external_key] = r;
          }
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
      this.queryCategories();
    },

    isSelected(key) {
      return this.selectedKeys.includes(String(key));
    },

    toggleOne(key) {
      const k = String(key);
      if (this.isSelected(k)) {
        this.selectedKeys = this.selectedKeys.filter(x => x !== k);
      } else {
        this.selectedKeys.push(k);
      }
    },

    allVisibleSelected() {
      return this.rows.length > 0 && this.rows.every(r => this.isSelected(r.external_key));
    },

    toggleAllVisible(checked) {
      const keys = this.rows.map(r => String(r.external_key));
      if (checked) {
        this.selectedKeys = Array.from(new Set([...this.selectedKeys, ...keys]));
      } else {
        const set = new Set(keys);
        this.selectedKeys = this.selectedKeys.filter(k => !set.has(k));
      }
    },

    selectAllFiltered() {
      this.toggleAllVisible(true);
    },

    selectUnmapped() {
      const keys = this.rows.filter(r => r.mapping_status !== 'mapped').map(r => String(r.external_key));
      this.selectedKeys = Array.from(new Set([...this.selectedKeys, ...keys]));
    },

    deselectAll() {
      this.selectedKeys = [];
    },

    getTargetForRow(row) {
      if (Object.prototype.hasOwnProperty.call(this.targetOverrides, row.external_key)) {
        return Number(this.targetOverrides[row.external_key]);
      }
      return Number(row.suggested_target_id || 0);
    },

    setTargetForRow(key, targetId) {
      this.targetOverrides = { ...this.targetOverrides, [key]: Number(targetId || 0) };
    },

    applyBulkTargetToSelected() {
      if (this.selectedKeys.length === 0) return;
      const next = { ...this.targetOverrides };
      for (const k of this.selectedKeys) {
        next[k] = Number(this.bulkAssignTargetId || 0);
      }
      this.targetOverrides = next;
      window.showToast(`Applied target mapping to ${this.selectedKeys.length} selected category rows.`, 'info');
    },

    openConfirmModal() {
      if (this.selectedKeys.length === 0) {
        window.showToast('Please select at least one category first.', 'warning');
        return;
      }
      let createOrMatch = 0;
      let mapExisting = 0;
      for (const k of this.selectedKeys) {
        const row = this.allRowsByKey[k] || this.rows.find(r => r.external_key === k);
        const tid = row ? this.getTargetForRow(row) : Number(this.targetOverrides[k] || 0);
        if (tid > 0) mapExisting++;
        else createOrMatch++;
      }
      this.confirmSummary = {
        selected: this.selectedKeys.length,
        createOrMatch,
        mapExisting,
      };
      this.confirmModalOpen = true;
    },

    async executeCategoryImport() {
      if (this.importing || this.selectedKeys.length === 0) return;
      this.importing = true;
      try {
        const selections = this.selectedKeys.map(k => {
          const row = this.allRowsByKey[k] || this.rows.find(r => r.external_key === k);
          const targetId = row ? this.getTargetForRow(row) : Number(this.targetOverrides[k] || 0);
          return { external_key: k, target_category_id: targetId };
        });

        const res = await this.postAjax('import_selected_categories', {
          selections_json: JSON.stringify(selections),
          existing_mode: this.existingMode,
        });

        this.confirmModalOpen = false;
        if (res.ok) {
          this.lastImportStats = res.stats;
          if (Array.isArray(res.platform_categories)) {
            this.platformCategories = res.platform_categories;
          }
          this.notify(`Imported ${res.stats.imported}, updated ${res.stats.updated}, skipped ${res.stats.skipped} categories.`, 'success');
          await this.queryCategories();
          await this.refreshSavedAndLogs();
        } else {
          this.notify(res.error || 'Category import failed.', 'error');
        }
      } catch (e) {
        this.notify('Unexpected error during category import.', 'error');
      } finally {
        this.importing = false;
      }
    },

    async deleteMapping(mappingId) {
      const res = await this.postAjax('delete_mapping', { mapping_id: mappingId });
      if (res.ok) {
        this.notify('Category mapping removed.', 'info');
        await this.queryCategories();
        await this.refreshSavedAndLogs();
      } else {
        this.notify(res.error || 'Failed to delete mapping.', 'error');
      }
    },

    async refreshSavedAndLogs() {
      const res = await this.safeFetchJson('/admin/provider-categories.php?ajax_action=recent_data', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (res.ok) {
        this.savedMappings = res.mappings || [];
        this.categoryLogs = res.logs || [];
      }
    },
  };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
