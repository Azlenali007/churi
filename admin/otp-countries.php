<?php
/**
 * Admin OTP Countries Manager
 * - Add, edit, enable/disable, safe-delete countries
 * - Fixed & Batch-Powered Country Importer from configured provider (5SIM getPrices / custom provider)
 *   with flag/dial code enrichment, duplicate-free updates, optional service linking, and detailed Import Report
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';
$admin = admin_guard('otp.manage');
$pageTitle = 'OTP Countries';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_country') {
        $id = (int)($_POST['id'] ?? 0);
        $name = Validator::str($_POST['name'] ?? '', 120);
        $iso = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string)($_POST['iso_code'] ?? ''))));
        $dial = Validator::str($_POST['dial_code'] ?? '', 15);
        $flag = Validator::str($_POST['flag_emoji'] ?? '', 16);
        $provCode = strtolower(Validator::str($_POST['default_provider_code'] ?? '', 80));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $status = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';

        if ($name === '' || $iso === '' || $provCode === '') {
            Helpers::setFlash('error', 'Name, ISO code, and default provider country code are required.');
            Helpers::redirect('/admin/otp-countries.php');
        }

        try {
            if ($id > 0) {
                Database::execute(
                    "UPDATE otp_countries SET name = ?, iso_code = ?, dial_code = ?, flag_emoji = ?, default_provider_code = ?, sort_order = ?, status = ? WHERE id = ?",
                    [$name, $iso, $dial, $flag, $provCode, $sortOrder, $status, $id]
                );
                Helpers::setFlash('success', 'Country updated.');
            } else {
                Database::insert(
                    "INSERT INTO otp_countries (name, iso_code, dial_code, flag_emoji, default_provider_code, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$name, $iso, $dial, $flag, $provCode, $sortOrder, $status]
                );
                Helpers::setFlash('success', 'Country added.');
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Could not save country (ISO code must be unique): ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $c = Database::fetch("SELECT id, status FROM otp_countries WHERE id = ?", [$id]);
        if ($c) {
            $next = $c['status'] === 'active' ? 'inactive' : 'active';
            Database::execute("UPDATE otp_countries SET status = ? WHERE id = ?", [$next, $id]);
            Helpers::setFlash('success', 'Country status set to ' . $next . '.');
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'delete_country') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)Database::fetchColumn("SELECT COUNT(*) FROM otp_activations WHERE country_id = ?", [$id]);
        if ($used > 0) {
            Helpers::setFlash('error', "Cannot delete country with {$used} activation record(s). Disable it instead.");
        } else {
            Database::execute("DELETE FROM otp_countries WHERE id = ?", [$id]);
            Helpers::setFlash('success', 'Country deleted.');
        }
        Helpers::redirect('/admin/otp-countries.php');
    } elseif ($action === 'sync_from_provider') {
        // Non-JS POST fallback powered by the exact same fixed OtpProviderImporter batch engine
        $pid = (int)($_POST['provider_id'] ?? 0);
        $linkServices = !empty($_POST['link_services']);
        $prov = Database::fetch("SELECT * FROM otp_providers WHERE id = ? LIMIT 1", [$pid]);
        if (!$prov) {
            Helpers::setFlash('error', 'Please select a provider to import countries from.');
            Helpers::redirect('/admin/otp-countries.php');
        }
        try {
            $start = OtpProviderImporter::startBatchJob($pid, (int)$admin['id'], 'countries', 'import_countries', [], [
                'link_services' => $linkServices,
                'batch_size'    => 150,
            ]);
            if (empty($start['ok'])) {
                Helpers::setFlash('error', $start['error'] ?? 'Failed to start country import.');
                Helpers::redirect('/admin/otp-countries.php');
            }
            $jobId = (int)$start['job_id'];
            $last = $start;
            $guard = 0;
            while (empty($last['done']) && $guard < 200) {
                $last = OtpProviderImporter::processNextBatch($jobId, 150);
                if (empty($last['ok'])) {
                    Helpers::setFlash('error', $last['error'] ?? 'Country import failed.');
                    Helpers::redirect('/admin/otp-countries.php');
                }
                $guard++;
            }
            $job = $last['job'] ?? OtpProviderImporter::getJobStatus($jobId);
            if (!empty($job['zero_reason'])) {
                Helpers::setFlash('error', $job['zero_reason']);
            } else {
                Helpers::setFlash(
                    'success',
                    "Country import complete from {$prov['name']}: {$job['total_countries_found']} found, {$job['new_countries']} new imported, {$job['updated_countries']} updated"
                    . ($linkServices ? ", {$job['new_services']} service link(s) added." : '.')
                );
            }
        } catch (Throwable $e) {
            Helpers::setFlash('error', 'Country import failed: ' . $e->getMessage());
        }
        Helpers::redirect('/admin/otp-countries.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editCountry = $editId > 0 ? Database::fetch("SELECT * FROM otp_countries WHERE id = ?", [$editId]) : null;
$providers = Database::fetchAll("SELECT id, name, adapter_type, status FROM otp_providers ORDER BY FIELD(status,'active','inactive'), name ASC");
$countries = Database::fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM otp_activations a WHERE a.country_id = c.id) AS act_count,
            (SELECT COUNT(*) FROM otp_service_provider_mappings m WHERE m.country_id = c.id) AS linked_services
     FROM otp_countries c ORDER BY c.sort_order ASC, c.name ASC"
);

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="grid lg:grid-cols-12 gap-6" x-data="otpCountryImporter()">
    <div class="lg:col-span-4 space-y-6">
        <div class="smm-card rounded-3xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-extrabold text-slate-900"><?= $editCountry ? 'Edit Country' : 'Add OTP Country' ?></h2>
                <?php if ($editCountry): ?><a href="/admin/otp-countries.php" class="text-xs font-bold text-purple-600">+ New</a><?php endif; ?>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_country">
                <input type="hidden" name="id" value="<?= (int)($editCountry['id'] ?? 0) ?>">

                <div>
                    <label class="block font-bold text-slate-600 mb-1">Country Name *</label>
                    <input type="text" name="name" required value="<?= Validator::e($editCountry['name'] ?? '') ?>" placeholder="India" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">ISO Code *</label>
                        <input type="text" name="iso_code" required value="<?= Validator::e($editCountry['iso_code'] ?? '') ?>" placeholder="IN" class="w-full px-3 py-2 rounded-xl border border-purple-200 uppercase font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Dial Code</label>
                        <input type="text" name="dial_code" value="<?= Validator::e($editCountry['dial_code'] ?? '') ?>" placeholder="+91" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Flag Emoji</label>
                        <input type="text" name="flag_emoji" value="<?= Validator::e($editCountry['flag_emoji'] ?? '') ?>" placeholder="🇮🇳" class="w-full px-3 py-2 rounded-xl border border-purple-200">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Provider Country Code *</label>
                        <input type="text" name="default_provider_code" required value="<?= Validator::e($editCountry['default_provider_code'] ?? '') ?>" placeholder="22" class="w-full px-3 py-2 rounded-xl border border-purple-200 font-mono">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-600 mb-1">Sort / Status</label>
                        <div class="flex gap-1.5">
                            <input type="number" name="sort_order" value="<?= (int)($editCountry['sort_order'] ?? 0) ?>" class="w-16 px-2 py-2 rounded-xl border border-purple-200">
                            <select name="status" class="flex-1 px-2 py-2 rounded-xl border border-purple-200 font-semibold">
                                <option value="active" <?= ($editCountry['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editCountry['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-extrabold shadow">
                    <?= $editCountry ? 'Update Country' : 'Add Country' ?>
                </button>
            </form>
        </div>

        <?php if (!empty($providers)): ?>
        <div class="smm-card rounded-3xl p-6 space-y-3.5">
            <div>
                <h3 class="text-sm font-extrabold text-slate-900">Import Countries From Provider</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Discovers supported countries from your selected OTP provider API (Custom REST/JSON, Custom PHP, or optional 5SIM), saves names/flags/dial codes, updates existing records without duplicates, and processes large lists in batches.
                </p>
            </div>

            <form method="POST" @submit.prevent="startCountryImport()" class="space-y-3 text-xs">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="sync_from_provider">
                <div>
                    <label class="block font-bold text-slate-600 mb-1">Select Provider</label>
                    <select name="provider_id" x-model.number="providerId" required class="w-full px-3 py-2.5 rounded-xl border border-purple-200 font-semibold bg-white">
                        <?php foreach ($providers as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= Validator::e($p['name']) ?> (<?= Validator::e($p['adapter_type']) ?><?= $p['status'] !== 'active' ? ' · Inactive' : '' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="flex items-start gap-2 font-semibold text-slate-700 cursor-pointer">
                    <input type="checkbox" name="link_services" value="1" x-model="linkServices" class="mt-0.5 accent-purple-600">
                    <span>Also link imported countries to existing imported services for this provider</span>
                </label>
                <button type="submit" :disabled="running || !providerId"
                        class="w-full py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white font-extrabold transition">
                    <span x-text="running ? (stageLabel || 'Fetching Countries...') : 'Fetch & Import Countries'"></span>
                </button>
            </form>

            <!-- Live Progress Bar -->
            <template x-if="job && !['completed','cancelled'].includes(job.status)">
                <div class="p-3.5 rounded-2xl border space-y-2 text-xs"
                     :class="job.status === 'failed' ? 'bg-rose-50 border-rose-200' : 'bg-purple-50 border-purple-200'">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-extrabold" :class="job.status === 'failed' ? 'text-rose-800' : 'text-purple-900'"
                              x-text="job.stage_message || job.status"></span>
                        <span class="font-mono font-bold text-slate-700"
                              x-text="(job.processed_records || 0) + '/' + (job.total_records || 0) + ' (' + (job.progress_percent || 0) + '%)'"></span>
                    </div>
                    <div class="w-full h-2 bg-white rounded-full overflow-hidden">
                        <div class="h-full transition-all duration-300"
                             :class="job.status === 'failed' ? 'bg-rose-500' : 'bg-purple-600'"
                             :style="'width:' + Math.max(5, job.progress_percent || 0) + '%'"></div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" x-show="job.status === 'failed'" @click="startCountryImport()" class="px-2.5 py-1 rounded-lg bg-purple-600 text-white font-bold">Retry</button>
                        <button type="button" x-show="job.status !== 'failed'" @click="cancelJob()" class="px-2.5 py-1 rounded-lg bg-rose-100 text-rose-700 font-bold">Cancel</button>
                    </div>
                </div>
            </template>

            <!-- Error Banner -->
            <template x-if="errorMessage">
                <div class="p-3 rounded-2xl bg-rose-50 border border-rose-200 text-xs font-bold text-rose-800" x-text="errorMessage"></div>
            </template>

            <!-- Final Country Import Report -->
            <template x-if="report">
                <div class="p-4 rounded-2xl border space-y-3 text-xs"
                     :class="report.zero_reason ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200'">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold" :class="report.zero_reason ? 'text-amber-900' : 'text-emerald-900'">Country Import Report</span>
                        <button type="button" @click="window.location.reload()" class="px-2.5 py-1 rounded-lg bg-white border border-emerald-200 text-emerald-800 font-bold hover:bg-emerald-100">Refresh List</button>
                    </div>
                    <template x-if="report.zero_reason">
                        <div class="p-2.5 rounded-xl bg-white border border-amber-300 text-amber-900 font-bold" x-text="report.zero_reason"></div>
                    </template>
                    <div class="grid grid-cols-2 gap-2 text-center">
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Countries Found</div>
                            <div class="text-base font-extrabold text-slate-900" x-text="report.total_countries_found || 0"></div>
                        </div>
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">New Imported</div>
                            <div class="text-base font-extrabold text-emerald-700" x-text="report.new_countries || 0"></div>
                        </div>
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Existing Updated</div>
                            <div class="text-base font-extrabold text-indigo-700" x-text="report.updated_countries || 0"></div>
                        </div>
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Services Linked</div>
                            <div class="text-base font-extrabold text-purple-700" x-text="(report.new_services || 0) + (report.updated_services || 0)"></div>
                        </div>
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Unsupported / Skipped</div>
                            <div class="text-base font-extrabold text-slate-600" x-text="(report.unsupported_records || 0) + (report.skipped_records || 0)"></div>
                        </div>
                        <div class="p-2 rounded-xl bg-white border border-slate-100">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Failed</div>
                            <div class="text-base font-extrabold" :class="(report.failed_records || 0) > 0 ? 'text-rose-600' : 'text-emerald-700'" x-text="report.failed_records || 0"></div>
                        </div>
                    </div>
                    <template x-if="report.errors && report.errors.length > 0">
                        <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 space-y-1 max-h-28 overflow-y-auto">
                            <template x-for="(e, i) in report.errors" :key="i">
                                <div class="font-mono text-[10px]" x-text="e"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-8 smm-card rounded-3xl overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50 flex items-center justify-between gap-2">
            <h2 class="text-sm font-extrabold text-slate-900">Configured Countries (<?= count($countries) ?>)</h2>
            <a href="/admin/otp-providers.php?tab=import" class="text-xs font-extrabold text-purple-600 hover:underline">Open Service Importer &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-4 py-3">Country</th>
                        <th class="px-4 py-3">ISO / Dial</th>
                        <th class="px-4 py-3">Provider Code</th>
                        <th class="px-4 py-3">Linked Services</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php if (empty($countries)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No countries configured yet. Use "Fetch &amp; Import Countries" to import from your provider.</td></tr>
                    <?php else: foreach ($countries as $c): ?>
                    <tr class="hover:bg-purple-50/30">
                        <td class="px-4 py-3 font-extrabold text-slate-900">
                            <?= Validator::e(($c['flag_emoji'] ? $c['flag_emoji'] . ' ' : '') . $c['name']) ?>
                            <span class="text-[10px] font-normal text-slate-400 ml-1">(<?= (int)$c['act_count'] ?> orders)</span>
                        </td>
                        <td class="px-4 py-3 font-mono"><?= Validator::e($c['iso_code']) ?> <?= Validator::e($c['dial_code']) ?></td>
                        <td class="px-4 py-3 font-mono text-purple-700 font-bold"><?= Validator::e($c['default_provider_code']) ?></td>
                        <td class="px-4 py-3 font-mono text-slate-600"><?= (int)$c['linked_services'] ?></td>
                        <td class="px-4 py-3"><?= Helpers::statusBadge((string)$c['status']) ?></td>
                        <td class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                            <a href="/admin/otp-countries.php?edit=<?= (int)$c['id'] ?>" class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">Edit</a>
                            <form method="POST" class="inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 font-bold"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this country?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="delete_country">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-700 font-bold">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function otpCountryImporter() {
    return {
        csrfToken: <?= json_encode(CSRF::token()) ?>,
        providerId: <?= (int)($providers[0]['id'] ?? 0) ?>,
        linkServices: true,
        running: false,
        stageLabel: '',
        job: null,
        report: null,
        errorMessage: '',
        abortRequested: false,

        async postApi(payload, timeoutMs = 70000) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), timeoutMs);
            try {
                const res = await fetch('/api/admin-otp-provider.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                    signal: controller.signal,
                });
                const text = await res.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return { ok: false, error: `Server returned non-JSON response (HTTP ${res.status}).` };
                }
            } catch (e) {
                return { ok: false, error: e?.name === 'AbortError' ? 'Provider request timed out. Please retry.' : ('Network error: ' + (e?.message || 'Failed')) };
            } finally {
                clearTimeout(timer);
            }
        },

        async startCountryImport() {
            if (!this.providerId || this.running) return;
            this.running = true;
            this.errorMessage = '';
            this.report = null;
            this.abortRequested = false;
            this.stageLabel = 'Fetching Countries...';
            this.job = { id: '...', status: 'fetching_countries', stage_message: 'Fetching Countries', processed_records: 0, total_records: 0, progress_percent: 5 };

            try {
                const start = await this.postApi({
                    action: 'start_import_job',
                    provider_id: this.providerId,
                    job_type: 'countries',
                    mode: 'import_countries',
                    link_services: this.linkServices ? 1 : 0,
                    batch_size: 100,
                });

                if (!start.ok) {
                    this.job = start.job || start.active_job || null;
                    this.errorMessage = start.error || 'Could not start country import.';
                    return;
                }

                this.job = start.job;
                if (start.done) {
                    this.report = start.job;
                    return;
                }

                let done = false;
                let guard = 0;
                while (!done && !this.abortRequested && guard < 300) {
                    guard++;
                    this.stageLabel = this.job?.stage_message || 'Importing Records...';
                    const step = await this.postApi({
                        action: 'process_import_batch',
                        provider_id: this.providerId,
                        job_id: start.job_id,
                        batch_size: 100,
                    }, 60000);
                    if (!step.ok) {
                        this.job = step.job || this.job;
                        this.errorMessage = step.error || 'Country import batch failed.';
                        return;
                    }
                    this.job = step.job;
                    done = !!step.done || ['completed', 'failed', 'cancelled'].includes(step.job?.status);
                }
                if (this.job && this.job.status === 'completed') {
                    this.report = this.job;
                }
            } finally {
                this.running = false;
                this.stageLabel = '';
            }
        },

        async cancelJob() {
            if (!this.job || !this.job.id || this.job.id === '...') return;
            this.abortRequested = true;
            await this.postApi({
                action: 'cancel_import_job',
                provider_id: this.providerId,
                job_id: this.job.id,
            });
            this.job = null;
            this.running = false;
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
