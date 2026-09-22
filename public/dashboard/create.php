<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: new.php');
    exit;
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: new.php?msg=badtoken');
    exit;
}

$cfg = sbf_config();
// Fallback in case an older api/config.php (without this key) is still
// deployed — without it, "$guests > null" is true for any guest count
// and every submission would fail validation.
$maxGuests = (int) ($cfg['admin_max_guests_per_reservation'] ?? 60);

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$guests = filter_var($_POST['guests'] ?? null, FILTER_VALIDATE_INT);
$newsletter = isset($_POST['news']);
$dsgvo = isset($_POST['dsgvo']);
$sendMail = isset($_POST['send_mail']);
$adminNote = trim((string) ($_POST['admin_note'] ?? ''));

$reason = null;
if ($name === '' || mb_strlen($name) > 190) {
    $reason = 'name';
} elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
    $reason = 'email';
} elseif ($guests === false || $guests < 1 || $guests > $maxGuests) {
    $reason = 'guests';
} elseif (!$dsgvo) {
    $reason = 'dsgvo';
} elseif ($phone !== '' && mb_strlen($phone) > 60) {
    $reason = 'phone';
} elseif ($adminNote !== '' && mb_strlen($adminNote) > 190) {
    $reason = 'note';
}

if ($reason !== null) {
    header('Location: new.php?msg=invalid&reason=' . $reason);
    exit;
}

$pdo = sbf_pdo();
$code = '';

try {
    $pdo->beginTransaction();

    // Same lock order as reserve.php/cancel.php/assign.php — always
    // capacity_counter first — so nothing can deadlock against this.
    $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1 FOR UPDATE');

    $code = 'SBF-' . random_int(1000, 9999);

    $insert = $pdo->prepare(
        'INSERT INTO reservations (code, name, email, phone, guests, newsletter, dsgvo_consent_at, created_at, admin_note, source)
         VALUES (:code, :name, :email, :phone, :guests, :newsletter, NOW(), NOW(), :note, :source)'
    );
    $insert->execute([
        'code' => $code,
        'name' => $name,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'guests' => $guests,
        'newsletter' => $newsletter ? 1 : 0,
        'note' => $adminNote !== '' ? $adminNote : null,
        'source' => 'admin',
    ]);

    $counter = $pdo->prepare('UPDATE capacity_counter SET seats_taken = seats_taken + :g WHERE id = 1');
    $counter->execute(['g' => $guests]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        header('Location: new.php?msg=invalid');
        exit;
    }
    error_log('[spitalbierfest] admin create error: ' . $e->getMessage());
    header('Location: new.php?msg=invalid');
    exit;
}

// Best-effort email, same as the public form — a failed send must not
// undo an already-saved reservation.
if ($sendMail && $email !== '') {
    try {
        $smtp = $cfg['smtp'];
        if ($smtp['host'] !== '') {
            $mailer = new SmtpMailer(
                $smtp['host'],
                $smtp['port'],
                $smtp['secure'],
                $smtp['user'],
                $smtp['pass'],
                $smtp['from_email'],
                $smtp['from_name']
            );

            $subject = 'Ihre Reservierung zum Spitalbierfest – ' . $code;
            $text = "Vergelt's Gott, {$name}!\n\n"
                . "wir haben {$guests} Plätze für Sie zum 1. Straubinger Spitalbierfest vorgemerkt.\n\n"
                . "Reservierungsnummer: {$code}\n"
                . "Termin: Freitag, 30. Oktober 2026, 18:00 Uhr\n"
                . "Ort: Rittersaal im Herzogschloss, Straubing\n"
                . "Ihre Tische werden bis 18:30 Uhr freigehalten, danach entfallen nicht angetretene Reservierungen.\n\n"
                . "Fragen? stiftungsamt@straubing.de\n\n"
                . "Bürgerspitalstiftung Straubing";
            $html = '<p>Vergelt&rsquo;s Gott, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!</p>'
                . '<p>wir haben <strong>' . $guests . ' Plätze</strong> für Sie zum 1. Straubinger Spitalbierfest vorgemerkt.</p>'
                . '<p>Reservierungsnummer: <strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                . 'Termin: Freitag, 30. Oktober 2026, 18:00 Uhr<br>'
                . 'Ort: Rittersaal im Herzogschloss, Straubing</p>'
                . '<p>Ihre Tische werden bis 18:30 Uhr freigehalten, danach entfallen nicht angetretene Reservierungen.</p>'
                . '<p>Fragen? <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a></p>'
                . '<p>Bürgerspitalstiftung Straubing</p>';

            $mailer->send($email, $name, $subject, $text, $html);
        }
    } catch (Throwable $e) {
        error_log('[spitalbierfest] admin create mail error: ' . $e->getMessage());
    }
}

header('Location: new.php?msg=created&code=' . urlencode($code));
