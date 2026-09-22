<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$cfg = sbf_config();
$cap = $cfg['capacity'];
$maxGuests = (int) ($cfg['admin_max_guests_per_reservation'] ?? 60);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$pdo = sbf_pdo();
$seatsTaken = (int) $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1')->fetchColumn();
$seatsLeft = max(0, $cap - $seatsTaken);

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$reasonTexts = [
    'name' => 'Bitte einen Namen angeben (max. 190 Zeichen).',
    'email' => 'Die E-Mail-Adresse ist ungültig oder zu lang.',
    'guests' => "Bitte eine Personenzahl zwischen 1 und {$maxGuests} angeben.",
    'dsgvo' => 'Bitte bestätigen, dass die Einwilligung zur Datenspeicherung vorliegt.',
    'phone' => 'Die Telefonnummer ist zu lang (max. 60 Zeichen).',
    'note' => 'Die Notiz ist zu lang (max. 190 Zeichen).',
];

$flash = null;
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') {
        $code = (string) ($_GET['code'] ?? '');
        $flash = ['type' => 'ok', 'text' => 'Reservierung ' . $code . ' wurde angelegt.'];
    } elseif ($_GET['msg'] === 'invalid') {
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
<title>Neue Reservierung &ndash; Spitalbierfest Dashboard</title>
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
  <a href="index.php">Buchungen</a>
  <a href="seating.php">Sitzplan</a>
  <a href="new.php" class="is-active">Neue Reservierung</a>
</nav>

<main class="dash-main">

  <?php if ($flash): ?>
  <div class="dash-flash dash-flash--<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <div class="dash-form-card">
    <p class="hint">Aktuell <strong><?= $seatsLeft ?> von <?= $cap ?></strong> Pl&auml;tzen frei (&ouml;ffentlicher Z&auml;hler). Gr&ouml;&szlig;ere Gruppen k&ouml;nnen hier auch &uuml;ber die normale 10-Personen-Grenze des Online-Formulars hinaus angelegt werden.</p>

    <form method="post" action="create.php" class="assign-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <label>Name
        <input type="text" name="name" required maxlength="190" placeholder="Vor- und Nachname oder Gruppenbezeichnung">
      </label>

      <label>E-Mail (optional)
        <input type="email" name="email" placeholder="name@beispiel.de">
      </label>

      <label>Telefon (optional)
        <input type="tel" name="phone" placeholder="f&uuml;r R&uuml;ckfragen">
      </label>

      <label>Anzahl Personen
        <input type="number" name="guests" min="1" max="<?= $maxGuests ?>" value="10" required>
      </label>

      <label class="checkbox-row">
        <input type="checkbox" name="dsgvo" required>
        <span>Einwilligung zur Datenspeicherung liegt vor (z.&nbsp;B. telefonisch eingeholt). <span class="required">*</span></span>
      </label>

      <label class="checkbox-row">
        <input type="checkbox" name="news">
        <span>Newsletter-Einwilligung liegt ebenfalls vor.</span>
      </label>

      <label class="checkbox-row">
        <input type="checkbox" name="send_mail" checked>
        <span>Best&auml;tigungsmail an die angegebene E-Mail-Adresse senden (falls vorhanden).</span>
      </label>

      <label>Notiz (optional, nur intern sichtbar)
        <input type="text" name="admin_note" maxlength="190" placeholder="z.&nbsp;B. telefonisch gebucht, Vereinsausflug">
      </label>

      <button type="submit" class="dash-btn-cancel">Reservierung anlegen</button>
    </form>
  </div>
</main>
</body>
</html>
