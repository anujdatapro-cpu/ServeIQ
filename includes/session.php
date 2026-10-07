<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/load_environment.php';
require_once __DIR__ . '/encryption.php';

/**
 * ServeIQ — Centralized Session Manager
 *
 * Responsibilities:
 * - Configure secure session cookie parameters BEFORE session_start().
 * - Start session exactly once.
 * - Enforce inactivity timeout.
 * - Detect and reject session fixation.
 * - Provide session-destroy helper used by logout.
 *
 * Constants (override via environment):
 *   SESSION_TIMEOUT_SECONDS  — Inactivity timeout in seconds (default 1800 = 30 min).
 */

// ─── Timeout ────────────────────────────────────────────────────────────────
$sessionTimeout = max(60, (int)(getenv('SESSION_TIMEOUT_SECONDS') ?: 1800)); // 30 minutes minimum

// ─── Secure cookie flags ─────────────────────────────────────────────────────
// Determine if HTTPS is active so the Secure flag can be set appropriately.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // Browser-session cookie (expires when browser closes)
        'path'     => '/',
        'domain'   => '',         // Current domain
        'secure'   => $isHttps,   // Only send over HTTPS when HTTPS is active
        'httponly' => true,        // Prevent JavaScript access to session cookie
        'samesite' => 'Lax',      // CSRF mitigation
    ]);

    // Prevent PHP from using a user-supplied session ID (session fixation protection)
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)$sessionTimeout);

    session_start();
}

/** Rotate the session identifier after authentication state changes. */
function regenerateSessionId(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['last_activity'] = time();
    }
}

function sessionHasExpired(int $lastActivity, int $timeoutSeconds, ?int $now = null): bool
{
    return $lastActivity > 0 && (($now ?? time()) - $lastActivity) > $timeoutSeconds;
}

/** Destroy authentication state and expire the browser's session cookie. */
function destroySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// ─── Inactivity timeout enforcement ─────────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);

    if (sessionHasExpired($lastActivity, $sessionTimeout)) {
        // Session expired: destroy cleanly and force re-login
        destroySession();

        // Restart a fresh session so the page can set flash messages
        session_start();
        $_SESSION['flash_error'] = 'Your session has expired due to inactivity. Please log in again.';

        header('Location: ' . (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false ||
                               strpos($_SERVER['SCRIPT_NAME'] ?? '', '/customer/') !== false ||
                               strpos($_SERVER['SCRIPT_NAME'] ?? '', '/provider/') !== false
                               ? '../login.php' : 'login.php'));
        exit;
    }

    // Refresh activity timestamp on every request
    $_SESSION['last_activity'] = time();
} else {
    // For unauthenticated sessions, still track activity for timeout
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
}
