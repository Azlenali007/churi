<?php
/**
 * User Theme Registry, Activation & View Resolver
 * Keeps Classic untouched and enables safe switching between Classic and SwipeX.
 */

class ThemeManager
{
    public static function getThemes(): array
    {
        return [
            'classic' => [
                'key'         => 'classic',
                'name'        => 'Classic Purple',
                'version'     => '1.0.0',
                'badge'       => 'Default Theme',
                'accent'      => '#7C3AED',
                'secondary'   => '#1B1145',
                'description' => 'Rich deep-indigo sidebar, soft lavender cards, and compact multi-column KPI dashboard.',
                'highlights'  => [
                    'Deep indigo desktop sidebar navigation',
                    'Purple-magenta flash sale hero & wallet cards',
                    '2x2 mobile quick actions & sticky bottom bar',
                ],
            ],
            'swipex' => [
                'key'         => 'swipex',
                'name'        => 'SwipeX',
                'version'     => '1.0.0',
                'badge'       => 'Modern Swipe UI',
                'accent'      => '#2563EB',
                'secondary'   => '#4F46E5',
                'description' => 'Ultra-clean white SaaS experience featuring a royal-blue balance banner, interactive swipeable action card deck, and sleek bottom dock.',
                'highlights'  => [
                    'Interactive center-focus swipeable action carousel',
                    'Clean white header with currency pill & support shortcuts',
                    'Elevated balance card with vivid + Add Funds CTA',
                ],
            ],
        ];
    }

    public static function getActiveThemeKey(): string
    {
        $themes = self::getThemes();
        $configured = strtolower(trim(Helpers::setting('active_theme', 'classic')));
        if (isset($themes[$configured]) && is_dir(APP_ROOT . '/themes/' . $configured)) {
            return $configured;
        }
        return 'classic';
    }

    public static function activate(string $key, ?int $adminId = null): bool
    {
        $themes = self::getThemes();
        $key = strtolower(trim($key));
        if (!isset($themes[$key]) || !is_dir(APP_ROOT . '/themes/' . $key)) {
            return false;
        }
        Helpers::setSetting('active_theme', $key, 'theme');
        if ($adminId !== null) {
            Logger::activity('admin', $adminId, 'theme.activate', 'theme', $key, 'Activated user dashboard theme: ' . $themes[$key]['name']);
        }
        return true;
    }

    public static function deactivateToDefault(?int $adminId = null): bool
    {
        Helpers::setSetting('active_theme', 'classic', 'theme');
        if ($adminId !== null) {
            Logger::activity('admin', $adminId, 'theme.deactivate', 'theme', 'classic', 'Reverted user dashboard theme to Classic');
        }
        return true;
    }

    public static function resolveViewPath(string $viewName): string
    {
        $safeView = preg_replace('/[^a-zA-Z0-9_-]/', '', $viewName);
        $active = self::getActiveThemeKey();
        $candidate = APP_ROOT . '/themes/' . $active . '/' . $safeView . '.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        return APP_ROOT . '/themes/classic/' . $safeView . '.php';
    }

    public static function renderUserView(string $viewName, array $vars = []): void
    {
        $viewPath = self::resolveViewPath($viewName);
        if (!is_file($viewPath)) {
            http_response_code(500);
            die('Theme view not found.');
        }
        if (!empty($vars)) {
            extract($vars, EXTR_SKIP);
        }
        require $viewPath;
    }
}
