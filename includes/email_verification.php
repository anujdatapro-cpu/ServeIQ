<?php
declare(strict_types=1);

function generateEmailOtp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Persist only a password hash of the one-time code and enforce resend cooldowns. */
function issueEmailOtp(PDO $pdo, EmailServiceInterface $emailService, int $userId, string $recipient, bool $requireUnverified = false): array
{
    $ownsTransaction = !$pdo->inTransaction();
    $savepointActive = false;
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT serveiq_issue_email_otp');
            $savepointActive = true;
        }

        $userStmt = $pdo->prepare('SELECT id, is_email_verified FROM users WHERE id = :user_id FOR UPDATE');
        $userStmt->execute(['user_id' => $userId]);
        $user = $userStmt->fetch();
        if (!$user || ($requireUnverified && (int)$user['is_email_verified'] === 1)) {
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
            return ['sent' => false, 'preview_code' => null, 'error' => 'Verification is unavailable for this account.'];
        }

        $existingStmt = $pdo->prepare(
            'SELECT resend_after > CURRENT_TIMESTAMP AS cooldown_active
             FROM email_verifications WHERE user_id = :user_id FOR UPDATE'
        );
        $existingStmt->execute(['user_id' => $userId]);
        $existingChallenge = $existingStmt->fetch();
        if ($existingChallenge && (bool)$existingChallenge['cooldown_active']) {
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
            return ['sent' => false, 'preview_code' => null, 'error' => 'Please wait before requesting another code.'];
        }

        $otp = generateEmailOtp();
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO email_verifications (user_id, otp_hash, expires_at, attempts, resend_after)
             VALUES (:user_id, :otp_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, DATE_ADD(NOW(), INTERVAL 60 SECOND))
             ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), expires_at = VALUES(expires_at), attempts = 0,
               resend_after = VALUES(resend_after), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['user_id' => $userId, 'otp_hash' => $hash]);

        try {
            $delivery = $emailService->sendVerificationCode($recipient, $otp);
        } catch (Throwable $exception) {
            invalidatePendingEmailOtp($pdo, $userId, $hash);
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
            error_log('ServeIQ OTP provider threw ' . get_class($exception) . '.');
            return ['sent' => false, 'preview_code' => null, 'error' => 'We could not send the verification code. Please try again shortly.'];
        }

        if (!$delivery['sent']) {
            invalidatePendingEmailOtp($pdo, $userId, $hash);
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
            return ['sent' => false, 'preview_code' => null, 'error' => 'We could not send the verification code. Please try again shortly.'];
        }

        completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
        return ['sent' => true, 'preview_code' => $delivery['preview_code'], 'error' => null];
    } catch (Throwable $exception) {
        rollbackEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive);
        throw $exception;
    }
}

function invalidatePendingEmailOtp(PDO $pdo, int $userId, string $otpHash): void
{
    $stmt = $pdo->prepare(
        "UPDATE email_verifications
         SET otp_hash = '', expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), attempts = 5
         WHERE user_id = :user_id AND otp_hash = :otp_hash"
    );
    $stmt->execute(['user_id' => $userId, 'otp_hash' => $otpHash]);
}

function completeEmailOtpTransaction(PDO $pdo, bool $ownsTransaction, bool &$savepointActive, string $savepoint = 'serveiq_issue_email_otp'): void
{
    if ($ownsTransaction) {
        $pdo->commit();
    } elseif ($savepointActive) {
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        $savepointActive = false;
    }
}

function rollbackEmailOtpTransaction(PDO $pdo, bool $ownsTransaction, bool &$savepointActive, string $savepoint = 'serveiq_issue_email_otp'): void
{
    if ($ownsTransaction) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } elseif ($savepointActive && $pdo->inTransaction()) {
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        $savepointActive = false;
    }
}

function verifyEmailOtp(PDO $pdo, int $userId, string $submittedOtp): string
{
    if (!preg_match('/^[0-9]{6}$/', $submittedOtp)) {
        return 'invalid';
    }

    $ownsTransaction = !$pdo->inTransaction();
    $savepointActive = false;
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT serveiq_verify_email_otp');
            $savepointActive = true;
        }

        $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
        $userStmt->execute(['user_id' => $userId]);
        if (!$userStmt->fetch()) {
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
            return 'missing';
        }

        $stmt = $pdo->prepare('SELECT otp_hash, expires_at, attempts FROM email_verifications WHERE user_id = :user_id FOR UPDATE');
        $stmt->execute(['user_id' => $userId]);
        $challenge = $stmt->fetch();
        if (!$challenge) {
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
            return 'missing';
        }
        if (strtotime((string)$challenge['expires_at']) <= time()) {
            $pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
            return 'expired';
        }
        if ((int)$challenge['attempts'] >= 5) {
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
            return 'locked';
        }
        if (!password_verify($submittedOtp, (string)$challenge['otp_hash'])) {
            $pdo->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
            return 'invalid';
        }

        $pdo->prepare('UPDATE users SET is_email_verified = 1 WHERE id = :user_id')->execute(['user_id' => $userId]);
        $pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        completeEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
        return 'verified';
    } catch (Throwable $e) {
        rollbackEmailOtpTransaction($pdo, $ownsTransaction, $savepointActive, 'serveiq_verify_email_otp');
        throw $e;
    }
}

function resendEmailOtp(PDO $pdo, EmailServiceInterface $emailService, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.email, u.is_email_verified FROM users u WHERE u.id = :user_id LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_email_verified'] === 1) {
        return ['sent' => false, 'preview_code' => null, 'error' => 'Verification is unavailable for this account.'];
    }
    return issueEmailOtp($pdo, $emailService, $userId, (string)$row['email'], true);
}
