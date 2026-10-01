-- Run this once against your EXISTING live database (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-10-01-first-last-name.sql`),
-- together with deploying the code that writes these columns.
-- Splits the single name field into first name, last name and company.
-- The combined `name` column stays and keeps being filled
-- ("Vorname Nachname" or "Firma (Vorname Nachname)").

ALTER TABLE reservations
  ADD COLUMN first_name VARCHAR(90) NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN last_name VARCHAR(99) NOT NULL DEFAULT '' AFTER first_name,
  ADD COLUMN company VARCHAR(120) NOT NULL DEFAULT '' AFTER last_name;

-- Existing rows: exactly two words become first + last name. Anything else
-- (companies, groups, several first names) goes into last_name unchanged
-- and can be corrected by hand in the dashboard.
UPDATE reservations
SET first_name = SUBSTRING_INDEX(TRIM(name), ' ', 1),
    last_name = SUBSTRING_INDEX(TRIM(name), ' ', -1)
WHERE last_name = ''
  AND CHAR_LENGTH(TRIM(name)) - CHAR_LENGTH(REPLACE(TRIM(name), ' ', '')) = 1;

UPDATE reservations
SET last_name = LEFT(TRIM(name), 99)
WHERE last_name = '';
