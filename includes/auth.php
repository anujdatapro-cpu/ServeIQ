<?php
declare(strict_types=1);

// Authorization helpers
// Reusable role and authentication checks

function isLoggedIn(): bool
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $role = $_SESSION['user_role'] ?? null;
    return $userId !== false && $userId !== null && $userId > 0
        && is_string($role) && in_array($role, ['customer', 'provider', 'admin'], true);
}

function getUserRole(): ?string
{
    $role = $_SESSION['user_role'] ?? null;
    return is_string($role) && in_array($role, ['customer', 'provider', 'admin'], true) ? $role : null;
}

function getUserId(): ?int
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    return $userId !== false && $userId !== null && $userId > 0 ? $userId : null;
}

function applicationBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = dirname($scriptName);
    $lastDirectory = basename($directory);

    if (in_array($lastDirectory, ['customer', 'provider', 'admin'], true)) {
        $directory = dirname($directory);
    }

    $directory = trim(str_replace('\\', '/', $directory), '/');
    return $directory === '' || $directory === '.' ? '' : '/' . $directory;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . applicationBasePath() . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    
    $userRole = getUserRole();
    if (!in_array($userRole, $roles, true)) {
        http_response_code(403);
        die('Access denied. This page is for ' . implode(', ', $roles) . ' only.');
    }
}

function requireCustomer(): void
{
    requireRole('customer');
}

function requireProvider(): void
{
    requireRole('provider');
}

function requireAdmin(): void
{
    requireRole('admin');
}

function logout(): void
{
    if (function_exists('writeAuditLog') && !empty($_SESSION['user_id'])) {
        try {
            writeAuditLog(getDatabaseConnection(), 'logout', 'user', (int)$_SESSION['user_id']);
        } catch (Throwable $e) {
            error_log('ServeIQ audit logging failed: ' . $e->getMessage());
        }
    }
    destroySession();
}
