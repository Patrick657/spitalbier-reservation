<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$pdo = sbf_pdo();

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$tables = $pdo->query('SELECT id, code, row_label, position, seats, base_seats, merged_into FROM venue_tables ORDER BY row_label, position')->fetchAll();

$tablesById = [];
foreach ($tables as $t) {
    $tablesById[(int) $t['id']] = $t;
}

// primaryId => list of tables merged into it (for "undo merge" + display)
$mergedChildrenByPrimary = [];
foreach ($tables as $t) {
    if ($t['merged_into'] !== null) {
        $mergedChildrenByPrimary[(int) $t['merged_into']][] = $t;
    }
}

$occupiedByTable = [];
foreach ($pdo->query('SELECT table_id, SUM(seats) AS occupied FROM table_assignments GROUP BY table_id')->fetchAll() as $row) {
    $occupiedByTable[(int) $row['table_id']] = (int) $row['occupied'];
}

// Who's sitting at each table, for the hover tooltip on the floor plan.
$tooltipByTable = [];
foreach ($pdo->query(
    "SELECT ta.table_id, ta.seats, ta.note, ta.reservation_id, r.name
     FROM table_assignments ta
     LEFT JOIN reservations r ON r.id = ta.reservation_id
     ORDER BY ta.created_at ASC"
)->fetchAll() as $row) {
    $label = $row['reservation_id'] ? $row['name'] : (($row['note'] ?: 'Blockiert') . ' (manuell)');
    $tooltipByTable[(int) $row['table_id']][] = $label . ' – ' . (int) $row['seats'] . ' Pl.';
}

$rowsByLabel = [];
foreach ($tables as $t) {
    $rowsByLabel[$t['row_label']][] = $t;
}

// Open (not yet fully assigned) vs. assigned reservations, for the two
// summary tables below the floor plan.
$assignmentsByReservation = [];
foreach ($pdo->query(
    "SELECT ta.reservation_id, vt.code, ta.seats
     FROM table_assignments ta
     JOIN venue_tables vt ON vt.id = ta.table_id
     WHERE ta.reservation_id IS NOT NULL
     ORDER BY vt.row_label, vt.position"
)->fetchAll() as $a) {
    $assignmentsByReservation[(int) $a['reservation_id']][] = ['code' => $a['code'], 'seats' => (int) $a['seats']];
}

$openReservations = [];
$assignedReservations = [];
foreach ($pdo->query(
    'SELECT id, code, name, guests, admin_note FROM reservations WHERE cancelled_at IS NULL ORDER BY created_at ASC'
)->fetchAll() as $r) {
    $tablesForRes = $assignmentsByReservation[(int) $r['id']] ?? [];
    $assignedSeats = array_sum(array_column($tablesForRes, 'seats'));
    if ($tablesForRes && $assignedSeats >= (int) $r['guests']) {
        $assignedReservations[] = ['name' => $r['name'], 'code' => $r['code'], 'tables' => $tablesForRes];
    } else {
        $openReservations[] = [
            'name' => $r['name'],
            'code' => $r['code'],
            'guests' => (int) $r['guests'],
            'assignedSeats' => $assignedSeats,
            'adminNote' => $r['admin_note'],
        ];
    }
}

$selectedId = isset($_GET['table']) ? (int) $_GET['table'] : 0;
$selected = null;
$assignments = [];
$unassignedReservations = [];
$remainingTable = 0;

if ($selectedId) {
    foreach ($tables as $t) {
        if ((int) $t['id'] === $selectedId) {
            $selected = $t;
            break;
        }
    }
}

