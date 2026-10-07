<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$errors = [];
$form = [
    'title' => trim((string)($_GET['title'] ?? '')),
    'description' => trim((string)($_GET['description'] ?? '')),
    'category_id' => '',
    'city' => '',
    'area' => '',
    'latitude' => '',
    'longitude' => '',
    'address' => '',
    'urgency' => 'medium',
    'contact_preference' => 'email',
];

$categoriesStmt = $pdo->query('SELECT id, category_name, description FROM service_categories WHERE is_active = 1 ORDER BY category_name ASC');
$categories = $categoriesStmt->fetchAll();
$categoryIds = array_map('intval', array_column($categories, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $form = [
        'title' => trim((string)($_POST['title'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'category_id' => trim((string)($_POST['category_id'] ?? '')),
        'city' => trim((string)($_POST['city'] ?? '')),
        'area' => trim((string)($_POST['area'] ?? '')),
        'latitude' => trim((string)($_POST['latitude'] ?? '')),
        'longitude' => trim((string)($_POST['longitude'] ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
        'urgency' => (string)($_POST['urgency'] ?? 'medium'),
        'contact_preference' => (string)($_POST['contact_preference'] ?? 'email'),
    ];

    $errors = array_values(validateServiceRequest($form));
    if ($form['category_id'] !== '' && (!ctype_digit($form['category_id']) || !in_array((int)$form['category_id'], $categoryIds, true))) {
        $errors[] = 'Please choose a valid service category.';
    }
    $hasLatitude = $form['latitude'] !== '';
    $hasLongitude = $form['longitude'] !== '';
    if ($hasLatitude !== $hasLongitude || ($hasLatitude && (!is_numeric($form['latitude']) || !is_numeric($form['longitude']) || (float)$form['latitude'] < -90 || (float)$form['latitude'] > 90 || (float)$form['longitude'] < -180 || (float)$form['longitude'] > 180))) {
        $errors[] = 'Location coordinates are invalid. Please use the location button again or clear the location.';
    }

    $files = $_FILES['images'] ?? null;
    $uploadedFiles = [];
    if ($files && isset($files['name']) && is_array($files['name'])) {
        $fileCount = count(array_filter($files['name'], static fn($name) => (string)$name !== ''));
        if ($fileCount > 5) {
            $errors[] = 'You can upload a maximum of 5 images.';
        }

        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
        for ($index = 0; $index < count($files['name']); $index++) {
            if ((string)$files['name'][$index] === '') {
                continue;
            }
            $fileError = (int)$files['error'][$index];
            $fileSize = (int)$files['size'][$index];
            $tmpName = (string)$files['tmp_name'][$index];
            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = 'One of the selected images could not be uploaded.';
                continue;
            }
            if ($fileSize > 5 * 1024 * 1024) {
                $errors[] = 'Each image must be 5MB or smaller.';
                continue;
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmpName) : false;
            if ($finfo) {
                finfo_close($finfo);
            }
            $imageInfo = @getimagesize($tmpName);
            if (!in_array($mime, $allowedMime, true) || $imageInfo === false) {
                $errors[] = 'Only valid JPG, PNG, and WEBP images are allowed.';
                continue;
            }
            $uploadedFiles[] = [
                'tmp_name' => $tmpName,
                'original_name' => basename((string)$files['name'][$index]),
                'mime' => $mime,
                'size' => $fileSize,
            ];
        }
    }

    if (empty($errors)) {
        $storedFiles = [];
        try {
            $pdo->beginTransaction();
            $requestStmt = $pdo->prepare(
                'INSERT INTO service_requests (customer_id, category_id, title, description, city, area, latitude, longitude, address, urgency, contact_preference, status)
                 VALUES (:customer_id, :category_id, :title, :description, :city, :area, :latitude, :longitude, :address, :urgency, :contact_preference, :status)'
            );
            $requestStmt->execute([
                'customer_id' => (int)getUserId(),
                'category_id' => $form['category_id'] === '' ? null : (int)$form['category_id'],
                'title' => $form['title'],
                'description' => $form['description'],
                'city' => $form['city'],
                'area' => $form['area'] === '' ? null : $form['area'],
                'latitude' => $hasLatitude ? (float)$form['latitude'] : null,
                'longitude' => $hasLongitude ? (float)$form['longitude'] : null,
                'address' => $form['address'] === '' ? null : encryptSensitiveData($form['address']),
                'urgency' => $form['urgency'],
                'contact_preference' => $form['contact_preference'],
                'status' => 'submitted',
            ]);
            $requestId = (int)$pdo->lastInsertId();

            $uploadDir = __DIR__ . '/../uploads/requests/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Upload directory could not be created.');
            }
            $imageStmt = $pdo->prepare(
                'INSERT INTO request_images (request_id, original_name, stored_name, file_path, mime_type, file_size)
                 VALUES (:request_id, :original_name, :stored_name, :file_path, :mime_type, :file_size)'
            );

            foreach ($uploadedFiles as $file) {
                $extension = match ($file['mime']) {
                    'image/jpeg' => '.jpg',
                    'image/png' => '.png',
                    'image/webp' => '.webp',
                    default => throw new RuntimeException('Unsupported image type.'),
                };
                $storedName = bin2hex(random_bytes(16)) . $extension;
                $targetPath = $uploadDir . $storedName;
                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    throw new RuntimeException('An image could not be stored.');
                }
                $storedFiles[] = $targetPath;
                $imageStmt->execute([
                    'request_id' => $requestId,
                    'original_name' => $file['original_name'],
                    'stored_name' => $storedName,
                    'file_path' => 'uploads/requests/' . $storedName,
                    'mime_type' => $file['mime'],
                    'file_size' => $file['size'],
                ]);
            }

            analyzeAndStoreServiceDna($pdo, $requestId);
            refreshMatchingResultsForRequest($pdo, $requestId);
            calculateADCSForRequest($pdo, $requestId);

            $pdo->commit();
            writeAuditLog($pdo, 'request_created', 'service_request', $requestId, null, ['category_id' => $form['category_id'] === '' ? null : (int)$form['category_id'], 'status' => 'submitted']);
            header('Location: request_details.php?id=' . $requestId . '&created=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($storedFiles as $storedFile) {
                if (is_file($storedFile)) {
                    unlink($storedFile);
                }
            }
            error_log($exception->getMessage());
            $errors[] = 'Your request could not be saved. Please try again.';
        }
    }
}

$pageTitle = 'Describe Your Problem | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page request-page">
    <div class="container">
        <div class="mb-4">
            <span class="section-kicker">Customer Workspace</span>
            <h1 class="mb-2">Describe Your Problem</h1>
            <p class="text-muted mb-0">Give us the details that will help a future service expert understand the situation.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="requestForm">
            <?= csrfField() ?>
            <section class="request-section-card">
                <div class="request-section-heading"><span class="request-step">01</span><div><h2>Tell us what's wrong</h2><p>Start with the problem in your own words.</p></div></div>
                <div class="mb-3">
                    <label for="title" class="form-label">Problem Title</label>
                    <input type="text" id="title" name="title" class="form-control" minlength="2" maxlength="180" value="<?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div>
                    <label for="description" class="form-label">Detailed Problem Description</label>
                    <textarea id="description" name="description" class="form-control" rows="8" minlength="20" maxlength="5000" required placeholder="Describe what happened, when the problem started, what symptoms you notice, and anything important that may help a service provider understand the issue..."><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="d-flex justify-content-between mt-2"><small class="text-muted">At least 20 characters</small><small id="requestDescriptionCount" class="text-muted">0 / 5000</small></div>
                </div>
            </section>

            <section class="request-section-card">
                <div class="request-section-heading"><span class="request-step">02</span><div><h2>Help us understand better</h2><p>A category is optional. Future ServiceDNA analysis can identify it from your description.</p></div></div>
                <label for="category_id" class="form-label">Service Category <span class="text-muted">(optional)</span></label>
                <select id="category_id" name="category_id" class="form-select">
                    <option value="">I am not sure yet</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= $form['category_id'] === (string)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="request-section-card">
                <div class="request-section-heading"><span class="request-step">03</span><div><h2>Where should help happen?</h2><p>City and area are enough for the initial matching stage.</p></div></div>
                <div class="row g-3">
                    <div class="col-md-6"><label for="city" class="form-label">City</label><input type="text" id="city" name="city" maxlength="100" class="form-control" value="<?= htmlspecialchars($form['city'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="col-md-6"><label for="area" class="form-label">Area / Locality <span class="text-muted">(optional)</span></label><input type="text" id="area" name="area" maxlength="100" class="form-control" value="<?= htmlspecialchars($form['area'], ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-12"><label for="address" class="form-label">Detailed Address <span class="text-muted">(optional)</span></label><input type="text" id="address" name="address" maxlength="255" class="form-control" value="<?= htmlspecialchars($form['address'], ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-12"><button type="button" class="btn btn-outline-primary btn-sm" data-use-location="request">Use my current location</button><span class="small text-muted ms-2" data-location-status>Optional. Coordinates enable distance filtering for this request.</span><input type="hidden" name="latitude" value="<?= htmlspecialchars($form['latitude'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="longitude" value="<?= htmlspecialchars($form['longitude'], ENT_QUOTES, 'UTF-8') ?>"></div>
                </div>
            </section>

            <section class="request-section-card">
                <div class="request-section-heading"><span class="request-step">04</span><div><h2>Urgency and contact</h2><p>Help providers understand timing and your preferred first contact.</p></div></div>
                <div class="row g-3">
                    <div class="col-md-6"><label for="urgency" class="form-label">Urgency</label><select id="urgency" name="urgency" class="form-select"><option value="low" <?= $form['urgency'] === 'low' ? 'selected' : '' ?>>Low - Can wait</option><option value="medium" <?= $form['urgency'] === 'medium' ? 'selected' : '' ?>>Medium - Needs attention soon</option><option value="high" <?= $form['urgency'] === 'high' ? 'selected' : '' ?>>High - Needs urgent service</option><option value="emergency" <?= $form['urgency'] === 'emergency' ? 'selected' : '' ?>>Emergency - Immediate attention required</option></select></div>
                    <div class="col-md-6"><label for="contact_preference" class="form-label">Preferred Contact</label><select id="contact_preference" name="contact_preference" class="form-select"><option value="phone" <?= $form['contact_preference'] === 'phone' ? 'selected' : '' ?>>Phone</option><option value="email" <?= $form['contact_preference'] === 'email' ? 'selected' : '' ?>>Email</option><option value="messaging" disabled>Platform Messaging (coming later)</option></select></div>
                </div>
            </section>

            <section class="request-section-card">
                <div class="request-section-heading"><span class="request-step">05</span><div><h2>Supporting images</h2><p>Images can help explain a damaged part, error screen, or leak.</p></div></div>
                <label for="images" class="form-label">Upload images <span class="text-muted">(optional, up to 5)</span></label>
                <input type="file" id="images" name="images[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                <div class="d-flex justify-content-between mt-2"><small class="text-muted">JPG, PNG, or WEBP. Maximum 5MB each.</small><small id="requestImageHint" class="text-muted"></small></div>
            </section>

            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mt-4"><a href="dashboard.php" class="btn btn-outline-secondary">Save nothing and go back</a><button type="submit" class="btn btn-primary btn-lg rounded-pill px-4">Submit Problem Request <i class="bi bi-arrow-right ms-1"></i></button></div>
        </form>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
