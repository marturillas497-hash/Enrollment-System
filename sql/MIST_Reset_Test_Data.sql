-- MIST Enrollment System: reset to a clean slate
-- Run in phpMyAdmin > your database > SQL tab. Export the database first (Export > Quick > SQL) as a backup.
--
-- KEEPS
--   Department (6), Program (6), the Admin account (account_id 2)
--   BSM subjects: every subject in the curriculum "BSM 2026" (52 subjects), plus that curriculum and its subject list
--   Prerequisites whose two subjects both survive
--   BSM sections 3, 4, 5, 6, 8, 9
-- DELETES
--   Every other account (registrars, teachers, admission staff, students), every application, student,
--   enrollment, grade, term, class offering, shift request, credit, document and reset token.
--   Every non-BSM section, curriculum, and subject (this includes BSIS and all TST test data).
--
-- Safe to run again after you finish the v3 checklist to get back to this exact state.
-- The DELETEs run inside one transaction, so if any statement errors, nothing is deleted.

START TRANSACTION;

DELETE FROM Shift_credit;
DELETE FROM Transferee_credit;
DELETE FROM Enrolled_subject;
DELETE FROM Enrollment;
DELETE FROM Program_shift_request;
DELETE FROM Class_Offering;
DELETE FROM Enrollment_Document;
DELETE FROM Student;
DELETE FROM Admission_Application;
DELETE FROM Password_reset;
DELETE FROM Admission_Staff;
DELETE FROM Teacher;
DELETE FROM Registrar;
DELETE FROM School_term;
DELETE FROM Accounts WHERE role <> 'admin';

DELETE FROM Section WHERE section_id NOT IN (3, 4, 5, 6, 8, 9);

DELETE FROM Curriculum_subject
WHERE curriculum_id NOT IN (SELECT curriculum_id FROM Curriculum WHERE curriculum_name = 'BSM 2026');
DELETE FROM Curriculum WHERE curriculum_name <> 'BSM 2026';

DELETE FROM Prerequisite
WHERE subject_id NOT IN (SELECT subject_id FROM Curriculum_subject)
   OR prerequisite_subject_id NOT IN (SELECT subject_id FROM Curriculum_subject);
DELETE FROM Subject WHERE subject_id NOT IN (SELECT subject_id FROM Curriculum_subject);

UPDATE Curriculum SET is_active = 1 WHERE curriculum_name = 'BSM 2026';

COMMIT;

-- Restart the id counters (a counter can't go below the highest id still in use, so kept tables resume after it)
ALTER TABLE Shift_credit AUTO_INCREMENT = 1;
ALTER TABLE Transferee_credit AUTO_INCREMENT = 1;
ALTER TABLE Enrolled_subject AUTO_INCREMENT = 1;
ALTER TABLE Enrollment AUTO_INCREMENT = 1;
ALTER TABLE Program_shift_request AUTO_INCREMENT = 1;
ALTER TABLE Class_Offering AUTO_INCREMENT = 1;
ALTER TABLE Enrollment_Document AUTO_INCREMENT = 1;
ALTER TABLE Student AUTO_INCREMENT = 1;
ALTER TABLE Admission_Application AUTO_INCREMENT = 1;
ALTER TABLE Password_reset AUTO_INCREMENT = 1;
ALTER TABLE Admission_Staff AUTO_INCREMENT = 1;
ALTER TABLE Teacher AUTO_INCREMENT = 1;
ALTER TABLE Registrar AUTO_INCREMENT = 1;
ALTER TABLE School_term AUTO_INCREMENT = 1;
ALTER TABLE Accounts AUTO_INCREMENT = 1;
ALTER TABLE Section AUTO_INCREMENT = 1;
ALTER TABLE Curriculum AUTO_INCREMENT = 1;
ALTER TABLE Subject AUTO_INCREMENT = 1;

-- Verify. Expected row counts:
-- Accounts 1, Department 6, Program 6, Curriculum 1, Curriculum_subject 52, Subject 52, Prerequisite 3, Section 6,
-- every other table 0.
SELECT 'Accounts' AS tbl, COUNT(*) AS rows_left FROM Accounts
UNION ALL SELECT 'Department', COUNT(*) FROM Department
UNION ALL SELECT 'Program', COUNT(*) FROM Program
UNION ALL SELECT 'Curriculum', COUNT(*) FROM Curriculum
UNION ALL SELECT 'Curriculum_subject', COUNT(*) FROM Curriculum_subject
UNION ALL SELECT 'Subject', COUNT(*) FROM Subject
UNION ALL SELECT 'Prerequisite', COUNT(*) FROM Prerequisite
UNION ALL SELECT 'Section', COUNT(*) FROM Section
UNION ALL SELECT 'School_term', COUNT(*) FROM School_term
UNION ALL SELECT 'Registrar', COUNT(*) FROM Registrar
UNION ALL SELECT 'Teacher', COUNT(*) FROM Teacher
UNION ALL SELECT 'Admission_Staff', COUNT(*) FROM Admission_Staff
UNION ALL SELECT 'Admission_Application', COUNT(*) FROM Admission_Application
UNION ALL SELECT 'Student', COUNT(*) FROM Student
UNION ALL SELECT 'Enrollment', COUNT(*) FROM Enrollment
UNION ALL SELECT 'Enrolled_subject', COUNT(*) FROM Enrolled_subject
UNION ALL SELECT 'Class_Offering', COUNT(*) FROM Class_Offering
UNION ALL SELECT 'Program_shift_request', COUNT(*) FROM Program_shift_request
UNION ALL SELECT 'Shift_credit', COUNT(*) FROM Shift_credit
UNION ALL SELECT 'Transferee_credit', COUNT(*) FROM Transferee_credit
UNION ALL SELECT 'Enrollment_Document', COUNT(*) FROM Enrollment_Document
UNION ALL SELECT 'Password_reset', COUNT(*) FROM Password_reset;
