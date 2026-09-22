<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$pdo = sbf_pdo();

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$tz = new DateTimeZone('Europe/Berlin');

$tables = $pdo->query('SELECT id, code, row_label, position, seats, base_seats, merged_into FROM venue_tables ORDER BY row_label, position')->fetchAll();

$tablesById = [];
$rowsByLabel = [];
foreach ($tables as $t) {
    $tablesById[(int) $t['id']] = $t;
    $rowsByLabel[$t['row_label']][] = $t;
}

$occupiedByTable = [];
foreach ($pdo->query('SELECT table_id, SUM(seats) AS occupied FROM table_assignments GROUP BY table_id')->fetchAll() as $row) {
    $occupiedByTable[(int) $row['table_id']] = (int) $row['occupied'];
}

$assignmentsByTable = [];
foreach ($pdo->query(
    'SELECT ta.table_id, ta.seats, ta.note, ta.reservation_id, r.name
     FROM table_assignments ta
     LEFT JOIN reservations r ON r.id = ta.reservation_id
     ORDER BY ta.created_at ASC'
)->fetchAll() as $row) {
    $assignmentsByTable[(int) $row['table_id']][] = $row;
}

// "Name (Sitze)" per table, shared by the graphical floor plan and the
// per-row detail tables below so both stay in sync.
$labelsByTable = [];
foreach ($assignmentsByTable as $tid => $entries) {
    foreach ($entries as $en) {
        $labelsByTable[$tid][] = $en['reservation_id']
            ? $en['name'] . ' (' . (int) $en['seats'] . ')'
            : ($en['note'] !== null && $en['note'] !== '' ? $en['note'] : 'Blockiert') . ' (manuell, ' . (int) $en['seats'] . ')';
    }
}

$assignedByReservation = [];
foreach ($pdo->query(
    'SELECT ta.reservation_id, vt.code, ta.seats
     FROM table_assignments ta
     JOIN venue_tables vt ON vt.id = ta.table_id
     WHERE ta.reservation_id IS NOT NULL
     ORDER BY vt.row_label, vt.position'
)->fetchAll() as $a) {
    $assignedByReservation[(int) $a['reservation_id']][] = $a;
}

