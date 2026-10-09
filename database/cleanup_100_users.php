<?php
declare(strict_types=1);

/**
 * ServeIQ 100-User Database Safe Cleanup & Seeder.
 * Ensures exactly 30 customers and 70 providers (100 total user accounts)
 * while strictly preserving accounts with active dependencies or historical records.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/setup_demo_accounts.php';

function perform100UserCleanup(PDO $pdo): array
{
    // Ensure dedicated demo accounts exist first
    setupDedicatedDemoAccounts($pdo);

    $pdo->beginTransaction();

    try {
        // 1. Identify all protected user IDs (users with dependencies or dedicated demo accounts)
        $protectedUserIds = [];

        // Dedicated demo accounts
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email IN (:c_email, :p_email)');
        $stmt->execute([
            'c_email' => DEMO_CUSTOMER_EMAIL,
            'p_email' => DEMO_PROVIDER_EMAIL,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $protectedUserIds[(int)$id] = true;
        }

        // Customers with service requests
        $stmt = $pdo->query('SELECT DISTINCT customer_id FROM service_requests');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($id) $protectedUserIds[(int)$id] = true;
        }

        // Customers or Providers with bookings
        $stmt = $pdo->query('SELECT DISTINCT customer_id FROM bookings UNION SELECT DISTINCT user_id FROM provider_profiles WHERE id IN (SELECT DISTINCT provider_id FROM bookings)');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($id) $protectedUserIds[(int)$id] = true;
        }

        // Customers or Providers with reviews
        $stmt = $pdo->query('SELECT DISTINCT customer_id FROM reviews UNION SELECT DISTINCT user_id FROM provider_profiles WHERE id IN (SELECT DISTINCT provider_id FROM reviews)');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($id) $protectedUserIds[(int)$id] = true;
        }

        // Providers with responses
        $stmt = $pdo->query('SELECT DISTINCT user_id FROM provider_profiles WHERE id IN (SELECT DISTINCT provider_id FROM provider_responses)');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($id) $protectedUserIds[(int)$id] = true;
        }

        // Get counts before
        $stmtCust = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'");
        $custBefore = (int)$stmtCust->fetchColumn();

        $stmtProv = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'");
        $provBefore = (int)$stmtProv->fetchColumn();

        $totalBefore = $custBefore + $provBefore;

        $deletedSyntheticCount = 0;

        // 2. Adjust Customers to exactly 30
        if ($custBefore > 30) {
            $stmtAllCust = $pdo->query("SELECT id FROM users WHERE role = 'customer' ORDER BY id DESC");
            $allCustIds = $stmtAllCust->fetchAll(PDO::FETCH_COLUMN);
            $currentCustCount = count($allCustIds);

            foreach ($allCustIds as $cId) {
                $cId = (int)$cId;
                if ($currentCustCount <= 30) {
                    break;
                }
                if (!isset($protectedUserIds[$cId])) {
                    $delUser = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'customer'");
                    $delUser->execute(['id' => $cId]);
                    if ($delUser->rowCount() > 0) {
                        $currentCustCount--;
                        $deletedSyntheticCount++;
                    }
                }
            }
        } elseif ($custBefore < 30) {
            $insCust = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, 'customer', 1)");
            $passHash = password_hash('ServeIQDemo#2026', PASSWORD_DEFAULT);
            for ($i = $custBefore + 1; $i <= 30; $i++) {
                $email = sprintf('demo.customer%02d@serveiq.local', $i);
                $name = sprintf('Demo Customer %02d', $i);
                $insCust->execute(['name' => $name, 'email' => $email, 'pass' => $passHash]);
            }
        }

        // 3. Adjust Providers to exactly 70
        if ($provBefore > 70) {
            $stmtAllProv = $pdo->query("SELECT id FROM users WHERE role = 'provider' ORDER BY id DESC");
            $allProvIds = $stmtAllProv->fetchAll(PDO::FETCH_COLUMN);
            $currentProvCount = count($allProvIds);

            foreach ($allProvIds as $pUserId) {
                $pUserId = (int)$pUserId;
                if ($currentProvCount <= 70) {
                    break;
                }
                if (!isset($protectedUserIds[$pUserId])) {
                    // Check if provider profile exists and delete child services/profile safely if no bookings/responses exist
                    $stmtProf = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id = :uid');
                    $stmtProf->execute(['uid' => $pUserId]);
                    $profId = $stmtProf->fetchColumn();

                    if ($profId) {
                        $pdo->prepare('DELETE FROM services WHERE provider_id = :pid')->execute(['pid' => $profId]);
                        $pdo->prepare('DELETE FROM provider_profiles WHERE id = :pid')->execute(['pid' => $profId]);
                    }

                    $delUser = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'provider'");
                    $delUser->execute(['id' => $pUserId]);

                    if ($delUser->rowCount() > 0) {
                        $currentProvCount--;
                        $deletedSyntheticCount++;
                    }
                }
            }
        } elseif ($provBefore < 70) {
            $insProv = $pdo->prepare("INSERT INTO users (name, email, password, role, is_email_verified) VALUES (:name, :email, :pass, 'provider', 1)");
            $insProf = $pdo->prepare("
                INSERT INTO provider_profiles (user_id, business_name, phone, address, city, area, experience_years, description, availability_status, verification_status)
                VALUES (:user_id, :bname, '9876543210', 'Demo Address', 'Pune', 'Bavdhan', 2, 'Synthetic demo provider profile', 'available', 'approved')
            ");
            $passHash = password_hash('ServeIQDemo#2026', PASSWORD_DEFAULT);

            for ($i = $provBefore + 1; $i <= 70; $i++) {
                $email = sprintf('demo.provider%03d@serveiq.local', $i);
                $name = sprintf('Demo Provider %03d', $i);
                $insProv->execute(['name' => $name, 'email' => $email, 'pass' => $passHash]);
                $newPUserId = (int)$pdo->lastInsertId();

                $insProf->execute([
                    'user_id' => $newPUserId,
                    'bname' => sprintf('Demo Provider Service %03d', $i),
                ]);
            }
        }

        // Get counts after
        $stmtCustAfter = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'");
        $custAfter = (int)$stmtCustAfter->fetchColumn();

        $stmtProvAfter = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'");
        $provAfter = (int)$stmtProvAfter->fetchColumn();

        $totalAfter = $custAfter + $provAfter;

        $pdo->commit();

        return [
            'before' => [
                'total' => $totalBefore,
                'customers' => $custBefore,
                'providers' => $provBefore,
            ],
            'after' => [
                'total' => $totalAfter,
                'customers' => $custAfter,
                'providers' => $provAfter,
            ],
            'deleted_synthetic' => $deletedSyntheticCount,
            'protected_users_retained' => count($protectedUserIds),
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
        $report = perform100UserCleanup($pdo);
        echo "Database 100-User Cleanup Report:\n";
        print_r($report);
    } catch (Throwable $e) {
        echo "Error executing database cleanup: " . $e->getMessage() . "\n";
        exit(1);
    }
}
