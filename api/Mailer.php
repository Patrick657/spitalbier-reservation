<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (no Composer/PHPMailer dependency) so this deploys
 * on plain Plesk PHP hosting with no extra install step. Supports AUTH LOGIN
 * and STARTTLS/implicit TLS, which covers a typical mailbox-as-relay setup.
 */
final class SmtpException extends RuntimeException
{
}

final class SmtpMailer
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $secure;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var string */
    private $fromEmail;
    /** @var string */
    private $fromName;
    /** @var int */
    private $timeout;

    public function __construct(
        string $host,
        int $port,
        string $secure,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        int $timeout = 15
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->secure = $secure;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->timeout = $timeout;
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        foreach ([$toEmail, $this->fromEmail] as $addr) {
            if (preg_match('/[\r\n]/', $addr)) {
                throw new SmtpException('Invalid address (header injection attempt).');
            }
        }

        $sock = $this->connect();
        try {
            $this->expect($sock, 220);
            $this->command($sock, 'EHLO ' . $this->heloDomain(), 250);

            if ($this->secure === 'tls') {
                $this->command($sock, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new SmtpException('STARTTLS negotiation failed.');
                }
                $this->command($sock, 'EHLO ' . $this->heloDomain(), 250);
            }

            if ($this->username !== '') {
                $this->command($sock, 'AUTH LOGIN', 334);
                $this->command($sock, base64_encode($this->username), 334);
                $this->command($sock, base64_encode($this->password), 235);
            }

            $this->command($sock, 'MAIL FROM:<' . $this->fromEmail . '>', 250);
            $this->command($sock, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($sock, 'DATA', 354);

            $message = $this->buildMessage($toEmail, $toName, $subject, $textBody, $htmlBody);
            $message = $this->dotStuff($message);

            $this->write($sock, $message . "\r\n.");
            $this->expect($sock, 250);

            $this->command($sock, 'QUIT', 221);
        } finally {
            fclose($sock);
        }
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody): string
    {
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';
        $headers[] = 'To: ' . $this->encodeHeader($toName) . ' <' . $toEmail . '>';
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloDomain() . '>';

        if ($htmlBody !== null) {
            $boundary = 'sbf-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $textBody . "\r\n";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** @return resource */
    private function connect()
    {
        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $sock = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($sock === false) {
            throw new SmtpException("Could not connect to {$this->host}:{$this->port}: {$errstr}");
        }
        stream_set_timeout($sock, $this->timeout);
        return $sock;
    }

    private function heloDomain(): string
    {
        $at = strrpos($this->fromEmail, '@');
        return $at !== false ? substr($this->fromEmail, $at + 1) : 'localhost';
    }

    /** @param resource $sock */
    private function write($sock, string $data): void
    {
        if (fwrite($sock, $data . "\r\n") === false) {
            throw new SmtpException('Failed to write to SMTP socket.');
        }
    }

    /**
     * @param resource $sock
     * @param int|int[] $expectedCode
     */
    private function command($sock, string $line, $expectedCode): string
    {
        $this->write($sock, $line);
        return $this->expect($sock, $expectedCode);
    }

    /**
     * @param resource $sock
     * @param int|int[] $expectedCode
     */
    private function expect($sock, $expectedCode): string
    {
        $codes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $response = '';
        do {
            $line = fgets($sock, 515);
            if ($line === false) {
                throw new SmtpException('SMTP connection closed unexpectedly.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new SmtpException("Unexpected SMTP response: {$response}");
        }
        return $response;
    }

    private function dotStuff(string $message): string
    {
        $result = preg_replace('/^\./m', '..', $message);
        return $result !== null ? $result : $message;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new SmtpException('Invalid header value (injection attempt).');
        }
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
