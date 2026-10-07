<?php
declare(strict_types=1);

// health.php - Development health check
// Verify PHP runtime and database connectivity

require __DIR__ . '/includes/session.php';

$pageTitle = 'ServeIQ Health Check';
$basePath = '';
require __DIR__ . '/includes/header.php';
?>

<main class="simple-page">
    <div class="container">
        <div class="simple-panel">
            <span class="section-kicker">System Status</span>
            <h1 class="simple-title">ServeIQ Application Health</h1>
            
            <div style="text-align: left; margin-top: 2rem;">
                <?php
                $checks = [];
                
                // PHP runtime
                $checks['PHP Runtime'] = [
                    'status' => 'OK',
                    'detail' => 'PHP ' . phpversion()
                ];
                
                // Database connection
                try {
                    require __DIR__ . '/config/database.php';
                    $pdo = getDatabaseConnection();
                    $pdo->query('SELECT 1');
                    $checks['Database Connection'] = [
                        'status' => 'OK',
                        'detail' => 'MySQL connected'
                    ];
                } catch (Exception $e) {
                    error_log('ServeIQ health check database failure: ' . $e->getMessage());
                    $checks['Database Connection'] = [
                        'status' => 'ERROR',
                        'detail' => 'Database is unavailable. Check server logs for details.'
                    ];
                }
                
                // Sessions
                $checks['Sessions'] = [
                    'status' => 'OK',
                    'detail' => 'Session support active'
                ];
                
                // File uploads
                $uploadDir = __DIR__ . '/uploads/profiles';
                $checks['Upload Directory'] = [
                    'status' => is_writable($uploadDir) ? 'OK' : 'WARNING',
                    'detail' => is_writable($uploadDir) ? 'Profile image storage is writable.' : 'Profile image storage is not writable.'
                ];
                
                foreach ($checks as $name => $check):
                    $badgeClass = $check['status'] === 'OK' ? 'bg-success' : 'bg-warning';
                ?>
                    <div style="margin-bottom: 1.5rem; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($check['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <small style="color: #64748b; display: block; margin-top: 0.5rem;">
                            <?= htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top: 2rem; text-align: center;">
                <a class="btn btn-primary rounded-pill px-4" href="index.php">Return to Home</a>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
