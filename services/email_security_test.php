<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/load_environment.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../includes/email_verification.php';

final class EmailCooldownMockPdo extends PDO
{
    public bool $transactionActive = false;
    public bool $userExists = true;
    public bool $userVerified = false;
    public bool $cooldownActive = false;
    public bool $challengeExists = false;
    public int $challengeUserId = 1;
    public string $challengeHash = '';
    public string $challengeExpiresAt = '';
    public int $challengeAttempts = 0;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public int $savepointRollbackCalls = 0;
    public ?string $failAt = null;
    public array $queries = [];
    private array $transactionSnapshot = [];
    private array $savepointSnapshot = [];

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        if ($this->failAt === 'begin') {
            throw new PDOException('mock begin failure');
        }
        $this->transactionSnapshot = $this->snapshot();
        $this->transactionActive = true;
        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        if ($this->failAt === 'commit') {
            throw new PDOException('mock commit failure');
        }
        $this->transactionActive = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
        $this->restore($this->transactionSnapshot);
        $this->transactionActive = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;
        if ($this->failAt === 'savepoint' && str_starts_with($statement, 'SAVEPOINT ')) {
            throw new PDOException('mock savepoint failure');
        }
        if (str_starts_with($statement, 'SAVEPOINT ')) {
            $this->savepointSnapshot = $this->snapshot();
        } elseif (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ')) {
            $this->savepointRollbackCalls++;
            $this->restore($this->savepointSnapshot);
        } elseif (str_starts_with($statement, 'RELEASE SAVEPOINT ')) {
            $this->savepointSnapshot = [];
        }
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new EmailCooldownMockStatement($this, $query);
    }

    public function executeMock(string $query, ?array $params): bool
    {
        if ($this->failAt === 'user_lock' && str_contains($query, 'FROM users WHERE id')) {
            throw new PDOException('mock user lock failure');
        }
        if ($this->failAt === 'cooldown_query' && str_contains($query, 'cooldown_active')) {
            throw new PDOException('mock cooldown query failure');
        }
        if ($this->failAt === 'insert' && str_starts_with($query, 'INSERT INTO email_verifications')) {
            throw new PDOException('mock insert failure');
        }
        if ($this->failAt === 'invalidate' && str_starts_with($query, 'UPDATE email_verifications')) {
            throw new PDOException('mock invalidation failure');
        }
        if (str_starts_with($query, 'INSERT INTO email_verifications')) {
            $this->challengeExists = true;
            $this->challengeUserId = (int)($params['user_id'] ?? 0);
            $this->challengeHash = (string)($params['otp_hash'] ?? '');
            $this->challengeExpiresAt = date('Y-m-d H:i:s', time() + 600);
            $this->challengeAttempts = 0;
            $this->cooldownActive = true;
        } elseif (str_starts_with($query, 'UPDATE email_verifications') && str_contains($query, 'SET otp_hash')) {
            if ($this->failAt === 'invalidate') {
                throw new PDOException('mock invalidation failure');
            }
            if ($this->challengeExists && $this->challengeUserId === (int)($params['user_id'] ?? 0)
                && $this->challengeHash === (string)($params['otp_hash'] ?? '')) {
                $this->challengeHash = '';
                $this->challengeExpiresAt = date('Y-m-d H:i:s', time() - 1);
                $this->challengeAttempts = 5;
            }
        } elseif (str_starts_with($query, 'UPDATE email_verifications SET attempts = attempts + 1')) {
            if ($this->challengeExists && $this->challengeUserId === (int)($params['user_id'] ?? 0)) {
                $this->challengeAttempts++;
            }
        } elseif (str_starts_with($query, 'UPDATE users SET is_email_verified')) {
            $this->userVerified = true;
        } elseif (str_starts_with($query, 'DELETE FROM email_verifications')) {
            if ($this->challengeUserId === (int)($params['user_id'] ?? 0)) {
                $this->challengeExists = false;
            }
        }
        return true;
    }

    public function fetchMock(string $query, ?array $params): array|false
    {
        if (str_contains($query, 'FROM users WHERE id')) {
            return $this->userExists ? ['id' => (int)($params['user_id'] ?? 1), 'is_email_verified' => (int)$this->userVerified] : false;
        }
        if (str_contains($query, 'SELECT u.email, u.is_email_verified')) {
            return $this->userExists ? ['email' => 'user@example.test', 'is_email_verified' => (int)$this->userVerified] : false;
        }
        if (str_contains($query, 'cooldown_active')) {
            return $this->challengeExists && $this->challengeUserId === (int)($params['user_id'] ?? 0)
                ? ['cooldown_active' => $this->cooldownActive]
                : false;
        }
        if (str_contains($query, 'SELECT otp_hash, expires_at, attempts')) {
            return $this->challengeExists && $this->challengeUserId === (int)($params['user_id'] ?? 0)
                ? ['otp_hash' => $this->challengeHash, 'expires_at' => $this->challengeExpiresAt, 'attempts' => $this->challengeAttempts]
                : false;
        }
        return false;
    }

    private function snapshot(): array
    {
        return [$this->userVerified, $this->challengeExists, $this->challengeUserId, $this->challengeHash, $this->challengeExpiresAt, $this->challengeAttempts, $this->cooldownActive];
    }

    private function restore(array $snapshot): void
    {
        if ($snapshot !== []) {
            [$this->userVerified, $this->challengeExists, $this->challengeUserId, $this->challengeHash, $this->challengeExpiresAt, $this->challengeAttempts, $this->cooldownActive] = $snapshot;
        }
    }
}

