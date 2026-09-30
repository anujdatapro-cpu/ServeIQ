<?php require_once __DIR__ . '/csrf.php'; ?>
<nav class="navbar navbar-expand-lg fixed-top site-navbar" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand brand-mark" href="<?= $basePath ?>index.php">
            <span class="brand-symbol"><i class="bi bi-stars"></i></span>
            <span>Serve<span class="brand-accent">IQ</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <ul class="navbar-nav mx-auto gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#how-it-works">How It Works</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#services">Services</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>index.php#about">About</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2 nav-actions">
                <?php if (empty($_SESSION['user_id'])): ?>
                    <a class="btn btn-link nav-login" href="<?= $basePath ?>login.php">Log in</a>
                    <a class="btn btn-primary rounded-pill px-4" href="<?= $basePath ?>register.php">Get started <i class="bi bi-arrow-up-right ms-1"></i></a>
                <?php else: ?>
                    <span class="text-muted small d-none d-md-inline">Hello, <?= htmlspecialchars(substr($_SESSION['user_name'] ?? '', 0, 15), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php 
                        $role = $_SESSION['user_role'] ?? '';
                        if ($role === 'customer'): 
                    ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= $basePath ?>customer/dashboard.php">Dashboard</a>
                        <a class="btn btn-outline-primary btn-sm" href="<?= $basePath ?>customer/bookings.php">My Bookings</a>
                    <?php elseif ($role === 'provider'): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= $basePath ?>provider/dashboard.php">Dashboard</a>
                        <a class="btn btn-outline-primary btn-sm" href="<?= $basePath ?>provider/bookings.php">Bookings</a>
                    <?php elseif ($role === 'admin'): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= $basePath ?>admin/dashboard.php">Dashboard</a>
                    <?php endif; ?>
                    <form method="POST" action="<?= $basePath ?>logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger btn-sm">Log out</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
