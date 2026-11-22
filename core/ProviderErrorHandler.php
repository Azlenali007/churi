<?php
/**
 * Centralized Provider Error Handler & Safe JSON Response Guard
 * - Classifies network, timeout, auth, HTTP, parse, config, and DB errors cleanly
 * - Redacts API keys, tokens, passwords, and secrets before logging or returning messages
 * - Discards stray PHP output buffers so warnings/notices never corrupt AJAX JSON
 * - Prevents unhandled HTTP 500 crashes across OTP & SMM Provider Management
 */

class ProviderErrorHandler
{
    private static bool $ajaxGuardActive = false;
    private static array $ajaxContext = [];

    /**
     * Activate clean JSON output buffering and fatal-error protection for AJAX provider endpoints.
     */
    public static function startAjaxGuard(array $context = []): void
    {
        self::$ajaxContext = $context;

        if (!ob_get_level()) {
            ob_start();
        }

        if (self::$ajaxGuardActive) {
            return;
        }
        self::$ajaxGuardActive = true;

        // Capture non-fatal warnings/notices into server logs without polluting JSON output
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            Logger::errorFile(sprintf(
                'AJAX PHP warning [%d] in %s:%d — %s',
                $severity,
                basename($file),
                $line,
                self::redact($message)
            ));
            return true;
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) {
                return;
            }

            $msg = sprintf(
                'AJAX Fatal Error [%d] in %s:%d — %s',
                $err['type'],
                basename((string)($err['file'] ?? 'unknown')),
                (int)($err['line'] ?? 0),
                self::redact((string)($err['message'] ?? 'Unknown fatal error'))
            );
            Logger::errorFile($msg);
            Logger::workflowLog('provider_error', 'Fatal error in provider AJAX endpoint', array_merge(self::$ajaxContext, [
                'file' => basename((string)($err['file'] ?? '')),
                'line' => (int)($err['line'] ?? 0),
            ]));

