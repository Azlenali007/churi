<?php
/**
 * Atomic Wallet Engine (Row-Locked Financial Operations + Duplicate Prevention)
 */

class Wallet
{
    public static function credit(
        int $userId,
        string $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): array {
        $amount = Currency::normalize($amount, 4);
        if (Currency::cmp($amount, '0', 4) <= 0) {
            return ['ok' => false, 'error' => 'Amount must be greater than zero.'];
        }

        $ownTx = !Database::pdo()->inTransaction();
        if ($ownTx) {
            Database::beginTransaction();
        }

        try {
            if ($idempotencyKey !== null) {
                $existing = Database::fetch("SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1", [$idempotencyKey]);
                if ($existing) {
                    if ($ownTx) Database::commit();
                    return ['ok' => true, 'duplicate' => true, 'tx_id' => (int)$existing['id']];
                }
            }

            $user = Database::fetch("SELECT id, wallet_balance, referred_by FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user) {
                if ($ownTx) Database::rollBack();
                return ['ok' => false, 'error' => 'User account not found.'];
            }

            $before = Currency::normalize((string)$user['wallet_balance'], 4);
            $after = Currency::add($before, $amount, 4);

            Database::execute("UPDATE users SET wallet_balance = ? WHERE id = ?", [$after, $userId]);

            $txId = Database::insert(
                "INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, $type, $amount, $before, $after, $referenceType, $referenceId, $idempotencyKey, substr($description, 0, 255)]
            );

            // Process referral bonus if this is a verified payment and user was referred
            if ($type === 'payment' && !empty($user['referred_by']) && Helpers::setting('referral_enabled', '1') === '1') {
                self::awardReferralBonus((int)$user['referred_by'], $userId, $amount, (int)$referenceId);
            }

            if ($ownTx) {
                Database::commit();
            }
            return ['ok' => true, 'tx_id' => $txId, 'balance_before' => $before, 'balance_after' => $after];
        } catch (Throwable $e) {
            if ($ownTx) Database::rollBack();
            Logger::errorFile('Wallet::credit error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Wallet transaction failed.'];
        }
    }

    public static function debit(
        int $userId,
        string $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
        bool $trackSpent = false
    ): array {
        $amount = Currency::normalize($amount, 4);
        if (Currency::cmp($amount, '0', 4) <= 0) {
            return ['ok' => false, 'error' => 'Debit amount must be greater than zero.'];
        }

        $ownTx = !Database::pdo()->inTransaction();
        if ($ownTx) {
            Database::beginTransaction();
        }

        try {
            if ($idempotencyKey !== null) {
                $existing = Database::fetch("SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1", [$idempotencyKey]);
                if ($existing) {
                    if ($ownTx) Database::rollBack();
                    return ['ok' => false, 'duplicate' => true, 'error' => 'Duplicate debit prevented.'];
                }
            }

            $user = Database::fetch("SELECT id, wallet_balance, total_spent FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user) {
                if ($ownTx) Database::rollBack();
                return ['ok' => false, 'error' => 'User account not found.'];
            }

            $before = Currency::normalize((string)$user['wallet_balance'], 4);
            if (Currency::cmp($before, $amount, 4) < 0) {
                if ($ownTx) Database::rollBack();
                return ['ok' => false, 'error' => 'Insufficient wallet balance. Please add funds.'];
            }

            $after = Currency::sub($before, $amount, 4);
            $newSpent = $trackSpent
                ? Currency::add((string)$user['total_spent'], $amount, 4)
                : Currency::normalize((string)$user['total_spent'], 4);

            Database::execute(
                "UPDATE users SET wallet_balance = ?, total_spent = ? WHERE id = ?",
                [$after, $newSpent, $userId]
            );

            $txId = Database::insert(
                "INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, $type, $amount, $before, $after, $referenceType, $referenceId, $idempotencyKey, substr($description, 0, 255)]
            );

            if ($ownTx) {
                Database::commit();
            }
            return ['ok' => true, 'tx_id' => $txId, 'balance_before' => $before, 'balance_after' => $after];
        } catch (Throwable $e) {
            if ($ownTx) Database::rollBack();
            Logger::errorFile('Wallet::debit error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Unable to process wallet debit.'];
        }
    }

    private static function awardReferralBonus(int $referrerId, int $referredId, string $depositAmount, int $paymentId): void
    {
        $ref = Database::fetch(
            "SELECT * FROM referrals WHERE referrer_user_id = ? AND referred_user_id = ? LIMIT 1",
            [$referrerId, $referredId]
        );
        if (!$ref) {
            return;
        }
        $rate = (string)$ref['commission_rate'];
        if (Currency::cmp($rate, '0', 2) <= 0) {
            return;
        }
        $bonus = Currency::mul($depositAmount, Currency::div($rate, '100', 6), 4);
        if (Currency::cmp($bonus, '0.01', 4) < 0) {
            return;
        }
        $idemKey = 'ref_bonus_pay_' . $paymentId;
        $res = self::credit(
            $referrerId,
            $bonus,
            'referral_bonus',
            'Referral commission (' . $rate . '%) from user #' . $referredId,
            'payment',
            (string)$paymentId,
            $idemKey
        );
        if (!empty($res['ok']) && empty($res['duplicate'])) {
            Database::execute(
                "UPDATE referrals SET total_earned = total_earned + ? WHERE id = ?",
                [$bonus, $ref['id']]
            );
            Database::insert(
                "INSERT INTO referral_transactions (referral_id, referrer_user_id, referred_user_id, source_type, source_id, base_amount, commission_amount)
                 VALUES (?, ?, ?, 'payment', ?, ?, ?)",
                [$ref['id'], $referrerId, $referredId, $paymentId, $depositAmount, $bonus]
            );
        }
    }
}
