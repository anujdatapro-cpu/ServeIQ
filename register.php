<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $role = getUserRole();
    header('Location: ' . ($role === 'customer' ? 'customer/dashboard.php' : ($role === 'provider' ? 'provider/dashboard.php' : 'admin/dashboard.php')));
    exit;
}

$errors = [];
$email = '';
$fullName = '';
$role = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';
    
    if (empty($fullName)) {
        $errors[] = 'Full name is required.';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (empty($role) || !in_array($role, ['customer', 'provider'], true)) {
        $errors[] = 'Please select a valid role.';
    }
    
    if (empty($errors)) {
        try {
            $pdo = getDatabaseConnection();
            
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'This email is already registered. Please log in or use a different email.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare('
                    INSERT INTO users (name, email, password, role)
                    VALUES (?, ?, ?, ?)
                ');
                $stmt->execute([$fullName, $email, $passwordHash, $role]);
                
                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['user_name'] = $fullName;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $role;
                
                setcookie('serveiq_email', $email, time() + (30 * 24 * 60 * 60), applicationBasePath() . '/');
                
                header('Location: ' . ($role === 'customer' ? 'customer/dashboard.php' : 'provider/dashboard.php'));
                exit;
            }
        } catch (Exception $e) {
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
            
            <form method="POST" class="auth-form" novalidate>
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
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
                    <input type="password" id="password" name="password" class="form-control" required>
                    <small class="form-text text-muted">At least 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
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