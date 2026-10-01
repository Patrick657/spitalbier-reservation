<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/MailLog.php';
require_once __DIR__ . '/../../src/TableMail.php';

// Batch "Tischbestätigung" in three deliberate steps, so nothing goes out
// by accident:
//   1. compose  — write the text, pick recipients (nothing is sent)
//   2. preview  — every mail exactly as it will be sent, plus a confirmation
//                 that has to be ticked and typed
//   3. progress — the browser sends one recipient at a time through
//                 table_mail_send.php and can be stopped at any point
// The previewed batch lives in the session under a one-time token; the send
// endpoint only accepts recipients of a started batch, each exactly once.

// A preview older than this has to be redone before sending.
const SBF_TABLE_MAIL_PREVIEW_TTL = 1800;

$cfg = sbf_config();
$tz = new DateTimeZone('Europe/Berlin');
$pdo = sbf_pdo();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/** Back to the compose step with a message (post/redirect/get). */
function sbf_table_mail_back(string $type, string $text): void
{
    $_SESSION['table_mail_flash'] = ['type' => $type, 'text' => $text];
    header('Location: table_mail.php', true, 303);
    exit;
}

$smtpReady = $cfg['smtp']['host'] !== '';
$view = 'compose';
$previewError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        sbf_table_mail_back('error', 'Ungültige Anfrage. Bitte erneut versuchen.');
    }
    $step = (string) ($_POST['step'] ?? '');

    if ($step === 'discard') {
        unset($_SESSION['table_mail_batch']);
        sbf_table_mail_back('info', 'Der offene Versand wurde verworfen. Bereits versendete E-Mails stehen im E-Mail-Log.');
    }

    if ($step === 'preview' || $step === 'test') {
        $subject = sbf_table_mail_clean_subject((string) ($_POST['subject'] ?? ''));
        $body = sbf_table_mail_clean_body((string) ($_POST['body'] ?? ''));
        $testEmail = trim((string) ($_POST['test_email'] ?? ''));
        $ids = [];
        foreach ((array) ($_POST['ids'] ?? []) as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if ($id) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);

        // Keep what was typed, whatever happens next.
        $_SESSION['table_mail_draft'] = ['subject' => $subject, 'body' => $body, 'ids' => $ids, 'test_email' => $testEmail];

        $pending = $_SESSION['table_mail_batch']['pending'] ?? [];
        if (!empty($_SESSION['table_mail_batch']['started']) && $pending) {
            sbf_table_mail_back('error', 'Es läuft noch ein Versand. Bitte diesen zuerst fortsetzen oder verwerfen.');
        }

        $errors = sbf_table_mail_validate($subject, $body);
        if ($errors) {
            sbf_table_mail_back('error', implode(' ', $errors));
        }
        if (!$smtpReady) {
            sbf_table_mail_back('error', 'Es ist kein SMTP-Server konfiguriert – es können keine E-Mails versendet werden.');
        }
        sbf_table_mail_save_template($pdo, $subject, $body);

        $candidates = sbf_table_mail_candidates($pdo);

        if ($step === 'test') {
            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                sbf_table_mail_back('error', 'Bitte eine gültige Adresse für die Testmail angeben.');
            }
            // Example data: the first selected recipient, else the first possible one.
            $sample = null;
            foreach (array_merge($ids, array_keys($candidates)) as $id) {
                if (isset($candidates[$id]) && $candidates[$id]['blocker'] === null) {
                    $sample = $candidates[$id];
                    break;
                }
            }
            if ($sample === null) {
                sbf_table_mail_back('error', 'Für eine Testmail fehlt eine Reservierung mit zugewiesenem Tisch und E-Mail-Adresse.');
            }
            $vars = sbf_table_mail_vars($sample);
            $text = sbf_table_mail_render($body, $vars);
            try {
                sbf_send_logged(
                    $pdo,
                    sbf_table_mail_mailer($cfg),
                    SBF_TABLE_MAIL_TEST_KIND,
                    null,
                    $testEmail,
                    'Test',
                    '[TEST] ' . sbf_table_mail_render($subject, $vars),
                    $text,
                    sbf_table_mail_html($text)
                );
            } catch (Throwable $ex) {
                error_log('[spitalbierfest] table mail test error: ' . $ex->getMessage());
                sbf_table_mail_back('error', 'Die Testmail konnte nicht versendet werden: ' . $ex->getMessage());
            }
            sbf_table_mail_back('ok', 'Testmail an ' . $testEmail . ' versendet (mit den Daten von ' . $sample['name'] . ', ' . $sample['code'] . '). An die Gäste wurde nichts versendet.');
        }

        if (!$ids) {
            sbf_table_mail_back('error', 'Bitte mindestens einen Empfänger auswählen.');
        }
        $recipients = [];
        foreach ($ids as $id) {
            if (!isset($candidates[$id]) || $candidates[$id]['blocker'] !== null) {
                sbf_table_mail_back('error', 'Die Auswahl enthält eine Reservierung, an die nicht (mehr) versendet werden kann. Bitte Auswahl prüfen.');
            }
            // Remember how often this one already got the mail: if that
            // number grows before our turn, someone else sent it meanwhile.
            $recipients[$id] = $candidates[$id]['sent_count'];
        }

        $_SESSION['table_mail_batch'] = [
            'token' => bin2hex(random_bytes(16)),
            'subject' => $subject,
            'body' => $body,
            'recipients' => $recipients,
            'pending' => [],
            'results' => [],
            'created' => time(),
            'started' => false,
        ];
        $view = 'preview';
    }

    if ($step === 'start') {
        $batch = $_SESSION['table_mail_batch'] ?? null;
        if (!$batch || !hash_equals($batch['token'], (string) ($_POST['token'] ?? ''))) {
            sbf_table_mail_back('error', 'Diese Vorschau ist nicht mehr gültig. Bitte die Vorschau erneut erstellen.');
        }
        if ($batch['started']) {
            // Reload or double click: never start the same batch twice.
            header('Location: table_mail.php?view=progress', true, 303);
            exit;
        }
        if (time() - $batch['created'] > SBF_TABLE_MAIL_PREVIEW_TTL) {
            unset($_SESSION['table_mail_batch']);
            sbf_table_mail_back('error', 'Die Vorschau ist älter als 30 Minuten. Bitte die Vorschau erneut erstellen und prüfen.');
        }
        $typed = filter_var(trim((string) ($_POST['confirm_count'] ?? '')), FILTER_VALIDATE_INT);
        if (empty($_POST['confirm_checked']) || $typed !== count($batch['recipients'])) {
            $previewError = 'Nicht versendet: Bitte das Häkchen setzen und die Anzahl der Empfänger (' . count($batch['recipients']) . ') eintippen.';
            $view = 'preview';
        } else {
            $_SESSION['table_mail_batch']['started'] = true;
            $_SESSION['table_mail_batch']['pending'] = array_keys($batch['recipients']);
            $_SESSION['table_mail_autostart'] = true;
            header('Location: table_mail.php?view=progress', true, 303);
            exit;
        }
    }
}

