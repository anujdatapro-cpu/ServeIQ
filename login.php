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
$redirectTarget = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');
$problemText = (string)($_GET['problem'] ?? $_POST['problem'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);
    
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
            
            $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? AND role IN ("customer", "provider", "admin")');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                
                if ($rememberMe) {
                    setcookie('serveiq_email', $email, time() + (30 * 24 * 60 * 60), applicationBasePath() . '/');
                }
                
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
            } else {
                $errors[] = 'Invalid email or password. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Login failed. Please try again later.';
            error_log($e->getMessage());
        }
    }
}

if (empty($email) && isset($_COOKIE['serveiq_email'])) {
    $email = htmlspecialchars($_COOKIE['serveiq_email'], ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Log in | ServeIQ';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<main class="auth-page">
    <div class="container">
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
                
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                    <label class="form-check-label" for="remember_me">
                        Remember my email
                    </label>
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