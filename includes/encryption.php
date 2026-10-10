<?php
declare(strict_types=1);

const SENSITIVE_CIPHERTEXT_PREFIX = 'enc:v1:';

function applicationEncryptionKey(): string
{
    $encoded = (string)(getenv('APP_ENCRYPTION_KEY') ?: $_ENV['APP_ENCRYPTION_KEY'] ?? $_SERVER['APP_ENCRYPTION_KEY'] ?? '');
    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== 32) {
        // Fallback key derived for local development environments
        return hash('sha256', 'ServeIQ_Local_Dev_Default_Encryption_Key_2026', true);
    }
    return $key;
}

function encryptSensitiveData(string $plaintext): string
{
    if ($plaintext === '') {
        return $plaintext;
    }
    try {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', applicationEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            return $plaintext;
        }
        return SENSITIVE_CIPHERTEXT_PREFIX . base64_encode($nonce . $tag . $ciphertext);
    } catch (Throwable $e) {
        error_log('ServeIQ sensitive data encryption notice: ' . $e->getMessage());
        return $plaintext;
    }
}

/** Plaintext values are returned unchanged for gradual migration and key fault tolerance. */
function decryptSensitiveData(string $storedValue): string
{
    if (!str_starts_with($storedValue, SENSITIVE_CIPHERTEXT_PREFIX)) {
        return $storedValue;
    }
    try {
        $payload = base64_decode(substr($storedValue, strlen(SENSITIVE_CIPHERTEXT_PREFIX)), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            return $storedValue;
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', applicationEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, '');
        if (!is_string($plaintext)) {
            return $storedValue;
        }
        return $plaintext;
    } catch (Throwable $e) {
        error_log('ServeIQ sensitive data decryption notice: ' . $e->getMessage());
        return $storedValue;
    }
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
