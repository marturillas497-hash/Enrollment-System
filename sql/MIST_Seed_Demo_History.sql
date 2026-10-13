-- MIST demo history (localhost / XAMPP only). Run MIST_Seed_Demo.sql first, then this file once.
-- Adds 10 students of section 'A 2025' with graded classes in the two closed terms of 2025-2026, so section 'A 2025' will
-- suggest Year 2 in the generator, Class History and My Curriculum have data, and the irregular path can be tried.
-- Students log in with their student number and the password Demo#Pass2026
--
-- What the history contains:
--   student 7  failed GE 101 in Sem 1 (regular again in Sem 2, still has not passed GE 101)
--   student 8  dropped IS 106 in Sem 2 (so IS 207 stays locked)
--   student 9  failed IS 104 in Sem 2   -> irregular now
--   student 10 has an Incomplete in IS 105 in Sem 2 -> irregular now
--   students 1 to 6 passed everything -> regular
-- The ongoing term 2026-2027 Sem 1 has no classes. Generate them for 'A 2025' (Year 2) and then let the students enroll.

SELECT (SELECT COUNT(*) FROM subject) AS subjects, (SELECT COUNT(*) FROM student) AS students, (SELECT COUNT(*) FROM class_offering) AS classes;
-- subjects should be 47, students and classes 0.

START TRANSACTION;

