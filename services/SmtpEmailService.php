<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailServiceInterface.php';

final class SmtpEmailService implements EmailServiceInterface
{
    public function sendVerificationCode(string $recipient, string $code): array
    {
        $host = trim((string)getenv('SMTP_HOST'));
        $port = (int)(getenv('SMTP_PORT') ?: 587);
        $username = (string)getenv('SMTP_USERNAME');
        $password = (string)getenv('SMTP_PASSWORD');
        $from = trim((string)getenv('SMTP_FROM_EMAIL'));
        $fromName = trim((string)(getenv('SMTP_FROM_NAME') ?: 'ServeIQ'));
        $encryption = strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: 'tls')));

        if ($host === '' || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !in_array($encryption, ['tls', 'ssl'], true)) {
            error_log('ServeIQ SMTP is not configured with valid host/from/encryption settings.');
            return ['sent' => false, 'preview_code' => null];
        }

        $target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $error, 8, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            error_log('ServeIQ SMTP connection failed (' . $errno . ').');
            return ['sent' => false, 'preview_code' => null];
        }

        stream_set_timeout($socket, 8);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO serveiq.local', [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command($socket, 'EHLO serveiq.local', [250]);
            }
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $subject = 'Your ServeIQ verification code';
            $body = "Your ServeIQ verification code is {$code}. It expires in 10 minutes. If you did not create this account, ignore this message.";
            $message = 'From: ' . $this->safeHeader($fromName) . ' <' . $from . ">\r\n"
                . 'To: <' . $recipient . ">\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $body;
            $message = preg_replace('/\r?\n\./', "\r\n..", $message) ?? $message;
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
            return ['sent' => true, 'preview_code' => null];
        } catch (Throwable $e) {
            error_log('ServeIQ SMTP delivery failed: ' . $e->getMessage());
            return ['sent' => false, 'preview_code' => null];
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    private function expect($socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP server response timed out.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $status = (int)substr($response, 0, 3);
        if (!in_array($status, $expected, true)) {
            throw new RuntimeException('SMTP server rejected a delivery step (status ' . $status . ').');
        }
        return $response;
    }

    private function safeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
