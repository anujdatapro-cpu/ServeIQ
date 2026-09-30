<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$userId = (int) getUserId();
$pdo = getDatabaseConnection();
$profile = $pdo->prepare('SELECT * FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profile->execute(['user_id' => $userId]);
$profileData = $profile->fetch();

$reputation = [
    'average_rating' => 0.0,
    'review_count' => 0,
    'completed_services' => 0,
    'rating_distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
];
if ($profileData) {
    $reputation = getProviderReputation($pdo, (int)$profileData['id']);
}

$errors = [];
$successMessage = '';
$formData = [
    'business_name' => '',
    'phone' => '',
    'address' => '',
    'city' => '',
    'area' => '',
    'experience_years' => '1',
    'description' => '',
    'availability_status' => 'available',
];

if ($profileData) {
    foreach ($formData as $key => $value) {
        $formData[$key] = $profileData[$key] ?? $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $formData = [
        'business_name' => trim((string)($_POST['business_name'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
        'city' => trim((string)($_POST['city'] ?? '')),
        'area' => trim((string)($_POST['area'] ?? '')),
        'experience_years' => (int)($_POST['experience_years'] ?? 0),
        'description' => trim((string)($_POST['description'] ?? '')),
        'availability_status' => in_array((string)($_POST['availability_status'] ?? ''), ['available', 'busy', 'offline'], true) ? (string)$_POST['availability_status'] : 'available',
    ];

    if ($formData['business_name'] === '') {
        $errors[] = 'Business name is required.';
    }
    if ($formData['phone'] === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($formData['address'] === '') {
        $errors[] = 'Address is required.';
    }
    if ($formData['city'] === '') {
        $errors[] = 'City is required.';
    }
    if ($formData['experience_years'] < 0 || $formData['experience_years'] > 80) {
        $errors[] = 'Years of experience must be between 0 and 80.';
    }
    if (empty($formData['availability_status'])) {
        $errors[] = 'Availability status is required.';
    }

    $uploadedImage = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was an issue uploading your profile image.';
        } else {
            $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($detectedMime, $allowedMime, true)) {
                $errors[] = 'Only JPG, PNG, and WEBP images are allowed.';
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile image must be smaller than 2MB.';
            }

            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                $errors[] = 'Uploaded file is not a valid image.';
            }

            if ($detectedMime === 'image/jpeg' || $detectedMime === 'image/png' || $detectedMime === 'image/webp') {
                $uploadedImage = $file;
            }
        }
    }

    if (empty($errors)) {
        $imagePath = $profileData['profile_image'] ?? null;

        if ($uploadedImage) {
            $uploadDir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $extension = match ($detectedMime ?? '') {
                'image/jpeg' => '.jpg',
                'image/png' => '.png',
                'image/webp' => '.webp',
                default => '.jpg',
            };

            $newName = 'profile_' . uniqid('', true) . $extension;
            $targetPath = $uploadDir . $newName;

            if (!move_uploaded_file($uploadedImage['tmp_name'], $targetPath)) {
                $errors[] = 'The image could not be saved. Please try again.';
            } else {
                $imagePath = 'uploads/profiles/' . $newName;
                if (!empty($profileData['profile_image']) && $profileData['profile_image'] !== $imagePath) {
                    $oldFile = __DIR__ . '/../' . $profileData['profile_image'];
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            if ($profileData) {
                $sql = 'UPDATE provider_profiles
                        SET business_name = :business_name,
                            phone = :phone,
                            address = :address,
                            city = :city,
                            area = :area,
                            experience_years = :experience_years,
                            description = :description,
                            availability_status = :availability_status,
                            profile_image = :profile_image
                        WHERE user_id = :user_id';
                $params = [
                    'business_name' => $formData['business_name'],
                    'phone' => $formData['phone'],
                    'address' => $formData['address'],
                    'city' => $formData['city'],
                    'area' => $formData['area'],
                    'experience_years' => $formData['experience_years'],
                    'description' => $formData['description'],
                    'availability_status' => $formData['availability_status'],
                    'profile_image' => $imagePath ?? ($profileData['profile_image'] ?? null),
                    'user_id' => $userId,
                ];
            } else {
                $sql = 'INSERT INTO provider_profiles (user_id, business_name, phone, address, city, area, experience_years, description, availability_status, profile_image)
                        VALUES (:user_id, :business_name, :phone, :address, :city, :area, :experience_years, :description, :availability_status, :profile_image)';
                $params = [
                    'user_id' => $userId,
                    'business_name' => $formData['business_name'],
                    'phone' => $formData['phone'],
                    'address' => $formData['address'],
                    'city' => $formData['city'],
                    'area' => $formData['area'],
                    'experience_years' => $formData['experience_years'],
                    'description' => $formData['description'],
                    'availability_status' => $formData['availability_status'],
                    'profile_image' => $imagePath ?? null,
                ];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $successMessage = $profileData ? 'Business profile updated successfully.' : 'Business profile created successfully.';
            $profileData = $pdo->prepare('SELECT * FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
            $profileData->execute(['user_id' => $userId]);
            $profileData = $profileData->fetch();
            foreach ($formData as $key => $value) {
                $formData[$key] = $profileData[$key] ?? $value;
            }
            if ($profileData) {
                $reputation = getProviderReputation($pdo, (int)$profileData['id']);
            }
        } catch (Exception $e) {
            $errors[] = 'Unable to save your profile at the moment. Please retry.';
            error_log($e->getMessage());
        }
    }
}

$pageTitle = 'Business Profile | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="section-kicker">Provider Workspace</span>
                <h1 class="mb-0">Business Profile</h1>
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

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 p-4 text-center mb-4">
                    <div class="mb-3">
                        <?php
                        $avatarPath = $profileData['profile_image'] ?? null;
                        if (!empty($avatarPath) && file_exists(__DIR__ . '/../' . $avatarPath)) {
                            $avatarSrc = '../' . $avatarPath;
                        } else {
                            $avatarSrc = '../assets/images/default-avatar.png';
                        }
                        ?>
                        <img src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Profile avatar" class="rounded-circle border" style="width: 120px; height: 120px; object-fit: cover; background: #f8fafc;">
                    </div>
                    <h4 class="mb-1"><?= htmlspecialchars($formData['business_name'] ?: 'Your Business', ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="text-muted mb-3"><?= htmlspecialchars($formData['city'] ?: 'City not set', ENT_QUOTES, 'UTF-8') ?></p>
                    
                    <hr>

                    <!-- Reputation Summary in Profile -->
                    <div class="text-start">
                        <span class="section-kicker">Trust & Reputation</span>
                        <div class="d-flex align-items-center gap-2 mb-2 mt-1">
                            <span class="fs-5 fw-bold"><?= $reputation['review_count'] > 0 ? number_format($reputation['average_rating'], 1) : '—' ?></span>
                            <span><?= renderStarRating($reputation['average_rating']) ?></span>
                        </div>
                        <p class="small text-muted mb-1">
                            <i class="bi bi-patch-check text-primary me-1"></i><strong><?= (int)$reputation['review_count'] ?></strong> Verified Customer Reviews
                        </p>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-check2-circle text-success me-1"></i><strong><?= (int)$reputation['completed_services'] ?></strong> Completed Services
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <?= csrfField() ?>
                        <div class="col-md-6">
                            <label for="business_name" class="form-label">Business Name</label>
                            <input type="text" id="business_name" name="business_name" class="form-control" value="<?= htmlspecialchars($formData['business_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($formData['phone'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-12">
                            <label for="address" class="form-label">Address</label>
                            <input type="text" id="address" name="address" class="form-control" value="<?= htmlspecialchars($formData['address'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="city" class="form-label">City</label>
                            <input type="text" id="city" name="city" class="form-control" value="<?= htmlspecialchars($formData['city'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="area" class="form-label">Area / Locality</label>
                            <input type="text" id="area" name="area" class="form-control" value="<?= htmlspecialchars($formData['area'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="experience_years" class="form-label">Years of Experience</label>
                            <input type="number" id="experience_years" name="experience_years" min="0" max="80" class="form-control" value="<?= htmlspecialchars((string)$formData['experience_years'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="availability_status" class="form-label">Availability Status</label>
                            <select id="availability_status" name="availability_status" class="form-select" required>
                                <option value="available" <?= $formData['availability_status'] === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="busy" <?= $formData['availability_status'] === 'busy' ? 'selected' : '' ?>>Busy</option>
                                <option value="offline" <?= $formData['availability_status'] === 'offline' ? 'selected' : '' ?>>Offline</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Business Description</label>
                            <textarea id="description" name="description" class="form-control" rows="4" placeholder="Tell customers about your work, specialties, and customer experience."><?= htmlspecialchars($formData['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label for="profile_image" class="form-label">Profile Image</label>
                            <input type="file" id="profile_image" name="profile_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="text-muted">Allowed: JPG, PNG, WEBP. Maximum size: 2MB.</small>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary rounded-pill px-4">Save Profile</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
