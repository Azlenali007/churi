<?php
/**
 * Custom OTP Store User Portal — Footer & Mobile Bottom Navigation
 */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$otpEnabled = StoreManager::isOtpEnabled();
?>
</main>
</div>
</div>

<!-- Mobile Bottom Navigation Bar -->
<nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur-xl border-t border-purple-100 px-2 py-1.5 flex items-center justify-around shadow-lg">
    <a href="/otp/dashboard.php" class="flex flex-col items-center gap-0.5 px-2.5 py-1 rounded-xl <?= $currentPath === '/otp/dashboard.php' ? 'text-purple-700 font-extrabold' : 'text-slate-500 font-semibold' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        <span class="text-[10px]">Home</span>
    </a>
    <?php if ($otpEnabled): ?>
    <a href="/otp/buy.php" class="flex flex-col items-center gap-0.5 px-3 py-1 rounded-xl <?= $currentPath === '/otp/buy.php' ? 'text-purple-700 font-extrabold' : 'text-slate-500 font-semibold' ?>">
        <span class="w-9 h-9 -mt-4 rounded-2xl bg-gradient-to-br from-violet-600 to-purple-600 text-white flex items-center justify-center shadow-md shadow-purple-600/30">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        </span>
        <span class="text-[10px]">Buy OTP</span>
    </a>
    <?php endif; ?>
    <a href="/otp/activations.php" class="flex flex-col items-center gap-0.5 px-2.5 py-1 rounded-xl <?= $currentPath === '/otp/activations.php' ? 'text-purple-700 font-extrabold' : 'text-slate-500 font-semibold' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        <span class="text-[10px]">Active</span>
    </a>
    <a href="/otp/history.php" class="flex flex-col items-center gap-0.5 px-2.5 py-1 rounded-xl <?= $currentPath === '/otp/history.php' ? 'text-purple-700 font-extrabold' : 'text-slate-500 font-semibold' ?>">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg>
        <span class="text-[10px]">History</span>
    </a>
    <a href="/user/add-funds.php" class="flex flex-col items-center gap-0.5 px-2.5 py-1 rounded-xl text-slate-500 font-semibold">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M16 12h2"/></svg>
        <span class="text-[10px]">Wallet</span>
    </a>
</nav>
<script>
window.updateOtpWalletDisplay = function(displayStr) {
    if (!displayStr) return;
    document.querySelectorAll('[data-otp-wallet-balance]').forEach(function(el) {
        el.textContent = displayStr;
    });
};
</script>
</body>
</html>