$batch = $_SESSION['table_mail_batch'] ?? null;

if ($view === 'compose' && ($_GET['view'] ?? '') === 'progress' && $batch && $batch['started']) {
    $view = 'progress';
}

// A finished batch has nothing left to protect; start clean next time.
if ($view === 'compose' && $batch && $batch['started'] && !$batch['pending']) {
    unset($_SESSION['table_mail_batch'], $_SESSION['table_mail_draft']['ids']);
    $batch = null;
}

$flash = null;
if ($view === 'compose' && isset($_SESSION['table_mail_flash'])) {
    $flash = $_SESSION['table_mail_flash'];
    unset($_SESSION['table_mail_flash']);
}

$placeholders = sbf_table_mail_placeholders();

if ($view === 'compose') {
    $candidates = sbf_table_mail_candidates($pdo);
    $draft = $_SESSION['table_mail_draft'] ?? [];
    $template = sbf_table_mail_load_template($pdo);
    $subject = $draft['subject'] ?? $template['subject'];
    $body = $draft['body'] ?? $template['body'];
    $testEmail = $draft['test_email'] ?? '';
    $selectedIds = isset($draft['ids']) ? array_flip($draft['ids']) : null;
    $openBatch = $batch && $batch['started'] && $batch['pending'];

    $sendable = 0;
    $alreadySent = 0;
    foreach ($candidates as $c) {
        if ($c['blocker'] === null) {
            $sendable++;
            if ($c['sent_count'] > 0) {
                $alreadySent++;
            }
        }
    }
} else {
    $candidates = sbf_table_mail_candidates($pdo, array_keys($batch['recipients']));
    $resendCount = 0;
    $incompleteCount = 0;
    foreach ($batch['recipients'] as $id => $sentBefore) {
        if ($sentBefore > 0) {
            $resendCount++;
        }
        if (isset($candidates[$id]) && !$candidates[$id]['complete']) {
            $incompleteCount++;
        }
    }
    $total = count($batch['recipients']);
}