INSERT INTO accounts (username, email, password_hash, role, must_change_password, session_version, is_active, activated_at) VALUES
('2025-00001', 'juan.delarosa@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00002', 'maria.mercado@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00003', 'carlo.navarro@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00004', 'angela.pascual@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00005', 'rafael.quizon@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00006', 'bianca.salvador@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00007', 'marco.tolentino@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00008', 'patricia.uy@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00009', 'joshua.valdez@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW()),
('2025-00010', 'kristine.zamora@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'student', 0, 0, 1, NOW());

INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 1, '2025-00001', account_id, NULL, 'Dela Rosa', 'Juan', '2007-02-11', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00001';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 2, '2025-00002', account_id, NULL, 'Mercado', 'Maria', '2007-03-12', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00002';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 3, '2025-00003', account_id, NULL, 'Navarro', 'Carlo', '2007-04-13', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00003';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 4, '2025-00004', account_id, NULL, 'Pascual', 'Angela', '2007-05-14', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00004';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 5, '2025-00005', account_id, NULL, 'Quizon', 'Rafael', '2007-06-15', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00005';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 6, '2025-00006', account_id, NULL, 'Salvador', 'Bianca', '2007-07-16', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00006';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 7, '2025-00007', account_id, NULL, 'Tolentino', 'Marco', '2007-08-17', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00007';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 8, '2025-00008', account_id, NULL, 'Uy', 'Patricia', '2007-09-18', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00008';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 9, '2025-00009', account_id, NULL, 'Valdez', 'Joshua', '2007-01-10', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00009';
INSERT INTO student (student_id, student_id_number, account_id, application_id, last_name, first_name, birthdate, province, municipality, student_type, overall_status)
SELECT 10, '2025-00010', account_id, NULL, 'Zamora', 'Kristine', '2007-02-11', 'Cotabato', 'Makilala', 'freshman', 'active' FROM accounts WHERE username = '2025-00010';

INSERT INTO class_offering (offering_id, subject_id, teacher_id, section_id, term_id, generation_run_id) VALUES
(1, 1, 8, 1, 1, NULL),
(2, 2, 5, 1, 1, NULL),
(3, 3, 7, 1, 1, NULL),
(4, 4, 1, 1, 1, NULL),
(5, 5, 2, 1, 1, NULL),
(6, 6, 7, 1, 1, NULL),
(7, 7, 10, 1, 1, NULL),
(8, 8, 8, 1, 1, NULL),
(9, 9, 8, 1, 2, NULL),
(10, 10, 8, 1, 2, NULL),
(11, 11, 1, 1, 2, NULL),
(12, 12, 5, 1, 2, NULL),
(13, 13, 3, 1, 2, NULL),
(14, 14, 4, 1, 2, NULL),
(15, 15, 10, 1, 2, NULL),
(16, 16, 8, 1, 2, NULL);

INSERT INTO class_meeting (offering_id, day_of_week, start_time, end_time, room_id) VALUES
(1, 'Monday', '07:30:00', '09:00:00', 1),
(1, 'Tuesday', '07:30:00', '09:00:00', 1),
(2, 'Wednesday', '07:30:00', '09:00:00', 1),
(2, 'Thursday', '07:30:00', '09:00:00', 1),
(3, 'Friday', '07:30:00', '09:00:00', 1),
(3, 'Monday', '09:00:00', '10:30:00', 1),
(4, 'Tuesday', '09:00:00', '11:00:00', 1),
(4, 'Wednesday', '12:30:00', '15:30:00', 8),
(5, 'Thursday', '09:00:00', '11:00:00', 1),
(5, 'Friday', '12:30:00', '15:30:00', 8),
(6, 'Monday', '12:30:00', '14:00:00', 1),
(6, 'Tuesday', '12:30:00', '14:00:00', 1),
(7, 'Wednesday', '15:30:00', '17:30:00', 12),
(8, 'Thursday', '12:30:00', '15:30:00', 1),
(9, 'Monday', '07:30:00', '09:00:00', 1),
(9, 'Tuesday', '07:30:00', '09:00:00', 1),
(10, 'Wednesday', '07:30:00', '09:00:00', 1),
(10, 'Thursday', '07:30:00', '09:00:00', 1),
(11, 'Friday', '07:30:00', '09:30:00', 1),
(11, 'Monday', '12:30:00', '15:30:00', 8),
(12, 'Tuesday', '09:00:00', '10:30:00', 1),
(12, 'Wednesday', '09:00:00', '10:30:00', 1),
(13, 'Thursday', '09:00:00', '10:30:00', 1),
(13, 'Friday', '09:30:00', '11:00:00', 1),
(14, 'Monday', '15:30:00', '17:30:00', 1),
(14, 'Tuesday', '12:30:00', '15:30:00', 11),
(15, 'Wednesday', '12:30:00', '14:30:00', 12),
(16, 'Thursday', '12:30:00', '15:30:00', 1);

INSERT INTO enrollment (enrollment_id, student_id, term_id, curriculum_id, section_id, year_level, date_enrolled, status, approved_by, student_standing) VALUES
(1, 1, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(2, 2, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(3, 3, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(4, 4, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(5, 5, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(6, 6, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(7, 7, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'irregular'),
(8, 8, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(9, 9, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(10, 10, 1, 1, 1, 1, '2025-08-18 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(11, 1, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(12, 2, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(13, 3, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(14, 4, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(15, 5, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(16, 6, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(17, 7, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(18, 8, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'regular'),
(19, 9, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'irregular'),
(20, 10, 2, 1, 1, 1, '2026-01-12 09:00:00', 'approved', (SELECT account_id FROM accounts WHERE username = 'nico_reg'), 'irregular');

INSERT INTO enrolled_subject (enrolled_subject_id, enrollment_id, offering_id, grade, remarks) VALUES
(1, 1, 1, 1.25, 'Passed'),
(2, 1, 2, 2.0, 'Passed'),
(3, 1, 3, 2.75, 'Passed'),
(4, 1, 4, 1.5, 'Passed'),
(5, 1, 5, 2.25, 'Passed'),
(6, 1, 6, 3.0, 'Passed'),
(7, 1, 7, 1.75, 'Passed'),
(8, 1, 8, 2.5, 'Passed'),
(9, 2, 1, 2.5, 'Passed'),
(10, 2, 2, 1.25, 'Passed'),
(11, 2, 3, 2.0, 'Passed'),
(12, 2, 4, 2.75, 'Passed'),
(13, 2, 5, 1.5, 'Passed'),
(14, 2, 6, 2.25, 'Passed'),
(15, 2, 7, 3.0, 'Passed'),
(16, 2, 8, 1.75, 'Passed'),
(17, 3, 1, 1.75, 'Passed'),
(18, 3, 2, 2.5, 'Passed'),
(19, 3, 3, 1.25, 'Passed'),
(20, 3, 4, 2.0, 'Passed'),
(21, 3, 5, 2.75, 'Passed'),
(22, 3, 6, 1.5, 'Passed'),
(23, 3, 7, 2.25, 'Passed'),
(24, 3, 8, 3.0, 'Passed'),
(25, 4, 1, 3.0, 'Passed'),
(26, 4, 2, 1.75, 'Passed'),
(27, 4, 3, 2.5, 'Passed'),
(28, 4, 4, 1.25, 'Passed'),
(29, 4, 5, 2.0, 'Passed'),
(30, 4, 6, 2.75, 'Passed'),
(31, 4, 7, 1.5, 'Passed'),
(32, 4, 8, 2.25, 'Passed'),
(33, 5, 1, 2.25, 'Passed'),
(34, 5, 2, 3.0, 'Passed'),
(35, 5, 3, 1.75, 'Passed'),
(36, 5, 4, 2.5, 'Passed'),
(37, 5, 5, 1.25, 'Passed'),
(38, 5, 6, 2.0, 'Passed'),
(39, 5, 7, 2.75, 'Passed'),
(40, 5, 8, 1.5, 'Passed'),
(41, 6, 1, 1.5, 'Passed'),
(42, 6, 2, 2.25, 'Passed'),
(43, 6, 3, 3.0, 'Passed'),
(44, 6, 4, 1.75, 'Passed'),
(45, 6, 5, 2.5, 'Passed'),
(46, 6, 6, 1.25, 'Passed'),
(47, 6, 7, 2.0, 'Passed'),
(48, 6, 8, 2.75, 'Passed'),
(49, 7, 1, 5.0, 'Failed'),
(50, 7, 2, 1.5, 'Passed'),
(51, 7, 3, 2.25, 'Passed'),
(52, 7, 4, 3.0, 'Passed'),
(53, 7, 5, 1.75, 'Passed'),
(54, 7, 6, 2.5, 'Passed'),
(55, 7, 7, 1.25, 'Passed'),
(56, 7, 8, 2.0, 'Passed'),
(57, 8, 1, 2.0, 'Passed'),
(58, 8, 2, 2.75, 'Passed'),
(59, 8, 3, 1.5, 'Passed'),
(60, 8, 4, 2.25, 'Passed'),
(61, 8, 5, 3.0, 'Passed'),
(62, 8, 6, 1.75, 'Passed'),
(63, 8, 7, 2.5, 'Passed'),
(64, 8, 8, 1.25, 'Passed'),
(65, 9, 1, 1.25, 'Passed'),
(66, 9, 2, 2.0, 'Passed'),
(67, 9, 3, 2.75, 'Passed'),
(68, 9, 4, 1.5, 'Passed'),
(69, 9, 5, 2.25, 'Passed'),
(70, 9, 6, 3.0, 'Passed'),
(71, 9, 7, 1.75, 'Passed'),
(72, 9, 8, 2.5, 'Passed'),
(73, 10, 1, 2.5, 'Passed'),
(74, 10, 2, 1.25, 'Passed'),
(75, 10, 3, 2.0, 'Passed'),
(76, 10, 4, 2.75, 'Passed'),
(77, 10, 5, 1.5, 'Passed'),
(78, 10, 6, 2.25, 'Passed'),
(79, 10, 7, 3.0, 'Passed'),
(80, 10, 8, 1.75, 'Passed'),
(81, 11, 9, 1.25, 'Passed'),
(82, 11, 10, 2.0, 'Passed'),
(83, 11, 11, 2.75, 'Passed'),
(84, 11, 12, 1.5, 'Passed'),
(85, 11, 13, 2.25, 'Passed'),
(86, 11, 14, 3.0, 'Passed'),
(87, 11, 15, 1.75, 'Passed'),
(88, 11, 16, 2.5, 'Passed'),
(89, 12, 9, 2.5, 'Passed'),
(90, 12, 10, 1.25, 'Passed'),
(91, 12, 11, 2.0, 'Passed'),
(92, 12, 12, 2.75, 'Passed'),
(93, 12, 13, 1.5, 'Passed'),
(94, 12, 14, 2.25, 'Passed'),
(95, 12, 15, 3.0, 'Passed'),
(96, 12, 16, 1.75, 'Passed'),
(97, 13, 9, 1.75, 'Passed'),
(98, 13, 10, 2.5, 'Passed'),
(99, 13, 11, 1.25, 'Passed'),
(100, 13, 12, 2.0, 'Passed'),
(101, 13, 13, 2.75, 'Passed'),
(102, 13, 14, 1.5, 'Passed'),
(103, 13, 15, 2.25, 'Passed'),
(104, 13, 16, 3.0, 'Passed'),
(105, 14, 9, 3.0, 'Passed'),
(106, 14, 10, 1.75, 'Passed'),
(107, 14, 11, 2.5, 'Passed'),
(108, 14, 12, 1.25, 'Passed'),
(109, 14, 13, 2.0, 'Passed'),
(110, 14, 14, 2.75, 'Passed'),
(111, 14, 15, 1.5, 'Passed'),
(112, 14, 16, 2.25, 'Passed'),
(113, 15, 9, 2.25, 'Passed'),
(114, 15, 10, 3.0, 'Passed'),
(115, 15, 11, 1.75, 'Passed'),
(116, 15, 12, 2.5, 'Passed'),
(117, 15, 13, 1.25, 'Passed'),
(118, 15, 14, 2.0, 'Passed'),
(119, 15, 15, 2.75, 'Passed'),
(120, 15, 16, 1.5, 'Passed'),
(121, 16, 9, 1.5, 'Passed'),
(122, 16, 10, 2.25, 'Passed'),
(123, 16, 11, 3.0, 'Passed'),
(124, 16, 12, 1.75, 'Passed'),
(125, 16, 13, 2.5, 'Passed'),
(126, 16, 14, 1.25, 'Passed'),
(127, 16, 15, 2.0, 'Passed'),
(128, 16, 16, 2.75, 'Passed'),
(129, 17, 9, 2.75, 'Passed'),
(130, 17, 10, 1.5, 'Passed'),
(131, 17, 11, 2.25, 'Passed'),
(132, 17, 12, 3.0, 'Passed'),
(133, 17, 13, 1.75, 'Passed'),
(134, 17, 14, 2.5, 'Passed'),
(135, 17, 15, 1.25, 'Passed'),
(136, 17, 16, 2.0, 'Passed'),
(137, 18, 9, 2.0, 'Passed'),
(138, 18, 10, 2.75, 'Passed'),
(139, 18, 11, 1.5, 'Passed'),
(140, 18, 12, 2.25, 'Passed'),
(141, 18, 13, 3.0, 'Passed'),
(142, 18, 14, NULL, 'Dropped'),
(143, 18, 15, 2.5, 'Passed'),
(144, 18, 16, 1.25, 'Passed'),
(145, 19, 9, 1.25, 'Passed'),
(146, 19, 10, 2.0, 'Passed'),
(147, 19, 11, 2.75, 'Passed'),
(148, 19, 12, 5.0, 'Failed'),
(149, 19, 13, 2.25, 'Passed'),
(150, 19, 14, 3.0, 'Passed'),
(151, 19, 15, 1.75, 'Passed'),
(152, 19, 16, 2.5, 'Passed'),
(153, 20, 9, 2.5, 'Passed'),
(154, 20, 10, 1.25, 'Passed'),
(155, 20, 11, 2.0, 'Passed'),
(156, 20, 12, 2.75, 'Passed'),
(157, 20, 13, NULL, 'Incomplete'),
(158, 20, 14, 2.25, 'Passed'),
(159, 20, 15, 3.0, 'Passed'),
(160, 20, 16, 1.75, 'Passed');

COMMIT;

SELECT 'students' AS what, COUNT(*) AS n FROM student UNION ALL SELECT 'classes', COUNT(*) FROM class_offering
UNION ALL SELECT 'meetings', COUNT(*) FROM class_meeting UNION ALL SELECT 'enrollments', COUNT(*) FROM enrollment
UNION ALL SELECT 'graded rows', COUNT(*) FROM enrolled_subject;
-- expected: 10, 16, 28, 20, 160
