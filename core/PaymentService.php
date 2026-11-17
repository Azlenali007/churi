<?php
/**
 * Payment & Gateway Service (Razorpay Server-Side Verification + Manual Payments)
 */

class PaymentService
{
    public static function getActiveGateways(): array
    {
        return Database::fetchAll(
            "SELECT * FROM payment_gateways WHERE status = 'active' ORDER BY sort_order ASC, id ASC"
        );
    }

    public static function getRazorpayConfig(): ?array
    {
        $gw = Database::fetch("SELECT * FROM payment_gateways WHERE code = 'razorpay' LIMIT 1");
        if (!$gw || $gw['status'] !== 'active') {
            return null;
        }
        $cfg = json_decode((string)$gw['config_json'], true) ?: [];
        $keyId = trim((string)($cfg['key_id'] ?? ''));
        $keySecret = trim((string)($cfg['key_secret'] ?? ''));
        if ($keyId === '' || $keySecret === '') {
            return null;
        }
        return [
            'gateway'        => $gw,
            'key_id'         => $keyId,
            'key_secret'     => $keySecret,
            'webhook_secret' => trim((string)($cfg['webhook_secret'] ?? '')),
        ];
    }

    /**
     * Creates a Razorpay Order server-side via cURL.
     */
    public static function createRazorpayOrder(int $userId, string $amount, ?string $inputCurrencyCode = null): array
    {
        $rzp = self::getRazorpayConfig();
        if (!$rzp) {
            return ['ok' => false, 'error' => 'Razorpay gateway is not configured or inactive.'];
        }

        $gw = $rzp['gateway'];
        $baseCode = Currency::getBaseCode();
        $inCode = $inputCurrencyCode ? strtoupper(trim($inputCurrencyCode)) : $baseCode;
        $amtNorm = ($inCode !== $baseCode && Currency::getCurrency($inCode))
            ? Currency::convertToBase($amount, $inCode, 2)
            : Currency::normalize($amount, 2);

        if (Currency::cmp($amtNorm, (string)$gw['min_amount'], 2) < 0 || Currency::cmp($amtNorm, (string)$gw['max_amount'], 2) > 0) {
            return ['ok' => false, 'error' => 'Amount is outside the allowed gateway limits (' . Currency::display((string)$gw['min_amount'], $inCode) . ' - ' . Currency::display((string)$gw['max_amount'], $inCode) . ').'];
        }

        $paise = (int)Currency::mul($amtNorm, '100', 0);
        $txRef = 'RZP_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $currency = $baseCode;

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $rzp['key_id'] . ':' . $rzp['key_secret'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'amount'   => $paise,
                'currency' => $currency,
                'receipt'  => $txRef,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['id'])) {
            $errMsg = $decoded['error']['description'] ?? ($err ?: 'Failed to create Razorpay order.');
            Logger::api('payment_webhook', null, $userId, 'razorpay/orders', ['amount' => $amtNorm], $raw, $httpCode, 'error');
            return ['ok' => false, 'error' => $errMsg];
        }

        $fee = Currency::mul($amtNorm, Currency::div((string)$gw['fee_percent'], '100', 6), 4);
        $net = Currency::sub($amtNorm, $fee, 4);

        $paymentId = Database::insert(
            "INSERT INTO payments (user_id, gateway_id, gateway_code, transaction_ref, gateway_order_id, amount, fee, net_amount, currency, status)
             VALUES (?, ?, 'razorpay', ?, ?, ?, ?, ?, ?, 'pending')",
            [$userId, $gw['id'], $txRef, $decoded['id'], $amtNorm, $fee, $net, $currency]
        );

