<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/login_throttle.php';
require __DIR__ . '/includes/validation.php';
require __DIR__ . '/includes/email_verification.php';
require __DIR__ . '/config/email.php';

if (isLoggedIn()) {
    $role = getUserRole();
    header('Location: ' . ($role === 'customer' ? 'customer/dashboard.php' : ($role === 'provider' ? 'provider/dashboard.php' : 'admin/dashboard.php')));
    exit;
}

$errors = [];
$email = '';
$redirectTarget = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$problemText = mb_substr((string)($_GET['problem'] ?? $_POST['problem'] ?? ''), 0, 5000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required.';
    }
    
    if (empty($errors)) {
        try {
            $pdo = getDatabaseConnection();
            $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

            if (loginIsThrottled($pdo, $email, $clientIp)) {
                writeAuditLog($pdo, 'login_throttled', 'authentication', null, null, ['identifier_hash' => loginThrottleHashes($email, $clientIp)[0]], null, null);
                $errors[] = 'Invalid email or password. Please wait 15 minutes before trying again.';
            } else {
                $stmt = $pdo->prepare('SELECT id, name, email, password, role, is_email_verified FROM users WHERE email = ? AND role IN ("customer", "provider", "admin")');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    clearFailedLogins($pdo, $email, $clientIp);
                    if ((int)$user['is_email_verified'] !== 1) {
                        regenerateSessionId();
                        $_SESSION['pending_verification_user_id'] = (int)$user['id'];
                        $_SESSION['pending_verification_email'] = (string)$user['email'];
                        header('Location: verify.php');
                        exit;
                    }
                    regenerateSessionId();
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];

                    writeAuditLog($pdo, 'login_success', 'user', (int)$user['id'], null, null, (int)$user['id'], (string)$user['role']);

                    if (preg_match('/^customer\/create_request\.php$/', $redirectTarget)) {
                        $destination = $redirectTarget;
                        if ($problemText !== '') {
                            $destination .= '?description=' . rawurlencode($problemText);
                        }
                        header('Location: ' . $destination);
                    } else {
                        header('Location: ' . ($user['role'] === 'customer' ? 'customer/dashboard.php' : ($user['role'] === 'provider' ? 'provider/dashboard.php' : 'admin/dashboard.php')));
                    }
                    exit;
                }

                recordFailedLogin($pdo, $email, $clientIp);
                writeAuditLog($pdo, 'login_failure', 'authentication', null, null, ['identifier_hash' => loginThrottleHashes($email, $clientIp)[0]], null, null);
                $errors[] = 'Invalid email or password. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Login failed. Please try again later.';
            error_log($e->getMessage());
        }
    }
}

$sessionNotice = (string)($_SESSION['flash_error'] ?? '');
$successNotice = (string)($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error']);
unset($_SESSION['flash_success']);

$pageTitle = 'Log in | ServeIQ';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <div class="container">
        <aside class="auth-story" aria-label="About the ServeIQ service workflow"><a class="brand-mark" href="index.php"><span class="brand-symbol"><i class="bi bi-stars"></i></span><span>Serve<span class="brand-accent">IQ</span></span></a><span class="section-kicker">A clearer way to find help</span><h2>From a real-world problem to the right local service.</h2><p>Describe what is happening. ServeIQ organizes the details and helps you compare relevant providers.</p><div class="auth-story-flow"><span>PROBLEM</span><i class="bi bi-arrow-down"></i><span>ServiceDNA</span><i class="bi bi-arrow-down"></i><span>PROVIDER MATCH</span></div></aside>
        <div class="auth-panel">
            <div class="auth-header">
                <span class="section-kicker">Welcome back</span>
                <h1 class="auth-title">Log in to ServeIQ</h1>
                <p class="auth-subtitle">Continue your service journey</p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <strong>Login failed:</strong>
                    <ul style="margin-bottom: 0; margin-top: 0.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($sessionNotice !== ''): ?>
                <div class="alert alert-warning" role="status"><?= htmlspecialchars($sessionNotice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($successNotice !== ''): ?>
                <div class="alert alert-success" role="status"><?= htmlspecialchars($successNotice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="problem" value="<?= htmlspecialchars($problemText, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill">Log In</button>
            </form>
            
            <div class="auth-footer">
                <p>Don't have an account? <a href="register.php">Create one here</a></p>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
