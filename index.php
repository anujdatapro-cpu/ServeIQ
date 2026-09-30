<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';

$pageTitle = 'ServeIQ | Find the right service';
$basePath = '';
$problemAction = !empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer'
    ? 'customer/create_request.php'
    : 'login.php?redirect=customer%2Fcreate_request.php';
require __DIR__ . '/includes/header.php';
?>

<main>
    <section class="hero-section">
        <div class="hero-grid-pattern" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <div class="eyebrow"><span class="eyebrow-dot"></span> AI-powered service discovery</div>
                    <h1>Describe your problem.<br><span class="text-gradient">Find the right service.</span></h1>
                    <p class="hero-description">Tell us what is wrong in your own words. ServeIQ helps you understand the issue and connects you with local professionals who can solve it.</p>
                    <div class="hero-actions d-flex flex-wrap gap-3">
                        <a class="btn btn-primary btn-lg rounded-pill px-4" href="#problem-box">Describe a problem <i class="bi bi-arrow-down ms-2"></i></a>
                        <a class="btn btn-outline-dark btn-lg rounded-pill px-4" href="#how-it-works">See how it works</a>
                    </div>
                    <div class="trust-row">
                        <div class="avatar-stack" aria-hidden="true"><span>R</span><span>A</span><span>M</span><span><i class="bi bi-plus"></i></span></div>
                        <span>Helping people make better service decisions</span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="problem-card" id="problem-box">
                        <div class="problem-card-header"><span class="status-pulse"></span><span>Start with the problem</span><i class="bi bi-stars ms-auto"></i></div>
                        <label class="visually-hidden" for="problemDescription">Describe your problem</label>
                        <textarea id="problemDescription" class="form-control problem-textarea" maxlength="1000" placeholder="Example: My laptop overheats after gaming for 20 minutes, the fan becomes loud and FPS drops..."></textarea>
                        <div class="problem-card-footer"><span id="characterCount">0 / 1000</span><a id="problemSubmitLink" class="btn btn-dark rounded-pill px-3" href="<?= htmlspecialchars($problemAction, ENT_QUOTES, 'UTF-8') ?>">Analyze my problem <i class="bi bi-arrow-up-right ms-1"></i></a></div>
                    </div>
                    <div class="floating-insight"><span class="insight-icon"><i class="bi bi-lightbulb"></i></span><span><strong>Smart matching</strong><small>Built around your situation</small></span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-padding categories-section" id="services">
        <div class="container">
            <div class="section-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
                <div><span class="section-kicker">Explore services</span><h2>Whatever needs fixing,<br>start with the facts.</h2></div>
                <p class="section-intro">From a noisy laptop to a leaky tap, explain the situation and let the right expertise come to you.</p>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-6 col-lg-3"><div class="category-card"><span class="category-icon blue"><i class="bi bi-laptop"></i></span><h3>Computer repair</h3><span>Hardware, software &amp; more</span></div></div>
                <div class="col-6 col-lg-3"><div class="category-card"><span class="category-icon cyan"><i class="bi bi-phone"></i></span><h3>Mobile repair</h3><span>Screen, battery &amp; device care</span></div></div>
                <div class="col-6 col-lg-3"><div class="category-card"><span class="category-icon amber"><i class="bi bi-lightning-charge"></i></span><h3>Electrical</h3><span>Safe, reliable home solutions</span></div></div>
                <div class="col-6 col-lg-3"><div class="category-card"><span class="category-icon green"><i class="bi bi-tools"></i></span><h3>Home services</h3><span>Repairs, cleaning &amp; maintenance</span></div></div>
            </div>
        </div>
    </section>

    <section class="section-padding process-section" id="how-it-works">
        <div class="container">
            <div class="section-heading text-center mx-auto"><span class="section-kicker">A clearer way forward</span><h2>From confusion to confidence.</h2><p class="section-intro mx-auto">Skip the endless calls and repeated explanations. ServeIQ turns your situation into a service decision you can understand.</p></div>
            <div class="row g-4 process-grid">
                <div class="col-md-4"><div class="process-card"><span class="process-number">01</span><i class="bi bi-chat-square-text process-icon"></i><h3>Describe</h3><p>Share what happened, when it happens, and anything you have noticed.</p></div></div>
                <div class="col-md-4"><div class="process-card featured"><span class="process-number">02</span><i class="bi bi-diagram-3 process-icon"></i><h3>Understand</h3><p>Your details become a clear problem fingerprint with likely service needs.</p></div></div>
                <div class="col-md-4"><div class="process-card"><span class="process-number">03</span><i class="bi bi-person-check process-icon"></i><h3>Connect</h3><p>Compare relevant local professionals, responses, and quotations.</p></div></div>
            </div>
        </div>
    </section>

    <section class="section-padding feature-section" id="about">
        <div class="container"><div class="row align-items-center g-5"><div class="col-lg-5"><span class="section-kicker">Why ServeIQ</span><h2>A better brief gets a better answer.</h2><p class="section-intro">Good service starts before the first conversation. We help you communicate the details that matter.</p><a href="register.php" class="text-link">Get started today <i class="bi bi-arrow-right"></i></a></div><div class="col-lg-6 offset-lg-1"><div class="feature-list"><div class="feature-item"><span><i class="bi bi-fingerprint"></i></span><div><h3>Problem fingerprint</h3><p>See the symptoms, urgency, and possible service areas captured clearly.</p></div></div><div class="feature-item"><span><i class="bi bi-bar-chart"></i></span><div><h3>Transparent comparison</h3><p>Review provider experience, availability, ratings, and quotations together.</p></div></div><div class="feature-item"><span><i class="bi bi-shield-check"></i></span><div><h3>Local and considered</h3><p>Find professionals matched to your category and location, not just a keyword.</p></div></div></div></div></div></div>
    </section>

    <section class="cta-section"><div class="container"><div class="cta-panel"><div><span class="section-kicker">Your next fix starts here</span><h2>Stop explaining the same problem twice.</h2></div><a class="btn btn-light btn-lg rounded-pill px-4" href="register.php">Find your service <i class="bi bi-arrow-up-right ms-1"></i></a></div></div></section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>