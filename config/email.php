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
    $appEnvironment = strtolower(getEnvVar('APP_ENV', 'production'));
    $provider = strtolower(getEnvVar('MAIL_PROVIDER', getEnvVar('MAIL_TRANSPORT', 'smtp')));

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