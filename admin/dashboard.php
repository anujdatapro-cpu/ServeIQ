<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';

requireAdmin();

try {
    require __DIR__ . '/../config/database.php';
    $pdo = getDatabaseConnection();
    
    $stats = [
        'total_users' => $pdo->query('SELECT COUNT(*) as count FROM users')->fetch()['count'],
        'customers' => $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'customer'")->fetch()['count'],
        'providers' => $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'provider'")->fetch()['count'],
        'service_categories' => $pdo->query('SELECT COUNT(*) as count FROM service_categories')->fetch()['count'],
        'service_requests' => $pdo->query('SELECT COUNT(*) as count FROM service_requests')->fetch()['count'],
        'total_reviews' => $pdo->query('SELECT COUNT(*) as count FROM reviews')->fetch()['count'] ?? 0,
    ];
} catch (Exception $e) {
    $stats = [];
    error_log($e->getMessage());
}

$pageTitle = 'Admin Dashboard | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="dashboard-header">
                    <div>
                        <h1>Admin Panel</h1>
                        <p class="text-muted">Platform management and statistics</p>
                    </div>
                    <div>
                        <form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4" style="margin-top: 2rem;">
            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon blue">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3>Manage Users</h3>
                    <p class="text-muted">View and manage all users on the platform.</p>
                    <a href="users.php" class="btn btn-sm btn-primary">View Users</a>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon cyan">
                        <i class="bi bi-building"></i>
                    </div>
                    <h3>Verify Providers</h3>
                    <p class="text-muted">Approve or reject service provider registrations.</p>
                    <a href="providers.php" class="btn btn-sm btn-primary">View Providers</a>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon green">
                        <i class="bi bi-tag"></i>
                    </div>
                    <h3>Service Categories</h3>
                    <p class="text-muted">Manage available service categories.</p>
                    <a href="categories.php" class="btn btn-sm btn-primary">Manage Categories</a>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon amber">
                        <i class="bi bi-star"></i>
                    </div>
                    <h3>Review Moderation</h3>
                    <p class="text-muted">Inspect customer reviews and manage visibility.</p>
                    <a href="reviews.php" class="btn btn-sm btn-primary">Manage Reviews</a>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon cyan">
                        <i class="bi bi-diagram-3"></i>
                    </div>
                    <h3>ServiceDNA Diagnostics</h3>
                    <p class="text-muted">Inspect structured problem understanding and extraction evidence.</p>
                    <a href="service_dna.php" class="btn btn-sm btn-primary">Inspect ServiceDNA</a>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon amber">
                        <i class="bi bi-bar-chart-steps"></i>
                    </div>
                    <h3>ADCS Diagnostics</h3>
                    <p class="text-muted">Inspect provider assessment agreement and outlier evidence.</p>
                    <a href="adcs.php" class="btn btn-sm btn-primary">Inspect ADCS</a>
                </div>
            </div>
        </div>
        
        <div class="row" style="margin-top: 3rem;">
            <div class="col-12">
                <h2 style="margin-bottom: 1.5rem;">Platform Statistics</h2>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['total_users'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['customers'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Customers</div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['providers'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Service Providers</div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['service_categories'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Service Categories</div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['service_requests'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Service Requests</div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><?= htmlspecialchars((string)($stats['total_reviews'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="stat-label">Verified Reviews</div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
