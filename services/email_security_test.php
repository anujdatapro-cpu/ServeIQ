<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/load_environment.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/email_verification.php';

$code = generateEmailOtp();
$preview = (new DevelopmentPreviewEmailService())->sendVerificationCode('demo@example.com', $code);

// Test getEnvVar behavior
$_ENV['TEST_KEY_ENV'] = 'from_env';
$_SERVER['TEST_KEY_SERVER'] = 'from_server';
putenv('TEST_KEY_GETENV=from_getenv');

$envTest1 = getEnvVar('TEST_KEY_ENV') === 'from_env';
$envTest2 = getEnvVar('TEST_KEY_SERVER') === 'from_server';
$envTest3 = getEnvVar('TEST_KEY_GETENV') === 'from_getenv';
$envTest4 = getEnvVar('NON_EXISTENT_KEY', 'default_val') === 'default_val';

// Test empty environment override ignore
putenv('TEST_KEY_EMPTY=');
$_ENV['TEST_KEY_EMPTY'] = 'valid_value';
$envTest5 = getEnvVar('TEST_KEY_EMPTY') === 'valid_value';

// Test createEmailService provider selection
putenv('MAIL_PROVIDER=resend');
$_ENV['MAIL_PROVIDER'] = 'resend';
$resendService = createEmailService();
$isResendService = ($resendService instanceof ResendEmailService);

// Test Resend missing API key handling
putenv('RESEND_API_KEY=');
$_ENV['RESEND_API_KEY'] = '';
$_SERVER['RESEND_API_KEY'] = '';
$resendDeliveryFail = $resendService->sendVerificationCode('user@example.com', '123456');
$missingKeyHandled = ($resendDeliveryFail['sent'] === false);

$cases = [
    ['OTP is exactly six decimal digits', (bool)preg_match('/^[0-9]{6}$/', $code)],
    ['Development preview returns generated code', $preview['sent'] === true && $preview['preview_code'] === $code],
    ['getEnvVar reads from $_ENV', $envTest1],
    ['getEnvVar reads from $_SERVER', $envTest2],
    ['getEnvVar reads from getenv()', $envTest3],
    ['getEnvVar returns default value for missing keys', $envTest4],
    ['getEnvVar ignores empty getenv values in favor of non-empty $_ENV', $envTest5],
    ['createEmailService selects ResendEmailService when MAIL_PROVIDER=resend', $isResendService],
    ['ResendEmailService safely handles missing API key', $missingKeyHandled],
];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("EMAIL SECURITY & CONFIG TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
