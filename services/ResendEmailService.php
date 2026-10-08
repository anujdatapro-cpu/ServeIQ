<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class ResendEmailService implements EmailServiceInterface
{
    public function sendVerificationCode(string $recipient, string $code): array
    {
        $apiKey = trim((string)(getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? ($_SERVER['RESEND_API_KEY'] ?? ''))));
        $from = trim((string)(getenv('MAIL_FROM') ?: ($_ENV['MAIL_FROM'] ?? ($_SERVER['MAIL_FROM'] ?? (getenv('RESEND_FROM_EMAIL') ?: ($_ENV['RESEND_FROM_EMAIL'] ?? ($_SERVER['RESEND_FROM_EMAIL'] ?? 'onboarding@resend.dev')))))));

        if ($apiKey === '') {
            error_log('ServeIQ Resend API key is missing in environment (RESEND_API_KEY).');
            return ['sent' => false, 'preview_code' => null, 'error' => 'Email service configuration error.'];
        }

        $url = 'https://api.resend.com/emails';
        $payload = [
            'from' => $from,
            'to' => [$recipient],
            'subject' => 'Your ServeIQ Email OTP Verification Code',
            'text' => "Your ServeIQ verification code is {$code}. It expires in 10 minutes. If you did not request this, please ignore this email.",
            'html' => "<p>Your ServeIQ verification code is <strong>{$code}</strong>.</p><p>It expires in 10 minutes.</p>"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            $sanitizedResponse = is_string($response) ? preg_replace('/"key":\s*"[^"]*"/', '"key":"[REDACTED]"', $response) : '';
            error_log("ServeIQ Resend email delivery failed for recipient {$recipient} (HTTP {$httpCode}): {$sanitizedResponse} | Error: {$curlError}");

            // Check if failure is due to Resend onboarding sender domain restriction
            if ($httpCode === 403 && str_contains((string)$response, 'testing emails')) {
                error_log("ServeIQ Resend Note: onboarding@resend.dev restricts delivery to account owner email. A verified custom domain is required in MAIL_FROM for all recipients.");
            }

            return ['sent' => false, 'preview_code' => null, 'error' => 'Provider rejected email delivery.'];
        }

        return ['sent' => true, 'preview_code' => null, 'error' => null];
    }
}
