<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class ResendEmailService implements EmailServiceInterface
{
    public function sendVerificationCode(string $recipient, string $code): array
    {
        $apiKey = getEnvVar('RESEND_API_KEY');
        $from = getEnvVar('MAIL_FROM', getEnvVar('RESEND_FROM_EMAIL', 'onboarding@resend.dev'));

        if ($apiKey === '') {
            error_log('[ServeIQ Email Error] Resend provider failed: RESEND_API_KEY is missing or empty.');
            return ['sent' => false, 'preview_code' => null];
        }

        if (!function_exists('curl_init')) {
            error_log('[ServeIQ Email Error] Resend provider failed: PHP cURL extension is not enabled.');
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            $sanitizedResponse = preg_replace('/"key"\s*:\s*"[^"]+"/', '"key":"***"', (string)$response);
            error_log(sprintf(
                '[ServeIQ Email Error] Resend API delivery failed (HTTP %d). Response: %s | cURL Error (%d): %s | Sender: %s | Recipient: %s',
                $httpCode,
                $sanitizedResponse,
                $curlErrno,
                $curlError,
                $from,
                $recipient
            ));

            if ($httpCode === 403 && str_contains((string)$response, 'only send testing emails')) {
                error_log('[ServeIQ Email Notice] Resend testing domain (onboarding@resend.dev) restricts delivery to the Resend account owner\'s mailbox only. To send to arbitrary recipients, set MAIL_FROM to an address on a verified domain at resend.com/domains.');
            }

            return ['sent' => false, 'preview_code' => null];
        }

        return ['sent' => true, 'preview_code' => null];
    }
}
