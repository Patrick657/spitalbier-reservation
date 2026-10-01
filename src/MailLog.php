<?php
declare(strict_types=1);

require_once __DIR__ . '/Mailer.php';

/**
 * Sends a mail and records the attempt (sent or failed) in mail_log.
 * Logging is best-effort: a missing table or DB hiccup must never block
 * the mail itself. A failed send is rethrown so callers keep their own
 * error handling. Copies ($cc/$bcc) ride along on the same mail and are
 * not logged as rows of their own.
 */
function sbf_send_logged(
    PDO $pdo,
    SmtpMailer $mailer,
    string $kind,
    ?string $reservationCode,
    string $toEmail,
    string $toName,
    string $subject,
    string $textBody,
    ?string $htmlBody = null,
    array $cc = [],
    array $bcc = []
): void {
    $error = null;
    try {
        $mailer->send($toEmail, $toName, $subject, $textBody, $htmlBody, $cc, $bcc);
    } catch (Throwable $e) {
        $error = $e;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_log (recipient_email, recipient_name, subject, kind, reservation_code, status, error)
             VALUES (:email, :name, :subject, :kind, :code, :status, :error)'
        );
        $stmt->execute([
            'email' => mb_substr($toEmail, 0, 190),
            'name' => $toName !== '' ? mb_substr($toName, 0, 190) : null,
            'subject' => mb_substr($subject, 0, 190),
            'kind' => $kind,
            'code' => $reservationCode,
            'status' => $error === null ? 'sent' : 'failed',
            'error' => $error === null ? null : mb_substr($error->getMessage(), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[spitalbierfest] mail log error: ' . $e->getMessage());
    }

    if ($error !== null) {
        throw $error;
    }
}
