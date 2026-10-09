<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/csrf.php';

$categories = [];
try {
    require_once __DIR__ . '/config/database.php';
    $categories = getDatabaseConnection()
        ->query('SELECT id, category_name, description FROM service_categories WHERE is_active = 1 ORDER BY category_name')
        ->fetchAll();
} catch (Throwable $e) {
    error_log('Homepage category query failed: ' . $e->getMessage());
}

$pageTitle = 'ServeIQ | Intelligent Local Services';
$bodyClass = 'homepage';
$basePath = '';
$userRole = (string)($_SESSION['user_role'] ?? '');
$canStartRequest = empty($_SESSION['user_id']) || $userRole === 'customer';
$problemAction = match ($userRole) {
    'customer' => 'customer/create_request.php',
    'provider' => 'provider/dashboard.php',
    'admin' => 'admin/dashboard.php',
    default => 'login.php?redirect=customer%2Fcreate_request.php',
};

$categoryIcon = static function (string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    return match (true) {
        str_contains($name, 'laptop'), str_contains($name, 'computer') => 'bi-laptop',
        str_contains($name, 'mobile'), str_contains($name, 'phone') => 'bi-phone',
        str_contains($name, 'electric') => 'bi-lightning-charge',
        str_contains($name, 'plumb'), str_contains($name, 'pipe') => 'bi-droplet',
        str_contains($name, 'home'), str_contains($name, 'clean') => 'bi-house-gear',
        str_contains($name, 'vehicle'), str_contains($name, 'car') => 'bi-car-front',
        str_contains($name, 'ac'), str_contains($name, 'cool') => 'bi-snow',
        default => 'bi-wrench-adjustable',
    };
};

require __DIR__ . '/includes/header.php';
?>

