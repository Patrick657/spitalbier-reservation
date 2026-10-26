<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (no Composer/PHPMailer dependency) so this deploys
 * on plain Plesk PHP hosting with no extra install step. Supports AUTH LOGIN
 * and STARTTLS/implicit TLS, which covers a typical mailbox-as-relay setup.
 */
final class SmtpException extends RuntimeException
{
    /** True when the server went away (no answer) rather than refusing something. */
    public bool $connectionLost = false;

    public static function lost(string $message): self
    {
        $e = new self($message);
        $e->connectionLost = true;
        return $e;
    }
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
    /** @var string what the current SMTP exchange is doing, for error messages */
    private $step = '';
    /** @var bool whether the message itself has gone out on this connection */
    private $dataSent = false;

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

    /**
     * @param string[] $cc  extra recipients named in the mail's Cc header
     * @param string[] $bcc extra recipients the others don't get to see
     */
    public function send(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody = null, array $cc = [], array $bcc = []): void
    {
        foreach (array_merge([$toEmail, $this->fromEmail], $cc, $bcc) as $addr) {
            if (preg_match('/[\r\n]/', $addr)) {
                throw new SmtpException('Invalid address (header injection attempt).');
            }
        }

        $message = $this->dotStuff($this->buildMessage($toEmail, $toName, $subject, $textBody, $htmlBody, $cc));

        // A server that hangs up before the message went out gets one more
        // try. Once the message was sent we never retry — it may have been
        // delivered, and a guest must not get it twice.
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->deliver($toEmail, $cc, $bcc, $message);
                return;
            } catch (SmtpException $e) {
                if ($attempt >= 2 || $this->dataSent || !$e->connectionLost) {
                    throw $e;
                }
                sleep(2);
            }
        }
    }

    /**
     * @param string[] $cc
     * @param string[] $bcc
     */
    private function deliver(string $toEmail, array $cc, array $bcc, string $message): void
    {
        $this->dataSent = false;
        $this->step = 'Verbindungsaufbau';
        $sock = $this->connect();
        try {
            $this->expect($sock, 220);
            $this->step = 'EHLO';
            $this->command($sock, 'EHLO ' . $this->heloDomain(), 250);

            if ($this->secure === 'tls') {
                $this->step = 'STARTTLS';
                $this->command($sock, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw SmtpException::lost('STARTTLS negotiation failed.');
                }
                $this->command($sock, 'EHLO ' . $this->heloDomain(), 250);
            }

            if ($this->username !== '') {
                $this->step = 'Anmeldung (AUTH)';
                $this->command($sock, 'AUTH LOGIN', 334);
                $this->command($sock, base64_encode($this->username), 334);
                $this->command($sock, base64_encode($this->password), 235);
            }

            $this->step = 'Absender (MAIL FROM)';
            $this->command($sock, 'MAIL FROM:<' . $this->fromEmail . '>', 250);
            foreach (array_merge([$toEmail], $cc, $bcc) as $addr) {
                $this->step = 'Empfänger ' . $addr . ' (RCPT TO)';
                $this->command($sock, 'RCPT TO:<' . $addr . '>', [250, 251]);
            }
            $this->step = 'DATA';
            $this->command($sock, 'DATA', 354);

            $this->step = 'Übertragen der Nachricht';
            $this->dataSent = true;
            $this->write($sock, $message . "\r\n.");
            $this->expect($sock, 250);
        } catch (Throwable $e) {
            @fclose($sock);
            throw $e;
        }

        // Accepted — the mail is on its way. A server that hangs up on QUIT
        // doesn't change that, so errors from here on are ignored.
        try {
            stream_set_timeout($sock, 2);
            $this->write($sock, 'QUIT');
            @fgets($sock, 515);
        } catch (SmtpException $e) {
            // ignore
        }
        @fclose($sock);
    }

    /** @param string[] $cc */
    private function buildMessage(string $toEmail, string $toName, string $subject, string $textBody, ?string $htmlBody, array $cc = []): string
    {
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>';
        $headers[] = 'To: ' . $this->encodeHeader($toName) . ' <' . $toEmail . '>';
        if ($cc) {
            $headers[] = 'Cc: ' . implode(', ', array_map(static fn(string $addr): string => '<' . $addr . '>', $cc));
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloDomain() . '>';

        // Base64 keeps every line short and every line end a proper CRLF —
        // strict servers drop the connection on over-long lines or bare LFs.
        if ($htmlBody !== null) {
            $boundary = 'sbf-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . $this->encodeBody($textBody)
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . $this->encodeBody($htmlBody)
                . "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = $this->encodeBody($textBody);
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
            throw SmtpException::lost("Could not connect to {$this->host}:{$this->port}: {$errstr}");
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
            throw SmtpException::lost('Failed to write to SMTP socket (' . $this->step . ').');
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
                throw SmtpException::lost('SMTP connection closed unexpectedly (' . $this->step . ').');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new SmtpException('Unexpected SMTP response (' . $this->step . "): {$response}");
        }
        return $response;
    }

    /** Base64 in 76-character lines, with CRLF line ends inside the content too. */
    private function encodeBody(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return chunk_split(base64_encode(str_replace("\n", "\r\n", $text)), 76, "\r\n");
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