if ($selected) {
    $stmt = $pdo->prepare(
        'SELECT ta.id, ta.seats, ta.note, ta.reservation_id, r.name, r.code
         FROM table_assignments ta
         LEFT JOIN reservations r ON r.id = ta.reservation_id
         WHERE ta.table_id = :id
         ORDER BY ta.created_at ASC'
    );
    $stmt->execute(['id' => $selected['id']]);
    $assignments = $stmt->fetchAll();

    $occupied = $occupiedByTable[(int) $selected['id']] ?? 0;
    $remainingTable = max(0, (int) $selected['seats'] - $occupied);

    $mergedChildren = $mergedChildrenByPrimary[(int) $selected['id']] ?? [];

    $neighborCandidates = [];
    foreach ($rowsByLabel[$selected['row_label']] ?? [] as $t) {
        $isAdjacent = abs((int) $t['position'] - (int) $selected['position']) === 1;
        $isStandalone = $t['merged_into'] === null && empty($mergedChildrenByPrimary[(int) $t['id']]);
        $isEmpty = ($occupiedByTable[(int) $t['id']] ?? 0) === 0;
        if ($isAdjacent && $isStandalone && $isEmpty) {
            $neighborCandidates[] = $t;
        }
    }

    $unassignedReservations = $pdo->query(
        "SELECT r.id, r.code, r.name, r.guests, COALESCE(SUM(ta.seats), 0) AS assigned
         FROM reservations r
         LEFT JOIN table_assignments ta ON ta.reservation_id = r.id
         WHERE r.cancelled_at IS NULL
         GROUP BY r.id, r.code, r.name, r.guests
         HAVING assigned < r.guests
         ORDER BY r.created_at ASC"
    )->fetchAll();
}

