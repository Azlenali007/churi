<?php
/**
 * Promotional Popup Engine — Scheduling, Context & Frequency Rules
 */

class Popup
{
    public static function getActiveForContext(string $context = 'public'): ?array
    {
        $col = $context === 'dashboard' ? 'show_on_dashboard' : 'show_on_public';
        $popups = Database::fetchAll(
            "SELECT * FROM popups
             WHERE status = 'active'
               AND {$col} = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY priority ASC, id DESC"
        );

        return $popups[0] ?? null;
    }
}
