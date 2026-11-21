# SMM PRO Panel — Deployment & cPanel Setup Guide

## 1. Requirements
- **PHP**: 8.1+ (`pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, `gd`, `json`)
- **Database**: MySQL 8.0+ or MariaDB 10.6+
- **Web Server**: Apache (cPanel), LiteSpeed, or Nginx (or PHP built-in server via `router.php` for local verification)
- **No Node.js, npm, or Composer build step required in production.**

## 2. Folder Structure
- `/` — Public website (`index.php`, `services.php`, `faq.php`, `contact.php`, `terms.php`, `privacy.php`, `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`, `verify-email.php`, `child-panel-site.php`)
- `/themes/classic/` — User Panel UI theme (Classic Theme)
- `/user/` — Authenticated User Panel routes
- `/admin/` — Separate Admin Panel with RBAC & TOTP 2FA
- `/api/` — Reseller API v2 (`v2.php`), AJAX endpoints (`ajax.php`), Razorpay Webhook (`razorpay-webhook.php`), Isolated Child Panel API (`child-api.php`)
- `/core/` — Protected PHP core classes (PDO Database, Auth, CSRF, Currency, Wallet, FlashSale, OrderService, CategoryMapper, ProviderAPI, PaymentService, Mailer, Logger, Uploader)
- `/config/` — Database/App configuration (`config.sample.php` -> generated `config.php` during install)
- `/database/` — `schema.sql` (32 tables + structural defaults)
- `/cron/` — CLI cron scripts (`sync_orders.php`, `sync_providers.php`, `expire_sales.php`)
- `/storage/` — Protected uploads, logs, and SQL backups

## 3. cPanel Installation Steps
1. Upload all project files directly into `public_html/` (or your addon domain document root).
2. Create a MySQL database and MySQL user in **cPanel → MySQL Databases**, and grant **ALL PRIVILEGES**.
3. Ensure `/config/` and `/storage/` (including `/storage/logs/`, `/storage/uploads/`, `/storage/backups/`) are writable (`0755` or `0775`).
4. Visit `https://yourdomain.com/install/` in your browser and complete the 4-step installer:
   - Step 1: PHP version & extension verification
   - Step 2: Enter DB host, DB name, username, password -> auto-generates `/config/config.php` & imports `/database/schema.sql`
   - Step 3: Create your Super Admin account -> generates `/install/install.lock`
5. **Security Best Practice**: Delete or restrict `/install/` after completing setup.

## 4. cPanel Cron Jobs
Configure these in **cPanel → Cron Jobs** (replace `/path/to/public_html` with your actual root):
```bash
*/2 * * * * /usr/local/bin/php /path/to/public_html/cron/sync_orders.php >/dev/null 2>&1
0 */6 * * * /usr/local/bin/php /path/to/public_html/cron/sync_providers.php >/dev/null 2>&1
*/5 * * * * /usr/local/bin/php /path/to/public_html/cron/expire_sales.php >/dev/null 2>&1
* * * * * /usr/local/bin/php /path/to/public_html/cron/sync_otp_activations.php >/dev/null 2>&1
```

## 5. External Credentials Required After Installation
1. **Razorpay** (`/admin/payments.php`):
   - `Key ID`, `Key Secret`, and `Webhook Secret`
   - Set Razorpay Webhook URL to: `https://yourdomain.com/api/razorpay-webhook.php` (event: `payment.captured`)
2. **Manual Payment Instructions** (`/admin/payments.php`):
   - Bank Account / UPI ID / QR instructions
3. **Upstream SMM Provider APIs** (`/admin/providers.php`):
   - Provider API URL (v2 compatible) and API Key, plus currency & exchange rate
4. **OTP Activation Providers** (`/admin/otp-providers.php`):
   - **Built-in 5SIM API1**: Select `Built-in 5SIM API1 (handler_api.php)`, keep Base URL `http://api1.5sim.net/stubs/handler_api.php`, enter your 5SIM API Key (encrypted server-side), verify RUB exchange rate under **Currencies & Rates** (`/admin/currencies.php`), and click **Test Connection** to confirm `ACCESS_BALANCE:<amount>`.
   - **Automatic Service Import**: Open the **Auto Service Import** tab to fetch live `getPrices` data, preview discovered countries/services with server-side selling prices, and import all, by country, selected, or missing-only without duplicates.
   - **Availability Synchronization**: Open the **Availability Sync** tab to verify real stock via `getNumbersStatus` (all enabled, by country, or by selected services) and optionally auto-disable out-of-stock services.
   - **Custom OTP Providers**: Select `Custom OTP Provider (Configurable REST/JSON)` or `Custom PHP Adapter Class` (`/core/otp_adapters/<ClassName>.php`) to configure dedicated Balance, Purchase, Pricing, Availability, Status, Cancel, and Complete endpoints with custom auth, parameter templates, response dot-path mappings, and status mappings.
   - **API Logs**: Monitor sanitized API requests, latency (ms), HTTP codes, and errors in **Admin → OTP API Logs** (`/admin/otp-provider-logs.php`).
   - **Optional Webhooks**: Set a Webhook Secret on any provider and point the provider webhook to `https://yourdomain.com/api/otp-webhook.php?provider_id=<ID>`.
5. **SMTP Email** (`/admin/settings.php`):
   - SMTP Host, Port, Username, Password, Encryption, From Email (for password resets, email verification, and notifications)

## 6. Upgrading an Existing Database (cPanel / phpMyAdmin)
1. Upload the updated PHP files over your existing installation (preserving `/config/config.php` and `/storage/`).
2. In **cPanel → phpMyAdmin**, select your database and import (both migrations are repeat-safe and preserve all existing production data):
   - `/database/migrations/2026_11_dual_store_otp.sql` (if upgrading from an older SMM-only installation)
   - `/database/migrations/2026_11_otp_provider_management.sql` (adds OTP provider sync status, pricing rules, verified availability columns, immutable purchase FX snapshots, API latency logging, and seeds `RUB` currency)
   - `/database/migrations/2026_11_otp_reseller_api_and_importer.sql` (adds database-backed batch import jobs `otp_import_jobs`, OTP Reseller API tables `otp_api_keys`, `otp_api_pricing_rules`, `otp_api_logs`, `otp_api_idempotency`, `otp_activations.source`/`api_key_id`, and `otp_api_*` settings)
   *(Note: `StoreManager::ensureSchema()` also upgrades missing tables/columns safely on first admin request.)*
3. Ensure the root `/.htaccess` file includes `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` and `RewriteRule ^api/otp/v1/(.*)$ api/otp/v1/index.php?route=$1 [QSA,L]` so `Authorization: Bearer <OTP_API_KEY>` works on cPanel Apache/FastCGI.
4. Add the 1-minute OTP sync cron job above so active OTP activations automatically finalize and refund on expiry.
5. Configure independent store availability in **Admin → Store Management** (`/admin/store-management.php`), configure 5SIM or custom providers in **Admin → OTP Providers** (`/admin/otp-providers.php`), and manage reseller keys, pricing rules, rate limits, and usage logs in **Admin → OTP Reseller API** (`/admin/otp-api.php`).
6. Users can generate their OTP Reseller API key, configure IP allowlists, and view live API documentation at `/otp/reseller-api.php` and `/otp/api-docs.php` (separate from the existing SMM Reseller API `/api/v2.php`, sharing the same unified user wallet).