$autostart = false;
if ($view === 'progress') {
    $autostart = !empty($_SESSION['table_mail_autostart']);
    unset($_SESSION['table_mail_autostart']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Tischbest&auml;tigung &ndash; Spitalbierfest Dashboard</title>
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
  <a href="table_mail.php" class="is-active">Tischbest&auml;tigung</a>
  <a href="mail_log.php">E-Mail-Log</a>
</nav>

<main class="dash-main">

  <ol class="mail-steps">
    <li class="<?= $view === 'compose' ? 'is-current' : '' ?>">1. Text &amp; Empf&auml;nger</li>
    <li class="<?= $view === 'preview' ? 'is-current' : '' ?>">2. Vorschau &amp; Best&auml;tigung</li>
    <li class="<?= $view === 'progress' ? 'is-current' : '' ?>">3. Versand</li>
  </ol>

<?php if ($view === 'compose'): ?>

  <?php if ($flash): ?>
  <div class="dash-flash dash-flash--<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <?php if (!$smtpReady): ?>
  <div class="dash-flash dash-flash--error">Es ist kein SMTP-Server konfiguriert &ndash; es k&ouml;nnen keine E-Mails versendet werden.</div>
  <?php endif; ?>

  <?php if ($openBatch): ?>
  <div class="dash-flash dash-flash--error mail-open-batch">
    <span>Ein Versand wurde unterbrochen: <?= count($batch['pending']) ?> von <?= count($batch['recipients']) ?> E-Mails sind noch offen.</span>
    <a class="dash-btn-edit" href="table_mail.php?view=progress">Zum Versand</a>
    <form method="post" action="table_mail.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="step" value="discard">
      <button type="submit" class="dash-btn-cancel">Rest verwerfen</button>
    </form>
  </div>
  <?php endif; ?>

  <form method="post" action="table_mail.php" id="mail-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <div class="dash-two-col mail-compose">
      <div class="dash-form-card mail-card">
        <h2 class="dash-two-col__heading">Text der E-Mail</h2>
        <p class="hint">Jeder Empf&auml;nger erh&auml;lt eine eigene E-Mail. Die Platzhalter werden pro Reservierung ersetzt. Auf dieser Seite wird noch nichts an G&auml;ste versendet.</p>

        <div class="assign-form">
          <label>Betreff
            <input type="text" name="subject" id="mail-subject" maxlength="150" value="<?= e($subject) ?>" required>
          </label>

          <label>Text
            <textarea name="body" id="mail-body" rows="18" maxlength="10000" required><?= e($body) ?></textarea>
          </label>

          <div class="mail-placeholders">
            <span class="mail-placeholders__label">Platzhalter einf&uuml;gen (an der Cursor-Position):</span>
            <?php foreach ($placeholders as $key => $description): ?>
            <button type="button" class="mail-chip" data-placeholder="{<?= e($key) ?>}" title="<?= e($description) ?>">{<?= e($key) ?>}</button>
            <?php endforeach; ?>
          </div>
          <dl class="mail-placeholder-list">
            <?php foreach ($placeholders as $key => $description): ?>
            <dt>{<?= e($key) ?>}</dt><dd><?= e($description) ?></dd>
            <?php endforeach; ?>
          </dl>

          <button type="button" class="dash-btn-edit mail-reset" id="mail-reset"
            data-subject="<?= e(sbf_table_mail_default_subject()) ?>"
            data-body="<?= e(sbf_table_mail_default_body()) ?>">Standardtext wiederherstellen</button>
        </div>
      </div>

      <div class="dash-form-card mail-card">
        <h2 class="dash-two-col__heading">Testmail</h2>
        <p class="hint">Sendet genau eine E-Mail an die hier eingetragene Adresse &ndash; mit den Daten des ersten ausgew&auml;hlten Empf&auml;ngers und &bdquo;[TEST]&ldquo; im Betreff.</p>
        <div class="assign-form">
          <label>Testadresse
            <input type="email" name="test_email" value="<?= e($testEmail) ?>" placeholder="ihre.adresse@beispiel.de">
          </label>
          <button type="submit" name="step" value="test" class="dash-btn-edit" formnovalidate <?= $smtpReady && !$openBatch ? '' : 'disabled' ?>>Testmail senden</button>
        </div>

        <h2 class="dash-two-col__heading mail-next">Weiter</h2>
        <p class="hint"><strong id="mail-count">0</strong> Empf&auml;nger ausgew&auml;hlt. Im n&auml;chsten Schritt sehen Sie jede E-Mail, bevor etwas versendet wird.</p>
        <button type="submit" name="step" value="preview" class="mail-btn-primary" <?= $smtpReady && !$openBatch ? '' : 'disabled' ?>>Vorschau anzeigen &rarr;</button>
      </div>
    </div>

    <h2 class="dash-two-col__heading mail-next">Empf&auml;nger</h2>
    <p class="dash-note mail-select-tools">
      <?= $sendable ?> Reservierungen mit Tisch und E-Mail-Adresse, davon <?= $alreadySent ?> bereits benachrichtigt.
      <button type="button" class="dash-btn-edit" id="mail-select-new">Alle noch nicht benachrichtigten</button>
      <button type="button" class="dash-btn-edit" id="mail-select-none">Keine</button>
    </p>

    <div class="dash-table-wrap">
      <table class="dash-table">
        <thead>
          <tr>
            <th data-nosort></th>
            <th data-sorted="asc">Name</th>
            <th>Code</th>
            <th>E-Mail</th>
            <th>Personen</th>
            <th>Tische</th>
            <th>Hinweis</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$candidates): ?>
          <tr><td colspan="7" class="dash-table__empty">Keine aktiven Reservierungen.</td></tr>
          <?php endif; ?>
          <?php foreach ($candidates as $c):
              $ok = $c['blocker'] === null;
              $isNew = $ok && $c['complete'] && $c['sent_count'] === 0;
              $checked = $ok && ($selectedIds !== null ? isset($selectedIds[$c['id']]) : $isNew);
          ?>
          <tr class="<?= $ok ? '' : 'is-cancelled' ?>">
            <td>
              <?php if ($ok): ?>
              <input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="mail-check" data-new="<?= $c['sent_count'] === 0 ? '1' : '0' ?>" <?= $checked ? 'checked' : '' ?> aria-label="<?= e($c['name']) ?> ausw&auml;hlen">
              <?php endif; ?>
            </td>
            <td><?= sbf_name_html($c) ?></td>
            <td><?= e($c['code']) ?></td>
            <td><?= $c['email'] !== '' ? e($c['email']) : '&ndash;' ?></td>
            <td><?= $c['guests'] ?></td>
            <td><?= $c['tables'] ? e(sbf_table_mail_vars($c)['tische_detail']) : '&ndash;' ?></td>
            <td>
              <?php if (!$ok): ?>
                <span class="dash-badge dash-badge--cancelled"><?= e($c['blocker']) ?></span>
              <?php endif; ?>
              <?php if ($ok && !$c['complete']): ?>
                <span class="dash-seats__partial" title="<?= $c['assigned'] ?> von <?= $c['guests'] ?> Pl&auml;tzen zugewiesen">unvollst&auml;ndig gesetzt</span>
              <?php endif; ?>
              <?php if ($c['sent_count'] > 0): ?>
                <span class="dash-badge dash-badge--active">bereits versendet <?= e((new DateTime($c['last_sent_at'], $tz))->format('d.m. H:i')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>

<?php elseif ($view === 'preview'): ?>

  <?php if ($previewError): ?>
  <div class="dash-flash dash-flash--error"><?= e($previewError) ?></div>
  <?php endif; ?>

  <div class="dash-flash dash-flash--info">Noch wurde nichts versendet. Bitte die E-Mails unten pr&uuml;fen &ndash; der Versand startet erst nach der Best&auml;tigung am Ende der Seite.</div>

  <?php if ($resendCount > 0): ?>
  <div class="dash-flash dash-flash--error"><?= $resendCount ?> der ausgew&auml;hlten Empf&auml;nger haben bereits eine Tischbest&auml;tigung erhalten und bekommen sie <strong>erneut</strong>.</div>
  <?php endif; ?>
  <?php if ($incompleteCount > 0): ?>
  <div class="dash-flash dash-flash--error">Bei <?= $incompleteCount ?> der ausgew&auml;hlten Reservierungen sind noch nicht alle Pl&auml;tze einem Tisch zugewiesen.</div>
  <?php endif; ?>

  <h2 class="dash-two-col__heading"><?= $total ?> E-Mail<?= $total === 1 ? '' : 's' ?> &ndash; Vorschau</h2>

  <div class="mail-previews">
    <?php $first = true; foreach ($batch['recipients'] as $id => $sentBefore):
        $c = $candidates[$id] ?? null;
        if ($c === null) { continue; }
        $vars = sbf_table_mail_vars($c);
    ?>
    <details class="mail-preview" <?= $first ? 'open' : '' ?>>
      <summary>
        <span class="mail-preview__name"><?= e($c['name']) ?></span>
        <span class="mail-preview__meta"><?= e($c['email']) ?> &middot; <?= e($vars['tische']) ?></span>
        <?php if ($sentBefore > 0): ?><span class="dash-seats__partial">erneut</span><?php endif; ?>
        <?php if (!$c['complete']): ?><span class="dash-seats__partial">unvollst&auml;ndig gesetzt</span><?php endif; ?>
      </summary>
      <div class="mail-preview__body">
        <div class="mail-preview__head"><strong>An:</strong> <?= e($vars['name']) ?> &lt;<?= e($c['email']) ?>&gt;</div>
        <div class="mail-preview__head"><strong>Betreff:</strong> <?= e(sbf_table_mail_render($batch['subject'], $vars)) ?></div>
        <pre><?= e(sbf_table_mail_render($batch['body'], $vars)) ?></pre>
      </div>
    </details>
    <?php $first = false; endforeach; ?>
  </div>

  <div class="dash-form-card mail-confirm">
    <h2 class="dash-two-col__heading">Versand best&auml;tigen</h2>
    <p class="hint">Der Versand kann nicht r&uuml;ckg&auml;ngig gemacht werden. Jede E-Mail wird im E-Mail-Log protokolliert.</p>
    <form method="post" action="table_mail.php" class="assign-form" id="mail-confirm-form" data-total="<?= $total ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="step" value="start">
      <input type="hidden" name="token" value="<?= e($batch['token']) ?>">

      <label class="checkbox-row">
        <input type="checkbox" name="confirm_checked" value="1" id="mail-confirm-check">
        <span>Ich habe Text und Empf&auml;nger gepr&uuml;ft und m&ouml;chte <strong><?= $total ?> E-Mail<?= $total === 1 ? '' : 's' ?></strong> an die G&auml;ste versenden.</span>
      </label>

      <label>Zur Sicherheit: Anzahl der Empf&auml;nger eintippen (<?= $total ?>)
        <input type="text" name="confirm_count" id="mail-confirm-count" inputmode="numeric" autocomplete="off" maxlength="4">
      </label>

      <div class="dash-row-actions">
        <a class="dash-btn-edit" href="table_mail.php">&larr; Zur&uuml;ck zum Bearbeiten</a>
        <button type="submit" class="mail-btn-primary mail-btn-danger" id="mail-confirm-submit" disabled>Jetzt <?= $total ?> E-Mail<?= $total === 1 ? '' : 's' ?> senden</button>
      </div>
    </form>
  </div>

<?php else: ?>

  <div class="dash-form-card mail-card mail-progress" id="mail-progress"
       data-token="<?= e($batch['token']) ?>" data-csrf="<?= e($csrf) ?>" data-autostart="<?= $autostart ? '1' : '0' ?>">
    <h2 class="dash-two-col__heading">Versand</h2>
    <p class="hint" id="mail-progress-text">
      <?php if ($batch['pending']): ?>
        <?= count($batch['pending']) ?> von <?= $total ?> E-Mails sind noch offen. Bitte diese Seite w&auml;hrend des Versands ge&ouml;ffnet lassen.
      <?php else: ?>
        Der Versand ist abgeschlossen.
      <?php endif; ?>
    </p>
    <div class="mail-bar"><div class="mail-bar__fill" id="mail-bar-fill"></div></div>
    <div class="dash-row-actions">
      <button type="button" class="mail-btn-primary" id="mail-resume" <?= $batch['pending'] && !$autostart ? '' : 'hidden' ?>>Versand fortsetzen</button>
      <button type="button" class="dash-btn-cancel" id="mail-stop" <?= $autostart ? '' : 'hidden' ?>>Versand anhalten</button>
      <a class="dash-btn-edit" href="mail_log.php">Zum E-Mail-Log</a>
      <a class="dash-btn-edit" href="table_mail.php">Zur&uuml;ck</a>
    </div>
  </div>

  <div class="dash-table-wrap mail-next">
    <table class="dash-table">
      <thead>
        <tr><th>Name</th><th>Code</th><th>E-Mail</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php
          $pendingIds = array_flip($batch['pending']);
          $statusLabels = ['sent' => 'versendet', 'failed' => 'fehlgeschlagen', 'skipped' => 'übersprungen', 'unknown' => 'unklar'];
          foreach ($batch['recipients'] as $id => $sentBefore):
            $c = $candidates[$id] ?? null;
            $result = $batch['results'][$id] ?? null;
            $status = isset($pendingIds[$id]) ? 'pending' : ($result['status'] ?? 'unknown');
        ?>
        <tr data-id="<?= (int) $id ?>" data-status="<?= e($status) ?>">
          <td><?= $c ? sbf_name_html($c) : '&ndash;' ?></td>
          <td><?= $c ? e($c['code']) : '&ndash;' ?></td>
          <td><?= $c ? e($c['email']) : '&ndash;' ?></td>
          <td class="mail-status">
            <?php if ($status === 'pending'): ?>
              <span class="dash-badge dash-badge--manual">offen</span>
            <?php else: ?>
              <span class="dash-badge <?= $status === 'sent' ? 'dash-badge--active' : 'dash-badge--cancelled' ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
              <?php if (!empty($result['message'])): ?><span class="dash-note"><?= e($result['message']) ?></span><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>
</main>

<?php if ($view === 'compose'): ?>
<script src="sort.js"></script>
<?php endif; ?>
<script src="table_mail.js"></script>
</body>
</html>
