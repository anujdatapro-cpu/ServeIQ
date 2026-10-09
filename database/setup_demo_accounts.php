<?php
declare(strict_types=1);

/**
 * ServeIQ Demo Dataset Trimming & Setup Script
 *
 * Configures the demo environment with exactly:
 * - 100 total user accounts
 * - 70 provider accounts
 * - 30 customer accounts
 *
 * Usage (CLI only):
 *   php database/setup_demo_accounts.php --confirm-reset
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/encryption.php';

$options = getopt('', ['confirm-reset', 'help']);
if (!isset($options['confirm-reset'])) {
    echo "===================================================================\n";
    echo "ServeIQ Demo Account Setup Script\n";
    echo "===================================================================\n";
    echo "ERROR: Safety check failed. This script modifies user account data.\n";
    echo "To execute demo setup, run with explicit confirmation:\n\n";
    echo "  php database/setup_demo_accounts.php --confirm-reset\n\n";
    exit(1);
}

$pdo = getDatabaseConnection();
$dbName = DB_NAME;

// Verify target database safety
$allowedDatabases = ['serveiq_db', 'serveiq', 'serveiq_demo', 'serveiq_dev', 'serveiq_test'];
if (!in_array(strtolower($dbName), $allowedDatabases, true) && !str_contains(strtolower($dbName), 'demo') && !str_contains(strtolower($dbName), 'test')) {
    fwrite(STDERR, "ERROR: Refusing to run demo reset against unapproved production database '{$dbName}'.\n");
    exit(1);
}

echo "Target database verified: {$dbName}\n";

$passwordHash = password_hash('ServeIQDemo#2026', PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    // 1. Ensure 30 Customer Accounts exist
    $customerEmails = [];
    for ($i = 1; $i <= 30; $i++) {
        $email = sprintf('demo.customer%02d@serveiq.local', $i);
        $name = ($i === 1) ? 'Anuj K' : sprintf('Demo Customer %02d', $i);
        $customerEmails[] = $email;

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $upd = $pdo->prepare('UPDATE users SET name = :name, password = :pass, role = \'customer\', is_email_verified = 1 WHERE id = :id');
            $upd->execute(['name' => $name, 'pass' => $passwordHash, 'id' => $existingId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, \'customer\', 1)');
            $ins->execute(['name' => $name, 'email' => $email, 'pass' => $passwordHash]);
        }
    }

    // 2. Ensure 70 Provider Accounts exist
    $providerEmails = [];
    for ($i = 1; $i <= 70; $i++) {
        $email = sprintf('demo.provider%03d@serveiq.local', $i);
        $providerEmails[] = $email;

        // Give specific realistic demo business names to prominent accounts
        if ($i === 1) {
            $name = 'Demo Home Cleaner';
            $businessName = 'ServeIQ Demo Home Cleaning';
        } elseif ($i === 2) {
            $name = 'Demo Bike Mechanic';
            $businessName = 'ServeIQ Demo Bike Repair';
        } else {
            $name = sprintf('Demo Provider %03d', $i);
            $businessName = sprintf('ServeIQ Demo Service Shop %03d', $i);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $upd = $pdo->prepare('UPDATE users SET name = :name, password = :pass, role = \'provider\', is_email_verified = 1 WHERE id = :id');
            $upd->execute(['name' => $name, 'pass' => $passwordHash, 'id' => $existingId]);
            $userId = (int)$existingId;
        } else {
            $ins = $pdo->prepare('INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, \'provider\', 1)');
            $ins->execute(['name' => $name, 'email' => $email, 'pass' => $passwordHash]);
            $userId = (int)$pdo->lastInsertId();
        }

        // Upsert provider profile
        $profStmt = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id = :uid');
        $profStmt->execute(['uid' => $userId]);
        $existingProfId = $profStmt->fetchColumn();

        if (!$existingProfId) {
            $insProf = $pdo->prepare('
                INSERT INTO provider_profiles (user_id, business_name, phone, address, city, area, experience_years, availability_status, verification_status, marketplace_active)
                VALUES (:uid, :bname, :phone, :address, \'Pune\', \'Kothrud\', 5, \'available\', \'approved\', 1)
            ');
            $insProf->execute([
                'uid' => $userId,
                'bname' => $businessName,
                'phone' => encryptSensitiveData('98765432' . sprintf('%02d', $i % 100)),
                'address' => encryptSensitiveData('Demo Shop Street ' . $i . ', Pune'),
            ]);
            $profileId = (int)$pdo->lastInsertId();
        } else {
            $profileId = (int)$existingProfId;
            $updProf = $pdo->prepare('UPDATE provider_profiles SET business_name = :bname, verification_status = \'approved\', availability_status = \'available\', marketplace_active = 1 WHERE id = :pid');
            $updProf->execute(['bname' => $businessName, 'pid' => $profileId]);
        }

        // Ensure at least 1 active service exists for each provider profile
        $svcCheck = $pdo->prepare('SELECT id FROM services WHERE provider_id = :pid LIMIT 1');
        $svcCheck->execute(['pid' => $profileId]);
        if (!$svcCheck->fetchColumn()) {
            $catId = ($i % 2 === 0) ? 8 : 1; // Category 8 = Home Cleaning, Category 1 = Laptop Repair
            $svcName = ($i === 1) ? 'Full Home Cleaning for 2 BHK Flat' : (($i === 2) ? 'Bike Engine & Brake Service' : 'General Service Inspection');
            $insSvc = $pdo->prepare('INSERT INTO services (provider_id, category_id, service_name, description, base_price, is_active) VALUES (:pid, :cid, :sname, \'Professional demo service\', 999.00, 1)');
            $insSvc->execute(['pid' => $profileId, 'cid' => $catId, 'sname' => $svcName]);
        }
    }

    // 3. Trim extra accounts to enforce EXACTLY 100 users (70 providers, 30 customers)
    $allowedEmails = array_merge($customerEmails, $providerEmails);
    $placeholders = implode(',', array_fill(0, count($allowedEmails), '?'));

    $delExtraUsers = $pdo->prepare("DELETE FROM users WHERE email NOT IN ({$placeholders})");
    $delExtraUsers->execute($allowedEmails);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: Demo setup failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 4. Validate final counts
$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$providerCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn();
$customerCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();

echo "===================================================================\n";
echo "ServeIQ Demo Account Setup Completed Successfully\n";
echo "===================================================================\n";
echo "Total Users:    {$totalUsers} (Expected: 100)\n";
echo "Providers:      {$providerCount} (Expected: 70)\n";
echo "Customers:      {$customerCount} (Expected: 30)\n";
echo "Password:       ServeIQDemo#2026\n";
echo "===================================================================\n";

if ($totalUsers !== 100 || $providerCount !== 70 || $customerCount !== 30) {
    fwrite(STDERR, "FATAL VALIDATION ERROR: User counts do not match expected 100/70/30 distribution!\n");
    exit(1);
}

exit(0);