<main>
    <!-- HERO SECTION -->
    <section class="hero-section product-hero py-5">
        <div class="hero-grid-pattern" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <!-- Hero Left: Copy & Actions -->
                <div class="col-lg-6">
                    <div class="eyebrow mb-3"><span class="eyebrow-dot"></span> Intelligent local services</div>
                    <h1 class="hero-title mb-4">
                        Describe the problem.<br>
                        <span class="text-gradient">We'll find the right service.</span>
                    </h1>
                    <p class="hero-description mb-4">
                        Tell ServeIQ what is wrong in your own words. We understand the context and connect you with relevant local service providers.
                    </p>
                    <div class="hero-actions d-flex flex-wrap gap-3 mb-4">
                        <a class="btn btn-primary btn-lg rounded-pill px-4" href="#problem-box">
                            Describe a problem <i class="bi bi-arrow-down ms-1"></i>
                        </a>
                        <a class="btn btn-outline-secondary btn-lg rounded-pill px-4" href="#how-it-works">
                            How it works
                        </a>
                    </div>
                    <div class="hero-proof d-flex align-items-center gap-2 text-muted small">
                        <i class="bi bi-shield-check text-primary fs-5"></i>
                        <span>Clear steps, verified local providers, and transparent request tracking.</span>
                    </div>
                </div>

                <!-- Hero Right: Problem Description Card or CSS Visual -->
                <div class="col-lg-6">
                    <div class="problem-card shadow-lg p-4 rounded-4" id="problem-box" data-preview-url="services/service_dna_preview.php" data-csrf-token="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="problem-card-header d-flex align-items-center gap-2 mb-3">
                            <span class="status-pulse"></span>
                            <span class="fw-bold">What do you need help with?</span>
                            <i class="bi bi-chat-square-text text-primary ms-auto fs-5"></i>
                        </div>

                        <?php if ($canStartRequest): ?>
                            <label for="problemDescription" class="form-label small fw-semibold">Problem description</label>
                            <textarea id="problemDescription" class="form-control problem-textarea mb-2" maxlength="5000" aria-describedby="heroProblemHint characterCount" placeholder="For example: My laptop gets hot and the fan becomes loud after about 20 minutes of gaming."></textarea>

                            <div class="d-flex justify-content-between gap-3 mb-3 small text-muted">
                                <span id="heroProblemHint">A few details help providers understand the issue.</span>
                                <span id="characterCount" aria-live="polite">0 / 5000</span>
                            </div>

                            <div class="example-prompts d-flex flex-wrap gap-2 mb-4" aria-label="Example problem descriptions">
                                <span class="small text-muted align-self-center me-1">Try an example:</span>
                                <button type="button" class="quick-chip" data-problem="My laptop gets hot and the fan becomes loud during gaming.">Laptop overheating</button>
                                <button type="button" class="quick-chip" data-problem="My air conditioner runs but does not cool the room.">AC not cooling</button>
                                <button type="button" class="quick-chip" data-problem="My washing machine leaks water and makes a loud noise while spinning.">Washing machine leaking</button>
                                <button type="button" class="quick-chip" data-problem="My phone screen is cracked and touch input is unreliable.">Phone screen damaged</button>
                            </div>

                            <div class="problem-card-footer d-flex flex-wrap gap-2">
                                <button id="problemAnalyzeButton" class="btn btn-outline-primary rounded-pill px-3" type="button">
                                    <i class="bi bi-fingerprint me-1"></i> Preview Service Analysis
                                </button>
                                <a id="problemSubmitLink" class="btn btn-primary rounded-pill px-4 ms-auto" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">
                                    Start request <i class="bi bi-arrow-up-right ms-1"></i>
                                </a>
                            </div>

                            <p id="problemValidation" class="small text-danger mb-0 mt-2" role="status" aria-live="polite"></p>
                            <div id="problemAnalysisResult" class="service-dna-preview mt-3" hidden aria-live="polite"></div>
                        <?php else: ?>
                            <p class="text-muted">You’re signed in with a <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?> account. Continue to your workspace to manage your ServeIQ activity.</p>
                            <div class="problem-card-footer d-flex justify-content-between align-items-center">
                                <span class="small text-muted">Your workspace is ready.</span>
                                <a class="btn btn-primary rounded-pill px-4" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">
                                    Open workspace <i class="bi bi-arrow-up-right ms-1"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- CSS Interactive Visual Flow (No Photographs) -->
                    <div class="hero-css-visual mt-4 p-4 rounded-4 border bg-surface-subtle" aria-hidden="true">
                        <div class="text-uppercase small fw-bold text-muted mb-3 tracking-wider">AI Service Architecture</div>
                        <div class="hero-visual-nodes d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="hero-node active">
                                <i class="bi bi-chat-square-text"></i>
                                <span>Problem</span>
                            </div>
                            <div class="hero-line"></div>
                            <div class="hero-node active">
                                <i class="bi bi-cpu"></i>
                                <span>Service Analysis</span>
                            </div>
                            <div class="hero-line"></div>
                            <div class="hero-node">
                                <i class="bi bi-diagram-3"></i>
                                <span>Provider Match</span>
                            </div>
                            <div class="hero-line"></div>
                            <div class="hero-node">
                                <i class="bi bi-calendar-check"></i>
                                <span>Book</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- PROBLEM SHIFT SECTION -->
    <section class="problem-shift-section py-5 border-top border-bottom bg-surface-subtle" aria-labelledby="problem-shift-title">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-5">
                    <span class="section-kicker">A better starting point</span>
                    <h2 id="problem-shift-title" class="mb-3">
                        The hard part isn't finding a service.<br>
                        <span class="text-primary">It's finding the right one.</span>
                    </h2>
                </div>
                <div class="col-lg-7">
                    <div class="d-flex flex-column gap-3">
                        <div class="p-3 rounded-3 border bg-surface d-flex align-items-center gap-3">
                            <span class="badge bg-secondary-subtle text-muted px-2 py-1">THE USUAL WAY</span>
                            <div class="text-muted small">Search <i class="bi bi-arrow-right mx-1"></i> Browse <i class="bi bi-arrow-right mx-1"></i> Call <i class="bi bi-arrow-right mx-1"></i> Explain <i class="bi bi-arrow-right mx-1"></i> Repeat</div>
                        </div>
                        <div class="p-3 rounded-3 border border-primary bg-surface d-flex align-items-center gap-3">
                            <span class="badge bg-primary text-dark fw-bold px-2 py-1">WITH SERVEIQ</span>
                            <div class="fw-semibold text-main small">
                                <span class="text-primary">Describe</span> <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <span class="text-primary">Analyze</span> <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <span class="text-primary">Match</span> <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <span class="text-primary">Book</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS (PRODUCT WORKFLOW) -->
    <section class="section-padding py-5" id="how-it-works">
        <div class="container">
            <div class="text-center mx-auto mb-5" style="max-width: 680px;">
                <span class="section-kicker">How ServeIQ Works</span>
                <h2>A clear step-by-step workflow.</h2>
                <p class="text-muted">From your natural description to a completed, reviewed service.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg">
                    <div class="card h-100 p-4 border rounded-4 text-center">
                        <div class="step-badge mb-3 mx-auto">Step 01</div>
                        <i class="bi bi-chat-square-text fs-2 text-primary mb-3"></i>
                        <h3 class="h5 mb-2">Describe</h3>
                        <p class="text-muted small mb-0">Explain the problem naturally in your own words with symptoms and location.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg">
                    <div class="card h-100 p-4 border border-primary rounded-4 text-center bg-surface-subtle">
                        <div class="step-badge mb-3 mx-auto bg-primary text-dark">Step 02</div>
                        <i class="bi bi-cpu fs-2 text-primary mb-3"></i>
                        <h3 class="h5 mb-2">Analyze</h3>
                        <p class="text-muted small mb-0">ServeIQ structures the issue into actionable context and service requirements.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg">
                    <div class="card h-100 p-4 border rounded-4 text-center">
                        <div class="step-badge mb-3 mx-auto">Step 03</div>
                        <i class="bi bi-diagram-3 fs-2 text-primary mb-3"></i>
                        <h3 class="h5 mb-2">Match</h3>
                        <p class="text-muted small mb-0">Compare qualified local service providers ranked by compatibility and location.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg">
                    <div class="card h-100 p-4 border rounded-4 text-center">
                        <div class="step-badge mb-3 mx-auto">Step 04</div>
                        <i class="bi bi-calendar-check fs-2 text-primary mb-3"></i>
                        <h3 class="h5 mb-2">Book</h3>
                        <p class="text-muted small mb-0">Select your preferred provider and coordinate a convenient service appointment.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg">
                    <div class="card h-100 p-4 border rounded-4 text-center">
                        <div class="step-badge mb-3 mx-auto">Step 05</div>
                        <i class="bi bi-star fs-2 text-primary mb-3"></i>
                        <h3 class="h5 mb-2">Review</h3>
                        <p class="text-muted small mb-0">Completed services contribute to provider ratings and verified reputation.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICE CATEGORIES SECTION -->
    <section class="section-padding py-5 bg-surface-subtle border-top border-bottom" id="services">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <span class="section-kicker">Available categories</span>
                    <h2>Find the right kind of help.</h2>
                </div>
                <p class="text-muted mb-0" style="max-width: 450px;">Choose a category or describe any custom issue directly in the search box above.</p>
            </div>
            <?php if ($categories === []): ?>
                <div class="p-4 border rounded-4 bg-surface text-center text-muted">
                    <i class="bi bi-grid-1x2 fs-2 d-block mb-2"></i>
                    <strong>Categories are loading...</strong>
                    <p class="mb-0 small">You can still describe your problem above to find relevant providers.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($categories as $i => $category): ?>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <a class="category-card card p-4 h-100 text-decoration-none" href="#problem-box">
                                <div class="category-icon-wrapper mb-3 text-primary fs-3">
                                    <i class="bi <?= htmlspecialchars($categoryIcon((string)$category['category_name']), ENT_QUOTES, 'UTF-8') ?>"></i>
                                </div>
                                <h3 class="h6 mb-2 text-main"><?= htmlspecialchars((string)$category['category_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="text-muted small mb-3 flex-grow-1"><?= htmlspecialchars((string)($category['description'] ?: 'Describe your issue to find qualified local specialists.'), ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="small fw-semibold text-primary">Describe problem <i class="bi bi-arrow-right ms-1"></i></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- COMMON SERVICE SCENARIOS (REPLACED PHOTOGRAPHIC IMAGE SECTION) -->
    <section class="section-padding py-5" aria-labelledby="scenario-title">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <span class="section-kicker">Common Service Requests</span>
                    <h2 id="scenario-title">Everyday problems start here.</h2>
                </div>
                <p class="text-muted mb-0" style="max-width: 450px;">Typical issue requests described by customers to connect with technicians.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="p-4 rounded-4 border bg-surface h-100 d-flex flex-column justify-content-between">
                        <div>
                            <i class="bi bi-laptop fs-1 text-primary d-block mb-3"></i>
                            <span class="badge bg-primary-subtle text-primary mb-2">ELECTRONICS</span>
                            <h3 class="h5 mb-2">Laptop Overheating</h3>
                            <p class="text-muted small mb-0">Thermal throttling, high fan noise, and sudden shutdowns under load.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="p-4 rounded-4 border bg-surface h-100 d-flex flex-column justify-content-between">
                        <div>
                            <i class="bi bi-phone fs-1 text-primary d-block mb-3"></i>
                            <span class="badge bg-primary-subtle text-primary mb-2">MOBILE</span>
                            <h3 class="h5 mb-2">Cracked Phone Screen</h3>
                            <p class="text-muted small mb-0">Unresponsive touch display, flickering OLED, or shattered glass.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="p-4 rounded-4 border bg-surface h-100 d-flex flex-column justify-content-between">
                        <div>
                            <i class="bi bi-snow fs-1 text-primary d-block mb-3"></i>
                            <span class="badge bg-primary-subtle text-primary mb-2">HOME APPLIANCES</span>
                            <h3 class="h5 mb-2">AC Not Cooling</h3>
                            <p class="text-muted small mb-0">Refrigerant leaks, dirty filter airflow restriction, or capacitor failure.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="p-4 rounded-4 border bg-surface h-100 d-flex flex-column justify-content-between">
                        <div>
                            <i class="bi bi-droplet fs-1 text-primary d-block mb-3"></i>
                            <span class="badge bg-primary-subtle text-primary mb-2">PLUMBING</span>
                            <h3 class="h5 mb-2">Leaking Sink & Drain</h3>
                            <p class="text-muted small mb-0">Drain blockages, worn faucet seals, or low pipe water pressure.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- PROVIDER CALLOUT -->
    <section class="provider-cta-section py-5 bg-surface-subtle border-top border-bottom" id="for-providers">
        <div class="container">
            <div class="p-4 p-md-5 rounded-4 border bg-surface d-flex flex-wrap align-items-center justify-content-between gap-4">
                <div style="max-width: 580px;">
                    <span class="section-kicker">For Service Professionals</span>
                    <h2 class="h3 mb-2">Grow your local service business with ServeIQ.</h2>
                    <p class="text-muted mb-0">Register as a provider, list your services, receive structured customer requests, and manage bookings smoothly.</p>
                </div>
                <a class="btn btn-primary rounded-pill px-4 btn-lg" href="register.php?role=provider">
                    Become a provider <i class="bi bi-arrow-up-right ms-1"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- CLOSING CTA -->
    <section class="closing-cta py-5 text-center" aria-labelledby="closing-cta-title">
        <div class="container py-4">
            <span class="section-kicker">Get started today</span>
            <h2 id="closing-cta-title" class="mb-4">
                Your problem has a service solution.<br>
                <span class="text-primary">Let's find it together.</span>
            </h2>
            <a class="btn btn-primary btn-lg rounded-pill px-5" href="#problem-box">
                Describe your problem <i class="bi bi-arrow-up-right ms-1" aria-hidden="true"></i>
            </a>
        </div>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
