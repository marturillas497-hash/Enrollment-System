-- MIST local TEST seed: Auto-generate Classes (real BSIS curriculum)
-- Import AFTER seed_test.sql. It adds to that data and does not wipe anything.
-- To run it again, import seed_test.sql first (that resets the database), then this file.
-- Registrar for the BSIS department: alcantara_ramon. Every account uses the password mist2027.
-- Curriculum: BSIS 2023 (CMO 25 s. 2015, effective SY 2023-2024), 49 subjects.
-- Teach years for term 2027-2028 S1: A 2027 and B 2027 are year 1, A 2026 is year 2, A 2025 is year 3, A 2024 is year 4.
-- Deliberate traps: Aquino (PE) has a 4 unit cap for 3 PE classes, Dizon has no tags, Reyes is deactivated,
-- PolSci has no tags (no teacher can be matched), CAP 102 and PRAC 101 have no hours (TBA), T-301 is inactive,
-- T-LAB1 and NET-1 seat 30 and three sections have more than 30 students.
SET NAMES utf8mb4;

-- 1. Closed term that holds the students' last enrollment
INSERT INTO `School_term` (`term_id`, `school_year`, `semester`, `status`, `closed_by`, `date_closed`) VALUES
(2, '2026-2027', 2, 'closed', 1, '2027-05-30 17:00:00');

