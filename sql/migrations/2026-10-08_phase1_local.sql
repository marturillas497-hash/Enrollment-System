/*
  Phase 1 (v2.4), localhost (XAMPP). phpMyAdmin > select `enrollment_system` > SQL tab.
  Step 1: run the SELECTs alone first. They must return no rows (the last one is information only).
  If one lists something, fix it first (change duplicate emails; rename usernames containing @), then run Step 2.
  The first and third SELECTs read student.email_address, so skip them once Step 2 has run (the column is gone).
*/
SELECT e AS email, COUNT(*) AS accounts FROM (
  SELECT COALESCE(NULLIF(a.email, ''), NULLIF(s.email_address, '')) AS e
  FROM `enrollment_system`.`accounts` a LEFT JOIN `enrollment_system`.`student` s ON s.account_id = a.account_id
) t WHERE e IS NOT NULL GROUP BY e HAVING COUNT(*) > 1;

SELECT username FROM `enrollment_system`.`accounts` WHERE username LIKE '%@%';

SELECT s.student_id_number, a.email AS account_email, s.email_address AS student_email
FROM `enrollment_system`.`student` s JOIN `enrollment_system`.`accounts` a ON a.account_id = s.account_id
WHERE a.email IS NOT NULL AND s.email_address IS NOT NULL AND a.email <> s.email_address;

/* Step 2: safe to run more than once */
UPDATE `enrollment_system`.`accounts` SET email = NULL WHERE email = '';

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'enrollment_system' AND TABLE_NAME = 'student' AND COLUMN_NAME = 'email_address');
SET @copy := IF(@has_col > 0,
  'UPDATE `enrollment_system`.`accounts` a JOIN `enrollment_system`.`student` s ON s.account_id = a.account_id SET a.email = s.email_address WHERE a.email IS NULL AND s.email_address IS NOT NULL AND LENGTH(s.email_address) > 0',
  'SELECT 1');
PREPARE copy_stmt FROM @copy;
EXECUTE copy_stmt;
DEALLOCATE PREPARE copy_stmt;

ALTER TABLE `enrollment_system`.`accounts`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_account_email` (`email`);

ALTER TABLE `enrollment_system`.`student` DROP INDEX IF EXISTS `uq_student_email`;
ALTER TABLE `enrollment_system`.`student` DROP COLUMN IF EXISTS `email_address`;

ALTER TABLE `enrollment_system`.`subject`
  ADD COLUMN IF NOT EXISTS `year_level` INT NULL DEFAULT NULL AFTER `units`;

UPDATE `enrollment_system`.`subject` s
JOIN (SELECT subject_id, MIN(year_level) AS y FROM `enrollment_system`.`curriculum_subject` GROUP BY subject_id) m ON m.subject_id = s.subject_id
SET s.year_level = m.y
WHERE s.year_level IS NULL;

UPDATE `enrollment_system`.`subject` SET year_level = 1 WHERE year_level IS NULL;

ALTER TABLE `enrollment_system`.`subject`
  MODIFY COLUMN `year_level` INT NOT NULL DEFAULT 1;

/* Step 3: expect NON_UNIQUE = 0; then IS_NULLABLE = NO and COLUMN_DEFAULT = 1; then 0 */
SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'enrollment_system' AND TABLE_NAME = 'accounts' AND INDEX_NAME = 'uq_account_email';

SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'enrollment_system' AND TABLE_NAME = 'subject' AND COLUMN_NAME = 'year_level';

SELECT COUNT(*) AS should_be_0 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'enrollment_system' AND TABLE_NAME = 'student' AND COLUMN_NAME = 'email_address';
