<?php
declare(strict_types=1);

interface EmailServiceInterface
{
    /** @return array{sent: bool, preview_code: ?string} */
    public function sendVerificationCode(string $recipient, string $code): array;
}
