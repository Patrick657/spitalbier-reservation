<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$cfg = sbf_config();
$cap = $cfg['capacity'];
$maxGuests = (int) ($cfg['admin_max_guests_per_reservation'] ?? 60);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pdo = sbf_pdo();

$stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id');
$stmt->execute(['id' => $id]);
$reservation = $stmt->fetch();

$assignedStmt = $pdo->prepare('SELECT COALESCE(SUM(seats), 0) FROM table_assignments WHERE reservation_id = :id');
$assignedStmt->execute(['id' => $id]);
$assignedSeats = (int) $assignedStmt->fetchColumn();

$seatsTaken = (int) $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1')->fetchColumn();
$seatsLeft = max(0, $cap - $seatsTaken);

$reasonTexts = [
    'name' => 'Bitte einen Namen angeben (max. 190 Zeichen).',
    'email' => 'Die E-Mail-Adresse ist ungültig oder zu lang.',
    'guests' => "Bitte eine Personenzahl zwischen 1 und {$maxGuests} angeben.",
    'guests_below_assigned' => "Die Personenzahl kann nicht unter die bereits im Sitzplan zugewiesenen {$assignedSeats} Plätze gesenkt werden. Bitte zuerst die Tischzuweisung anpassen.",
    'phone' => 'Die Telefonnummer ist zu lang (max. 60 Zeichen).',
    'note' => 'Die Notiz ist zu lang (max. 190 Zeichen).',
];

$flash = null;
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'invalid') {
        $reason = (string) ($_GET['reason'] ?? '');
        $flash = ['type' => 'error', 'text' => $reasonTexts[$reason] ?? 'Bitte Angaben prüfen.'];
    } elseif ($_GET['msg'] === 'badtoken') {
        $flash = ['type' => 'error', 'text' => 'Ungültige Anfrage. Bitte erneut versuchen.'];
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Reservierung bearbeiten &ndash; Spitalbierfest Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bitter:ital,wght@0,700;0,800&family=Source+Sans+3:ital,wght@0,400;0,600;0,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashboard.css">
</head>
<body>

<header class="dash-header">
  <div class="dash-header__brand">
    <span>Spitalbierfest &ndash; Dashboard</span>
    <span class="dash-header__sub">B&uuml;rgerspitalstiftung Straubing</span>
  </div>
  <a class="dash-header__link" href="../index.html">Zur Website</a>
</header>

<nav class="dash-tabs">
  <a href="index.php" class="is-active">Buchungen</a>
  <a href="seating.php">Sitzplan</a>
  <a href="new.php">Neue Reservierung</a>
</nav>

<main class="dash-main">

  <?php if (!$reservation): ?>
  <div class="dash-flash dash-flash--error">Reservierung nicht gefunden.</div>
  <p><a class="assign-panel__close" href="index.php">Zur&uuml;ck zur &Uuml;bersicht</a></p>
  <?php elseif ($reservation['cancelled_at'] !== null): ?>
  <div class="dash-flash dash-flash--error">Diese Reservierung (<?= e($reservation['code']) ?>) ist storniert und kann nicht mehr bearbeitet werden.</div>
  <p><a class="assign-panel__close" href="index.php">Zur&uuml;ck zur &Uuml;bersicht</a></p>
  <?php else: ?>

  <?php if ($flash): ?>
  <div class="dash-flash dash-flash--<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <div class="dash-form-card">
    <p class="hint">
      Reservierung <strong><?= e($reservation['code']) ?></strong> bearbeiten.
      Aktuell <strong><?= $seatsLeft ?> von <?= $cap ?></strong> Pl&auml;tzen frei (&ouml;ffentlicher Z&auml;hler).
      <?php if ($assignedSeats > 0): ?>
        Im Sitzplan sind bereits <strong><?= $assignedSeats ?></strong> Pl&auml;tze dieser Reservierung zugewiesen &ndash; die Personenzahl kann nicht darunter gesenkt werden, ohne die Tischzuweisung zuerst anzupassen.
      <?php endif; ?>
    </p>

    <form method="post" action="update.php" class="assign-form">
      <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <label>Name
        <input type="text" name="name" required maxlength="190" value="<?= e($reservation['name']) ?>">
      </label>

      <label>E-Mail (optional)
        <input type="email" name="email" value="<?= e($reservation['email']) ?>">
      </label>

      <label>Telefon (optional)
        <input type="tel" name="phone" value="<?= e($reservation['phone'] ?? '') ?>">
      </label>

      <label>Anzahl Personen
        <input type="number" name="guests" min="<?= max(1, $assignedSeats) ?>" max="<?= $maxGuests ?>" value="<?= (int) $reservation['guests'] ?>" required>
      </label>

      <label class="checkbox-row">
        <input type="checkbox" name="news" <?= $reservation['newsletter'] ? 'checked' : '' ?>>
        <span>Newsletter-Einwilligung liegt vor.</span>
      </label>

      <label class="checkbox-row">
        <input type="checkbox" name="send_mail">
        <span>Best&auml;tigungsmail mit den ge&auml;nderten Daten an die E-Mail-Adresse senden (falls vorhanden).</span>
      </label>

      <label>Notiz (optional, nur intern sichtbar)
        <input type="text" name="admin_note" maxlength="190" value="<?= e($reservation['admin_note'] ?? '') ?>">
      </label>

      <button type="submit" class="dash-btn-cancel">Speichern</button>
    </form>

    <a class="assign-panel__close" href="index.php">Abbrechen</a>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
