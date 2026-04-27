-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 22, 2026 at 03:04 PM
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
-- Database: `attendance`
--

-- --------------------------------------------------------

--
-- Table structure for table `advance_requests`
--

CREATE TABLE `advance_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `needed_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `advance_requests`
--

INSERT INTO `advance_requests` (`id`, `user_id`, `amount`, `reason`, `status`, `needed_date`, `created_at`) VALUES
(1, 3, 5000, 'Family Problem', 'Pending', '2026-04-22', '2026-04-22 09:12:01'),
(2, 5, 5000, 'Emergency', 'Pending', '2026-04-22', '2026-04-22 09:56:04');

-- --------------------------------------------------------

--
-- Table structure for table `appreciations`
--

CREATE TABLE `appreciations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `month` varchar(50) NOT NULL,
  `reason` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appreciations`
--

INSERT INTO `appreciations` (`id`, `user_id`, `month`, `reason`, `created_at`) VALUES
(1, 4, 'April 2026', 'Exceptional performance in project handling and team work', '2026-04-22 10:02:57'),
(2, 5, 'April 2026', 'He did 2 Lakh sell in this month congratulations.', '2026-04-22 10:05:00'),
(3, 3, 'April 2026', 'Congratulation', '2026-04-22 10:13:26');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `radius` int(11) NOT NULL DEFAULT 100,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `code`, `radius`, `latitude`, `longitude`, `created_at`) VALUES
(1, 'New Delhi', 'BR1', 20, 28.68254010, 77.04786480, '2026-04-22 05:11:24'),
(2, 'Patna', 'BR2', 20, 19.07000000, 72.87770000, '2026-04-22 09:21:45'),
(3, 'Mumbai', 'MUM', 100, 19.07600000, 72.87770000, '2026-04-22 12:29:02');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`) VALUES
(1, 'Accountant', '2026-04-22 05:51:42'),
(2, 'Stock Manager', '2026-04-22 05:51:49'),
(3, 'HR', '2026-04-22 05:51:53'),
(4, 'Developer', '2026-04-22 05:51:59'),
(5, 'Social Media Handler', '2026-04-22 05:52:16'),
(6, 'Dealing Staff', '2026-04-22 05:52:27'),
(7, 'Manager', '2026-04-22 05:52:32'),
(8, 'Designer', '2026-04-22 05:52:46'),
(9, 'Labour', '2026-04-22 05:52:51'),
(10, 'Guard', '2026-04-22 05:52:59'),
(11, 'Maid', '2026-04-22 05:53:09');

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `holiday_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `holidays`
--

INSERT INTO `holidays` (`id`, `name`, `holiday_date`, `created_at`) VALUES
(1, 'Independence Day', '2026-08-15', '2026-04-22 08:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_from` date NOT NULL,
  `leave_to` date NOT NULL,
  `reason` text NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `user_id`, `leave_from`, `leave_to`, `reason`, `document_path`, `status`, `created_at`) VALUES
(1, 3, '2026-04-22', '2026-04-30', 'Out', NULL, 'Approved', '2026-04-22 07:23:07'),
(2, 3, '2026-04-30', '2026-05-12', 'Out', NULL, 'Rejected', '2026-04-22 07:33:10'),
(3, 5, '2026-04-22', '2026-04-30', 'Emergency', NULL, 'Rejected', '2026-04-22 09:18:21'),
(4, 3, '2026-05-07', '2026-05-27', 'out', NULL, 'Approved', '2026-04-22 11:36:45'),
(5, 5, '2026-04-22', '2026-04-25', 'test', 'uploads/prescriptions/E5_1776861585.jpg', 'Pending', '2026-04-22 12:39:45');

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

CREATE TABLE `salaries` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `month_year` varchar(50) NOT NULL,
  `base_salary` int(11) NOT NULL,
  `present_days` int(11) NOT NULL DEFAULT 26,
  `late_fines` int(11) NOT NULL DEFAULT 0,
  `ot_bonus` int(11) NOT NULL DEFAULT 0,
  `net_payable` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'checkin_time', '09:30', '2026-04-22 08:54:37'),
(2, 'checkout_time', '18:30', '2026-04-22 08:54:37'),
(3, 'late_fine', '100', '2026-04-22 08:54:37'),
(4, 'ot_rate', '50', '2026-04-22 08:54:37');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','HR','Employee') NOT NULL DEFAULT 'Employee',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `base_salary` int(11) NOT NULL DEFAULT 25000,
  `status` varchar(20) DEFAULT 'Active',
  `profile_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `emp_id`, `name`, `designation`, `joining_date`, `email`, `phone_number`, `password`, `role`, `created_at`, `branch_id`, `department_id`, `base_salary`, `status`, `profile_photo`) VALUES
(1, 'E-0001', 'Ajeet Kumar', NULL, NULL, 'admin@example.com', NULL, '$2y$10$o491jJkuR901KsSJ6u0A1.YASZ286i8xQwwv4BxDi.FFWrrcu31QO', 'Admin', '2026-04-22 04:49:40', NULL, NULL, 25000, 'Active', NULL),
(2, 'E-0002', 'Anita Singh', NULL, NULL, 'hr@example.com', NULL, '$2y$10$IEzkSVFC5AUQUAoeCnWsc.57ppiO9uPFSYC4HIWlFRFctrN9Mgieu', 'HR', '2026-04-22 04:49:40', 1, NULL, 25000, 'Active', NULL),
(3, 'E-0003', 'Priya Sharma', 'Dealing', '2024-05-07', 'employee@example.com', '+919835441834', '$2y$10$zWS5kwRB/9nj.5kn.s4DJ.KeFvXcCmbBmFyRZeg6rF7JHJ.2gXG2u', 'Employee', '2026-04-22 04:49:41', 1, 6, 25000, 'Active', 'uploads/profiles/P3_1776859708.jpg'),
(4, 'E-0004', 'Ajay Kumar', 'Developer', NULL, 'ajay.kumar174@company.com', NULL, '$2y$10$TOmd1MQX4xcfUWXzB8RJ4.Tu8nMNsgyxi.ODnX/A4bIfRv73vJpMW', 'Employee', '2026-04-22 06:03:59', 1, 4, 30000, 'Active', NULL),
(5, 'E-0005', 'Vishnu', 'Social Media Manager', '2026-04-01', 'vishnu969@company.com', '', '$2y$10$.SIXSJ2lDniSvki.0ZtI.egmYl3UhELnA7wsouBdZEx0H18FREL8W', 'Employee', '2026-04-22 09:16:39', 2, 5, 30000, 'Active', 'uploads/profiles/P5_1776862130.png');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `advance_requests`
--
ALTER TABLE `advance_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `appreciations`
--
ALTER TABLE `appreciations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `emp_id` (`emp_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `advance_requests`
--
ALTER TABLE `advance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `appreciations`
--
ALTER TABLE `appreciations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
