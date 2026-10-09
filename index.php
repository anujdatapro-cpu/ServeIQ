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

$pageTitle = 'ServeIQ | Intelligent Local Service Marketplace';
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
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div class="eyebrow"><span class="eyebrow-dot"></span> Intelligent Service Marketplace</div>
                    <h1>Describe the problem.<br><span class="text-gradient">We'll find the right service.</span></h1>
                    <p class="hero-description">Tell ServeIQ what is wrong in your own words. ServeIQ organizes the details and connects you with relevant local service providers.</p>
                    <div class="hero-actions d-flex flex-wrap gap-3">
                        <a class="btn btn-primary btn-lg rounded-pill px-4" href="#problem-box">Describe a problem <i class="bi bi-arrow-down ms-1" aria-hidden="true"></i></a>
                        <a class="btn btn-outline-secondary btn-lg rounded-pill px-4" href="#how-it-works">How it works</a>
                    </div>
                    <div class="hero-proof">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        <span>Structured problem analysis · Verified local professionals</span>
                    </div>
                </div>

                <div class="col-lg-6">
                    <!-- ANIMATED VISUAL: PROBLEM -> ANALYZE -> MATCH -> BOOK -->
                    <div class="hero-flow-container" aria-label="Animated service processing visual">
                        <div class="hero-flow-header">
                            <span class="hero-flow-title"><i class="bi bi-cpu" aria-hidden="true"></i> Service Intelligence Engine</span>
                            <span class="hero-flow-badge">Active System Flow</span>
                        </div>
                        <div class="hero-flow-nodes">
                            <div class="flow-step-node is-active">
                                <div class="flow-node-icon"><i class="bi bi-chat-left-text-fill" aria-hidden="true"></i></div>
                                <div class="flow-node-content">
                                    <span class="flow-node-label">1. PROBLEM</span>
                                    <span class="flow-node-subtext">Customer describes issues in plain words</span>
                                </div>
                            </div>
                            <div class="flow-node-arrow"><i class="bi bi-arrow-down-short" aria-hidden="true"></i></div>

                            <div class="flow-step-node">
                                <div class="flow-node-icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                                <div class="flow-node-content">
                                    <span class="flow-node-label">2. ANALYZE</span>
                                    <span class="flow-node-subtext">ServeIQ structures symptoms & urgency</span>
                                </div>
                            </div>
                            <div class="flow-node-arrow"><i class="bi bi-arrow-down-short" aria-hidden="true"></i></div>

                            <div class="flow-step-node">
                                <div class="flow-node-icon"><i class="bi bi-diagram-3-fill" aria-hidden="true"></i></div>
                                <div class="flow-node-content">
                                    <span class="flow-node-label">3. MATCH</span>
                                    <span class="flow-node-subtext">Relevant verified local providers ranked</span>
                                </div>
                            </div>
                            <div class="flow-node-arrow"><i class="bi bi-arrow-down-short" aria-hidden="true"></i></div>

                            <div class="flow-step-node">
                                <div class="flow-node-icon"><i class="bi bi-calendar-check-fill" aria-hidden="true"></i></div>
                                <div class="flow-node-content">
                                    <span class="flow-node-label">4. BOOK</span>
                                    <span class="flow-node-subtext">Schedule appointment and review work</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- INTERACTIVE SERVICE REQUEST PANEL -->
    <section class="section-padding bg-surface-subtle" id="problem-box">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="problem-card" data-preview-url="services/service_dna_preview.php" data-csrf-token="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="problem-card-header">
                            <span class="status-pulse"></span>
                            <span>What do you need help with?</span>
                            <i class="bi bi-chat-square-text ms-auto" aria-hidden="true"></i>
                        </div>
                        <?php if ($canStartRequest): ?>
                            <label for="problemDescription" class="form-label">Problem description</label>
                            <textarea id="problemDescription" class="form-control problem-textarea" maxlength="5000" aria-describedby="heroProblemHint characterCount" placeholder="My laptop gets hot and the fan becomes loud after about 20 minutes of gaming."></textarea>

                            <div class="d-flex justify-content-between gap-3 mt-2 small text-muted">
                                <span id="heroProblemHint">Share details like symptoms, duration, or model if known.</span>
                                <span id="characterCount" aria-live="polite">0 / 5000</span>
                            </div>

                            <div class="example-prompts" aria-label="Example problem descriptions">
                                <span class="small text-muted me-1">Try an example:</span>
                                <button type="button" class="quick-chip" data-problem="My laptop gets hot and the fan becomes loud after about 20 minutes of gaming.">Laptop overheating</button>
                                <button type="button" class="quick-chip" data-problem="Air conditioner runs but does not cool the room efficiently.">AC not cooling</button>
                                <button type="button" class="quick-chip" data-problem="Washing machine leaks water from underneath during spin cycle.">Washing machine leaking</button>
                                <button type="button" class="quick-chip" data-problem="Phone screen is cracked and touch input is unresponsive in some areas.">Phone screen damaged</button>
                                <button type="button" class="quick-chip" data-problem="WiFi keeps disconnecting frequently on multiple devices.">WiFi disconnecting</button>
                            </div>

                            <div class="problem-card-footer mt-3">
                                <button id="problemAnalyzeButton" class="btn btn-outline-primary rounded-pill px-3" type="button">
                                    <i class="bi bi-cpu" aria-hidden="true"></i> Preview Service Analysis
                                </button>
                                <a id="problemSubmitLink" class="btn btn-primary rounded-pill px-4" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">
                                    Start request <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                                </a>
                            </div>
                            <p id="problemValidation" class="small text-danger mb-0 mt-2" role="status" aria-live="polite"></p>
                            <div id="problemAnalysisResult" class="service-dna-preview mt-3" hidden aria-live="polite"></div>
                        <?php else: ?>
                            <p class="text-muted">You are signed in with a <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?> account. Continue to your workspace to manage your requests and bookings.</p>
                            <div class="problem-card-footer">
                                <a class="btn btn-primary rounded-pill px-4" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">Open Workspace <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS: 5-STEP WORKFLOW -->
    <section class="section-padding" id="how-it-works">
        <div class="container">
            <div class="section-heading text-center mx-auto">
                <span class="section-kicker">End-to-End Process</span>
                <h2>How ServeIQ Works</h2>
                <p class="section-intro mx-auto">An intelligent workflow that turns your natural language description into structured details, verified matches, and completed service.</p>
            </div>

            <div class="process-grid-5">
                <article class="process-card">
                    <span class="process-number">01</span>
                    <i class="bi bi-chat-left-text process-icon" aria-hidden="true"></i>
                    <h3>DESCRIBE</h3>
                    <p>Tell us what is happening in plain words. Include symptoms, location, or urgency.</p>
                </article>

                <article class="process-card">
                    <span class="process-number">02</span>
                    <i class="bi bi-search process-icon" aria-hidden="true"></i>
                    <h3>ANALYZE</h3>
                    <p>ServeIQ organizes the problem context and identifies service requirements.</p>
                </article>

                <article class="process-card">
                    <span class="process-number">03</span>
                    <i class="bi bi-diagram-3 process-icon" aria-hidden="true"></i>
                    <h3>MATCH</h3>
                    <p>Relevant local service providers are ranked by location, service fit, and quality.</p>
                </article>

                <article class="process-card">
                    <span class="process-number">04</span>
                    <i class="bi bi-calendar-check process-icon" aria-hidden="true"></i>
                    <h3>BOOK</h3>
                    <p>Choose a verified provider, select an available time slot, and schedule service.</p>
                </article>

                <article class="process-card">
                    <span class="process-number">05</span>
                    <i class="bi bi-star-half process-icon" aria-hidden="true"></i>
                    <h3>REVIEW</h3>
                    <p>Review completed services to help maintain marketplace quality and transparency.</p>
                </article>
            </div>
        </div>
    </section>

    <!-- PROBLEM SHIFT SECTION -->
    <section class="section-padding bg-surface-subtle" aria-labelledby="problem-shift-title">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-5">
                    <span class="section-kicker">A better starting point</span>
                    <h2 id="problem-shift-title">The hard part isn't finding a service. It's finding the right one.</h2>
                </div>
                <div class="col-lg-7">
                    <p class="section-intro mb-0">Describe the issue once, then move through analysis, provider matching, and booking without repeating the same search and explanation.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- WHY SERVEIQ / ABSTRACT SERVICE NETWORK -->
    <section class="section-padding bg-surface-subtle" id="about">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-5">
                    <span class="section-kicker">Intelligent Architecture</span>
                    <h2>A clearer picture of every service request.</h2>
                    <p class="section-intro">Traditional marketplaces rely on endless searching and repetitive phone calls. ServeIQ brings structure to service management through intelligent contextual matching.</p>
                </div>
                <div class="col-lg-7">
                    <div class="abstract-flow-card">
                        <div class="abstract-grid">
                            <div class="abstract-item">
                                <div class="abstract-item-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></div>
                                <div>
                                    <div class="abstract-item-title">Context Extraction</div>
                                    <p class="abstract-item-desc">Extracts key symptoms, affected devices, and urgency from plain language.</p>
                                </div>
                            </div>

                            <div class="abstract-item">
                                <div class="abstract-item-icon"><i class="bi bi-geo-alt" aria-hidden="true"></i></div>
                                <div>
                                    <div class="abstract-item-title">Location Alignment</div>
                                    <p class="abstract-item-desc">Matches local providers within your city and service radius.</p>
                                </div>
                            </div>

                            <div class="abstract-item">
                                <div class="abstract-item-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                                <div>
                                    <div class="abstract-item-title">Verified Profiles</div>
                                    <p class="abstract-item-desc">Ensures provider qualifications and active service catalog compatibility.</p>
                                </div>
                            </div>

                            <div class="abstract-item">
                                <div class="abstract-item-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></div>
                                <div>
                                    <div class="abstract-item-title">Direct Scheduling</div>
                                    <p class="abstract-item-desc">Clear status updates from request creation through job completion.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICE CATEGORIES -->
    <section class="section-padding" id="services">
        <div class="container">
            <div class="section-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
                <div>
                    <span class="section-kicker">Active Categories</span>
                    <h2>Find help across key service areas.</h2>
                </div>
                <p class="section-intro mb-0">Browse active categories or describe your custom request above.</p>
            </div>

            <?php if ($categories === []): ?>
                <div class="empty-state-saas mt-4">
                    <div class="empty-state-icon"><i class="bi bi-grid-1x2" aria-hidden="true"></i></div>
                    <div class="empty-state-title">Categories are updating</div>
                    <p class="empty-state-desc">You can still describe your problem directly in the input box above.</p>
                </div>
            <?php else: ?>
                <div class="row g-3 mt-3">
                    <?php foreach ($categories as $i => $category): ?>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <a class="category-card" href="#problem-box">
                                <div class="category-icon">
                                    <i class="bi <?= htmlspecialchars($categoryIcon((string)$category['category_name']), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                </div>
                                <h3><?= htmlspecialchars((string)$category['category_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="category-card-description">
                                    <?= htmlspecialchars((string)($category['description'] ?: 'Describe your issue to explore available local providers.'), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <span class="category-card-action">Describe a problem <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- COMMON SERVICE SCENARIOS -->
    <section class="section-padding" aria-labelledby="scenario-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Common service requests</span>
                <h2 id="scenario-title">Everyday problems start here.</h2>
            </div>
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <article class="process-card">
                        <i class="bi bi-laptop process-icon" aria-hidden="true"></i>
                        <h3>Laptop overheating</h3>
                        <p>High fan noise, thermal throttling, or sudden shutdowns under load.</p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="process-card">
                        <i class="bi bi-phone process-icon" aria-hidden="true"></i>
                        <h3>Cracked phone screen</h3>
                        <p>Unresponsive touch, display flickering, or damaged glass.</p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="process-card">
                        <i class="bi bi-snow process-icon" aria-hidden="true"></i>
                        <h3>Air conditioner not cooling</h3>
                        <p>Restricted airflow, poor cooling, or unusual system behavior.</p>
                    </article>
                </div>
                <div class="col-md-6 col-lg-3">
                    <article class="process-card">
                        <i class="bi bi-droplet process-icon" aria-hidden="true"></i>
                        <h3>Leaking sink or drain</h3>
                        <p>Leaks, slow drains, or low water pressure around the home.</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <!-- TRUST / WORKFLOW EXPLANATION -->
    <section class="section-padding bg-surface-subtle" id="for-providers">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="section-kicker">For Customers & Providers</span>
                    <h2>Trust built into every step.</h2>
                    <p class="section-intro">ServeIQ provides clear milestones for both sides of the marketplace, ensuring smooth communication and transparent status tracking.</p>

                    <div class="d-flex flex-column gap-3 mt-4">
                        <div class="d-flex gap-3 align-items-start">
                            <span class="badge badge-status-accepted p-2"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            <div>
                                <strong>Understand</strong>
                                <p class="small text-muted mb-0">Automated structured summary organizes symptoms and requirements.</p>
                            </div>
                        </div>

                        <div class="d-flex gap-3 align-items-start">
                            <span class="badge badge-status-accepted p-2"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            <div>
                                <strong>Match</strong>
                                <p class="small text-muted mb-0">Ranks local providers using service alignment and verified track records.</p>
                            </div>
                        </div>

                        <div class="d-flex gap-3 align-items-start">
                            <span class="badge badge-status-accepted p-2"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            <div>
                                <strong>Book</strong>
                                <p class="small text-muted mb-0">Seamless appointment scheduling and booking state transition management.</p>
                            </div>
                        </div>

                        <div class="d-flex gap-3 align-items-start">
                            <span class="badge badge-status-accepted p-2"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                            <div>
                                <strong>Complete</strong>
                                <p class="small text-muted mb-0">Verified service completion and reviews tied strictly to finished jobs.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="abstract-flow-card text-center p-5">
                        <i class="bi bi-briefcase text-primary display-4 mb-3" aria-hidden="true"></i>
                        <h3>Are you a service professional?</h3>
                        <p class="text-muted mb-4">Expand your business reach with structured incoming customer requests matched directly to your service capabilities.</p>
                        <a class="btn btn-outline-primary rounded-pill px-4" href="register.php?role=provider">Become a Provider <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FINAL CLOSING CTA -->
    <section class="section-padding text-center">
        <div class="container">
            <span class="section-kicker">Get Started Today</span>
            <h2 class="display-6 mb-3">Your problem has a service.<br><span class="text-gradient">Let's find it.</span></h2>
            <p class="text-muted max-w-lg mx-auto mb-4">Start by describing what is happening in your own words.</p>
            <a class="btn btn-primary btn-lg rounded-pill px-5" href="#problem-box">Describe your problem <i class="bi bi-arrow-up-right ms-1" aria-hidden="true"></i></a>
        </div>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
