<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Request not found.');
}

$pdo = getDatabaseConnection();
$request = findCustomerRequest($pdo, (int)$requestId, (int)getUserId());
if (!$request) {
    http_response_code(404);
    exit('Request not found.');
}

$editableStatuses = ['draft', 'submitted'];
$errors = [];
$successMessage = '';
$form = [
    'title' => $request['title'],
    'description' => $request['description'],
    'category_id' => $request['category_id'] === null ? '' : (string)$request['category_id'],
    'city' => $request['city'],
    'area' => $request['area'] ?? '',
    'address' => $request['address'] ?? '',
    'urgency' => $request['urgency'],
    'contact_preference' => $request['contact_preference'],
];
$categories = $pdo->query('SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY category_name ASC')->fetchAll();
$categoryIds = array_map('intval', array_column($categories, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $removeImageId = filter_input(INPUT_POST, 'remove_image_id', FILTER_VALIDATE_INT);
    if ($removeImageId) {
        if (!in_array($request['status'], $editableStatuses, true)) {
            $errors[] = 'Supporting images cannot be changed after this request moves beyond the submitted stage.';
        } else {
            $imageStmt = $pdo->prepare('SELECT stored_name FROM request_images WHERE id = :image_id AND request_id = :request_id LIMIT 1');
            $imageStmt->execute(['image_id' => $removeImageId, 'request_id' => (int)$requestId]);
            $image = $imageStmt->fetch();
            if ($image) {
                $deleteImage = $pdo->prepare('DELETE FROM request_images WHERE id = :image_id AND request_id = :request_id');
                $deleteImage->execute(['image_id' => $removeImageId, 'request_id' => (int)$requestId]);
                writeAuditLog($pdo, 'request_image_removed', 'service_request', (int)$requestId, null, ['image_id' => $removeImageId]);
                $imagePath = __DIR__ . '/../uploads/requests/' . basename($image['stored_name']);
                if (is_file($imagePath)) {
                    unlink($imagePath);
                }
                $successMessage = 'Supporting image removed.';
            }
        }
    } elseif (!in_array($request['status'], $editableStatuses, true)) {
        $errors[] = 'This request can no longer be edited because its status is ' . requestStatusLabel($request['status']) . '.';
    } else {
        $form = [
            'title' => trim((string)($_POST['title'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'category_id' => trim((string)($_POST['category_id'] ?? '')),
            'city' => trim((string)($_POST['city'] ?? '')),
            'area' => trim((string)($_POST['area'] ?? '')),
            'address' => trim((string)($_POST['address'] ?? '')),
            'urgency' => (string)($_POST['urgency'] ?? 'medium'),
            'contact_preference' => (string)($_POST['contact_preference'] ?? 'email'),
        ];
        $errors = array_merge($errors, array_values(validateServiceRequest($form)));
        if ($form['category_id'] !== '' && (!ctype_digit($form['category_id']) || !in_array((int)$form['category_id'], $categoryIds, true))) $errors[] = 'Please choose a valid service category.';

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                $update = $pdo->prepare("UPDATE service_requests SET category_id = :category_id, title = :title, description = :description, city = :city, area = :area, address = :address, urgency = :urgency, contact_preference = :contact_preference WHERE id = :request_id AND customer_id = :customer_id AND status IN ('draft', 'submitted')");
                $update->execute([
                    'category_id' => $form['category_id'] === '' ? null : (int)$form['category_id'], 'title' => $form['title'], 'description' => $form['description'],
                    'city' => $form['city'], 'area' => $form['area'] === '' ? null : $form['area'], 'address' => $form['address'] === '' ? null : encryptSensitiveData($form['address']),
                    'urgency' => $form['urgency'], 'contact_preference' => $form['contact_preference'], 'request_id' => (int)$requestId, 'customer_id' => (int)getUserId(),
                ]);
                analyzeAndStoreServiceDna($pdo, (int)$requestId);
                refreshMatchingResultsForRequest($pdo, (int)$requestId);
                invalidateADCSAssessmentsForRequest($pdo, (int)$requestId);
                calculateADCSForRequest($pdo, (int)$requestId);
                $pdo->commit();
                writeAuditLog($pdo, 'request_updated', 'service_request', (int)$requestId, null, ['fields' => array_keys($form)]);
                $successMessage = 'Request and ServiceDNA updated. Matching was refreshed and prior assessments were invalidated.';
                $request = findCustomerRequest($pdo, (int)$requestId, (int)getUserId());
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($exception->getMessage());
                $errors[] = 'The request could not be updated and analyzed. Please try again.';
            }
        }
    }
}

$imageStmt = $pdo->prepare('SELECT id, original_name, stored_name FROM request_images WHERE request_id = :request_id ORDER BY id ASC');
$imageStmt->execute(['request_id' => (int)$requestId]);
$images = $imageStmt->fetchAll();

$pageTitle = 'Edit Request | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page request-page"><div class="container"><div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><span class="section-kicker">Customer Workspace</span><h1 class="mb-0">Edit Request</h1></div><a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">Back to Details</a></div>
<?php if (!in_array($request['status'], $editableStatuses, true) && empty($successMessage)): ?><div class="alert alert-warning">This request can no longer be edited because its status is <strong><?= htmlspecialchars(requestStatusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></strong>.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($successMessage !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (in_array($request['status'], $editableStatuses, true)): ?><form method="POST" id="editRequestForm" novalidate><?= csrfField() ?><div class="card shadow-sm border-0 rounded-4 p-4 p-md-5"><div class="row g-3"><div class="col-12"><label for="title" class="form-label">Problem Title</label><input type="text" id="title" name="title" maxlength="180" class="form-control" value="<?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?>" required></div><div class="col-12"><label for="description" class="form-label">Detailed Description</label><textarea id="description" name="description" maxlength="5000" minlength="20" rows="8" class="form-control" required><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea><small id="requestDescriptionCount" class="text-muted d-block text-end mt-2">0 / 5000</small></div><div class="col-md-6"><label for="category_id" class="form-label">Category</label><select id="category_id" name="category_id" class="form-select"><option value="">Not selected</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $form['category_id'] === (string)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label for="city" class="form-label">City</label><input type="text" id="city" name="city" maxlength="100" class="form-control" value="<?= htmlspecialchars($form['city'], ENT_QUOTES, 'UTF-8') ?>" required></div><div class="col-md-6"><label for="area" class="form-label">Area / Locality</label><input type="text" id="area" name="area" maxlength="100" class="form-control" value="<?= htmlspecialchars($form['area'], ENT_QUOTES, 'UTF-8') ?>"></div><div class="col-md-6"><label for="address" class="form-label">Detailed Address</label><input type="text" name="address" id="address" maxlength="255" class="form-control" value="<?= htmlspecialchars($form['address'], ENT_QUOTES, 'UTF-8') ?>"></div><div class="col-md-6"><label for="urgency" class="form-label">Urgency</label><select id="urgency" name="urgency" class="form-select"><option value="low" <?= $form['urgency'] === 'low' ? 'selected' : '' ?>>Low</option><option value="medium" <?= $form['urgency'] === 'medium' ? 'selected' : '' ?>>Medium</option><option value="high" <?= $form['urgency'] === 'high' ? 'selected' : '' ?>>High</option><option value="emergency" <?= $form['urgency'] === 'emergency' ? 'selected' : '' ?>>Emergency</option></select></div><div class="col-md-6"><label for="contact_preference" class="form-label">Preferred Contact</label><select id="contact_preference" name="contact_preference" class="form-select"><option value="phone" <?= $form['contact_preference'] === 'phone' ? 'selected' : '' ?>>Phone</option><option value="email" <?= $form['contact_preference'] === 'email' ? 'selected' : '' ?>>Email</option></select></div><div class="col-12 text-end"><button type="submit" class="btn btn-primary rounded-pill px-4">Save Changes</button></div></div></div></form><?php endif; ?>
<div class="card shadow-sm border-0 rounded-4 p-4 mt-4"><h2 class="h5">Supporting Images</h2><p class="text-muted">Remove images that are no longer relevant. This cannot be undone.</p><?php if (empty($images)): ?><p class="mb-0 text-muted">No supporting images attached.</p><?php else: ?><div class="row g-3"><?php foreach ($images as $image): ?><div class="col-6 col-md-3"><img src="../request_image.php?id=<?= (int)$image['id'] ?>" alt="<?= htmlspecialchars($image['original_name'], ENT_QUOTES, 'UTF-8') ?>" class="img-fluid rounded-3 border request-image"><form method="POST" class="mt-2" data-confirm="Remove this supporting image? This cannot be undone."><?= csrfField() ?><input type="hidden" name="remove_image_id" value="<?= (int)$image['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger w-100">Remove</button></form></div><?php endforeach; ?></div><?php endif; ?></div>
</div></main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
