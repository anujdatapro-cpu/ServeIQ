<?php
declare(strict_types=1);

function configureApplicationErrorHandling(): void
{
    static $configured = false;
    if ($configured) {
        return;
    }
    $configured = true;

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        error_log(sprintf('ServeIQ PHP error [%d] %s at %s:%d', $severity, $message, basename($file), $line));
        return true;
    });

    set_exception_handler(static function (Throwable $exception): void {
        error_log('ServeIQ uncaught exception: ' . $exception);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "An unexpected application error occurred. Details were written to the PHP error log.\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo 'ServeIQ could not complete this request. Please try again later.';
    });
}