-- 2. Teachers (BSIS department)
INSERT INTO `Accounts` (`account_id`, `username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`) VALUES
(101, 'santos_mark', 'santos@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(102, 'villanueva_grace', 'villanueva@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(103, 'ramirez_joel', 'ramirez@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(104, 'castillo_anna', 'castillo@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(105, 'mendoza_rico', 'mendoza@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(106, 'flores_liza', 'flores@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(107, 'aquino_ben', 'aquino@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(108, 'dizon_carlo', 'dizon@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(109, 'reyes_tess', 'reyes@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 0, '2026-09-01 08:00:00'),
(110, 'torres_nina', 'torres@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00');

INSERT INTO `Teacher` (`teacher_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `max_units`, `max_hours_per_week`) VALUES
(101, 101, 'Santos', 'Mark', NULL, NULL, 1, 24, 30.0),
(102, 102, 'Villanueva', 'Grace', NULL, NULL, 1, 24, 30.0),
(103, 103, 'Ramirez', 'Joel', NULL, NULL, 1, 12, 18.0),
(104, 104, 'Castillo', 'Anna', NULL, NULL, 1, 24, 30.0),
(105, 105, 'Mendoza', 'Rico', NULL, NULL, 1, 24, 30.0),
(106, 106, 'Flores', 'Liza', NULL, NULL, 1, 24, 30.0),
(107, 107, 'Aquino', 'Ben', NULL, NULL, 1, 4, 10.0),
(108, 108, 'Dizon', 'Carlo', NULL, NULL, 1, 24, 30.0),
(109, 109, 'Reyes', 'Tess', NULL, NULL, 1, 24, 30.0),
(110, 110, 'Torres', 'Nina', NULL, NULL, 1, 24, 30.0);

-- 3. Tags: 1 Programming, 2 Database, 3 Mathematics, 4 Social Science already exist
INSERT INTO `Tag` (`tag_id`, `tag_name`) VALUES
(5, 'Networking'),
(6, 'Web Development'),
(7, 'Systems Analysis'),
(8, 'Management'),
(9, 'English'),
(10, 'Physical Education'),
(11, 'Research'),
(12, 'Information Systems'),
(13, 'Accounting'),
(14, 'Humanities'),
(15, 'Science and Technology'),
(16, 'NSTP');

INSERT INTO `Teacher_tag` (`teacher_id`, `tag_id`) VALUES
(101, 1), (101, 6), (102, 12), (102, 7), (102, 2), (103, 5), (104, 3), (105, 4), (105, 9), (105, 14), (106, 8), (106, 11), (106, 13), (107, 10), (109, 1), (110, 15), (110, 16);

-- 4. Rooms (existing: 1 to 8, types 1 Lecture Room, 2 Computer Laboratory, 3 Networking and Hardware Lab, 15 Gymnasium)
INSERT INTO `Room` (`room_id`, `room_code`, `room_type_id`, `capacity`, `is_active`) VALUES
(9, 'T-LAB2', 2, 40, 1),
(10, 'T-LAB3', 2, 35, 1),
(11, 'NET-1', 3, 30, 1),
(12, 'GYM-1', 15, 60, 1),
(13, 'T-301', 1, 40, 0);

-- 5. BSIS 2023 curriculum: subjects, tags, weekly parts, prerequisites and year standing
INSERT INTO `Subject` (`subject_id`, `subject_code`, `subject_name`, `subject_description`, `units`, `year_level`) VALUES
(101, 'CC 101', 'Introduction to Computing', 'BSIS 2023 curriculum', 3, 1),
(102, 'CC 102', 'Computer Programming 1', 'BSIS 2023 curriculum', 3, 1),
(103, 'GE 4', 'Mathematics in the Modern World', 'BSIS 2023 curriculum', 3, 1),
(104, 'GE 1', 'Understanding the Self', 'BSIS 2023 curriculum', 3, 1),
(105, 'GE 7', 'Science, Technology and Society', 'BSIS 2023 curriculum', 3, 1),
(106, 'GE Elec 2', 'The Entrepreneurial Mind', 'BSIS 2023 curriculum', 3, 1),
(107, 'PE 1', 'PATHFit 1 Movement Competency Training', 'BSIS 2023 curriculum', 2, 1),
(108, 'NSTP 1', 'National Service Training Program 1', 'BSIS 2023 curriculum', 3, 1),
(109, 'IS 101', 'Fundamentals of Information Systems', 'BSIS 2023 curriculum', 3, 1),
(110, 'CC 103', 'Computer Programming 2', 'BSIS 2023 curriculum', 3, 1),
(111, 'GE 2', 'Readings in Philippine History', 'BSIS 2023 curriculum', 3, 1),
(112, 'GE 5', 'Purposive Communication', 'BSIS 2023 curriculum', 3, 1),
(113, 'WEB DEV', 'Web Development', 'BSIS 2023 curriculum', 3, 1),
(114, 'GE Elec 1', 'Living in the IT Era', 'BSIS 2023 curriculum', 3, 1),
(115, 'PE 2', 'PATHFit 2 Exercise-based Fitness Activities', 'BSIS 2023 curriculum', 2, 1),
(116, 'NSTP 2', 'National Service Training Program 2', 'BSIS 2023 curriculum', 3, 1),
(117, 'CC 104', 'Data Structures & Algorithms', 'BSIS 2023 curriculum', 3, 2),
(118, 'IS 102', 'Professional Issues in Information Systems', 'BSIS 2023 curriculum', 3, 2),
(119, 'IS 103', 'IT Infrastructure & Network Technologies', 'BSIS 2023 curriculum', 3, 2),
(120, 'DM 101', 'Organization & Management Concepts', 'BSIS 2023 curriculum', 3, 2),
(121, 'Lit 1', 'Philippine Literature', 'BSIS 2023 curriculum', 3, 2),
(122, 'GE 6', 'Arts Appreciation', 'BSIS 2023 curriculum', 3, 2),
(123, 'PolSci', 'Philippine Government and Constitution', 'BSIS 2023 curriculum', 3, 2),
(124, 'PE 3', 'PATHFit 3 Dance', 'BSIS 2023 curriculum', 2, 2),
(125, 'IS 104', 'Systems Analysis & Design', 'BSIS 2023 curriculum', 3, 2),
(126, 'DM 102', 'Financial Management', 'BSIS 2023 curriculum', 3, 2),
(127, 'PROFEL 1', 'IS Innovations & New Technologies', 'BSIS 2023 curriculum', 3, 2),
(128, 'Math 4', 'Probability & Statistics', 'BSIS 2023 curriculum', 3, 2),
(129, 'CHF', 'Computer Hardware Fundamentals', 'BSIS 2023 curriculum', 3, 2),
(130, 'GE 3', 'The Contemporary World', 'BSIS 2023 curriculum', 3, 2),
(131, 'Acctg 1', 'Principle of Accounting', 'BSIS 2023 curriculum', 3, 2),
(132, 'PE 4', 'PATHFit 4 Sports', 'BSIS 2023 curriculum', 2, 2),
(133, 'CC 105', 'Information Management', 'BSIS 2023 curriculum', 3, 3),
(134, 'IS 105', 'Enterprise Architecture', 'BSIS 2023 curriculum', 3, 3),
(135, 'DM 103', 'Business Process Management', 'BSIS 2023 curriculum', 3, 3),
(136, 'QUAMET', 'Quantitative Methods', 'BSIS 2023 curriculum', 3, 3),
(137, 'PROFEL 2', 'Human Computer Interaction', 'BSIS 2023 curriculum', 3, 3),
(138, 'Rizal', 'Life and Works of Rizal', 'BSIS 2023 curriculum', 3, 3),
(139, 'IS 106', 'IS Project Management 1', 'BSIS 2023 curriculum', 3, 3),
(140, 'DM 104', 'Evaluation of Business Performance', 'BSIS 2023 curriculum', 3, 3),
(141, 'CAP 101', 'Capstone Project 1', 'BSIS 2023 curriculum', 3, 3),
(142, 'PROFEL 3', 'Data Mining', 'BSIS 2023 curriculum', 3, 3),
(143, 'GE 8', 'Ethics', 'BSIS 2023 curriculum', 3, 3),
(144, 'GV', 'Graphics and Visual Computing', 'BSIS 2023 curriculum', 3, 3),
(145, 'IS 107', 'IS Strategy Management & Acquisition', 'BSIS 2023 curriculum', 3, 4),
(146, 'CC 106', 'Application Development & Emerging Technologies', 'BSIS 2023 curriculum', 3, 4),
(147, 'CAP 102', 'Capstone Project 2', 'BSIS 2023 curriculum', 3, 4),
(148, 'PROFEL 4', 'IT Security and Management', 'BSIS 2023 curriculum', 3, 4),
(149, 'PRAC 101', 'Practicum for Information Systems (486 Hrs)', 'BSIS 2023 curriculum', 6, 4);

INSERT INTO `Subject_tag` (`subject_id`, `tag_id`) VALUES
(101, 12), (102, 1), (103, 3), (104, 4), (105, 15), (106, 8), (107, 10), (108, 16), (109, 12), (110, 1), (111, 4), (112, 9), (113, 1), (113, 6), (114, 12), (115, 10), (116, 16), (117, 1), (118, 12), (119, 5), (120, 8), (121, 14), (122, 14), (124, 10), (125, 7), (126, 13), (127, 12), (128, 3), (129, 5), (130, 4), (131, 13), (132, 10), (133, 2), (134, 7), (135, 8), (136, 3), (137, 6), (137, 12), (138, 4), (139, 8), (140, 8), (141, 11), (142, 2), (143, 4), (144, 6), (145, 12), (145, 8), (146, 1), (147, 11), (148, 5);

-- Weekly parts: 3 lecture units = 1.5 h x 2, 2 lecture + 1 lab = 2 h lecture + 3 h lab, PE = 2 h in the gym, NSTP = 3 h, TBA = no hours
INSERT INTO `Subject_requirement` (`subject_id`, `part_kind`, `hours_per_meeting`, `meetings_per_week`, `preferred_room_type_id`, `fallback_room_type_id`) VALUES
(101, 'Lecture', 2.0, 1, 1, NULL),
(101, 'Laboratory', 3.0, 1, 2, NULL),
(102, 'Lecture', 2.0, 1, 1, NULL),
(102, 'Laboratory', 3.0, 1, 2, NULL),
(103, 'Lecture', 1.5, 2, 1, NULL),
(104, 'Lecture', 1.5, 2, 1, NULL),
(105, 'Lecture', 1.5, 2, 1, NULL),
(106, 'Lecture', 1.5, 2, 1, NULL),
(107, 'Activity', 2.0, 1, 15, NULL),
(108, 'Lecture', 3.0, 1, 1, NULL),
(109, 'Lecture', 1.5, 2, 1, NULL),
(110, 'Lecture', 2.0, 1, 1, NULL),
(110, 'Laboratory', 3.0, 1, 2, NULL),
(111, 'Lecture', 1.5, 2, 1, NULL),
(112, 'Lecture', 1.5, 2, 1, NULL),
(113, 'Lecture', 2.0, 1, 1, NULL),
(113, 'Laboratory', 3.0, 1, 2, NULL),
(114, 'Lecture', 1.5, 2, 1, NULL),
(115, 'Activity', 2.0, 1, 15, NULL),
(116, 'Lecture', 3.0, 1, 1, NULL),
(117, 'Lecture', 2.0, 1, 1, NULL),
(117, 'Laboratory', 3.0, 1, 2, NULL),
(118, 'Lecture', 1.5, 2, 1, NULL),
(119, 'Lecture', 2.0, 1, 1, NULL),
(119, 'Laboratory', 3.0, 1, 3, 2),
(120, 'Lecture', 1.5, 2, 1, NULL),
(121, 'Lecture', 1.5, 2, 1, NULL),
(122, 'Lecture', 1.5, 2, 1, NULL),
(123, 'Lecture', 1.5, 2, 1, NULL),
(124, 'Activity', 2.0, 1, 15, NULL),
(125, 'Lecture', 2.0, 1, 1, NULL),
(125, 'Laboratory', 3.0, 1, 2, NULL),
(126, 'Lecture', 1.5, 2, 1, NULL),
(127, 'Lecture', 2.0, 1, 1, NULL),
(127, 'Laboratory', 3.0, 1, 2, NULL),
(128, 'Lecture', 1.5, 2, 1, NULL),
(129, 'Lecture', 2.0, 1, 1, NULL),
(129, 'Laboratory', 3.0, 1, 3, 2),
(130, 'Lecture', 1.5, 2, 1, NULL),
(131, 'Lecture', 1.5, 2, 1, NULL),
(132, 'Activity', 2.0, 1, 15, NULL),
(133, 'Lecture', 2.0, 1, 1, NULL),
(133, 'Laboratory', 3.0, 1, 2, NULL),
(134, 'Lecture', 2.0, 1, 1, NULL),
(134, 'Laboratory', 3.0, 1, 2, NULL),
(135, 'Lecture', 1.5, 2, 1, NULL),
(136, 'Lecture', 1.5, 2, 1, NULL),
(137, 'Lecture', 2.0, 1, 1, NULL),
(137, 'Laboratory', 3.0, 1, 2, NULL),
(138, 'Lecture', 1.5, 2, 1, NULL),
(139, 'Lecture', 1.5, 2, 1, NULL),
(140, 'Lecture', 1.5, 2, 1, NULL),
(141, 'Lecture', 1.5, 2, 1, NULL),
(142, 'Lecture', 2.0, 1, 1, NULL),
(142, 'Laboratory', 3.0, 1, 2, NULL),
(143, 'Lecture', 1.5, 2, 1, NULL),
(144, 'Lecture', 2.0, 1, 1, NULL),
(144, 'Laboratory', 3.0, 1, 2, NULL),
(145, 'Lecture', 1.5, 2, 1, NULL),
(146, 'Lecture', 2.0, 1, 1, NULL),
(146, 'Laboratory', 3.0, 1, 2, NULL),
(147, 'Consultation', 0, 1, NULL, NULL),
(148, 'Lecture', 2.0, 1, 1, NULL),
(148, 'Laboratory', 3.0, 1, 3, 2),
(149, 'Practicum', 0, 1, NULL, NULL);

INSERT INTO `Curriculum` (`curriculum_id`, `program_id`, `curriculum_name`, `effective_year`, `is_active`) VALUES
(3, 1, 'BSIS 2023', 2023, 1);

INSERT INTO `Curriculum_subject` (`curriculum_id`, `subject_id`, `year_level`, `semester`) VALUES
(3, 101, 1, 1),
(3, 102, 1, 1),
(3, 103, 1, 1),
(3, 104, 1, 1),
(3, 105, 1, 1),
(3, 106, 1, 1),
(3, 107, 1, 1),
(3, 108, 1, 1),
(3, 109, 1, 2),
(3, 110, 1, 2),
(3, 111, 1, 2),
(3, 112, 1, 2),
(3, 113, 1, 2),
(3, 114, 1, 2),
(3, 115, 1, 2),
(3, 116, 1, 2),
(3, 117, 2, 1),
(3, 118, 2, 1),
(3, 119, 2, 1),
(3, 120, 2, 1),
(3, 121, 2, 1),
(3, 122, 2, 1),
(3, 123, 2, 1),
(3, 124, 2, 1),
(3, 125, 2, 2),
(3, 126, 2, 2),
(3, 127, 2, 2),
(3, 128, 2, 2),
(3, 129, 2, 2),
(3, 130, 2, 2),
(3, 131, 2, 2),
(3, 132, 2, 2),
(3, 133, 3, 1),
(3, 134, 3, 1),
(3, 135, 3, 1),
(3, 136, 3, 1),
(3, 137, 3, 1),
(3, 138, 3, 1),
(3, 139, 3, 2),
(3, 140, 3, 2),
(3, 141, 3, 2),
(3, 142, 3, 2),
(3, 143, 3, 2),
(3, 144, 3, 2),
(3, 145, 4, 1),
(3, 146, 4, 1),
(3, 147, 4, 1),
(3, 148, 4, 1),
(3, 149, 4, 2);
UPDATE `Curriculum_subject` SET `required_standing` = 2 WHERE `curriculum_id` = 3 AND `subject_id` IN (125, 127);
UPDATE `Curriculum_subject` SET `required_standing` = 3 WHERE `curriculum_id` = 3 AND `subject_id` IN (136, 137, 141, 142);
UPDATE `Curriculum_subject` SET `required_standing` = 4 WHERE `curriculum_id` = 3 AND `subject_id` IN (146, 147, 148, 149);

INSERT INTO `Curriculum_prerequisite` (`curriculum_id`, `subject_id`, `prerequisite_subject_id`) VALUES
(3, 109, 101),
(3, 110, 102),
(3, 115, 107),
(3, 116, 108),
(3, 117, 110),
(3, 118, 101),
(3, 119, 101),
(3, 120, 101),
(3, 124, 115),
(3, 126, 120),
(3, 132, 115),
(3, 133, 117),
(3, 134, 119),
(3, 135, 126),
(3, 139, 125),
(3, 140, 135),
(3, 145, 139);

-- 6. Sections. Sections 8 and 9 have no students yet, so the generator uses max_slots as their size.
INSERT INTO `Section` (`section_id`, `section_name`, `year_level`, `program_id`, `curriculum_id`, `max_slots`) VALUES
(8, 'A 2027', 1, 1, 3, 35),
(9, 'B 2027', 1, 1, 3, 30),
(10, 'A 2026', 1, 1, 3, 35),
(11, 'A 2025', 2, 1, 3, 35),
(12, 'A 2024', 3, 1, 3, 30);

-- 7. 82 students whose last enrollment was 2026-2027 S2: 32 in A 2026, 28 in A 2025, 22 in A 2024
CREATE TABLE `tmp_numbers` (`i` INT NOT NULL PRIMARY KEY);
INSERT INTO `tmp_numbers` (`i`)
SELECT a.d * 10 + b.d + 1
FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b;

INSERT INTO `Accounts` (`account_id`, `username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`)
SELECT 1000 + i,
       CONCAT(CASE WHEN i <= 32 THEN '2026' WHEN i <= 60 THEN '2025' ELSE '2024' END, '-', LPAD(100 + i, 5, '0')),
       CONCAT('bsis', 100 + i, '@mist.test'),
       '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00'
FROM `tmp_numbers` WHERE i <= 82;

INSERT INTO `Admission_Application` (`application_id`, `applicant_last_name`, `applicant_first_name`, `birthdate`, `applicant_province`, `applicant_municipality`, `applicant_barangay`, `contact_no`, `email_address`, `student_type`, `program_id`, `application_date`, `status`, `validated_by`, `evaluated_year_level`, `date_validated`)
SELECT 1000 + i,
       ELT(1 + MOD(i * 7, 12), 'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Castro', 'Dizon'),
       ELT(1 + MOD(i, 10), 'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Grace', 'Paolo', 'Lea', 'Carlo', 'Joy'),
       DATE_ADD('2004-01-01', INTERVAL i * 13 DAY), 'Cotabato', 'Makilala', 'Poblacion', CONCAT('0917', LPAD(5550000 + i, 7, '0')),
       CONCAT('bsis', 100 + i, '@mist.test'), 'freshman', 1, '2025-06-01', 'validated', 7, 1, '2025-06-10'
FROM `tmp_numbers` WHERE i <= 82;

INSERT INTO `Student` (`student_id`, `student_id_number`, `account_id`, `application_id`, `last_name`, `first_name`, `birthdate`, `province`, `municipality`, `barangay`, `contact_no`, `student_type`, `overall_status`)
SELECT 1000 + i,
       CONCAT(CASE WHEN i <= 32 THEN '2026' WHEN i <= 60 THEN '2025' ELSE '2024' END, '-', LPAD(100 + i, 5, '0')),
       1000 + i, 1000 + i,
       ELT(1 + MOD(i * 7, 12), 'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Aquino', 'Castro', 'Dizon'),
       ELT(1 + MOD(i, 10), 'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Grace', 'Paolo', 'Lea', 'Carlo', 'Joy'),
       DATE_ADD('2004-01-01', INTERVAL i * 13 DAY), 'Cotabato', 'Makilala', 'Poblacion', CONCAT('0917', LPAD(5550000 + i, 7, '0')),
       'freshman', 'active'
FROM `tmp_numbers` WHERE i <= 82;

INSERT INTO `Enrollment` (`enrollment_id`, `student_id`, `term_id`, `curriculum_id`, `section_id`, `year_level`, `date_enrolled`, `status`, `approved_by`, `student_standing`)
SELECT 1000 + i, 1000 + i, 2, 3,
       CASE WHEN i <= 32 THEN 10 WHEN i <= 60 THEN 11 ELSE 12 END,
       CASE WHEN i <= 32 THEN 1 WHEN i <= 60 THEN 2 ELSE 3 END,
       '2027-01-15 09:00:00', 'approved', 2, 'regular'
FROM `tmp_numbers` WHERE i <= 82;

DROP TABLE `tmp_numbers`;

-- 8. Check
SELECT 'Teachers in BSIS' AS item, COUNT(*) AS n FROM `Teacher` WHERE `department_id` = 1
UNION ALL SELECT 'Rooms', COUNT(*) FROM `Room`
UNION ALL SELECT 'BSIS 2023 subjects (49)', COUNT(*) FROM `Curriculum_subject` WHERE `curriculum_id` = 3
UNION ALL SELECT 'Students in A 2026 (32)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 10
UNION ALL SELECT 'Students in A 2025 (28)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 11
UNION ALL SELECT 'Students in A 2024 (22)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 12;
