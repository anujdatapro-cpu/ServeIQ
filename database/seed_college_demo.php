<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

echo "=== SERVEIQ COLLEGE PRESENTATION DEMO SEEDING ===" . PHP_EOL;

try {
    $pdo = getDatabaseConnection();

    // 1. Ensure Tyre/Puncture Services or Vehicle Repair category exists
    $stmt = $pdo->prepare("SELECT id FROM service_categories WHERE category_name IN ('Tyre/Puncture Services', 'Vehicle Repair') ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $categoryId = (int)$stmt->fetchColumn();

    if ($categoryId < 1) {
        $insertCat = $pdo->prepare("INSERT INTO service_categories (category_name, description, is_active) VALUES ('Tyre/Puncture Services', 'Car and bike tyre repair, punctures and replacement.', 1)");
        $insertCat->execute();
        $categoryId = (int)$pdo->lastInsertId();
    }

    // Passwords for demo accounts
    $customerPass = 'DemoCustomer#2026';
    $providerPass = 'DemoProvider#2026';
    $customerHash = password_hash($customerPass, PASSWORD_DEFAULT);
    $providerHash = password_hash($providerPass, PASSWORD_DEFAULT);

    // 2. Upsert Demo Customer
    $custEmail = 'serveiq.demo.customer@example.com';
    $custName = 'ServeIQ Demo Customer';

    $findCust = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $findCust->execute([$custEmail]);
    $existingCustId = (int)$findCust->fetchColumn();

    if ($existingCustId > 0) {
        $updateCust = $pdo->prepare("UPDATE users SET name = ?, password = ?, is_email_verified = 1 WHERE id = ?");
        $updateCust->execute([$custName, $customerHash, $existingCustId]);
        $customerId = $existingCustId;
        echo "[SUCCESS] Updated existing Demo Customer (ID: {$customerId})" . PHP_EOL;
    } else {
        $insertCust = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (?, ?, ?, 'customer', 1)");
        $insertCust->execute([$custName, $custEmail, $customerHash]);
        $customerId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Customer (ID: {$customerId})" . PHP_EOL;
    }

    // 3. Upsert Demo Provider
    $provEmail = 'serveiq.demo.provider@example.com';
    $provName = 'ServeIQ Demo Provider';

    $findProv = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $findProv->execute([$provEmail]);
    $existingProvId = (int)$findProv->fetchColumn();

    if ($existingProvId > 0) {
        $updateProv = $pdo->prepare("UPDATE users SET name = ?, password = ?, is_email_verified = 1 WHERE id = ?");
        $updateProv->execute([$provName, $providerHash, $existingProvId]);
        $providerUserId = $existingProvId;
        echo "[SUCCESS] Updated existing Demo Provider user (ID: {$providerUserId})" . PHP_EOL;
    } else {
        $insertProv = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (?, ?, ?, 'provider', 1)");
        $insertProv->execute([$provName, $provEmail, $providerHash]);
        $providerUserId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Provider user (ID: {$providerUserId})" . PHP_EOL;
    }

    // 4. Upsert Provider Profile
    $findProfile = $pdo->prepare("SELECT id FROM provider_profiles WHERE user_id = ? LIMIT 1");
    $findProfile->execute([$providerUserId]);
    $profileId = (int)$findProfile->fetchColumn();

    $businessName = 'ServeIQ Demo Bike Repair';
    $phone = '9876543210';
    $address = 'Demo Service Address, Bavdhan, Pune';
    $city = 'Pune';
    $area = 'Bavdhan';
    $expYears = 2;

    if ($profileId > 0) {
        $updateProfile = $pdo->prepare(
            "UPDATE provider_profiles
             SET business_name = ?, phone = ?, address = ?, city = ?, area = ?, experience_years = ?,
                 verification_status = 'approved', availability_status = 'available', marketplace_active = 1
             WHERE id = ?"
        );
        $updateProfile->execute([$businessName, $phone, $address, $city, $area, $expYears, $profileId]);
        echo "[SUCCESS] Updated Demo Provider Profile (ID: {$profileId})" . PHP_EOL;
    } else {
        $insertProfile = $pdo->prepare(
            "INSERT INTO provider_profiles
             (user_id, business_name, phone, address, city, area, experience_years, verification_status, availability_status, marketplace_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', 'available', 1)"
        );
        $insertProfile->execute([$providerUserId, $businessName, $phone, $address, $city, $area, $expYears]);
        $profileId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Provider Profile (ID: {$profileId})" . PHP_EOL;
    }

    // 5. Upsert Provider Service
    $serviceName = 'Bike Puncture Repair';
    $serviceDesc = 'Motorcycle puncture repair, tube replacement, tyre inspection and roadside assistance in Bavdhan, Pune.';
    $basePrice = 250.00;

    $findService = $pdo->prepare("SELECT id FROM services WHERE provider_id = ? AND service_name = ? LIMIT 1");
    $findService->execute([$profileId, $serviceName]);
    $serviceId = (int)$findService->fetchColumn();

    if ($serviceId > 0) {
        $updateService = $pdo->prepare("UPDATE services SET category_id = ?, description = ?, base_price = ?, is_active = 1 WHERE id = ?");
        $updateService->execute([$categoryId, $serviceDesc, $basePrice, $serviceId]);
        echo "[SUCCESS] Updated Demo Provider Service (ID: {$serviceId})" . PHP_EOL;
    } else {
        $insertService = $pdo->prepare("INSERT INTO services (provider_id, category_id, service_name, description, base_price, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $insertService->execute([$profileId, $categoryId, $serviceName, $serviceDesc, $basePrice]);
        $serviceId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Provider Service (ID: {$serviceId})" . PHP_EOL;
    }

    echo PHP_EOL . "=== DEMO ACCOUNTS READY ===" . PHP_EOL;
    echo "Customer: {$custEmail} | Password: {$customerPass}" . PHP_EOL;
    echo "Provider: {$provEmail} | Password: {$providerPass}" . PHP_EOL;
    echo "Service: {$serviceName} (₹{$basePrice}) in {$city}, {$area}" . PHP_EOL;

} catch (Throwable $e) {
    echo "[ERROR] Demo seeding failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