$flashes = [
    'assigned' => ['type' => 'ok', 'text' => 'Zuweisung gespeichert.'],
    'unassigned' => ['type' => 'ok', 'text' => 'Zuweisung entfernt.'],
    'full' => ['type' => 'error', 'text' => 'An diesem Tisch sind nicht mehr genug freie Plätze.'],
    'invalid' => ['type' => 'error', 'text' => 'Bitte Angaben prüfen.'],
    'notfound' => ['type' => 'error', 'text' => 'Nicht gefunden.'],
    'badtoken' => ['type' => 'error', 'text' => 'Ungültige Anfrage. Bitte erneut versuchen.'],
    'seats_updated' => ['type' => 'ok', 'text' => 'Platzzahl aktualisiert.'],
    'merged' => ['type' => 'ok', 'text' => 'Tische zusammengelegt.'],
    'unmerged' => ['type' => 'ok', 'text' => 'Zusammenlegung aufgehoben.'],
    'not_empty' => ['type' => 'error', 'text' => 'Dazu müssen beide Tische leer sein (keine Zuweisungen).'],
    'below_occupied' => ['type' => 'error', 'text' => 'Die Platzzahl kann nicht unter die bereits belegten Plätze gesenkt werden.'],
];
$flash = isset($_GET['msg']) ? ($flashes[$_GET['msg']] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sitzplan &ndash; Spitalbierfest Dashboard</title>
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
  <a href="seating.php" class="is-active">Sitzplan</a>
  <a href="new.php">Neue Reservierung</a>
  <a href="export.php">PDF-Export</a>
</nav>

<main class="dash-main">

  <?php if ($flash): ?>
  <div class="dash-flash dash-flash--<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <div class="dash-legend">
    <span class="legend-item"><span class="chair"></span> frei</span>
    <span class="legend-item"><span class="chair is-occupied"></span> besetzt</span>
  </div>

  <div class="seating-layout">
    <div class="seating-grid-wrap">
      <div class="seating-floor">
      <div class="stage"><span>B&uuml;hne</span></div>
      <div class="seating-grid">
        <?php foreach (['A', 'B', 'C', 'D'] as $label): ?>
        <div class="seating-row">
          <?php foreach ($rowsByLabel[$label] ?? [] as $t):
            if ($t['merged_into'] !== null):
              $primaryCode = $tablesById[(int) $t['merged_into']]['code'] ?? '?';
          ?>
          <a class="table-tile table-tile--merged" href="?table=<?= (int) $t['merged_into'] ?>" title="Zusammengelegt mit <?= e($primaryCode) ?>">
            <div class="table-tile__code"><?= e($t['code']) ?></div>
            <div class="table-tile__merged-note">&rarr; <?= e($primaryCode) ?></div>
          </a>
          <?php
              continue;
            endif;
            $occupied = $occupiedByTable[(int) $t['id']] ?? 0;
            $seats = (int) $t['seats'];
            $halfSeats = (int) ceil($seats / 2);
            $isSelected = $selected && (int) $selected['id'] === (int) $t['id'];
            $tooltipLines = $tooltipByTable[(int) $t['id']] ?? [];
            $tooltip = $tooltipLines ? implode("\n", $tooltipLines) : 'Frei';
          ?>
          <a class="table-tile<?= $isSelected ? ' is-selected' : '' ?><?= $seats > 6 ? ' table-tile--wide' : '' ?>" href="?table=<?= (int) $t['id'] ?>" title="<?= e($tooltip) ?>">
            <div class="table-tile__code"><?= e($t['code']) ?></div>
            <div class="table-tile__shape">
              <div class="table-tile__chairs">
                <?php for ($i = 0; $i < $halfSeats; $i++): ?>
                <span class="chair<?= $i < $occupied ? ' is-occupied' : '' ?>"></span>
                <?php endfor; ?>
              </div>
              <div class="table-tile__rect"></div>
              <div class="table-tile__chairs">
                <?php for ($i = $halfSeats; $i < $seats; $i++): ?>
                <span class="chair<?= $i < $occupied ? ' is-occupied' : '' ?>"></span>
                <?php endfor; ?>
              </div>
            </div>
            <div class="table-tile__count"><?= $occupied ?>/<?= $seats ?></div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      </div>
    </div>

    <div>
      <h2 class="dash-two-col__heading">Tischreservierung</h2>
      <div class="assign-panel">
        <?php if (!$selected): ?>
          <p class="assign-panel__hint">Tisch anklicken, um Pl&auml;tze zuzuweisen oder zu blockieren.</p>
        <?php else: ?>
          <h3>Tisch <?= e($selected['code']) ?></h3>
          <p class="assign-panel__sub"><?= $occupiedByTable[(int) $selected['id']] ?? 0 ?> von <?= (int) $selected['seats'] ?> Pl&auml;tzen belegt</p>

          <?php if ($assignments): ?>
          <ul class="assign-list">
            <?php foreach ($assignments as $a): ?>
            <li>
              <span class="assign-list__label">
                <?php if ($a['reservation_id']): ?>
                  <?= e($a['name']) ?> <span class="assign-list__code"><?= e($a['code']) ?></span>
                <?php else: ?>
                  <?= e($a['note'] ?? 'Blockiert') ?> <span class="assign-list__code">manuell</span>
                <?php endif; ?>
              </span>
              <span class="assign-list__seats"><?= (int) $a['seats'] ?> Pl.</span>
              <form method="post" action="unassign.php">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="assign-list__remove" title="Entfernen">&times;</button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <p class="assign-panel__hint">Noch keine Zuweisung an diesem Tisch.</p>
          <?php endif; ?>

          <?php if ($remainingTable > 0): ?>
          <form method="post" action="assign.php" class="assign-form">
            <input type="hidden" name="mode" value="reservation">
            <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Reservierung zuweisen
              <select name="reservation_id" required>
                <option value="">&mdash; ausw&auml;hlen &mdash;</option>
                <?php foreach ($unassignedReservations as $ur): $remaining = (int) $ur['guests'] - (int) $ur['assigned']; ?>
                <option value="<?= (int) $ur['id'] ?>"><?= e($ur['name']) ?> (<?= e($ur['code']) ?>) &ndash; <?= $remaining ?> von <?= (int) $ur['guests'] ?> offen</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Pl&auml;tze
              <input type="number" name="seats" min="1" max="<?= $remainingTable ?>" value="1" required>
            </label>
            <button type="submit" class="dash-btn-cancel">Zuweisen</button>
          </form>

          <form method="post" action="assign.php" class="assign-form">
            <input type="hidden" name="mode" value="manual">
            <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Manuell blockieren
              <input type="text" name="note" placeholder="z. B. reserviert f&uuml;r Vorstand" maxlength="190" required>
            </label>
            <label>Pl&auml;tze
              <input type="number" name="seats" min="1" max="<?= $remainingTable ?>" value="<?= $remainingTable ?>" required>
            </label>
            <button type="submit" class="dash-btn-cancel">Blockieren</button>
          </form>
          <?php else: ?>
          <p class="assign-panel__hint">Tisch ist voll belegt.</p>
          <?php endif; ?>

          <a class="assign-panel__close" href="seating.php">Tisch schlie&szlig;en</a>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <h2 class="dash-two-col__heading">Tischverwaltung</h2>
      <div class="assign-panel">
        <?php if (!$selected): ?>
          <p class="assign-panel__hint">Tisch anklicken, um Pl&auml;tze anzupassen oder zusammenzulegen.</p>
        <?php else: ?>
          <h3>Tisch <?= e($selected['code']) ?></h3>
          <p class="assign-panel__sub"><?= $occupiedByTable[(int) $selected['id']] ?? 0 ?> von <?= (int) $selected['seats'] ?> Pl&auml;tzen belegt</p>

          <form method="post" action="table_seats.php" class="assign-form">
            <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Pl&auml;tze anpassen (z.&nbsp;B. +2 St&uuml;hle an den Stirnseiten)
              <input type="number" name="seats" min="<?= $occupied ?>" max="30" value="<?= (int) $selected['seats'] ?>" required>
            </label>
            <button type="submit" class="dash-btn-edit">Speichern</button>
          </form>

          <?php if ($mergedChildren): ?>
          <div class="table-manage__note">
            Zusammengelegt mit <?= e(implode(', ', array_map(fn($c) => $c['code'], $mergedChildren))) ?> (<?= (int) $selected['base_seats'] ?> + <?= array_sum(array_map(fn($c) => (int) $c['base_seats'], $mergedChildren)) ?> Grundpl&auml;tze).
          </div>
          <?php if ($occupied === 0): ?>
          <form method="post" action="table_unmerge.php" class="assign-form">
            <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="dash-btn-edit">Zusammenlegung aufheben</button>
          </form>
          <?php else: ?>
          <p class="assign-panel__hint">Zum Aufheben muss der Tisch erst leer sein.</p>
          <?php endif; ?>
          <?php elseif ($occupied === 0 && $neighborCandidates): ?>
          <form method="post" action="table_merge.php" class="assign-form">
            <input type="hidden" name="table_id" value="<?= (int) $selected['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Mit Nachbartisch zusammenlegen
              <select name="neighbor_id" required>
                <option value="">&mdash; ausw&auml;hlen &mdash;</option>
                <?php foreach ($neighborCandidates as $n): ?>
                <option value="<?= (int) $n['id'] ?>"><?= e($n['code']) ?> (+<?= (int) $n['base_seats'] ?> Pl&auml;tze)</option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="dash-btn-edit">Zusammenlegen</button>
          </form>
          <?php elseif ($occupied === 0): ?>
          <p class="assign-panel__hint">Kein freier Nachbartisch zum Zusammenlegen verf&uuml;gbar.</p>
          <?php endif; ?>

          <a class="assign-panel__close" href="seating.php">Tisch schlie&szlig;en</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="dash-two-col">
    <div>
      <h2 class="dash-two-col__heading">Offene Reservierungen</h2>
      <div class="dash-table-wrap">
        <table class="dash-table">
          <thead>
            <tr><th>Code</th><th>Name</th><th>Personen</th><th>Zugewiesen</th></tr>
          </thead>
          <tbody>
            <?php if (!$openReservations): ?>
            <tr><td colspan="4" class="dash-table__empty">Alle Reservierungen sind zugewiesen.</td></tr>
            <?php endif; ?>
            <?php foreach ($openReservations as $r): ?>
            <tr>
              <td><?= e($r['code']) ?></td>
              <td>
                <?= e($r['name']) ?>
                <?php if (!empty($r['adminNote'])): ?><br><span class="dash-note">Notiz: <?= e($r['adminNote']) ?></span><?php endif; ?>
              </td>
              <td><?= (int) $r['guests'] ?></td>
              <td><?= $r['assignedSeats'] ?> von <?= (int) $r['guests'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div>
      <h2 class="dash-two-col__heading">Zugewiesene Reservierungen</h2>
      <div class="dash-table-wrap">
        <table class="dash-table">
          <thead>
            <tr><th>Code</th><th>Name</th><th>Tisch</th></tr>
          </thead>
          <tbody>
            <?php if (!$assignedReservations): ?>
            <tr><td colspan="3" class="dash-table__empty">Noch keine Tischzuweisungen.</td></tr>
            <?php endif; ?>
            <?php foreach ($assignedReservations as $r): ?>
            <tr>
              <td><?= e($r['code']) ?></td>
              <td><?= e($r['name']) ?></td>
              <td><?= e(implode(', ', array_map(fn($t) => $t['code'] . ' (' . $t['seats'] . ')', $r['tables']))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
