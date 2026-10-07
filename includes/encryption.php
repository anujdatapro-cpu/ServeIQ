<?php
declare(strict_types=1);

const SENSITIVE_CIPHERTEXT_PREFIX = 'enc:v1:';

function applicationEncryptionKey(): string
{
    $encoded = (string)(getenv('APP_ENCRYPTION_KEY') ?: '');
    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
    }
    return $key;
}

function encryptSensitiveData(string $plaintext): string
{
    if ($plaintext === '') {
        return $plaintext;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', applicationEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Sensitive data encryption failed.');
    }
    return SENSITIVE_CIPHERTEXT_PREFIX . base64_encode($nonce . $tag . $ciphertext);
}

/** Plaintext values are returned unchanged only for gradual migration compatibility. */
function decryptSensitiveData(string $storedValue): string
{
    if (!str_starts_with($storedValue, SENSITIVE_CIPHERTEXT_PREFIX)) {
        return $storedValue;
    }
    $payload = base64_decode(substr($storedValue, strlen(SENSITIVE_CIPHERTEXT_PREFIX)), true);
    if (!is_string($payload) || strlen($payload) < 29) {
        throw new RuntimeException('Encrypted field is malformed.');
    }
    $nonce = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', applicationEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '');
    if (!is_string($plaintext)) {
        throw new RuntimeException('Encrypted field authentication failed.');
    }
    return $plaintext;
}

function decryptSensitiveFields(array $row, array $fields): array
{
    foreach ($fields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = decryptSensitiveData($row[$field]);
        }
    }
    return $row;
}
