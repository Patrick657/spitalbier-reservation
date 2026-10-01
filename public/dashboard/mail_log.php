<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$tz = new DateTimeZone('Europe/Berlin');
$pdo = sbf_pdo();

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$kinds = [
    'confirmation' => 'Best&auml;tigung',
    'update' => '&Auml;nderung',
    'admin_notice' => 'Info an Verwaltung',
    'table_confirm' => 'Tischbest&auml;tigung',
    'table_test' => 'Tischbest&auml;tigung (Test)',
];

// The table only exists once the mail-log migration has been run.
$rows = [];
$missingTable = false;
try {
    $rows = $pdo->query(
        'SELECT sent_at, recipient_email, recipient_name, subject, kind, reservation_code, status, error
         FROM mail_log ORDER BY sent_at DESC, id DESC'
    )->fetchAll();

    // Show guests the way every other list does ("Nachname, Vorname"). Looked
    // up here rather than joined, so differing table collations can't break it.
    $nameByCode = $pdo->query('SELECT code, name FROM reservations')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as &$row) {
        if ($row['kind'] !== 'admin_notice' && isset($nameByCode[$row['reservation_code']])) {
            $row['recipient_name'] = $nameByCode[$row['reservation_code']];
        }
    }
    unset($row);
} catch (PDOException $ex) {
    $missingTable = true;
}

$sentCount = 0;
$failedCount = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'sent') {
        $sentCount++;
    } else {
        $failedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>E-Mail-Log &ndash; Spitalbierfest Dashboard</title>
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
  <a href="new.php">Neue Reservierung</a>
  <a href="export.php">PDF-Export</a>
  <a href="table_mail.php">Tischbest&auml;tigung</a>
  <a href="mail_log.php" class="is-active">E-Mail-Log</a>
</nav>

<main class="dash-main">

  <?php if ($missingTable): ?>
  <div class="dash-flash dash-flash--error">Die Tabelle f&uuml;r das E-Mail-Log fehlt noch. Bitte migrations/migration-2026-10-01-mail-log.sql in der Datenbank ausf&uuml;hren.</div>
  <?php else: ?>
  <p class="dash-note"><?= $sentCount ?> versendet, <?= $failedCount ?> fehlgeschlagen. E-Mails vor Einf&uuml;hrung des Logs sind hier nicht enthalten.</p>
  <?php endif; ?>

  <div class="dash-table-wrap">
    <table class="dash-table">
      <thead>
        <tr><th data-sorted="desc">Zeitpunkt</th><th>Empf&auml;nger</th><th>E-Mail</th><th>Art</th><th>Code</th><th>Betreff</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" class="dash-table__empty">Noch keine E-Mails protokolliert.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): $sent = $r['status'] === 'sent'; ?>
        <tr>
          <td data-sort="<?= e($r['sent_at']) ?>"><?= e((new DateTime($r['sent_at'], $tz))->format('d.m.Y H:i')) ?></td>
          <td><?= e($r['recipient_name'] ?? '–') ?></td>
          <td><a href="mailto:<?= e($r['recipient_email']) ?>"><?= e($r['recipient_email']) ?></a></td>
          <td><?= $kinds[$r['kind']] ?? e($r['kind']) ?></td>
          <td><?= e($r['reservation_code'] ?? '–') ?></td>
          <td><?= e($r['subject']) ?></td>
          <td>
            <?php if ($sent): ?>
              <span class="dash-badge dash-badge--active">versendet</span>
            <?php else: ?>
              <span class="dash-badge dash-badge--cancelled" title="<?= e($r['error'] ?? '') ?>">fehlgeschlagen</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<script src="sort.js"></script>
</body>
</html>
