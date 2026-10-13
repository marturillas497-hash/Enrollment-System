-- =====================================================================
-- MIST Enrollment System: local TEST seed (v4 test run)
-- Wipes every table, then loads a known starting state.
-- Import schema.sql first (once, into an empty database), then this file.
-- Importing this file again at any time resets the database to this state.
-- Every account below uses the password: mist2027
-- Table names use exact capitals on purpose (the live host is case sensitive).
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Wipe
TRUNCATE TABLE `Accounts`;
TRUNCATE TABLE `Department`;
TRUNCATE TABLE `Subject`;
TRUNCATE TABLE `Program`;
TRUNCATE TABLE `Curriculum`;
TRUNCATE TABLE `School_term`;
TRUNCATE TABLE `Section`;
TRUNCATE TABLE `Teacher`;
TRUNCATE TABLE `Registrar`;
TRUNCATE TABLE `Curriculum_subject`;
TRUNCATE TABLE `Curriculum_prerequisite`;
TRUNCATE TABLE `Admission_Application`;
TRUNCATE TABLE `Student`;
TRUNCATE TABLE `Admission_Staff`;
TRUNCATE TABLE `Class_Offering`;
TRUNCATE TABLE `Generation_run`;
TRUNCATE TABLE `Class_Meeting`;
TRUNCATE TABLE `Room_type`;
TRUNCATE TABLE `Tag`;
TRUNCATE TABLE `Subject_tag`;
TRUNCATE TABLE `Teacher_tag`;
TRUNCATE TABLE `Subject_requirement`;
TRUNCATE TABLE `Room`;
TRUNCATE TABLE `Enrollment_Document`;
TRUNCATE TABLE `Program_shift_request`;
TRUNCATE TABLE `Enrollment`;
TRUNCATE TABLE `Enrolled_subject`;
TRUNCATE TABLE `Transferee_credit`;
TRUNCATE TABLE `Shift_credit`;
TRUNCATE TABLE `Password_reset`;
TRUNCATE TABLE `Account_email_change`;
TRUNCATE TABLE `Audit_log`;
TRUNCATE TABLE `Grade_correction`;
TRUNCATE TABLE `Grade_correction_flag`;

-- 2. Reference data: departments, programs, room types
INSERT INTO `Department` (`department_id`, `department_name`, `max_units_per_term`) VALUES
(1, 'COLLEGE OF TECHNOLOGY AND INFORMATION SYSTEMS', 25),
(2, 'COLLEGE OF CRIMINAL JUSTICE AND EDUCATION', 25),
(3, 'COLLEGE OF PUBLIC ADMINISTRATION', 25),
(4, 'COLLEGE OF BUSINESS', 25),
(5, 'COLLEGE OF AGRICULTURE', 25),
(6, 'COLLEGE OF MEDICINE', 25);

INSERT INTO `Program` (`program_id`, `department_id`, `program_code`, `program_name`) VALUES
(1, 1, 'BSIS', 'BACHELOR OF SCIENCE IN INFORMATION SYSTEMS'),
(2, 2, 'BSCRIM', 'BACHELOR OF SCIENCE IN CRIMINOLOGY'),
(3, 3, 'BPA', 'BACHELOR OF SCIENCE IN PUBLIC ADMINISTRATION'),
(4, 4, 'BSE', 'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP'),
(5, 5, 'BSA', 'BACHELOR OF SCIENCE IN AGRICULTURE'),
(6, 6, 'BSM', 'BACHELOR OF SCIENCE IN MIDWIFERY');

INSERT INTO `Room_type` (`room_type_id`, `type_name`, `is_unlimited`) VALUES
(1, 'Lecture Room', 0),
(2, 'Computer Laboratory', 0),
(3, 'Networking and Hardware Lab', 0),
(4, 'Science Laboratory', 0),
(5, 'Forensic or Crime Laboratory', 0),
(6, 'Moot Court', 0),
(7, 'Tactics and Combative Room', 0),
(8, 'Business Simulation or Mock Store', 0),
(9, 'Crop or Farm Field', 0),
(10, 'Greenhouse and Nursery', 0),
(11, 'Livestock or Animal Science', 0),
(12, 'Food Processing Lab', 0),
(13, 'Midwifery Skills or Clinical Simulation Lab', 0),
(14, 'Anatomy and Physiology Lab', 0),
(15, 'Gymnasium or Covered Court', 0),
(16, 'Auditorium or AV Room', 0),
(17, 'Off-campus or Clinical Site', 1);

