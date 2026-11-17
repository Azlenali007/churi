<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$siteFavicon = Helpers::setting('site_favicon', '');
$title = ($pageTitle ?? 'Dashboard') . ' — ' . $siteName;
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="text-slate-900 antialiased" x-data="{ sidebarOpen: false }">
<div id="toast-container" class="fixed bottom-24 lg:bottom-5 right-5 z-50 flex flex-col gap-2 max-w-sm"></div>
<div class="min-h-screen flex">
