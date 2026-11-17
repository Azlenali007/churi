<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Contact Us — ' . Helpers::setting('site_name', 'SMM PRO');
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();
    $name = Validator::str($_POST['name'] ?? '', 100);
    $email = Validator::email($_POST['email'] ?? '');
    $subject = Validator::str($_POST['subject'] ?? '', 150);
    $message = Validator::str($_POST['message'] ?? '', 3000);

    if ($name === '' || !$email || $subject === '' || $message === '') {
        $error = 'Please fill in all fields with a valid email address.';
    } else {
        $supportEmail = Helpers::setting('support_email', 'support@example.com');
        $body = '<p><strong>From:</strong> ' . Validator::e($name) . ' (' . Validator::e($email) . ')</p>'
              . '<p><strong>Subject:</strong> ' . Validator::e($subject) . '</p>'
              . '<p>' . nl2br(Validator::e($message)) . '</p>';
        Mailer::send($supportEmail, '[Contact Form] ' . $subject, $body);
        Database::insert(
            "INSERT INTO notifications (admin_id, type, title, message, link) VALUES (NULL, 'system', ?, ?, '/admin/tickets.php')",
            ['Contact Inquiry: ' . $subject, 'From ' . $name . ' (' . $email . '): ' . mb_substr($message, 0, 200)]
        );
        Logger::activity('system', null, 'contact.submit', 'contact', null, $email . ' - ' . $subject);
        $sent = true;
    }
}

require __DIR__ . '/includes/public_header.php';
?>
<main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="smm-card rounded-3xl p-8">
        <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Contact Us</h1>
        <p class="text-sm text-slate-600 mb-6">Need assistance with orders, reseller API, or child panels? Reach our team at <strong><?= Validator::e(Helpers::setting('support_email', 'support@example.com')) ?></strong> or send a message below.</p>

        <?php if ($sent): ?>
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold mb-4">
                Your message has been logged and forwarded to our support team.
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold mb-4">
                <?= Validator::e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= CSRF::field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Your Name</label>
                    <input type="text" name="name" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-purple-500 outline-none text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Email Address</label>
                    <input type="email" name="email" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-purple-500 outline-none text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Subject</label>
                <input type="text" name="subject" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-purple-500 outline-none text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Message</label>
                <textarea name="message" rows="5" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-purple-500 outline-none text-sm"></textarea>
            </div>
            <button type="submit" class="px-6 py-3 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm shadow-lg shadow-purple-500/25">Send Message</button>
        </form>
    </div>
</main>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
