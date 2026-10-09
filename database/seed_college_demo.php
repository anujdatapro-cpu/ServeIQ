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

    // Ensure Home Cleaning and Tyre/Puncture Services categories exist
    $ensureCategories = [
        'Home Cleaning' => 'Home, office, apartment deep cleaning, and sanitization services.',
        'Tyre/Puncture Services' => 'Car and bike tyre repair, punctures and replacement.',
    ];

    $categoryIds = [];
    foreach ($ensureCategories as $catName => $catDesc) {
        $stmt = $pdo->prepare("SELECT id FROM service_categories WHERE category_name = ? LIMIT 1");
        $stmt->execute([$catName]);
        $catId = (int)$stmt->fetchColumn();

        if ($catId < 1) {
            $insertCat = $pdo->prepare("INSERT INTO service_categories (category_name, description, is_active) VALUES (?, ?, 1)");
            $insertCat->execute([$catName, $catDesc]);
            $catId = (int)$pdo->lastInsertId();
        }
        $categoryIds[$catName] = $catId;
    }

    // Demo Account Credentials
    $customerPass = 'DemoCustomer#2026';
    $providerPass = 'DemoProvider#2026';
    $customerHash = password_hash($customerPass, PASSWORD_DEFAULT);
    $providerHash = password_hash($providerPass, PASSWORD_DEFAULT);

    // 1. Upsert Dedicated Customer
    $custEmail = 'serveiq.demo.customer@example.com';
    $custName = 'ServeIQ Demo Customer';

    $findCust = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $findCust->execute([$custEmail]);
    $existingCustId = (int)$findCust->fetchColumn();

    if ($existingCustId > 0) {
        $updateCust = $pdo->prepare("UPDATE users SET name = ?, password = ?, is_email_verified = 1 WHERE id = ?");
        $updateCust->execute([$custName, $customerHash, $existingCustId]);
        $customerId = $existingCustId;
        echo "[SUCCESS] Updated Demo Customer (ID: {$customerId})" . PHP_EOL;
    } else {
        $insertCust = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (?, ?, ?, 'customer', 1)");
        $insertCust->execute([$custName, $custEmail, $customerHash]);
        $customerId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Customer (ID: {$customerId})" . PHP_EOL;
    }

    // 2. Upsert Dedicated Provider
    $provEmail = 'serveiq.demo.provider@example.com';
    $provName = 'ServeIQ Demo Provider';

    $findProv = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $findProv->execute([$provEmail]);
    $existingProvId = (int)$findProv->fetchColumn();

    if ($existingProvId > 0) {
        $updateProv = $pdo->prepare("UPDATE users SET name = ?, password = ?, is_email_verified = 1 WHERE id = ?");
        $updateProv->execute([$provName, $providerHash, $existingProvId]);
        $providerUserId = $existingProvId;
        echo "[SUCCESS] Updated Demo Provider User (ID: {$providerUserId})" . PHP_EOL;
    } else {
        $insertProv = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (?, ?, ?, 'provider', 1)");
        $insertProv->execute([$provName, $provEmail, $providerHash]);
        $providerUserId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Provider User (ID: {$providerUserId})" . PHP_EOL;
    }

    // 3. Upsert Provider Profile
    $findProfile = $pdo->prepare("SELECT id FROM provider_profiles WHERE user_id = ? LIMIT 1");
    $findProfile->execute([$providerUserId]);
    $profileId = (int)$findProfile->fetchColumn();

    $businessName = 'Demo Home Cleaner & Repair Services';
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
        echo "[SUCCESS] Updated Demo Provider Profile (Profile ID: {$profileId})" . PHP_EOL;
    } else {
        $insertProfile = $pdo->prepare(
            "INSERT INTO provider_profiles
             (user_id, business_name, phone, address, city, area, experience_years, verification_status, availability_status, marketplace_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', 'available', 1)"
        );
        $insertProfile->execute([$providerUserId, $businessName, $phone, $address, $city, $area, $expYears]);
        $profileId = (int)$pdo->lastInsertId();
        echo "[SUCCESS] Created Demo Provider Profile (Profile ID: {$profileId})" . PHP_EOL;
    }

    // 4. Upsert Services (Home Deep Cleaning and Bike Puncture Repair)
    $demoServices = [
        [
            'category_id' => $categoryIds['Home Cleaning'],
            'name' => 'Home Deep Cleaning',
            'desc' => 'Detailed home, 2 BHK flat, kitchen, bathroom and deep cleaning service in Bavdhan, Pune.',
            'price' => 1499.00,
        ],
        [
            'category_id' => $categoryIds['Tyre/Puncture Services'],
            'name' => 'Bike Puncture Repair',
            'desc' => 'Motorcycle puncture repair, tube replacement, tyre inspection and roadside assistance in Bavdhan, Pune.',
            'price' => 250.00,
        ],
    ];

    foreach ($demoServices as $serv) {
        $findServ = $pdo->prepare("SELECT id FROM services WHERE provider_id = ? AND service_name = ? LIMIT 1");
        $findServ->execute([$profileId, $serv['name']]);
        $serviceId = (int)$findServ->fetchColumn();

        if ($serviceId > 0) {
            $updateServ = $pdo->prepare("UPDATE services SET category_id = ?, description = ?, base_price = ?, is_active = 1 WHERE id = ?");
            $updateServ->execute([$serv['category_id'], $serv['desc'], $serv['price'], $serviceId]);
            echo "[SUCCESS] Updated Service '{$serv['name']}' (ID: {$serviceId})" . PHP_EOL;
        } else {
            $insertServ = $pdo->prepare("INSERT INTO services (provider_id, category_id, service_name, description, base_price, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $insertServ->execute([$profileId, $serv['category_id'], $serv['name'], $serv['desc'], $serv['price']]);
            $serviceId = (int)$pdo->lastInsertId();
            echo "[SUCCESS] Created Service '{$serv['name']}' (ID: {$serviceId})" . PHP_EOL;
        }
    }

    echo PHP_EOL . "=== DEMO ACCOUNTS READY ===" . PHP_EOL;
    echo "Customer: {$custEmail} | Password: {$customerPass}" . PHP_EOL;
    echo "Provider: {$provEmail} | Password: {$providerPass}" . PHP_EOL;
    echo "Provider Business Profile ID: {$profileId}" . PHP_EOL;

} catch (Throwable $e) {
    echo "[ERROR] Demo seeding failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
