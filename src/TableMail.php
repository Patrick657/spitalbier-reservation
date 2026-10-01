<?php
declare(strict_types=1);

require_once __DIR__ . '/names.php';

/**
 * Batch "Tischbestätigung": one individual mail per reservation, built from
 * a free-text template with {platzhalter}. Shared by the compose/preview
 * page (dashboard/table_mail.php) and the per-recipient send endpoint
 * (dashboard/table_mail_send.php).
 */

const SBF_TABLE_MAIL_KIND = 'table_confirm';
const SBF_TABLE_MAIL_TEST_KIND = 'table_test';

/** Placeholder => explanation shown next to the text field. */
function sbf_table_mail_placeholders(): array
{
    return [
        'name' => 'Anrede-Name: „Vorname Nachname“, ohne Person die Firma',
        'vorname' => 'Vorname',
        'nachname' => 'Nachname',
        'firma' => 'Firma / Gruppe',
        'code' => 'Reservierungsnummer, z. B. SBF-1234',
        'personen' => 'Anzahl reservierter Personen',
        'tische' => 'Zugewiesene Tische, z. B. „A-01, A-02“',
        'tische_detail' => 'Tische mit Platzzahl, z. B. „A-01 (6 Plätze), A-02 (4 Plätze)“',
    ];
}

function sbf_table_mail_default_subject(): string
{
    return 'Ihre Tischreservierung zum Spitalbierfest – {code}';
}

function sbf_table_mail_default_body(): string
{
    return "Vergelt's Gott, {name}!\n\n"
        . "Ihre Plätze für das 1. Straubinger Spitalbierfest stehen fest:\n\n"
        . "Reservierungsnummer: {code}\n"
        . "Personen: {personen}\n"
        . "Ihr Tisch: {tische}\n\n"
        . "Termin: Freitag, 30. Oktober 2026, 18:00 Uhr\n"
        . "Ort: Rittersaal im Herzogschloss, Straubing\n\n"
        . "Ihre Tische werden bis 18:30 Uhr freigehalten, danach entfallen nicht angetretene Reservierungen.\n\n"
        . "Fragen? stiftungsamt@straubing.de\n\n"
        . "Straubinger Spitalbier der Bürgerspitalstiftung Straubing";
}

/**
 * The last saved template, or the default text. The table only exists once
 * the table-mail migration has been run; without it the default is used and
 * nothing is remembered between visits.
 *
 * @return array{subject: string, body: string, copy_mode: string, copy_email: string}
 */
function sbf_table_mail_load_template(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM mail_templates WHERE name = :name');
        $stmt->execute(['name' => SBF_TABLE_MAIL_KIND]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'subject' => (string) $row['subject'],
                'body' => (string) $row['body'],
                'copy_mode' => (string) ($row['copy_mode'] ?? ''),
                'copy_email' => (string) ($row['copy_email'] ?? ''),
            ];
        }
    } catch (PDOException $e) {
        // table missing — fall through to the default
    }
    return [
        'subject' => sbf_table_mail_default_subject(),
        'body' => sbf_table_mail_default_body(),
        'copy_mode' => '',
        'copy_email' => '',
    ];
}