final class EmailCooldownMockStatement extends PDOStatement
{
    private ?array $params = null;

    public function __construct(private EmailCooldownMockPdo $pdo, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params;
        return $this->pdo->executeMock($this->query, $params);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->pdo->fetchMock($this->query, $this->params);
    }
}

final class EmailDeliverySpy implements EmailServiceInterface
{
    public int $calls = 0;
    public bool $sent = true;
    public bool $throws = false;
    public string $lastCode = '';

    public function sendVerificationCode(string $recipient, string $code): array
    {
        $this->calls++;
        $this->lastCode = $code;
        if ($this->throws) {
            throw new RuntimeException('mock provider exception');
        }
        return ['sent' => $this->sent, 'preview_code' => null];
    }
}

$code = generateEmailOtp();
$preview = (new DevelopmentPreviewEmailService())->sendVerificationCode('demo@example.com', $code);
$cooldownPdo = new EmailCooldownMockPdo();
$cooldownPdo->challengeExists = true;
$cooldownPdo->cooldownActive = true;
$emailDeliverySpy = new EmailDeliverySpy();
$cooldownResult = issueEmailOtp($cooldownPdo, $emailDeliverySpy, 1, 'user@example.com');
$cooldownPreventsResend = !$cooldownResult['sent'] && $emailDeliverySpy->calls === 0 && !$cooldownPdo->transactionActive;
$cooldownCommitsOwnedTransaction = $cooldownPdo->beginCalls === 1 && $cooldownPdo->commitCalls === 1 && $cooldownPdo->rollbackCalls === 0;
$accountRowLockedBeforeCooldown = str_contains($cooldownPdo->queries[0] ?? '', 'FROM users WHERE id')
    && str_contains($cooldownPdo->queries[0] ?? '', 'FOR UPDATE');

$insertFailurePdo = new EmailCooldownMockPdo();
$insertFailurePdo->failAt = 'insert';
$insertFailureMailer = new EmailDeliverySpy();
$insertFailureThrown = false;
try {
    issueEmailOtp($insertFailurePdo, $insertFailureMailer, 1, 'user@example.test');
} catch (PDOException) {
    $insertFailureThrown = true;
}
$insertFailureRollsBack = $insertFailureThrown && $insertFailurePdo->rollbackCalls === 1
    && !$insertFailurePdo->transactionActive && !$insertFailurePdo->challengeExists && $insertFailureMailer->calls === 0;

$commitFailurePdo = new EmailCooldownMockPdo();
$commitFailurePdo->failAt = 'commit';
$commitFailureMailer = new EmailDeliverySpy();
$commitFailureThrown = false;
try {
    issueEmailOtp($commitFailurePdo, $commitFailureMailer, 1, 'user@example.test');
} catch (PDOException) {
    $commitFailureThrown = true;
}
$commitFailureRollsBack = $commitFailureThrown && $commitFailurePdo->rollbackCalls === 1
    && !$commitFailurePdo->transactionActive && !$commitFailurePdo->challengeExists && $commitFailureMailer->calls === 1;

$providerRejectPdo = new EmailCooldownMockPdo();
$providerRejectMailer = new EmailDeliverySpy();
$providerRejectMailer->sent = false;
$providerRejectResult = issueEmailOtp($providerRejectPdo, $providerRejectMailer, 1, 'user@example.test');
$providerRejectInvalidatesAndCommitsCooldown = !$providerRejectResult['sent'] && $providerRejectPdo->commitCalls === 1
    && $providerRejectPdo->rollbackCalls === 0 && !$providerRejectPdo->transactionActive
    && $providerRejectPdo->challengeExists && $providerRejectPdo->challengeHash === ''
    && $providerRejectPdo->challengeAttempts === 5 && $providerRejectPdo->cooldownActive;