        return [
            'ok'                => true,
            'payment_db_id'     => $paymentId,
            'razorpay_order_id' => $decoded['id'],
            'key_id'            => $rzp['key_id'],
            'amount_paise'      => $paise,
            'currency'          => $currency,
            'tx_ref'            => $txRef,
        ];
    }

    /**
     * Verifies Razorpay checkout signature strictly server-side before crediting wallet.
     */
    public static function verifyRazorpayPayment(int $userId, string $rzpOrderId, string $rzpPaymentId, string $rzpSignature): array
    {
        $rzp = self::getRazorpayConfig();
        if (!$rzp) {
            return ['ok' => false, 'error' => 'Razorpay configuration unavailable.'];
        }

        $expected = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, $rzp['key_secret']);
        if (!hash_equals($expected, $rzpSignature)) {
            Logger::api('payment_webhook', null, $userId, 'razorpay/verify', ['order' => $rzpOrderId, 'payment' => $rzpPaymentId], 'Invalid signature', 400, 'error');
            return ['ok' => false, 'error' => 'Payment signature verification failed.'];
        }

        return self::finalizeGatewayCredit($rzpOrderId, $rzpPaymentId, json_encode(['verified_via' => 'checkout_signature', 'payment_id' => $rzpPaymentId]));
    }

    public static function finalizeGatewayCredit(string $gatewayOrderId, string $gatewayPaymentId, string $rawPayload): array
    {
        Database::beginTransaction();
        try {
            $payment = Database::fetch("SELECT * FROM payments WHERE gateway_order_id = ? FOR UPDATE", [$gatewayOrderId]);
            if (!$payment) {
                Database::rollBack();
                return ['ok' => false, 'error' => 'Matching pending payment record not found.'];
            }
            if ($payment['status'] === 'completed') {
                Database::commit();
                return ['ok' => true, 'already_completed' => true];
            }

            Database::execute(
                "UPDATE payments SET status = 'completed', gateway_payment_id = ?, verified_at = NOW(), raw_response = ? WHERE id = ?",
                [$gatewayPaymentId, $rawPayload, $payment['id']]
            );

            $credit = Wallet::credit(
                (int)$payment['user_id'],
                (string)$payment['net_amount'],
                'payment',
                'Razorpay Deposit #' . $payment['transaction_ref'],
                'payment',
                (string)$payment['id'],
                'pay_credit_' . $payment['id']
            );

            if (!$credit['ok']) {
                Database::rollBack();
                return $credit;
            }

            Database::insert(
                "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'payment', ?, ?, ?)",
                [
                    $payment['user_id'],
                    'Funds Added: ' . Currency::formatBase((string)$payment['net_amount'], 2, true),
                    'Your Razorpay payment (' . $payment['transaction_ref'] . ') was verified and credited.',
                    '/user/add-funds.php',
                ]
            );

            Database::commit();
            return ['ok' => true, 'credited' => $payment['net_amount']];
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('PaymentService::finalizeGatewayCredit: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to finalize payment credit.'];
        }
    }

    public static function submitManualPayment(int $userId, int $gatewayId, string $amount, string $txRef, ?string $payerNote, ?string $proofPath, ?string $inputCurrencyCode = null): array
    {
        $gw = Database::fetch("SELECT * FROM payment_gateways WHERE id = ? AND status = 'active' AND type = 'manual' LIMIT 1", [$gatewayId]);
        if (!$gw) {
            return ['ok' => false, 'error' => 'Selected manual payment method is inactive.'];
        }

        $baseCode = Currency::getBaseCode();
        $inCode = $inputCurrencyCode ? strtoupper(trim($inputCurrencyCode)) : $baseCode;
        $amtNorm = ($inCode !== $baseCode && Currency::getCurrency($inCode))
            ? Currency::convertToBase($amount, $inCode, 4)
            : Currency::normalize($amount, 4);

        if (Currency::cmp($amtNorm, (string)$gw['min_amount'], 4) < 0 || Currency::cmp($amtNorm, (string)$gw['max_amount'], 4) > 0) {
            return ['ok' => false, 'error' => 'Deposit amount is outside allowed gateway limits (' . Currency::display((string)$gw['min_amount'], $inCode) . ' - ' . Currency::display((string)$gw['max_amount'], $inCode) . ').'];
        }

        $txClean = Validator::str($txRef, 110);
        if ($txClean === '') {
            return ['ok' => false, 'error' => 'Transaction / UTR reference ID is required.'];
        }

        if (Database::fetch("SELECT id FROM payments WHERE transaction_ref = ? LIMIT 1", [$txClean])) {
            return ['ok' => false, 'error' => 'This transaction reference has already been submitted.'];
        }

        $fee = Currency::mul($amtNorm, Currency::div((string)$gw['fee_percent'], '100', 6), 4);
        $net = Currency::sub($amtNorm, $fee, 4);
        $currency = $baseCode;

        $pid = Database::insert(
            "INSERT INTO payments (user_id, gateway_id, gateway_code, transaction_ref, amount, fee, net_amount, currency, proof_path, payer_note, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [$userId, $gw['id'], $gw['code'], $txClean, $amtNorm, $fee, $net, $currency, $proofPath, $payerNote]
        );

        return ['ok' => true, 'payment_id' => $pid, 'base_amount' => $amtNorm];
    }

    public static function reviewManualPayment(int $paymentId, int $adminId, string $decision, string $adminNote = ''): array
    {
        Database::beginTransaction();
        try {
            $payment = Database::fetch("SELECT * FROM payments WHERE id = ? FOR UPDATE", [$paymentId]);
            if (!$payment) {
                Database::rollBack();
                return ['ok' => false, 'error' => 'Payment record not found.'];
            }
            if ($payment['status'] !== 'pending') {
                Database::rollBack();
                return ['ok' => false, 'error' => 'Payment has already been processed.'];
            }

            if ($decision === 'approve') {
                Database::execute(
                    "UPDATE payments SET status = 'completed', verified_at = NOW(), reviewed_by = ?, admin_note = ? WHERE id = ?",
                    [$adminId, $adminNote, $paymentId]
                );
                $credit = Wallet::credit(
                    (int)$payment['user_id'],
                    (string)$payment['net_amount'],
                    'payment',
                    'Approved Manual Payment #' . $payment['transaction_ref'],
                    'payment',
                    (string)$paymentId,
                    'pay_credit_' . $paymentId
                );
                if (!$credit['ok']) {
                    Database::rollBack();
                    return $credit;
                }
                Database::insert(
                    "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'payment', ?, ?, ?)",
                    [
                        $payment['user_id'],
                        'Payment Approved: ' . Currency::formatBase((string)$payment['net_amount'], 2, true),
                        'Your manual payment (' . $payment['transaction_ref'] . ') was approved and added to your wallet.',
                        '/user/add-funds.php',
                    ]
                );
            } else {
                Database::execute(
                    "UPDATE payments SET status = 'rejected', reviewed_by = ?, admin_note = ? WHERE id = ?",
                    [$adminId, $adminNote, $paymentId]
                );
                Database::insert(
                    "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'payment', ?, ?, ?)",
                    [
                        $payment['user_id'],
                        'Payment Rejected',
                        'Your manual payment (' . $payment['transaction_ref'] . ') was rejected.' . ($adminNote ? ' Note: ' . $adminNote : ''),
                        '/user/add-funds.php',
                    ]
                );
            }

            Database::commit();
            Logger::activity('admin', $adminId, 'payment.' . $decision, 'payment', (string)$paymentId, $adminNote);
            return ['ok' => true];
        } catch (Throwable $e) {
            Database::rollBack();
            Logger::errorFile('PaymentService::reviewManualPayment: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Unable to process payment review.'];
        }
    }
}
