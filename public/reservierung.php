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
$closed = sbf_deadline_passed($cfg, 'guest_deadline');
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
$cancelled = $reservation && $reservation['cancelled_at'] !== null;
$confirmed = $reservation && $reservation['confirmed_at'] !== null;
$guestName = $reservation ? (sbf_person_name((string) $reservation['first_name'], (string) $reservation['last_name']) ?: (string) $reservation['company']) : '';

// Exactly one of these views is shown.
if ($error) {
    $view = 'error';
} elseif (!$reservation) {
    $view = 'invalid';
} elseif ($cancelled) {
    $view = 'cancelled';
} elseif ($closed) {
    $view = 'closed';
} elseif (($_GET['a'] ?? '') === 'stornieren') {
    $view = 'cancel';
} elseif ($confirmed) {
    $view = 'confirmed';
} else {
    $view = 'confirm';
}

// Short notice above the card, for the outcome of a button press.
$notices = [
    'test' => ['info', 'Testlink: Es wurde nichts geändert. Die Gäste erhalten in ihrer E-Mail einen persönlichen Link.'],
    'error' => ['error', 'Das hat leider nicht geklappt. Bitte versuchen Sie es noch einmal oder schreiben Sie uns an stiftungsamt@straubing.de.'],
];
$notice = $notices[$done] ?? null;
if ($isTest && !$notice) {
    $notice = ['info', 'Testlink aus einer Testmail – die Angaben sind Beispieldaten, die Buttons ändern nichts.'];
}

$link = static fn(string $a): string => 'reservierung.php?t=' . rawurlencode($token) . '&a=' . $a;

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
<body class="guest-page">

<header class="site-header">
  <div class="site-header__brand">
    <span class="site-header__name">B&uuml;rgerspitalstiftung Straubing</span>
  </div>
  <nav class="site-header__nav">
    <a href="index.html">Das Fest</a>
    <a href="impressum.html">Impressum</a>
  </nav>
</header>

