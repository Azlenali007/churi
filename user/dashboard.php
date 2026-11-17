<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
require APP_ROOT . '/themes/classic/dashboard.php';
