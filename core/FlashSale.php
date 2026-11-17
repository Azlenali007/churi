<?php
/**
 * Flash Sale Engine — Server-Side Pricing, Conflict Detection & Live Countdowns
 */

class FlashSale
{
    public static function expireFinishedSales(): void
    {
        try {
            Database::execute(
                "UPDATE flash_sales SET status = 'expired' WHERE status = 'active' AND ends_at <= NOW()"
            );
        } catch (Throwable $e) {
            // Silently log
            Logger::errorFile('FlashSale::expireFinishedSales: ' . $e->getMessage());
        }
    }

    /**
     * Returns the highest-priority currently active flash sale (for banners).
     */
    public static function getFeaturedActiveSale(): ?array
    {
        self::expireFinishedSales();
        $sale = Database::fetch(
            "SELECT * FROM flash_sales
             WHERE status = 'active' AND starts_at <= NOW() AND ends_at > NOW()
             ORDER BY priority ASC, id DESC LIMIT 1"
        );
        if (!$sale) {
            return null;
        }
        $sampleService = Database::fetch(
            "SELECT s.id, s.name, s.platform, s.rate, fss.custom_sale_price
             FROM flash_sale_services fss
             JOIN services s ON s.id = fss.service_id
             WHERE fss.flash_sale_id = ? AND s.status = 'active'
             ORDER BY s.id ASC LIMIT 1",
            [$sale['id']]
        );
        $sale['sample_service'] = $sampleService;
        if ($sampleService) {
            $sale['original_price'] = Currency::normalize((string)$sampleService['rate'], 2);
            $sale['sale_price'] = Currency::normalize(self::computeEffectiveUnitPrice(
                (string)$sampleService['rate'],
                $sale,
                $sampleService['custom_sale_price'] !== null ? (string)$sampleService['custom_sale_price'] : null
            ), 2);
        }
        return $sale;
    }

    /**
     * Authoritative server-side pricing lookup for a service.
     * Guarantees expired or stopped sales NEVER affect orders.
     */
    public static function getServicePricing(array $service): array
    {
        self::expireFinishedSales();
        $baseRate = Currency::normalize((string)$service['rate'], 4);

        $row = Database::fetch(
            "SELECT fs.*, fss.custom_sale_price
             FROM flash_sale_services fss
             JOIN flash_sales fs ON fs.id = fss.flash_sale_id
             WHERE fss.service_id = ?
               AND fs.status = 'active'
               AND fs.starts_at <= NOW()
               AND fs.ends_at > NOW()
             ORDER BY fs.priority ASC, fs.id DESC
             LIMIT 1",
            [(int)$service['id']]
        );

        if (!$row) {
            return [
                'on_sale'        => false,
                'flash_sale_id'  => null,
                'original_rate'  => $baseRate,
                'effective_rate' => $baseRate,
                'discount_label' => null,
                'ends_at'        => null,
                'sale_title'     => null,
            ];
        }

        $custom = $row['custom_sale_price'] !== null ? (string)$row['custom_sale_price'] : null;
        $effective = self::computeEffectiveUnitPrice($baseRate, $row, $custom);

        $label = $row['discount_type'] === 'percent'
            ? rtrim(rtrim(number_format((float)$row['discount_value'], 2, '.', ''), '0'), '.') . '% OFF'
            : 'SALE';

        return [
            'on_sale'        => true,
            'flash_sale_id'  => (int)$row['id'],
            'original_rate'  => $baseRate,
            'effective_rate' => $effective,
            'discount_label' => $label,
            'ends_at'        => $row['ends_at'],
            'sale_title'     => $row['title'],
        ];
    }

    public static function computeEffectiveUnitPrice(string $baseRate, array $sale, ?string $customSalePrice = null): string
    {
        if ($customSalePrice !== null && Currency::cmp($customSalePrice, '0', 4) > 0) {
            return Currency::normalize($customSalePrice, 4);
        }
        return Currency::applyDiscount($baseRate, (string)$sale['discount_type'], (string)$sale['discount_value']);
    }

    /**
     * Checks if any of the given service IDs already belong to an overlapping active sale.
     */
    public static function findConflictingServices(array $serviceIds, string $startsAt, string $endsAt, ?int $excludeSaleId = null): array
    {
        if (empty($serviceIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $params = array_map('intval', $serviceIds);
        $sql = "SELECT DISTINCT s.id, s.name, fs.title AS sale_title
                FROM flash_sale_services fss
                JOIN flash_sales fs ON fs.id = fss.flash_sale_id
                JOIN services s ON s.id = fss.service_id
                WHERE fss.service_id IN ($placeholders)
                  AND fs.status = 'active'
                  AND fs.starts_at < ?
                  AND fs.ends_at > ?";
        $params[] = $endsAt;
        $params[] = $startsAt;
        if ($excludeSaleId !== null) {
            $sql .= " AND fs.id != ?";
            $params[] = $excludeSaleId;
        }
        return Database::fetchAll($sql, $params);
    }
}
