<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_key') {
    CSRF::requireValid();
    $newKey = Auth::generateApiKey();
    Database::execute("UPDATE users SET api_key = ? WHERE id = ?", [$newKey, $user['id']]);
    Logger::activity('user', (int)$user['id'], 'user.api_key_regenerated', 'user', (string)$user['id']);
    Helpers::setFlash('success', 'Your Reseller API key has been updated.');
    Helpers::redirect('/user/reseller-api.php');
}

require APP_ROOT . '/themes/classic/reseller-api.php';