$providerThrowPdo = new EmailCooldownMockPdo();
$providerThrowMailer = new EmailDeliverySpy();
$providerThrowMailer->throws = true;
$providerThrowResult = issueEmailOtp($providerThrowPdo, $providerThrowMailer, 1, 'user@example.test');
$providerThrowInvalidatesAndCommitsCooldown = !$providerThrowResult['sent'] && $providerThrowPdo->commitCalls === 1
    && $providerThrowPdo->rollbackCalls === 0 && !$providerThrowPdo->transactionActive
    && $providerThrowPdo->challengeExists && $providerThrowPdo->challengeHash === ''
    && $providerThrowPdo->challengeAttempts === 5 && $providerThrowPdo->cooldownActive;

$invalidateFailurePdo = new EmailCooldownMockPdo();
$invalidateFailurePdo->failAt = 'invalidate';
$invalidateFailureMailer = new EmailDeliverySpy();
$invalidateFailureMailer->sent = false;
$invalidateFailureThrown = false;
try {
    issueEmailOtp($invalidateFailurePdo, $invalidateFailureMailer, 1, 'user@example.test');
} catch (PDOException) {
    $invalidateFailureThrown = true;
}
$invalidateFailureRollsBack = $invalidateFailureThrown && $invalidateFailurePdo->rollbackCalls === 1
    && !$invalidateFailurePdo->transactionActive && !$invalidateFailurePdo->challengeExists;

$callerTransactionPdo = new EmailCooldownMockPdo();
$callerTransactionPdo->transactionActive = true;
$callerTransactionMailer = new EmailDeliverySpy();
$callerTransactionMailer->sent = false;
$callerTransactionResult = issueEmailOtp($callerTransactionPdo, $callerTransactionMailer, 1, 'user@example.test');
$callerTransactionSavepointHandled = !$callerTransactionResult['sent'] && $callerTransactionPdo->transactionActive
    && $callerTransactionPdo->savepointRollbackCalls === 0 && $callerTransactionPdo->commitCalls === 0
    && $callerTransactionPdo->rollbackCalls === 0 && $callerTransactionPdo->challengeExists
    && $callerTransactionPdo->challengeHash === '' && $callerTransactionPdo->cooldownActive;

$successfulIssuePdo = new EmailCooldownMockPdo();
$successfulIssueMailer = new EmailDeliverySpy();
$successfulIssueResult = issueEmailOtp($successfulIssuePdo, $successfulIssueMailer, 7, 'user@example.test');
$successfulIssueCommitsHash = $successfulIssueResult['sent'] && $successfulIssuePdo->commitCalls === 1
    && $successfulIssuePdo->rollbackCalls === 0 && password_verify($successfulIssueMailer->lastCode, $successfulIssuePdo->challengeHash);

$verificationCode = '482601';
$verifiedPdo = new EmailCooldownMockPdo();
$verifiedPdo->challengeExists = true;
$verifiedPdo->challengeUserId = 7;
$verifiedPdo->challengeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
$verifiedPdo->challengeExpiresAt = date('Y-m-d H:i:s', time() + 600);
$verificationResult = verifyEmailOtp($verifiedPdo, 7, $verificationCode);
$verificationLocksAccountFirst = str_contains($verifiedPdo->queries[0] ?? '', 'FROM users WHERE id')
    && str_contains($verifiedPdo->queries[0] ?? '', 'FOR UPDATE')
    && str_contains($verifiedPdo->queries[1] ?? '', 'FROM email_verifications');
$verificationIsSingleUse = $verificationResult === 'verified' && $verifiedPdo->userVerified
    && !$verifiedPdo->challengeExists && verifyEmailOtp($verifiedPdo, 7, $verificationCode) === 'missing';

$wrongAccountPdo = new EmailCooldownMockPdo();
$wrongAccountPdo->challengeExists = true;
$wrongAccountPdo->challengeUserId = 7;
$wrongAccountPdo->challengeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
$wrongAccountPdo->challengeExpiresAt = date('Y-m-d H:i:s', time() + 600);
$wrongAccountCannotConsumeOtp = verifyEmailOtp($wrongAccountPdo, 8, $verificationCode) === 'missing'
    && $wrongAccountPdo->challengeExists && !$wrongAccountPdo->userVerified;

