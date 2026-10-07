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

$pageTitle = 'ServeIQ | A clearer way to find local services';
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
    <section class="hero-section product-hero">
        <div class="hero-grid-pattern" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div class="eyebrow"><span class="eyebrow-dot"></span> AI-powered local service discovery</div>
                    <h1>Describe the problem.<br><span class="text-gradient">We'll find the right help.</span></h1>
                    <p class="hero-description">Describe the problem in your own words. ServeIQ organizes the details, finds relevant local providers, and keeps assessments and bookings in one place.</p>
                    <div class="hero-actions d-flex flex-wrap gap-3">
                        <a class="btn btn-primary btn-lg rounded-pill px-4" href="#problem-box">Describe a problem <i class="bi bi-arrow-down ms-1"></i></a>
                        <a class="btn btn-outline-dark btn-lg rounded-pill px-4" href="#how-it-works">How it works</a>
                    </div>
                    <div class="hero-proof"><i class="bi bi-shield-check"></i><span>Clear steps, real provider information, and a record of your service request.</span></div>
                </div>
                <div class="col-lg-6">
                    <div class="problem-card" id="problem-box" data-preview-url="services/service_dna_preview.php" data-csrf-token="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="problem-card-header"><span class="status-pulse"></span><span>What do you need help with?</span><i class="bi bi-chat-square-text ms-auto"></i></div>
                        <?php if ($canStartRequest): ?>
                            <label for="problemDescription" class="form-label small fw-semibold">Problem description</label>
                            <textarea id="problemDescription" class="form-control problem-textarea" maxlength="5000" aria-describedby="heroProblemHint characterCount" placeholder="For example: My laptop gets hot and the fan becomes loud after about 20 minutes of gaming."></textarea>
                            <div class="d-flex justify-content-between gap-3 mt-2 small text-muted"><span id="heroProblemHint">A few details help providers understand the issue.</span><span id="characterCount" aria-live="polite">0 / 5000</span></div>
                            <div class="example-prompts" aria-label="Example problem descriptions">
                                <span class="small text-muted">Try an example:</span>
                                <button type="button" class="quick-chip" data-problem="My laptop gets hot and the fan becomes loud during gaming.">Laptop overheating</button>
                                <button type="button" class="quick-chip" data-problem="My air conditioner runs but does not cool the room.">AC not cooling</button>
                                <button type="button" class="quick-chip" data-problem="My washing machine leaks water and makes a loud noise while spinning.">Washing machine leaking</button>
                                <button type="button" class="quick-chip" data-problem="My phone screen is cracked and touch input is unreliable.">Phone screen damaged</button>
                            </div>
                            <div class="problem-card-footer"><button id="problemAnalyzeButton" class="btn btn-outline-primary rounded-pill px-3" type="button"><i class="bi bi-fingerprint"></i> Preview ServiceDNA</button><a id="problemSubmitLink" class="btn btn-dark rounded-pill px-3" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">Start request <i class="bi bi-arrow-up-right ms-1"></i></a></div>
                            <p id="problemValidation" class="small text-danger mb-0 mt-2" role="status" aria-live="polite"></p>
                            <div id="problemAnalysisResult" class="service-dna-preview mt-3" hidden aria-live="polite"></div>
                        <?php else: ?>
                            <p class="text-muted">You’re signed in with a <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?> account. Continue to your workspace to manage your ServeIQ activity.</p>
                            <div class="problem-card-footer"><span class="small text-muted">Your workspace is ready.</span><a class="btn btn-dark rounded-pill px-3" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">Open workspace <i class="bi bi-arrow-up-right ms-1"></i></a></div>
                        <?php endif; ?>
                    </div>
                    <div class="floating-insight"><span class="insight-icon"><i class="bi bi-diagram-3"></i></span><span><strong>One connected workflow</strong><small>Request · matching · booking</small></span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-padding categories-section" id="services">
        <div class="container">
            <div class="section-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
                <div><span class="section-kicker">Available categories</span><h2>Find the right kind<br>of help to get started.</h2></div>
                <p class="section-intro">These categories come from the active services configured on ServeIQ. Your request can also be described in your own words.</p>
            </div>
            <?php if ($categories === []): ?>
                <div class="empty-state-card mt-4"><i class="bi bi-grid-1x2"></i><div><strong>Categories are unavailable right now.</strong><p class="mb-0">You can still describe your problem and continue when the service is available.</p></div></div>
            <?php else: ?>
                <div class="row g-3 mt-3">
                    <?php foreach ($categories as $i => $category): ?>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <a class="category-card category-card-link" href="#problem-box">
                                <span class="category-icon <?= ['blue', 'cyan', 'amber', 'green'][$i % 4] ?>"><i class="bi <?= htmlspecialchars($categoryIcon((string)$category['category_name']), ENT_QUOTES, 'UTF-8') ?>"></i></span>
                                <h3><?= htmlspecialchars((string)$category['category_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <span class="category-card-description"><?= htmlspecialchars((string)($category['description'] ?: 'Describe the issue to explore relevant services.'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="category-card-action">Describe a problem <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-padding process-section" id="how-it-works">
        <div class="container">
            <div class="section-heading text-center mx-auto"><span class="section-kicker">A connected workflow</span><h2>From a clear description to a completed service.</h2><p class="section-intro mx-auto">Each step builds on information you and the participating providers actually share.</p></div>
            <div class="row g-4 process-grid">
                <div class="col-md-6 col-lg-3"><article class="process-card"><span class="process-number">01</span><i class="bi bi-chat-square-text process-icon"></i><h3>Describe</h3><p>Share the symptoms, location, urgency, and photos that help explain the service request.</p></article></div>
                <div class="col-md-6 col-lg-3"><article class="process-card featured"><span class="process-number">02</span><i class="bi bi-fingerprint process-icon"></i><h3>Understand</h3><p>Preview the rule-based ServiceDNA summary, then submit the request to save its analysis.</p></article></div>
                <div class="col-md-6 col-lg-3"><article class="process-card"><span class="process-number">03</span><i class="bi bi-diagram-3 process-icon"></i><h3>Match</h3><p>Compare eligible providers using their configured services, location, availability, and real review data.</p></article></div>
                <div class="col-md-6 col-lg-3"><article class="process-card"><span class="process-number">04</span><i class="bi bi-calendar2-check process-icon"></i><h3>Resolve</h3><p>Coordinate through provider assessments, booking status updates, and a completed-service review.</p></article></div>
            </div>
        </div>
    </section>

    <section class="section-padding dna-section" aria-labelledby="dna-title">
        <div class="container"><div class="row align-items-center g-5">
            <div class="col-lg-5"><span class="section-kicker">From words to useful details</span><h2 id="dna-title">A clearer picture of the problem.</h2><p class="section-intro">ServeIQ turns the description into a structured ServiceDNA summary, then carries that context into provider matching, assessment, and booking.</p></div>
            <div class="col-lg-7"><div class="dna-flow" aria-label="Problem description to resolution workflow">
                <?php foreach ([['Problem description', 'Describe what is happening', 'bi-chat-square-text'], ['ServiceDNA', 'Rule-based structured summary', 'bi-fingerprint'], ['Provider matching', 'Compare eligible providers', 'bi-diagram-3'], ['Assessment', 'Review provider input', 'bi-clipboard2-pulse'], ['Booking', 'Coordinate the service', 'bi-calendar-check'], ['Resolution', 'Complete and review', 'bi-check2-circle']] as $step): ?>
                    <div class="dna-flow-step"><span class="dna-flow-icon"><i class="bi <?= $step[2] ?>" aria-hidden="true"></i></span><span><strong><?= htmlspecialchars($step[0], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($step[1], ENT_QUOTES, 'UTF-8') ?></small></span></div>
                <?php endforeach; ?>
            </div></div>
        </div></div>
    </section>

    <section class="section-padding matching-section" aria-labelledby="matching-title">
        <div class="container"><div class="section-heading text-center mx-auto"><span class="section-kicker">How matching is considered</span><h2 id="matching-title">Relevant fit, made easier to understand.</h2><p class="section-intro mx-auto">An illustrative view of factors the application uses. It does not represent a live provider or a real match result.</p></div>
            <article class="match-illustration" aria-label="Example visualization of provider matching factors"><div class="match-illustration-heading"><span class="match-illustration-icon"><i class="bi bi-diagram-3"></i></span><div><strong>Provider match factors</strong><small>Example visualization · not live data</small></div></div>
                <div class="match-score-label"><strong>Match score</strong><span>Calculated from available request and provider details</span></div>
                <div class="match-factor-grid"><?php foreach ([['Category compatibility', 'bi-grid'], ['Service compatibility', 'bi-tools'], ['Problem and symptom match', 'bi-search'], ['Location', 'bi-geo-alt'], ['Provider quality', 'bi-star']] as $factor): ?><div class="match-factor"><i class="bi <?= $factor[1] ?>" aria-hidden="true"></i><span><?= htmlspecialchars($factor[0], ENT_QUOTES, 'UTF-8') ?></span><i class="bi bi-check2" aria-hidden="true"></i></div><?php endforeach; ?></div>
                <p class="match-note mb-0">Actual match results depend on the submitted request and available provider records.</p>
            </article>
        </div>
    </section>

    <section class="section-padding feature-section" id="about">
        <div class="container"><div class="row align-items-center g-5"><div class="col-lg-5"><span class="section-kicker">Built around useful details</span><h2>Make the service conversation easier to follow.</h2><p class="section-intro">ServeIQ keeps the original request visible alongside structured details and the responses that follow.</p><figure class="workflow-figure mt-4 mb-0"><img class="workflow-image" src="assets/images/serveiq-service-workflow.png" alt="A customer shares an overheating laptop with a local repair professional" loading="lazy" width="1536" height="1024"><figcaption>Customers can explain the issue before coordinating a service.</figcaption></figure></div><div class="col-lg-6 offset-lg-1"><div class="feature-list">
            <article class="feature-item"><span><i class="bi bi-fingerprint"></i></span><div><h3>ServiceDNA</h3><p>Shows extracted symptoms, context, urgency, and possible service types. The current baseline is rule-based, with its analysis method recorded.</p></div></article>
            <article class="feature-item"><span><i class="bi bi-signpost-split"></i></span><div><h3>Provider matching</h3><p>Ranks eligible providers using the project’s matching factors and displays provider details available in the database.</p></div></article>
            <article class="feature-item"><span><i class="bi bi-people"></i></span><div><h3>Assessments and ADCS</h3><p>Provider assessments can be compared for areas of agreement and difference; they are decision support, not a guarantee of diagnosis.</p></div></article>
            <article class="feature-item"><span><i class="bi bi-calendar-check"></i></span><div><h3>Booking workflow</h3><p>Customers and providers can coordinate a booking and follow its status through completion.</p></div></article>
            <article class="feature-item"><span><i class="bi bi-patch-check"></i></span><div><h3>Verified service reviews</h3><p>Reviews are tied to completed bookings and can be moderated in the admin workspace.</p></div></article>
        </div></div></div></div>
    </section>

    <section class="provider-cta-section"><div class="container"><div class="provider-cta-panel"><div><span class="section-kicker">For service professionals</span><h2>Bring your services into a clearer workflow.</h2><p class="mb-0">Create a provider account, add your services, and respond to customer requests through the provider workspace.</p></div><a class="btn btn-light btn-lg rounded-pill px-4" href="register.php?role=provider">Become a provider <i class="bi bi-arrow-up-right ms-1"></i></a></div></div></section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
