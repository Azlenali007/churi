<?php
/**
 * Shared Application Helpers, Settings Cache, Platform SVG Icons & Encryption
 */

class Helpers
{
    private static ?array $settingsCache = null;

    public static function loadSettings(bool $refresh = false): array
    {
        if (self::$settingsCache !== null && !$refresh) {
            return self::$settingsCache;
        }
        try {
            $rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
            $map = [];
            foreach ($rows as $row) {
                $map[$row['setting_key']] = $row['setting_value'];
            }
            self::$settingsCache = $map;
            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function setting(string $key, string $default = ''): string
    {
        $all = self::loadSettings();
        return isset($all[$key]) && $all[$key] !== null ? (string)$all[$key] : $default;
    }

    public static function setSetting(string $key, ?string $value, string $group = 'general'): void
    {
        Database::execute(
            "INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)",
            [$key, $value, $group]
        );
        self::$settingsCache = null;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    public static function setFlash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
        }
    }

    public static function getFlashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    public static function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function encryptSecret(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $config = $GLOBALS['APP_CONFIG'] ?? [];
        $keyMaterial = (string)($config['app']['secret_key'] ?? 'smmpro-default-secret-key-change-me');
        $key = hash('sha256', $keyMaterial, true);
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher !== false) {
                return 'v1:' . base64_encode($iv . $tag . $cipher);
            }
        }
        return 'b64:' . base64_encode($plaintext);
    }

    public static function decryptSecret(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        if (str_starts_with($encoded, 'v1:')) {
            $raw = base64_decode(substr($encoded, 3), true);
            if ($raw === false || strlen($raw) < 29) {
                return '';
            }
            $config = $GLOBALS['APP_CONFIG'] ?? [];
            $keyMaterial = (string)($config['app']['secret_key'] ?? 'smmpro-default-secret-key-change-me');
            $key = hash('sha256', $keyMaterial, true);
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        if (str_starts_with($encoded, 'b64:')) {
            return (string)base64_decode(substr($encoded, 4), true);
        }
        return $encoded;
    }

    public static function detectPlatform(string $text): string
    {
        $t = strtolower($text);
        $map = [
            'instagram' => ['instagram', 'ig ', 'insta ', 'reels', 'igtv'],
            'youtube'   => ['youtube', 'yt ', 'shorts', 'subscribers'],
            'facebook'  => ['facebook', 'fb ', 'fanpage'],
            'telegram'  => ['telegram', 'tg ', 'channel members'],
            'tiktok'    => ['tiktok', 'tik tok', 'musically'],
            'twitter'   => ['twitter', 'x.com', 'tweet', 'retweet', 'x /', 'x followers'],
            'spotify'   => ['spotify', 'podcast plays'],
            'linkedin'  => ['linkedin'],
            'twitch'    => ['twitch'],
            'discord'   => ['discord'],
            'snapchat'  => ['snapchat'],
            'pinterest' => ['pinterest'],
            'threads'   => ['threads'],
            'website'   => ['website', 'traffic', 'seo', 'google'],
        ];
        foreach ($map as $platform => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($t, $kw)) {
                    return $platform;
                }
            }
        }
        return 'general';
    }

    public static function platformIcon(string $platform, string $sizeClass = 'w-6 h-6'): string
    {
        $p = strtolower(trim($platform));
        switch ($p) {
            case 'instagram':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-gradient-to-tr from-amber-500 via-pink-500 to-purple-600 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-full h-full"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></span>';
            case 'youtube':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-red-600 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31.4 31.4 0 0 0 0 12a31.4 31.4 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31.4 31.4 0 0 0 24 12a31.4 31.4 0 0 0-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg></span>';
            case 'facebook':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-blue-600 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></span>';
            case 'telegram':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-sky-500 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg></span>';
            case 'tiktok':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-slate-900 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.298 0 .593.047.87.14V9.4a6.33 6.33 0 0 0-.87-.06A6.34 6.34 0 0 0 3 15.68a6.34 6.34 0 0 0 10.86 4.43 6.27 6.27 0 0 0 1.88-4.46V8.68a8.2 8.2 0 0 0 4.78 1.52V6.76a4.85 4.85 0 0 1-.93-.07z"/></svg></span>';
            case 'twitter':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-zinc-900 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></span>';
            case 'spotify':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-emerald-500 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm4.58 14.42a.62.62 0 0 1-.86.21c-2.35-1.44-5.31-1.76-8.8-.96a.62.62 0 0 1-.28-1.22c3.82-.87 7.1-.5 9.73 1.11.29.18.39.56.21.86zm1.22-2.72a.78.78 0 0 1-1.07.26c-2.69-1.65-6.79-2.13-9.97-1.17a.78.78 0 1 1-.45-1.49c3.64-1.1 8.16-.57 11.23 1.32.37.23.48.71.26 1.08zm.1-2.83c-3.23-1.92-8.56-2.1-11.64-1.16a.93.93 0 1 1-.54-1.78c3.54-1.07 9.43-.87 13.15 1.34a.93.93 0 0 1-.97 1.6z"/></svg></span>';
            case 'linkedin':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-blue-700 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6zM2 9h4v12H2z"/><circle cx="4" cy="4" r="2"/></svg></span>';
            case 'discord':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-indigo-600 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M20.317 4.37a19.79 19.79 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg></span>';
            case 'twitch':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-purple-600 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="currentColor" class="w-full h-full"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/></svg></span>';
            case 'threads':
                return '<span class="inline-flex items-center justify-center rounded-lg bg-neutral-900 text-white p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-full h-full"><circle cx="12" cy="12" r="9"/><path d="M15.5 11.5c0-2-1.5-3.5-3.5-3.5s-3.5 1.5-3.5 3.5 1.5 3.5 3.5 3.5c1.5 0 2.5-.8 3-2"/></svg></span>';
            default:
                return '<span class="inline-flex items-center justify-center rounded-lg bg-purple-100 text-purple-700 p-1 shrink-0 ' . $sizeClass . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-full h-full"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg></span>';
        }
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'active'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'answered'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'in_progress' => 'bg-sky-50 text-sky-700 border-sky-200',
            'processing'  => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'pending'     => 'bg-amber-50 text-amber-700 border-amber-200',
            'open'        => 'bg-purple-50 text-purple-700 border-purple-200',
            'partial'     => 'bg-orange-50 text-orange-700 border-orange-200',
            'canceled'    => 'bg-rose-50 text-rose-700 border-rose-200',
            'rejected'    => 'bg-rose-50 text-rose-700 border-rose-200',
            'failed'      => 'bg-red-50 text-red-700 border-red-200',
            'suspended'   => 'bg-red-50 text-red-700 border-red-200',
            'inactive'    => 'bg-slate-100 text-slate-600 border-slate-200',
            'closed'      => 'bg-slate-100 text-slate-600 border-slate-200',
            'expired'     => 'bg-slate-100 text-slate-600 border-slate-200',
            'stopped'     => 'bg-rose-50 text-rose-700 border-rose-200',
        ];
        $cls = $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
        $label = ucwords(str_replace('_', ' ', $status));
        return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border ' . $cls . '">' . Validator::e($label) . '</span>';
    }

    public static function timeAgo(string $datetime): string
    {
        $ts = strtotime($datetime);
        if (!$ts) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', $ts);
    }

    public static function streamCsv(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
