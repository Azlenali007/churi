<?php
/**
 * Application Constants & RBAC Permissions Registry
 */

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('UPLOADS_PATH', STORAGE_PATH . '/uploads');
define('BACKUPS_PATH', STORAGE_PATH . '/backups');

define('ORDER_STATUSES', [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'processing'  => 'Processing',
    'completed'   => 'Completed',
    'partial'     => 'Partial',
    'canceled'    => 'Canceled',
    'failed'      => 'Failed',
]);

define('OTP_ACTIVATION_STATUSES', [
    'processing'   => 'Processing',
    'active'       => 'Active',
    'waiting_otp'  => 'Waiting OTP',
    'otp_received' => 'OTP Received',
    'completed'    => 'Completed',
    'expired'      => 'Expired',
    'canceled'     => 'Cancelled',
    'refunded'     => 'Refunded',
    'failed'       => 'Failed',
]);

define('TICKET_DEPARTMENTS', [
    'orders'      => 'Orders & Refills',
    'payments'    => 'Billing & Payments',
    'api'         => 'Reseller API',
    'child_panel' => 'Child Panel',
    'technical'   => 'Technical Support',
    'general'     => 'General Inquiry',
]);

define('OTP_TICKET_CATEGORIES', [
    'otp_not_received'   => 'OTP Not Received',
    'number_not_working' => 'Number Not Working',
    'activation_failed'  => 'Activation Failed',
    'wrong_otp'          => 'Wrong OTP Received',
    'activation_expired' => 'Activation Expired',
    'cancel_refund'      => 'Cancel / Refund Request',
    'wallet_issue'       => 'Wallet Deduction Issue',
    'payment_issue'      => 'Payment Issue',
    'other'              => 'Other Issues',
]);

define('TICKET_STATUS_LABELS', [
    'open'         => 'Open',
    'answered'     => 'Admin Replied',
    'pending'      => 'Waiting for User',
    'waiting_user' => 'Waiting for User',
    'resolved'     => 'Resolved',
    'closed'       => 'Closed',
]);

define('ADMIN_PERMISSIONS', [
    'dashboard.view'     => 'View Admin Dashboard',
    'stores.manage'      => 'Manage Store ON/OFF Controls',
    'otp.manage'         => 'Manage OTP Dashboard, Services, Countries, Orders & Settings',
    'otp.providers'      => 'Manage OTP Providers & API Credentials',
    'otp.refunds'        => 'Approve & Reject OTP Refunds',
    'users.view'         => 'View Users',
    'users.manage'       => 'Create, Edit, Suspend, Wallet Adjust Users',
    'orders.view'        => 'View Orders',
    'orders.edit'        => 'Edit, Cancel, Refill, Sync Orders',
    'categories.manage'  => 'Manage Categories & Subcategories',
    'services.manage'    => 'Manage Services & Pricing',
    'providers.manage'   => 'Manage Providers, API Import & Sync',
    'payments.manage'    => 'Manage Gateways, Approve/Reject Payments',
    'child_panels.manage'=> 'Manage Child Panels',
    'tickets.manage'     => 'Manage Support Tickets',
    'referrals.manage'   => 'Manage Referral Settings & Payouts',
    'staff.manage'       => 'Manage Staff & Roles',
    'popups.manage'      => 'Manage Popups',
    'flash_sales.manage' => 'Manage Flash Sales',
    'content.manage'     => 'Manage Website CMS Content',
    'reports.view'       => 'View & Export Reports',
    'settings.manage'    => 'Manage System Settings & Backups',
    'logs.view'          => 'View System, API & Activity Logs',
]);

define('PLATFORM_KEYS', [
    'instagram' => 'Instagram',
    'youtube'   => 'YouTube',
    'facebook'  => 'Facebook',
    'telegram'  => 'Telegram',
    'tiktok'    => 'TikTok',
    'twitter'   => 'X / Twitter',
    'whatsapp'  => 'WhatsApp',
    'google'    => 'Google',
    'amazon'    => 'Amazon',
    'microsoft' => 'Microsoft',
    'apple'     => 'Apple',
    'uber'      => 'Uber',
    'spotify'   => 'Spotify',
    'linkedin'  => 'LinkedIn',
    'twitch'    => 'Twitch',
    'discord'   => 'Discord',
    'snapchat'  => 'Snapchat',
    'pinterest' => 'Pinterest',
    'threads'   => 'Threads',
    'website'   => 'Website Traffic / SEO',
    'general'   => 'General',
]);
