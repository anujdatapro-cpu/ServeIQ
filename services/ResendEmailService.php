<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class ResendEmailService implements EmailServiceInterface
{
    private ?\Closure $mockRequest;

    public function __construct(?\Closure $mockRequest = null)
    {
        $this->mockRequest = $mockRequest;
    }

    public function sendVerificationCode(string $recipient, string $code): array
    {
        $apiKey = getEnvVar('RESEND_API_KEY');
        $from = getEnvVar('MAIL_FROM', getEnvVar('RESEND_FROM_EMAIL', 'onboarding@resend.dev'));

        if ($apiKey === '') {
            error_log('[ServeIQ Email Error] Resend provider failed: RESEND_API_KEY is missing or empty.');
            return ['sent' => false, 'preview_code' => null];
        }

        $url = 'https://api.resend.com/emails';
        $payload = [
            'from' => $from,
            'to' => [$recipient],
            'subject' => 'Your ServeIQ Email OTP Verification Code',
            'text' => "Your ServeIQ verification code is {$code}. It expires in 10 minutes. If you did not request this, please ignore this email.",
            'html' => "<p>Your ServeIQ verification code is <strong>{$code}</strong>.</p><p>It expires in 10 minutes.</p>"
        ];

        $result = $this->postJson($url, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ], $payload);
        $response = $result['response'];
        $httpCode = $result['http_code'];
        $curlErrno = $result['curl_errno'];
        $curlError = $result['curl_error'];

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            error_log(sprintf(
                '[ServeIQ Email Error] Resend API delivery failed (HTTP %d; cURL error %d): %s',
                $httpCode,
                $curlErrno,
                $curlError
            ));

            if ($httpCode === 403 && str_contains((string)$response, 'only send testing emails')) {
                error_log('[ServeIQ Email Notice] Resend testing domain (onboarding@resend.dev) restricts delivery to the Resend account owner\'s mailbox only. To send to arbitrary recipients, set MAIL_FROM to an address on a verified domain at resend.com/domains.');
            }

            return ['sent' => false, 'preview_code' => null];
        }

        return ['sent' => true, 'preview_code' => null];
    }

    /** @param list<string> $headers @param array<string, mixed> $payload @return array{response: mixed, http_code: int, curl_errno: int, curl_error: string} */
    private function postJson(string $url, array $headers, array $payload): array
    {
        if ($this->mockRequest !== null) {
            return ($this->mockRequest)($url, $headers, $payload, 15);
        }
        if (!function_exists('curl_init')) {
            return ['response' => false, 'http_code' => 0, 'curl_errno' => 0, 'curl_error' => 'PHP cURL extension is not enabled.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        return ['response' => $response, 'http_code' => $httpCode, 'curl_errno' => $curlErrno, 'curl_error' => $curlError];
    }
}
