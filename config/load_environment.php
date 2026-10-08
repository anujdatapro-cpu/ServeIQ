<?php
declare(strict_types=1);

/** Load simple KEY=VALUE entries from the project .env without overriding server configuration. */
function loadProjectEnvironment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $environmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($environmentFile) || !is_readable($environmentFile)) {
        return;
    }

    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

loadProjectEnvironment();
require_once __DIR__ . '/app.php';
configureApplicationErrorHandling();
