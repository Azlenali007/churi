<?php
/**
 * Dedicated OTP Reseller API v1 Documentation Page
 * Displays actual Base URL, Auth headers, all 9 implemented endpoints, JSON examples,
 * standardized error codes, rate limits, and copyable PHP (cURL) & cURL snippets.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$user = Auth::requireUser();
$pageTitle = 'OTP Reseller API Documentation';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host . '/api/otp/v1';
$baseCurrency = Currency::getBaseCode();
$defaultRate = max(1, (int)Helpers::setting('otp_api_default_user_rate_limit', '60'));
$maxDaily = max(1, (int)Helpers::setting('otp_api_max_daily_orders', '500'));
$maxActive = max(1, (int)Helpers::setting('otp_api_max_active_activations', '25'));

$endpoints = [
    [
        'method' => 'GET',
        'path'   => '/balance',
        'title'  => 'Get Wallet Balance',
        'desc'   => 'Returns your live shared wallet balance in base currency.',
        'params' => 'None',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Wallet balance retrieved.',
            'data'    => ['balance' => '250.0000', 'currency_code' => $baseCurrency, 'username' => $user['username']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'GET',
        'path'   => '/services',
        'title'  => 'List Available OTP Services',
        'desc'   => 'Returns all active OTP services with server-calculated starting reseller prices.',
        'params' => 'search (optional), popular (optional: 1), limit (optional: 1-200)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Available OTP services retrieved.',
            'data'    => [
                'count' => 1,
                'currency_code' => $baseCurrency,
                'services' => [['service_id' => 1, 'name' => 'WhatsApp', 'slug' => 'whatsapp', 'icon_key' => 'whatsapp', 'is_popular' => true, 'starting_price' => '18.5000', 'currency_code' => $baseCurrency]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'GET',
        'path'   => '/countries',
        'title'  => 'List Supported Countries',
        'desc'   => 'Returns active countries. Pass service_id to list countries enabled for a specific service.',
        'params' => 'service_id (optional int)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Available countries retrieved.',
            'data'    => [
                'count' => 1,
                'countries' => [['country_id' => 1, 'name' => 'India', 'iso_code' => 'IN', 'dial_code' => '+91', 'flag_emoji' => '🇮🇳']],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'GET',
        'path'   => '/prices',
        'title'  => 'Get Reseller Prices & Stock',
        'desc'   => 'Returns authoritative server-calculated reseller prices for active service & country combinations.',
        'params' => 'service_id (optional int), country_id (optional int), limit (optional: 1-500)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Reseller OTP prices retrieved.',
            'data'    => [
                'count' => 1,
                'currency_code' => $baseCurrency,
                'prices' => [['service_id' => 1, 'service_name' => 'WhatsApp', 'country_id' => 1, 'country_name' => 'India', 'country_iso' => 'IN', 'operator' => 'any', 'price' => '21.0000', 'currency_code' => $baseCurrency, 'available' => true, 'availability_verified' => true, 'stock' => 42]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'POST',
        'path'   => '/activation/create',
        'title'  => 'Create One-Time OTP Activation',
        'desc'   => 'Atomically deducts the server-calculated reseller price from your shared wallet and assigns a real phone number from the upstream provider. Automatically refunds on definitive provider failure.',
        'params' => 'service_id (required int), country_id (required int), operator (optional string), Idempotency-Key header or idempotency_key param (recommended)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'OTP activation created and phone number assigned.',
            'data'    => [
                'activation_id' => 1042,
                'activation_code' => 'OTPAPI-8F2A19C4',
                'source' => 'api',
                'service_id' => 1,
                'service_name' => 'WhatsApp',
                'country_id' => 1,
                'country_name' => 'India',
                'phone_number' => '919876543210',
                'price' => '21.0000',
                'currency_code' => $baseCurrency,
                'status' => 'waiting_otp',
                'otp_code' => null,
                'expires_at' => date('Y-m-d H:i:s', time() + 900),
                'balance_after' => '229.0000',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'GET',
        'path'   => '/activation/status',
        'title'  => 'Check Activation & OTP Code',
        'desc'   => 'Checks live provider status and returns the received OTP code / SMS text. Strictly restricted to activations you own.',
        'params' => 'activation_id (required int ID or activation_code), force (optional: 1)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Activation status retrieved.',
            'data'    => [
                'activation_id' => 1042,
                'activation_code' => 'OTPAPI-8F2A19C4',
                'phone_number' => '919876543210',
                'status' => 'otp_received',
                'otp_code' => '482910',
                'sms_text' => 'Your WhatsApp code is 482910',
                'sms_received_at' => date('Y-m-d H:i:s'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'POST',
        'path'   => '/activation/cancel',
        'title'  => 'Cancel Active Activation',
        'desc'   => 'Cancels an activation if no OTP has been received and the provider permits cancellation. Issues exact-once automatic wallet refund when enabled.',
        'params' => 'activation_id (required int ID or activation_code)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Activation cancelled successfully.',
            'data'    => ['activation_id' => 1042, 'status' => 'refunded', 'refunded' => true, 'balance_after' => '250.0000'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'POST',
        'path'   => '/activation/complete',
        'title'  => 'Complete Activation',
        'desc'   => 'Marks an active/received activation as completed on both provider and local ledger.',
        'params' => 'activation_id (required int ID or activation_code)',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Activation completed successfully.',
            'data'    => ['activation_id' => 1042, 'status' => 'completed'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    [
        'method' => 'GET',
        'path'   => '/activation/history',
        'title'  => 'Activation Order History',
        'desc'   => 'Returns paginated activation history for your account only.',
        'params' => 'page (default 1), per_page (1-100), status (optional), source (api|direct|all, default api), service_id, country_id',
        'sample_ok' => json_encode([
            'status'  => 'success',
            'message' => 'Activation history retrieved.',
            'data'    => ['activations' => [], 'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
];

$curlExample = <<<BASH
# 1. Check Shared Wallet Balance
curl -s -X GET "{$baseUrl}/balance" \\
  -H "Authorization: Bearer YOUR_OTP_API_KEY"

# 2. Fetch Reseller Prices
curl -s -X GET "{$baseUrl}/prices?service_id=1&country_id=1" \\
  -H "X-API-Key: YOUR_OTP_API_KEY"

# 3. Create OTP Activation (with Idempotency-Key)
curl -s -X POST "{$baseUrl}/activation/create" \\
  -H "Authorization: Bearer YOUR_OTP_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: order-2026-0001" \\
  -d '{"service_id": 1, "country_id": 1, "operator": "any"}'

# 4. Poll Activation Status / OTP Code
curl -s -X GET "{$baseUrl}/activation/status?activation_id=1042" \\
  -H "Authorization: Bearer YOUR_OTP_API_KEY"

# 5. Cancel Activation (before OTP delivery)
curl -s -X POST "{$baseUrl}/activation/cancel" \\
  -H "Authorization: Bearer YOUR_OTP_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"activation_id": 1042}'
BASH;

$phpExample = <<<PHP
<?php
\$baseUrl = '{$baseUrl}';
\$apiKey  = 'YOUR_OTP_API_KEY';

function otpApiRequest(string \$method, string \$path, array \$payload = [], ?string \$idemKey = null): array {
    global \$baseUrl, \$apiKey;
    \$url = \$baseUrl . \$path;
    \$headers = [
        'Authorization: Bearer ' . \$apiKey,
        'Accept: application/json',
    ];
    if (\$idemKey !== null) {
        \$headers[] = 'Idempotency-Key: ' . \$idemKey;
    }

    \$ch = curl_init();
    if (\$method === 'GET' && !empty(\$payload)) {
        \$url .= '?' . http_build_query(\$payload);
    } elseif (\$method === 'POST') {
        \$headers[] = 'Content-Type: application/json';
        curl_setopt(\$ch, CURLOPT_POST, true);
        curl_setopt(\$ch, CURLOPT_POSTFIELDS, json_encode(\$payload));
    }

    curl_setopt_array(\$ch, [
        CURLOPT_URL            => \$url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => \$headers,
    ]);
    \$body = curl_exec(\$ch);
    \$http = (int)curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
    curl_close(\$ch);

    return ['http' => \$http, 'body' => json_decode((string)\$body, true)];
}

// Create Activation
\$created = otpApiRequest('POST', '/activation/create', [
    'service_id' => 1,
    'country_id' => 1,
], 'my-unique-order-' . time());

if ((\$created['body']['status'] ?? '') === 'success') {
    \$actId = \$created['body']['data']['activation_id'];
    \$phone = \$created['body']['data']['phone_number'];
    echo "Assigned #{\$actId}: {\$phone}\\n";

    // Poll Status
    \$status = otpApiRequest('GET', '/activation/status', ['activation_id' => \$actId]);
    print_r(\$status['body']);
}
PHP;

$errorCodes = [
    ['INVALID_API_KEY', '401', 'API key is missing, malformed, revoked, or regenerated.'],
    ['API_DISABLED', '403 / 503', 'OTP Reseller API is disabled globally or for your account.'],
    ['IP_NOT_ALLOWED', '403', 'Request origin IP is not in your configured IP allowlist.'],
    ['INSUFFICIENT_BALANCE', '402', 'Shared wallet balance is insufficient for the server-calculated price.'],
    ['INVALID_SERVICE', '404 / 422', 'Requested service_id does not exist or is disabled.'],
    ['INVALID_COUNTRY', '404 / 422', 'Requested country_id does not exist or is disabled.'],
    ['SERVICE_UNAVAILABLE', '409', 'Upstream provider has no stock available for this service/country right now.'],
    ['ACTIVATION_NOT_FOUND', '404', 'Requested activation_id does not exist.'],
    ['ACTIVATION_NOT_OWNED', '403', 'Activation belongs to a different user account.'],
    ['ACTIVATION_EXPIRED', '409', 'Activation has already expired.'],
    ['CANCELLATION_NOT_ALLOWED', '409', 'OTP already received or provider does not permit cancellation yet.'],
    ['PROVIDER_ERROR', '502', 'Upstream OTP provider returned an error (wallet refunded on purchase failure).'],
    ['RATE_LIMIT_EXCEEDED', '429', 'Per-minute request limit, daily order limit, or active activations limit reached.'],
    ['DUPLICATE_REQUEST', '409', 'Idempotency-Key was reused with mismatched parameters or duplicate order token.'],
];

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Top Bar -->
    <div class="bg-white rounded-3xl border border-purple-100 p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="text-[11px] font-extrabold uppercase tracking-wider text-purple-600">Developer Reference &middot; REST JSON v1</div>
            <h1 class="text-xl font-extrabold text-slate-900 mt-0.5">OTP Reseller API Documentation</h1>
            <p class="text-xs text-slate-500 mt-1">All requests and responses use UTF-8 JSON. Prices are strictly calculated server-side in <strong><?= Validator::e($baseCurrency) ?></strong>.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/otp/reseller-api.php" class="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold shadow transition">&larr; Back to API Dashboard</a>
        </div>
    </div>

    <!-- Base URL, Auth & Rate Limits -->
    <div class="grid lg:grid-cols-3 gap-5 text-xs">
        <div class="bg-white rounded-2xl border border-purple-100 p-5 space-y-2">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-purple-700">1. Base URL</div>
            <div class="p-2.5 rounded-xl bg-[#FAF8FF] border border-purple-100 font-mono text-[11px] font-bold text-slate-900 break-all"><?= Validator::e($baseUrl) ?></div>
            <button type="button" onclick="window.copyText(<?= htmlspecialchars(json_encode($baseUrl), ENT_QUOTES, 'UTF-8') ?>, 'Base URL copied!')"
                    class="px-3 py-1.5 rounded-lg bg-purple-100 text-purple-800 font-extrabold">Copy Base URL</button>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-5 space-y-2">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-purple-700">2. Authentication Headers</div>
            <p class="text-slate-600">Send your key via either header (recommended) or <code class="font-mono">api_key</code> parameter:</p>
            <div class="p-2.5 rounded-xl bg-slate-900 text-emerald-300 font-mono text-[11px] space-y-1">
                <div>Authorization: Bearer YOUR_OTP_API_KEY</div>
                <div>X-API-Key: YOUR_OTP_API_KEY</div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-purple-100 p-5 space-y-2">
            <div class="text-[10px] font-extrabold uppercase tracking-wider text-purple-700">3. Rate &amp; Safety Limits</div>
            <ul class="space-y-1 text-slate-600">
                <li>&bull; Default Rate Limit: <strong class="text-slate-900"><?= $defaultRate ?> requests / minute</strong></li>
                <li>&bull; Max Active Activations: <strong class="text-slate-900"><?= $maxActive ?> concurrent</strong></li>
                <li>&bull; Daily Purchase Cap: <strong class="text-slate-900"><?= $maxDaily ?> orders / day</strong></li>
                <li>&bull; Max Request Payload: <strong class="text-slate-900">64 KB</strong></li>
            </ul>
        </div>
    </div>

    <!-- Endpoints List -->
    <div class="space-y-4">
        <h2 class="text-base font-extrabold text-slate-900">Implemented Endpoints (v1)</h2>
        <?php foreach ($endpoints as $ep): ?>
        <div class="bg-white rounded-2xl border border-purple-100 overflow-hidden">
            <div class="px-5 py-3.5 bg-[#FAF8FF] border-b border-purple-100 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                    <span class="px-2.5 py-1 rounded-lg font-mono text-[11px] font-extrabold <?= $ep['method'] === 'POST' ? 'bg-emerald-600 text-white' : 'bg-purple-600 text-white' ?>"><?= $ep['method'] ?></span>
                    <code class="font-mono text-xs font-extrabold text-slate-900">/api/otp/v1<?= Validator::e($ep['path']) ?></code>
                </div>
                <span class="text-xs font-bold text-slate-500"><?= Validator::e($ep['title']) ?></span>
            </div>
            <div class="p-5 grid lg:grid-cols-2 gap-4 text-xs">
                <div class="space-y-2">
                    <p class="text-slate-600 leading-relaxed"><?= Validator::e($ep['desc']) ?></p>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
                        <span class="text-[10px] font-extrabold uppercase text-slate-400 block mb-0.5">Parameters</span>
                        <span class="font-mono text-[11px] text-slate-800"><?= Validator::e($ep['params']) ?></span>
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-extrabold uppercase text-slate-400 mb-1">Example JSON Response</div>
                    <pre class="p-3 rounded-xl bg-slate-950 text-emerald-300 font-mono text-[11px] overflow-x-auto leading-relaxed"><?= Validator::e($ep['sample_ok']) ?></pre>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Copyable Code Examples -->
    <div class="grid lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-3xl border border-purple-100 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-purple-50 flex items-center justify-between">
                <h3 class="text-sm font-extrabold text-slate-900">cURL Examples</h3>
                <button type="button" onclick="window.copyText(document.getElementById('curlSnippet').textContent, 'cURL examples copied!')"
                        class="px-3 py-1.5 rounded-lg bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-extrabold">Copy cURL</button>
            </div>
            <pre id="curlSnippet" class="p-5 bg-slate-950 text-sky-200 font-mono text-[11px] overflow-x-auto leading-relaxed"><?= Validator::e($curlExample) ?></pre>
        </div>

        <div class="bg-white rounded-3xl border border-purple-100 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-purple-50 flex items-center justify-between">
                <h3 class="text-sm font-extrabold text-slate-900">PHP (cURL) Integration Example</h3>
                <button type="button" onclick="window.copyText(document.getElementById('phpSnippet').textContent, 'PHP example copied!')"
                        class="px-3 py-1.5 rounded-lg bg-purple-100 hover:bg-purple-200 text-purple-800 text-xs font-extrabold">Copy PHP</button>
            </div>
            <pre id="phpSnippet" class="p-5 bg-slate-950 text-purple-200 font-mono text-[11px] overflow-x-auto leading-relaxed"><?= Validator::e($phpExample) ?></pre>
        </div>
    </div>

    <!-- Standardized Error Codes Table -->
    <div class="bg-white rounded-3xl border border-purple-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-purple-50">
            <h2 class="text-sm font-extrabold text-slate-900">Standardized Error Codes</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#FAF8FF] text-slate-500 uppercase text-[10px] font-extrabold">
                    <tr>
                        <th class="px-5 py-3">error_code</th>
                        <th class="px-5 py-3">HTTP Status</th>
                        <th class="px-5 py-3">Meaning</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-purple-50">
                    <?php foreach ($errorCodes as $ec): ?>
                    <tr>
                        <td class="px-5 py-2.5 font-mono font-extrabold text-rose-700"><?= Validator::e($ec[0]) ?></td>
                        <td class="px-5 py-2.5 font-mono text-slate-600"><?= Validator::e($ec[1]) ?></td>
                        <td class="px-5 py-2.5 text-slate-700"><?= Validator::e($ec[2]) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
