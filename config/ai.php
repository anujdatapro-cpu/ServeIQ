<?php
declare(strict_types=1);

if (!function_exists('aiEnvironmentValue')) {
    function aiEnvironmentValue(string $name, string $fallback): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $fallback : $value;
    }
}

// AI Configuration Constants
if (!defined('SERVEIQ_AI_ENABLED')) {
    $aiEnabledEnv = aiEnvironmentValue('SERVEIQ_AI_ENABLED', aiEnvironmentValue('AI_ENABLED', 'true'));
    define('SERVEIQ_AI_ENABLED', filter_var($aiEnabledEnv, FILTER_VALIDATE_BOOLEAN));
}

if (!defined('SERVEIQ_AI_PROVIDER')) {
    define('SERVEIQ_AI_PROVIDER', aiEnvironmentValue('SERVEIQ_AI_PROVIDER', aiEnvironmentValue('AI_PROVIDER', 'local')));
}

if (!defined('SERVEIQ_AI_TIMEOUT_MS')) {
    define('SERVEIQ_AI_TIMEOUT_MS', (int)aiEnvironmentValue('SERVEIQ_AI_TIMEOUT_MS', '3000'));
}

if (!defined('SERVEIQ_AI_MAX_QUESTIONS')) {
    define('SERVEIQ_AI_MAX_QUESTIONS', (int)aiEnvironmentValue('SERVEIQ_AI_MAX_QUESTIONS', '3'));
}
