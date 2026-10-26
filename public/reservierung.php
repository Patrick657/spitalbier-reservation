<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/names.php';
require_once __DIR__ . '/../src/MailLog.php';
require_once __DIR__ . '/../src/Reservation.php';

// The guest's personal page behind the links in the Tischbestätigung:
//   GET  reservierung.php?t=<token>&a=bestaetigen|stornieren  shows the reservation and a button
//   POST action=confirm|cancel                                 changes it, then redirects back (?done=…)
// Opening the link never changes anything: mail scanners open links on
// their own, so only the button press counts.

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the token is in the URL
header('Cache-Control: no-store');

$cfg = sbf_config();
$tz = new DateTimeZone('Europe/Berlin');

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function sbf_manage_redirect(string $token, string $done): void
{
    header('Location: reservierung.php?t=' . rawurlencode($token) . '&done=' . $done, true, 303);
    exit;
}

$token = trim((string) ($_POST['t'] ?? $_GET['t'] ?? ''));
$isTest = $token === SBF_TEST_TOKEN;
$closed = sbf_deadline_passed($cfg);
$reservation = null;
$tables = [];
$error = false;

if (sbf_valid_token_format($token)) {
    if ($isTest) {
        $reservation = [
            'id' => 0, 'code' => 'SBF-0000', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'company' => '',
            'email' => '', 'guests' => 4, 'cancelled_at' => null, 'confirmed_at' => null,
        ];
        $tables = ['A-01'];
    } else {
        try {
            $pdo = sbf_pdo();
            $stmt = $pdo->prepare(
                'SELECT id, code, name, first_name, last_name, company, email, guests, cancelled_at, confirmed_at
                 FROM reservations WHERE manage_token = :t'
            );
            $stmt->execute(['t' => $token]);
            $reservation = $stmt->fetch() ?: null;
            if ($reservation) {
                $stmt = $pdo->prepare(
                    'SELECT DISTINCT vt.code, vt.row_label, vt.position FROM table_assignments ta
                     JOIN venue_tables vt ON vt.id = ta.table_id
                     WHERE ta.reservation_id = :id ORDER BY vt.row_label, vt.position'
                );
                $stmt->execute(['id' => (int) $reservation['id']]);
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Throwable $ex) {
            error_log('[spitalbierfest] manage page error: ' . $ex->getMessage());
            $error = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reservation && !$error) {
    $action = (string) ($_POST['action'] ?? '');
    if ($isTest) {
        sbf_manage_redirect($token, 'test');
    }
    if ($closed) {
        sbf_manage_redirect($token, 'closed');
    }
    $id = (int) $reservation['id'];

    try {
        if ($action === 'confirm') {
            if ($reservation['cancelled_at'] !== null) {
                sbf_manage_redirect($token, 'already_cancelled');
            }
            $pdo->prepare('UPDATE reservations SET confirmed_at = NOW() WHERE id = :id AND cancelled_at IS NULL AND confirmed_at IS NULL')
                ->execute(['id' => $id]);
            sbf_manage_redirect($token, 'confirmed');
        }

        if ($action === 'cancel') {
            $result = sbf_cancel_reservation($pdo, $id, 'guest');
            if ($result !== 'cancelled') {
                sbf_manage_redirect($token, 'already_cancelled');
            }

            // Let the organisers know the tables are free again (best-effort).
            try {
                $mailer = $cfg['smtp']['host'] !== '' && $cfg['admin_email'] !== '' ? new SmtpMailer(
                    $cfg['smtp']['host'], $cfg['smtp']['port'], $cfg['smtp']['secure'], $cfg['smtp']['user'],
                    $cfg['smtp']['pass'], $cfg['smtp']['from_email'], $cfg['smtp']['from_name']
                ) : null;
                if ($mailer) {
                    $text = "Ein Gast hat seine Reservierung über den Link in der Tischbestätigung storniert.\n\n"
                        . 'Reservierung: ' . $reservation['code'] . "\n"
                        . 'Name: ' . $reservation['name'] . "\n"
                        . 'E-Mail: ' . $reservation['email'] . "\n"
                        . 'Personen: ' . (int) $reservation['guests'] . "\n"
                        . 'Freigewordene Tische: ' . ($tables ? implode(', ', $tables) : '–') . "\n";
                    sbf_send_logged(
                        $pdo, $mailer, 'guest_cancel', $reservation['code'], $cfg['admin_email'],
                        'Reservierungen Spitalbierfest', 'Storniert durch Gast: ' . $reservation['code'], $text
                    );
                }
            } catch (Throwable $ex) {
                error_log('[spitalbierfest] guest cancel notice error: ' . $ex->getMessage());
            }
            sbf_manage_redirect($token, 'cancelled');
        }
    } catch (Throwable $ex) {
        error_log('[spitalbierfest] manage action error: ' . $ex->getMessage());
        sbf_manage_redirect($token, 'error');
    }
    sbf_manage_redirect($token, 'error');
}

$done = (string) ($_GET['done'] ?? '');
$messages = [
    'confirmed' => ['ok', 'Vielen Dank! Ihre Tischreservierung ist bestätigt. Wir freuen uns auf Sie.'],
    'cancelled' => ['ok', 'Ihre Tischreservierung wurde storniert. Schade, dass Sie nicht kommen können – vielleicht ein andermal!'],
    'already_cancelled' => ['info', 'Diese Reservierung war bereits storniert.'],
    'closed' => ['info', 'Die Reservierung kann online nicht mehr geändert werden.'],
    'test' => ['info', 'Testlink: Es wurde nichts geändert. Die Gäste erhalten in ihrer E-Mail einen persönlichen Link.'],
    'error' => ['error', 'Das hat leider nicht geklappt. Bitte versuchen Sie es später erneut oder schreiben Sie uns an stiftungsamt@straubing.de.'],
];
$message = $messages[$done] ?? null;

$preselect = (string) ($_GET['a'] ?? '');
$cancelled = $reservation && $reservation['cancelled_at'] !== null;
$confirmed = $reservation && $reservation['confirmed_at'] !== null;
$canAct = $reservation && !$cancelled && !$closed && !$error;
$guestName = $reservation ? (sbf_person_name((string) $reservation['first_name'], (string) $reservation['last_name']) ?: (string) $reservation['company']) : '';
if (!$reservation || $error) {
    http_response_code($error ? 500 : 404);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ihre Tischreservierung &ndash; 1. Straubinger Spitalbierfest</title>
<link rel="stylesheet" href="css/styles.css">
</head>
<body>

<header class="site-header">
  <div class="site-header__brand">
    <span class="site-header__name">B&uuml;rgerspitalstiftung Straubing</span>
  </div>
  <nav class="site-header__nav">
    <a href="index.html">Das Fest</a>
    <a href="impressum.html">Impressum</a>
  </nav>
</header>

<main class="manage">
  <div class="form-card manage__card">
    <div class="form-card__head">
      <h2>Ihre Tischreservierung</h2>
      <p>1. Straubinger Spitalbierfest &middot; Freitag, 30. Oktober 2026, 18:00 Uhr &middot; Rittersaal im Herzogschloss</p>
    </div>

    <?php if ($message): ?>
    <div class="manage__msg manage__msg--<?= e($message[0]) ?>" role="status"><?= e($message[1]) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <p class="manage__text">Die Seite ist gerade nicht erreichbar. Bitte versuchen Sie es sp&auml;ter erneut oder schreiben Sie uns an <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a>.</p>
    <?php elseif (!$reservation): ?>
      <p class="manage__text">Dieser Link ist ung&uuml;ltig. Bitte verwenden Sie den Link aus Ihrer E-Mail vollst&auml;ndig oder schreiben Sie uns an <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a>.</p>
    <?php else: ?>

      <?php if ($isTest && !$message): ?>
      <div class="manage__msg manage__msg--info">Testlink aus einer Testmail &ndash; die Angaben sind Beispieldaten, die Buttons &auml;ndern nichts.</div>
      <?php endif; ?>

      <dl class="manage__facts">
        <dt>Name</dt><dd><?= e($guestName) ?></dd>
        <dt>Reservierung</dt><dd><?= e((string) $reservation['code']) ?></dd>
        <dt>Personen</dt><dd><?= (int) $reservation['guests'] ?></dd>
        <?php if ($tables && !$cancelled): ?>
        <dt>Tisch</dt><dd><?= e(implode(', ', $tables)) ?></dd>
        <?php endif; ?>
        <dt>Status</dt>
        <dd>
          <?php if ($cancelled): ?>storniert
          <?php elseif ($confirmed): ?>best&auml;tigt am <?= e((new DateTime($reservation['confirmed_at'], $tz))->format('d.m.Y')) ?>
          <?php else: ?>reserviert, noch nicht best&auml;tigt
          <?php endif; ?>
        </dd>
      </dl>

      <?php if ($canAct): ?>
        <?php if ($preselect !== 'stornieren' && !$confirmed): ?>
        <form method="post" action="reservierung.php" class="manage__action">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="confirm">
          <button type="submit" class="btn btn--submit">Tischreservierung best&auml;tigen</button>
        </form>
        <?php endif; ?>

        <?php if ($preselect === 'stornieren'): ?>
        <p class="manage__text">M&ouml;chten Sie Ihre Reservierung wirklich stornieren? Ihre Pl&auml;tze werden dann f&uuml;r andere G&auml;ste freigegeben.</p>
        <?php endif; ?>
        <form method="post" action="reservierung.php" class="manage__action">
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn <?= $preselect === 'stornieren' ? 'btn--submit btn--danger' : 'btn--reset manage__cancel' ?>">Tischreservierung stornieren</button>
        </form>

        <?php if ($preselect === 'stornieren' && !$confirmed): ?>
        <p class="form-card__note"><a href="reservierung.php?t=<?= e(rawurlencode($token)) ?>&amp;a=bestaetigen">Doch nicht &ndash; ich m&ouml;chte meine Reservierung best&auml;tigen</a></p>
        <?php endif; ?>
      <?php elseif ($closed && !$cancelled): ?>
        <p class="manage__text">Die Reservierung kann online nicht mehr ge&auml;ndert werden. Bei Fragen: <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a></p>
      <?php elseif ($cancelled): ?>
        <p class="manage__text">Sie m&ouml;chten doch kommen? Schreiben Sie uns an <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a> &ndash; wir schauen, ob noch Pl&auml;tze frei sind.</p>
      <?php endif; ?>

    <?php endif; ?>

    <p class="form-card__note">Fragen? <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a></p>
  </div>
</main>

<footer id="recht" class="site-footer">
  <div class="site-footer__bar">
    <span>&copy; 2026 B&uuml;rgerspitalstiftung Straubing</span>
    <a href="impressum.html">Impressum</a>
    <a href="datenschutz.html">Datenschutz</a>
    <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a>
  </div>
</footer>

<script>
// One press is enough — avoid a double submit on slow connections.
document.querySelectorAll(".manage__action").forEach(function (form) {
  form.addEventListener("submit", function () {
    form.querySelector("button").disabled = true;
  });
});
</script>
</body>
</html>
