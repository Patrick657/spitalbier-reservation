<?php
declare(strict_types=1);

/** "Vorname Nachname" for addressing someone (mail greeting, recipient). */
function sbf_person_name(string $firstName, string $lastName): string
{
    return trim($firstName . ' ' . $lastName);
}

/** "Nachname, Vorname" for lists, or just one of them when the other is missing. */
function sbf_list_name(string $firstName, string $lastName): string
{
    return $firstName !== '' && $lastName !== ''
        ? $lastName . ', ' . $firstName
        : $firstName . $lastName;
}

/**
 * The combined name stored in reservations.name and shown wherever there is
 * only room for one line: the company first, the contact person in brackets.
 */
function sbf_display_name(string $firstName, string $lastName, string $company): string
{
    $person = sbf_list_name($firstName, $lastName);
    if ($company === '') {
        return $person;
    }
    return mb_substr($person !== '' ? "{$company} ({$person})" : $company, 0, 190);
}

/**
 * Name cell for dashboard tables: the company, with the contact person
 * printed lightly in brackets below. Expects name, first_name, last_name
 * and company; returns escaped HTML.
 */
function sbf_name_html(array $r): string
{
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $company = (string) ($r['company'] ?? '');
    if ($company === '') {
        return $esc((string) $r['name']);
    }
    $person = sbf_list_name((string) ($r['first_name'] ?? ''), (string) ($r['last_name'] ?? ''));
    return $esc($company) . ($person !== '' ? '<br><span class="dash-note">(' . $esc($person) . ')</span>' : '');
}
