<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$csrf = (string) ($_POST['csrf'] ?? '');
$back = 'edit.php' . ($id ? '?id=' . $id : '');

if (!$id || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: index.php?msg=badtoken');
    exit;
}

$cfg = sbf_config();
$maxGuests = (int) ($cfg['admin_max_guests_per_reservation'] ?? 60);

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$guests = filter_var($_POST['guests'] ?? null, FILTER_VALIDATE_INT);
$newsletter = isset($_POST['news']);
$sendMail = isset($_POST['send_mail']);
$adminNote = trim((string) ($_POST['admin_note'] ?? ''));

$reason = null;
if ($name === '' || mb_strlen($name) > 190) {
    $reason = 'name';
} elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
    $reason = 'email';
} elseif ($guests === false || $guests < 1 || $guests > $maxGuests) {
    $reason = 'guests';
} elseif ($phone !== '' && mb_strlen($phone) > 60) {
    $reason = 'phone';
} elseif ($adminNote !== '' && mb_strlen($adminNote) > 190) {
    $reason = 'note';
}

if ($reason !== null) {
    header('Location: ' . $back . '&msg=invalid&reason=' . $reason);
    exit;
}

$pdo = sbf_pdo();

try {
    $pdo->beginTransaction();

    // Same lock order everywhere — capacity_counter first — so nothing
    // here can deadlock against reserve.php/cancel.php/assign.php.
    $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1 FOR UPDATE');

    $stmt = $pdo->prepare('SELECT id, guests, cancelled_at FROM reservations WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        $pdo->rollBack();
        header('Location: index.php?msg=notfound');
        exit;
    }

    if ($reservation['cancelled_at'] !== null) {
        $pdo->rollBack();
        header('Location: index.php?msg=notfound');
        exit;
    }

    $assignedStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE reservation_id = :id');
    $assignedStmt->execute(['id' => $id]);
    $assignedSeats = (int) $assignedStmt->fetchColumn();

    if ($guests < $assignedSeats) {
        $pdo->rollBack();
        header('Location: ' . $back . '&msg=invalid&reason=guests_below_assigned');
        exit;
    }

    $delta = $guests - (int) $reservation['guests'];

    $update = $pdo->prepare(
        'UPDATE reservations
         SET name = :name, email = :email, phone = :phone, guests = :guests,
             newsletter = :newsletter, admin_note = :note
         WHERE id = :id'
    );
    $update->execute([
        'name' => $name,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'guests' => $guests,
        'newsletter' => $newsletter ? 1 : 0,
        'note' => $adminNote !== '' ? $adminNote : null,
        'id' => $id,
    ]);

    if ($delta !== 0) {
        $counter = $pdo->prepare('UPDATE capacity_counter SET seats_taken = GREATEST(0, seats_taken + :d) WHERE id = 1');
        $counter->execute(['d' => $delta]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[spitalbierfest] update error: ' . $e->getMessage());
    header('Location: ' . $back . '&msg=invalid');
    exit;
}

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

            $subject = 'Ihre Reservierung zum Spitalbierfest wurde aktualisiert';
            $text = "Vergelt's Gott, {$name}!\n\n"
                . "Ihre Reservierung zum 1. Straubinger Spitalbierfest wurde aktualisiert: aktuell {$guests} Plätze.\n\n"
                . "Termin: Freitag, 30. Oktober 2026, 18:00 Uhr\n"
                . "Ort: Rittersaal im Herzogschloss, Straubing\n\n"
                . "Fragen? stiftungsamt@straubing.de\n\n"
                . "Bürgerspitalstiftung Straubing";
            $html = '<p>Vergelt&rsquo;s Gott, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!</p>'
                . '<p>Ihre Reservierung zum 1. Straubinger Spitalbierfest wurde aktualisiert: aktuell <strong>' . $guests . ' Plätze</strong>.</p>'
                . '<p>Termin: Freitag, 30. Oktober 2026, 18:00 Uhr<br>Ort: Rittersaal im Herzogschloss, Straubing</p>'
                . '<p>Fragen? <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a></p>'
                . '<p>Bürgerspitalstiftung Straubing</p>';

            $mailer->send($email, $name, $subject, $text, $html);
        }
    } catch (Throwable $e) {
        error_log('[spitalbierfest] update mail error: ' . $e->getMessage());
    }
}

header('Location: index.php?msg=updated');
