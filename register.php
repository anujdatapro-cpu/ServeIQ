<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/validation.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/email_verification.php';
require __DIR__ . '/config/email.php';

if (isLoggedIn()) {
    $role = getUserRole();
    header('Location: ' . ($role === 'customer' ? 'customer/dashboard.php' : ($role === 'provider' ? 'provider/dashboard.php' : 'admin/dashboard.php')));
    exit;
}

$errors = [];
$email = '';
$fullName = '';
$role = in_array((string)($_GET['role'] ?? ''), ['customer', 'provider'], true)
    ? (string)$_GET['role']
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';
    
    $errors = array_values(validateRegistration([
        'full_name' => $fullName,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $confirmPassword,
        'role' => $role,
    ]));
    
    if (empty($errors)) {
        try {
            $pdo = getDatabaseConnection();
            
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'This email is already registered. Please log in or use a different email.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('
                    INSERT INTO users (name, email, password, role, is_email_verified)
                    VALUES (?, ?, ?, ?, 0)
                ');
                $stmt->execute([$fullName, $email, $passwordHash, $role]);
                $userId = (int)$pdo->lastInsertId();
                $delivery = issueEmailOtp($pdo, createEmailService(), $userId, $email);
                $pdo->commit();

                writeAuditLog($pdo, 'registration', 'user', $userId, null, ['role' => $role, 'email_verified' => false], $userId, $role);
                regenerateSessionId();
                $_SESSION['pending_verification_user_id'] = $userId;
                $_SESSION['pending_verification_email'] = $email;
                if ($delivery['preview_code'] !== null) {
                    $_SESSION['development_otp_preview'] = $delivery['preview_code'];
                }
                if (!$delivery['sent']) {
                    $_SESSION['flash_error'] = $delivery['error'];
                }

                header('Location: verify.php');
                exit;
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Registration failed. Please try again later.';
            error_log($e->getMessage());
        }
    }
}

$pageTitle = 'Register | ServeIQ';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <div class="container">
        <aside class="auth-story" aria-label="About the ServeIQ service workflow"><a class="brand-mark" href="index.php"><span class="brand-symbol"><i class="bi bi-stars"></i></span><span>Serve<span class="brand-accent">IQ</span></span></a><span class="section-kicker">A clearer way to find help</span><h2>From a real-world problem to the right local service.</h2><p>Describe what is happening. ServeIQ organizes the details and helps you compare relevant providers.</p><div class="auth-story-flow"><span>PROBLEM</span><i class="bi bi-arrow-down"></i><span>ServiceDNA</span><i class="bi bi-arrow-down"></i><span>PROVIDER MATCH</span></div></aside>
        <div class="auth-panel">
            <div class="auth-header">
                <span class="section-kicker">Join ServeIQ</span>
                <h1 class="auth-title">Create your account</h1>
                <p class="auth-subtitle">Choose your role and join our service community</p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin-bottom: 0; margin-top: 0.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>" minlength="2" maxlength="120" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" maxlength="190" required>
                </div>
                
                <div class="form-group">
                    <label for="role" class="form-label">I am a</label>
                    <select id="role" name="role" class="form-select" required>
                        <option value="">-- Select Role --</option>
                        <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>Customer (Looking for services)</option>
                        <option value="provider" <?= $role === 'provider' ? 'selected' : '' ?>>Service Provider</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" data-strong-password required>
                    <small class="form-text text-muted">At least 8 characters, including uppercase, lowercase, a number, and a special character.</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" data-password-confirm="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill">Create Account</button>
            </form>
            
            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Log in here</a></p>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