$expiredOtpPdo = new EmailCooldownMockPdo();
$expiredOtpPdo->challengeExists = true;
$expiredOtpPdo->challengeUserId = 7;
$expiredOtpPdo->challengeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
$expiredOtpPdo->challengeExpiresAt = date('Y-m-d H:i:s', time() - 1);
$expiredOtpIsDeleted = verifyEmailOtp($expiredOtpPdo, 7, $verificationCode) === 'expired' && !$expiredOtpPdo->challengeExists;

$wrongAttemptPdo = new EmailCooldownMockPdo();
$wrongAttemptPdo->challengeExists = true;
$wrongAttemptPdo->challengeUserId = 7;
$wrongAttemptPdo->challengeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
$wrongAttemptPdo->challengeExpiresAt = date('Y-m-d H:i:s', time() + 600);
$wrongAttemptCounts = verifyEmailOtp($wrongAttemptPdo, 7, '999999') === 'invalid'
    && $wrongAttemptPdo->challengeAttempts === 1 && $wrongAttemptPdo->challengeExists;

$lockedOtpPdo = new EmailCooldownMockPdo();
$lockedOtpPdo->challengeExists = true;
$lockedOtpPdo->challengeUserId = 7;
$lockedOtpPdo->challengeHash = password_hash($verificationCode, PASSWORD_DEFAULT);
$lockedOtpPdo->challengeExpiresAt = date('Y-m-d H:i:s', time() + 600);
$lockedOtpPdo->challengeAttempts = 5;
$attemptLimitEnforced = verifyEmailOtp($lockedOtpPdo, 7, $verificationCode) === 'locked'
    && !$lockedOtpPdo->userVerified && $lockedOtpPdo->challengeExists;

$malformedOtpPdo = new EmailCooldownMockPdo();
$malformedOtpRejectedBeforeTransaction = verifyEmailOtp($malformedOtpPdo, 7, '12A456') === 'invalid'
    && $malformedOtpPdo->beginCalls === 0;

$verifiedAccountPdo = new EmailCooldownMockPdo();
$verifiedAccountPdo->userVerified = true;
$verifiedAccountMailer = new EmailDeliverySpy();
$verifiedAccountCannotResend = !issueEmailOtp($verifiedAccountPdo, $verifiedAccountMailer, 7, 'user@example.test', true)['sent']
    && $verifiedAccountMailer->calls === 0 && !$verifiedAccountPdo->challengeExists;

// Test getEnvVar behavior
$_ENV['TEST_KEY_ENV'] = 'from_env';
$_SERVER['TEST_KEY_SERVER'] = 'from_server';
putenv('TEST_KEY_GETENV=from_getenv');

$envTest1 = getEnvVar('TEST_KEY_ENV') === 'from_env';
$envTest2 = getEnvVar('TEST_KEY_SERVER') === 'from_server';
$envTest3 = getEnvVar('TEST_KEY_GETENV') === 'from_getenv';
$envTest4 = getEnvVar('NON_EXISTENT_KEY', 'default_val') === 'default_val';

// Test empty environment override ignore
putenv('TEST_KEY_EMPTY=');
$_ENV['TEST_KEY_EMPTY'] = 'valid_value';
$envTest5 = getEnvVar('TEST_KEY_EMPTY') === 'valid_value';

// Test createEmailService provider selection
putenv('MAIL_PROVIDER=resend');
$_ENV['MAIL_PROVIDER'] = 'resend';
$resendService = createEmailService();
$isResendService = ($resendService instanceof ResendEmailService);

// Test Resend missing API key handling
putenv('RESEND_API_KEY=');
$_ENV['RESEND_API_KEY'] = '';
$_SERVER['RESEND_API_KEY'] = '';
$resendDeliveryFail = $resendService->sendVerificationCode('user@example.com', '123456');
$missingKeyHandled = ($resendDeliveryFail['sent'] === false);

putenv('MAIL_PROVIDER=brevo');
$_ENV['MAIL_PROVIDER'] = 'brevo';
$_SERVER['MAIL_PROVIDER'] = 'brevo';
$brevoService = createEmailService();
$isBrevoService = ($brevoService instanceof BrevoEmailService);
putenv('BREVO_API_KEY=');
$_ENV['BREVO_API_KEY'] = '';
$_SERVER['BREVO_API_KEY'] = '';
$brevoDeliveryFail = $brevoService->sendVerificationCode('user@example.com', '123456');
$brevoMissingKeyHandled = ($brevoDeliveryFail['sent'] === false);

