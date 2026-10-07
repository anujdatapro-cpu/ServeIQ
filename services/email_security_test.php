<?php
declare(strict_types=1);

require __DIR__ . '/../includes/email_verification.php';
require __DIR__ . '/DevelopmentPreviewEmailService.php';

$code = generateEmailOtp();
$preview = (new DevelopmentPreviewEmailService())->sendVerificationCode('demo@example.com', $code);
$cases = [
    ['OTP is exactly six decimal digits', (bool)preg_match('/^[0-9]{6}$/', $code)],
    ['development preview returns the generated code without SMTP', $preview['sent'] === true && $preview['preview_code'] === $code],
];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("EMAIL SECURITY TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
