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
        'total_reviews' => $pdo->query("SELECT COUNT(*) AS count FROM reviews WHERE status = 'published'")->fetch()['count'] ?? 0,
        'active_bookings' => $pdo->query("SELECT COUNT(*) AS count FROM bookings WHERE status IN ('pending', 'accepted', 'in_progress')")->fetch()['count'] ?? 0,
        'completed_bookings' => $pdo->query("SELECT COUNT(*) AS count FROM bookings WHERE status = 'completed'")->fetch()['count'] ?? 0,
        'booking_cancellation_rate' => $pdo->query("SELECT CASE WHEN COUNT(*) = 0 THEN NULL ELSE ROUND(100 * SUM(status = 'cancelled') / COUNT(*), 1) END FROM bookings")->fetchColumn(),
        'assessments' => $pdo->query("SELECT COUNT(*) AS count FROM provider_assessments WHERE status IN ('submitted', 'updated')")->fetch()['count'] ?? 0,
        'consensus_results' => $pdo->query('SELECT COUNT(*) AS count FROM adcs_results')->fetch()['count'] ?? 0,
        'matching_results' => $pdo->query('SELECT COUNT(*) AS count FROM matching_results')->fetch()['count'] ?? 0,
        'average_match_score' => $pdo->query('SELECT ROUND(COALESCE(AVG(match_score), 0), 1) FROM matching_results')->fetchColumn(),
        'average_rating' => $pdo->query("SELECT ROUND(COALESCE(AVG(rating), 0), 1) AS average_rating FROM reviews WHERE status = 'published'")->fetch()['average_rating'] ?? 0,
    ];
    $requestStatusRows = $pdo->query('SELECT status, COUNT(*) AS total FROM service_requests GROUP BY status ORDER BY total DESC, status')->fetchAll();
    try {
        $stats['audit_events_24h'] = $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)')->fetchColumn();
    } catch (Throwable) {
        $stats['audit_events_24h'] = null;
    }
} catch (Exception $e) {
    $stats = null;
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
                    <div class="dashboard-card-icon blue"><i class="bi bi-heart-pulse"></i></div>
                    <h3>System Status</h3>
                    <p class="text-muted">Check application runtime and database connectivity.</p>
                    <a href="../health.php" class="btn btn-sm btn-primary">Open health check</a>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon cyan"><i class="bi bi-journal-check"></i></div>
                    <h3>Audit Logs</h3>
                    <p class="text-muted">Review recorded security and business events.</p>
                    <a href="audit_logs.php" class="btn btn-sm btn-primary">View Audit Logs</a>
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
            <?php if ($stats === null): ?>
                <div class="col-12"><div class="alert alert-warning" role="status">Platform statistics are unavailable right now. Check database configuration and server logs.</div></div>
            <?php else: ?>
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
            <?php foreach ([
                'active_bookings' => 'Active Bookings',
                'completed_bookings' => 'Completed Bookings',
                'booking_cancellation_rate' => 'Bookings Cancelled',
                'assessments' => 'Current Assessments',
                'consensus_results' => 'ADCS Results',
                'matching_results' => 'Provider Match Results',
                'average_match_score' => 'Average Match Score',
                'average_rating' => 'Average Published Rating',
                'audit_events_24h' => 'Audit Events (24h)',
            ] as $metric => $label): ?>
                <div class="col-md-4 col-sm-6 mb-3"><div class="stat-card"><div class="stat-number"><?php if ($stats[$metric] === null): ?>—<?php else: ?><?= htmlspecialchars((string)$stats[$metric], ENT_QUOTES, 'UTF-8') ?><?= $metric === 'booking_cancellation_rate' ? '%' : ($metric === 'average_match_score' ? '/100' : '') ?><?php endif; ?></div><div class="stat-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div></div></div>
            <?php endforeach; ?>
            <div class="col-12 mb-3">
                <section class="card border-0 p-4" aria-labelledby="request-status-heading">
                    <h3 id="request-status-heading" class="h5">Requests by status</h3>
                    <?php if ($requestStatusRows === []): ?>
                        <p class="text-muted mb-0">No request data available.</p>
                    <?php else: ?>
                        <div class="request-status-list">
                            <?php foreach ($requestStatusRows as $statusRow): ?>
                                <div class="request-status-item"><span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$statusRow['status'])), ENT_QUOTES, 'UTF-8') ?></span><strong><?= (int)$statusRow['total'] ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
