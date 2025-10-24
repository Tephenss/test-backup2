-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 24, 2025 at 03:16 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('admin','teacher','student') NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `user_type`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(3, 1, 'admin', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-09-30 01:57:57'),
(4, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-09-30 02:05:51'),
(5, 2025005, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-02 14:38:38'),
(6, 2025005, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-09 13:34:45'),
(7, 2025007, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-09 14:43:38'),
(8, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:39:11'),
(9, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:42:56'),
(10, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:46:17'),
(11, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:48:46'),
(12, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:51:12'),
(13, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:55:16'),
(14, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 00:58:06'),
(15, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 01:08:27'),
(16, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 01:12:19'),
(17, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 01:24:05'),
(18, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 01:26:05'),
(19, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 01:30:07'),
(20, 2025005, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-10 02:08:18'),
(21, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-20 13:13:03'),
(22, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-20 13:39:51'),
(23, 0, 'teacher', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-20 14:36:53'),
(24, 2025, 'student', 'Password Reset', 'Password was reset successfully', NULL, NULL, '2025-10-20 14:45:21');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `admin_id` varchar(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `admin_id`, `username`, `password`, `full_name`, `email`, `created_at`, `updated_at`) VALUES
(1, 'A0001', 'admin', '$2y$10$UpO.lCuqO17Jr3DrY2ZxKeaknZcysPPGbb8Yb02zlsBUeDqt7A.lK', 'System Administrator', 'markstephenespinosa@gmail.com', '2025-04-22 11:18:23', '2025-10-02 15:44:55');

-- --------------------------------------------------------

--
-- Table structure for table `assessment_types`
--

CREATE TABLE `assessment_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assessment_types`
--

INSERT INTO `assessment_types` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'Quiz', 'Regular class quizzes', '2025-04-15 07:53:22'),
(2, 'Midterm Exam', 'Midterm examination', '2025-04-15 07:53:22'),
(3, 'Final Exam', 'Final examination', '2025-04-15 07:53:22'),
(4, 'Assignment', 'Take-home assignments', '2025-04-15 07:53:22'),
(5, 'Project', 'Course projects', '2025-04-15 07:53:22'),
(6, 'Laboratory', 'Lab work and exercises', '2025-04-15 07:53:22'),
(7, 'Class Participation', 'Participation in class discussions', '2025-04-15 07:53:22');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','late','excused') NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `class_id`, `student_id`, `date`, `status`, `recorded_by`, `created_at`) VALUES
(2, 10, 102, '2025-09-30', 'absent', 1, '2025-09-30 03:14:32'),
(3, 10, 62, '2025-09-30', 'present', 1, '2025-09-30 03:14:32');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `section` varchar(20) NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `year_level` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `subject_id`, `teacher_id`, `section`, `academic_year`, `semester`, `status`, `created_at`, `year_level`) VALUES
(1, 53, 11, 'A', '2024-2025', 0, 'active', '2025-09-28 15:26:30', NULL),
(9, 52, 1, 'B', '2024-2025', 1, 'active', '2025-04-28 15:46:00', NULL),
(10, 52, 1, 'A', '2024-2025', 1, 'active', '2025-09-30 03:03:55', '0000-00-00 00:00:00'),
(11, 53, 11, 'A', '2024-2025', 1, 'active', '2025-09-30 03:04:27', '0000-00-00 00:00:00'),
(12, 54, 15, 'A', '2024-2025', 1, 'active', '2025-09-30 03:04:27', '0000-00-00 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `class_students`
--

CREATE TABLE `class_students` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('active','dropped','withdrawn') DEFAULT 'active',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_students`
--

INSERT INTO `class_students` (`id`, `class_id`, `student_id`, `status`, `enrolled_at`) VALUES
(1, 10, 62, '', '2025-09-30 03:17:08'),
(2, 10, 102, '', '2025-09-30 03:17:23'),
(6, 1, 62, '', '2025-09-30 14:24:10'),
(7, 11, 62, '', '2025-09-30 14:24:10'),
(8, 12, 62, '', '2025-09-30 14:24:10'),
(9, 1, 102, '', '2025-09-30 14:24:10'),
(10, 11, 102, '', '2025-09-30 14:24:10'),
(11, 12, 102, '', '2025-09-30 14:24:10'),
(12, 1, 105, 'active', '2025-10-09 04:21:32'),
(13, 10, 105, 'active', '2025-10-09 04:21:32'),
(14, 11, 105, 'active', '2025-10-09 04:21:32'),
(15, 12, 105, 'active', '2025-10-09 04:21:32'),
(16, 1, 106, 'active', '2025-10-20 13:26:58'),
(17, 10, 106, 'active', '2025-10-20 13:26:58'),
(18, 11, 106, 'active', '2025-10-20 13:26:58'),
(19, 12, 106, 'active', '2025-10-20 13:26:58'),
(20, 9, 108, 'active', '2025-10-20 13:30:02');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `code`, `name`, `description`, `created_at`) VALUES
(1, 'BSIT', 'Bachelor of Science in Information Technology', 'Information Technology program', '2025-04-25 18:05:56'),
(3, 'BSIS', 'Bachelor of Science in Information Systems', 'Information Systems program', '2025-04-25 18:05:56'),
(7, 'BSCS', 'Bachelor of Science in Computer Science', 'A comprehensive program that focuses on computational theory, programming, algorithms, and software development.', '2025-04-26 19:48:55');

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `user_type` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`id`, `user_id`, `username`, `user_type`, `status`, `ip_address`, `created_at`) VALUES
(0, 1, NULL, 'admin', 'success', '::1', '2025-09-28 14:42:40'),
(1, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 11:26:08'),
(2, NULL, 'admin', 'admin', 'failed', '192.168.1.5', '2025-04-22 11:28:43'),
(3, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 11:29:32'),
(4, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-22 11:30:25'),
(5, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 11:34:20'),
(6, NULL, 'teacher1', 'admin', 'failed', '192.168.1.5', '2025-04-22 11:35:07'),
(7, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-22 11:35:49'),
(8, NULL, 'admin', 'admin', 'success', '192.168.1.5', '2025-04-22 11:38:43'),
(9, NULL, 'teacher1', 'teacher', 'success', '192.168.1.5', '2025-04-22 11:38:56'),
(10, NULL, 'student1', 'student', 'success', '192.168.1.5', '2025-04-22 11:39:07'),
(11, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 11:40:50'),
(12, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-22 11:41:43'),
(13, 1, NULL, 'student', 'success', '192.168.1.5', '2025-04-22 11:42:23'),
(14, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-22 11:43:05'),
(15, NULL, 'espinosa', 'student', 'failed', '192.168.1.5', '2025-04-22 13:06:59'),
(16, NULL, 'admin', 'admin', 'failed', '192.168.1.5', '2025-04-22 13:58:24'),
(17, NULL, 'admin', 'admin', 'failed', '192.168.1.5', '2025-04-22 13:58:29'),
(18, NULL, 'admin', 'admin', 'failed', '192.168.1.5', '2025-04-22 13:58:34'),
(19, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-22 13:59:39'),
(20, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 14:00:14'),
(21, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-22 14:07:22'),
(22, NULL, 'mespinosa', 'student', 'failed', '::1', '2025-04-22 15:36:48'),
(23, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-23 07:06:29'),
(24, 1, NULL, 'teacher', 'success', '192.168.1.5', '2025-04-23 07:07:25'),
(25, 1, NULL, 'student', 'success', '192.168.1.5', '2025-04-23 07:12:34'),
(26, 1, NULL, 'admin', 'success', '192.168.1.5', '2025-04-23 07:13:55'),
(27, 1, NULL, 'admin', 'success', '::1', '2025-04-24 08:35:55'),
(28, NULL, 'mespinosa', 'student', 'failed', '::1', '2025-04-24 09:01:44'),
(29, NULL, 'mespinosa', 'student', 'failed', '::1', '2025-04-24 09:12:57'),
(30, 1, NULL, 'teacher', 'success', '::1', '2025-04-24 10:39:14'),
(31, 1, NULL, 'student', 'success', '::1', '2025-04-24 10:51:14'),
(32, 1, NULL, 'admin', 'success', '::1', '2025-04-24 10:55:06'),
(33, NULL, 'jayson', 'teacher', 'failed', '::1', '2025-04-25 21:14:28'),
(34, NULL, 'jayson', 'teacher', 'failed', '::1', '2025-04-25 21:15:57'),
(35, NULL, 'jayson', 'teacher', 'failed', '::1', '2025-04-25 21:19:25'),
(36, 1, NULL, 'admin', 'success', '::1', '2025-04-26 09:48:37'),
(37, NULL, 'jopea', 'teacher', 'failed', '::1', '2025-04-26 09:49:57'),
(38, NULL, 'jopea', 'teacher', 'failed', '::1', '2025-04-26 09:56:33'),
(39, 4, NULL, 'teacher', 'success', '::1', '2025-04-26 09:59:35'),
(40, 1, NULL, 'admin', 'success', '::1', '2025-04-26 16:27:10'),
(41, 11, NULL, 'teacher', 'success', '::1', '2025-04-26 18:39:18'),
(42, 12, NULL, 'teacher', 'success', '::1', '2025-04-26 20:21:48'),
(43, 1, NULL, 'admin', 'success', '::1', '2025-04-27 06:50:20'),
(44, 1, NULL, 'teacher', 'success', '::1', '2025-04-28 13:42:14'),
(45, NULL, 'abautista', 'student', 'failed', '::1', '2025-04-28 19:11:50'),
(46, 1, NULL, 'admin', 'success', '::1', '2025-04-29 05:10:25'),
(47, 1, NULL, 'admin', 'success', '::1', '2025-04-29 05:11:12'),
(48, 1, NULL, 'teacher', 'success', '::1', '2025-04-29 05:13:04'),
(49, 15, NULL, 'teacher', 'success', '::1', '2025-04-29 07:37:19'),
(50, 1, NULL, 'admin', 'success', '::1', '2025-04-30 02:15:20'),
(51, 1, NULL, 'admin', 'success', '::1', '2025-04-30 02:18:28'),
(52, 15, NULL, 'teacher', 'success', '::1', '2025-04-30 03:15:04'),
(53, 1, NULL, 'teacher', 'success', '::1', '2025-04-30 06:34:39'),
(54, 1, NULL, 'admin', 'success', '::1', '2025-04-30 06:35:30');

-- --------------------------------------------------------

--
-- Table structure for table `marks`
--

CREATE TABLE `marks` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `assessment_type_id` int(11) NOT NULL,
  `marks` decimal(5,2) NOT NULL,
  `total_marks` decimal(5,2) NOT NULL,
  `date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `term` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `course_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `name`, `course_id`, `year_level`, `created_at`, `updated_at`) VALUES
(3, 'B', 1, 1, '2025-04-26 19:07:30', '2025-04-26 19:07:30'),
(4, 'C', 1, 1, '2025-04-26 19:49:21', '2025-04-26 19:49:21'),
(5, 'D', 1, 1, '2025-04-27 08:15:47', '2025-04-27 08:15:47'),
(6, 'A', 1, 1, '2025-04-27 08:16:46', '2025-04-27 08:16:46'),
(7, 'A', 1, 2, '2025-04-27 09:15:41', '2025-04-27 09:15:41'),
(8, 'A', 1, 3, '2025-04-27 09:16:12', '2025-04-27 09:16:12'),
(9, 'A', 1, 4, '2025-04-27 09:16:37', '2025-04-27 09:16:37'),
(10, 'B', 1, 2, '2025-04-27 09:30:56', '2025-04-27 09:30:56'),
(11, 'C', 1, 2, '2025-04-27 09:35:52', '2025-04-27 09:35:52'),
(12, 'D', 1, 2, '2025-04-27 09:36:12', '2025-04-27 09:36:12'),
(13, 'B', 1, 3, '2025-04-27 09:53:02', '2025-04-27 09:53:02'),
(14, 'C', 1, 3, '2025-04-27 09:53:17', '2025-04-27 09:53:17'),
(15, 'D', 1, 3, '2025-04-27 09:53:47', '2025-04-27 09:53:47');

-- --------------------------------------------------------

--
-- Table structure for table `semester_settings`
--

CREATE TABLE `semester_settings` (
  `id` int(11) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `prelim_start` date DEFAULT NULL,
  `prelim_end` date DEFAULT NULL,
  `midterm_start` date DEFAULT NULL,
  `midterm_end` date DEFAULT NULL,
  `final_start` date DEFAULT NULL,
  `final_end` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `semester_settings`
--

INSERT INTO `semester_settings` (`id`, `semester`, `start_date`, `end_date`, `prelim_start`, `prelim_end`, `midterm_start`, `midterm_end`, `final_start`, `final_end`, `is_current`, `created_at`) VALUES
(1, '1st Semester', '2025-08-01', '2025-12-20', '2025-08-01', '2025-09-15', '2025-09-16', '2025-10-30', '2025-11-01', '2025-12-20', 1, '2025-09-30 02:23:40');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix_name` varchar(10) DEFAULT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated') NOT NULL,
  `birthdate` date NOT NULL,
  `place_of_birth` varchar(100) NOT NULL,
  `citizenship` varchar(50) NOT NULL,
  `address` text NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `course` varchar(50) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `form_138` varchar(255) DEFAULT NULL,
  `good_moral` varchar(255) DEFAULT NULL,
  `diploma` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `student_id`, `password`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `sex`, `civil_status`, `birthdate`, `place_of_birth`, `citizenship`, `address`, `phone_number`, `email`, `profile_picture`, `course`, `year_level`, `section`, `created_at`, `status`, `approved_at`, `form_138`, `good_moral`, `diploma`, `last_login`, `is_deleted`, `deleted_at`) VALUES
(42, '2025005', '$2y$10$r/YYRG7b1L7ZzQmKTyrx7eeJryoLXTjIa33rVAGtORAC8dixwz19K', 'Mark Stephen', 'Co', 'Espinosa', '', 'Male', 'Single', '2004-09-13', 'Manila', 'Filipino', 'purok 5 chicko, Barangay Pangil, Majayjay, Laguna, CALABARZON', '09555294182', 'markstephenespinosa@gmail.com', NULL, 'BSIT', 1, 'A', '2025-04-27 10:20:53', 'approved', NULL, NULL, NULL, NULL, NULL, 0, NULL),
(52, '2025007', '$2y$10$A/oGIqam8Z5kBnXe9Srnm.uDIAOXhKk7jyGHhhqe/RD98d1f2/58C', 'Kark stephen', 'Co', 'Espinosa', '', 'Male', 'Single', '2004-02-20', 'Manila', 'Filipino', 'purok 5, Barangay Pangil, Majayjay, Laguna, CALABARZON', '09555294182', 'iattendancemanagement@gmail.com', NULL, 'BSIT', 1, NULL, '2025-04-27 12:35:33', NULL, NULL, 'uploads/requirements/form138_1745757333_680e2495d171f.jpg', 'uploads/requirements/goodmoral_1745757333_680e2495d1b21.jpg', 'uploads/requirements/diploma_1745757333_680e2495d1e44.jpg', NULL, 0, NULL),
(62, '2025-008', '$2y$10$RBdHkPOVdW9ujIqv118RVu5gAU0AGWaNThDewkZ7GB6TRGd0fBk.C', 'Cian Clyde', 'Pasahol', 'Tandang', '', 'Male', 'Single', '2004-09-13', 'Manila', 'Filipino', 'purok 5 chicko, Barangay Pangil, Majayjay, Laguna, CALABARZON', '09555294182', 'cubejuliuscubejulius@gmail.com', NULL, 'BSIT', 4, 'A', '2025-04-29 06:55:13', 'approved', NULL, 'uploads/requirements/form138_1745909713_681077d1c8465.png', 'uploads/requirements/goodmoral_1745909713_681077d1c8f22.jpg', 'uploads/requirements/diploma_1745909713_681077d1c9810.jpg', NULL, 0, NULL),
(102, '2025-001', '$2y$10$JMIyrJ9K7FAXKwjjmodFS.fFVBh785ury7yW8MnHUEFbefMcYpcdW', 'Jolymar', 'Soroan', 'Opena', '', 'Male', 'Single', '2004-09-13', 'BAY LAGUNA', 'FILIPINO', 'dasdas, Barangay Pangil, Majayjay, Laguna, CALABARZON', '09554164329', 'accforsamsunh@gmail.com', NULL, 'BSIT', 4, 'A', '2025-09-30 01:25:31', 'approved', NULL, 'uploads/requirements/form138_1759195531_68db318b8d8e4.png', 'uploads/requirements/goodmoral_1759195531_68db318b8db71.png', 'uploads/requirements/diploma_1759195531_68db318b8ded4.png', NULL, 0, NULL),
(103, '2025-002', '$2y$10$ubzXtzS6aezSTB2EvlnOOOVSh2/t4D/Lz3NZp.gGeK5DGat2Ay0GS', 'Erishs', 'Oca', 'Bautista', '', 'Male', 'Single', '2007-09-10', 'BAY LAGUNA', 'FILIPINO', 'asas, Barangay Select Barangay, Indang, Cavite, CALABARZON', '09555294182', 'tandangcianclyde134@gmail.com', NULL, 'BSIT', 1, NULL, '2025-09-30 14:42:45', 'approved', NULL, 'uploads/requirements/form138_1759243365_68dbec6578676.png', 'uploads/requirements/goodmoral_1759243365_68dbec657898a.png', 'uploads/requirements/diploma_1759243365_68dbec6578c11.png', NULL, 0, NULL),
(105, '2025-003', '$2y$10$r1f3X9330vcBGKHXHVjmfu.tPE91Iu1MGb.oXGX4kgdgBOzIe9whG', 'Ayyash Jayo', 'Oca', 'Bautista', '', 'Female', 'Single', '2007-10-01', 'BAY LAGUNA', 'FILIPINO', 'dsdsd, Barangay Pansipit, Agoncillo, Batangas, CALABARZON', '09554164329', 'hiddencuisine1@gmail.com', NULL, 'BSIT', 1, 'A', '2025-10-09 04:13:11', 'approved', NULL, 'uploads/requirements/form138_1759983191_68e73657ba910.png', 'uploads/requirements/goodmoral_1759983191_68e73657bae86.png', 'uploads/requirements/diploma_1759983191_68e73657bb236.png', NULL, 0, NULL),
(106, '2025-004', '$2y$10$KP5/RiMrDXv5.y92M.ILBeuQfU4RuDdMJkCGyfGxcmfGLSRj11qam', 'Kenjie', 'Co', 'Espinosa', '', 'Male', 'Single', '2007-10-01', 'BAY LAGUNA', 'FILIPINO', 'gfgf, Barangay Calumpang Lejos I, Indang, Cavite, CALABARZON', '09555294182', 'cubejuliuscqubejulius@gmail.com', NULL, 'BSIT', 1, 'A', '2025-10-09 04:50:26', 'approved', NULL, 'uploads/requirements/form138_1759985426_68e73f12da6f2.png', 'uploads/requirements/goodmoral_1759985426_68e73f12da9c5.png', 'uploads/requirements/diploma_1759985426_68e73f12dac81.png', NULL, 0, NULL),
(108, '2025-005', '$2y$10$/KhVvIRTqs5HUknt0cEEVO.EP//9u7fa.DtkpP29v/ZJ5OkaFNh7O', 'Gars', 'Gars', 'Espino', '', 'Male', 'Single', '2007-10-03', 'BAY LAGUNA', 'FILIPINO', 'gdgd, Barangay Burgos, Majayjay, Laguna, CALABARZON', '09554164329', 'calmaerish26@gmail.com', NULL, 'BSIT', 1, 'B', '2025-10-20 13:18:19', 'approved', NULL, 'uploads/requirements/form138_1760966299_68f6369b79b2e.png', 'uploads/requirements/goodmoral_1760966299_68f6369b7a042.png', 'uploads/requirements/diploma_1760966299_68f6369b7a2df.png', NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `units` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `teacher_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `year_level` int(11) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `description`, `units`, `created_at`, `teacher_id`, `section_id`, `year_level`, `is_deleted`, `deleted_at`) VALUES
(52, 'CS101', 'Introduction to Computing', 'Basic concepts of computer systems and programming', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(53, 'CS102', 'Computer Programming 1', 'Fundamentals of programming using C++', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(54, 'CS103', 'Discrete Mathematics', 'Mathematical foundations for computer science', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(55, 'GE101', 'Understanding the Self', 'Study of the self and identity', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(56, 'GE102', 'Mathematics in the Modern World', 'Mathematical concepts in real-world applications', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(57, 'GE103', 'Science, Technology and Society', 'Impact of science and technology on society', 3, '2025-04-28 04:06:20', NULL, NULL, 1, 0, NULL),
(58, 'CS201', 'Data Structures and Algorithms', 'Study of data organization and manipulation', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(59, 'CS202', 'Object-Oriented Programming', 'OOP concepts using Java', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(60, 'CS203', 'Computer Organization and Architecture', 'Computer hardware and system design', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(61, 'CS204', 'Database Management Systems', 'Database design and SQL', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(62, 'CS205', 'Web Development', 'Web technologies and programming', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(63, 'GE201', 'Ethics', 'Ethical principles and moral values', 3, '2025-04-28 04:06:20', NULL, NULL, 2, 0, NULL),
(64, 'CS301', 'Software Engineering', 'Software development methodologies', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(65, 'CS302', 'Operating Systems', 'OS concepts and implementation', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(66, 'CS303', 'Computer Networks', 'Network protocols and architecture', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(67, 'CS304', 'Mobile Application Development', 'Android/iOS app development', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(68, 'CS305', 'Artificial Intelligence', 'AI concepts and applications', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(69, 'CS306', 'Information Security', 'Cybersecurity fundamentals', 3, '2025-04-28 04:06:20', NULL, NULL, 3, 0, NULL),
(70, 'CS401', 'Thesis 1', 'Research methodology and proposal', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL),
(71, 'CS402', 'Thesis 2', 'Project implementation and defense', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL),
(72, 'CS403', 'Cloud Computing', 'Cloud services and deployment', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL),
(73, 'CS404', 'Data Science', 'Data analytics and machine learning', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL),
(74, 'CS405', 'Professional Practice', 'Industry practices and ethics', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL),
(75, 'CS406', 'IT Project Management', 'Project planning and execution', 3, '2025-04-28 04:06:20', NULL, NULL, 4, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subject_assignments`
--

CREATE TABLE `subject_assignments` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subject_assignments`
--

INSERT INTO `subject_assignments` (`id`, `teacher_id`, `subject_id`, `section_id`, `semester`, `created_at`, `updated_at`, `start_date`, `end_date`) VALUES
(6, 1, 52, 3, '1st Semester', '2025-04-28 05:20:44', '2025-04-28 05:20:44', '2025-04-28', '2025-04-28'),
(15, 11, 53, 6, '1st Semester', '2025-04-28 16:53:55', '2025-04-28 16:53:55', '2025-04-29', '2025-05-29'),
(16, 15, 54, 6, '1st Semester', '2025-04-30 03:44:33', '2025-04-30 03:44:33', '2025-04-28', '2025-05-28'),
(0, 1, 52, 6, '1st Semester', '2025-09-30 02:50:56', '2025-09-30 02:50:56', '2025-08-01', '2025-12-20');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `teacher_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix_name` varchar(20) DEFAULT NULL,
  `sex` varchar(10) NOT NULL,
  `civil_status` varchar(20) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `phone_number` varchar(30) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `course` varchar(50) NOT NULL DEFAULT 'BSIT',
  `last_login` datetime DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `teacher_id`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `sex`, `civil_status`, `birth_date`, `phone_number`, `username`, `password`, `full_name`, `email`, `phone`, `created_at`, `course`, `last_login`, `avatar`, `is_deleted`, `deleted_at`) VALUES
(1, 'T0001', 'John', NULL, 'Doe', NULL, '', '', '2004-09-13', '', 'teacher1', '$2y$10$HAk1U2D7JLJfSrGaqeqD3uplVIn047vV/qIVrnUXEPHe8BdIJLpp.', 'John Doe', 'markstephenespinosa@gmail.com', NULL, '2025-04-15 07:53:22', 'BSIT', NULL, NULL, 0, NULL),
(11, 'T0011', 'Erish', 'montemayor', 'Opena', '', 'Male', 'Single', '2000-09-19', '09554164329', 'eopena', '$2y$10$Rt8rVxUvFw91kO/ZkypFgOFnFMN/FlxdJE3GyTrfxNPc85JY8PX2G', 'Erish montemayor Opena', 'iattendancemanagement@gmail.com', NULL, '2025-04-26 18:36:30', 'BSIT', NULL, NULL, 0, NULL),
(12, 'T0012', 'cian clyde', 'pasahol', 'tandang', '', 'Male', 'Single', '2002-02-19', '09090636354', 'ctandang', '$2y$10$WXJE3NGfdGJ8Zqto5t6dz.kIf90oo96OibE5cc5hs.z4Snpo0iVcm', 'cian clyde pasahol tandang', 'tandangc5@gmail.com', NULL, '2025-04-26 20:20:21', 'BSIT', NULL, NULL, 0, NULL),
(15, 'T0015', 'Ayyash', 'Oca', 'Bautista', '', 'Female', 'Separated', '2005-12-02', '09090636354', '', '$2y$10$C6O/VQ/l93RygnOms7Z9zub8zQcIDhUOCtBqqJLJhj9K/OFpydSQi', 'Ayyash Oca Bautista', 'ayyashjb@gmail.com', NULL, '2025-04-29 07:34:13', 'BSIT', NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `timetable`
--

CREATE TABLE `timetable` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `timetable`
--

INSERT INTO `timetable` (`id`, `class_id`, `day_of_week`, `start_time`, `end_time`, `room`, `created_at`) VALUES
(0, 10, 'Monday', '08:00:00', '11:00:00', '303', '2025-10-21 03:52:01');

-- --------------------------------------------------------

--
-- Table structure for table `verification_codes`
--

CREATE TABLE `verification_codes` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `code` varchar(6) NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `verification_codes`
--

INSERT INTO `verification_codes` (`id`, `user_id`, `user_type`, `code`, `is_used`, `expires_at`, `created_at`) VALUES
(0, 'T0001', 'teacher', '530553', 1, '2025-10-08 11:56:41', '2025-10-08 11:56:10'),
(0, '42', 'student', '150025', 1, '2025-10-20 13:21:14', '2025-10-09 14:49:42'),
(0, '15', 'teacher', '312226', 1, '2025-10-20 13:21:14', '2025-10-10 00:25:21'),
(0, '62', 'student', '460410', 1, '2025-10-20 13:21:14', '2025-10-10 00:40:23'),
(0, '11', 'teacher', '074810', 1, '2025-10-20 13:21:14', '2025-10-10 00:44:55'),
(0, '106', 'student', '962753', 1, '2025-10-20 13:21:14', '2025-10-10 00:49:47'),
(0, '1', 'student', '907887', 1, '2025-10-20 14:34:13', '2025-10-20 14:26:23'),
(0, '108', 'student', '218844', 1, '2025-10-20 14:44:53', '2025-10-20 14:44:24'),
(0, '1', 'admin', '992515', 1, '2025-10-21 03:51:28', '2025-10-21 03:50:45'),
(0, '1', 'teacher', '471704', 1, '2025-10-21 04:19:38', '2025-10-21 04:18:55');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `user_type` (`user_type`),
  ADD KEY `action` (`action`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `admin_id` (`admin_id`);

--
-- Indexes for table `assessment_types`
--
ALTER TABLE `assessment_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`class_id`,`student_id`,`date`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `attendance_ibfk_2` (`student_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class` (`subject_id`,`section`,`academic_year`,`semester`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `class_students`
--
ALTER TABLE `class_students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`class_id`,`student_id`),
  ADD KEY `class_students_ibfk_2` (`student_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `marks`
--
ALTER TABLE `marks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mark` (`student_id`,`class_id`,`assessment_type_id`,`date`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `assessment_type_id` (`assessment_type_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `semester_settings`
--
ALTER TABLE `semester_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `teacher_id` (`teacher_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `class_students`
--
ALTER TABLE `class_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `semester_settings`
--
ALTER TABLE `semester_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