/** Best-effort: a missing table must not block previewing or sending. */
function sbf_table_mail_save_template(PDO $pdo, string $subject, string $body, string $copyMode, string $copyEmail): bool
{
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM mail_templates WHERE name = :name')->execute(['name' => SBF_TABLE_MAIL_KIND]);
        $pdo->prepare(
            'INSERT INTO mail_templates (name, subject, body, copy_mode, copy_email)
             VALUES (:name, :subject, :body, :copy_mode, :copy_email)'
        )->execute([
            'name' => SBF_TABLE_MAIL_KIND,
            'subject' => $subject,
            'body' => $body,
            'copy_mode' => $copyMode,
            'copy_email' => $copyEmail,
        ]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/**
 * Every active reservation with its tables and what was already sent,
 * keyed by reservation id. `blocker` is null when a mail can be sent,
 * otherwise the reason it can't.
 *
 * @param int[]|null $onlyIds restrict to these reservation ids
 */
function sbf_table_mail_candidates(PDO $pdo, ?array $onlyIds = null): array
{
    $where = 'cancelled_at IS NULL';
    $params = [];
    if ($onlyIds !== null) {
        if (!$onlyIds) {
            return [];
        }
        $where .= ' AND id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
        $params = array_values($onlyIds);
    }
    $stmt = $pdo->prepare(
        "SELECT id, code, name, first_name, last_name, company, email, guests
         FROM reservations WHERE {$where} ORDER BY name"
    );
    $stmt->execute($params);

    $candidates = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['id'] = (int) $r['id'];
        $r['guests'] = (int) $r['guests'];
        $r['tables'] = [];
        $r['assigned'] = 0;
        $r['sent_count'] = 0;
        $r['last_sent_at'] = null;
        $candidates[$r['id']] = $r;
    }

    foreach ($pdo->query(
        'SELECT ta.reservation_id, vt.code, ta.seats
         FROM table_assignments ta
         JOIN venue_tables vt ON vt.id = ta.table_id
         WHERE ta.reservation_id IS NOT NULL
         ORDER BY vt.row_label, vt.position'
    )->fetchAll() as $a) {
        $id = (int) $a['reservation_id'];
        if (!isset($candidates[$id])) {
            continue;
        }
        // Two assignments at the same table count as one table.
        $candidates[$id]['tables'][$a['code']] = ($candidates[$id]['tables'][$a['code']] ?? 0) + (int) $a['seats'];
        $candidates[$id]['assigned'] += (int) $a['seats'];
    }

    // Looked up by code rather than joined, so differing table collations
    // can't break it (same reason as in mail_log.php).
    $idByCode = [];
    foreach ($candidates as $id => $r) {
        $idByCode[$r['code']] = $id;
    }
    try {
        $sent = $pdo->prepare(
            'SELECT reservation_code, COUNT(*) AS n, MAX(sent_at) AS last_sent
             FROM mail_log WHERE kind = :kind AND status = :status GROUP BY reservation_code'
        );
        $sent->execute(['kind' => SBF_TABLE_MAIL_KIND, 'status' => 'sent']);
        foreach ($sent->fetchAll() as $s) {
            $id = $idByCode[$s['reservation_code']] ?? null;
            if ($id !== null) {
                $candidates[$id]['sent_count'] = (int) $s['n'];
                $candidates[$id]['last_sent_at'] = $s['last_sent'];
            }
        }
    } catch (PDOException $e) {
        // mail_log missing — nothing was logged yet
    }

    foreach ($candidates as &$r) {
        if (!$r['tables']) {
            $r['blocker'] = 'kein Tisch zugewiesen';
        } elseif (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
            $r['blocker'] = $r['email'] === '' ? 'keine E-Mail-Adresse' : 'E-Mail-Adresse ungültig';
        } else {
            $r['blocker'] = null;
        }
        $r['complete'] = $r['assigned'] >= $r['guests'];
    }
    unset($r);

    return $candidates;
}

/** Placeholder values for one candidate row. */
function sbf_table_mail_vars(array $r): array
{
    $detail = [];
    foreach ($r['tables'] as $code => $seats) {
        $detail[] = $code . ' (' . $seats . ($seats === 1 ? ' Platz' : ' Plätze') . ')';
    }
    return [
        'name' => sbf_person_name((string) $r['first_name'], (string) $r['last_name']) ?: (string) $r['company'],
        'vorname' => (string) $r['first_name'],
        'nachname' => (string) $r['last_name'],
        'firma' => (string) $r['company'],
        'code' => (string) $r['code'],
        'personen' => (string) $r['guests'],
        'tische' => implode(', ', array_keys($r['tables'])),
        'tische_detail' => implode(', ', $detail),
    ];
}

/** Placeholders used in the text that don't exist (typos like {tisch}). */
function sbf_table_mail_unknown_placeholders(string $template): array
{
    $known = sbf_table_mail_placeholders();
    $unknown = [];
    if (preg_match_all('/\{([^{}\s]*)\}/u', $template, $m)) {
        foreach ($m[1] as $key) {
            if (!isset($known[mb_strtolower($key)])) {
                $unknown['{' . $key . '}'] = true;
            }
        }
    }
    return array_keys($unknown);
}

function sbf_table_mail_render(string $template, array $vars): string
{
    return (string) preg_replace_callback(
        '/\{([^{}\s]*)\}/u',
        static function (array $m) use ($vars): string {
            $key = mb_strtolower($m[1]);
            return array_key_exists($key, $vars) ? $vars[$key] : $m[0];
        },
        $template
    );
}

/** @return string[] problems that must be fixed before previewing or sending */
function sbf_table_mail_validate(string $subject, string $body): array
{
    $errors = [];
    if ($subject === '') {
        $errors[] = 'Bitte einen Betreff angeben.';
    } elseif (mb_strlen($subject) > 150) {
        $errors[] = 'Der Betreff ist zu lang (max. 150 Zeichen).';
    }
    if ($body === '') {
        $errors[] = 'Bitte einen Text angeben.';
    } elseif (mb_strlen($body) > 10000) {
        $errors[] = 'Der Text ist zu lang (max. 10.000 Zeichen).';
    }
    $unknown = sbf_table_mail_unknown_placeholders($subject . "\n" . $body);
    if ($unknown) {
        $errors[] = 'Unbekannte Platzhalter: ' . implode(', ', $unknown) . '. Bitte nur die aufgelisteten Platzhalter verwenden.';
    }
    return $errors;
}

/** How the archive copy is addressed; '' sends no copy. */
function sbf_table_mail_copy_modes(): array
{
    return [
        '' => 'Keine Kopie',
        'bcc' => 'BCC – Blindkopie, für den Gast unsichtbar',
        'cc' => 'CC – Kopie, für den Gast sichtbar',
    ];
}

/** @return string[] problems with the archive copy settings */
function sbf_table_mail_validate_copy(string $mode, string $email): array
{
    if (!isset(sbf_table_mail_copy_modes()[$mode])) {
        return ['Ungültige Auswahl für die Kopie.'];
    }
    if ($mode !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
        return ['Bitte eine gültige E-Mail-Adresse für die Kopie (' . strtoupper($mode) . ') angeben.'];
    }
    return [];
}

/** Normalises what comes out of the form: one-line subject, \n line ends. */
function sbf_table_mail_clean_subject(string $subject): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $subject));
}

function sbf_table_mail_clean_body(string $body): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $body));
}

/** HTML part for a plain-text mail body: escaped, line breaks kept. */
function sbf_table_mail_html(string $text): string
{
    $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
    $html = '';
    foreach ($paragraphs as $p) {
        $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }
    return $html;
}

/** The mailer from config, or null when no SMTP host is configured. */
function sbf_table_mail_mailer(array $cfg): ?SmtpMailer
{
    $smtp = $cfg['smtp'];
    if ($smtp['host'] === '') {
        return null;
    }
    return new SmtpMailer(
        $smtp['host'],
        $smtp['port'],
        $smtp['secure'],
        $smtp['user'],
        $smtp['pass'],
        $smtp['from_email'],
        $smtp['from_name']
    );
}
