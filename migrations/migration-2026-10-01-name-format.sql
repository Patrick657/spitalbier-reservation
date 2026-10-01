-- Run this once AFTER migration-2026-10-01-first-last-name.sql (via phpMyAdmin
-- or `mysql -u ... -p dbname < migration-2026-10-01-name-format.sql`).
-- Rewrites the combined `name` column to the list spelling used everywhere
-- in the dashboard: "Nachname, Vorname" or "Firma (Nachname, Vorname)".
-- Safe to re-run.

UPDATE reservations
SET name = LEFT(CASE
    WHEN company <> '' AND first_name <> '' AND last_name <> '' THEN CONCAT(company, ' (', last_name, ', ', first_name, ')')
    WHEN company <> '' AND CONCAT(first_name, last_name) <> '' THEN CONCAT(company, ' (', first_name, last_name, ')')
    WHEN company <> '' THEN company
    WHEN first_name <> '' AND last_name <> '' THEN CONCAT(last_name, ', ', first_name)
    ELSE CONCAT(first_name, last_name)
  END, 190)
WHERE first_name <> '' OR last_name <> '' OR company <> '';
