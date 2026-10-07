<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

/** Controlled local-only preview; never enabled unless APP_ENV=development explicitly. */
final class DevelopmentPreviewEmailService implements EmailServiceInterface
{
    public function sendVerificationCode(string $recipient, string $code): array
    {
        return ['sent' => true, 'preview_code' => $code];
    }
}
