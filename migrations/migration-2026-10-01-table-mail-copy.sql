-- Only needed if you already ran migration-2026-10-01-table-mail.sql BEFORE
-- it contained the copy_mode / copy_email columns (via phpMyAdmin or
-- `mysql -u ... -p dbname < migration-2026-10-01-table-mail-copy.sql`).
-- Lets the dashboard remember the CC/BCC archive address of the
-- Tischbestätigung. If the columns already exist, this fails with
-- "Duplicate column name" — that is harmless, nothing else to do.

ALTER TABLE mail_templates
  ADD COLUMN copy_mode VARCHAR(3) NOT NULL DEFAULT '' AFTER body,
  ADD COLUMN copy_email VARCHAR(190) NOT NULL DEFAULT '' AFTER copy_mode;
