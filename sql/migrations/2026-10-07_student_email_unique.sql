/*
  Live (InfinityFree). Run in phpMyAdmin, SQL tab. Safe to run twice.
  Every table is written with the database name, so it does not matter which database is selected.
  The first SELECT must return no rows. If it lists an email, fix those students first.
*/
SELECT email_address, COUNT(*) AS students
FROM `if0_43013716_enrollment_system`.`Student`
WHERE email_address IS NOT NULL GROUP BY email_address HAVING COUNT(*) > 1;

ALTER TABLE `if0_43013716_enrollment_system`.`Student`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_student_email` (`email_address`);

SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'if0_43013716_enrollment_system' AND TABLE_NAME = 'Student' AND INDEX_NAME = 'uq_student_email';