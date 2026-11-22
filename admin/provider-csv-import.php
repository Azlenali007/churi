<?php
/**
 * Admin CSV File Importer — Services (SMM & OTP) & Countries
 * Supports UTF-8/BOM CSV upload, auto header detection, configurable column mapping,
 * live preview with duplicate & missing-field validation, duplicate handling modes,
 * AJAX batch progress bar, row-level error report, and downloadable sample CSVs.
 */
require_once __DIR__ . '/includes/admin_bootstrap.php';

$admin = Auth::requireAdmin();
$canAccess = Auth::adminHasPermission($admin, 'otp.providers')
    || Auth::adminHasPermission($admin, 'providers.manage')
    || Auth::adminHasPermission($admin, 'otp.manage')
    || Auth::adminHasPermission($admin, 'services.manage');
if (!$canAccess) {
    http_response_code(403);
    die('Access denied.');
}

$pageTitle = 'CSV File Importer (Services & Countries)';
$initialTab = (($_GET['tab'] ?? '') === 'countries') ? 'countries' : 'services';
$initialServiceType = (($_GET['type'] ?? '') === 'otp') ? 'otp_services' : 'smm_services';

$smmProviders = Database::fetchAll("SELECT id, name, status FROM providers ORDER BY name ASC");
$otpProviders = Database::fetchAll("SELECT id, name, adapter_type, status FROM otp_providers ORDER BY name ASC");

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div x-data="csvImporterApp()" x-init="init()" class="space-y-6">
    <!-- Top Header & Mode Switcher -->
    <div class="smm-card rounded-3xl p-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-purple-600/20 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                    <polyline points="7 10 12 5 17 10"/>
                    <line x1="12" y1="5" x2="12" y2="16"/>
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">CSV File Importer</h2>
                <p class="text-xs text-slate-500">Bulk import Services (SMM or OTP) and Countries from UTF-8 CSV files with column mapping, live preview, duplicate control, and batch progress.</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <a href="/admin/otp-providers.php" class="px-3.5 py-2 rounded-xl bg-purple-50 text-purple-800 hover:bg-purple-100 font-bold transition">&larr; OTP Providers</a>
            <a href="/admin/providers.php" class="px-3.5 py-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 font-bold transition">&larr; SMM Providers</a>
        </div>
    </div>

    <!-- Primary Import Options: 1. Import Services via CSV | 2. Import Countries via CSV -->
    <div class="grid sm:grid-cols-2 gap-4">
        <button type="button" @click="switchSection('services')"
                :class="section === 'services' ? 'border-purple-500 bg-purple-50/70 ring-2 ring-purple-200' : 'border-purple-100 bg-white hover:border-purple-300'"
                class="text-left p-5 rounded-3xl border transition flex items-start gap-3.5">
            <span :class="section === 'services' ? 'bg-purple-600 text-white' : 'bg-purple-100 text-purple-700'"
                  class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900">1. Import Services via CSV</div>
                <p class="text-xs text-slate-500 mt-0.5">Upload SMM Services or OTP Services with pricing, categories, min/max quantities, and optional provider mapping.</p>
            </div>
        </button>

        <button type="button" @click="switchSection('countries')"
                :class="section === 'countries' ? 'border-purple-500 bg-purple-50/70 ring-2 ring-purple-200' : 'border-purple-100 bg-white hover:border-purple-300'"
                class="text-left p-5 rounded-3xl border transition flex items-start gap-3.5">
            <span :class="section === 'countries' ? 'bg-purple-600 text-white' : 'bg-purple-100 text-purple-700'"
                  class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-slate-900">2. Import Countries via CSV</div>
                <p class="text-xs text-slate-500 mt-0.5">Upload supported OTP countries with ISO codes, dial codes, flags, provider country codes, and active status.</p>
            </div>
        </button>
    </div>

    <!-- Status Banner -->
    <template x-if="notice.message">
        <div :class="notice.type === 'error' ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'"
             class="rounded-2xl border px-4 py-3 text-xs font-bold flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full shrink-0" :class="notice.type === 'error' ? 'bg-rose-500' : 'bg-emerald-500'"></span>
                <span x-text="notice.message"></span>
            </div>
            <button type="button" @click="notice.message = ''" class="text-slate-400 hover:text-slate-700 font-extrabold text-sm">&times;</button>
        </div>
    </template>

    <!-- STEP 1: Target Configuration, Sample CSV & File Upload -->
    <div class="smm-card rounded-3xl p-6 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
            <div>
                <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Step 1 of 3</div>
                <h3 class="text-base font-extrabold text-slate-900" x-text="section === 'services' ? 'Upload Services CSV File' : 'Upload Countries CSV File'"></h3>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a :href="'/api/admin-csv-import.php?action=download_sample&target=' + encodeURIComponent(currentTarget)"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-800 text-xs font-extrabold transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span x-text="currentTarget === 'smm_services' ? 'Download Sample SMM Services CSV' : (currentTarget === 'otp_services' ? 'Download Sample OTP Services CSV' : 'Download Sample Countries CSV')"></span>
                </a>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-4 text-xs">
            <div x-show="section === 'services'">
                <label class="block font-bold uppercase text-slate-500 mb-1.5">Service Store Target</label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="setServiceTarget('smm_services')"
                            :class="serviceTarget === 'smm_services' ? 'bg-purple-600 text-white border-purple-600 shadow-sm' : 'bg-[#FAF8FF] text-slate-700 border-purple-100 hover:bg-purple-50'"
                            class="px-3.5 py-2.5 rounded-xl border font-extrabold transition">SMM Services</button>
                    <button type="button" @click="setServiceTarget('otp_services')"
                            :class="serviceTarget === 'otp_services' ? 'bg-purple-600 text-white border-purple-600 shadow-sm' : 'bg-[#FAF8FF] text-slate-700 border-purple-100 hover:bg-purple-50'"
                            class="px-3.5 py-2.5 rounded-xl border font-extrabold transition">OTP Services</button>
                </div>
            </div>

            <div x-show="section === 'services'">
                <label class="block font-bold uppercase text-slate-500 mb-1.5">Default Provider Assignment (Optional)</label>
                <select x-model="providerId" class="w-full px-3.5 py-2.5 rounded-xl border border-purple-200 bg-white font-semibold">
                    <option value="0">— None / Match Provider Name column from CSV —</option>
                    <template x-if="serviceTarget === 'smm_services'">
                        <?php foreach ($smmProviders as $sp): ?>
                        <option value="<?= (int)$sp['id'] ?>"><?= Validator::e($sp['name']) ?> (<?= Validator::e($sp['status']) ?>)</option>
                        <?php endforeach; ?>
                    </template>
                    <template x-if="serviceTarget === 'otp_services'">
                        <?php foreach ($otpProviders as $op): ?>
                        <option value="<?= (int)$op['id'] ?>"><?= Validator::e($op['name']) ?> [<?= Validator::e($op['adapter_type']) ?>]</option>
                        <?php endforeach; ?>
                    </template>
                </select>
            </div>
        </div>

        <!-- Upload Dropzone -->
        <div class="rounded-2xl border-2 border-dashed border-purple-200 bg-[#FAF8FF] p-6 text-center space-y-3"
             @dragover.prevent="dragOver = true"
             @dragleave.prevent="dragOver = false"
             @drop.prevent="handleDrop($event)"
             :class="dragOver ? 'border-purple-500 bg-purple-50' : ''">
            <div class="w-12 h-12 mx-auto rounded-2xl bg-white border border-purple-100 text-purple-600 flex items-center justify-center shadow-xs">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 12 15 15"/></svg>
            </div>
            <div>
                <div class="text-sm font-extrabold text-slate-900">Select or Drop a UTF-8 CSV File</div>
                <p class="text-xs text-slate-500 mt-0.5">Supports quoted fields, commas inside values, UTF-8 with/without BOM. Max size: 10 MB (up to 25,000 rows).</p>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <label class="cursor-pointer inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-sm transition">
                    <span x-text="uploading ? 'Validating & Parsing CSV...' : 'Choose .CSV File'"></span>
                    <input type="file" accept=".csv,text/csv" class="hidden" :disabled="uploading || importing" @change="handleFileSelect($event)" x-ref="fileInput">
                </label>
                <template x-if="staged">
                    <button type="button" @click="resetJob()" :disabled="importing"
                            class="px-4 py-2.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition">Clear File</button>
                </template>
            </div>
            <template x-if="staged">
                <div class="inline-flex flex-wrap items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-purple-200 text-xs font-bold text-slate-700">
                    <span class="text-emerald-600">&#10003;</span>
                    <span x-text="staged.filename"></span>
                    <span class="text-slate-400">&middot;</span>
                    <span x-text="staged.total_rows + ' data rows'"></span>
                    <span class="text-slate-400">&middot;</span>
                    <span x-text="staged.headers.length + ' columns (delimiter: ' + staged.delimiter + ')'"></span>
                </div>
            </template>
        </div>
    </div>

    <!-- STEP 2: Column Mapping & Duplicate Strategy -->
    <template x-if="staged">
        <div class="smm-card rounded-3xl p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
                <div>
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Step 2 of 3</div>
                    <h3 class="text-base font-extrabold text-slate-900">Map CSV Columns &amp; Duplicate Handling</h3>
                    <p class="text-xs text-slate-500">Headers were auto-detected. Adjust any column mapping below before previewing.</p>
                </div>
                <div class="flex items-center gap-2">
                    <select x-model="dupMode" class="px-3.5 py-2 rounded-xl border border-purple-200 bg-white text-xs font-bold">
                        <option value="skip">Duplicates: Skip Existing</option>
                        <option value="update">Duplicates: Update Existing</option>
                        <option value="report">Duplicates: Report Only (Do Not Overwrite)</option>
                    </select>
                    <button type="button" @click="runPreview()" :disabled="previewing || importing"
                            class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white text-xs font-extrabold transition">
                        <span x-text="previewing ? 'Validating Rows...' : 'Validate & Preview'"></span>
                    </button>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3.5 text-xs">
                <template x-for="(def, fieldKey) in staged.fields" :key="fieldKey">
                    <div class="p-3.5 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                        <label class="flex items-center justify-between font-bold text-slate-800 mb-1.5">
                            <span x-text="def.label"></span>
                            <span x-show="def.required" class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[10px] font-extrabold uppercase">Required</span>
                            <span x-show="!def.required" class="text-[10px] text-slate-400 font-semibold">Optional</span>
                        </label>
                        <select x-model="mapping[fieldKey]" class="w-full px-3 py-2 rounded-xl border border-purple-200 bg-white text-xs font-semibold">
                            <option value="">— Do Not Import —</option>
                            <template x-for="(h, idx) in staged.headers" :key="idx">
                                <option :value="String(idx)" x-text="'Col ' + (idx + 1) + ': ' + h"></option>
                            </template>
                        </select>
                    </div>
                </template>
            </div>
        </div>
    </template>

    <!-- STEP 3: Validation Preview, Batch Import Progress & Final Report -->
    <template x-if="preview">
        <div class="smm-card rounded-3xl p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-purple-100">
                <div>
                    <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Step 3 of 3</div>
                    <h3 class="text-base font-extrabold text-slate-900">Preview &amp; Execute Import</h3>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" x-show="importing" @click="cancelImport()"
                            class="px-4 py-2.5 rounded-xl bg-rose-100 hover:bg-rose-200 text-rose-700 text-xs font-extrabold transition">Cancel</button>
                    <button type="button" @click="startBatchImport()" :disabled="importing || (preview.summary.valid_new === 0 && (dupMode !== 'update' || preview.summary.duplicates === 0))"
                            class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 hover:opacity-95 disabled:opacity-40 text-white text-xs font-extrabold shadow-md shadow-purple-600/20 transition">
                        <span x-text="importing ? 'Importing (' + progress.percent + '%)...' : 'Confirm & Start Import'"></span>
                    </button>
                </div>
            </div>

            <!-- Preview Summary Badges -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                    <div class="text-[10px] font-extrabold uppercase text-slate-400">Total Rows</div>
                    <div class="text-xl font-extrabold text-slate-900 tabular-nums mt-0.5" x-text="preview.summary.total_rows"></div>
                </div>
                <div class="p-3.5 rounded-2xl bg-emerald-50 border border-emerald-200">
                    <div class="text-[10px] font-extrabold uppercase text-emerald-700">New Records</div>
                    <div class="text-xl font-extrabold text-emerald-700 tabular-nums mt-0.5" x-text="preview.summary.valid_new"></div>
                </div>
                <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200">
                    <div class="text-[10px] font-extrabold uppercase text-amber-700">Duplicates Found</div>
                    <div class="text-xl font-extrabold text-amber-700 tabular-nums mt-0.5" x-text="preview.summary.duplicates"></div>
                </div>
                <div class="p-3.5 rounded-2xl bg-rose-50 border border-rose-200">
                    <div class="text-[10px] font-extrabold uppercase text-rose-700">Invalid Rows</div>
                    <div class="text-xl font-extrabold text-rose-700 tabular-nums mt-0.5" x-text="preview.summary.invalid"></div>
                </div>
            </div>

            <!-- Batch Progress Bar -->
            <template x-if="importing || finalReport">
                <div class="p-5 rounded-2xl bg-[#FAF8FF] border border-purple-200 space-y-3">
                    <div class="flex items-center justify-between text-xs font-extrabold">
                        <span class="text-purple-900" x-text="importing ? 'Processing CSV batches securely...' : 'Import Completed'"></span>
                        <span class="text-purple-700 tabular-nums" x-text="progress.percent + '%'"></span>
                    </div>
                    <div class="w-full h-3 rounded-full bg-purple-100 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-violet-600 to-purple-600 transition-all duration-300"
                             :style="'width:' + progress.percent + '%'"></div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs pt-1">
                        <div class="px-3 py-2 rounded-xl bg-white border border-emerald-200 text-emerald-700 font-extrabold">Imported: <span x-text="progress.stats.imported"></span></div>
                        <div class="px-3 py-2 rounded-xl bg-white border border-blue-200 text-blue-700 font-extrabold">Updated: <span x-text="progress.stats.updated"></span></div>
                        <div class="px-3 py-2 rounded-xl bg-white border border-amber-200 text-amber-700 font-extrabold">Skipped: <span x-text="progress.stats.skipped"></span></div>
                        <div class="px-3 py-2 rounded-xl bg-white border border-rose-200 text-rose-700 font-extrabold">Failed: <span x-text="progress.stats.failed"></span></div>
                    </div>
                    <template x-if="progress.stats.errors && progress.stats.errors.length > 0">
                        <div class="mt-2 p-3 rounded-xl bg-rose-50 border border-rose-200 max-h-48 overflow-y-auto text-xs space-y-1">
                            <div class="font-extrabold text-rose-800 mb-1">Row Issues &amp; Reports (<span x-text="progress.stats.errors.length"></span>):</div>
                            <template x-for="(err, i) in progress.stats.errors" :key="i">
                                <div class="text-rose-700 font-medium">
                                    <span class="font-mono font-bold" x-text="'Row #' + err.row + ':'"></span>
                                    <span x-text="err.reason"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Preview Table (First 25 Rows) -->
            <div>
                <div class="text-xs font-extrabold text-slate-700 mb-2">Preview Sample (up to first 25 rows)</div>
                <div class="overflow-x-auto rounded-2xl border border-purple-100">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                            <tr>
                                <th class="px-4 py-2.5">Row</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">Parsed Values</th>
                                <th class="px-4 py-2.5">Validation Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-purple-50 bg-white">
                            <template x-for="r in preview.preview_rows" :key="r.row_number">
                                <tr>
                                    <td class="px-4 py-2.5 font-mono font-bold text-slate-500" x-text="'#' + r.row_number"></td>
                                    <td class="px-4 py-2.5">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase"
                                              :class="r.status === 'new' ? 'bg-emerald-100 text-emerald-800' : (r.status === 'duplicate' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-700')"
                                              x-text="r.status"></span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="flex flex-wrap gap-x-3 gap-y-1">
                                            <template x-for="(v, k) in r.data" :key="k">
                                                <span class="text-slate-700"><strong class="text-slate-500" x-text="k + ':'"></strong> <span x-text="v || '—'"></span></span>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-slate-500" x-text="r.note || 'Ready'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function csvImporterApp() {
    return {
        section: <?= json_encode($initialTab) ?>,
        serviceTarget: <?= json_encode($initialServiceType) ?>,
        providerId: '0',
        dupMode: 'skip',
        dragOver: false,
        uploading: false,
        previewing: false,
        importing: false,
        cancelRequested: false,
        staged: null,
        mapping: {},
        preview: null,
        finalReport: false,
        progress: { percent: 0, stats: { processed: 0, imported: 0, updated: 0, skipped: 0, failed: 0, errors: [] } },
        notice: { type: 'success', message: '' },

        get currentTarget() {
            return this.section === 'countries' ? 'countries' : this.serviceTarget;
        },

        init() {},

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        },

        notify(type, message) {
            this.notice = { type, message };
            if (window.showToast) window.showToast(message, type);
        },

        switchSection(sec) {
            if (this.importing) return;
            this.section = sec;
            this.providerId = '0';
            this.resetJob();
        },

        setServiceTarget(t) {
            if (this.importing) return;
            this.serviceTarget = t;
            this.providerId = '0';
            this.resetJob();
        },

        async resetJob() {
            if (this.staged && this.staged.token && !this.finalReport) {
                try {
                    const fd = new FormData();
                    fd.append('action', 'cancel_job');
                    fd.append('token', this.staged.token);
                    fd.append('_csrf_token', this.csrf());
                    fetch('/api/admin-csv-import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                } catch (e) {}
            }
            this.staged = null;
            this.mapping = {};
            this.preview = null;
            this.finalReport = false;
            this.progress = { percent: 0, stats: { processed: 0, imported: 0, updated: 0, skipped: 0, failed: 0, errors: [] } };
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
        },

        handleDrop(ev) {
            this.dragOver = false;
            const file = ev.dataTransfer?.files?.[0];
            if (file) this.uploadFile(file);
        },

        handleFileSelect(ev) {
            const file = ev.target?.files?.[0];
            if (file) this.uploadFile(file);
        },

        async uploadFile(file) {
            if (this.uploading || this.importing) return;
            if (!file.name.toLowerCase().endsWith('.csv')) {
                this.notify('error', 'Please select a valid .csv file.');
                return;
            }
            this.uploading = true;
            this.notice.message = '';
            this.preview = null;
            this.finalReport = false;

            try {
                const fd = new FormData();
                fd.append('action', 'upload_csv');
                fd.append('target', this.currentTarget);
                fd.append('csv_file', file);
                fd.append('_csrf_token', this.csrf());

                const resp = await fetch('/api/admin-csv-import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (!data || !data.ok) {
                    this.notify('error', (data && data.error) || 'Could not upload or parse CSV file.');
                    return;
                }
                this.staged = data;
                const mapObj = {};
                Object.keys(data.fields || {}).forEach(k => {
                    const v = data.suggested_mapping?.[k];
                    mapObj[k] = (v !== undefined && v !== null) ? String(v) : '';
                });
                this.mapping = mapObj;
                this.notify('success', 'CSV uploaded (' + data.total_rows + ' rows detected). Review mapping and click Validate & Preview.');
            } catch (e) {
                this.notify('error', 'Network error while uploading CSV file.');
            } finally {
                this.uploading = false;
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            }
        },

        async runPreview() {
            if (!this.staged || this.previewing || this.importing) return;
            this.previewing = true;
            this.notice.message = '';
            this.finalReport = false;
            try {
                const fd = new FormData();
                fd.append('action', 'preview_csv');
                fd.append('token', this.staged.token);
                fd.append('mapping_json', JSON.stringify(this.mapping));
                fd.append('duplicate_mode', this.dupMode);
                fd.append('provider_id', this.providerId || '0');
                fd.append('_csrf_token', this.csrf());

                const resp = await fetch('/api/admin-csv-import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (!data || !data.ok) {
                    this.notify('error', (data && data.error) || 'Validation preview failed.');
                    return;
                }
                this.preview = data;
                this.progress = { percent: 0, stats: { processed: 0, imported: 0, updated: 0, skipped: 0, failed: 0, errors: [] } };
                this.notify('success', 'Preview ready: ' + data.summary.valid_new + ' new, ' + data.summary.duplicates + ' duplicates, ' + data.summary.invalid + ' invalid.');
            } catch (e) {
                this.notify('error', 'Network error while validating CSV rows.');
            } finally {
                this.previewing = false;
            }
        },

        cancelImport() {
            this.cancelRequested = true;
        },

        async startBatchImport() {
            if (!this.staged || !this.preview || this.importing) return;
            this.importing = true;
            this.cancelRequested = false;
            this.finalReport = false;
            this.notice.message = '';
            this.progress = { percent: 2, stats: { processed: 0, imported: 0, updated: 0, skipped: 0, failed: 0, errors: [] } };

            let offset = 0;
            const batchSize = 75;

            try {
                while (true) {
                    if (this.cancelRequested) {
                        await this.resetJob();
                        this.notify('error', 'CSV import cancelled by administrator.');
                        return;
                    }
                    const fd = new FormData();
                    fd.append('action', 'process_batch');
                    fd.append('token', this.staged.token);
                    fd.append('offset', String(offset));
                    fd.append('limit', String(batchSize));
                    fd.append('_csrf_token', this.csrf());

                    const resp = await fetch('/api/admin-csv-import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await resp.json();
                    if (!data || !data.ok) {
                        this.notify('error', (data && data.error) || 'Batch import failed.');
                        return;
                    }
                    this.progress = { percent: data.percent, stats: data.stats };
                    if (data.completed) {
                        this.finalReport = true;
                        this.notify(
                            'success',
                            'CSV import finished: ' + data.stats.imported + ' imported, ' + data.stats.updated + ' updated, ' + data.stats.skipped + ' skipped, ' + data.stats.failed + ' failed.'
                        );
                        break;
                    }
                    offset = data.next_offset;
                }
            } catch (e) {
                this.notify('error', 'Network error interrupted CSV batch import.');
            } finally {
                this.importing = false;
            }
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
