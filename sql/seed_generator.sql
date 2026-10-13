-- MIST local TEST seed: Auto-generate Classes
-- Import AFTER seed_test.sql. It adds to that data and does not wipe anything.
-- To run it again, import seed_test.sql first (that resets the database), then this file.
-- Registrar for the BSIS department: alcantara_ramon. Every account uses the password mist2027.
-- Teach years for term 2027-2028 S1: A 2027 and B 2027 are year 1, A 2026 is year 2, A 2025 is year 3, A 2024 is year 4.
-- Deliberate traps: Aquino (PE) has a 4 unit cap for 3 PE classes, Dizon has no tags, Reyes is deactivated,
-- IS 404 has no tags and no hours (TBA), T-301 is inactive, T-LAB1 seats 30 and three sections have more than 30.
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
(109, 'reyes_tess', 'reyes@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 0, '2026-09-01 08:00:00');

INSERT INTO `Teacher` (`teacher_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `max_units`, `max_hours_per_week`) VALUES
(101, 101, 'Santos', 'Mark', NULL, NULL, 1, 24, 30.0),
(102, 102, 'Villanueva', 'Grace', NULL, NULL, 1, 24, 30.0),
(103, 103, 'Ramirez', 'Joel', NULL, NULL, 1, 12, 18.0),
(104, 104, 'Castillo', 'Anna', NULL, NULL, 1, 24, 30.0),
(105, 105, 'Mendoza', 'Rico', NULL, NULL, 1, 24, 30.0),
(106, 106, 'Flores', 'Liza', NULL, NULL, 1, 24, 30.0),
(107, 107, 'Aquino', 'Ben', NULL, NULL, 1, 4, 10.0),
(108, 108, 'Dizon', 'Carlo', NULL, NULL, 1, 24, 30.0),
(109, 109, 'Reyes', 'Tess', NULL, NULL, 1, 24, 30.0);

-- 3. Tags: 1 Programming, 2 Database, 3 Mathematics, 4 Social Science already exist
INSERT INTO `Tag` (`tag_id`, `tag_name`) VALUES
(5, 'Networking'),
(6, 'Web Development'),
(7, 'Systems Analysis'),
(8, 'Management'),
(9, 'English'),
(10, 'Physical Education'),
(11, 'Research');

INSERT INTO `Teacher_tag` (`teacher_id`, `tag_id`) VALUES
(101, 1), (101, 6),
(102, 2), (102, 7),
(103, 5),
(104, 3),
(105, 4), (105, 9),
(106, 7), (106, 8), (106, 11),
(107, 10),
(109, 1);

-- 4. Rooms (existing: 1 to 8, types 1 Lecture Room, 2 Computer Laboratory, 3 Networking and Hardware Lab, 15 Gymnasium)
INSERT INTO `Room` (`room_id`, `room_code`, `room_type_id`, `capacity`, `is_active`) VALUES
(9, 'T-LAB2', 2, 40, 1),
(10, 'T-LAB3', 2, 35, 1),
(11, 'NET-1', 3, 30, 1),
(12, 'GYM-1', 15, 60, 1),
(13, 'T-301', 1, 40, 0);

-- 5. Subjects. GE 101 (1), GE 102 (5) and GE 103 (7) already exist and are shared with BSM.
INSERT INTO `Subject` (`subject_id`, `subject_code`, `subject_name`, `subject_description`, `units`, `year_level`) VALUES
(101, 'IS 101', 'Introduction to Information Systems', 'BSIS sample subject', 3, 1),
(102, 'IS 102', 'Computer Programming 1', 'BSIS sample subject', 3, 1),
(103, 'IS 103', 'Computer Hardware and Networking Basics', 'BSIS sample subject', 3, 1),
(104, 'PE 101', 'Physical Fitness and Health', 'BSIS sample subject', 2, 1),
(105, 'IS 104', 'Computer Programming 2', 'BSIS sample subject', 3, 1),
(106, 'IS 105', 'Discrete Mathematics', 'BSIS sample subject', 3, 1),
(107, 'IS 106', 'Data Communications', 'BSIS sample subject', 3, 1),
(108, 'PE 102', 'Rhythmic Activities', 'BSIS sample subject', 2, 1),
(109, 'IS 201', 'Data Structures and Algorithms', 'BSIS sample subject', 3, 2),
(110, 'IS 202', 'Database Systems', 'BSIS sample subject', 3, 2),
(111, 'IS 203', 'Systems Analysis and Design', 'BSIS sample subject', 3, 2),
(112, 'IS 204', 'Web Development', 'BSIS sample subject', 3, 2),
(113, 'PE 201', 'Individual and Dual Sports', 'BSIS sample subject', 2, 2),
(114, 'GE 201', 'Ethics', 'BSIS sample subject', 3, 2),
(115, 'IS 301', 'IS Strategy, Management and Acquisition', 'BSIS sample subject', 3, 3),
(116, 'IS 302', 'Network Administration', 'BSIS sample subject', 3, 3),
(117, 'IS 303', 'Software Engineering', 'BSIS sample subject', 3, 3),
(118, 'IS 304', 'Business Intelligence', 'BSIS sample subject', 3, 3),
(119, 'IS 305', 'Research Methods', 'BSIS sample subject', 3, 3),
(120, 'IS 401', 'IS Project Management', 'BSIS sample subject', 3, 4),
(121, 'IS 402', 'Capstone Project 1', 'BSIS sample subject', 3, 4),
(122, 'IS 403', 'Enterprise Architecture', 'BSIS sample subject', 3, 4),
(123, 'IS 404', 'On-the-Job Training', 'BSIS sample subject, no tags and no hours on purpose', 6, 4);

INSERT INTO `Subject_tag` (`subject_id`, `tag_id`) VALUES
(1, 4), (5, 9), (7, 3),
(101, 7), (102, 1), (103, 5), (104, 10),
(105, 1), (106, 3), (107, 5), (108, 10),
(109, 1), (110, 2), (111, 7), (112, 1), (112, 6), (113, 10), (114, 4),
(115, 8), (116, 5), (117, 1), (118, 2), (119, 11),
(120, 8), (121, 11), (122, 7);

-- Weekly parts: lecture 1.5 h x 2, lecture 1.5 h + computer lab 3 h, lecture 1.5 h + networking lab 3 h, PE 2 h in the gym
INSERT INTO `Subject_requirement` (`subject_id`, `part_kind`, `hours_per_meeting`, `meetings_per_week`, `preferred_room_type_id`, `fallback_room_type_id`) VALUES
(1, 'Lecture', 1.5, 2, 1, NULL),
(5, 'Lecture', 1.5, 2, 1, NULL),
(7, 'Lecture', 1.5, 2, 1, NULL),
(101, 'Lecture', 1.5, 2, 1, NULL),
(106, 'Lecture', 1.5, 2, 1, NULL),
(111, 'Lecture', 1.5, 2, 1, NULL),
(114, 'Lecture', 1.5, 2, 1, NULL),
(115, 'Lecture', 1.5, 2, 1, NULL),
(119, 'Lecture', 1.5, 2, 1, NULL),
(120, 'Lecture', 1.5, 2, 1, NULL),
(122, 'Lecture', 1.5, 2, 1, NULL),
(102, 'Lecture', 1.5, 1, 1, NULL), (102, 'Laboratory', 3.0, 1, 2, NULL),
(105, 'Lecture', 1.5, 1, 1, NULL), (105, 'Laboratory', 3.0, 1, 2, NULL),
(109, 'Lecture', 1.5, 1, 1, NULL), (109, 'Laboratory', 3.0, 1, 2, NULL),
(110, 'Lecture', 1.5, 1, 1, NULL), (110, 'Laboratory', 3.0, 1, 2, NULL),
(112, 'Lecture', 1.5, 1, 1, NULL), (112, 'Laboratory', 3.0, 1, 2, NULL),
(117, 'Lecture', 1.5, 1, 1, NULL), (117, 'Laboratory', 3.0, 1, 2, NULL),
(118, 'Lecture', 1.5, 1, 1, NULL), (118, 'Laboratory', 3.0, 1, 2, NULL),
(103, 'Lecture', 1.5, 1, 1, NULL), (103, 'Laboratory', 3.0, 1, 3, 2),
(107, 'Lecture', 1.5, 1, 1, NULL), (107, 'Laboratory', 3.0, 1, 3, 2),
(116, 'Lecture', 1.5, 1, 1, NULL), (116, 'Laboratory', 3.0, 1, 3, 2),
(104, 'Activity', 2.0, 1, 15, NULL),
(108, 'Activity', 2.0, 1, 15, NULL),
(113, 'Activity', 2.0, 1, 15, NULL),
(121, 'Lecture', 2.0, 1, 1, NULL),
(123, 'On-the-job', 0, 1, NULL, NULL);

-- 6. BSIS 2027 curriculum
INSERT INTO `Curriculum` (`curriculum_id`, `program_id`, `curriculum_name`, `effective_year`, `is_active`) VALUES
(3, 1, 'BSIS 2027', 2027, 1);

INSERT INTO `Curriculum_subject` (`curriculum_id`, `subject_id`, `year_level`, `semester`) VALUES
(3, 1, 1, 1), (3, 7, 1, 1), (3, 101, 1, 1), (3, 102, 1, 1), (3, 103, 1, 1), (3, 104, 1, 1),
(3, 5, 1, 2), (3, 105, 1, 2), (3, 106, 1, 2), (3, 107, 1, 2), (3, 108, 1, 2),
(3, 109, 2, 1), (3, 110, 2, 1), (3, 111, 2, 1), (3, 112, 2, 1), (3, 113, 2, 1), (3, 114, 2, 1),
(3, 115, 3, 1), (3, 116, 3, 1), (3, 117, 3, 1), (3, 118, 3, 1), (3, 119, 3, 1),
(3, 120, 4, 1), (3, 121, 4, 1), (3, 122, 4, 1), (3, 123, 4, 1);

INSERT INTO `Curriculum_prerequisite` (`curriculum_id`, `subject_id`, `prerequisite_subject_id`) VALUES
(3, 105, 102),
(3, 109, 105),
(3, 110, 101),
(3, 117, 109);

-- 7. Sections. Sections 8 and 9 have no students yet, so the generator uses max_slots as their size.
INSERT INTO `Section` (`section_id`, `section_name`, `year_level`, `program_id`, `curriculum_id`, `max_slots`) VALUES
(8, 'A 2027', 1, 1, 3, 35),
(9, 'B 2027', 1, 1, 3, 30),
(10, 'A 2026', 1, 1, 3, 35),
(11, 'A 2025', 2, 1, 3, 35),
(12, 'A 2024', 3, 1, 3, 30);

-- 8. 82 students whose last enrollment was 2026-2027 S2: 32 in A 2026, 28 in A 2025, 22 in A 2024
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

-- 9. Check
SELECT 'Teachers in BSIS' AS item, COUNT(*) AS n FROM `Teacher` WHERE `department_id` = 1
UNION ALL SELECT 'Rooms', COUNT(*) FROM `Room`
UNION ALL SELECT 'BSIS 2027 subjects', COUNT(*) FROM `Curriculum_subject` WHERE `curriculum_id` = 3
UNION ALL SELECT 'Students in A 2026 (32)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 10
UNION ALL SELECT 'Students in A 2025 (28)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 11
UNION ALL SELECT 'Students in A 2024 (22)', COUNT(*) FROM `Enrollment` WHERE `section_id` = 12;
