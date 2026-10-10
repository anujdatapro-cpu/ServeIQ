<?php
declare(strict_types=1);

/**
 * Dedicated Demo Accounts Setup Script for ServeIQ.
 * Creates or updates dedicated customer and provider accounts for live demonstration.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/encryption.php';

const DEMO_CUSTOMER_EMAIL = 'serveiq.demo.customer@example.com';
const DEMO_CUSTOMER_PASS  = 'ServeIQDemoCustomer#2026';
const DEMO_CUSTOMER_NAME  = 'ServeIQ Demo Customer';

const DEMO_PROVIDER_EMAIL = 'serveiq.demo.provider@example.com';
const DEMO_PROVIDER_PASS  = 'ServeIQDemoProvider#2026';
const DEMO_PROVIDER_NAME  = 'ServeIQ Demo Provider';

function setupDedicatedDemoAccounts(PDO $pdo): array
{
    $pdo->beginTransaction();

    try {
        // 1. Ensure Customer Account
        $custStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $custStmt->execute(['email' => DEMO_CUSTOMER_EMAIL]);
        $cust = $custStmt->fetch();

        $custPassHash = password_hash(DEMO_CUSTOMER_PASS, PASSWORD_DEFAULT);

        if ($cust) {
            $customerId = (int)$cust['id'];
            $updateCust = $pdo->prepare('UPDATE users SET name = :name, password = :pass, role = \'customer\', is_email_verified = 1 WHERE id = :id');
            $updateCust->execute(['name' => DEMO_CUSTOMER_NAME, 'pass' => $custPassHash, 'id' => $customerId]);
        } else {
            $insCust = $pdo->prepare('INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, \'customer\', 1)');
            $insCust->execute(['name' => DEMO_CUSTOMER_NAME, 'email' => DEMO_CUSTOMER_EMAIL, 'pass' => $custPassHash]);
            $customerId = (int)$pdo->lastInsertId();
        }

        // 2. Ensure Provider Account
        $provStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $provStmt->execute(['email' => DEMO_PROVIDER_EMAIL]);
        $prov = $provStmt->fetch();

        $provPassHash = password_hash(DEMO_PROVIDER_PASS, PASSWORD_DEFAULT);

        if ($prov) {
            $providerUserId = (int)$prov['id'];
            $updateProv = $pdo->prepare('UPDATE users SET name = :name, password = :pass, role = \'provider\', is_email_verified = 1 WHERE id = :id');
            $updateProv->execute(['name' => DEMO_PROVIDER_NAME, 'pass' => $provPassHash, 'id' => $providerUserId]);
        } else {
            $insProv = $pdo->prepare('INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, \'provider\', 1)');
            $insProv->execute(['name' => DEMO_PROVIDER_NAME, 'email' => DEMO_PROVIDER_EMAIL, 'pass' => $provPassHash]);
            $providerUserId = (int)$pdo->lastInsertId();
        }

        // 3. Ensure Provider Profile
        // Safe encryption helper check
        $phoneEnc = '9876543210';
        $addressEnc = 'Demo Service Address, Bavdhan, Pune';
        try {
            $phoneEnc = encryptSensitiveData('9876543210');
            $addressEnc = encryptSensitiveData('Demo Service Address, Bavdhan, Pune');
        } catch (Throwable $e) {
            // If encryption key is not set in CLI environment, store as plain text compatibility string
        }

        $profStmt = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
        $profStmt->execute(['user_id' => $providerUserId]);
        $profile = $profStmt->fetch();

        if ($profile) {
            $profileId = (int)$profile['id'];
            $updProf = $pdo->prepare('
                UPDATE provider_profiles SET
                    business_name = :bname,
                    phone = :phone,
                    address = :address,
                    city = \'Pune\',
                    area = \'Bavdhan\',
                    latitude = 18.5204,
                    longitude = 73.7830,
                    location_source = \'demo_estimate\',
                    experience_years = 2,
                    description = :desc,
                    availability_status = \'available\',
                    verification_status = \'approved\',
                    response_time_minutes = 30,
                    response_time_source = \'provider_estimate\'
                WHERE id = :id
            ');
            $updProf->execute([
                'bname' => 'ServeIQ Demo Bike Repair',
                'phone' => $phoneEnc,
                'address' => $addressEnc,
                'desc' => 'Dedicated ServeIQ demo bike repair service in Bavdhan, Pune.',
                'id' => $profileId,
            ]);
        } else {
            $insProf = $pdo->prepare('
                INSERT INTO provider_profiles (
                    user_id, business_name, phone, address, city, area,
                    latitude, longitude, location_source, experience_years,
                    description, availability_status, verification_status,
                    response_time_minutes, response_time_source
                ) VALUES (
                    :user_id, :bname, :phone, :address, \'Pune\', \'Bavdhan\',
                    18.5204, 73.7830, \'demo_estimate\', 2,
                    :desc, \'available\', \'approved\',
                    30, \'provider_estimate\'
                )
            ');
            $insProf->execute([
                'user_id' => $providerUserId,
                'bname' => 'ServeIQ Demo Bike Repair',
                'phone' => $phoneEnc,
                'address' => $addressEnc,
                'desc' => 'Dedicated ServeIQ demo bike repair service in Bavdhan, Pune.',
            ]);
            $profileId = (int)$pdo->lastInsertId();
        }

        // 4. Ensure Category "Tyre/Puncture Services"
        $catStmt = $pdo->prepare('SELECT id FROM service_categories WHERE category_name = :cname LIMIT 1');
        $catStmt->execute(['cname' => 'Tyre/Puncture Services']);
        $cat = $catStmt->fetch();

        if ($cat) {
            $categoryId = (int)$cat['id'];
            $updCat = $pdo->prepare('UPDATE service_categories SET is_active = 1 WHERE id = :id');
            $updCat->execute(['id' => $categoryId]);
        } else {
            $insCat = $pdo->prepare('INSERT INTO service_categories (category_name, description, is_active) VALUES (\'Tyre/Puncture Services\', \'Car and bike tyre repair, punctures and replacement.\', 1)');
            $insCat->execute();
            $categoryId = (int)$pdo->lastInsertId();
        }

        // 5. Ensure Active Service "Bike Puncture Repair"
        $svcStmt = $pdo->prepare('SELECT id FROM services WHERE provider_id = :provider_id AND service_name = :sname LIMIT 1');
        $svcStmt->execute(['provider_id' => $profileId, 'sname' => 'Bike Puncture Repair']);
        $svc = $svcStmt->fetch();

        if ($svc) {
            $serviceId = (int)$svc['id'];
            $updSvc = $pdo->prepare('UPDATE services SET category_id = :cat_id, description = :desc, base_price = 250.00, is_active = 1 WHERE id = :id');
            $updSvc->execute([
                'cat_id' => $categoryId,
                'desc' => 'Motorcycle puncture repair, tube replacement, tyre inspection and roadside assistance in Bavdhan, Pune.',
                'id' => $serviceId,
            ]);
        } else {
            $insSvc = $pdo->prepare('INSERT INTO services (provider_id, category_id, service_name, description, base_price, is_active) VALUES (:provider_id, :cat_id, \'Bike Puncture Repair\', :desc, 250.00, 1)');
            $insSvc->execute([
                'provider_id' => $profileId,
                'cat_id' => $categoryId,
                'desc' => 'Motorcycle puncture repair, tube replacement, tyre inspection and roadside assistance in Bavdhan, Pune.',
            ]);
            $serviceId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        return [
            'status' => 'success',
            'customer_id' => $customerId,
            'customer_email' => DEMO_CUSTOMER_EMAIL,
            'provider_user_id' => $providerUserId,
            'provider_profile_id' => $profileId,
            'provider_email' => DEMO_PROVIDER_EMAIL,
            'category_id' => $categoryId,
            'service_id' => $serviceId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $pdo = getDatabaseConnection();
        $result = setupDedicatedDemoAccounts($pdo);
        echo "Dedicated demo accounts configured successfully:\n";
        print_r($result);
    } catch (Throwable $e) {
        echo "Error setting up dedicated demo accounts: " . $e->getMessage() . "\n";
        exit(1);
    }
}
