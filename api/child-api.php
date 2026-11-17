<?php
/**
 * Isolated Child Panel API Endpoint
 * Strictly scopes all queries to the authenticated child_panel_id.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$apiKey = trim((string)($_POST['key'] ?? ($_GET['key'] ?? '')));
$action = strtolower(trim((string)($_POST['action'] ?? ($_GET['action'] ?? ''))));

$panel = $apiKey !== '' ? Database::fetch("SELECT * FROM child_panels WHERE api_key = ? AND status = 'active' LIMIT 1", [$apiKey]) : null;
if (!$panel) {
    Helpers::jsonResponse(['error' => 'Invalid or inactive Child Panel API key.'], 401);
}

$cpId = (int)$panel['id'];
$ownerId = (int)$panel['owner_user_id'];

if ($action === 'services') {
    $rows = Database::fetchAll(
        "SELECT cps.parent_service_id AS service, COALESCE(cps.custom_name, s.name) AS name,
                cps.custom_rate AS rate, s.min_qty AS min, s.max_qty AS max, s.platform
         FROM child_panel_services cps
         JOIN services s ON s.id = cps.parent_service_id
         WHERE cps.child_panel_id = ? AND cps.status = 'active' AND s.status = 'active'",
        [$cpId]
    );
    Helpers::jsonResponse($rows);
}

if ($action === 'orders') {
    $orders = Database::fetchAll(
        "SELECT id, service_id, link, quantity, charge, status, start_count, remains, created_at
         FROM orders WHERE child_panel_id = ? ORDER BY id DESC LIMIT 100",
        [$cpId]
    );
    Helpers::jsonResponse($orders);
}

if ($action === 'add') {
    $serviceId = (int)($_POST['service'] ?? 0);
    $link = (string)($_POST['link'] ?? '');
    $qty = (int)($_POST['quantity'] ?? 0);
    $cpSvc = Database::fetch(
        "SELECT * FROM child_panel_services WHERE child_panel_id = ? AND parent_service_id = ? AND status = 'active' LIMIT 1",
        [$cpId, $serviceId]
    );
    if (!$cpSvc) {
        Helpers::jsonResponse(['error' => 'Service is not enabled on this child panel.'], 400);
    }
    $res = OrderService::placeOrder($ownerId, $serviceId, $link, $qty, [
        'is_api_order'   => true,
        'child_panel_id' => $cpId,
    ]);
    Helpers::jsonResponse($res['ok'] ? ['order' => $res['order_id']] : ['error' => $res['error']], $res['ok'] ? 200 : 400);
}

Helpers::jsonResponse(['error' => 'Unsupported action.'], 400);
