<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class BrevoEmailService implements EmailServiceInterface
{
    public function sendVerificationCode(string $recipient, string $code): array
    {
        $apiKey = trim((string)(getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? ($_SERVER['BREVO_API_KEY'] ?? ''))));
        $from = trim((string)(getenv('MAIL_FROM') ?: ($_ENV['MAIL_FROM'] ?? ($_SERVER['MAIL_FROM'] ?? 'noreply@serveiq.com'))));
        $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: ($_ENV['MAIL_FROM_NAME'] ?? ($_SERVER['MAIL_FROM_NAME'] ?? 'ServeIQ Security'))));

        if ($apiKey === '') {
            error_log('ServeIQ Brevo API key is missing in environment (BREVO_API_KEY).');
            return ['sent' => false, 'preview_code' => null, 'error' => 'Email service configuration error.'];
        }

        $url = 'https://api.brevo.com/v3/smtp/email';
        $payload = [
            'sender' => ['name' => $fromName, 'email' => $from],
            'to' => [['email' => $recipient]],
            'subject' => 'Your ServeIQ Email OTP Verification Code',
            'textContent' => "Your ServeIQ verification code is {$code}. It expires in 10 minutes.",
            'htmlContent' => "<p>Your ServeIQ verification code is <strong>{$code}</strong>.</p><p>It expires in 10 minutes.</p>"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            $sanitizedResponse = is_string($response) ? preg_replace('/"api-key":\s*"[^"]*"/', '"api-key":"[REDACTED]"', $response) : '';
            error_log("ServeIQ Brevo email delivery failed for recipient {$recipient} (HTTP {$httpCode}): {$sanitizedResponse} | Error: {$curlError}");
            return ['sent' => false, 'preview_code' => null, 'error' => 'Provider rejected email delivery.'];
        }

        return ['sent' => true, 'preview_code' => null, 'error' => null];
    }
}
