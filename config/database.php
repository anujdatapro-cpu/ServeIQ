<?php
declare(strict_types=1);

function environmentValue(string $name, string $fallback): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $fallback : $value;
}

// Environment variables are used when available; defaults keep XAMPP/Laragon setup simple.
define('DB_HOST', environmentValue('DB_HOST', '127.0.0.1'));
define('DB_PORT', environmentValue('DB_PORT', '3306'));
define('DB_NAME', environmentValue('DB_NAME', 'serveiq_db'));
define('DB_USER', environmentValue('DB_USER', 'root'));
define('DB_PASS', environmentValue('DB_PASS', ''));

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}