            self::cleanOutputBuffers();
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
            }
            echo json_encode([
                'ok'             => false,
                'error'          => 'A server execution error occurred while processing the provider request. Technical details have been logged.',
                'error_code'     => 'SERVER_FATAL',
                'error_category' => 'internal',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    /**
     * Discard any stray output buffers so JSON output is strictly valid.
     */
    public static function cleanOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }
    }

    /**
     * Redact API keys, tokens, passwords, and explicit secret values from strings.
     */
    public static function redact(string $text, array $secrets = []): string
    {
        foreach ($secrets as $sec) {
            $s = trim((string)$sec);
            if ($s !== '' && strlen($s) >= 4) {
                $text = str_replace($s, '[REDACTED]', $text);
                $text = str_replace(rawurlencode($s), '[REDACTED]', $text);
                $text = str_replace(urlencode($s), '[REDACTED]', $text);
            }
        }
        $patterns = [
            '/((?:api_?key|token|secret|key|password|passwd|auth)\s*[=:]\s*)(["\']?)([^&\s"\'}\],]+)/i' => '$1$2[REDACTED]',
            '/(Authorization\s*:\s*(?:Bearer|Basic|Token)\s+)([^\r\n\s]+)/i' => '$1[REDACTED]',
            '/(X-API-Key\s*:\s*)([^\r\n\s]+)/i' => '$1[REDACTED]',
        ];
        foreach ($patterns as $regex => $replacement) {
            $text = (string)preg_replace($regex, $replacement, $text);
        }
        return $text;
    }

    /**
     * Classify an HTTP / cURL / provider failure into a clean diagnostic category & message.
     */
    public static function classifyHttpFailure(
        int $httpCode,
        int $curlErrno,
        string $curlError,
        string $rawBody = '',
        ?string $providerMessage = null,
        array $secrets = []
    ): array {
        if ($curlErrno !== 0) {
            $cleanCurl = self::redact($curlError, $secrets);
            if (defined('CURLE_OPERATION_TIMEDOUT') && $curlErrno === CURLE_OPERATION_TIMEDOUT) {
                return [
                    'category'   => 'timeout',
                    'error_code' => 'PROVIDER_TIMEOUT',
                    'message'    => 'Provider API request timed out. The remote server took too long to respond.',
                ];
            }
            if ((defined('CURLE_COULDNT_RESOLVE_HOST') && $curlErrno === CURLE_COULDNT_RESOLVE_HOST)
                || (defined('CURLE_COULDNT_CONNECT') && $curlErrno === CURLE_COULDNT_CONNECT)
            ) {
                return [
                    'category'   => 'network',
                    'error_code' => 'PROVIDER_UNREACHABLE',
                    'message'    => 'Unable to connect to the provider API host. Verify the API URL and server network connectivity.',
                ];
            }
            if ((defined('CURLE_SSL_CONNECT_ERROR') && $curlErrno === CURLE_SSL_CONNECT_ERROR)
                || (defined('CURLE_PEER_FAILED_VERIFICATION') && $curlErrno === CURLE_PEER_FAILED_VERIFICATION)
                || str_contains(strtolower($cleanCurl), 'ssl')
                || str_contains(strtolower($cleanCurl), 'certificate')
            ) {
                return [
                    'category'   => 'network',
                    'error_code' => 'PROVIDER_SSL_ERROR',
                    'message'    => 'SSL/TLS handshake with the provider API failed: ' . $cleanCurl,
                ];
            }
            return [
                'category'   => 'network',
                'error_code' => 'PROVIDER_CONNECTION_ERROR',
                'message'    => 'Provider connection failed: ' . ($cleanCurl ?: 'Network error'),
            ];
        }

        $cleanMsg = self::redact((string)($providerMessage ?? ''), $secrets);
        $low = strtolower($cleanMsg . ' ' . substr(self::redact($rawBody, $secrets), 0, 300));

        if ($httpCode === 401 || $httpCode === 403
            || str_contains($low, 'bad_key')
            || str_contains($low, 'invalid api key')
            || str_contains($low, 'invalid key')
            || str_contains($low, 'unauthorized')
            || str_contains($low, 'forbidden')
            || str_contains($low, 'authentication failed')
            || str_contains($low, 'wrong api key')
            || str_contains($low, 'access denied')
        ) {
            return [
                'category'   => 'auth',
                'error_code' => 'PROVIDER_AUTH_FAILED',
                'message'    => $cleanMsg !== '' ? $cleanMsg : 'Provider authentication failed. Verify your API key and authentication settings.',
            ];
        }

        if ($httpCode === 404) {
            return [
                'category'   => 'config',
                'error_code' => 'PROVIDER_ENDPOINT_NOT_FOUND',
                'message'    => $cleanMsg !== '' ? $cleanMsg : 'Provider endpoint returned HTTP 404 (Not Found). Check the configured base URL and endpoint path.',
            ];
        }

        if ($httpCode === 429) {
            return [
                'category'   => 'provider',
                'error_code' => 'PROVIDER_RATE_LIMITED',
                'message'    => 'Provider rate limit exceeded (HTTP 429). Wait briefly and try again.',
            ];
        }

        if ($httpCode >= 500) {
            return [
                'category'   => 'http',
                'error_code' => 'PROVIDER_UPSTREAM_ERROR',
                'message'    => "Provider server returned HTTP {$httpCode}. The external provider may be temporarily experiencing issues.",
            ];
        }

        if ($httpCode > 0 && ($httpCode < 200 || $httpCode >= 300)) {
            return [
                'category'   => 'http',
                'error_code' => 'PROVIDER_HTTP_ERROR',
                'message'    => $cleanMsg !== '' ? $cleanMsg : "Provider API returned HTTP {$httpCode}.",
            ];
        }

        return [
            'category'   => 'provider',
            'error_code' => 'PROVIDER_ERROR',
            'message'    => $cleanMsg !== '' ? $cleanMsg : 'Provider returned an error response.',
        ];
    }

    /**
     * Persist a sanitized error message onto a provider row without risking a secondary failure.
     */
    public static function recordProviderError(
        string $scope,
        int $providerId,
        string $errorMessage,
        bool $markFailed = false,
        array $secrets = []
    ): void {
        if ($providerId <= 0) {
            return;
        }
        $clean = substr(self::redact($errorMessage, $secrets), 0, 500);
        try {
            if ($scope === 'otp') {
                if ($markFailed) {
                    Database::execute(
                        "UPDATE otp_providers SET connection_status = 'failed', last_error = ? WHERE id = ?",
                        [$clean, $providerId]
                    );
                } else {
                    Database::execute(
                        "UPDATE otp_providers SET last_error = ? WHERE id = ?",
                        [$clean, $providerId]
                    );
                }
            } elseif ($scope === 'smm') {
                Database::execute(
                    "UPDATE providers SET last_error = ? WHERE id = ?",
                    [$clean, $providerId]
                );
            }
        } catch (Throwable $e) {
            Logger::errorFile('ProviderErrorHandler::recordProviderError warning: ' . $e->getMessage());
        }
    }

    /**
     * Handle any Throwable raised during a provider action, log technical details server-side,
     * update provider last_error if applicable, and return a clean client-safe error structure.
     */
    public static function handleException(Throwable $e, array $context = [], array $secrets = []): array
    {
        $rawMsg = self::redact($e->getMessage(), $secrets);
        $scope = (string)($context['scope'] ?? 'provider');
        $providerId = (int)($context['provider_id'] ?? 0);
        $action = (string)($context['action'] ?? 'unknown');

        Logger::errorFile(sprintf(
            '[%s:%s provider=%d] %s in %s:%d — %s',
            $scope,
            $action,
            $providerId,
            get_class($e),
            basename($e->getFile()),
            $e->getLine(),
            $rawMsg
        ));

        Logger::workflowLog('provider_error', "Exception during {$scope} {$action}", array_merge($context, [
            'exception' => get_class($e),
            'file'      => basename($e->getFile()),
            'line'      => $e->getLine(),
            'message'   => substr($rawMsg, 0, 400),
        ]));

        // Map known exception categories to actionable, non-leaking messages
        $category = 'internal';
        $errorCode = 'PROVIDER_INTERNAL_ERROR';
        $httpStatus = 422;
        $userMessage = 'An unexpected error occurred while processing the provider action. Check system logs for details.';

        if ($e instanceof PDOException) {
            $sqlState = (string)$e->getCode();
            $category = 'database';
            $errorCode = 'DATABASE_ERROR';
            if ($sqlState === '23000' || str_contains($rawMsg, '1062') || str_contains(strtolower($rawMsg), 'duplicate entry')) {
                $errorCode = 'DUPLICATE_RECORD';
                $httpStatus = 409;
                $userMessage = 'A record with the same unique identifier or slug already exists.';
            } elseif (str_contains($rawMsg, '1451') || str_contains(strtolower($rawMsg), 'foreign key constraint')) {
                $errorCode = 'RECORD_IN_USE';
                $httpStatus = 409;
                $userMessage = 'This provider is linked to existing orders or activations and cannot be deleted. Disable it instead.';
            } elseif (str_contains($rawMsg, '42S02') || str_contains($rawMsg, '42S22')) {
                $errorCode = 'SCHEMA_MISMATCH';
                $httpStatus = 500;
                $userMessage = 'Database schema mismatch detected. Please run the latest database migrations.';
            } else {
                $httpStatus = 500;
                $userMessage = 'Database error occurred while saving provider data. Technical details have been logged.';
            }
        } elseif ($e instanceof InvalidArgumentException) {
            $category = 'config';
            $errorCode = 'INVALID_CONFIG';
            $httpStatus = 400;
            $userMessage = $rawMsg !== '' ? $rawMsg : 'Invalid provider configuration or parameters.';
        } elseif ($e instanceof RuntimeException) {
            $category = 'config';
            $errorCode = 'ADAPTER_ERROR';
            $httpStatus = 422;
            $userMessage = $rawMsg !== '' ? $rawMsg : 'Provider adapter could not execute this action.';
        }

        if ($providerId > 0 && in_array($scope, ['otp', 'smm'], true)) {
            self::recordProviderError($scope, $providerId, $userMessage, false, $secrets);
        }

        return [
            'ok'             => false,
            'error'          => $userMessage,
            'error_code'     => $errorCode,
            'error_category' => $category,
            'http_status'    => $httpStatus,
        ];
    }

    /**
     * Send a consistent JSON error response after clearing any stray output buffers.
     */
    public static function jsonError(
        string $message,
        string $errorCode = 'PROVIDER_ERROR',
        int $httpStatus = 200,
        array $extra = []
    ): never {
        self::cleanOutputBuffers();
        $payload = array_merge([
            'ok'         => false,
            'error'      => self::redact($message),
            'error_code' => $errorCode,
        ], $extra);
        Helpers::jsonResponse($payload, $httpStatus);
    }

    /**
     * Send a consistent JSON success response after clearing any stray output buffers.
     */
    public static function jsonSuccess(array $data = [], int $httpStatus = 200): never
    {
        self::cleanOutputBuffers();
        Helpers::jsonResponse(array_merge(['ok' => true], $data), $httpStatus);
    }
}
