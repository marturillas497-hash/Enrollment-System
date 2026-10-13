-- MIST demo seed (localhost / XAMPP only). Do not import on the live site.
-- Run on a database that has the schema, the departments and programs, the room types and a registrar for
-- department 1 (nico_reg). Back up first. Run once. All demo accounts share the password Demo#Pass2026
--
-- Gives department 1 (COLLEGE OF TECHNOLOGY AND INFORMATION SYSTEMS, program BSIS) everything the generator needs:
-- 14 tags, 13 rooms, 13 teachers, 47 subjects with weekly parts, one curriculum 'BSIS 2026', prerequisites,
-- 4 sections and 3 terms (2025-2026 Sem 1 and 2 closed, 2026-2027 Sem 1 ongoing). No classes exist in the ongoing term.
--
-- A first run (A 2026, B 2026, C 2026 at Year 1 plus A 2025, which suggests Year 2 once the history file is loaded)
-- already shows these on purpose:
--   Ramos, Eric (the only PE teacher) has 6 units -> one of the four PE classes gets no teacher
--   Lopez, Ben has no tags                        -> listed in the readiness check, never chosen
--   Salazar, Ruth is deactivated                  -> never offered
--   CL-3 and NHL-1 seat 30, LR-OLD is inactive    -> room size and inactive-room behaviour
-- Pick another Teach year to see more:
--   Year 3: IS 306 has no tags -> drafted with an empty teacher
--   Year 4: IS 403 has no weekly parts -> default one lecture block
-- Sem 2 only (needs a Sem 2 term to be ongoing):
--   IS 309, IS 310, IS 311 have only partial tag matches; IS 405 has 0 hours -> no weekly meeting (TBA)
-- Section 'A 2025' has history only if you also run MIST_Seed_Demo_History.sql.

SELECT (SELECT COUNT(*) FROM subject) AS subjects, (SELECT COUNT(*) FROM teacher) AS teachers,
       (SELECT COUNT(*) FROM room) AS rooms, (SELECT COUNT(*) FROM curriculum) AS curricula,
       (SELECT COUNT(*) FROM school_term) AS terms;
-- every number above should be 0. Stop here if not.

START TRANSACTION;

INSERT INTO tag (tag_id, tag_name) VALUES
(1, 'Programming'),
(2, 'Database'),
(3, 'Web Development'),
(4, 'Networking'),
(5, 'Systems Analysis'),
(6, 'Mathematics'),
(7, 'English'),
(8, 'Filipino'),
(9, 'Social Science'),
(10, 'Ethics'),
(11, 'Physical Education'),
(12, 'Information Security'),
(13, 'Management and Accounting'),
(14, 'Research');

INSERT INTO room (room_id, room_code, room_type_id, capacity, is_active)
SELECT v.id, v.code, rt.room_type_id, v.cap, v.act FROM (
SELECT 1 AS id, 'LR-101' AS code, 'Lecture Room' AS tname, 40 AS cap, 1 AS act
UNION ALL
SELECT 2 AS id, 'LR-102' AS code, 'Lecture Room' AS tname, 40 AS cap, 1 AS act
UNION ALL
SELECT 3 AS id, 'LR-103' AS code, 'Lecture Room' AS tname, 40 AS cap, 1 AS act
UNION ALL
SELECT 4 AS id, 'LR-104' AS code, 'Lecture Room' AS tname, 40 AS cap, 1 AS act
UNION ALL
SELECT 5 AS id, 'LR-201' AS code, 'Lecture Room' AS tname, 45 AS cap, 1 AS act
UNION ALL
SELECT 6 AS id, 'LR-202' AS code, 'Lecture Room' AS tname, 45 AS cap, 1 AS act
UNION ALL
SELECT 7 AS id, 'LR-OLD' AS code, 'Lecture Room' AS tname, 30 AS cap, 0 AS act
UNION ALL
SELECT 8 AS id, 'CL-1' AS code, 'Computer Laboratory' AS tname, 35 AS cap, 1 AS act
UNION ALL
SELECT 9 AS id, 'CL-2' AS code, 'Computer Laboratory' AS tname, 35 AS cap, 1 AS act
UNION ALL
SELECT 10 AS id, 'CL-3' AS code, 'Computer Laboratory' AS tname, 30 AS cap, 1 AS act
UNION ALL
SELECT 11 AS id, 'NHL-1' AS code, 'Networking and Hardware Lab' AS tname, 30 AS cap, 1 AS act
UNION ALL
SELECT 12 AS id, 'GYM' AS code, 'Gymnasium or Covered Court' AS tname, 120 AS cap, 1 AS act
UNION ALL
SELECT 13 AS id, 'AVR' AS code, 'Auditorium or AV Room' AS tname, 150 AS cap, 1 AS act
) v JOIN room_type rt ON rt.type_name = v.tname;

