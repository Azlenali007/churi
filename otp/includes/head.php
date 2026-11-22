<?php
/**
 * Custom OTP Store User Portal — Shared HTML Head
 * Loads Tailwind CSS, Plus Jakarta Sans font, shared currency config, app.js, and Alpine.js.
 */
StoreManager::setActiveStore('otp');
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteFavicon = Helpers::setting('site_favicon', '');
$title = ($pageTitle ?? 'OTP Store') . ' — ' . $siteName . ' OTP';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Validator::e(CSRF::token()) ?>">
    <title><?= Validator::e($title) ?></title>
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= Validator::e($siteFavicon) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        window.__SMM_CURRENCY_CFG = <?= json_encode(Currency::getClientConfig($user ?? null), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        window.__CSRF_TOKEN = <?= json_encode(CSRF::token()) ?>;
    </script>
    <script src="/assets/js/app.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; background: #F7F5FF; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="text-slate-900 antialiased" x-data="{ sidebarOpen: false }">
<div id="toast-container" class="fixed bottom-24 lg:bottom-5 right-5 z-50 flex flex-col gap-2 max-w-sm"></div>
<div class="min-h-screen flex">
