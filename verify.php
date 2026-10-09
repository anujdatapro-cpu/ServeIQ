<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/email.php';
require __DIR__ . '/includes/email_verification.php';
require __DIR__ . '/includes/audit.php';

$userId = (int)($_SESSION['pending_verification_user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: register.php');
    exit;
}

$errors = [];
$notice = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        $pdo = getDatabaseConnection();
        if (($_POST['action'] ?? '') === 'resend') {
            $result = resendEmailOtp($pdo, createEmailService(), $userId);
            if (!$result['sent']) {
                $errors[] = (string)$result['error'];
            } else {
                if (!empty($result['preview_code'])) {
                    $_SESSION['development_otp_preview'] = $result['preview_code'];
                } else {
                    unset($_SESSION['development_otp_preview']);
                }
                $notice = 'A new verification code has been sent to your email address.';
            }
        } else {
            $result = verifyEmailOtp($pdo, $userId, trim((string)($_POST['otp'] ?? '')));
            if ($result === 'verified') {
                writeAuditLog($pdo, 'email_verification', 'user', $userId, null, ['verified' => true], $userId, null);
                $isOtpLogin = !empty($_SESSION['otp_login_flow']);
                unset($_SESSION['pending_verification_user_id'], $_SESSION['pending_verification_email'], $_SESSION['development_otp_preview'], $_SESSION['otp_login_flow']);

                if ($isOtpLogin) {
                    $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();
                    if ($user) {
                        regenerateSessionId();
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        header('Location: ' . ($user['role'] === 'customer' ? 'customer/dashboard.php' : ($user['role'] === 'provider' ? 'provider/dashboard.php' : 'admin/dashboard.php')));
                        exit;
                    }
                }

                $_SESSION['flash_success'] = 'Your email is verified. You can now log in.';
                header('Location: login.php');
                exit;
            }
            $messages = [
                'invalid' => 'That code is invalid. Check it and try again.',
                'expired' => 'That code has expired. Request a new code.',
                'locked' => 'Too many incorrect attempts. Request a new code to continue.',
                'missing' => 'No active code was found. Request a new code.',
            ];
            $errors[] = $messages[$result] ?? 'Verification could not be completed. Please try again.';
        }
    } catch (Throwable $e) {
        error_log('ServeIQ email verification failed: ' . $e->getMessage());
        $errors[] = 'Verification is temporarily unavailable. Please try again shortly.';
    }
}

$pageTitle = 'Verify email | ServeIQ';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>
<main class="auth-page">
    <div class="container">
        <aside class="auth-story" aria-label="About the ServeIQ service workflow">
            <a class="brand-mark" href="index.php"><span class="brand-symbol"><i class="bi bi-stars"></i></span><span>Serve<span class="brand-accent">IQ</span></span></a>
            <span class="section-kicker">A clearer way to find help</span>
            <h2>From a real-world problem to the right local service.</h2>
            <p>Describe what is happening. ServeIQ organizes the details and helps you compare relevant providers.</p>
            <div class="auth-story-flow"><span>PROBLEM</span><i class="bi bi-arrow-down"></i><span>SERVICE ANALYSIS</span><i class="bi bi-arrow-down"></i><span>PROVIDER MATCH</span></div>
        </aside>
        <div class="auth-panel">
            <div class="auth-header">
                <span class="section-kicker">One more step</span>
                <h1 class="auth-title">Verify your email</h1>
                <p class="auth-subtitle">Enter the six-digit code sent to <?= htmlspecialchars((string)($_SESSION['pending_verification_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <?php if (!empty($_SESSION['development_otp_preview'])): ?>
                <div class="alert alert-warning border-warning my-3" role="status">
                    <i class="bi bi-code-slash me-1"></i><strong>[Dev Mode Preview]</strong> Your OTP code is:
                    <strong class="fs-5 ms-1 text-dark"><?= htmlspecialchars((string)$_SESSION['development_otp_preview'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            <?php endif; ?>
            <?php if ($notice !== ''): ?><div class="alert alert-info" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="POST" class="auth-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="verify">
                <div class="form-group mb-3">
                    <label for="otp" class="form-label">Verification code</label>
                    <input type="text" id="otp" name="otp" class="form-control" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="Enter 6-digit code" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill">Verify email</button>
            </form>
            <form method="POST" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn btn-outline-secondary w-100 rounded-pill">Resend code</button>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