<main class="guest">
  <section class="guest__hero">
    <img class="guest__hero-img" src="assets/wirtsstube.png" alt="" aria-hidden="true">
    <div class="guest__hero-text">
      <img class="guest__logo" src="assets/logo_spitalbier.svg" alt="Straubinger Spitalbier">
      <div class="guest__kicker">1616 &ndash; 2026</div>
      <h1>1. Straubinger Spitalbierfest</h1>
      <p>Freitag, 30. Oktober 2026 &middot; 18:00 Uhr &middot; Rittersaal im Herzogschloss</p>
    </div>
  </section>

  <div class="guest__card">

    <?php if ($notice): ?>
    <div class="guest__notice guest__notice--<?= e($notice[0]) ?>" role="status"><?= e($notice[1]) ?></div>
    <?php endif; ?>

    <?php if ($view === 'error' || $view === 'invalid'): ?>

      <h2 class="guest__title">Hoppla &ndash; das hat nicht geklappt</h2>
      <p class="guest__lead">
        <?php if ($view === 'error'): ?>
          Die Seite ist gerade nicht erreichbar. Bitte versuchen Sie es sp&auml;ter noch einmal.
        <?php else: ?>
          Dieser Link ist ung&uuml;ltig oder unvollst&auml;ndig. Bitte &ouml;ffnen Sie den Link direkt aus Ihrer E-Mail.
        <?php endif; ?>
        Oder schreiben Sie uns an <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a>.
      </p>

    <?php else: ?>

      <?php if ($view === 'confirmed'): ?>
        <div class="guest__badge guest__badge--ok" aria-hidden="true">&#10003;</div>
        <h2 class="guest__title"><?= $done === 'confirmed' ? 'Vergelt&rsquo;s Gott' : 'Alles bestätigt' ?>, <?= e($guestName) ?>!</h2>
        <p class="guest__lead">Ihre Tischreservierung ist best&auml;tigt. Wir freuen uns auf Sie und halten Ihren Tisch bis 18:30&nbsp;Uhr frei.</p>
      <?php elseif ($view === 'cancel'): ?>
        <h2 class="guest__title">Reservierung stornieren?</h2>
        <p class="guest__lead">Schade, dass Sie nicht kommen k&ouml;nnen. Wenn Sie stornieren, geben wir Ihre Pl&auml;tze f&uuml;r andere G&auml;ste frei.</p>
      <?php elseif ($view === 'cancelled'): ?>
        <div class="guest__badge guest__badge--muted" aria-hidden="true">&times;</div>
        <h2 class="guest__title"><?= $done === 'cancelled' ? 'Ihre Reservierung ist storniert' : 'Diese Reservierung ist storniert' ?></h2>
        <p class="guest__lead">
          <?= $done === 'cancelled' ? 'Danke, dass Sie Bescheid gegeben haben – so können sich andere Gäste über die Plätze freuen.' : '' ?>
          Sie m&ouml;chten doch kommen? Schreiben Sie uns an <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a> &ndash; wir schauen, ob noch Pl&auml;tze frei sind.
        </p>
      <?php elseif ($view === 'closed'): ?>
        <h2 class="guest__title">Gr&uuml;&szlig; Gott, <?= e($guestName) ?>!</h2>
        <p class="guest__lead">Die Reservierung kann online nicht mehr ge&auml;ndert werden. Bei Fragen erreichen Sie uns unter <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a>.</p>
      <?php else: ?>
        <h2 class="guest__title">Gr&uuml;&szlig; Gott, <?= e($guestName) ?>!</h2>
        <p class="guest__lead">Ihr Tisch ist reserviert. Bitte best&auml;tigen Sie kurz, dass Sie kommen &ndash; dann wissen wir, dass wir mit Ihnen rechnen d&uuml;rfen.</p>
      <?php endif; ?>

      <div class="ticket<?= $cancelled ? ' ticket--void' : '' ?>">
        <div class="ticket__main">
          <div class="ticket__item ticket__item--big">
            <span class="ticket__label">Ihr Tisch</span>
            <span class="ticket__value"><?= $tables && !$cancelled ? e(implode(', ', $tables)) : '&ndash;' ?></span>
          </div>
          <div class="ticket__item ticket__item--big">
            <span class="ticket__label">Personen</span>
            <span class="ticket__value"><?= (int) $reservation['guests'] ?></span>
          </div>
          <div class="ticket__item">
            <span class="ticket__label">Wann</span>
            <span class="ticket__text">Fr., 30.10.2026, 18:00&nbsp;Uhr</span>
          </div>
          <div class="ticket__item">
            <span class="ticket__label">Wo</span>
            <span class="ticket__text">Rittersaal im Herzogschloss</span>
          </div>
        </div>
        <div class="ticket__stub<?= $confirmed && !$cancelled ? ' ticket__stub--ok' : '' ?>">
          <span>Reservierung <strong><?= e((string) $reservation['code']) ?></strong></span>
          <span class="ticket__status ticket__status--<?= $cancelled ? 'void' : ($confirmed ? 'ok' : 'open') ?>">
            <?= $cancelled ? 'storniert' : ($confirmed ? '&#10003; bestätigt' : 'noch nicht bestätigt') ?>
          </span>
        </div>
      </div>

      <?php if (in_array($view, ['confirm', 'confirmed', 'cancel'], true)): ?>
        <div class="guest__actions">
          <form method="post" action="reservierung.php" class="guest__action">
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="confirm">
            <?php if ($confirmed): ?>
            <button type="button" class="guest__btn guest__btn--done" disabled>&#10003;&ensp;Bestätigt</button>
            <?php else: ?>
            <button type="submit" class="guest__btn guest__btn--confirm">&#10003;&ensp;Reservierung bestätigen</button>
            <?php endif; ?>
          </form>
          <form method="post" action="reservierung.php" class="guest__action"<?= $view === 'cancel' ? '' : ' data-ask="Möchten Sie Ihre Tischreservierung wirklich stornieren? Ihre Plätze werden dann für andere Gäste freigegeben."' ?>>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="guest__btn <?= $view === 'cancel' ? 'guest__btn--cancel' : 'guest__btn--cancel-soft' ?>"><?= $view === 'cancel' ? 'Ja, stornieren' : 'Reservierung stornieren' ?></button>
          </form>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    <p class="guest__contact">Fragen? <a href="mailto:stiftungsamt@straubing.de">stiftungsamt@straubing.de</a></p>
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
// Cancelling asks once more; one press is enough — no double submit on slow connections.
document.querySelectorAll(".guest__action").forEach(function (form) {
  form.addEventListener("submit", function (evt) {
    var ask = form.getAttribute("data-ask");
    if (ask && !window.confirm(ask)) {
      evt.preventDefault();
      return;
    }
    form.querySelector("button").disabled = true;
  });
});
</script>
</body>
</html>
