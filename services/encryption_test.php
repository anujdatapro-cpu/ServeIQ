<?php
declare(strict_types=1);

putenv('APP_ENCRYPTION_KEY=' . base64_encode(str_repeat('K', 32)));
require __DIR__ . '/../includes/encryption.php';

$cleartext = '9876543210';
$first = encryptSensitiveData($cleartext);
$second = encryptSensitiveData($cleartext);
$tamperRejected = false;
try {
    decryptSensitiveData(substr($first, 0, -2) . 'AA');
} catch (RuntimeException) {
    $tamperRejected = true;
}

$cases = [
    ['AES-GCM value decrypts to original data', decryptSensitiveData($first) === $cleartext],
    ['encrypted value does not contain the plaintext', !str_contains($first, $cleartext)],
    ['encryption uses a fresh nonce', $first !== $second],
    ['tampered ciphertext is rejected', $tamperRejected],
    ['legacy plaintext remains readable during migration', decryptSensitiveData('legacy-address') === 'legacy-address'],
    ['empty optional value stays empty', encryptSensitiveData('') === ''],
];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("ENCRYPTION TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
