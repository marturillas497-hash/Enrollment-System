-- MIST Enrollment System: fresh import
-- Structure (23 tables, InnoDB utf8mb4) plus 6 departments, 6 programs and the Admin account.
-- Import into an existing EMPTY database. Do not import over a database that already has data.
-- Login: username Admin, password Admin123 (change it after first login).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE `Accounts` (
  `account_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(225) NOT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('student', 'teacher', 'registrar', 'admission_staff', 'admin') NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
  `session_version` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `activated_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `username_UNIQUE` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Department` (
  `department_id` INT NOT NULL AUTO_INCREMENT,
  `department_name` VARCHAR(255) NOT NULL,
  `max_units_per_term` INT NULL DEFAULT NULL,
  PRIMARY KEY (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Subject` (
  `subject_id` INT NOT NULL AUTO_INCREMENT,
  `subject_code` VARCHAR(45) NOT NULL,
  `subject_name` VARCHAR(255) NOT NULL,
  `subject_description` VARCHAR(255) NULL DEFAULT NULL,
  `units` INT NOT NULL,
  PRIMARY KEY (`subject_id`),
  UNIQUE KEY `uq_subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Program` (
  `program_id` INT NOT NULL AUTO_INCREMENT,
  `department_id` INT NOT NULL,
  `program_code` VARCHAR(255) NOT NULL,
  `program_name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`program_id`),
  KEY `department_id_idx` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Curriculum` (
  `curriculum_id` INT NOT NULL AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `curriculum_name` VARCHAR(255) NOT NULL,
  `effective_year` YEAR NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`curriculum_id`),
  KEY `program_id_idx` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `School_term` (
  `term_id` INT NOT NULL AUTO_INCREMENT,
  `school_year` VARCHAR(45) NOT NULL,
  `semester` INT NOT NULL,
  `status` ENUM('ongoing', 'closed') NOT NULL,
  `closed_by` INT NULL DEFAULT NULL,
  `date_closed` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `uq_term_year_semester` (`school_year`, `semester`),
  KEY `closed_by_idx` (`closed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Section` (
  `section_id` INT NOT NULL AUTO_INCREMENT,
  `section_name` VARCHAR(45) NOT NULL,
  `year_level` INT NOT NULL,
  `program_id` INT NOT NULL,
  `max_slots` INT NOT NULL,
  PRIMARY KEY (`section_id`),
  KEY `program_id_idx` (`program_id`),
  UNIQUE KEY `uq_section_program_year_name` (`program_id`, `year_level`, `section_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Teacher` (
  `teacher_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `suffix` VARCHAR(45) NULL DEFAULT NULL,
  `department_id` INT NOT NULL,
  PRIMARY KEY (`teacher_id`),
  UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  KEY `account_id_idx` (`account_id`),
  KEY `department_id_idx` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Registrar` (
  `registrar_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `suffix` VARCHAR(45) NULL DEFAULT NULL,
  `department_id` INT NOT NULL,
  `created_by` INT NULL,
  PRIMARY KEY (`registrar_id`),
  UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  KEY `department_id_idx` (`department_id`),
  KEY `created_by_fk_idx` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Curriculum_subject` (
  `curriculum_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `year_level` INT NOT NULL,
  `semester` INT NOT NULL,
  PRIMARY KEY (`curriculum_id`,`subject_id`),
  KEY `subject_id_idx` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Prerequisite` (
  `subject_id` INT NOT NULL,
  `prerequisite_subject_id` INT NOT NULL,
  PRIMARY KEY (`subject_id`,`prerequisite_subject_id`),
  KEY `prerequisite_subject_id_idx` (`prerequisite_subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Admission_Application` (
  `application_id` INT NOT NULL AUTO_INCREMENT,
  `applicant_last_name` VARCHAR(255) NOT NULL,
  `applicant_first_name` VARCHAR(255) NOT NULL,
  `applicant_middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `applicant_suffix` VARCHAR(45) NULL DEFAULT NULL,
  `birthdate` DATE NOT NULL,
  `applicant_province` VARCHAR(255) NULL DEFAULT NULL,
  `applicant_municipality` VARCHAR(255) NULL DEFAULT NULL,
  `applicant_barangay` VARCHAR(255) NULL DEFAULT NULL,
  `applicant_purok` VARCHAR(255) NULL DEFAULT NULL,
  `father_last_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_first_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_suffix` VARCHAR(45) NULL DEFAULT NULL,
  `father_occupation` VARCHAR(45) NULL DEFAULT NULL,
  `mother_maiden_name` VARCHAR(255) NULL DEFAULT NULL,
  `mother_first_name` VARCHAR(255) NULL DEFAULT NULL,
  `mother_middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `mother_occupation` VARCHAR(255) NULL DEFAULT NULL,
  `contact_no` VARCHAR(15) NULL DEFAULT NULL,
  `email_address` VARCHAR(255) NULL DEFAULT NULL,
  `guardian_name` VARCHAR(255) NULL DEFAULT NULL,
  `guardian_relationship` VARCHAR(45) NULL DEFAULT NULL,
  `guardian_contact_no` VARCHAR(15) NULL DEFAULT NULL,
  `student_type` ENUM('freshman', 'transferee') NOT NULL,
  `applicant_year_level` INT NULL,
  `program_id` INT NOT NULL,
  `application_date` DATE NOT NULL,
  `status` ENUM('pending', 'validated', 'rejected') NOT NULL,
  `validated_by` INT NULL DEFAULT NULL,
  `evaluated_year_level` INT NULL DEFAULT NULL,
  `date_validated` DATE NULL DEFAULT NULL,
  `rejection_reason` VARCHAR(255) NULL DEFAULT NULL,
  `rejected_by` INT NULL DEFAULT NULL,
  `date_rejected` DATE NULL DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  KEY `program_id_idx` (`program_id`),
  KEY `validated_by_idx` (`validated_by`),
  KEY `rejected_by_idx` (`rejected_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Student` (
  `student_id` INT NOT NULL AUTO_INCREMENT,
  `student_id_number` VARCHAR(45) NOT NULL,
  `account_id` INT NOT NULL,
  `application_id` INT NULL DEFAULT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `suffix` VARCHAR(45) NULL DEFAULT NULL,
  `birthdate` DATE NOT NULL,
  `province` VARCHAR(255) NULL DEFAULT NULL,
  `municipality` VARCHAR(255) NULL DEFAULT NULL,
  `barangay` VARCHAR(255) NULL DEFAULT NULL,
  `purok` VARCHAR(255) NULL DEFAULT NULL,
  `father_last_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_first_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_suffix` VARCHAR(45) NULL DEFAULT NULL,
  `mother_maiden_name` VARCHAR(255) NULL DEFAULT NULL,
  `mother_first_name` VARCHAR(255) NULL DEFAULT NULL,
  `mother_middle_name` VARCHAR(255) NULL DEFAULT NULL,
  `father_occupation` VARCHAR(255) NULL DEFAULT NULL,
  `mother_occupation` VARCHAR(255) NULL DEFAULT NULL,
  `contact_no` VARCHAR(15) NULL DEFAULT NULL,
  `email_address` VARCHAR(255) NULL DEFAULT NULL,
  `guardian_name` VARCHAR(255) NULL DEFAULT NULL,
  `guardian_relationship` VARCHAR(45) NULL DEFAULT NULL,
  `guardian_contact_no` VARCHAR(15) NULL DEFAULT NULL,
  `student_type` ENUM('freshman', 'transferee') NOT NULL,
  `overall_status` ENUM('active', 'on_leave', 'graduated', 'dropped') NOT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  UNIQUE KEY `application_id_UNIQUE` (`application_id`),
  UNIQUE KEY `student_id_number_UNIQUE` (`student_id_number`),
  KEY `account_id_idx` (`account_id`),
  KEY `application_id_idx` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Admission_Staff` (
  `staff_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `last_name` VARCHAR(45) NOT NULL,
  `first_name` VARCHAR(45) NOT NULL,
  `middle_name` VARCHAR(45) NULL DEFAULT NULL,
  `suffix` VARCHAR(45) NULL DEFAULT NULL,
  PRIMARY KEY (`staff_id`),
  KEY `account_id_fk_idx` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Class_Offering` (
  `offering_id` INT NOT NULL AUTO_INCREMENT,
  `subject_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `room` VARCHAR(255) NULL DEFAULT NULL,
  `day_of_week` VARCHAR(45) NULL DEFAULT NULL,
  `start_time` TIME NULL DEFAULT NULL,
  `end_time` TIME NULL DEFAULT NULL,
  PRIMARY KEY (`offering_id`),
  KEY `subject_id_idx` (`subject_id`),
  KEY `teacher_id_idx` (`teacher_id`),
  KEY `section_id_idx` (`section_id`),
  KEY `term_id_idx` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Enrollment_Document` (
  `document_id` INT NOT NULL AUTO_INCREMENT,
  `document_type` VARCHAR(45) NOT NULL,
  `status` ENUM('submitted', 'verified', 'missing') NOT NULL,
  `verified_by` INT NULL DEFAULT NULL,
  `application_id` INT NOT NULL,
  PRIMARY KEY (`document_id`),
  KEY `application_id_fk_idx` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Program_shift_request` (
  `request_id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `from_curriculum_id` INT NOT NULL,
  `to_curriculum_id` INT NOT NULL,
  `request_date` DATE NOT NULL,
  `effective_term_id` INT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL,
  `approved_by` INT NULL DEFAULT NULL,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  `credit_evaluation_status` ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
  `target_section_id` INT NULL DEFAULT NULL,
  `target_year_level` INT NULL DEFAULT NULL,
  `applied_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `student_id_idx` (`student_id`),
  KEY `from_curriculum_id_idx` (`from_curriculum_id`),
  KEY `to_curriculum_id_idx` (`to_curriculum_id`),
  KEY `effective_term_id_idx` (`effective_term_id`),
  KEY `approved_by_idx` (`approved_by`),
  KEY `target_section_id_idx` (`target_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Enrollment` (
  `enrollment_id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `curriculum_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `year_level` INT NOT NULL,
  `date_enrolled` DATETIME NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL,
  `rejection_reason` VARCHAR(255) NULL DEFAULT NULL,
  `approved_by` INT NULL DEFAULT NULL,
  `student_standing` ENUM('regular', 'irregular') NOT NULL DEFAULT 'regular',
  `source_shift_request_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `student_term_UNIQUE` (`student_id`,`term_id`),
  KEY `student_id_idx` (`student_id`),
  KEY `term_id_idx` (`term_id`),
  KEY `curriculum_id_idx` (`curriculum_id`),
  KEY `section_id_idx` (`section_id`),
  KEY `approved_by_idx` (`approved_by`),
  KEY `enrollment_shift_request_fk_idx` (`source_shift_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Enrolled_subject` (
  `enrolled_subject_id` INT NOT NULL AUTO_INCREMENT,
  `enrollment_id` INT NOT NULL,
  `offering_id` INT NOT NULL,
  `grade` DECIMAL(3,2) NULL DEFAULT NULL,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`enrolled_subject_id`),
  UNIQUE KEY `enrollment_offering_UNIQUE` (`offering_id`,`enrollment_id`),
  KEY `enrollment_id_idx` (`enrollment_id`),
  KEY `offering_id_idx` (`offering_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Transferee_credit` (
  `credit_id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `previous_school` VARCHAR(255) NOT NULL,
  `previous_subject_description` VARCHAR(255) NULL DEFAULT NULL,
  `previous_grade` DECIMAL(3,2) NOT NULL,
  `credited_subject_id` INT NOT NULL,
  PRIMARY KEY (`credit_id`),
  KEY `student_id_idx` (`student_id`),
  KEY `credited_subject_id_idx` (`credited_subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Shift_credit` (
  `shift_credit_id` INT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `enrolled_subject_id` INT NOT NULL,
  `credited_subject_id` INT NOT NULL,
  `evaluated_by` INT NULL DEFAULT NULL,
  `remarks` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`shift_credit_id`),
  KEY `request_id_idx` (`request_id`),
  KEY `enrolled_subject_id_idx` (`enrolled_subject_id`),
  KEY `credited_subject_id_idx` (`credited_subject_id`),
  KEY `evaluated_by_idx` (`evaluated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `Password_reset` (
  `reset_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token_hash_UNIQUE` (`token_hash`),
  KEY `account_id_created_at_idx` (`account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Foreign keys

ALTER TABLE `Program` ADD CONSTRAINT `fk_program_department_id` FOREIGN KEY (`department_id`) REFERENCES `Department` (`department_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Curriculum` ADD CONSTRAINT `fk_curriculum_program_id` FOREIGN KEY (`program_id`) REFERENCES `Program` (`program_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `School_term` ADD CONSTRAINT `fk_school_term_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Section` ADD CONSTRAINT `fk_section_program_id` FOREIGN KEY (`program_id`) REFERENCES `Program` (`program_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Teacher` ADD CONSTRAINT `fk_teacher_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Teacher` ADD CONSTRAINT `fk_teacher_department_id` FOREIGN KEY (`department_id`) REFERENCES `Department` (`department_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Registrar` ADD CONSTRAINT `fk_registrar_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Registrar` ADD CONSTRAINT `fk_registrar_department_id` FOREIGN KEY (`department_id`) REFERENCES `Department` (`department_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Registrar` ADD CONSTRAINT `fk_registrar_created_by` FOREIGN KEY (`created_by`) REFERENCES `Accounts` (`account_id`) ON DELETE SET NULL ON UPDATE NO ACTION;
ALTER TABLE `Curriculum_subject` ADD CONSTRAINT `fk_curriculum_subject_curriculum_id` FOREIGN KEY (`curriculum_id`) REFERENCES `Curriculum` (`curriculum_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Curriculum_subject` ADD CONSTRAINT `fk_curriculum_subject_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Prerequisite` ADD CONSTRAINT `fk_prerequisite_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Prerequisite` ADD CONSTRAINT `fk_prerequisite_prerequisite_subject_id` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Admission_Application` ADD CONSTRAINT `fk_admission_application_program_id` FOREIGN KEY (`program_id`) REFERENCES `Program` (`program_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Admission_Application` ADD CONSTRAINT `fk_admission_application_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Admission_Application` ADD CONSTRAINT `fk_admission_application_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Student` ADD CONSTRAINT `fk_student_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Student` ADD CONSTRAINT `fk_student_application_id` FOREIGN KEY (`application_id`) REFERENCES `Admission_Application` (`application_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Admission_Staff` ADD CONSTRAINT `fk_admission_staff_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Class_Offering` ADD CONSTRAINT `fk_class_offering_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Class_Offering` ADD CONSTRAINT `fk_class_offering_teacher_id` FOREIGN KEY (`teacher_id`) REFERENCES `Teacher` (`teacher_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Class_Offering` ADD CONSTRAINT `fk_class_offering_section_id` FOREIGN KEY (`section_id`) REFERENCES `Section` (`section_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Class_Offering` ADD CONSTRAINT `fk_class_offering_term_id` FOREIGN KEY (`term_id`) REFERENCES `School_term` (`term_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment_Document` ADD CONSTRAINT `fk_enrollment_document_application_id` FOREIGN KEY (`application_id`) REFERENCES `Admission_Application` (`application_id`) ON DELETE NO ACTION ON UPDATE NO ACTION;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_student_id` FOREIGN KEY (`student_id`) REFERENCES `Student` (`student_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_from_curriculum_id` FOREIGN KEY (`from_curriculum_id`) REFERENCES `Curriculum` (`curriculum_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_to_curriculum_id` FOREIGN KEY (`to_curriculum_id`) REFERENCES `Curriculum` (`curriculum_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_effective_term_id` FOREIGN KEY (`effective_term_id`) REFERENCES `School_term` (`term_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Program_shift_request` ADD CONSTRAINT `fk_program_shift_request_target_section_id` FOREIGN KEY (`target_section_id`) REFERENCES `Section` (`section_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_student_id` FOREIGN KEY (`student_id`) REFERENCES `Student` (`student_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_term_id` FOREIGN KEY (`term_id`) REFERENCES `School_term` (`term_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_curriculum_id` FOREIGN KEY (`curriculum_id`) REFERENCES `Curriculum` (`curriculum_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_section_id` FOREIGN KEY (`section_id`) REFERENCES `Section` (`section_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrollment` ADD CONSTRAINT `fk_enrollment_source_shift_request_id` FOREIGN KEY (`source_shift_request_id`) REFERENCES `Program_shift_request` (`request_id`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `Enrolled_subject` ADD CONSTRAINT `fk_enrolled_subject_enrollment_id` FOREIGN KEY (`enrollment_id`) REFERENCES `Enrollment` (`enrollment_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Enrolled_subject` ADD CONSTRAINT `fk_enrolled_subject_offering_id` FOREIGN KEY (`offering_id`) REFERENCES `Class_Offering` (`offering_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Transferee_credit` ADD CONSTRAINT `fk_transferee_credit_student_id` FOREIGN KEY (`student_id`) REFERENCES `Student` (`student_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Transferee_credit` ADD CONSTRAINT `fk_transferee_credit_credited_subject_id` FOREIGN KEY (`credited_subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Shift_credit` ADD CONSTRAINT `fk_shift_credit_request_id` FOREIGN KEY (`request_id`) REFERENCES `Program_shift_request` (`request_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Shift_credit` ADD CONSTRAINT `fk_shift_credit_enrolled_subject_id` FOREIGN KEY (`enrolled_subject_id`) REFERENCES `Enrolled_subject` (`enrolled_subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Shift_credit` ADD CONSTRAINT `fk_shift_credit_credited_subject_id` FOREIGN KEY (`credited_subject_id`) REFERENCES `Subject` (`subject_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Shift_credit` ADD CONSTRAINT `fk_shift_credit_evaluated_by` FOREIGN KEY (`evaluated_by`) REFERENCES `Accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `Password_reset` ADD CONSTRAINT `fk_password_reset_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Manual hardening: the Workbench model does not carry CHECK constraints, so they are added here.
ALTER TABLE `Enrolled_subject` ADD CONSTRAINT `chk_enrolled_subject_grade` CHECK (`grade` IS NULL OR (`grade` BETWEEN 1.00 AND 5.00));
ALTER TABLE `Transferee_credit` ADD CONSTRAINT `chk_transferee_credit_grade` CHECK (`previous_grade` BETWEEN 1.00 AND 5.00);

-- Invite links: audit trail of email changes made by staff.
CREATE TABLE `Account_email_change` (
  `change_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `changed_by` INT NULL DEFAULT NULL,
  `old_email` VARCHAR(255) NULL DEFAULT NULL,
  `new_email` VARCHAR(255) NOT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`change_id`),
  KEY `account_id_idx` (`account_id`),
  KEY `changed_by_idx` (`changed_by`),
  CONSTRAINT `fk_account_email_change_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_account_email_change_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `Accounts` (`account_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


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

INSERT INTO `Accounts` (`username`, `email`, `password_hash`, `role`, `must_change_password`, `session_version`, `is_active`, `activated_at`) VALUES
('Admin', NULL, '$2y$12$5hVNTIINFeHT/HH9TagYZerqbQTaIruDNEaqgcZBRV0870I.Jszi.', 'admin', 0, 0, 1, CURRENT_TIMESTAMP);
