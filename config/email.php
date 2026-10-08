<?php
declare(strict_types=1);

require_once __DIR__ . '/load_environment.php';

require_once __DIR__ . '/../services/EmailServiceInterface.php';
require_once __DIR__ . '/../services/DevelopmentPreviewEmailService.php';
require_once __DIR__ . '/../services/SmtpEmailService.php';
require_once __DIR__ . '/../services/ResendEmailService.php';
require_once __DIR__ . '/../services/BrevoEmailService.php';

function createEmailService(): EmailServiceInterface
{
    $appEnvironment = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? 'production'))));
    $provider = strtolower(trim((string)(getenv('MAIL_PROVIDER') ?: ($_ENV['MAIL_PROVIDER'] ?? ($_SERVER['MAIL_PROVIDER'] ?? getenv('MAIL_TRANSPORT') ?: ($_ENV['MAIL_TRANSPORT'] ?? ($_SERVER['MAIL_TRANSPORT'] ?? 'smtp')))))));

    if ($appEnvironment === 'development' && ($provider === 'development_preview' || $provider === 'preview')) {
        return new DevelopmentPreviewEmailService();
    }

    if ($provider === 'resend') {
        return new ResendEmailService();
    }

    if ($provider === 'brevo') {
        return new BrevoEmailService();
    }

    return new SmtpEmailService();
}