INSERT INTO accounts (username, email, password_hash, role, must_change_password, session_version, is_active, activated_at) VALUES
('reyes_ana', 'reyes.ana@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('santos_miguel', 'santos.miguel@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('cruz_liza', 'cruz.liza@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('delacruz_paolo', 'delacruz.paolo@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('garcia_maria', 'garcia.maria@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('mendoza_jose', 'mendoza.jose@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('bautista_clara', 'bautista.clara@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('villanueva_ramon', 'villanueva.ramon@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('aquino_grace', 'aquino.grace@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('ramos_eric', 'ramos.eric@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('torres_nina', 'torres.nina@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('lopez_ben', 'lopez.ben@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 1, NOW()),
('salazar_ruth', 'salazar.ruth@demo.invalid', '$2y$10$nRwEHKsFZCJ1zGcJ0FNaFuanIB8Bxl5dYkmtODwsv2rdOqlRNRm9W', 'teacher', 0, 0, 0, NOW());

INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 1, account_id, 'Reyes', 'Ana', 1, 24, 30 FROM accounts WHERE username = 'reyes_ana';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 2, account_id, 'Santos', 'Miguel', 1, 24, 30 FROM accounts WHERE username = 'santos_miguel';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 3, account_id, 'Cruz', 'Liza', 1, 24, 30 FROM accounts WHERE username = 'cruz_liza';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 4, account_id, 'Dela Cruz', 'Paolo', 1, 24, 30 FROM accounts WHERE username = 'delacruz_paolo';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 5, account_id, 'Garcia', 'Maria', 1, 24, 30 FROM accounts WHERE username = 'garcia_maria';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 6, account_id, 'Mendoza', 'Jose', 1, 21, 27 FROM accounts WHERE username = 'mendoza_jose';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 7, account_id, 'Bautista', 'Clara', 1, 24, 30 FROM accounts WHERE username = 'bautista_clara';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 8, account_id, 'Villanueva', 'Ramon', 1, 24, 30 FROM accounts WHERE username = 'villanueva_ramon';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 9, account_id, 'Aquino', 'Grace', 1, 12, 15 FROM accounts WHERE username = 'aquino_grace';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 10, account_id, 'Ramos', 'Eric', 1, 6, 6 FROM accounts WHERE username = 'ramos_eric';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 11, account_id, 'Torres', 'Nina', 1, 24, 30 FROM accounts WHERE username = 'torres_nina';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 12, account_id, 'Lopez', 'Ben', 1, 24, 30 FROM accounts WHERE username = 'lopez_ben';
INSERT INTO teacher (teacher_id, account_id, last_name, first_name, department_id, max_units, max_hours_per_week)
SELECT 13, account_id, 'Salazar', 'Ruth', 1, 24, 30 FROM accounts WHERE username = 'salazar_ruth';

INSERT INTO teacher_tag (teacher_id, tag_id) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 3),
(3, 2),
(3, 5),
(4, 4),
(4, 12),
(5, 6),
(6, 6),
(6, 14),
(7, 7),
(7, 8),
(8, 9),
(8, 10),
(9, 13),
(9, 5),
(10, 11),
(11, 1),
(11, 12),
(11, 14),
(13, 1),
(13, 2);

INSERT INTO subject (subject_id, subject_code, subject_name, units, year_level) VALUES
(1, 'GE 101', 'Understanding the Self', 3, 1),
(2, 'GE 102', 'Mathematics in the Modern World', 3, 1),
(3, 'GE 103', 'Purposive Communication', 3, 1),
(4, 'IS 101', 'Introduction to Computing', 3, 1),
(5, 'IS 102', 'Computer Programming 1', 3, 1),
(6, 'FIL 101', 'Komunikasyon sa Akademikong Filipino', 3, 1),
(7, 'PE 101', 'Physical Fitness', 2, 1),
(8, 'NSTP 101', 'National Service Training Program 1', 3, 1),
(9, 'GE 104', 'Readings in Philippine History', 3, 1),
(10, 'GE 105', 'Ethics', 3, 1),
(11, 'IS 103', 'Computer Programming 2', 3, 1),
(12, 'IS 104', 'Discrete Mathematics', 3, 1),
(13, 'IS 105', 'Fundamentals of Information Systems', 3, 1),
(14, 'IS 106', 'Data Communications', 3, 1),
(15, 'PE 102', 'Rhythmic Activities', 2, 1),
(16, 'NSTP 102', 'National Service Training Program 2', 3, 1),
(17, 'IS 201', 'Data Structures and Algorithms', 3, 2),
(18, 'IS 202', 'Database Management Systems 1', 3, 2),
(19, 'IS 203', 'Systems Analysis and Design', 3, 2),
(20, 'IS 204', 'Web Systems and Technologies', 3, 2),
(21, 'GE 201', 'Statistics and Probability', 3, 2),
(22, 'GE 202', 'The Contemporary World', 3, 2),
(23, 'PE 201', 'Individual and Dual Sports', 2, 2),
(24, 'IS 205', 'Object-Oriented Programming', 3, 2),
(25, 'IS 206', 'Database Management Systems 2', 3, 2),
(26, 'IS 207', 'Networking 1', 3, 2),
(27, 'IS 208', 'IT Project Management', 3, 2),
(28, 'IS 209', 'Business Process Management', 3, 2),
(29, 'GE 203', 'Technical Writing', 3, 2),
(30, 'PE 202', 'Team Sports', 2, 2),
(31, 'IS 301', 'Information Security Fundamentals', 3, 3),
(32, 'IS 302', 'Mobile Application Development', 3, 3),
(33, 'IS 303', 'Enterprise Architecture', 3, 3),
(34, 'IS 304', 'Research Methods', 3, 3),
(35, 'IS 305', 'Quantitative Methods', 3, 3),
(36, 'IS 306', 'Special Topics in Information Systems', 3, 3),
(37, 'IS 307', 'IT Audit and Controls', 3, 3),
(38, 'IS 308', 'Capstone Project 1', 3, 3),
(39, 'IS 309', 'Software Engineering', 3, 3),
(40, 'IS 310', 'E-Commerce', 3, 3),
(41, 'IS 311', 'Data Analytics', 3, 3),
(42, 'GE 301', 'Life and Works of Rizal', 3, 3),
(43, 'IS 401', 'Capstone Project 2', 3, 4),
(44, 'IS 402', 'IS Strategy, Management and Acquisition', 3, 4),
(45, 'IS 403', 'Professional Ethics in Computing', 3, 4),
(46, 'IS 404', 'Cloud Computing', 3, 4),
(47, 'IS 405', 'Practicum', 6, 4);

INSERT INTO subject_tag (subject_id, tag_id) VALUES
(1, 9),
(2, 6),
(3, 7),
(4, 1),
(5, 1),
(6, 8),
(7, 11),
(8, 9),
(9, 9),
(10, 10),
(11, 1),
(12, 6),
(13, 5),
(14, 4),
(15, 11),
(16, 9),
(17, 1),
(18, 2),
(19, 5),
(20, 3),
(21, 6),
(22, 9),
(23, 11),
(24, 1),
(25, 2),
(26, 4),
(27, 13),
(28, 13),
(28, 5),
(29, 7),
(30, 11),
(31, 12),
(32, 1),
(32, 3),
(33, 5),
(34, 14),
(35, 6),
(37, 12),
(38, 14),
(39, 1),
(39, 5),
(40, 3),
(40, 13),
(41, 2),
(41, 6),
(42, 9),
(43, 14),
(44, 13),
(45, 10),
(46, 4),
(47, 14);

INSERT INTO subject_requirement (subject_id, part_kind, hours_per_meeting, meetings_per_week, preferred_room_type_id, fallback_room_type_id) VALUES
(1, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(2, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(3, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(4, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(4, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(5, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(5, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(6, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(7, 'Activity', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Gymnasium or Covered Court'), (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room')),
(8, 'Lecture', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(9, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(10, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(11, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(11, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(12, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(13, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(14, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(14, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Networking and Hardware Lab'), (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory')),
(15, 'Activity', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Gymnasium or Covered Court'), (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room')),
(16, 'Lecture', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(17, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(17, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(18, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(18, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(19, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(20, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(20, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(21, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(22, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(23, 'Activity', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Gymnasium or Covered Court'), (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room')),
(24, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(24, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(25, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(25, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(26, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(26, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Networking and Hardware Lab'), (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory')),
(27, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(28, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(29, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(30, 'Activity', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Gymnasium or Covered Court'), (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room')),
(31, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(31, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(32, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(32, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(33, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(34, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(35, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(36, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(37, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(38, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(39, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(40, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(41, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(41, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory'), NULL),
(42, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(43, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(44, 'Lecture', 1.5, 2, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(46, 'Lecture', 2, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Lecture Room'), NULL),
(46, 'Laboratory', 3, 1, (SELECT room_type_id FROM room_type WHERE type_name = 'Networking and Hardware Lab'), (SELECT room_type_id FROM room_type WHERE type_name = 'Computer Laboratory')),
(47, 'Practicum', 0, 1, NULL, NULL);

INSERT INTO curriculum (curriculum_id, program_id, curriculum_name, effective_year, is_active) VALUES (1, 1, 'BSIS 2026', 2026, 1);

INSERT INTO curriculum_subject (curriculum_id, subject_id, year_level, semester, required_standing) VALUES
(1, 1, 1, 1, NULL),
(1, 2, 1, 1, NULL),
(1, 3, 1, 1, NULL),
(1, 4, 1, 1, NULL),
(1, 5, 1, 1, NULL),
(1, 6, 1, 1, NULL),
(1, 7, 1, 1, NULL),
(1, 8, 1, 1, NULL),
(1, 9, 1, 2, NULL),
(1, 10, 1, 2, NULL),
(1, 11, 1, 2, NULL),
(1, 12, 1, 2, NULL),
(1, 13, 1, 2, NULL),
(1, 14, 1, 2, NULL),
(1, 15, 1, 2, NULL),
(1, 16, 1, 2, NULL),
(1, 17, 2, 1, NULL),
(1, 18, 2, 1, NULL),
(1, 19, 2, 1, NULL),
(1, 20, 2, 1, NULL),
(1, 21, 2, 1, NULL),
(1, 22, 2, 1, NULL),
(1, 23, 2, 1, NULL),
(1, 24, 2, 2, NULL),
(1, 25, 2, 2, NULL),
(1, 26, 2, 2, NULL),
(1, 27, 2, 2, NULL),
(1, 28, 2, 2, NULL),
(1, 29, 2, 2, NULL),
(1, 30, 2, 2, NULL),
(1, 31, 3, 1, NULL),
(1, 32, 3, 1, NULL),
(1, 33, 3, 1, NULL),
(1, 34, 3, 1, 3),
(1, 35, 3, 1, NULL),
(1, 36, 3, 1, NULL),
(1, 37, 3, 2, NULL),
(1, 38, 3, 2, 3),
(1, 39, 3, 2, NULL),
(1, 40, 3, 2, NULL),
(1, 41, 3, 2, NULL),
(1, 42, 3, 2, NULL),
(1, 43, 4, 1, NULL),
(1, 44, 4, 1, NULL),
(1, 45, 4, 1, NULL),
(1, 46, 4, 1, NULL),
(1, 47, 4, 2, NULL);

INSERT INTO curriculum_prerequisite (curriculum_id, subject_id, prerequisite_subject_id) VALUES
(1, 11, 5),
(1, 17, 11),
(1, 18, 11),
(1, 19, 13),
(1, 20, 11),
(1, 21, 2),
(1, 24, 17),
(1, 25, 18),
(1, 26, 14),
(1, 31, 26),
(1, 38, 34),
(1, 43, 38);

INSERT INTO section (section_id, section_name, year_level, program_id, curriculum_id, max_slots) VALUES
(1, 'A 2025', 1, 1, 1, 40),
(2, 'A 2026', 1, 1, 1, 35),
(3, 'B 2026', 1, 1, 1, 35),
(4, 'C 2026', 1, 1, 1, 30);

INSERT INTO school_term (term_id, school_year, semester, status, closed_by, date_closed) VALUES
(1, '2025-2026', 1, 'closed', (SELECT account_id FROM accounts WHERE role = 'admin' ORDER BY account_id LIMIT 1), '2025-12-20 10:00:00'),
(2, '2025-2026', 2, 'closed', (SELECT account_id FROM accounts WHERE role = 'admin' ORDER BY account_id LIMIT 1), '2026-05-30 10:00:00'),
(3, '2026-2027', 1, 'ongoing', NULL, NULL);

COMMIT;

SELECT 'tags' AS what, COUNT(*) AS n FROM tag UNION ALL SELECT 'rooms', COUNT(*) FROM room UNION ALL SELECT 'teachers', COUNT(*) FROM teacher
UNION ALL SELECT 'subjects', COUNT(*) FROM subject UNION ALL SELECT 'curriculum subjects', COUNT(*) FROM curriculum_subject
UNION ALL SELECT 'prerequisites', COUNT(*) FROM curriculum_prerequisite UNION ALL SELECT 'sections', COUNT(*) FROM section
UNION ALL SELECT 'terms', COUNT(*) FROM school_term;
-- expected: 14, 13, 13, 47, 47, 12, 4, 3
