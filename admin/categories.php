<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDatabaseConnection();
$errors = [];
$successMessage = '';
$mode = 'create';
$editingCategoryId = null;
$formData = [
    'category_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editingCategoryId = (int) $_GET['edit'];
    $mode = 'edit';
    $existingStmt = $pdo->prepare('SELECT * FROM service_categories WHERE id = :id LIMIT 1');
    $existingStmt->execute(['id' => $editingCategoryId]);
    $existingCategory = $existingStmt->fetch();

    if ($existingCategory) {
        $formData = [
            'category_name' => $existingCategory['category_name'],
            'description' => $existingCategory['description'],
            'is_active' => (string) $existingCategory['is_active'],
        ];
    } else {
        $errors[] = 'The selected category was not found.';
        $mode = 'create';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? 'save';
    $isDeleteRequest = isset($_POST['delete_category']);

    if ($isDeleteRequest || $action === 'delete') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $errors[] = 'Invalid category selected for deletion.';
        } else {
            $deactivateStmt = $pdo->prepare('UPDATE service_categories SET is_active = 0 WHERE id = :id');
            $deactivateStmt->execute(['id' => $categoryId]);
            if ($deactivateStmt->rowCount() > 0) {
                writeAuditLog($pdo, 'category_deactivated', 'service_category', $categoryId, null, ['is_active' => false]);
                $successMessage = 'Category deactivated. Existing services and requests retain their history.';
            } else {
                $errors[] = 'The selected category was not found or is already inactive.';
            }
        }
    } else {
        $formData = [
            'category_name' => trim((string)($_POST['category_name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? '1' : '0',
        ];

        $errors = array_values(validateCategoryData($formData));

        if (empty($errors)) {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            try {
                if ($categoryId > 0) {
                    $sql = 'UPDATE service_categories SET category_name = :category_name, description = :description, is_active = :is_active WHERE id = :id';
                    $params = ['category_name' => $formData['category_name'], 'description' => $formData['description'], 'is_active' => (int)$formData['is_active'], 'id' => $categoryId];
                    $successMessage = 'Category updated successfully.';
                } else {
                    $sql = 'INSERT INTO service_categories (category_name, description, is_active) VALUES (:category_name, :description, :is_active)';
                    $params = ['category_name' => $formData['category_name'], 'description' => $formData['description'], 'is_active' => (int)$formData['is_active']];
                    $successMessage = 'Category created successfully.';
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                writeAuditLog($pdo, $categoryId > 0 ? 'category_updated' : 'category_created', 'service_category', $categoryId > 0 ? $categoryId : (int)$pdo->lastInsertId(), null, ['category_name' => $formData['category_name'], 'is_active' => $formData['is_active'] === '1']);
                $formData = ['category_name' => '', 'description' => '', 'is_active' => '1'];
                $mode = 'create';
                $editingCategoryId = null;
            } catch (Exception $e) {
                $errors[] = 'Unable to save this category. Please try again.';
                error_log($e->getMessage());
            }
        }
    }
}

$categoriesStmt = $pdo->query('SELECT * FROM service_categories ORDER BY is_active DESC, category_name ASC');
$categories = $categoriesStmt->fetchAll();

$pageTitle = 'Category Management | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Admin Workspace</span>
                <h1 class="mb-0">Category Management</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
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
            <h3 class="mb-3"><?= $mode === 'edit' ? 'Edit Category' : 'Create Category' ?></h3>
            <form method="POST" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="category_id" value="<?= htmlspecialchars((string)$editingCategoryId, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-6">
                    <label for="category_name" class="form-label">Category Name</label>
                    <input type="text" id="category_name" name="category_name" class="form-control" maxlength="120" value="<?= htmlspecialchars($formData['category_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $formData['is_active'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="4" maxlength="5000"><?= htmlspecialchars($formData['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <?php if ($mode === 'edit'): ?>
                        <a href="categories.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= $mode === 'edit' ? 'Update Category' : 'Create Category' ?></button>
                </div>
            </form>
        </div>

        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
            <h3 class="mb-3">Existing Categories</h3>
            <?php if (empty($categories)): ?>
                <div class="text-center py-5 border rounded-4 bg-light">
                    <h5>No categories available</h5>
                    <p class="text-muted">Create the first service category.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($categories as $category): ?>
                        <div class="col-12 col-lg-6">
                            <div class="border rounded-4 p-3 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0"><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></h5>
                                    <span class="badge bg-<?= (int)$category['is_active'] === 1 ? 'success' : 'secondary' ?> rounded-pill"><?= (int)$category['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                                </div>
                                <p class="text-muted mb-3"><?= htmlspecialchars($category['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="d-flex gap-2">
                                    <a href="categories.php?edit=<?= (int)$category['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="POST" data-confirm="Deactivate this category? Existing services and request history will remain available.">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="delete_category" value="1">
                                        <input type="hidden" name="category_id" value="<?= (int)$category['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" <?= (int)$category['is_active'] === 0 ? 'disabled' : '' ?>>Deactivate</button>
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
