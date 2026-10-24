-- MIST local TEST seed: all four years for Auto-generate Classes (BSIS, 10 sections, 189 students, 40 applicants)
-- Import AFTER seed_test.sql and seed_generator.sql, once. A second import stops with a duplicate key error.
-- To run it again, import seed_test.sql, seed_generator.sql, then this file. Registrar: alcantara_ramon. Password for every account: mist2027.
-- Adds 8 teachers, 5 sections and 107 students, so the 10 BSIS sections need 2 runs of up to 5. TEST-A from seed_test is also listed but has nothing to schedule.
-- Teach years for term 2027-2028 S1: year 1 = A, B and C 2027 (no students yet, 40 validated applicants wait for placement),
-- year 2 = A, B and C 2026, year 3 = A and B 2025, year 4 = A and B 2024.
-- Expected: 68 classes (24 + 24 + 12 + 8) and none left unplaced. Deliberate shortages stay: PolSci has no tags (3 classes) and
-- Aquino's 4 unit cap covers only 2 of the 6 PE classes, so about 7 classes need a teacher by hand. B 2026 has 34 students,
-- more than the 30 seats of T-LAB1 and NET-1. Do not use it with Phase B of Test_Run_v5, it changes the expected counts there.
SET NAMES utf8mb4;

-- 1. Teachers (BSIS department). Tags: 1 Programming, 2 Database, 3 Mathematics, 4 Social Science, 5 Networking, 6 Web Development,
-- 7 Systems Analysis, 8 Management, 9 English, 11 Research, 12 Information Systems, 14 Humanities, 15 Science and Technology
INSERT INTO `Accounts` (`account_id`, `username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`) VALUES
(111, 'lim_paolo', 'lim.paolo@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(112, 'navarro_marco', 'navarro.marco@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(113, 'domingo_ruth', 'domingo.ruth@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(114, 'pascual_ian', 'pascual.ian@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(115, 'tan_victor', 'tan.victor@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(116, 'cruz_maricel', 'cruz.maricel@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(117, 'garcia_noel', 'garcia.noel@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(118, 'lopez_dina', 'lopez.dina@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00');

INSERT INTO `Teacher` (`teacher_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `max_units`, `max_hours_per_week`) VALUES
(111, 111, 'Lim', 'Paolo', NULL, NULL, 1, 24, 30.0),
(112, 112, 'Navarro', 'Marco', NULL, NULL, 1, 24, 30.0),
(113, 113, 'Domingo', 'Ruth', NULL, NULL, 1, 24, 30.0),
(114, 114, 'Pascual', 'Ian', NULL, NULL, 1, 24, 30.0),
(115, 115, 'Tan', 'Victor', NULL, NULL, 1, 24, 30.0),
(116, 116, 'Cruz', 'Maricel', NULL, NULL, 1, 24, 30.0),
(117, 117, 'Garcia', 'Noel', NULL, NULL, 1, 24, 30.0),
(118, 118, 'Lopez', 'Dina', NULL, NULL, 1, 24, 30.0);

INSERT INTO `Teacher_tag` (`teacher_id`, `tag_id`) VALUES
(111, 1), (111, 6), (112, 12), (112, 2), (112, 7), (113, 14), (113, 9), (113, 4), (114, 8), (114, 11),
(115, 5), (116, 12), (116, 8), (117, 3), (117, 15), (118, 1), (118, 2);

-- 2. Sections. Year level is the year students enter the section; students keep it as they move up.
INSERT INTO `Section` (`section_id`, `section_name`, `year_level`, `program_id`, `curriculum_id`, `max_slots`) VALUES
(13, 'C 2027', 1, 1, 3, 30),
(14, 'B 2026', 1, 1, 3, 35),
(15, 'C 2026', 1, 1, 3, 30),
(16, 'B 2025', 2, 1, 3, 35),
(17, 'B 2024', 3, 1, 3, 30);

-- 3. 107 students whose last enrollment was 2026-2027 S2: 34 in B 2026, 26 in C 2026, 27 in B 2025, 20 in B 2024
CREATE TABLE `tmp_numbers` (`i` INT NOT NULL PRIMARY KEY);
INSERT INTO `tmp_numbers` (`i`)
SELECT a.d * 100 + b.d * 10 + c.d + 1
FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c;

INSERT INTO `Accounts` (`account_id`, `username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`)
SELECT 2000 + i,
       CONCAT(CASE WHEN i <= 60 THEN '2026' WHEN i <= 87 THEN '2025' ELSE '2024' END, '-', LPAD(300 + i, 5, '0')),
       CONCAT('bsis', 300 + i, '@mist.test'),
       '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00'
FROM `tmp_numbers` WHERE i <= 107;

INSERT INTO `Admission_Application` (`application_id`, `applicant_last_name`, `applicant_first_name`, `birthdate`, `applicant_province`, `applicant_municipality`, `applicant_barangay`, `contact_no`, `email_address`, `student_type`, `program_id`, `application_date`, `status`, `validated_by`, `evaluated_year_level`, `date_validated`)
SELECT 2000 + i,
       ELT(1 + MOD(i * 5, 12), 'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Castro', 'Dizon'),
       ELT(1 + MOD(i * 3, 10), 'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Grace', 'Paolo', 'Lea', 'Carlo', 'Joy'),
       DATE_ADD('2004-01-01', INTERVAL i * 11 DAY), 'Cotabato', 'Makilala', 'Poblacion', CONCAT('0917', LPAD(5560000 + i, 7, '0')),
       CONCAT('bsis', 300 + i, '@mist.test'), 'freshman', 1, '2025-06-01', 'validated', 7, 1, '2025-06-10'
FROM `tmp_numbers` WHERE i <= 107;

INSERT INTO `Student` (`student_id`, `student_id_number`, `account_id`, `application_id`, `last_name`, `first_name`, `birthdate`, `province`, `municipality`, `barangay`, `contact_no`, `student_type`, `overall_status`)
SELECT 2000 + i,
       CONCAT(CASE WHEN i <= 60 THEN '2026' WHEN i <= 87 THEN '2025' ELSE '2024' END, '-', LPAD(300 + i, 5, '0')),
       2000 + i, 2000 + i,
       ELT(1 + MOD(i * 5, 12), 'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Castro', 'Dizon'),
       ELT(1 + MOD(i * 3, 10), 'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Grace', 'Paolo', 'Lea', 'Carlo', 'Joy'),
       DATE_ADD('2004-01-01', INTERVAL i * 11 DAY), 'Cotabato', 'Makilala', 'Poblacion', CONCAT('0917', LPAD(5560000 + i, 7, '0')),
       'freshman', 'active'
FROM `tmp_numbers` WHERE i <= 107;

INSERT INTO `Enrollment` (`enrollment_id`, `student_id`, `term_id`, `curriculum_id`, `section_id`, `year_level`, `date_enrolled`, `status`, `approved_by`, `student_standing`)
SELECT 2000 + i, 2000 + i, 2, 3,
       CASE WHEN i <= 34 THEN 14 WHEN i <= 60 THEN 15 WHEN i <= 87 THEN 16 ELSE 17 END,
       CASE WHEN i <= 60 THEN 1 WHEN i <= 87 THEN 2 ELSE 3 END,
       '2027-01-15 09:00:00', 'approved', 2, 'regular'
FROM `tmp_numbers` WHERE i <= 107;

-- 4. 40 validated freshman applicants waiting for placement into the year 1 sections (place them after year 1 classes exist)
INSERT INTO `Admission_Application` (`application_id`, `applicant_last_name`, `applicant_first_name`, `birthdate`, `applicant_province`, `applicant_municipality`, `applicant_barangay`, `contact_no`, `email_address`, `student_type`, `program_id`, `application_date`, `status`, `validated_by`, `evaluated_year_level`, `date_validated`)
SELECT 3000 + i,
       ELT(1 + MOD(i * 7, 12), 'Lopez', 'Navarro', 'Tan', 'Domingo', 'Pascual', 'Villanueva', 'Ramirez', 'Castillo', 'Santiago', 'Abad', 'Mercado', 'Salazar'),
       ELT(1 + MOD(i * 3, 10), 'Rey', 'Anna', 'Luis', 'Mae', 'Dan', 'Jen', 'Kyle', 'Pia', 'Noel', 'Ella'),
       DATE_ADD('2009-01-01', INTERVAL i * 17 DAY), 'Cotabato', 'Makilala', 'Poblacion', CONCAT('0917', LPAD(5570000 + i, 7, '0')),
       CONCAT('applicant', 100 + i, '@mist.test'), 'freshman', 1, '2027-05-10', 'validated', 7, 1, '2027-05-20'
FROM `tmp_numbers` WHERE i <= 40;

DROP TABLE `tmp_numbers`;

-- 5. Check
SELECT 'Teachers in BSIS (20)' AS item, COUNT(*) AS n FROM `Teacher` WHERE `department_id` = 1
UNION ALL SELECT 'BSIS sections (11, with TEST-A)', COUNT(*) FROM `Section` WHERE `program_id` = 1
UNION ALL SELECT 'Students in B 2026 (34)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 14
UNION ALL SELECT 'Students in C 2026 (26)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 15
UNION ALL SELECT 'Students in B 2025 (27)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 16
UNION ALL SELECT 'Students in B 2024 (20)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 17
UNION ALL SELECT 'BSIS students in all sections (189)', COUNT(*) FROM `Enrollment` WHERE `section_id` BETWEEN 10 AND 17
UNION ALL SELECT 'Validated applicants not placed (40)', COUNT(*) FROM `Admission_Application` a WHERE a.`status` = 'validated' AND a.`program_id` = 1 AND NOT EXISTS (SELECT 1 FROM `Student` s WHERE s.`application_id` = a.`application_id`);
