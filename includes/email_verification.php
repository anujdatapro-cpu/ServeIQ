<?php
declare(strict_types=1);

function generateEmailOtp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Persist only a password hash of the one-time code and enforce resend cooldowns. */
function issueEmailOtp(PDO $pdo, EmailServiceInterface $emailService, int $userId, string $recipient): array
{
    $otp = generateEmailOtp();
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, otp_hash, expires_at, attempts, resend_after)
         VALUES (:user_id, :otp_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, DATE_ADD(NOW(), INTERVAL 60 SECOND))
         ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), expires_at = VALUES(expires_at), attempts = 0,
           resend_after = VALUES(resend_after), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['user_id' => $userId, 'otp_hash' => $hash]);
    $delivery = $emailService->sendVerificationCode($recipient, $otp);
    if (!$delivery['sent']) {
        return ['sent' => false, 'preview_code' => null, 'error' => 'We could not send the verification code. Please try again shortly.'];
    }
    return ['sent' => true, 'preview_code' => $delivery['preview_code'], 'error' => null];
}

function verifyEmailOtp(PDO $pdo, int $userId, string $submittedOtp): string
{
    if (!preg_match('/^[0-9]{6}$/', $submittedOtp)) {
        return 'invalid';
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT otp_hash, expires_at, attempts FROM email_verifications WHERE user_id = :user_id FOR UPDATE');
        $stmt->execute(['user_id' => $userId]);
        $challenge = $stmt->fetch();
        if (!$challenge) {
            $pdo->commit();
            return 'missing';
        }
        if (strtotime((string)$challenge['expires_at']) <= time()) {
            $pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            $pdo->commit();
            return 'expired';
        }
        if ((int)$challenge['attempts'] >= 5) {
            $pdo->commit();
            return 'locked';
        }
        if (!password_verify($submittedOtp, (string)$challenge['otp_hash'])) {
            $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            $pdo->commit();
            return 'invalid';
        }

        $pdo->prepare('UPDATE users SET is_email_verified = 1 WHERE id = :user_id')->execute(['user_id' => $userId]);
        $pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $pdo->commit();
        return 'verified';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function resendEmailOtp(PDO $pdo, EmailServiceInterface $emailService, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.email, u.is_email_verified, v.resend_after
         FROM users u LEFT JOIN email_verifications v ON v.user_id = u.id WHERE u.id = :user_id LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_email_verified'] === 1) {
        return ['sent' => false, 'preview_code' => null, 'error' => 'Verification is unavailable for this account.'];
    }
    if (!empty($row['resend_after']) && strtotime((string)$row['resend_after']) > time()) {
        return ['sent' => false, 'preview_code' => null, 'error' => 'Please wait before requesting another code.'];
    }
    return issueEmailOtp($pdo, $emailService, $userId, (string)$row['email']);
}
