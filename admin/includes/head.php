<?php
$siteName = Helpers::setting('site_name', 'SMM PRO');
$title = ($pageTitle ?? 'Admin Panel') . ' — ' . $siteName . ' Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Validator::e($title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-[#F6F4FC] text-slate-900 antialiased" x-data="{ sidebarOpen: false }">
<div id="toast-container" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 max-w-sm"></div>
<div class="min-h-screen flex">