-- 3. Accounts (all password mist2027). must_change_password = 1 only for alcantara_ramon and 2026-00001
INSERT INTO `Accounts` (`account_id`, `username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`) VALUES
(1, 'Admin', NULL, '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'admin', 0, 0, 1, '2026-09-01 08:00:00'),
(2, 'alcantara_ramon', 'rega@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'registrar', 1, 0, 1, '2026-09-01 08:00:00'),
(3, 'alcantara_ramon2', 'regb@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'registrar', 0, 0, 1, '2026-09-01 08:00:00'),
(4, 'bautista_carla', 'bautista@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(5, 'lim_daniel', 'lim@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(6, 'navarro_elena', 'navarro@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'teacher', 0, 0, 1, '2026-09-01 08:00:00'),
(7, 'ocampo_patricia', 'ocampo@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'admission_staff', 0, 0, 1, '2026-09-01 08:00:00'),
(8, '2026-00001', 'juan@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 1, 0, 1, '2026-09-01 08:00:00'),
(9, '2026-00002', 'maria@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00'),
(10, '2026-00003', 'pedro@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00'),
(11, '2026-00004', 'ana@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00'),
(12, '2026-00005', 'luis@mist.test', '$2y$10$bBYD.hBCAI0Pan7ILUBiXu1OGoLGe35luXuijzMUbXvPuoAuyhPhW', 'student', 0, 0, 1, '2026-09-01 08:00:00');

INSERT INTO `Registrar` (`registrar_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `created_by`) VALUES
(1, 2, 'Alcantara', 'Ramon', 'Bautista', NULL, 1, 1),
(2, 3, 'Alcantara', 'Ramon', 'Cruz', 'Jr.', 6, 1);

INSERT INTO `Teacher` (`teacher_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `max_units`, `max_hours_per_week`) VALUES
(1, 4, 'Bautista', 'Carla', 'Reyes', NULL, 1, 9, 20.0),
(2, 5, 'Lim', 'Daniel', NULL, NULL, 1, 24, 30.0),
(3, 6, 'Navarro', 'Elena', 'Diaz', NULL, 6, 24, 30.0);

INSERT INTO `Admission_Staff` (`staff_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`) VALUES
(1, 7, 'Ocampo', 'Patricia', 'Lopez', NULL);

-- 4. Applications: 1 to 5 are validated and placed, 6 (Rina) is pending
INSERT INTO `Admission_Application` (`application_id`, `applicant_last_name`, `applicant_first_name`, `applicant_middle_name`, `applicant_suffix`, `birthdate`, `applicant_province`, `applicant_municipality`, `applicant_barangay`, `applicant_purok`, `father_last_name`, `father_first_name`, `father_middle_name`, `father_suffix`, `father_occupation`, `mother_maiden_name`, `mother_first_name`, `mother_middle_name`, `mother_occupation`, `contact_no`, `email_address`, `guardian_name`, `guardian_relationship`, `guardian_contact_no`, `student_type`, `applicant_year_level`, `program_id`, `application_date`, `status`, `validated_by`, `evaluated_year_level`, `date_validated`) VALUES
(1, 'Dela Cruz', 'Juan', 'Santos', 'Jr.', '2008-03-15', 'Cotabato', 'Makilala', 'Poblacion', 'Purok 4', 'Dela Cruz', 'Roberto', 'Mendoza', NULL, 'Farmer', 'Santos', 'Elena', 'Reyes', 'Housewife', '09171234501', 'juan@mist.test', 'Roberto Dela Cruz', 'Father', '09171234502', 'freshman', NULL, 1, '2026-09-20', 'validated', 7, 1, '2026-09-22'),
(2, 'Reyes', 'Maria', 'Clara', NULL, '2008-07-22', 'Cotabato', 'Makilala', 'Luayon', 'Purok 1', 'Reyes', 'Antonio', 'Cruz', NULL, 'Driver', 'Aquino', 'Luz', 'Ramos', 'Vendor', '09171234511', 'maria@mist.test', 'Luz Aquino Reyes', 'Mother', '09171234512', 'freshman', NULL, 1, '2026-09-20', 'validated', 7, 1, '2026-09-22'),
(3, 'Ramos', 'Pedro', 'Lopez', NULL, '2007-11-05', 'Cotabato', 'Makilala', 'Batasan', 'Purok 5', 'Ramos', 'Ernesto', 'Lopez', NULL, 'Carpenter', 'Diaz', 'Carmen', 'Torres', 'Teacher', '09171234521', 'pedro@mist.test', 'Ernesto Ramos', 'Father', '09171234522', 'freshman', NULL, 1, '2026-09-20', 'validated', 7, 1, '2026-09-22'),
(4, 'Villanueva', 'Ana', 'Salazar', NULL, '2006-01-30', 'Cotabato', 'Kidapawan City', 'Sudapin', 'Purok 2', 'Villanueva', 'Manuel', 'Cortez', NULL, 'Mechanic', 'Salazar', 'Teresita', 'Gomez', 'Nurse', '09171234531', 'ana@mist.test', 'Teresita Villanueva', 'Mother', '09171234532', 'transferee', 1, 1, '2026-09-20', 'validated', 7, 1, '2026-09-22'),
(5, 'Garcia', 'Luis', 'Flores', NULL, '2008-09-12', 'Cotabato', 'Makilala', 'Buhay', 'Purok 4', 'Garcia', 'Ramil', 'Ocampo', NULL, 'Electrician', 'Flores', 'Rosario', 'Jimenez', 'Cashier', '09171234541', 'luis@mist.test', 'Ramil Garcia', 'Father', '09171234542', 'freshman', NULL, 1, '2026-09-20', 'validated', 7, 1, '2026-09-22'),
(6, 'Soriano', 'Rina', 'Alma', NULL, '2008-02-14', 'Cotabato', 'Makilala', 'Villaflor', 'Purok 2', 'Soriano', 'Danilo', 'Reyes', NULL, 'Farmer', 'Pineda', 'Joy', 'Cruz', 'Teacher', '09171234551', 'rina.soriano@mist.test', 'Danilo Soriano', 'Father', '09171234552', 'freshman', NULL, 1, '2026-09-20', 'pending', NULL, NULL, NULL);

INSERT INTO `Student` (`student_id`, `student_id_number`, `account_id`, `application_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `birthdate`, `province`, `municipality`, `barangay`, `purok`, `father_last_name`, `father_first_name`, `father_middle_name`, `father_suffix`, `mother_maiden_name`, `mother_first_name`, `mother_middle_name`, `father_occupation`, `mother_occupation`, `contact_no`, `guardian_name`, `guardian_relationship`, `guardian_contact_no`, `student_type`, `overall_status`) VALUES
(1, '2026-00001', 8, 1, 'Dela Cruz', 'Juan', 'Santos', 'Jr.', '2008-03-15', 'Cotabato', 'Makilala', 'Poblacion', 'Purok 4', 'Dela Cruz', 'Roberto', 'Mendoza', NULL, 'Santos', 'Elena', 'Reyes', 'Farmer', 'Housewife', '09171234501', 'Roberto Dela Cruz', 'Father', '09171234502', 'freshman', 'active'),
(2, '2026-00002', 9, 2, 'Reyes', 'Maria', 'Clara', NULL, '2008-07-22', 'Cotabato', 'Makilala', 'Luayon', 'Purok 1', 'Reyes', 'Antonio', 'Cruz', NULL, 'Aquino', 'Luz', 'Ramos', 'Driver', 'Vendor', '09171234511', 'Luz Aquino Reyes', 'Mother', '09171234512', 'freshman', 'active'),
(3, '2026-00003', 10, 3, 'Ramos', 'Pedro', 'Lopez', NULL, '2007-11-05', 'Cotabato', 'Makilala', 'Batasan', 'Purok 5', 'Ramos', 'Ernesto', 'Lopez', NULL, 'Diaz', 'Carmen', 'Torres', 'Carpenter', 'Teacher', '09171234521', 'Ernesto Ramos', 'Father', '09171234522', 'freshman', 'active'),
(4, '2026-00004', 11, 4, 'Villanueva', 'Ana', 'Salazar', NULL, '2006-01-30', 'Cotabato', 'Kidapawan City', 'Sudapin', 'Purok 2', 'Villanueva', 'Manuel', 'Cortez', NULL, 'Salazar', 'Teresita', 'Gomez', 'Mechanic', 'Nurse', '09171234531', 'Teresita Villanueva', 'Mother', '09171234532', 'transferee', 'active'),
(5, '2026-00005', 12, 5, 'Garcia', 'Luis', 'Flores', NULL, '2008-09-12', 'Cotabato', 'Makilala', 'Buhay', 'Purok 4', 'Garcia', 'Ramil', 'Ocampo', NULL, 'Flores', 'Rosario', 'Jimenez', 'Electrician', 'Cashier', '09171234541', 'Ramil Garcia', 'Father', '09171234542', 'freshman', 'active');

-- 5. Documents: Pedro 2x2 ID Photos missing, Ana Form 137 missing, everything else verified
INSERT INTO `Enrollment_Document` (`document_type`, `status`, `verified_by`, `application_id`) VALUES
('Birth Certificate', 'verified', 2, 1),
('Form 137 / Report Card', 'verified', 2, 1),
('Certificate of Good Moral Character', 'verified', 2, 1),
('2x2 ID Photos', 'verified', 2, 1),
('Birth Certificate', 'verified', 2, 2),
('Form 137 / Report Card', 'verified', 2, 2),
('Certificate of Good Moral Character', 'verified', 2, 2),
('2x2 ID Photos', 'verified', 2, 2),
('Birth Certificate', 'verified', 2, 3),
('Form 137 / Report Card', 'verified', 2, 3),
('Certificate of Good Moral Character', 'verified', 2, 3),
('2x2 ID Photos', 'missing', NULL, 3),
('Birth Certificate', 'verified', 2, 4),
('Form 137 / Report Card', 'missing', NULL, 4),
('Certificate of Good Moral Character', 'verified', 2, 4),
('2x2 ID Photos', 'verified', 2, 4),
('Birth Certificate', 'verified', 2, 5),
('Form 137 / Report Card', 'verified', 2, 5),
('Certificate of Good Moral Character', 'verified', 2, 5),
('2x2 ID Photos', 'verified', 2, 5);

-- 6. Term 1 is ongoing
INSERT INTO `School_term` (`term_id`, `school_year`, `semester`, `status`) VALUES
(1, '2027-2028', 1, 'ongoing');

-- 7. Subjects: 8 BSM, TST101 to TST105 (TST106 is added by the tester)
INSERT INTO `Subject` (`subject_id`, `subject_code`, `subject_name`, `subject_description`, `units`, `year_level`) VALUES
(1, 'GE 101', 'Understanding the Self', 'BSM sample subject', 3, 1),
(2, 'MID 101', 'Fundamentals of Midwifery', 'BSM sample subject', 3, 1),
(3, 'HC 101', 'Health Care Ethics', 'BSM sample subject', 3, 1),
(4, 'ANAT 101', 'Anatomy and Physiology', 'BSM sample subject', 3, 1),
(5, 'GE 102', 'Purposive Communication', 'BSM sample subject', 3, 1),
(6, 'MID 102', 'Maternal and Newborn Care', 'BSM sample subject', 3, 1),
(7, 'GE 103', 'Mathematics in the Modern World', 'BSM sample subject', 3, 1),
(8, 'HC 102', 'Community Health', 'BSM sample subject', 3, 1),
(9, 'TST101', 'Test Introduction to Computing', 'Test subject, year 1 sem 1', 3, 1),
(10, 'TST102', 'Test Computer Programming 1', 'Test subject, year 1 sem 1', 3, 1),
(11, 'TST103', 'Test Understanding the Self', 'Test subject, year 1 sem 1', 3, 1),
(12, 'TST104', 'Test Computer Programming 2', 'Test subject, year 1 sem 2', 3, 1),
(13, 'TST105', 'Test Data Structures', 'Test subject, year 1 sem 2', 3, 1);

INSERT INTO `Curriculum` (`curriculum_id`, `program_id`, `curriculum_name`, `effective_year`, `is_active`) VALUES
(1, 6, 'BSM 2026', 2026, 1),
(2, 1, 'TEST BSIS Curriculum 2027', 2027, 1);

INSERT INTO `Curriculum_subject` (`curriculum_id`, `subject_id`, `year_level`, `semester`) VALUES
(1, 1, 1, 1),
(1, 2, 1, 1),
(1, 3, 1, 1),
(1, 5, 1, 1),
(1, 4, 1, 2),
(1, 6, 1, 2),
(1, 7, 1, 2),
(1, 8, 1, 2),
(2, 9, 1, 1),
(2, 10, 1, 1),
(2, 11, 1, 1),
(2, 12, 1, 2),
(2, 13, 1, 2);

-- Prerequisites: MID 102 needs MID 101, HC 102 needs HC 101, TST102 needs TST101, TST104 needs TST102
INSERT INTO `Curriculum_prerequisite` (`curriculum_id`, `subject_id`, `prerequisite_subject_id`) VALUES
(1, 6, 2),
(1, 8, 3),
(2, 10, 9),
(2, 12, 10);

-- 8. Sections: six BSM sections, TEST-A with only 4 slots (it holds 5 students on purpose)
INSERT INTO `Section` (`section_id`, `section_name`, `year_level`, `program_id`, `curriculum_id`, `max_slots`) VALUES
(1, 'A', 1, 6, 1, 30),
(2, 'B', 1, 6, 1, 30),
(3, 'A', 2, 6, 1, 30),
(4, 'B', 2, 6, 1, 30),
(5, 'A', 3, 6, 1, 30),
(6, 'A', 4, 6, 1, 30),
(7, 'TEST-A', 1, 1, 2, 4);

-- 9. Rooms (the tester adds T-202). M-202 has no capacity on purpose
INSERT INTO `Room` (`room_id`, `room_code`, `room_type_id`, `capacity`, `is_active`) VALUES
(1, 'T-101', 1, 40, 1),
(2, 'T-102', 1, 40, 1),
(3, 'T-103', 1, 40, 1),
(4, 'T-104', 1, 40, 1),
(5, 'T-201', 1, 40, 1),
(6, 'M-201', 1, 40, 1),
(7, 'M-202', 1, NULL, 1),
(8, 'T-LAB1', 2, 30, 1);

INSERT INTO `Tag` (`tag_id`, `tag_name`) VALUES
(1, 'Programming'),
(2, 'Database'),
(3, 'Mathematics'),
(4, 'Social Science');

INSERT INTO `Subject_tag` (`subject_id`, `tag_id`) VALUES
(9, 1),
(10, 1),
(11, 4),
(12, 1),
(13, 2);

INSERT INTO `Teacher_tag` (`teacher_id`, `tag_id`) VALUES
(1, 1),
(1, 2),
(2, 1),
(2, 3),
(3, 4);

-- Weekly parts: TST104 = 2 x 1.5 h lecture, TST105 = 2 h lecture + 3 h computer lab
INSERT INTO `Subject_requirement` (`subject_id`, `part_kind`, `hours_per_meeting`, `meetings_per_week`, `preferred_room_type_id`, `fallback_room_type_id`) VALUES
(12, 'Lecture', 1.5, 2, 1, NULL),
(13, 'Lecture', 2.0, 1, 1, NULL),
(13, 'Laboratory', 3.0, 1, 2, 1);

-- 10. Term 1 classes (TEST-A, taught by Bautista)
INSERT INTO `Class_Offering` (`offering_id`, `subject_id`, `teacher_id`, `section_id`, `term_id`) VALUES
(1, 9, 1, 7, 1),
(2, 10, 1, 7, 1),
(3, 11, 1, 7, 1);

INSERT INTO `Class_Meeting` (`meeting_id`, `offering_id`, `day_of_week`, `start_time`, `end_time`, `room_id`) VALUES
(1, 1, 'Monday', '08:00:00', '10:00:00', 1),
(2, 2, 'Tuesday', '08:00:00', '10:00:00', 2),
(3, 3, 'Wednesday', '08:00:00', '10:00:00', 3);

-- 11. Term 1 enrollments, all in TEST-A
INSERT INTO `Enrollment` (`enrollment_id`, `student_id`, `term_id`, `curriculum_id`, `section_id`, `year_level`, `date_enrolled`, `status`, `approved_by`, `student_standing`) VALUES
(1, 1, 1, 2, 7, 1, '2026-09-25 09:00:00', 'approved', 2, 'regular'),
(2, 2, 1, 2, 7, 1, '2026-09-25 09:00:00', 'approved', 2, 'regular'),
(3, 3, 1, 2, 7, 1, '2026-09-25 09:00:00', 'approved', 2, 'regular'),
(4, 4, 1, 2, 7, 1, '2026-09-25 09:00:00', 'approved', 2, 'regular'),
(5, 5, 1, 2, 7, 1, '2026-09-25 09:00:00', 'approved', 2, 'regular');

-- Ana (transferee) is only on TST103, no credits yet
INSERT INTO `Enrolled_subject` (`enrollment_id`, `offering_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 2),
(2, 3),
(3, 1),
(3, 2),
(3, 3),
(5, 1),
(5, 2),
(5, 3),
(4, 3);

SET FOREIGN_KEY_CHECKS = 1;

-- 12. Check (phpMyAdmin shows this result after the import)
SELECT 'Accounts' AS item, COUNT(*) AS rows_loaded FROM `Accounts`
UNION ALL SELECT 'Students', COUNT(*) FROM `Student`
UNION ALL SELECT 'Applications (1 pending)', COUNT(*) FROM `Admission_Application`
UNION ALL SELECT 'Subjects', COUNT(*) FROM `Subject`
UNION ALL SELECT 'Sections', COUNT(*) FROM `Section`
UNION ALL SELECT 'Rooms', COUNT(*) FROM `Room`
UNION ALL SELECT 'Class offerings', COUNT(*) FROM `Class_Offering`
UNION ALL SELECT 'Enrolled subjects', COUNT(*) FROM `Enrolled_subject`;
