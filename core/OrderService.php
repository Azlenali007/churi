<?php
/**
 * Order Processing, Mass Orders, Sync, Refill & Automatic Refund Engine
 */

class OrderService
{
    public static function placeOrder(int $userId, int $serviceId, string $link, int $quantity, array $options = []): array
    {
        $user = Database::fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user || $user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'Account is not active.'];
        }

        $service = Database::fetch(
            "SELECT s.*, p.id AS prov_id, p.api_url, p.api_key_enc, p.exchange_rate AS prov_ex_rate, p.status AS prov_status
             FROM services s
             LEFT JOIN providers p ON p.id = s.provider_id
             WHERE s.id = ? AND s.status = 'active' LIMIT 1",
            [$serviceId]
        );
        if (!$service) {
            return ['ok' => false, 'error' => 'Selected service is unavailable or inactive.'];
        }

        $cleanLink = Validator::linkOrHandle($link);
        if ($cleanLink === null) {
            return ['ok' => false, 'error' => 'Please enter a valid target link or username.'];
        }

        if ($quantity < (int)$service['min_qty'] || $quantity > (int)$service['max_qty']) {
            return ['ok' => false, 'error' => sprintf('Quantity must be between %d and %d.', $service['min_qty'], $service['max_qty'])];
        }

        // Server-side authoritative Flash Sale & User discount pricing
        $pricing = FlashSale::getServicePricing($service);
        $effectiveUnitRate = $pricing['effective_rate'];

        // Apply custom user discount only if not already on a deeper flash sale
        $userDiscount = Currency::normalize((string)($user['custom_rate_discount'] ?? '0'), 2);
        if (!$pricing['on_sale'] && Currency::cmp($userDiscount, '0', 2) > 0) {
            $effectiveUnitRate = Currency::applyDiscount($effectiveUnitRate, 'percent', $userDiscount);
        }

        $charge = Currency::calculateOrderCharge($effectiveUnitRate, $quantity);
        if (Currency::cmp($charge, '0', 4) <= 0) {
            return ['ok' => false, 'error' => 'Calculated order amount is invalid.'];
        }

        // Compute provider cost in system base currency
        $providerRateBase = !empty($service['prov_id'])
            ? Currency::mul((string)$service['provider_rate'], (string)$service['prov_ex_rate'], 4)
            : '0.0000';
        $providerCost = Currency::calculateOrderCharge($providerRateBase, $quantity);
        $profit = Currency::sub($charge, $providerCost, 4);

        $isMass = !empty($options['is_mass_order']) ? 1 : 0;
        $massBatch = $options['mass_order_batch'] ?? null;
        $isApi = !empty($options['is_api_order']) ? 1 : 0;
        $customComments = !empty($options['comments']) ? Validator::str((string)$options['comments'], 5000) : null;
        $runs = !empty($options['runs']) ? (int)$options['runs'] : null;
        $interval = !empty($options['interval']) ? (int)$options['interval'] : null;
        $childPanelId = !empty($options['child_panel_id']) ? (int)$options['child_panel_id'] : null;
        $childPanelUserId = !empty($options['child_panel_user_id']) ? (int)$options['child_panel_user_id'] : null;

        Database::beginTransaction();
        try {
            $debitIdem = 'order_debit_' . $userId . '_' . bin2hex(random_bytes(8));
            $debit = Wallet::debit(
                $userId,
                $charge,
                'debit',
                'Order payment for ' . $service['name'] . ' (Qty: ' . $quantity . ')',
                'order',
                null,
                $debitIdem,
                true
            );
            if (!$debit['ok']) {
                Database::rollBack();
                return $debit;
            }

            $orderId = Database::insert(
                "INSERT INTO orders
                 (user_id, child_panel_id, child_panel_user_id, service_id, provider_id, flash_sale_id,
                  link, quantity, remains, unit_price, original_unit_price, charge, provider_cost, profit,
                  is_mass_order, mass_order_batch, is_api_order, custom_comments, drip_feed_runs, drip_feed_interval, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [
                    $userId,
                    $childPanelId,
                    $childPanelUserId,
                    $serviceId,
                    $service['provider_id'] ?: null,
                    $pricing['flash_sale_id'],
                    $cleanLink,
                    $quantity,
                    $quantity,
                    $effectiveUnitRate,
                    $pricing['original_rate'],
                    $charge,
                    $providerCost,
                    $profit,
                    $isMass,
                    $massBatch,
                    $isApi,
                    $customComments,
                    $runs,
                    $interval,
                ]
            );

            Database::execute(
                "UPDATE wallet_transactions SET reference_id = ? WHERE id = ?",
                [(string)$orderId, $debit['tx_id']]
            );

            Database::insert(
                "INSERT INTO order_logs (order_id, actor_type, actor_id, action, details) VALUES (?, ?, ?, 'created', ?)",
                [$orderId, $isApi ? 'user' : 'user', $userId, 'Order placed. Charge: ' . $charge]
            );

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('OrderService::placeOrder DB error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not create order. Please try again.'];
        }

        // Dispatch to upstream provider API if linked & active
        if (!empty($service['provider_id']) && !empty($service['provider_service_id']) && ($service['prov_status'] ?? '') === 'active') {
            $provider = [
                'id'          => $service['provider_id'],
                'api_url'     => $service['api_url'],
                'api_key_enc' => $service['api_key_enc'],
            ];
            $apiPayload = [
                'service'  => $service['provider_service_id'],
                'link'     => $cleanLink,
                'quantity' => $quantity,
            ];
            if ($customComments !== null) {
                $apiPayload['comments'] = $customComments;
            }
            if ($runs !== null && $interval !== null) {
                $apiPayload['runs'] = $runs;
                $apiPayload['interval'] = $interval;
            }

            $provRes = ProviderAPI::addOrder($provider, $apiPayload);
            if ($provRes['ok'] && !empty($provRes['data']['order'])) {
                $extOrderId = (string)$provRes['data']['order'];
                Database::execute(
                    "UPDATE orders SET provider_order_id = ?, status = 'in_progress', provider_response = ? WHERE id = ?",
                    [$extOrderId, json_encode($provRes['data']), $orderId]
                );
                Database::insert(
                    "INSERT INTO order_logs (order_id, actor_type, action, details) VALUES (?, 'provider', 'dispatched', ?)",
                    [$orderId, 'Dispatched to provider. External Order ID: #' . $extOrderId]
                );
            } else {
                $errMsg = $provRes['error'] ?? 'Provider rejected order';
                Database::execute(
                    "UPDATE orders SET status = 'failed', provider_response = ? WHERE id = ?",
                    [$errMsg, $orderId]
                );
                Database::insert(
                    "INSERT INTO order_logs (order_id, actor_type, action, details) VALUES (?, 'provider', 'dispatch_failed', ?)",
                    [$orderId, $errMsg]
                );
                // Auto-refund failed upstream dispatch
                self::refundOrder($orderId, $charge, 'Automatic refund: upstream provider dispatch failed (' . $errMsg . ')');
            }
        }

        // User notification
        Database::insert(
            "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'order', ?, ?, ?)",
            [
                $userId,
                'Order #' . $orderId . ' Placed',
                'Your order for ' . $service['name'] . ' (Qty: ' . $quantity . ') has been received.',
                '/user/orders.php?search=' . $orderId,
            ]
        );

        return ['ok' => true, 'order_id' => $orderId, 'charge' => $charge];
    }

    public static function syncOrderStatus(int $orderId): array
    {
        $order = Database::fetch(
            "SELECT o.*, p.api_url, p.api_key_enc
             FROM orders o
             JOIN providers p ON p.id = o.provider_id
             WHERE o.id = ? AND o.provider_order_id IS NOT NULL LIMIT 1",
            [$orderId]
        );
        if (!$order) {
            return ['ok' => false, 'error' => 'Order has no linked provider order ID.'];
        }

        $provider = [
            'id'          => $order['provider_id'],
            'api_url'     => $order['api_url'],
            'api_key_enc' => $order['api_key_enc'],
        ];
        $res = ProviderAPI::getOrderStatus($provider, (string)$order['provider_order_id']);
        if (!$res['ok']) {
            return $res;
        }

        $data = $res['data'];
        $mappedStatus = self::mapProviderStatus((string)($data['status'] ?? ''));
        $startCount = isset($data['start_count']) ? (int)$data['start_count'] : (int)$order['start_count'];
        $remains = isset($data['remains']) ? (int)$data['remains'] : (int)$order['remains'];

        Database::execute(
            "UPDATE orders SET status = ?, start_count = ?, remains = ?, provider_response = ? WHERE id = ?",
            [$mappedStatus, $startCount, $remains, json_encode($data), $orderId]
        );

        // Handle automatic decimal refund on canceled or partial status
        if ($mappedStatus === 'canceled') {
            self::refundOrder($orderId, (string)$order['charge'], 'Automatic refund for canceled order #' . $orderId);
        } elseif ($mappedStatus === 'partial' && $remains > 0 && (int)$order['quantity'] > 0) {
            $ratio = Currency::div((string)$remains, (string)$order['quantity'], 6);
            $refundAmt = Currency::mul((string)$order['charge'], $ratio, 4);
            if (Currency::cmp($refundAmt, '0', 4) > 0) {
                self::refundOrder($orderId, $refundAmt, 'Automatic partial refund (' . $remains . ' remains) for order #' . $orderId);
            }
        }

        Database::insert(
            "INSERT INTO order_logs (order_id, actor_type, action, details) VALUES (?, 'cron', 'synced', ?)",
            [$orderId, 'Status synced: ' . $mappedStatus . ' (Start: ' . $startCount . ', Remains: ' . $remains . ')']
        );

        return ['ok' => true, 'status' => $mappedStatus, 'start_count' => $startCount, 'remains' => $remains];
    }

    public static function refundOrder(int $orderId, string $amount, string $reason): array
    {
        $order = Database::fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        $maxRefundable = Currency::sub((string)$order['charge'], (string)$order['refunded_amount'], 4);
        $refundAmt = Currency::normalize($amount, 4);
        if (Currency::cmp($refundAmt, $maxRefundable, 4) > 0) {
            $refundAmt = $maxRefundable;
        }
        if (Currency::cmp($refundAmt, '0', 4) <= 0) {
            return ['ok' => false, 'error' => 'Nothing remaining to refund for this order.'];
        }

        $idemKey = 'refund_order_' . $orderId;
        $res = Wallet::credit((int)$order['user_id'], $refundAmt, 'refund', $reason, 'order', (string)$orderId, $idemKey);
        if ($res['ok'] && empty($res['duplicate'])) {
            Database::execute(
                "UPDATE orders SET refunded_amount = refunded_amount + ? WHERE id = ?",
                [$refundAmt, $orderId]
            );
            Database::insert(
                "INSERT INTO order_logs (order_id, actor_type, action, details) VALUES (?, 'system', 'refunded', ?)",
                [$orderId, $reason . ' (' . $refundAmt . ')']
            );
        }
        return $res;
    }

    private static function mapProviderStatus(string $raw): string
    {
        $s = strtolower(trim($raw));
        return match (true) {
            str_contains($s, 'complete')                         => 'completed',
            str_contains($s, 'partial')                          => 'partial',
            str_contains($s, 'cancel') || str_contains($s, 'refund') => 'canceled',
            str_contains($s, 'fail') || str_contains($s, 'error')    => 'failed',
            str_contains($s, 'progress')                         => 'in_progress',
            str_contains($s, 'process')                          => 'processing',
            default                                              => 'pending',
        };
    }
}
