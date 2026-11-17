<?php
/**
 * Mailer Utility (SMTP Socket Client with PHP mail() fallback)
 */

class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): array
    {
        $host = Helpers::setting('smtp_host', '');
        $fromEmail = Helpers::setting('smtp_from_email', '');
        $fromName = Helpers::setting('smtp_from_name', Helpers::setting('site_name', 'SMM PRO'));

        if ($host === '' || $fromEmail === '') {
            // Try PHP mail() if configured on cPanel, otherwise report unconfigured cleanly
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: {$fromName} <" . ($fromEmail ?: 'noreply@localhost') . ">\r\n";
            if (function_exists('mail') && @mail($to, $subject, $htmlBody, $headers)) {
                return ['ok' => true];
            }
            Logger::errorFile("Mailer skipped/not configured for {$to}: {$subject}");
            return ['ok' => false, 'error' => 'SMTP is not configured in Admin Settings.'];
        }

        $port = (int)Helpers::setting('smtp_port', '587');
        $user = Helpers::setting('smtp_user', '');
        $pass = Helpers::decryptSecret(Helpers::setting('smtp_pass', ''));
        $enc = Helpers::setting('smtp_encryption', 'tls');

        try {
            $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
            $fp = @stream_socket_client($remote, $errno, $errstr, 10);
            if (!$fp) {
                throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
            }
            self::readResp($fp);
            self::sendCmd($fp, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            if ($enc === 'tls') {
                self::sendCmd($fp, "STARTTLS");
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                self::sendCmd($fp, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            }
            if ($user !== '') {
                self::sendCmd($fp, "AUTH LOGIN");
                self::sendCmd($fp, base64_encode($user));
                self::sendCmd($fp, base64_encode($pass));
            }
            self::sendCmd($fp, "MAIL FROM:<{$fromEmail}>");
            self::sendCmd($fp, "RCPT TO:<{$to}>");
            self::sendCmd($fp, "DATA");

            $msg = "From: {$fromName} <{$fromEmail}>\r\n"
                 . "To: <{$to}>\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                 . $htmlBody . "\r\n.";
            self::sendCmd($fp, $msg);
            self::sendCmd($fp, "QUIT");
            fclose($fp);
            return ['ok' => true];
        } catch (Throwable $e) {
            Logger::errorFile('Mailer SMTP error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function sendCmd($fp, string $cmd): string
    {
        fwrite($fp, $cmd . "\r\n");
        return self::readResp($fp);
    }

    private static function readResp($fp): string
    {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $data;
    }
}
