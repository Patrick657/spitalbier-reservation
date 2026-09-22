<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$cfg = sbf_config();
$cap = $cfg['capacity'];
$tz = new DateTimeZone('Europe/Berlin');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$pdo = sbf_pdo();

$totalBookings = (int) $pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
$cancelledCount = (int) $pdo->query('SELECT COUNT(*) FROM reservations WHERE cancelled_at IS NOT NULL')->fetchColumn();

$monday = new DateTime('now', $tz);
$monday->modify('monday this week')->setTime(0, 0, 0);
$nextMonday = (clone $monday)->modify('+7 days');

$weekStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE created_at >= :start AND created_at < :end');
$weekStmt->execute(['start' => $monday->format('Y-m-d H:i:s'), 'end' => $nextMonday->format('Y-m-d H:i:s')]);
$weekBookings = (int) $weekStmt->fetchColumn();

$seatsTaken = (int) $pdo->query('SELECT seats_taken FROM capacity_counter WHERE id = 1')->fetchColumn();
$occupancyPct = $cap > 0 ? round(($seatsTaken / $cap) * 100, 1) : 0.0;

$rows = $pdo->query(
    'SELECT id, code, name, email, phone, guests, newsletter, created_at, cancelled_at, admin_note, source
     FROM reservations ORDER BY created_at DESC'
)->fetchAll();

$assignedByReservation = [];
foreach ($pdo->query(
    "SELECT ta.reservation_id, vt.code, ta.seats
     FROM table_assignments ta
     JOIN venue_tables vt ON vt.id = ta.table_id
     WHERE ta.reservation_id IS NOT NULL
     ORDER BY vt.row_label, vt.position"
)->fetchAll() as $a) {
    $assignedByReservation[(int) $a['reservation_id']][] = $a['code'] . ' (' . (int) $a['seats'] . ')';
}

$flashes = [
    'cancelled' => ['type' => 'ok', 'text' => 'Reservierung wurde storniert.'],
    'already' => ['type' => 'info', 'text' => 'Diese Reservierung war bereits storniert.'],
    'notfound' => ['type' => 'error', 'text' => 'Reservierung nicht gefunden.'],
    'badtoken' => ['type' => 'error', 'text' => 'Ungültige Anfrage. Bitte erneut versuchen.'],
    'updated' => ['type' => 'ok', 'text' => 'Reservierung wurde aktualisiert.'],
];
$flash = null;
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') {
        $flash = ['type' => 'ok', 'text' => 'Reservierung ' . (string) ($_GET['code'] ?? '') . ' wurde angelegt.'];
    } else {
        $flash = $flashes[$_GET['msg']] ?? null;
    }
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Dashboard &ndash; Spitalbierfest</title>
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
  <a href="export.php">PDF-Export</a>
</nav>

<main class="dash-main">

  <?php if ($flash): ?>
  <div class="dash-flash dash-flash--<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <div class="dash-kpis">
    <div class="dash-kpi">
      <div class="dash-kpi__label">Buchungen diese Woche</div>
      <div class="dash-kpi__value"><?= $weekBookings ?></div>
    </div>
    <div class="dash-kpi">
      <div class="dash-kpi__label">Buchungen gesamt</div>
      <div class="dash-kpi__value"><?= $totalBookings ?></div>
      <div class="dash-kpi__sub"><?= $cancelledCount ?> storniert</div>
    </div>
    <div class="dash-kpi">
      <div class="dash-kpi__label">Auslastung</div>
      <div class="dash-kpi__value"><?= $occupancyPct ?>%</div>
      <div class="dash-kpi__sub"><?= $seatsTaken ?> von <?= $cap ?> Pl&auml;tzen</div>
    </div>
  </div>

  <div class="dash-table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>E-Mail</th>
          <th>Telefon</th>
          <th>Personen</th>
          <th>Newsletter</th>
          <th>Eingegangen</th>
          <th>Status</th>
          <th>Sitzplatz</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="10" class="dash-table__empty">Noch keine Reservierungen.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): $cancelled = $r['cancelled_at'] !== null; ?>
        <tr class="<?= $cancelled ? 'is-cancelled' : '' ?>">
          <td><?= e($r['code']) ?></td>
          <td>
            <?= e($r['name']) ?>
            <?php if (!empty($r['admin_note'])): ?><br><span class="dash-note">Notiz: <?= e($r['admin_note']) ?></span><?php endif; ?>
          </td>
          <td><?php if ($r['email'] !== ''): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php else: ?>&ndash;<?php endif; ?></td>
          <td><?= e($r['phone'] !== null ? $r['phone'] : '–') ?></td>
          <td><?= (int) $r['guests'] ?></td>
          <td><?= $r['newsletter'] ? 'ja' : 'nein' ?></td>
          <td><?= e((new DateTime($r['created_at'], $tz))->format('d.m.Y H:i')) ?></td>
          <td>
            <?php if ($cancelled): ?>
              <span class="dash-badge dash-badge--cancelled">storniert</span>
            <?php else: ?>
              <span class="dash-badge dash-badge--active">aktiv</span>
            <?php endif; ?>
            <?php if ($r['source'] === 'admin'): ?>
              <span class="dash-badge dash-badge--manual">manuell</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $assigned = $assignedByReservation[(int) $r['id']] ?? [];
              $assignedSeats = 0;
              foreach ($assigned as $label) {
                  if (preg_match('/\((\d+)\)/', $label, $m)) { $assignedSeats += (int) $m[1]; }
              }
            ?>
            <?php if ($assigned): ?>
              <span class="dash-seats" title="<?= e(implode(', ', $assigned)) ?>"><?= e(implode(', ', $assigned)) ?></span>
              <?php if ($assignedSeats < (int) $r['guests']): ?><span class="dash-seats__partial">unvollst&auml;ndig</span><?php endif; ?>
            <?php else: ?>
              <span class="dash-seats dash-seats--none">nicht zugewiesen</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$cancelled): ?>
            <div class="dash-row-actions">
              <a class="dash-btn-edit" href="edit.php?id=<?= (int) $r['id'] ?>">Bearbeiten</a>
              <form method="post" action="cancel.php" class="dash-cancel-form" data-name="<?= e($r['name']) ?>" data-code="<?= e($r['code']) ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="dash-btn-cancel">Stornieren</button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<script>
document.querySelectorAll(".dash-cancel-form").forEach(function (form) {
  form.addEventListener("submit", function (evt) {
    var name = form.dataset.name || "";
    var code = form.dataset.code || "";
    if (!window.confirm("Reservierung von " + name + " (" + code + ") wirklich stornieren?")) {
      evt.preventDefault();
    }
  });
});
</script>
</body>
</html>
