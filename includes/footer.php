<footer class="site-footer">
    <div class="container">
        <div class="row gy-4 align-items-start">
            <div class="col-lg-5">
                <a class="brand-mark footer-brand" href="<?= $basePath ?>index.php">
                    <span class="brand-symbol"><i class="bi bi-stars"></i></span>
                    <span>Serve<span class="brand-accent">IQ</span></span>
                </a>
                <p class="footer-copy mb-0">A clearer workflow for finding and coordinating local services.</p>
            </div>
            <div class="col-6 col-lg-3">
                <h2 class="footer-heading">Explore</h2>
                <a class="footer-link" href="<?= $basePath ?>index.php#how-it-works">How it works</a>
                <a class="footer-link" href="<?= $basePath ?>index.php#services">Service categories</a>
                <a class="footer-link" href="<?= $basePath ?>index.php#about">About ServeIQ</a>
            </div>
            <div class="col-6 col-lg-4">
                <h2 class="footer-heading">Get started</h2>
                <a class="footer-link" href="<?= $basePath ?>register.php">Create a customer account</a>
                <a class="footer-link" href="<?= $basePath ?>register.php?role=provider">Join as a service provider</a>
                <a class="footer-link" href="<?= $basePath ?>login.php">Log in</a>
            </div>
        </div>
        <hr class="footer-rule">
        <p class="small footer-copyright mb-0">&copy; <?= date('Y') ?> ServeIQ. All rights reserved.</p>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $basePath ?>assets/js/main.js?v=<?= htmlspecialchars($assetVersion ?? '', ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
