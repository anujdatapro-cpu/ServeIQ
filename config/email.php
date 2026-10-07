<?php
declare(strict_types=1);

require_once __DIR__ . '/load_environment.php';

require_once __DIR__ . '/../services/EmailServiceInterface.php';
require_once __DIR__ . '/../services/DevelopmentPreviewEmailService.php';
require_once __DIR__ . '/../services/SmtpEmailService.php';

function createEmailService(): EmailServiceInterface
{
    $appEnvironment = strtolower((string)(getenv('APP_ENV') ?: 'production'));
    $transport = strtolower((string)(getenv('MAIL_TRANSPORT') ?: 'smtp'));

    if ($appEnvironment === 'development' && $transport === 'development_preview') {
        return new DevelopmentPreviewEmailService();
    }
    return new SmtpEmailService();
}