putenv('MAIL_PROVIDER=');
$_ENV['MAIL_PROVIDER'] = '';
$_SERVER['MAIL_PROVIDER'] = '';
putenv('MAIL_TRANSPORT=resend');
$_ENV['MAIL_TRANSPORT'] = 'resend';
$_SERVER['MAIL_TRANSPORT'] = 'resend';
$transportFallbackSelectsResend = (createEmailService() instanceof ResendEmailService);

putenv('APP_ENV=development');
$_ENV['APP_ENV'] = 'development';
$_SERVER['APP_ENV'] = 'development';
putenv('MAIL_PROVIDER=development_preview');
$_ENV['MAIL_PROVIDER'] = 'development_preview';
$_SERVER['MAIL_PROVIDER'] = 'development_preview';
$developmentPreviewSelected = (createEmailService() instanceof DevelopmentPreviewEmailService);

$mockBrevoRequest = null;
$mockResendRequest = null;
putenv('MAIL_FROM=');
$_ENV['MAIL_FROM'] = '';
$_SERVER['MAIL_FROM'] = '';
putenv('BREVO_FROM_EMAIL=brevo-sender@example.test');
$_ENV['BREVO_FROM_EMAIL'] = 'brevo-sender@example.test';
$_SERVER['BREVO_FROM_EMAIL'] = 'brevo-sender@example.test';
putenv('BREVO_API_KEY=mock-brevo-key');
$_ENV['BREVO_API_KEY'] = 'mock-brevo-key';
$_SERVER['BREVO_API_KEY'] = 'mock-brevo-key';
$mockBrevoService = new BrevoEmailService(static function (string $url, array $headers, array $payload, int $timeout) use (&$mockBrevoRequest): array {
    $mockBrevoRequest = compact('url', 'headers', 'payload', 'timeout');
    return ['response' => '{"messageId":"mock"}', 'http_code' => 201, 'curl_errno' => 0, 'curl_error' => ''];
});
$mockBrevoDelivery = $mockBrevoService->sendVerificationCode('customer@example.test', $code);
$brevoRequestContractValid = $mockBrevoDelivery['sent']
    && $mockBrevoRequest['url'] === 'https://api.brevo.com/v3/smtp/email'
    && in_array('api-key: mock-brevo-key', $mockBrevoRequest['headers'], true)
    && $mockBrevoRequest['payload']['sender']['email'] === 'brevo-sender@example.test'
    && $mockBrevoRequest['payload']['to'][0]['email'] === 'customer@example.test'
    && str_contains($mockBrevoRequest['payload']['textContent'], $code)
    && $mockBrevoRequest['timeout'] === 15;

putenv('RESEND_FROM_EMAIL=resend-sender@example.test');
$_ENV['RESEND_FROM_EMAIL'] = 'resend-sender@example.test';
$_SERVER['RESEND_FROM_EMAIL'] = 'resend-sender@example.test';
putenv('RESEND_API_KEY=mock-resend-key');
$_ENV['RESEND_API_KEY'] = 'mock-resend-key';
$_SERVER['RESEND_API_KEY'] = 'mock-resend-key';
$mockResendService = new ResendEmailService(static function (string $url, array $headers, array $payload, int $timeout) use (&$mockResendRequest): array {
    $mockResendRequest = compact('url', 'headers', 'payload', 'timeout');
    return ['response' => '{"id":"mock"}', 'http_code' => 200, 'curl_errno' => 0, 'curl_error' => ''];
});
$mockResendDelivery = $mockResendService->sendVerificationCode('customer@example.test', $code);
$resendRequestContractValid = $mockResendDelivery['sent']
    && $mockResendRequest['url'] === 'https://api.resend.com/emails'
    && in_array('Authorization: Bearer mock-resend-key', $mockResendRequest['headers'], true)
    && $mockResendRequest['payload']['from'] === 'resend-sender@example.test'
    && $mockResendRequest['payload']['to'] === ['customer@example.test']
    && str_contains($mockResendRequest['payload']['text'], $code)
    && $mockResendRequest['timeout'] === 15;

