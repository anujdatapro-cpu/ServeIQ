<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class BrevoEmailService implements EmailServiceInterface
{
    private ?\Closure $mockRequest;

    public function __construct(?\Closure $mockRequest = null)
    {
        $this->mockRequest = $mockRequest;
    }

    public function sendVerificationCode(string $recipient, string $code): array
    {
        $apiKey = getEnvVar('BREVO_API_KEY');
        $fromEmail = getEnvVar('MAIL_FROM', getEnvVar('BREVO_FROM_EMAIL', 'no-reply@serveiq.local'));
        $fromName = getEnvVar('SMTP_FROM_NAME', 'ServeIQ');

        if ($apiKey === '') {
            error_log('ServeIQ Brevo API key is missing or empty.');
            return ['sent' => false, 'preview_code' => null];
        }

        $url = 'https://api.brevo.com/v3/smtp/email';
        $payload = [
            'sender' => ['name' => $fromName, 'email' => $fromEmail],
            'to' => [['email' => $recipient]],
            'subject' => 'Your ServeIQ Email OTP Verification Code',
            'textContent' => "Your ServeIQ verification code is {$code}. It expires in 10 minutes. If you did not request this, please ignore this email.",
            'htmlContent' => "<p>Your ServeIQ verification code is <strong>{$code}</strong>.</p><p>It expires in 10 minutes.</p>"
        ];

        $result = $this->postJson($url, [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ], $payload);
        $httpCode = $result['http_code'];
        $curlError = $result['curl_error'];

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            error_log("ServeIQ Brevo email delivery failed (HTTP {$httpCode}); cURL error: {$curlError}");
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
