<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::logoutUser();
Helpers::redirect('/login.php');
