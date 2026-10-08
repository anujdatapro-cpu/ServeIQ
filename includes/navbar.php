<?php
declare(strict_types=1);
require_once __DIR__ . '/csrf.php';

$role = (string)($_SESSION['user_role'] ?? '');
$currentPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$isActiveLink = static function (string $href) use ($currentPath): string {
    $path = (string)parse_url($href, PHP_URL_PATH);
    $path = preg_replace('#^(?:\.\./)+#', '', $path) ?? $path;
    return $path !== '' && str_ends_with($currentPath, '/' . ltrim($path, '/')) ? ' active' : '';
};

$workspaceLinks = match ($role) {
    'customer' => [
        ['Dashboard', 'customer/dashboard.php', 'bi-grid'],
        ['Create request', 'customer/create_request.php', 'bi-plus-circle'],
        ['My requests', 'customer/my_requests.php', 'bi-card-list'],
        ['Bookings', 'customer/bookings.php', 'bi-calendar-check'],
    ],
    'provider' => [
        ['Dashboard', 'provider/dashboard.php', 'bi-grid'], ['Requests', 'provider/requests.php', 'bi-inbox'],
        ['Assessments', 'provider/assessments.php', 'bi-clipboard2-pulse'], ['Bookings', 'provider/bookings.php', 'bi-calendar-check'],
        ['Services', 'provider/services.php', 'bi-tools'], ['Profile', 'provider/profile.php', 'bi-person-gear'],
    ],
    'admin' => [
        ['Dashboard', 'admin/dashboard.php', 'bi-grid'], ['Users', 'admin/users.php', 'bi-people'],
        ['Providers', 'admin/providers.php', 'bi-patch-check'], ['Categories', 'admin/categories.php', 'bi-tags'],
        ['ServiceDNA', 'admin/service_dna.php', 'bi-fingerprint'], ['ADCS', 'admin/adcs.php', 'bi-diagram-3'],
        ['Reviews', 'admin/reviews.php', 'bi-star'], ['Audit logs', 'admin/audit_logs.php', 'bi-journal-text'],
    ],
    default => [],
};
?>
<nav class="navbar navbar-expand-lg fixed-top site-navbar" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand brand-mark" href="<?= $basePath ?>index.php" aria-label="ServeIQ home">
            <span class="brand-symbol"><i class="bi bi-stars"></i></span><span>Serve<span class="brand-accent">IQ</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation"><span class="menu-bars" aria-hidden="true"></span></button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <?php if ($workspaceLinks === []): ?>
                <ul class="navbar-nav mx-auto gap-lg-1">
                    <li class="nav-item"><a class="nav-link<?= $isActiveLink($basePath . 'index.php') ?>" href="<?= $basePath ?>index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#how-it-works">How it works</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#problem-box">For customers</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#for-providers">For providers</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#about">About</a></li>
                </ul>
                <div class="d-flex align-items-center gap-2 nav-actions">
                    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode" title="Switch theme" aria-pressed="false"><i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i><span class="visually-hidden" data-theme-label>Switch to dark mode</span></button>
                    <a class="btn btn-link nav-login" href="<?= $basePath ?>login.php">Log in</a>
                    <a class="btn btn-outline-primary rounded-pill px-3" href="<?= $basePath ?>register.php?role=provider">Become a provider</a>
                    <a class="btn btn-primary rounded-pill px-4" href="<?= $basePath ?>register.php">Get started <i class="bi bi-arrow-up-right ms-1"></i></a>
                </div>
            <?php else: ?>
                <ul class="navbar-nav mx-auto gap-lg-1 workspace-nav">
                    <?php foreach (array_slice($workspaceLinks, 0, 2) as [$label, $href, $icon]): ?>
                        <li class="nav-item"><a class="nav-link<?= $isActiveLink($basePath . $href) ?>" href="<?= $basePath . $href ?>"><i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
                    <?php endforeach; ?>
                    <li class="nav-item dropdown">
                        <button class="nav-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Workspace</button>
                        <ul class="dropdown-menu dropdown-menu-end workspace-menu">
                            <?php foreach (array_slice($workspaceLinks, 2) as [$label, $href, $icon]): ?>
                                <li><a class="dropdown-item<?= $isActiveLink($basePath . $href) ?>" href="<?= $basePath . $href ?>"><i class="bi <?= $icon ?> me-2"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-2 nav-actions">
                    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode" title="Switch theme" aria-pressed="false"><i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i><span class="visually-hidden" data-theme-label>Switch to dark mode</span></button>
                    <span class="nav-account d-none d-xl-inline"><span class="nav-account-role"><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?> workspace</span><strong><?= htmlspecialchars(mb_substr((string)($_SESSION['user_name'] ?? ''), 0, 24), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <form method="POST" action="<?= $basePath ?>logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-secondary btn-sm">Log out</button></form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
