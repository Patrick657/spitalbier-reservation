<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/MailLog.php';
require_once __DIR__ . '/../../src/TableMail.php';

// Sends the Tischbestätigung to ONE recipient of the batch that was
// previewed and confirmed in table_mail.php. A recipient is taken off the
// batch's pending list before the mail goes out, so a repeated or replayed
// request can never send the same mail twice.

function sbf_send_result(int $http, string $status, string $message = ''): void
{
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Keeps the outcome for the progress page, should it be reloaded. */
function sbf_send_remember(int $id, string $status, string $message): void
{
    session_start();
    if (isset($_SESSION['table_mail_batch'])) {
        $_SESSION['table_mail_batch']['results'][$id] = ['status' => $status, 'message' => $message];
    }
    session_write_close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sbf_send_result(405, 'error', 'Method not allowed.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$batch = $_SESSION['table_mail_batch'] ?? null;

if (!$id || !hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    sbf_send_result(400, 'error', 'Ungültige Anfrage.');
}
if (!$batch || !$batch['started'] || !hash_equals($batch['token'], (string) ($_POST['token'] ?? ''))) {
    sbf_send_result(409, 'error', 'Dieser Versand ist nicht (mehr) freigegeben.');
}
$position = array_search($id, $batch['pending'], true);
if ($position === false) {
    sbf_send_result(409, 'error', 'Dieser Empfänger wurde bereits verarbeitet.');
}

// Claim the recipient, then release the session so a slow SMTP server
// doesn't block the rest of the dashboard.
unset($_SESSION['table_mail_batch']['pending'][$position]);
$_SESSION['table_mail_batch']['pending'] = array_values($_SESSION['table_mail_batch']['pending']);
$_SESSION['table_mail_batch']['results'][$id] = ['status' => 'unknown', 'message' => 'Versand unterbrochen – bitte im E-Mail-Log prüfen.'];
session_write_close();

try {
    $cfg = sbf_config();
    $pdo = sbf_pdo();
    $mailer = sbf_table_mail_mailer($cfg);

    // Always from current data: tables may have changed since the preview.
    $recipient = sbf_table_mail_candidates($pdo, [$id])[$id] ?? null;

    $skip = null;
    if ($mailer === null) {
        $skip = 'Kein SMTP-Server konfiguriert.';
    } elseif ($recipient === null) {
        $skip = 'Reservierung wurde inzwischen storniert oder gelöscht.';
    } elseif ($recipient['blocker'] !== null) {
        $skip = 'Inzwischen: ' . $recipient['blocker'] . '.';
    } elseif ($recipient['sent_count'] > $batch['recipients'][$id]) {
        $skip = 'Wurde inzwischen bereits benachrichtigt.';
    }
    if ($skip !== null) {
        sbf_send_remember($id, 'skipped', $skip);
        sbf_send_result(200, 'skipped', $skip);
    }

    $vars = sbf_table_mail_vars($recipient);
    $text = sbf_table_mail_render($batch['body'], $vars);

    sbf_send_logged(
        $pdo,
        $mailer,
        SBF_TABLE_MAIL_KIND,
        $recipient['code'],
        $recipient['email'],
        $vars['name'],
        sbf_table_mail_render($batch['subject'], $vars),
        $text,
        sbf_table_mail_html($text)
    );
} catch (Throwable $e) {
    error_log('[spitalbierfest] table mail error: ' . $e->getMessage());
    $message = $e instanceof SmtpException ? trim($e->getMessage()) : 'Interner Fehler.';
    sbf_send_remember($id, 'failed', $message);
    sbf_send_result(200, 'failed', $message);
}

sbf_send_remember($id, 'sent', '');
sbf_send_result(200, 'sent');