$emailLogPath = tempnam(sys_get_temp_dir(), 'serveiq-email-test-');
$providerLogsAreSanitized = false;
if ($emailLogPath !== false) {
    $previousErrorLog = (string)ini_get('error_log');
    ini_set('error_log', $emailLogPath);
    try {
        $sensitiveMockResponse = json_encode(['key' => 'mock-brevo-key', 'otp' => $code, 'recipient' => 'customer@example.test']);
        $failingBrevoService = new BrevoEmailService(static function () use ($sensitiveMockResponse): array {
            return ['response' => $sensitiveMockResponse, 'http_code' => 500, 'curl_errno' => 0, 'curl_error' => 'mock failure'];
        });
        $failingResendService = new ResendEmailService(static function () use ($sensitiveMockResponse): array {
            return ['response' => $sensitiveMockResponse, 'http_code' => 500, 'curl_errno' => 0, 'curl_error' => 'mock failure'];
        });
        $failingBrevoService->sendVerificationCode('customer@example.test', $code);
        $failingResendService->sendVerificationCode('customer@example.test', $code);
        $providerLog = (string)file_get_contents($emailLogPath);
        $providerLogsAreSanitized = !str_contains($providerLog, 'mock-brevo-key')
            && !str_contains($providerLog, 'mock-resend-key')
            && !str_contains($providerLog, $code)
            && !str_contains($providerLog, 'customer@example.test');
    } finally {
        ini_set('error_log', $previousErrorLog);
        unlink($emailLogPath);
    }
}

$cases = [
    ['OTP is exactly six decimal digits', (bool)preg_match('/^[0-9]{6}$/', $code)],
    ['Development preview returns generated code', $preview['sent'] === true && $preview['preview_code'] === $code],
    ['getEnvVar reads from $_ENV', $envTest1],
    ['getEnvVar reads from $_SERVER', $envTest2],
    ['getEnvVar reads from getenv()', $envTest3],
    ['getEnvVar returns default value for missing keys', $envTest4],
    ['getEnvVar ignores empty getenv values in favor of non-empty $_ENV', $envTest5],
    ['createEmailService selects ResendEmailService when MAIL_PROVIDER=resend', $isResendService],
    ['ResendEmailService safely handles missing API key', $missingKeyHandled],
    ['createEmailService selects BrevoEmailService when MAIL_PROVIDER=brevo', $isBrevoService],
    ['BrevoEmailService safely handles missing API key', $brevoMissingKeyHandled],
    ['MAIL_TRANSPORT fallback selects the configured provider', $transportFallbackSelectsResend],
    ['Development preview requires APP_ENV=development', $developmentPreviewSelected],
    ['Active resend cooldown blocks replacement OTP delivery', $cooldownPreventsResend],
    ['OTP cooldown check locks the account row before reading challenge state', $accountRowLockedBeforeCooldown],
    ['Cooldown completion commits its owned read transaction', $cooldownCommitsOwnedTransaction],
    ['OTP insert database failure rolls back before calling the provider', $insertFailureRollsBack],
    ['OTP commit failure rolls back and does not report success', $commitFailureRollsBack],
    ['Provider rejection commits only an expired locked challenge and retains cooldown', $providerRejectInvalidatesAndCommitsCooldown],
    ['Provider exception commits only an expired locked challenge and returns safe failure', $providerThrowInvalidatesAndCommitsCooldown],
    ['Database failure invalidating a rejected OTP rolls back the whole owned transaction', $invalidateFailureRollsBack],
    ['Caller-owned registration transaction retains ownership and records no usable OTP', $callerTransactionSavepointHandled],
    ['Successful delivery commits a password-hashed OTP', $successfulIssueCommitsHash],
    ['Verification locks the account before its challenge row', $verificationLocksAccountFirst],
    ['Successful verification consumes the OTP exactly once', $verificationIsSingleUse],
    ['An OTP cannot be consumed by a different account ID', $wrongAccountCannotConsumeOtp],
    ['Expired OTPs are rejected and deleted', $expiredOtpIsDeleted],
    ['Invalid OTP attempts increment the challenge counter', $wrongAttemptCounts],
    ['Five failed attempts prevent further verification', $attemptLimitEnforced],
    ['Malformed OTPs are rejected before opening a transaction', $malformedOtpRejectedBeforeTransaction],
    ['Verified accounts cannot receive verification resends', $verifiedAccountCannotResend],
    ['Brevo uses the expected endpoint, auth header, sender, recipient, and OTP payload', $brevoRequestContractValid],
    ['Resend uses the expected endpoint, bearer auth, sender, recipient, and OTP payload', $resendRequestContractValid],
    ['Provider failure logs exclude API keys, OTPs, response bodies, and email addresses', $providerLogsAreSanitized],
];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("EMAIL SECURITY & CONFIG TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
