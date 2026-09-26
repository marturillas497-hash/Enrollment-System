-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 25, 2026 at 09:23 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Added so this file can be imported in one shot, without creating the
-- database by hand first. FK checks are disabled during import so the
-- DROP TABLE IF EXISTS lines below never fail on an existing install,
-- then re-enabled at the end. No data below this point was changed.
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `enrollment_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `enrollment_system`;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `enrollment_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;

CREATE TABLE `accounts` (
  `account_id` int(11) NOT NULL,
  `username` varchar(225) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','teacher','registrar','admission_staff','admin') NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`account_id`, `username`, `password_hash`, `role`, `must_change_password`, `created_at`) VALUES
(2, 'Admin', '$2y$10$pAW4arXLpS7wPSJrf3K7Ze42xc2mnRfy/XfwGx7fH3kQ2JaYevy3G', 'admin', 0, '2026-09-01 13:01:05'),
(3, 'deguzman_justine', '$2y$10$w6wtrv4SrfktDBQQ3RqUOuhJBfRU0ycAzcliw.K6QHyt2o/Ot8T.G', 'registrar', 0, '2026-09-01 13:32:57'),
(4, 'marturillas_ralphnico', '$2y$10$K9huyUun6Zi2s06D3lHUyum07oVBYjXnb7ZEWqpnXjZOHOy91JIyi', 'teacher', 0, '2026-09-01 13:34:25'),
(5, 'deguzman_justine2', '$2y$10$5hkfxuiV53uLfj7r7xJq6uQXSCkrPoV/3TUzoWHGxyEQNIMdB4jC2', 'registrar', 0, '2026-09-02 00:39:54'),
(6, 'juhoon', '$2y$10$cuHjreoOCIJo6Ggy64TS9.t3IfwIjMRuCormNP452R48mqWhJupTG', 'admission_staff', 0, '2026-09-02 00:42:10'),
(7, '2026-00001', '$2y$10$BGI58WOB2Rw9LtgsL8Pxue4z6YnhH53VKNa1s0xuVUE03ED81uQ72', 'student', 0, '2026-09-02 01:12:44'),
(8, 'sardua_glaiza', '$2y$10$lE64nNFyO6hACsjlSC7jzexwYckyirlw3Mvz4ZO8JCFw1Gm7IS2F2', 'registrar', 0, '2026-09-16 02:33:28'),
(9, '2026-00002', '$2y$10$XVEWs.P/1hjyV0xxYXH/t.T6hc78gK5UTjo49.MicuPBO9VLU9HVa', 'student', 0, '2026-09-16 03:00:13'),
(10, 'marturillas_ralphnico2', '$2y$10$zbHSp6wUvyxyK9ngef6D2u.MkacMIh.zzQ33kJ4L.MqYz4PsLV30.', 'teacher', 1, '2026-09-16 03:04:42'),
(11, 'deguzman_justinedave', '$2y$10$IsswDZSysx5r8USwr0naYugBspf2h1TxnOIahS8P2dMAyFXWTdIYG', 'teacher', 0, '2026-09-16 03:05:36'),
(12, '2026-00003', '$2y$10$GVznKTxrykSAJOJANM9.TOcrNN4rSPuhLkL.f2iCXC0bhviboUJMu', 'student', 0, '2026-09-16 12:01:59'),
(13, '2026-00004', '$2y$10$MEqEnkjMFOiWu5KB6namX.OcbxkCNfLZDqlr.i1cXV4MkwgfBbfje', 'student', 0, '2026-09-19 23:16:59');

-- --------------------------------------------------------

--
-- Table structure for table `admission_application`
--

DROP TABLE IF EXISTS `admission_application`;

CREATE TABLE `admission_application` (
  `application_id` int(11) NOT NULL,
  `applicant_last_name` varchar(255) NOT NULL,
  `applicant_first_name` varchar(255) NOT NULL,
  `applicant_middle_name` varchar(255) DEFAULT NULL,
  `applicant_suffix` varchar(45) DEFAULT NULL,
  `birthdate` date NOT NULL,
  `applicant_province` varchar(255) DEFAULT NULL,
  `applicant_municipality` varchar(255) DEFAULT NULL,
  `applicant_barangay` varchar(255) DEFAULT NULL,
  `applicant_purok` varchar(255) DEFAULT NULL,
  `father_last_name` varchar(255) DEFAULT NULL,
  `father_first_name` varchar(255) DEFAULT NULL,
  `father_middle_name` varchar(255) DEFAULT NULL,
  `father_suffix` varchar(45) DEFAULT NULL,
  `father_occupation` varchar(45) DEFAULT NULL,
  `mother_maiden_name` varchar(255) DEFAULT NULL,
  `mother_first_name` varchar(255) DEFAULT NULL,
  `mother_middle_name` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `contact_no` varchar(15) DEFAULT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(45) DEFAULT NULL,
  `guardian_contact_no` varchar(15) DEFAULT NULL,
  `student_type` enum('freshman','transferee') NOT NULL,
  `program_id` int(11) NOT NULL,
  `application_date` date NOT NULL,
  `status` enum('pending','validated','rejected') NOT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `evaluated_year_level` int(11) DEFAULT NULL,
  `date_validated` date DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `date_rejected` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admission_application`
--

INSERT INTO `admission_application` (`application_id`, `applicant_last_name`, `applicant_first_name`, `applicant_middle_name`, `applicant_suffix`, `birthdate`, `applicant_province`, `applicant_municipality`, `applicant_barangay`, `applicant_purok`, `father_last_name`, `father_first_name`, `father_middle_name`, `father_suffix`, `father_occupation`, `mother_maiden_name`, `mother_first_name`, `mother_middle_name`, `mother_occupation`, `contact_no`, `email_address`, `guardian_name`, `guardian_relationship`, `guardian_contact_no`, `student_type`, `program_id`, `application_date`, `status`, `validated_by`, `evaluated_year_level`, `date_validated`, `rejection_reason`, `rejected_by`, `date_rejected`) VALUES
(1, 'Apostol', 'Althea', NULL, NULL, '2004-09-14', 'Cotabato', 'Makilala', 'Bulakanon', NULL, 'Apostol', 'Beatriz', 'Mae', NULL, 'Farmer', 'Familgan', 'Beatriz', NULL, 'Housewife', '09096913123', 'beahfamilgandahan@gmail.com', 'Beatriz Dahan', NULL, '09096913123', 'freshman', 1, '2026-09-02', 'validated', 6, 1, '2026-09-02', NULL, NULL, NULL),
(2, 'Dahan', 'Beatriz', NULL, NULL, '2005-06-01', 'Davao', 'Makilala', 'Bulakanon', '7 Apitong', 'Dahan', 'Beatriz', 'Mae', NULL, 'Farmer', 'Familgan', 'Beatriz', NULL, 'Housewife', '09096913123', 'beahfamilgandahan@gmail.com', 'Beatriz Dahan', 'Mother', '09096913123', 'freshman', 1, '2026-09-02', 'validated', 6, 1, '2026-09-02', NULL, NULL, NULL),
(3, 'Marturillas', 'Ayumi Shane', 'Mae', NULL, '2013-08-17', 'Davao', 'Makilala', 'Kawayanon', NULL, 'Dahan', 'Beah', NULL, NULL, 'Business Owner', 'Quiboyen', 'Connie', 'Sanluis', 'Housewife', '09096913123', 'beahfamilgandahan@gmail.com', NULL, NULL, NULL, 'freshman', 1, '2026-09-02', 'validated', 6, 1, '2026-09-02', NULL, NULL, NULL),
(4, 'Sardua', 'Glaiza Jane', NULL, NULL, '2005-03-21', 'Cotabato', 'Makilala', 'Kawayanon', 'Bagong Lipunan', 'Dahan', 'Beah', 'Mae', NULL, NULL, 'Quiboyen', 'Beatriz', 'Sanluis', NULL, '09096913123', 'beahfamilgandahan@gmail.com', 'Judy Mole', 'Mother', '09096913123', 'freshman', 1, '2026-09-02', 'pending', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'De Guzman', 'Justine', 'Albino', NULL, '2004-12-16', 'Cotabato', 'Kidapawan', 'Manongol', '3-B Avocado', NULL, NULL, NULL, NULL, NULL, 'Abecia', 'Mary', 'Albino', 'Beautician', '09050882058', 'dabehave@gmail.com', 'Mary Abecia', 'Mother', '09050882058', 'transferee', 6, '2026-09-16', 'validated', 6, 1, '2026-09-16', NULL, NULL, NULL),
(6, 'Bais', 'Ayumi Shane', 'Marturillas', NULL, '2013-08-17', 'Cotabato', 'Makilala', 'Luna Sur', '8', NULL, NULL, NULL, NULL, NULL, 'Marturillas', 'Rosalyn', 'Dizon', 'Housewife', '09074174906', 'Bais@gmail.com', 'Nico Marturillas', 'Brother', '09074174906', 'transferee', 6, '2026-09-16', 'validated', 6, 1, '2026-09-16', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `admission_staff`
--

DROP TABLE IF EXISTS `admission_staff`;

CREATE TABLE `admission_staff` (
  `staff_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `last_name` varchar(45) NOT NULL,
  `first_name` varchar(45) NOT NULL,
  `middle_name` varchar(45) DEFAULT NULL,
  `suffix` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_offering`
--

DROP TABLE IF EXISTS `class_offering`;

CREATE TABLE `class_offering` (
  `offering_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `room` varchar(255) DEFAULT NULL,
  `day_of_week` varchar(45) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_offering`
--

INSERT INTO `class_offering` (`offering_id`, `subject_id`, `teacher_id`, `section_id`, `term_id`, `room`, `day_of_week`, `start_time`, `end_time`) VALUES
(1, 1, 1, 1, 1, 'COMLAB 1', 'Monday', '07:30:00', '10:30:00'),
(2, 2, 1, 1, 1, 'COMLAB 1', 'Monday', '10:30:00', '13:30:00'),
(3, 3, 1, 1, 1, 'ROOM 1', 'Wednesday', '07:30:00', '10:30:00'),
(4, 5, 1, 1, 1, 'ROOM 2', 'Friday', '07:30:00', '10:30:00'),
(5, 7, 3, 3, 1, 'BSM ROOM 1', 'Monday', '07:30:00', '08:30:00'),
(6, 10, 2, 3, 1, 'BSM ROOM 2', 'Tuesday', '07:30:00', '08:30:00'),
(7, 3, 3, 3, 1, 'SL 14', 'Wednesday', '07:30:00', '08:30:00'),
(8, 6, 3, 3, 1, 'SL 15', 'Friday', '15:30:00', '16:30:00'),
(9, 8, 3, 3, 1, 'SL 10', 'Monday', '10:00:00', '11:30:00'),
(10, 9, 3, 3, 1, 'ROOM 1', 'Thursday', '11:00:00', '12:00:00'),
(11, 4, 3, 3, 1, 'GYMNASIUM', 'Friday', '07:30:00', '08:30:00'),
(12, 11, 3, 3, 1, 'SL 11', 'Wednesday', '13:30:00', '14:30:00'),
(13, 1, 1, 1, 3, 'COMLAB 1', 'Monday', '07:30:00', '10:30:00'),
(14, 2, 1, 1, 3, 'COMLAB 1', 'Tuesday', '07:30:00', '10:30:00'),
(15, 3, 1, 1, 3, 'ROOM 1', 'Tuesday', '11:00:00', '13:30:00'),
(16, 56, 1, 1, 3, 'SL 15', 'Wednesday', '07:30:00', '08:30:00'),
(17, 57, 1, 1, 3, 'COMLAB 1', 'Thursday', '10:30:00', '13:30:00'),
(18, 11, 1, 1, 3, 'GYM', 'Saturday', '06:00:00', '12:00:00'),
(19, 4, 1, 1, 3, 'GYM', 'Friday', '07:30:00', '10:30:00'),
(20, 5, 1, 1, 3, 'ROOM 1', 'Thursday', '15:30:00', '16:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `curriculum`
--

DROP TABLE IF EXISTS `curriculum`;

CREATE TABLE `curriculum` (
  `curriculum_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `curriculum_name` varchar(255) NOT NULL,
  `effective_year` year(4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `curriculum`
--

INSERT INTO `curriculum` (`curriculum_id`, `program_id`, `curriculum_name`, `effective_year`, `is_active`) VALUES
(1, 1, 'BSIS Curriculum 2026', '2026', 1),
(2, 6, 'BSM 2026', '2026', 1);

-- --------------------------------------------------------

--
-- Table structure for table `curriculum_subject`
--

DROP TABLE IF EXISTS `curriculum_subject`;

CREATE TABLE `curriculum_subject` (
  `curriculum_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `semester` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `curriculum_subject`
--

INSERT INTO `curriculum_subject` (`curriculum_id`, `subject_id`, `year_level`, `semester`) VALUES
(1, 1, 1, 1),
(1, 2, 1, 1),
(1, 3, 1, 1),
(1, 4, 1, 1),
(1, 5, 1, 1),
(1, 10, 1, 2),
(1, 11, 1, 1),
(1, 18, 1, 2),
(1, 26, 2, 1),
(1, 32, 2, 1),
(1, 33, 2, 2),
(1, 56, 1, 1),
(1, 57, 1, 1),
(1, 60, 1, 2),
(1, 61, 1, 2),
(1, 62, 1, 2),
(1, 63, 1, 2),
(1, 64, 1, 2),
(1, 65, 2, 1),
(1, 66, 2, 1),
(1, 67, 2, 1),
(1, 68, 2, 1),
(1, 69, 2, 1),
(1, 71, 2, 2),
(1, 72, 2, 2),
(1, 73, 2, 2),
(1, 74, 2, 2),
(1, 75, 2, 2),
(1, 76, 2, 2),
(1, 100, 1, 2),
(2, 3, 1, 1),
(2, 4, 1, 1),
(2, 6, 1, 1),
(2, 7, 1, 1),
(2, 8, 1, 1),
(2, 9, 1, 1),
(2, 10, 1, 1),
(2, 11, 1, 1),
(2, 12, 1, 2),
(2, 13, 1, 2),
(2, 14, 1, 2),
(2, 15, 1, 2),
(2, 16, 1, 2),
(2, 17, 1, 2),
(2, 18, 1, 2),
(2, 19, 2, 1),
(2, 20, 2, 1),
(2, 21, 2, 1),
(2, 22, 2, 1),
(2, 23, 2, 1),
(2, 24, 2, 1),
(2, 25, 2, 1),
(2, 26, 2, 1),
(2, 27, 2, 2),
(2, 28, 2, 2),
(2, 29, 2, 2),
(2, 30, 2, 2),
(2, 31, 2, 2),
(2, 32, 2, 2),
(2, 33, 2, 2),
(2, 34, 3, 1),
(2, 35, 3, 1),
(2, 36, 3, 1),
(2, 37, 3, 1),
(2, 38, 3, 1),
(2, 39, 3, 1),
(2, 40, 3, 2),
(2, 41, 3, 1),
(2, 42, 3, 2),
(2, 43, 3, 2),
(2, 44, 3, 2),
(2, 45, 3, 2),
(2, 46, 4, 1),
(2, 47, 4, 1),
(2, 48, 4, 1),
(2, 49, 4, 1),
(2, 50, 4, 1),
(2, 51, 4, 2),
(2, 52, 4, 2),
(2, 53, 4, 2),
(2, 54, 4, 2),
(2, 55, 4, 2);

-- --------------------------------------------------------

--
-- Table structure for table `department`
--

DROP TABLE IF EXISTS `department`;

CREATE TABLE `department` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `max_units_per_term` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `department`
--

INSERT INTO `department` (`department_id`, `department_name`, `max_units_per_term`) VALUES
(1, 'COLLEGE OF TECHNOLOGY AND INFORMATION SYSTEMS', 25),
(2, 'COLLEGE OF CRIMINAL JUSTICE AND EDUCATION', 25),
(3, 'COLLEGE OF PUBLIC ADMINISTRATION', 25),
(4, 'COLLEGE OF BUSINESS', 25),
(5, 'COLLEGE OF AGRICULTURE', 25),
(6, 'COLLEGE OF MEDICINE', 25);

-- --------------------------------------------------------

--
-- Table structure for table `enrolled_subject`
--

DROP TABLE IF EXISTS `enrolled_subject`;

CREATE TABLE `enrolled_subject` (
  `enrolled_subject_id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `offering_id` int(11) NOT NULL,
  `grade` decimal(3,2) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ;

--
-- Dumping data for table `enrolled_subject`
--

INSERT INTO `enrolled_subject` (`enrolled_subject_id`, `enrollment_id`, `offering_id`, `grade`, `remarks`) VALUES
(1, 1, 1, 1.50, 'Passed'),
(2, 1, 2, 5.00, 'Failed'),
(3, 1, 3, 1.00, 'Passed'),
(4, 1, 4, 1.25, 'Passed'),
(5, 4, 13, NULL, NULL),
(6, 4, 14, NULL, NULL),
(7, 4, 15, NULL, NULL),
(8, 4, 16, NULL, NULL),
(9, 4, 17, NULL, NULL),
(10, 4, 18, NULL, NULL),
(11, 4, 19, NULL, NULL),
(12, 4, 20, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enrollment`
--

DROP TABLE IF EXISTS `enrollment`;

CREATE TABLE `enrollment` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `curriculum_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `date_enrolled` datetime NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `student_standing` enum('regular','irregular') NOT NULL DEFAULT 'regular',
  `source_shift_request_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment`
--

INSERT INTO `enrollment` (`enrollment_id`, `student_id`, `term_id`, `curriculum_id`, `section_id`, `year_level`, `date_enrolled`, `status`, `rejection_reason`, `approved_by`, `student_standing`, `source_shift_request_id`) VALUES
(1, 1, 1, 1, 1, 1, '2026-09-02 09:12:44', 'approved', NULL, 3, 'irregular', NULL),
(2, 2, 1, 2, 3, 1, '2026-09-16 11:00:13', 'approved', NULL, 8, 'regular', NULL),
(3, 3, 1, 2, 3, 1, '2026-09-16 20:01:59', 'approved', NULL, 8, 'regular', NULL),
(4, 4, 3, 1, 1, 1, '2026-09-20 07:16:59', 'approved', NULL, 3, 'regular', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_document`
--

DROP TABLE IF EXISTS `enrollment_document`;

CREATE TABLE `enrollment_document` (
  `document_id` int(11) NOT NULL,
  `document_type` varchar(45) NOT NULL,
  `status` enum('submitted','verified','missing') NOT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `application_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_document`
--

INSERT INTO `enrollment_document` (`document_id`, `document_type`, `status`, `verified_by`, `application_id`) VALUES
(1, 'Birth Certificate', 'verified', 3, 2),
(2, 'Form 137 / Report Card', 'verified', 3, 2),
(3, 'Certificate of Good Moral Character', 'missing', NULL, 2),
(4, '2x2 ID Photos', 'verified', 3, 2),
(5, 'Birth Certificate', 'verified', 8, 5),
(6, 'Form 137 / Report Card', 'verified', 8, 5),
(7, 'Certificate of Good Moral Character', 'verified', 8, 5),
(8, '2x2 ID Photos', 'verified', 8, 5),
(9, 'Birth Certificate', 'verified', 8, 6),
(10, 'Form 137 / Report Card', 'verified', 8, 6),
(11, 'Certificate of Good Moral Character', 'verified', 8, 6),
(12, '2x2 ID Photos', 'verified', 8, 6),
(13, 'Birth Certificate', 'verified', 3, 1),
(14, 'Form 137 / Report Card', 'verified', 3, 1),
(15, 'Certificate of Good Moral Character', 'verified', 3, 1),
(16, '2x2 ID Photos', 'verified', 3, 1);

-- --------------------------------------------------------

--
-- Table structure for table `prerequisite`
--

DROP TABLE IF EXISTS `prerequisite`;

CREATE TABLE `prerequisite` (
  `subject_id` int(11) NOT NULL,
  `prerequisite_subject_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prerequisite`
--

INSERT INTO `prerequisite` (`subject_id`, `prerequisite_subject_id`) VALUES
(10, 3),
(18, 4),
(26, 18),
(62, 2),
(63, 87),
(64, 59),
(65, 63),
(66, 59),
(67, 1),
(69, 63),
(70, 56),
(71, 65),
(72, 66),
(73, 67),
(74, 69),
(75, 59),
(100, 11);

-- --------------------------------------------------------

--
-- Table structure for table `program`
--

DROP TABLE IF EXISTS `program`;

CREATE TABLE `program` (
  `program_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `program_code` varchar(255) NOT NULL,
  `program_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program`
--

INSERT INTO `program` (`program_id`, `department_id`, `program_code`, `program_name`) VALUES
(1, 1, 'BSIS', 'BACHELOR OF SCIENCE IN INFORMATION SYSTEMS'),
(2, 2, 'BSCRIM', 'BACHELOR OF SCIENCE IN CRIMINOLOGY'),
(3, 3, 'BPA', 'BACHELOR OF SCIENCE IN PUBLIC ADMINISTRATION'),
(4, 4, 'BSE', 'BACHELOR OF SCIENCE IN ENTREPRENEURSHIP'),
(5, 5, 'BSA', 'BACHELOR OF SCIENCE IN AGRICULTURE'),
(6, 6, 'BSM', 'BACHELOR OF SCIENCE IN MIDWIFERY');

-- --------------------------------------------------------

--
-- Table structure for table `program_shift_request`
--

DROP TABLE IF EXISTS `program_shift_request`;

CREATE TABLE `program_shift_request` (
  `request_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `from_curriculum_id` int(11) NOT NULL,
  `to_curriculum_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `effective_term_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `credit_evaluation_status` enum('pending','completed') NOT NULL DEFAULT 'pending',
  `target_section_id` int(11) DEFAULT NULL,
  `target_year_level` int(11) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registrar`
--

DROP TABLE IF EXISTS `registrar`;

CREATE TABLE `registrar` (
  `registrar_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `suffix` varchar(45) DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registrar`
--

INSERT INTO `registrar` (`registrar_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`, `created_by`) VALUES
(1, 3, 'De Guzman', 'Justine', NULL, NULL, 1, 2),
(2, 5, 'De Guzman', 'Justine', NULL, NULL, 3, 2),
(3, 8, 'Sardua', 'Glaiza', NULL, NULL, 6, 2);

-- --------------------------------------------------------

--
-- Table structure for table `school_term`
--

DROP TABLE IF EXISTS `school_term`;

CREATE TABLE `school_term` (
  `term_id` int(11) NOT NULL,
  `school_year` varchar(45) NOT NULL,
  `semester` int(11) NOT NULL,
  `status` enum('ongoing','closed') NOT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `date_closed` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `school_term`
--

INSERT INTO `school_term` (`term_id`, `school_year`, `semester`, `status`, `closed_by`, `date_closed`) VALUES
(1, '2026-2027', 1, 'closed', 3, '2026-09-20 06:47:39'),
(2, '2026-2027', 2, 'closed', 3, '2026-09-20 06:56:59'),
(3, '2026-2027', 1, 'ongoing', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `section`
--

DROP TABLE IF EXISTS `section`;

CREATE TABLE `section` (
  `section_id` int(11) NOT NULL,
  `section_name` varchar(45) NOT NULL,
  `year_level` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `max_slots` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `section`
--

INSERT INTO `section` (`section_id`, `section_name`, `year_level`, `program_id`, `max_slots`) VALUES
(1, 'A', 1, 1, 45),
(2, 'B', 1, 1, 45),
(3, 'A', 1, 6, 30),
(4, 'B', 1, 6, 30),
(5, 'A', 2, 6, 30),
(6, 'B', 2, 6, 30),
(8, 'A', 3, 6, 30),
(9, 'A', 4, 6, 30);

-- --------------------------------------------------------

--
-- Table structure for table `shift_credit`
--

DROP TABLE IF EXISTS `shift_credit`;

CREATE TABLE `shift_credit` (
  `shift_credit_id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `enrolled_subject_id` int(11) NOT NULL,
  `credited_subject_id` int(11) NOT NULL,
  `evaluated_by` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

DROP TABLE IF EXISTS `student`;

CREATE TABLE `student` (
  `student_id` int(11) NOT NULL,
  `student_id_number` varchar(45) NOT NULL,
  `account_id` int(11) NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `last_name` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `suffix` varchar(45) DEFAULT NULL,
  `birthdate` date NOT NULL,
  `province` varchar(255) DEFAULT NULL,
  `municipality` varchar(255) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `purok` varchar(255) DEFAULT NULL,
  `father_last_name` varchar(255) DEFAULT NULL,
  `father_first_name` varchar(255) DEFAULT NULL,
  `father_middle_name` varchar(255) DEFAULT NULL,
  `father_suffix` varchar(45) DEFAULT NULL,
  `mother_maiden_name` varchar(255) DEFAULT NULL,
  `mother_first_name` varchar(255) DEFAULT NULL,
  `mother_middle_name` varchar(255) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `contact_no` varchar(15) DEFAULT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(45) DEFAULT NULL,
  `guardian_contact_no` varchar(15) DEFAULT NULL,
  `student_type` enum('freshman','transferee') NOT NULL,
  `overall_status` enum('active','on_leave','graduated','dropped') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`student_id`, `student_id_number`, `account_id`, `application_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `birthdate`, `province`, `municipality`, `barangay`, `purok`, `father_last_name`, `father_first_name`, `father_middle_name`, `father_suffix`, `mother_maiden_name`, `mother_first_name`, `mother_middle_name`, `father_occupation`, `mother_occupation`, `contact_no`, `email_address`, `guardian_name`, `guardian_relationship`, `guardian_contact_no`, `student_type`, `overall_status`) VALUES
(1, '2026-00001', 7, 2, 'Dahan', 'Beatriz', NULL, NULL, '2005-06-01', 'Davao', 'Makilala', 'Bulakanon', '7 Apitong', 'Dahan', 'Beatriz', 'Mae', NULL, 'Familgan', 'Beatriz', NULL, 'Farmer', 'Housewife', '09096913123', 'beahfamilgandahan@gmail.com', 'Beatriz Dahan', 'Mother', '09096913123', 'freshman', 'active'),
(2, '2026-00002', 9, 5, 'De Guzman', 'Justine', 'Albino', NULL, '2004-12-16', 'Cotabato', 'Kidapawan', 'Manongol', '3-B Avocado', NULL, NULL, NULL, NULL, 'Abecia', 'Mary', 'Albino', NULL, 'Beautician', '09050882058', 'dabehave@gmail.com', 'Mary Abecia', 'Mother', '09050882058', 'transferee', 'active'),
(3, '2026-00003', 12, 6, 'Bais', 'Ayumi Shane', 'Marturillas', NULL, '2013-08-17', 'Cotabato', 'Makilala', 'Luna Sur', '8', NULL, NULL, NULL, NULL, 'Marturillas', 'Rosalyn', 'Dizon', NULL, 'Housewife', '09074174906', 'Bais@gmail.com', 'Nico Marturillas', 'Brother', '09074174906', 'transferee', 'active'),
(4, '2026-00004', 13, 1, 'Apostol', 'Althea', NULL, NULL, '2004-09-14', 'Cotabato', 'Makilala', 'Bulakanon', NULL, 'Apostol', 'Beatriz', 'Mae', NULL, 'Familgan', 'Beatriz', NULL, 'Farmer', 'Housewife', '09096913123', 'beahfamilgandahan@gmail.com', 'Beatriz Dahan', NULL, '09096913123', 'freshman', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `subject`
--

DROP TABLE IF EXISTS `subject`;

CREATE TABLE `subject` (
  `subject_id` int(11) NOT NULL,
  `subject_code` varchar(45) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `subject_description` varchar(255) DEFAULT NULL,
  `units` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subject`
--

INSERT INTO `subject` (`subject_id`, `subject_code`, `subject_name`, `subject_description`, `units`) VALUES
(1, 'CC101', 'Introduction to Computing', 'An Introduction to Computing course serves as a foundational gateway to the world of technology, providing students with a broad overview of computer systems, digital literacy, and the fundamental principles of computer science.', 3),
(2, 'COMPROG 1', 'Computer Programming 1', 'Introduces the fundamental concepts, structures, and methodologies required to design, write, test, and debug high-quality software programs.', 3),
(3, 'GE 101', 'Understanding the Self', 'Understanding the Self course is a foundational general education subject designed to guide students through a journey of self-discovery, identity exploration, and personal growth. Unlike purely technical or clinical psychology courses, it takes an interd', 3),
(4, 'PATHFit 1', 'Physical Activities Toward Health and Fitness 1', 'Movement Competency Training is a mandatory 2-unit foundational Physical Education course. The course focuses on reintroducing fundamental movement patterns, core stability, and functional fitness to help students build the baseline physical competence ne', 2),
(5, 'STS', 'Science, Technology, and Society', 'It is an interdisciplinary course that examines how scientific discoveries and technological innovations shape—and are shaped by—social, cultural, economic, political, and ethical contexts', 3),
(6, 'MID 101', 'Introduction to Midwifery Practice', 'Introduces students to the midwifery profession, its roles and responsibilities, professional values, and basic principles of maternal and newborn care.', 3),
(7, 'ANAT 101', 'Human Anatomy and Physiology', 'Covers the structure and normal functions of the human body, including the major organ systems relevant to maternal and reproductive health.', 3),
(8, 'HC 101', 'Introduction to Health Care', 'Introduces basic concepts of health care delivery, health professionals, patient care, and the Philippine health-care system.', 3),
(9, 'NUTR 101', 'Nutrition and Diet Therapy', 'Discusses basic nutrition, nutrients, balanced diets, and nutritional requirements during pregnancy, childbirth, postpartum, and infancy.', 3),
(10, 'GE 106', 'Purposive Communication', 'Develops students\' communication skills for academic, professional, and health-care settings, including effective interaction with patients and health professionals.', 3),
(11, 'NSTP 101', 'National Service Training Program 1', 'Introduces civic consciousness, community involvement, social responsibility, and service-oriented activities among students.', 3),
(12, 'MID102', 'Fundamentals of Midwifery Practice', 'Introduces fundamental midwifery skills, patient assessment, basic procedures, and principles of safe client care.', 3),
(13, 'MICRO102', 'General Microbiology and Parasitology', 'Introduces microorganisms, infectious diseases, parasites, transmission, and basic infection-control practices.', 3),
(14, 'PATH102', 'Basic Pathophysiology', 'Discusses basic disease processes and changes in normal body functions.', 3),
(15, 'PSY102', 'General Psychology', 'Introduces human behavior, development, emotions, learning, and interpersonal relationships relevant to health care.', 3),
(16, 'ETH102', 'Ethics and Professional Responsibility', 'Discusses ethical principles, professional conduct, confidentiality, and responsibilities of health-care professionals.', 3),
(17, 'GEC102', 'Readings in Philippine History', 'Examines important Philippine historical events, sources, and their significance to contemporary society.', 3),
(18, 'PATHFit 2', 'Exercise-Based Fitness Activities', 'Develops physical fitness through structured exercises and recreational activities.', 2),
(19, 'MID201', 'Principles of Maternal and Newborn Care', 'Covers essential principles and practices in caring for pregnant women, mothers, and newborns.', 3),
(20, 'MID202', 'Prenatal Care', 'Focuses on assessment, monitoring, health education, and care of women during pregnancy.', 3),
(21, 'MID203', 'Normal Pregnancy and Childbirth', 'Discusses physiological pregnancy, normal labor, delivery, and immediate postpartum care.', 3),
(22, 'MID204', 'Midwifery Pharmacology', 'Introduces medications commonly encountered in maternal and newborn care, including safe medication practices.', 3),
(23, 'MID205', 'Midwifery Skills Laboratory', 'Provides supervised practice of fundamental clinical and midwifery procedures.', 3),
(24, 'BIO201', 'Biochemistry', 'Introduces chemical processes in living organisms and their relationship to human health.', 3),
(25, 'GEC203', 'Science, Technology and Society', 'Examines the relationship between science, technology, and society and their effects on human life.', 3),
(26, 'PATHFit 3', 'Exercise-Based Fitness Activities', 'Develops physical fitness through structured exercises and recreational activities.', 2),
(27, 'MID206', 'Intrapartum Midwifery Care', 'Focuses on monitoring and providing care during labor and childbirth under appropriate supervision.', 3),
(28, 'MID207', 'Postpartum and Newborn Care', 'Covers assessment and care of mothers and newborns during the postpartum period.', 3),
(29, 'MID208', 'Breastfeeding and Infant Nutrition', 'Discusses breastfeeding practices, infant nutrition, lactation support, and maternal education.', 3),
(30, 'MID209', 'Family Planning and Reproductive Health', 'Covers reproductive health, family-planning methods, counseling, and responsible reproductive decision-making.', 3),
(31, 'MID210', 'Community Midwifery', 'Introduces midwifery services delivered within communities and primary health-care settings.', 3),
(32, 'STAT201', 'Statistics for Health Sciences', 'Introduces basic statistical concepts and their application to health-related data.', 3),
(33, 'PATHFit 4', 'Individual and Dual Sports', 'Develops physical fitness, coordination, teamwork, and sports participation.', 2),
(34, 'MID301', 'Complicated Pregnancy', 'Covers recognition, monitoring, and appropriate management of pregnancy complications within the midwife\'s scope.', 3),
(35, 'MID302', 'High-Risk Pregnancy', 'Discusses risk factors, assessment, referral, and care considerations for high-risk pregnancies.', 3),
(36, 'MID303', 'Complicated Labor and Delivery', 'Introduces recognition of abnormal labor and appropriate midwifery interventions and referral.', 3),
(37, 'MID304', 'Maternal and Newborn Emergencies', 'Covers recognition and initial response to selected emergencies affecting mothers and newborns.', 3),
(38, 'MID305', 'Clinical Practicum I', 'Provides supervised clinical experience in maternal and newborn care settings.', 3),
(39, 'RES301', 'Introduction to Midwifery Research', 'Introduces research concepts, research processes, literature review, and basic research ethics.', 3),
(40, 'MID306', 'Advanced Midwifery Practice', 'Develops more advanced clinical knowledge and skills applicable to maternal and newborn care.', 3),
(41, 'MID307', 'Pediatric and Child Health', 'Covers basic health assessment, common health concerns, and preventive care for infants and children.', 3),
(42, 'MID308', 'Women\'s Health', 'Discusses common women\'s health concerns, reproductive health, and health promotion across the lifespan.', 3),
(43, 'MID309', 'Community Health and Epidemiology', 'Covers population health, disease prevention, epidemiological concepts, and community-based interventions.', 3),
(44, 'MID310', 'Clinical Practicum II', 'Provides supervised clinical experience involving maternal, newborn, and community health services.', 3),
(45, 'RES302', 'Midwifery Research Project', 'Applies research methods in developing and presenting a research study related to midwifery.', 3),
(46, 'MID401', 'Comprehensive Midwifery Care', 'Integrates knowledge and skills in providing comprehensive care to mothers and newborns.', 3),
(47, 'MID402', 'Midwifery Leadership and Management', 'Introduces leadership, management, teamwork, documentation, and coordination within health-care settings.', 3),
(48, 'MID403', 'Legal Aspects of Midwifery Practice', 'Discusses laws, regulations, professional standards, accountability, and legal responsibilities related to midwifery.', 3),
(49, 'MID404', 'Health Education and Counseling', 'Develops skills in providing health education, counseling, and client-centered communication.', 3),
(50, 'MID405', 'Clinical Practicum III', 'Provides supervised clinical experience in various maternal and newborn care environments.', 3),
(51, 'MID406', 'Clinical Practicum IV', 'Provides intensive supervised clinical experience integrating competencies in maternal, newborn, reproductive, and community health care.', 3),
(52, 'MID407', 'Comprehensive Midwifery Practice', 'Integrates theoretical knowledge and clinical competencies acquired throughout the program.', 3),
(53, 'MID408', 'Professional Practice and Career Development', 'Prepares students for professional practice, continuing education, workplace responsibilities, and career development.', 3),
(54, 'MID409', 'Midwifery Case Studies', 'Analyzes selected maternal and newborn cases to develop clinical reasoning and decision-making skills.', 3),
(55, 'MID410', 'Midwifery Seminar', 'Provides discussions on current issues, professional practices, and developments in midwifery.', 3),
(56, 'GE 102', 'Mathematics in the Modern World', 'Introduces mathematical concepts and applications relevant to everyday life and decision-making.', 3),
(57, 'GE103', 'The Contemporary World', 'Examines globalization, global institutions, and contemporary social issues.', 3),
(59, 'IS101', 'Fundamentals of Information Systems', 'Introduces information systems, their components, organizational role, and basic IS concepts.', 3),
(60, 'GE 104', 'Readings in Philippine History', 'Examines Philippine history through primary and secondary sources.', 3),
(61, 'GE105', 'Art Appreciation', 'Introduces the nature, elements, and significance of art in society.', 3),
(62, 'CC102', 'Data Structures and Algorithms', 'Introduces data structures, algorithms, and fundamental techniques for organizing and processing data.', 3),
(63, 'COMPROG 2', 'Computer Programming 2', 'Develops intermediate programming skills using structured and object-oriented programming concepts.', 3),
(64, 'IS102', 'Human-Computer Interaction', 'Introduces principles for designing usable, accessible, and user-centered information systems.', 3),
(65, 'IS201', 'Information Management', 'Introduces database concepts, data organization, database design, and information management.', 3),
(66, 'IS202', 'Systems Analysis and Design 1', 'Introduces methods for analyzing business requirements and designing information systems.', 3),
(67, 'NET201', 'Data Communications and Networking', 'Introduces computer networks, communication technologies, network architecture, and protocols.', 3),
(68, 'BP201', 'Business Process Management', 'Examines business processes and methods for analyzing and improving organizational workflows.', 3),
(69, 'PF201', 'Web Development', 'Introduces client-side and server-side web development and database-connected web applications.', 3),
(70, 'STAT201', 'Statistics for Information Systems', 'Introduces statistical methods used for analyzing and interpreting information-system data.', 3),
(71, 'IS203', 'Information Management 2', 'Applies database design, SQL, normalization, and database management concepts.', 3),
(72, 'IS204', 'Systems Analysis and Design 2', 'Applies systems analysis and design techniques to develop detailed system requirements and designs.', 3),
(73, 'IS205', 'IT Infrastructure and Network Technologies', 'Examines computing infrastructure, network technologies, servers, and organizational IT environments.', 3),
(74, 'PF202', 'Application Development', 'Develops applications using programming, database, interface, and software development concepts.', 3),
(75, 'IS206', 'Professional Issues in Information Systems', 'Examines ethical, legal, social, and professional issues related to information systems.', 3),
(76, 'BUS201', 'Fundamentals of Business and Management', 'Introduces management principles, organizational structures, and basic business operations.', 3),
(77, 'IS301', 'Enterprise Systems', 'Examines integrated information systems used to support organizational operations and decision-making.', 3),
(78, 'IS302', 'IT Project Management 1', 'Introduces project planning, scheduling, resource management, risk management, and project monitoring.', 3),
(79, 'IS303', 'Application Development and Emerging Technologies 1', 'Explores modern application development approaches and emerging technologies.', 3),
(80, 'IS304', 'Business Intelligence', 'Introduces methods for transforming organizational data into information for decision-making.', 3),
(81, 'IS305', 'Quantitative Methods', 'Applies quantitative techniques to support organizational and information-system decisions.', 3),
(82, 'IS306', 'Information Systems Elective 1', 'Specialized study in an information-systems area selected from approved electives.', 3),
(83, 'IS307', 'IT Security and Management', 'Introduces information security principles, security controls, risk management, and organizational security practices.', 3),
(84, 'IS308', 'Application Development and Emerging Technologies 2', 'Applies advanced application-development techniques and emerging technologies to system development.', 3),
(85, 'IS309', 'IT Project Management 2', 'Applies project management principles to information-system development projects.', 3),
(86, 'RES301', 'Research Methods in Computing', 'Introduces research methods, research design, data collection, and analysis for computing studies.', 3),
(87, 'CAP101', 'Capstone Project 1', 'Covers project planning, requirements analysis, system proposal, and initial development of an information-system project.', 3),
(88, 'IS310', 'Information Systems Elective 2', 'Provides specialized study in an approved information-systems area.', 3),
(89, 'CAP102', 'Capstone Project 2', 'Continues the development, testing, evaluation, documentation, and presentation of the capstone project.', 3),
(90, 'IS401', 'IT Audit and Controls', 'Introduces auditing concepts, internal controls, compliance, and evaluation of information systems.', 3),
(91, 'IS402', 'IT Service Management', 'Examines the management and delivery of IT services within organizations.', 3),
(92, 'IS403', 'IS Strategy, Management and Acquisition', 'Examines strategic planning, IT governance, system acquisition, and alignment of IS with organizational goals.', 3),
(93, 'IS404', 'Information Systems Elective 3', 'Provides advanced specialized study in an approved IS area.', 3),
(94, 'PRAC401', 'Information Systems Practicum', 'Provides supervised practical experience applying information-systems knowledge in a professional environment.', 3),
(95, 'IS405', 'IS Innovations and New Technologies', 'Examines emerging technologies and their potential applications and implications in information systems.', 3),
(96, 'IS406', 'Data Analytics and Mining', 'Introduces techniques for discovering patterns and useful information from datasets.', 3),
(97, 'IS407', 'Enterprise Resource Planning', 'Examines integrated enterprise systems supporting organizational resources and business processes.', 3),
(98, 'IS408', 'Information Systems Professional Practice', 'Integrates professional, ethical, managerial, and organizational competencies required in IS practice.', 3),
(99, 'IS409', 'Information Systems Elective 4', 'Advanced specialized study in an approved IS area.', 3),
(100, 'NSTP102', 'NSTP 2', 'Applies civic and community-service concepts through community-oriented activities.', 3);

-- --------------------------------------------------------

--
-- Table structure for table `teacher`
--

DROP TABLE IF EXISTS `teacher`;

CREATE TABLE `teacher` (
  `teacher_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `suffix` varchar(45) DEFAULT NULL,
  `department_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teacher`
--

INSERT INTO `teacher` (`teacher_id`, `account_id`, `last_name`, `first_name`, `middle_name`, `suffix`, `department_id`) VALUES
(1, 4, 'Marturillas', 'Ralph Nico', NULL, NULL, 1),
(2, 10, 'Marturillas', 'Ralph Nico', NULL, NULL, 6),
(3, 11, 'De Guzman', 'Justine Dave', NULL, NULL, 6);

-- --------------------------------------------------------

--
-- Table structure for table `transferee_credit`
--

DROP TABLE IF EXISTS `transferee_credit`;

CREATE TABLE `transferee_credit` (
  `credit_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `previous_school` varchar(255) NOT NULL,
  `previous_subject_description` varchar(255) DEFAULT NULL,
  `previous_grade` decimal(3,2) NOT NULL,
  `credited_subject_id` int(11) NOT NULL
) ;

--
-- Dumping data for table `transferee_credit`
--

INSERT INTO `transferee_credit` (`credit_id`, `student_id`, `previous_school`, `previous_subject_description`, `previous_grade`, `credited_subject_id`) VALUES
(1, 3, 'USM KCC', 'Understanding the Self', 1.50, 3),
(2, 3, 'USM KCC', 'Physical Education', 1.50, 4);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `username_UNIQUE` (`username`);

--
-- Indexes for table `admission_application`
--
ALTER TABLE `admission_application`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `program_id_idx` (`program_id`),
  ADD KEY `validated_by_idx` (`validated_by`),
  ADD KEY `rejected_by_idx` (`rejected_by`);

--
-- Indexes for table `admission_staff`
--
ALTER TABLE `admission_staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD KEY `account_id_fk_idx` (`account_id`);

--
-- Indexes for table `class_offering`
--
ALTER TABLE `class_offering`
  ADD PRIMARY KEY (`offering_id`),
  ADD KEY `subject_id_idx` (`subject_id`),
  ADD KEY `teacher_id_idx` (`teacher_id`),
  ADD KEY `section_id_idx` (`section_id`),
  ADD KEY `term_id_idx` (`term_id`);

--
-- Indexes for table `curriculum`
--
ALTER TABLE `curriculum`
  ADD PRIMARY KEY (`curriculum_id`),
  ADD KEY `program_id_idx` (`program_id`);

--
-- Indexes for table `curriculum_subject`
--
ALTER TABLE `curriculum_subject`
  ADD PRIMARY KEY (`curriculum_id`,`subject_id`),
  ADD KEY `subject_id_idx` (`subject_id`);

--
-- Indexes for table `department`
--
ALTER TABLE `department`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `enrolled_subject`
--
ALTER TABLE `enrolled_subject`
  ADD PRIMARY KEY (`enrolled_subject_id`),
  ADD UNIQUE KEY `enrollment_offering_UNIQUE` (`offering_id`,`enrollment_id`),
  ADD KEY `enrollment_id_idx` (`enrollment_id`),
  ADD KEY `offering_id_idx` (`offering_id`);

--
-- Indexes for table `enrollment`
--
ALTER TABLE `enrollment`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `student_term_UNIQUE` (`student_id`,`term_id`),
  ADD KEY `student_id_idx` (`student_id`),
  ADD KEY `term_id_idx` (`term_id`),
  ADD KEY `curriculum_id_idx` (`curriculum_id`),
  ADD KEY `section_id_idx` (`section_id`),
  ADD KEY `approved_by_idx` (`approved_by`),
  ADD KEY `enrollment_shift_request_fk_idx` (`source_shift_request_id`);

--
-- Indexes for table `enrollment_document`
--
ALTER TABLE `enrollment_document`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `application_id_fk_idx` (`application_id`);

--
-- Indexes for table `prerequisite`
--
ALTER TABLE `prerequisite`
  ADD PRIMARY KEY (`subject_id`,`prerequisite_subject_id`),
  ADD KEY `prerequisite_subject_id_idx` (`prerequisite_subject_id`);

--
-- Indexes for table `program`
--
ALTER TABLE `program`
  ADD PRIMARY KEY (`program_id`),
  ADD KEY `department_id_idx` (`department_id`);

--
-- Indexes for table `program_shift_request`
--
ALTER TABLE `program_shift_request`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `student_id_idx` (`student_id`),
  ADD KEY `from_curriculum_id_idx` (`from_curriculum_id`),
  ADD KEY `to_curriculum_id_idx` (`to_curriculum_id`),
  ADD KEY `effective_term_id_idx` (`effective_term_id`),
  ADD KEY `approved_by_idx` (`approved_by`),
  ADD KEY `target_section_id_idx` (`target_section_id`);

--
-- Indexes for table `registrar`
--
ALTER TABLE `registrar`
  ADD PRIMARY KEY (`registrar_id`),
  ADD UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  ADD KEY `department_id_idx` (`department_id`),
  ADD KEY `created_by_fk_idx` (`created_by`);

--
-- Indexes for table `school_term`
--
ALTER TABLE `school_term`
  ADD PRIMARY KEY (`term_id`),
  ADD KEY `closed_by_idx` (`closed_by`);

--
-- Indexes for table `section`
--
ALTER TABLE `section`
  ADD PRIMARY KEY (`section_id`),
  ADD KEY `program_id_idx` (`program_id`);

--
-- Indexes for table `shift_credit`
--
ALTER TABLE `shift_credit`
  ADD PRIMARY KEY (`shift_credit_id`),
  ADD KEY `request_id_idx` (`request_id`),
  ADD KEY `enrolled_subject_id_idx` (`enrolled_subject_id`),
  ADD KEY `credited_subject_id_idx` (`credited_subject_id`),
  ADD KEY `evaluated_by_idx` (`evaluated_by`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  ADD UNIQUE KEY `student_id_number_UNIQUE` (`student_id_number`),
  ADD UNIQUE KEY `application_id_UNIQUE` (`application_id`),
  ADD KEY `account_id_idx` (`account_id`),
  ADD KEY `application_id_idx` (`application_id`);

--
-- Indexes for table `subject`
--
ALTER TABLE `subject`
  ADD PRIMARY KEY (`subject_id`);

--
-- Indexes for table `teacher`
--
ALTER TABLE `teacher`
  ADD PRIMARY KEY (`teacher_id`),
  ADD UNIQUE KEY `account_id_UNIQUE` (`account_id`),
  ADD KEY `account_id_idx` (`account_id`),
  ADD KEY `department_id_idx` (`department_id`);

--
-- Indexes for table `transferee_credit`
--
ALTER TABLE `transferee_credit`
  ADD PRIMARY KEY (`credit_id`),
  ADD KEY `student_id_idx` (`student_id`),
  ADD KEY `credited_subject_id_idx` (`credited_subject_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `admission_application`
--
ALTER TABLE `admission_application`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `admission_staff`
--
ALTER TABLE `admission_staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_offering`
--
ALTER TABLE `class_offering`
  MODIFY `offering_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `curriculum`
--
ALTER TABLE `curriculum`
  MODIFY `curriculum_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `department`
--
ALTER TABLE `department`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `enrolled_subject`
--
ALTER TABLE `enrolled_subject`
  MODIFY `enrolled_subject_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollment`
--
ALTER TABLE `enrollment`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `enrollment_document`
--
ALTER TABLE `enrollment_document`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `program`
--
ALTER TABLE `program`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `program_shift_request`
--
ALTER TABLE `program_shift_request`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registrar`
--
ALTER TABLE `registrar`
  MODIFY `registrar_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `school_term`
--
ALTER TABLE `school_term`
  MODIFY `term_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `section`
--
ALTER TABLE `section`
  MODIFY `section_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `shift_credit`
--
ALTER TABLE `shift_credit`
  MODIFY `shift_credit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `subject`
--
ALTER TABLE `subject`
  MODIFY `subject_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT for table `teacher`
--
ALTER TABLE `teacher`
  MODIFY `teacher_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transferee_credit`
--
ALTER TABLE `transferee_credit`
  MODIFY `credit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admission_application`
--
ALTER TABLE `admission_application`
  ADD CONSTRAINT `fk_admission_application_program_id` FOREIGN KEY (`program_id`) REFERENCES `program` (`program_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_admission_application_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_admission_application_validated_by` FOREIGN KEY (`validated_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `admission_staff`
--
ALTER TABLE `admission_staff`
  ADD CONSTRAINT `fk_admission_staff_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `class_offering`
--
ALTER TABLE `class_offering`
  ADD CONSTRAINT `fk_class_offering_section_id` FOREIGN KEY (`section_id`) REFERENCES `section` (`section_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_class_offering_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_class_offering_teacher_id` FOREIGN KEY (`teacher_id`) REFERENCES `teacher` (`teacher_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_class_offering_term_id` FOREIGN KEY (`term_id`) REFERENCES `school_term` (`term_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `curriculum`
--
ALTER TABLE `curriculum`
  ADD CONSTRAINT `fk_curriculum_program_id` FOREIGN KEY (`program_id`) REFERENCES `program` (`program_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `curriculum_subject`
--
ALTER TABLE `curriculum_subject`
  ADD CONSTRAINT `fk_curriculum_subject_curriculum_id` FOREIGN KEY (`curriculum_id`) REFERENCES `curriculum` (`curriculum_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_curriculum_subject_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `enrolled_subject`
--
ALTER TABLE `enrolled_subject`
  ADD CONSTRAINT `fk_enrolled_subject_enrollment_id` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollment` (`enrollment_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrolled_subject_offering_id` FOREIGN KEY (`offering_id`) REFERENCES `class_offering` (`offering_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `enrollment`
--
ALTER TABLE `enrollment`
  ADD CONSTRAINT `fk_enrollment_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrollment_curriculum_id` FOREIGN KEY (`curriculum_id`) REFERENCES `curriculum` (`curriculum_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrollment_section_id` FOREIGN KEY (`section_id`) REFERENCES `section` (`section_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrollment_source_shift_request_id` FOREIGN KEY (`source_shift_request_id`) REFERENCES `program_shift_request` (`request_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrollment_student_id` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrollment_term_id` FOREIGN KEY (`term_id`) REFERENCES `school_term` (`term_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `enrollment_document`
--
ALTER TABLE `enrollment_document`
  ADD CONSTRAINT `fk_enrollment_document_application_id` FOREIGN KEY (`application_id`) REFERENCES `admission_application` (`application_id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

--
-- Constraints for table `prerequisite`
--
ALTER TABLE `prerequisite`
  ADD CONSTRAINT `fk_prerequisite_prerequisite_subject_id` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prerequisite_subject_id` FOREIGN KEY (`subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `program`
--
ALTER TABLE `program`
  ADD CONSTRAINT `fk_program_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`department_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `program_shift_request`
--
ALTER TABLE `program_shift_request`
  ADD CONSTRAINT `fk_program_shift_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_program_shift_request_effective_term_id` FOREIGN KEY (`effective_term_id`) REFERENCES `school_term` (`term_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_program_shift_request_from_curriculum_id` FOREIGN KEY (`from_curriculum_id`) REFERENCES `curriculum` (`curriculum_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_program_shift_request_student_id` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_program_shift_request_target_section_id` FOREIGN KEY (`target_section_id`) REFERENCES `section` (`section_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_program_shift_request_to_curriculum_id` FOREIGN KEY (`to_curriculum_id`) REFERENCES `curriculum` (`curriculum_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `registrar`
--
ALTER TABLE `registrar`
  ADD CONSTRAINT `fk_registrar_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registrar_created_by` FOREIGN KEY (`created_by`) REFERENCES `accounts` (`account_id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_registrar_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`department_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `school_term`
--
ALTER TABLE `school_term`
  ADD CONSTRAINT `fk_school_term_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `section`
--
ALTER TABLE `section`
  ADD CONSTRAINT `fk_section_program_id` FOREIGN KEY (`program_id`) REFERENCES `program` (`program_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `shift_credit`
--
ALTER TABLE `shift_credit`
  ADD CONSTRAINT `fk_shift_credit_credited_subject_id` FOREIGN KEY (`credited_subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_shift_credit_enrolled_subject_id` FOREIGN KEY (`enrolled_subject_id`) REFERENCES `enrolled_subject` (`enrolled_subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_shift_credit_evaluated_by` FOREIGN KEY (`evaluated_by`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_shift_credit_request_id` FOREIGN KEY (`request_id`) REFERENCES `program_shift_request` (`request_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `student`
--
ALTER TABLE `student`
  ADD CONSTRAINT `fk_student_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_student_application_id` FOREIGN KEY (`application_id`) REFERENCES `admission_application` (`application_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `teacher`
--
ALTER TABLE `teacher`
  ADD CONSTRAINT `fk_teacher_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_teacher_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`department_id`) ON DELETE NO ACTION ON UPDATE CASCADE;

--
-- Constraints for table `transferee_credit`
--
ALTER TABLE `transferee_credit`
  ADD CONSTRAINT `fk_transferee_credit_credited_subject_id` FOREIGN KEY (`credited_subject_id`) REFERENCES `subject` (`subject_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transferee_credit_student_id` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE NO ACTION ON UPDATE CASCADE;
COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
