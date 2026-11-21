<?php
/**
 * Standard SMM Reseller API v2 Endpoint
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$input = $_POST;
if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    $json = json_decode((string)$rawBody, true);
    if (is_array($json)) {
        $input = $json;
    }
}

$apiKey = trim((string)($input['key'] ?? ''));
$action = strtolower(trim((string)($input['action'] ?? '')));

if ($apiKey === '' || $action === '') {
    Logger::api('reseller_in', null, null, '/api/v2.php', $input, ['error' => 'Missing key or action'], 400, 'error');
    Helpers::jsonResponse(['error' => 'API key and action are required.'], 400);
}

$user = Database::fetch("SELECT * FROM users WHERE api_key = ? LIMIT 1", [$apiKey]);
if (!$user || $user['status'] !== 'active' || $user['api_status'] !== 'active') {
    Logger::api('reseller_in', null, null, '/api/v2.php', ['action' => $action], ['error' => 'Invalid or disabled API key'], 401, 'error');
    Helpers::jsonResponse(['error' => 'Invalid or disabled API key.'], 401);
}

$uid = (int)$user['id'];

switch ($action) {
    case 'balance':
        $resp = [
            'balance'  => Currency::normalize((string)$user['wallet_balance'], 4),
            'currency' => Helpers::setting('base_currency', 'INR'),
        ];
        Logger::api('reseller_in', null, $uid, '/api/v2.php?action=balance', ['action' => 'balance'], $resp, 200, 'ok');
        Helpers::jsonResponse($resp);
        break;

    case 'services':
        StoreManager::requireSmmEnabled(true);
        $rows = Database::fetchAll(
            "SELECT s.*, c.name AS category_name
             FROM services s
             JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND c.status = 'active'
             ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC"
        );
        $list = [];
        foreach ($rows as $s) {
            $p = FlashSale::getServicePricing($s);
            $list[] = [
                'service'  => (int)$s['id'],
                'name'     => $s['name'],
                'type'     => ucfirst(str_replace('_', ' ', $s['type'])),
                'category' => $s['category_name'],
                'rate'     => $p['effective_rate'],
                'min'      => (string)$s['min_qty'],
                'max'      => (string)$s['max_qty'],
                'refill'   => (bool)$s['refill_support'],
                'cancel'   => (bool)$s['cancel_support'],
                'dripfeed' => (bool)$s['drip_feed_support'],
            ];
        }
        Logger::api('reseller_in', null, $uid, '/api/v2.php?action=services', ['action' => 'services'], count($list) . ' services', 200, 'ok');
        Helpers::jsonResponse($list);
        break;

    case 'add':
        StoreManager::requireSmmEnabled(true);
        $serviceId = (int)($input['service'] ?? 0);
        $link = (string)($input['link'] ?? '');
        $qty = (int)($input['quantity'] ?? 0);
        $comments = isset($input['comments']) ? (string)$input['comments'] : null;
        $runs = isset($input['runs']) ? (int)$input['runs'] : null;
        $interval = isset($input['interval']) ? (int)$input['interval'] : null;

        $res = OrderService::placeOrder($uid, $serviceId, $link, $qty, [
            'is_api_order' => true,
            'comments'     => $comments,
            'runs'         => $runs,
            'interval'     => $interval,
        ]);
        if ($res['ok']) {
            $resp = ['order' => (int)$res['order_id']];
            Logger::api('reseller_in', null, $uid, '/api/v2.php?action=add', $input, $resp, 200, 'ok');
            Helpers::jsonResponse($resp);
        }
        $err = ['error' => $res['error'] ?? 'Order creation failed'];
        Logger::api('reseller_in', null, $uid, '/api/v2.php?action=add', $input, $err, 400, 'error');
        Helpers::jsonResponse($err, 400);
        break;

    case 'status':
        if (!empty($input['orders'])) {
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string)$input['orders']))), 0, 100);
            $out = [];
            foreach ($ids as $oid) {
                $o = Database::fetch("SELECT id, charge, start_count, status, remains FROM orders WHERE id = ? AND user_id = ? LIMIT 1", [$oid, $uid]);
                $out[(string)$oid] = $o ? [
                    'charge'      => $o['charge'],
                    'start_count' => (string)$o['start_count'],
                    'status'      => ORDER_STATUSES[$o['status']] ?? ucfirst($o['status']),
                    'remains'     => (string)$o['remains'],
                    'currency'    => Helpers::setting('base_currency', 'INR'),
                ] : ['error' => 'Incorrect order ID'];
            }
            Helpers::jsonResponse($out);
        }
        $oid = (int)($input['order'] ?? 0);
        $o = Database::fetch("SELECT id, charge, start_count, status, remains FROM orders WHERE id = ? AND user_id = ? LIMIT 1", [$oid, $uid]);
        if (!$o) {
            Helpers::jsonResponse(['error' => 'Incorrect order ID'], 404);
        }
        Helpers::jsonResponse([
            'charge'      => $o['charge'],
            'start_count' => (string)$o['start_count'],
            'status'      => ORDER_STATUSES[$o['status']] ?? ucfirst($o['status']),
            'remains'     => (string)$o['remains'],
            'currency'    => Helpers::setting('base_currency', 'INR'),
        ]);
        break;

    case 'refill':
        $oid = (int)($input['order'] ?? 0);
        $o = Database::fetch(
            "SELECT o.*, s.refill_support, p.api_url, p.api_key_enc
             FROM orders o JOIN services s ON s.id = o.service_id LEFT JOIN providers p ON p.id = o.provider_id
             WHERE o.id = ? AND o.user_id = ? LIMIT 1",
            [$oid, $uid]
        );
        if (!$o || !$o['refill_support']) {
            Helpers::jsonResponse(['error' => 'Refill not available for this order'], 400);
        }
        if (!empty($o['provider_id']) && !empty($o['provider_order_id'])) {
            $r = ProviderAPI::requestRefill($o, (string)$o['provider_order_id']);
            if (!$r['ok']) {
                Helpers::jsonResponse(['error' => $r['error'] ?? 'Refill rejected by provider'], 400);
            }
        }
        Database::execute("UPDATE orders SET refill_status = 'requested' WHERE id = ?", [$oid]);
        Helpers::jsonResponse(['refill' => $oid]);
        break;

    case 'cancel':
        $oid = (int)($input['order'] ?? 0);
        $o = Database::fetch(
            "SELECT o.*, s.cancel_support, p.api_url, p.api_key_enc
             FROM orders o JOIN services s ON s.id = o.service_id LEFT JOIN providers p ON p.id = o.provider_id
             WHERE o.id = ? AND o.user_id = ? LIMIT 1",
            [$oid, $uid]
        );
        if (!$o || !$o['cancel_support']) {
            Helpers::jsonResponse(['error' => 'Cancellation not supported for this order'], 400);
        }
        if (!empty($o['provider_id']) && !empty($o['provider_order_id'])) {
            ProviderAPI::requestCancel($o, (string)$o['provider_order_id']);
        }
        Database::execute("UPDATE orders SET cancel_status = 'requested' WHERE id = ?", [$oid]);
        Helpers::jsonResponse(['cancel' => $oid]);
        break;

    default:
        Helpers::jsonResponse(['error' => 'Unsupported API action.'], 400);
}
