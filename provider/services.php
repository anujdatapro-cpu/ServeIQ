<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$pdo = getDatabaseConnection();
$userId = (int) getUserId();

$profileStmt = $pdo->prepare('SELECT id, business_name, availability_status FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$providerProfile = $profileStmt->fetch();

$errors = [];
$successMessage = '';
$mode = 'create';
$editingServiceId = null;
$serviceData = [
    'service_name' => '',
    'category_id' => '',
    'description' => '',
    'base_price' => '',
    'is_active' => '1',
];

if (!$providerProfile) {
    $pageTitle = 'Provider Services | ServeIQ';
    $basePath = '../';
    require __DIR__ . '/../includes/header.php';
    ?>
    <main class="dashboard-page">
        <div class="container">
            <div class="alert alert-warning">
                Your business profile is not created yet. Please complete your provider profile before adding services.
            </div>
            <div class="d-flex gap-2">
                <a href="profile.php" class="btn btn-primary">Create Business Profile</a>
                <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
            </div>
        </div>
    </main>
    <?php require __DIR__ . '/../includes/footer.php'; ?>
    <?php exit; ?>
<?php }

$categoriesStmt = $pdo->query("SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY category_name ASC");
$categories = $categoriesStmt->fetchAll();

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editingServiceId = (int) $_GET['edit'];
    $mode = 'edit';
    $editStmt = $pdo->prepare('SELECT * FROM services WHERE id = :service_id AND provider_id = :provider_id LIMIT 1');
    $editStmt->execute(['service_id' => $editingServiceId, 'provider_id' => (int) $providerProfile['id']]);
    $existingService = $editStmt->fetch();

    if ($existingService) {
        $serviceData = [
            'service_name' => $existingService['service_name'],
            'category_id' => (string) $existingService['category_id'],
            'description' => $existingService['description'],
            'base_price' => (string) $existingService['base_price'],
            'is_active' => (string) $existingService['is_active'],
        ];
    } else {
        $errors[] = 'You can only edit your own services.';
        $mode = 'create';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $isDeleteRequest = isset($_POST['delete_service']);
    $action = $_POST['action'] ?? 'save';

    if ($isDeleteRequest || $action === 'delete') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $deleteStmt = $pdo->prepare('SELECT id FROM services WHERE id = :service_id AND provider_id = :provider_id LIMIT 1');
        $deleteStmt->execute(['service_id' => $serviceId, 'provider_id' => (int) $providerProfile['id']]);
        $serviceToDelete = $deleteStmt->fetch();

        if (!$serviceToDelete) {
            $errors[] = 'This service does not belong to your business.';
        } else {
            $deleteSql = $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = :service_id AND provider_id = :provider_id');
            $deleteSql->execute(['service_id' => $serviceId, 'provider_id' => (int) $providerProfile['id']]);
            writeAuditLog($pdo, 'service_deactivated', 'service', $serviceId, null, ['is_active' => false]);
            $successMessage = 'Service deactivated. Existing booking history is retained.';
            $mode = 'create';
            $serviceData = ['service_name' => '', 'category_id' => '', 'description' => '', 'base_price' => '', 'is_active' => '1'];
        }
    } else {
        $serviceData = [
            'service_name' => trim((string)($_POST['service_name'] ?? '')),
            'category_id' => trim((string)($_POST['category_id'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'base_price' => trim((string)($_POST['base_price'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? '1' : '0',
        ];

        if ($serviceData['category_id'] === '' || !ctype_digit($serviceData['category_id'])) {
            $errors[] = 'Please select a valid service category.';
        }
        $errors = array_merge($errors, array_values(validateServiceData($serviceData)));

        $categoryCount = $pdo->prepare('SELECT id FROM service_categories WHERE id = :category_id AND is_active = 1 LIMIT 1');
        $categoryCount->execute(['category_id' => (int)$serviceData['category_id']]);
        if (!$categoryCount->fetch()) {
            $errors[] = 'Selected category is unavailable.';
        }

        if (empty($errors)) {
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $providerId = (int) $providerProfile['id'];

            if ($serviceId > 0) {
                $ownershipStmt = $pdo->prepare('SELECT id FROM services WHERE id = :service_id AND provider_id = :provider_id LIMIT 1');
                $ownershipStmt->execute(['service_id' => $serviceId, 'provider_id' => $providerId]);
                if (!$ownershipStmt->fetch()) {
                    $errors[] = 'You can only edit your own services.';
                }
            }

            if (empty($errors)) {
                try {
                    if ($serviceId > 0) {
                        $sql = 'UPDATE services SET service_name = :service_name, category_id = :category_id, description = :description, base_price = :base_price, is_active = :is_active WHERE id = :service_id AND provider_id = :provider_id';
                        $params = [
                            'service_name' => $serviceData['service_name'],
                            'category_id' => (int)$serviceData['category_id'],
                            'description' => $serviceData['description'],
                            'base_price' => (float)$serviceData['base_price'],
                            'is_active' => (int)$serviceData['is_active'],
                            'service_id' => $serviceId,
                            'provider_id' => $providerId,
                        ];
                        $successMessage = 'Service updated successfully.';
                    } else {
                        $sql = 'INSERT INTO services (provider_id, category_id, service_name, description, base_price, is_active) VALUES (:provider_id, :category_id, :service_name, :description, :base_price, :is_active)';
                        $params = [
                            'provider_id' => $providerId,
                            'category_id' => (int)$serviceData['category_id'],
                            'service_name' => $serviceData['service_name'],
                            'description' => $serviceData['description'],
                            'base_price' => (float)$serviceData['base_price'],
                            'is_active' => (int)$serviceData['is_active'],
                        ];
                        $successMessage = 'Service added successfully.';
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    writeAuditLog($pdo, $serviceId > 0 ? 'service_updated' : 'service_created', 'service', $serviceId > 0 ? $serviceId : (int)$pdo->lastInsertId(), null, ['service_name' => $serviceData['service_name'], 'category_id' => (int)$serviceData['category_id'], 'is_active' => $serviceData['is_active'] === '1']);
                    $serviceData = ['service_name' => '', 'category_id' => '', 'description' => '', 'base_price' => '', 'is_active' => '1'];
                    $mode = 'create';
                } catch (Exception $e) {
                    $errors[] = 'Database error while saving the service.';
                    error_log($e->getMessage());
                }
            }
        }
    }
}

$servicesStmt = $pdo->prepare('SELECT s.*, c.category_name FROM services s INNER JOIN service_categories c ON c.id = s.category_id WHERE s.provider_id = :provider_id ORDER BY s.created_at DESC');
$servicesStmt->execute(['provider_id' => (int) $providerProfile['id']]);
$services = $servicesStmt->fetchAll();

$pageTitle = 'Service Management | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="section-kicker">Provider Workspace</span>
                <h1 class="mb-0">Service Management</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                <a href="profile.php" class="btn btn-outline-primary">Business Profile</a>
                <form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form>
            </div>
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

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-5">
            <h3 class="mb-3"><?= $mode === 'edit' ? 'Edit Service' : 'Add New Service' ?></h3>
            <form method="POST" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="service_id" value="<?= htmlspecialchars((string)($editingServiceId ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-6">
                    <label for="service_name" class="form-label">Service Name</label>
                    <input type="text" id="service_name" name="service_name" class="form-control" value="<?= htmlspecialchars($serviceData['service_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="category_id" class="form-label">Service Category</label>
                    <select id="category_id" name="category_id" class="form-select" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int)$category['id'] ?>" <?= (string)$serviceData['category_id'] === (string)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="4" required><?= htmlspecialchars($serviceData['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="base_price" class="form-label">Base / Starting Price</label>
                    <input type="number" id="base_price" name="base_price" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars($serviceData['base_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $serviceData['is_active'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Service active</label>
                    </div>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <?php if ($mode === 'edit'): ?>
                        <a href="services.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= $mode === 'edit' ? 'Update Service' : 'Add Service' ?></button>
                </div>
            </form>
        </div>

        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h3 class="mb-0">Your Services</h3>
                <span class="badge bg-primary rounded-pill"><?= count($services) ?> total</span>
            </div>

            <?php if (empty($services)): ?>
                <div class="text-center py-5 border rounded-4 bg-light">
                    <i class="bi bi-tools fs-1 text-muted mb-3 d-block"></i>
                    <h5>No services added yet</h5>
                    <p class="text-muted">Add your first service to start accepting customers.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($services as $service): ?>
                        <div class="col-12">
                            <div class="border rounded-4 p-3 d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                        <h5 class="mb-0"><?= htmlspecialchars($service['service_name'], ENT_QUOTES, 'UTF-8') ?></h5>
                                        <span class="badge bg-<?= (int)$service['is_active'] === 1 ? 'success' : 'secondary' ?> rounded-pill"><?= (int)$service['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                    </div>
                                    <p class="text-muted mb-1"><?= htmlspecialchars($service['category_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="mb-2"><?= htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <strong class="text-primary">₹<?= number_format((float)$service['base_price'], 2) ?></strong>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="services.php?edit=<?= (int)$service['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="POST" data-confirm="Deactivate this service? Existing booking history will remain available.">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="delete_service" value="1">
                                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
