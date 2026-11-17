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
```

## 5. External Credentials Required After Installation
1. **Razorpay** (`/admin/payments.php`):
   - `Key ID`, `Key Secret`, and `Webhook Secret`
   - Set Razorpay Webhook URL to: `https://yourdomain.com/api/razorpay-webhook.php` (event: `payment.captured`)
2. **Manual Payment Instructions** (`/admin/payments.php`):
   - Bank Account / UPI ID / QR instructions
3. **Upstream SMM Provider APIs** (`/admin/providers.php`):
   - Provider API URL (v2 compatible) and API Key, plus currency & exchange rate
4. **SMTP Email** (`/admin/settings.php`):
   - SMTP Host, Port, Username, Password, Encryption, From Email (for password resets, email verification, and notifications)
