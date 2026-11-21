<?php
/**
 * Active OTP Activations — Live SMS Polling, Countdown & Controls
 * Remains fully operational even when OTP Store purchasing is turned OFF.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$userId = (int)$user['id'];
$pageTitle = 'Active Activations';
$dispCurrency = Currency::getActiveDisplayCurrency($user);
$showFullSms = Helpers::setting('otp_show_full_sms', '1') === '1';
$pollIntervalSec = max(3, (int)Helpers::setting('otp_polling_interval', '5'));
$otpEnabled = StoreManager::isOtpEnabled();

$rawActive = OtpService::getActiveActivationsForUser($userId);
$now = time();
$initialItems = [];
foreach ($rawActive as $a) {
    $expTs = !empty($a['expires_at']) ? strtotime((string)$a['expires_at']) : null;
    $status = (string)$a['status'];
    $initialItems[] = [
        'id'                => (int)$a['id'],
        'activation_code'   => (string)$a['activation_code'],
        'service_name'      => (string)$a['service_name'],
        'country_name'      => (string)$a['country_name'],
        'country_flag'      => (string)($a['country_flag'] ?? ''),
        'provider_name'     => (string)($a['provider_name'] ?? ''),
        'provider_order_id' => (string)($a['provider_order_id'] ?? ''),
        'phone_number'      => (string)($a['phone_number'] ?? ''),
        'otp_code'          => (string)($a['otp_code'] ?? ''),
        'sms_text'          => $showFullSms ? (string)($a['sms_text'] ?? '') : '',
        'sms_received_at'   => $a['sms_received_at'] ? date('M d, H:i:s', strtotime((string)$a['sms_received_at'])) : null,
        'display_price'     => Currency::display((string)$a['selling_price'], $dispCurrency['code'], 2, true),
        'status'            => $status,
        'can_cancel'        => !empty($a['can_cancel']) && empty($a['otp_code']) && in_array($status, ['active', 'waiting_otp'], true) && Helpers::setting('otp_allow_user_cancel', '1') === '1',
        'can_complete'      => !empty($a['can_complete']) && in_array($status, ['otp_received', 'waiting_otp', 'active'], true),
        'remaining_seconds' => $expTs ? max(0, $expTs - $now) : 0,
        'failure_reason'    => (string)($a['failure_reason'] ?? ''),
        'created_at'        => date('M d, Y H:i', strtotime((string)$a['created_at'])),
    ];
}

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div x-data="otpActiveTracker()" x-init="init()" class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-white rounded-2xl border border-purple-100 p-4">
        <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full" :class="items.length > 0 ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
            <div class="text-xs font-bold text-slate-700">
                <span x-text="items.length"></span> active activation<span x-show="items.length !== 1">s</span>
                <span class="text-slate-400 mx-1">&middot;</span>
                <span class="text-slate-500" x-text="syncing ? 'Checking provider...' : 'Auto-polling every <?= $pollIntervalSec ?>s'"></span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="pollAll(true)" :disabled="syncing"
                    class="px-3.5 py-2 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-extrabold transition">
                Refresh All
            </button>
            <?php if ($otpEnabled): ?>
            <a href="/otp/buy.php" class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-sm transition">+ New OTP</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Empty State -->
    <template x-if="items.length === 0">
        <div class="bg-white rounded-3xl border border-purple-100 p-12 text-center">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <h2 class="text-base font-extrabold text-slate-900">No Active OTP Activations</h2>
            <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">When you purchase a direct one-time OTP activation, your assigned phone number, countdown timer, and incoming SMS code will appear here in real time.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2.5">
                <?php if ($otpEnabled): ?>
                <a href="/otp/buy.php" class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow-sm">Buy OTP Activation</a>
                <?php endif; ?>
                <a href="/otp/history.php" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold">View OTP History</a>
            </div>
        </div>
    </template>

    <!-- Active Cards -->
    <div class="grid gap-4">
        <template x-for="a in items" :key="a.id">
            <div class="bg-white rounded-3xl border shadow-xs p-5 sm:p-6 transition"
                 :class="a.otp_code ? 'border-emerald-300' : 'border-purple-100'">
                <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-purple-50">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-extrabold text-slate-900" x-text="a.service_name"></span>
                            <span class="px-2.5 py-0.5 rounded-full bg-purple-50 text-purple-700 text-[11px] font-bold"
                                  x-text="(a.country_flag ? a.country_flag + ' ' : '') + a.country_name"></span>
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase"
                                  :class="statusClass(a.status)" x-text="a.status.replace('_',' ')"></span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500 font-mono">
                            Code: <span class="font-bold text-slate-700" x-text="a.activation_code"></span>
                            <span x-show="a.provider_order_id"> &middot; Ref: <span x-text="a.provider_order_id"></span></span>
                            &middot; Price: <span class="font-sans font-bold text-slate-800" x-text="a.display_price"></span>
                            &middot; <span class="font-sans" x-text="a.created_at"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="px-3 py-1.5 rounded-xl bg-slate-900 text-white font-mono text-xs font-extrabold tabular-nums"
                             x-text="formatTimer(a.remaining_seconds)"></div>
                    </div>
                </div>

                <div class="mt-4 grid md:grid-cols-2 gap-4">
                    <!-- Phone Number -->
                    <div class="p-4 rounded-2xl bg-[#FAF8FF] border border-purple-100">
                        <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Assigned Phone Number</div>
                        <div class="mt-1.5 flex items-center justify-between gap-2">
                            <span class="text-lg sm:text-xl font-mono font-extrabold text-slate-900 select-all"
                                  x-text="a.phone_number || 'Assigning...'"></span>
                            <button type="button" x-show="a.phone_number"
                                    @click="window.copyText(a.phone_number, 'Phone number copied!')"
                                    class="px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shrink-0">
                                Copy
                            </button>
                        </div>
                    </div>

                    <!-- OTP Code -->
                    <div class="p-4 rounded-2xl border" :class="a.otp_code ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50/50 border-amber-200/80'">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider"
                                  :class="a.otp_code ? 'text-emerald-700' : 'text-amber-700'"
                                  x-text="a.otp_code ? 'OTP Received' : 'Waiting for SMS'"></span>
                            <span x-show="a.sms_received_at" class="text-[10px] font-semibold text-emerald-700" x-text="a.sms_received_at"></span>
                        </div>
                        <template x-if="a.otp_code">
                            <div class="mt-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-2xl font-mono font-extrabold text-emerald-800 tracking-wider select-all" x-text="a.otp_code"></span>
                                    <button type="button" @click="window.copyText(a.otp_code, 'OTP copied!')"
                                            class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shrink-0">
                                        Copy OTP
                                    </button>
                                </div>
                                <div x-show="a.sms_text" class="mt-2 p-2.5 rounded-xl bg-white/80 border border-emerald-200 text-xs text-emerald-950" x-text="a.sms_text"></div>
                            </div>
                        </template>
                        <template x-if="!a.otp_code">
                            <div class="mt-2 text-xs text-amber-800 font-semibold">
                                Listening for incoming verification code...
                                <div x-show="a.failure_reason" class="mt-1 text-rose-700" x-text="a.failure_reason"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mt-4 pt-4 border-t border-purple-50 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="checkOne(a.id)" :disabled="busyId === a.id"
                                class="px-3.5 py-2 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-extrabold transition">
                            <span x-text="busyId === a.id ? 'Checking...' : 'Check SMS'"></span>
                        </button>
                        <button type="button" x-show="a.can_complete" @click="finishOne(a.id)" :disabled="busyId === a.id"
                                class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition">
                            Complete
                        </button>
                        <button type="button" x-show="a.can_cancel" @click="cancelOne(a.id)" :disabled="busyId === a.id"
                                class="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-extrabold transition">
                            Cancel
                        </button>
                    </div>
                    <a :href="'/user/tickets.php?store=otp&otp_activation_id=' + a.id"
                       class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition">
                        Report Issue
                    </a>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function otpActiveTracker() {
    return {
        items: <?= json_encode($initialItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        pollSeconds: <?= (int)$pollIntervalSec ?>,
        syncing: false,
        busyId: null,
        pollTimer: null,
        tickTimer: null,

        init() {
            this.tickTimer = setInterval(() => {
                this.items.forEach(a => {
                    if (a.remaining_seconds > 0) a.remaining_seconds--;
                });
            }, 1000);
            this.pollTimer = setInterval(() => {
                if (this.items.length > 0) this.pollAll(false);
            }, this.pollSeconds * 1000);
        },
        async pollAll(force) {
            if (this.syncing) return;
            this.syncing = true;
            try {
                const prevCodes = {};
                this.items.forEach(x => prevCodes[x.id] = x.otp_code);
                const resp = await fetch('/api/otp.php?action=poll' + (force ? '&force=1' : ''), { credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) {
                    window.updateOtpWalletDisplay(data.display_balance);
                }
                if (data.ok && Array.isArray(data.activations)) {
                    data.activations.forEach(a => {
                        if (!prevCodes[a.id] && a.otp_code && window.showToast) {
                            window.showToast('OTP received for ' + a.service_name + ': ' + a.otp_code, 'success');
                        }
                    });
                    this.items = data.activations.filter(a => !['completed', 'expired', 'canceled', 'refunded', 'failed'].includes(a.status));
                }
            } catch (e) {} finally {
                this.syncing = false;
            }
        },
        async checkOne(id) {
            this.busyId = id;
            try {
                const resp = await fetch('/api/otp.php?action=check&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) window.updateOtpWalletDisplay(data.display_balance);
                if (data.activation) {
                    this.upsert(data.activation);
                    if (data.activation.otp_code && window.showToast) {
                        window.showToast('OTP Code: ' + data.activation.otp_code, 'success');
                    } else if (window.showToast) {
                        window.showToast('Status checked: ' + data.activation.status.replace('_', ' '), 'info');
                    }
                }
            } finally {
                this.busyId = null;
            }
        },
        async cancelOne(id) {
            this.busyId = id;
            try {
                const fd = new FormData();
                fd.append('action', 'cancel');
                fd.append('id', id);
                fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');
                const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (data.display_balance && window.updateOtpWalletDisplay) window.updateOtpWalletDisplay(data.display_balance);
                if (data.ok) {
                    this.items = this.items.filter(x => x.id !== id);
                    if (window.showToast) window.showToast(data.refunded ? 'Cancelled & refunded to shared wallet.' : 'Activation cancelled.', 'info');
                } else if (window.showToast) {
                    window.showToast(data.error || 'Could not cancel activation.', 'error');
                }
            } finally {
                this.busyId = null;
            }
        },
        async finishOne(id) {
            this.busyId = id;
            try {
                const fd = new FormData();
                fd.append('action', 'finish');
                fd.append('id', id);
                fd.append('_csrf', window.__CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '');
                const resp = await fetch('/api/otp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await resp.json();
                if (data.ok) {
                    this.items = this.items.filter(x => x.id !== id);
                    if (window.showToast) window.showToast('Activation completed!', 'success');
                } else if (window.showToast) {
                    window.showToast(data.error || 'Could not finish activation.', 'error');
                }
            } finally {
                this.busyId = null;
            }
        },
        upsert(act) {
            if (['completed', 'expired', 'canceled', 'refunded', 'failed'].includes(act.status)) {
                this.items = this.items.filter(x => x.id !== act.id);
                return;
            }
            const idx = this.items.findIndex(x => x.id === act.id);
            if (idx >= 0) this.items[idx] = act;
        },
        statusClass(st) {
            if (st === 'otp_received') return 'bg-emerald-100 text-emerald-800';
            if (st === 'waiting_otp' || st === 'active') return 'bg-amber-100 text-amber-800';
            return 'bg-purple-100 text-purple-800';
        },
        formatTimer(sec) {
            const s = Math.max(0, parseInt(sec || 0, 10));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
