<?php
declare(strict_types=1);

require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/login_throttle.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';

$cases = [
    ['valid registration accepted', validateRegistration(['full_name' => 'Asha Rao', 'email' => 'asha@example.com', 'password' => 'ServeIQ#2026', 'confirm_password' => 'ServeIQ#2026', 'role' => 'customer']) === []],
    ['blank name rejected', !validateName('   ')],
    ['single-character name rejected', !validateName('A')],
    ['name over 120 characters rejected', !validateName(str_repeat('A', 121))],
    ['valid email accepted', validateEmail('person@example.in')],
    ['malformed email rejected', !validateEmail('not-an-email')],
    ['valid Indian phone accepted', validatePhone('9876543210')],
    ['short phone rejected', !validatePhone('12345')],
    ['alphabetic phone rejected', !validatePhone('abcdefghij')],
    ['phone with country prefix rejected', !validatePhone('+919876543210')],
    ['strong password accepted', validatePassword('Strong#Pass9')],
    ['weak password rejected', !validatePassword('password')],
    ['password mismatch rejected', isset(validateRegistration(['full_name' => 'Asha Rao', 'email' => 'asha@example.com', 'password' => 'ServeIQ#2026', 'confirm_password' => 'Different#2026', 'role' => 'customer'])['confirm_password'])],
    ['unsupported registration role rejected', isset(validateRegistration(['full_name' => 'Asha Rao', 'email' => 'asha@example.com', 'password' => 'ServeIQ#2026', 'confirm_password' => 'ServeIQ#2026', 'role' => 'admin'])['role'])],
    ['email throttle hash normalizes case and whitespace', loginThrottleHashes(' Asha@Example.com ', '127.0.0.1')[0] === loginThrottleHashes('asha@example.com', '127.0.0.1')[0]],
    ['different IP receives a distinct throttle key', loginThrottleHashes('asha@example.com', '127.0.0.1')[1] !== loginThrottleHashes('asha@example.com', '127.0.0.2')[1]],
    ['meaningful problem description accepted', validateProblemDescription('My laptop overheats after gaming for twenty minutes.')],
    ['repeated meaningless problem description rejected', !validateProblemDescription(str_repeat('x', 30))],
    ['service price accepts two decimal places', validateServiceData(['service_name' => 'Laptop Repair', 'description' => 'Hardware diagnosis and repair', 'base_price' => '1250.50', 'is_active' => '1']) === []],
    ['service price rejects excess decimal precision', isset(validateServiceData(['service_name' => 'Laptop Repair', 'description' => 'Hardware diagnosis and repair', 'base_price' => '12.345', 'is_active' => '1'])['base_price'])],
    ['review rating 1 to 5 accepted', validateReviewData(5, 'Helpful and professional service.') === []],
    ['out-of-range review rating rejected', isset(validateReviewData(6, 'Fine.')['rating'])],
    ['past booking date rejected', isset(validateBookingData(['scheduled_date' => '2000-01-01', 'scheduled_time' => '10:00', 'notes' => ''])['schedule'])],
    ['provider profile requires valid phone', isset(validateProviderProfile(['business_name' => 'Repair Co', 'phone' => '123', 'address' => '12 Main Street', 'city' => 'Pune', 'area' => '', 'experience_years' => 3, 'description' => '', 'availability_status' => 'available'])['phone'])],
    ['legacy numeric-string session ID safely resolves to integer', (static function (): bool { $_SESSION = ['user_id' => '42', 'user_role' => 'customer']; return isLoggedIn() && getUserId() === 42; })()],
    ['invalid role is not authenticated', (static function (): bool { $_SESSION = ['user_id' => 42, 'user_role' => 'root']; return !isLoggedIn() && getUserRole() === null; })()],
    ['valid CSRF token matches', csrfTokensMatch('token-value', 'token-value')],
    ['incorrect CSRF token is rejected', !csrfTokensMatch('attacker-value', 'token-value')],
    ['missing CSRF token is rejected', !csrfTokensMatch('', 'token-value')],
    ['category name and description accepted within limits', validateCategoryData(['category_name' => 'Electrical Services', 'description' => str_repeat('a', 5000)]) === []],
    ['category name over database column limit rejected', isset(validateCategoryData(['category_name' => str_repeat('a', 121), 'description' => ''])['category_name'])],
    ['blank category name rejected', isset(validateCategoryData(['category_name' => '  ', 'description' => ''])['category_name'])],
    ['category description over limit rejected', isset(validateCategoryData(['category_name' => 'Electrical', 'description' => str_repeat('a', 5001)])['description'])],
    ['moderation note at limit accepted', validateModerationNote(str_repeat('a', 2000))],
    ['moderation note over limit rejected', !validateModerationNote(str_repeat('a', 2001))],
];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("SECURITY HELPER TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