$reservations = $pdo->query(
    "SELECT id, code, name, guests FROM reservations WHERE cancelled_at IS NULL ORDER BY name ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sitzplan &amp; Reservierungen &ndash; Spitalbierfest Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bitter:ital,wght@0,700;0,800&family=Source+Sans+3:ital,wght@0,400;0,600;0,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashboard.css">
<style>
  .pdf-toolbar { display: flex; gap: 12px; align-items: center; padding: 16px 24px; background: var(--parchment); border-bottom: 1px solid #d9cbae; }
  .pdf-toolbar button { font: 600 14px/1 var(--sans); padding: 10px 16px; border-radius: 6px; border: 0; background: var(--rust); color: #fff; cursor: pointer; }
  .pdf-toolbar button:hover { background: var(--rust-hover); }
  .pdf-toolbar a { font: 600 14px/1 var(--sans); color: var(--rust); text-decoration: none; }

  .pdf-doc { max-width: 900px; margin: 0 auto; padding: 32px 24px 64px; color: var(--ink); background: #fff; }
  .pdf-header { display: flex; align-items: center; gap: 20px; border-bottom: 3px solid var(--rust); padding-bottom: 16px; margin-bottom: 28px; }
  .pdf-header img { height: 72px; width: auto; }
  .pdf-header h1 { font: 800 24px/1.2 var(--serif); margin: 0 0 4px; color: var(--ink); }
  .pdf-header__sub { font: 400 13px/1.3 var(--sans); color: var(--ink-soft); }

  .pdf-doc h2 { font: 700 18px/1.2 var(--serif); color: var(--ink); border-bottom: 1px solid #d9cbae; padding-bottom: 6px; margin: 32px 0 12px; }
  .pdf-doc h3 { font: 700 14px/1.2 var(--serif); color: var(--ink-soft); margin: 18px 0 6px; }

  .pdf-table { width: 100%; border-collapse: collapse; font: 400 13px/1.4 var(--sans); margin-bottom: 8px; }
  .pdf-table th, .pdf-table td { padding: 6px 8px; border-bottom: 1px solid #e5dac2; text-align: left; vertical-align: top; }
  .pdf-table th { font: 700 12px/1.2 var(--sans); text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); }
  .pdf-row--warn td { color: var(--rust); font-weight: 600; }

  /* Graphical floor plan (Buehne + Tische), mirrors the live Sitzplan tab
     but simplified for paper: no hover tooltips, names printed inline. */
  .pdf-floor { display: flex; gap: 10px; align-items: stretch; margin-bottom: 24px; }
  .pdf-floor__stage {
    flex: 0 0 30px;
    display: flex; align-items: center; justify-content: center;
    background: repeating-linear-gradient(45deg, #cbb894, #cbb894 5px, #ddceb2 5px, #ddceb2 10px);
    border: 1.5px solid #b89a68; border-radius: 4px;
    writing-mode: vertical-rl; text-orientation: mixed;
    font: 700 11px/1 var(--sans); color: var(--ink-soft); text-transform: uppercase; letter-spacing: .06em;
  }
  .pdf-floor__grid { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .pdf-floor__row { display: flex; gap: 6px; }
  .pdf-tile {
    flex: 1; min-width: 0;
    border: 1px solid #c9b98f; border-radius: 4px;
    background: #fff; padding: 3px 4px;
    font: 400 8px/1.25 var(--sans);
  }
  .pdf-tile--occupied { background: #f3e6d3; }
  .pdf-tile--merged { background: #ece2cd; color: #8a7a5f; font-style: italic; }
  .pdf-tile__code { font: 700 9.5px/1 var(--sans); color: var(--ink); }
  .pdf-tile__count { font: 700 8px/1 var(--sans); color: var(--rust); margin: 2px 0; }
  .pdf-tile__names { color: var(--ink-soft); word-break: break-word; }

  /* A3 mode: same markup, roomier sizing so names stay legible. Toggled
     on <body> by the "A3 Querformat" button before printing. */
  body.pdf-a3 .pdf-doc { max-width: 1400px; }
  body.pdf-a3 .pdf-floor__stage { flex-basis: 40px; font-size: 13px; }
  body.pdf-a3 .pdf-tile { padding: 6px 8px; font-size: 10.5px; }
  body.pdf-a3 .pdf-tile__code { font-size: 12px; }
  body.pdf-a3 .pdf-tile__count { font-size: 10px; }

  @media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .pdf-doc { padding: 0; max-width: none; }
    .pdf-table { page-break-inside: auto; }
    .pdf-table tr { page-break-inside: avoid; }
    .pdf-floor { page-break-inside: avoid; }
    .pdf-floor__row { page-break-inside: avoid; }
    h2 { page-break-after: avoid; }
  }
</style>
</head>
<body>

<div class="no-print">
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
    <a href="new.php">Neue Reservierung</a>
    <a href="export.php" class="is-active">PDF-Export</a>
  </nav>
  <div class="pdf-toolbar">
    <button type="button" onclick="printAs('a4')">Als A4 (Hochformat) drucken</button>
    <button type="button" onclick="printAs('a3')">Als A3 (Querformat) drucken</button>
    <a href="seating.php">&larr; Zur&uuml;ck zum Sitzplan</a>
  </div>
</div>

<style id="pdf-page-style">
  @page { size: A4 portrait; margin: 16mm; }
</style>
<script>
function printAs(size) {
  var pageStyle = document.getElementById('pdf-page-style');
  if (size === 'a3') {
    pageStyle.textContent = '@page { size: A3 landscape; margin: 16mm; }';
    document.body.classList.add('pdf-a3');
  } else {
    pageStyle.textContent = '@page { size: A4 portrait; margin: 16mm; }';
    document.body.classList.remove('pdf-a3');
  }
  window.setTimeout(function () { window.print(); }, 50);
}
</script>

<div class="pdf-doc">

  <div class="pdf-header">
    <img src="../assets/logo_spitalbier.svg" alt="Straubinger Spitalbier">
    <div>
      <h1>Sitzplan &amp; Tischzuweisungen</h1>
      <div class="pdf-header__sub">Spitalbierfest &ndash; B&uuml;rgerspitalstiftung Straubing &middot; Stand: <?= e((new DateTime('now', $tz))->format('d.m.Y H:i')) ?> Uhr</div>
    </div>
  </div>

  <h2>Sitzplan</h2>
  <div class="pdf-floor">
    <div class="pdf-floor__stage">B&uuml;hne</div>
    <div class="pdf-floor__grid">
      <?php foreach (['A', 'B', 'C', 'D'] as $label): ?>
      <div class="pdf-floor__row">
        <?php foreach ($rowsByLabel[$label] ?? [] as $t): $tid = (int) $t['id'];
          if ($t['merged_into'] !== null): $primaryCode = $tablesById[(int) $t['merged_into']]['code'] ?? '?'; ?>
        <div class="pdf-tile pdf-tile--merged">
          <div class="pdf-tile__code"><?= e($t['code']) ?></div>
          <div class="pdf-tile__names">&rarr; <?= e($primaryCode) ?></div>
        </div>
          <?php continue; endif;
            $seats = (int) $t['seats'];
            $occupied = $occupiedByTable[$tid] ?? 0;
            $labels = $labelsByTable[$tid] ?? [];
          ?>
        <div class="pdf-tile<?= $occupied > 0 ? ' pdf-tile--occupied' : '' ?>">
          <div class="pdf-tile__code"><?= e($t['code']) ?></div>
          <div class="pdf-tile__count"><?= $occupied ?>/<?= $seats ?></div>
          <?php if ($labels): ?><div class="pdf-tile__names"><?= e(implode(', ', $labels)) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <h2>Tischbelegung im Detail</h2>
  <?php foreach (['A', 'B', 'C', 'D'] as $label): ?>
  <h3>Reihe <?= e($label) ?></h3>
  <table class="pdf-table">
    <thead>
      <tr><th>Tisch</th><th>Belegung</th><th>Zugewiesen</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rowsByLabel[$label] ?? [] as $t): $tid = (int) $t['id']; ?>
        <?php if ($t['merged_into'] !== null): $primaryCode = $tablesById[(int) $t['merged_into']]['code'] ?? '?'; ?>
        <tr>
          <td><?= e($t['code']) ?></td>
          <td colspan="2">zusammengelegt mit Tisch <?= e($primaryCode) ?></td>
        </tr>
        <?php continue; endif;
          $seats = (int) $t['seats'];
          $occupied = $occupiedByTable[$tid] ?? 0;
          $labels = $labelsByTable[$tid] ?? [];
        ?>
        <tr>
          <td><?= e($t['code']) ?></td>
          <td><?= $occupied ?>/<?= $seats ?></td>
          <td><?= $labels ? e(implode(', ', $labels)) : '&ndash;' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <h2>Reservierungen &ndash; Tischzuweisung</h2>
  <table class="pdf-table">
    <thead>
      <tr><th>Name</th><th>Code</th><th>Personen</th><th>Tisch(e)</th></tr>
    </thead>
    <tbody>
      <?php if (!$reservations): ?>
      <tr><td colspan="4">Noch keine Reservierungen.</td></tr>
      <?php endif; ?>
      <?php foreach ($reservations as $r):
        $assigned = $assignedByReservation[(int) $r['id']] ?? [];
        $assignedSeats = array_sum(array_column($assigned, 'seats'));
        $tableLabels = array_map(static fn($a) => $a['code'] . ' (' . (int) $a['seats'] . ')', $assigned);
        $incomplete = $assignedSeats < (int) $r['guests'];
      ?>
      <tr class="<?= $incomplete ? 'pdf-row--warn' : '' ?>">
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['code']) ?></td>
        <td><?= (int) $r['guests'] ?></td>
        <td>
          <?= $tableLabels ? e(implode(', ', $tableLabels)) : 'nicht zugewiesen' ?>
          <?php if ($incomplete && $tableLabels): ?> (unvollst&auml;ndig)<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</div>

</body>
</html>
