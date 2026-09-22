<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Mailer.php';

function sbf_json_error(int $status, string $message, array $errors = []): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sbf_json_error(405, 'Method not allowed.');
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST; // fallback if ever posted as a classic form
}

// Honeypot: a hidden field real visitors never see or fill in.
if (!empty($input['website'])) {
    echo json_encode(['ok' => true, 'code' => 'SBF-0000', 'seatsLeft' => null, 'seatPct' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = sbf_config();
$maxGuests = $cfg['max_guests_per_reservation'];
$cap = $cfg['capacity'];

$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$dsgvo = filter_var($input['dsgvo'] ?? false, FILTER_VALIDATE_BOOLEAN);
$newsletter = filter_var($input['news'] ?? false, FILTER_VALIDATE_BOOLEAN);
$guests = filter_var($input['guests'] ?? null, FILTER_VALIDATE_INT);

$errors = [];

if ($name === '') {
    $errors['name'] = 'Bitte Namen angeben.';
} elseif (mb_strlen($name) > 190) {
    $errors['name'] = 'Name ist zu lang.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Bitte gültige E-Mail-Adresse angeben.';
} elseif (mb_strlen($email) > 190) {
    $errors['email'] = 'E-Mail-Adresse ist zu lang.';
}

if ($guests === false || $guests < 1 || $guests > $maxGuests) {
    $errors['guests'] = "Bitte zwischen 1 und {$maxGuests} Personen angeben.";
}

if (!$dsgvo) {
    $errors['dsgvo'] = 'Ohne Einwilligung können wir die Reservierung nicht speichern.';
}

if ($phone !== '' && mb_strlen($phone) > 60) {
    $errors['phone'] = 'Telefonnummer ist zu lang.';
}

try {
    $deadline = new DateTime($cfg['event_deadline'], new DateTimeZone('Europe/Berlin'));
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    if ($now >= $deadline) {
        sbf_json_error(409, 'Reservierungen sind für dieses Fest leider nicht mehr möglich (Anmeldeschluss war 18:30 Uhr).');
    }
} catch (Exception $e) {
    // A misconfigured deadline string should never block valid reservations.
    error_log('[spitalbierfest] invalid event_deadline config: ' . $e->getMessage());
}

if ($errors) {
    sbf_json_error(422, 'Bitte Angaben prüfen.', $errors);
}

$pdo = sbf_pdo();
$seatsTaken = 0;
$code = '';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT seats_taken FROM capacity_counter WHERE id = 1 FOR UPDATE');
    $stmt->execute();
    $seatsTaken = (int) $stmt->fetchColumn();

    if ($seatsTaken + $guests > $cap) {
        $pdo->rollBack();
        $left = max(0, $cap - $seatsTaken);
//        sbf_json_error(409, "Es sind nur noch {$left} Plätze frei.", ['guests' => "Es sind nur noch {$left} Plätze frei."]);
        sbf_json_error(409, "Ausreserviert? Für spontane Besuche stehen <strong>weitere Sitzplätze sowie Stehtische</strong> zur Verfügung!", ['guests' => "Ausreserviert? Für spontane Besuche stehen <strong>weitere Sitzplätze sowie Stehtische</strong> zur Verfügung!"]);
    }

    $code = 'SBF-' . random_int(1000, 9999);

    $insert = $pdo->prepare(
        'INSERT INTO reservations (code, name, email, phone, guests, newsletter, dsgvo_consent_at, created_at)
         VALUES (:code, :name, :email, :phone, :guests, :newsletter, NOW(), NOW())'
    );
    $insert->execute([
        'code' => $code,
        'name' => $name,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'guests' => $guests,
        'newsletter' => $newsletter ? 1 : 0,
    ]);

    $update = $pdo->prepare('UPDATE capacity_counter SET seats_taken = seats_taken + :g WHERE id = 1');
    $update->execute(['g' => $guests]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        sbf_json_error(409, 'Bitte erneut versuchen.');
    }
    error_log('[spitalbierfest] DB error: ' . $e->getMessage());
    sbf_json_error(500, 'Die Reservierung konnte nicht gespeichert werden. Bitte später erneut versuchen.');
}

$seatsLeft = max(0, $cap - ($seatsTaken + $guests));
$seatPct = $cap > 0 ? (int) round((($seatsTaken + $guests) / $cap) * 100) : 0;

// Best-effort email: the reservation is already saved, so a mail failure
// must not turn into a user-facing error or a lost reservation.
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

        if ($cfg['admin_email'] !== '') {
            $adminSubject = 'Neue Reservierung: ' . $code;
            $adminText = "Neue Reservierung ({$code})\n\n"
                . "Name: {$name}\nE-Mail: {$email}\nTelefon: " . ($phone !== '' ? $phone : '–')
                . "\nPersonen: {$guests}\nNewsletter: " . ($newsletter ? 'ja' : 'nein');
            $mailer->send($cfg['admin_email'], 'Reservierungen Spitalbierfest', $adminSubject, $adminText);
        }
    }
} catch (Throwable $e) {
    error_log('[spitalbierfest] Mail error: ' . $e->getMessage());
}

echo json_encode([
    'ok' => true,
    'code' => $code,
    'seatsLeft' => $seatsLeft,
    'seatPct' => $seatPct,
], JSON_UNESCAPED_UNICODE);
