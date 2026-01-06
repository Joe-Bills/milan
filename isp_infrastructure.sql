-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 06, 2026 at 07:43 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `isp_infrastructure`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_actions`
--

CREATE TABLE `admin_actions` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `admin_username` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `target_username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `action_time` int(11) NOT NULL,
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_actions`
--

INSERT INTO `admin_actions` (`id`, `admin_id`, `admin_username`, `action_type`, `target_user_id`, `target_username`, `ip_address`, `user_agent`, `action_time`, `details`) VALUES
(4, 1, NULL, 'register_user', NULL, NULL, '::1', NULL, 1767679933, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `boxes`
--

CREATE TABLE `boxes` (
  `id` int(11) NOT NULL,
  `box_name` varchar(100) NOT NULL,
  `total_cores` int(11) NOT NULL,
  `location_lat` decimal(10,8) DEFAULT NULL,
  `location_lng` decimal(11,8) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `boxes`
--

INSERT INTO `boxes` (`id`, `box_name`, `total_cores`, `location_lat`, `location_lng`, `address`, `created_by`, `created_at`) VALUES
(25, 'palace', 2, -3.38319650, 36.68492530, 'Address lookup failed', 1, '2025-10-11 14:08:49'),
(27, 'misungw', 2, -3.37487370, 36.69148250, 'Address lookup failed', 1, '2025-10-20 09:11:38'),
(30, 'ngara', 2, -3.37482280, 36.69148210, 'njiro', 1, '2025-10-27 13:59:31'),
(31, 'police line', 2, -1.29947965, 36.68813646, 'Address lookup failed', 1, '2025-11-01 09:16:25'),
(32, 'mianzin', 2, -3.40227970, 36.68559950, 'Engosheratoni Avenue, Sombetini, Arusha, Arusha Municipal, Arusha, Northern Zone, 23116, Tanzania', 1, '2025-11-04 11:55:35'),
(36, 'sanawar', 3, -3.37235420, 36.68829610, 'Mtendeni Street, Majengo, Levolosi, Arusha, Arusha Municipal, Arusha, Northern Zone, 23103, Tanzania', 1, '2025-11-11 12:13:54'),
(38, 'ngara', 2, -3.38586020, 36.70447320, 'Mwanama, Olorien, Arusha, Arusha Municipal, Arusha, Northern Zone, 23110, Tanzania', 1, '2025-12-13 07:56:35');

-- --------------------------------------------------------

--
-- Table structure for table `connections`
--

CREATE TABLE `connections` (
  `id` int(11) NOT NULL,
  `source_type` enum('core','splitter') NOT NULL,
  `source_id` int(11) NOT NULL,
  `destination_type` enum('core','splitter','customer') NOT NULL,
  `destination_id` int(11) DEFAULT NULL,
  `destination_name` varchar(100) DEFAULT NULL,
  `connection_type` enum('direct','splitter') NOT NULL,
  `power_level` decimal(5,2) DEFAULT NULL,
  `connection_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cores`
--

CREATE TABLE `cores` (
  `id` int(11) NOT NULL,
  `box_id` int(11) DEFAULT NULL,
  `core_number` int(11) NOT NULL,
  `color` varchar(50) NOT NULL,
  `power_level` decimal(5,2) DEFAULT NULL,
  `is_connected` tinyint(1) DEFAULT 0,
  `connected_to` varchar(100) DEFAULT NULL,
  `technician_name` varchar(100) DEFAULT NULL,
  `customer_location` varchar(255) DEFAULT NULL,
  `customer_image` varchar(255) DEFAULT NULL,
  `connection_date` timestamp NULL DEFAULT NULL,
  `technician_notes` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `connection_status` enum('available','connected','split') DEFAULT 'available',
  `connected_to_id` int(11) DEFAULT NULL,
  `connected_to_type` enum('core','splitter','customer') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cores`
--

INSERT INTO `cores` (`id`, `box_id`, `core_number`, `color`, `power_level`, `is_connected`, `connected_to`, `technician_name`, `customer_location`, `customer_image`, `connection_date`, `technician_notes`, `notes`, `connection_status`, `connected_to_id`, `connected_to_type`) VALUES
(3, 25, 1, 'Aqua', 21.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(4, 25, 2, 'Rose', 17.00, 1, 'joseph', NULL, NULL, NULL, NULL, NULL, NULL, 'connected', NULL, 'customer'),
(7, 27, 1, 'Aqua', 21.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(8, 27, 2, 'Aqua', 12.00, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'split', 0, 'splitter'),
(11, 30, 1, 'Blue', -20.00, 1, 'anet', NULL, NULL, NULL, NULL, NULL, NULL, 'connected', NULL, 'customer'),
(12, 30, 2, 'Rose', 12.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(13, 31, 1, 'Yellow', 12.00, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'split', NULL, 'splitter'),
(14, 31, 2, 'Aqua', 12.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(15, 32, 1, 'Red', 21.00, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'split', NULL, 'splitter'),
(16, 32, 2, 'Orange', 21.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(23, 36, 1, 'Orange', -21.00, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'split', 0, 'splitter'),
(24, 36, 2, 'Aqua', 13.00, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'available', NULL, NULL),
(25, 36, 3, 'Green', 12.00, 1, 'juma', 'mr', 'Hq', 'uploads/customer_images/customer_25_1763979154.PNG', '2025-11-24 08:12:34', 'done', NULL, 'connected', NULL, 'customer'),
(28, 38, 1, 'Aqua', 21.00, 1, 'juma', NULL, NULL, NULL, NULL, NULL, NULL, 'connected', NULL, 'customer'),
(29, 38, 2, 'Blue', 21.00, 1, 'anna', NULL, NULL, NULL, NULL, NULL, NULL, 'connected', NULL, 'customer');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempt_time` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `username`, `ip_address`, `user_agent`, `success`, `attempt_time`) VALUES
(23, 'gerald', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 1, 1767679870),
(24, 'joseph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 1, 1767679956);

-- --------------------------------------------------------

--
-- Table structure for table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `splitters`
--

CREATE TABLE `splitters` (
  `id` int(11) NOT NULL,
  `splitter_name` varchar(100) NOT NULL,
  `splitter_type` enum('1x2','1x4','1x8','1x16','1x32') NOT NULL,
  `location_lat` decimal(10,8) DEFAULT NULL,
  `location_lng` decimal(11,8) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `input_power` decimal(5,2) DEFAULT NULL,
  `output_power` decimal(5,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `splitter_cores`
--

CREATE TABLE `splitter_cores` (
  `id` int(11) NOT NULL,
  `core_id` int(11) NOT NULL,
  `splitter_core_number` int(11) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_location` text DEFAULT NULL,
  `customer_image` varchar(255) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `power` decimal(5,2) DEFAULT NULL,
  `status` enum('connected','available','faulty') DEFAULT 'available',
  `core_detail` text DEFAULT NULL,
  `connected_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `splitter_cores`
--

INSERT INTO `splitter_cores` (`id`, `core_id`, `splitter_core_number`, `customer_name`, `customer_location`, `customer_image`, `comment`, `created_at`, `power`, `status`, `core_detail`, `connected_at`, `updated_at`) VALUES
(1, 8, 1, 'jamali', '', 'uploads/splitter_customers/customer_1_1763976442.PNG', 'connected', '2025-10-20 09:11:38', 21.00, 'connected', NULL, NULL, '2025-11-24 09:27:22'),
(2, 8, 2, '', NULL, NULL, 'not connected', '2025-10-20 09:11:38', 22.00, 'available', NULL, NULL, '2025-11-04 12:06:45'),
(3, 13, 1, 'juliana', NULL, NULL, 'red core connected', '2025-11-01 09:16:26', NULL, 'connected', NULL, NULL, '2025-11-01 09:16:26'),
(4, 13, 2, '', NULL, NULL, '', '2025-11-01 09:16:26', NULL, 'available', NULL, NULL, '2025-11-01 09:16:26'),
(5, 13, 3, '', NULL, NULL, '', '2025-11-01 09:16:26', NULL, 'available', NULL, NULL, '2025-11-01 09:16:26'),
(6, 15, 1, 'justine', NULL, NULL, '', '2025-11-04 11:55:35', NULL, 'connected', NULL, NULL, '2025-11-04 11:55:35'),
(7, 15, 2, '', NULL, NULL, '', '2025-11-04 11:55:35', NULL, 'available', NULL, NULL, '2025-11-04 11:55:35'),
(16, 23, 1, 'juma', NULL, NULL, '', '2025-11-13 12:23:06', 23.00, 'connected', NULL, '2025-11-13 12:23:06', '2025-11-13 12:23:06'),
(17, 23, 2, 'stan', NULL, NULL, '', '2025-11-13 12:23:07', 21.00, 'connected', NULL, '2025-11-13 12:23:07', '2025-11-13 12:23:07');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `log_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `severity` enum('info','warning','error','critical') DEFAULT 'info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `password_changed` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `created_at`, `is_admin`, `is_active`, `last_login`, `password_changed`) VALUES
(1, 'gerald', 'gerald@gmail.com', '$2y$10$eftJOftq6wKN73uV2cJBwOtQN4xhs2tdhum09FDxJ4L6o/Iw99Mmi', '2025-10-07 08:15:51', 1, 1, NULL, '2025-12-13 16:16:26'),
(5, 'daniel', 'daniel@gmail.com', '$2y$10$X8Q5xnJV88xEFQz0hGe.luVos/QSttks3hGUMXC1rZH4CwUt0YZoS', '2025-12-02 11:55:00', 1, 0, NULL, '2025-12-13 16:16:26'),
(6, 'samwel', 'samwel@gmail.com', '$2y$10$7iEe5wRvlc6cGEwvCmsW3.yu8TYZ5Hksvvki.ieXZVtPIUAaVb.pa', '2025-12-02 12:58:10', 0, 1, NULL, '2025-12-13 16:16:26'),
(7, 'joseph', 'josephbilla746@gmail.com', '$2y$10$/w.JnCElHPSHi.w3maImBeTHbL0QqcjACDLIQKZic8sJC5wNlkU0K', '2026-01-06 06:12:13', 1, 1, NULL, '2026-01-06 09:12:13');

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `log_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_logs`
--

INSERT INTO `user_logs` (`id`, `user_id`, `action`, `ip_address`, `log_time`) VALUES
(1, 1, 'login', '::1', '2025-12-13 16:20:32'),
(2, 1, 'login', '::1', '2025-12-13 16:22:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_actions`
--
ALTER TABLE `admin_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_time` (`admin_id`,`action_time`),
  ADD KEY `idx_ip_time` (`ip_address`,`action_time`),
  ADD KEY `idx_action_type` (`action_type`);

--
-- Indexes for table `boxes`
--
ALTER TABLE `boxes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `connections`
--
ALTER TABLE `connections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `cores`
--
ALTER TABLE `cores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `box_id` (`box_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  ADD KEY `idx_username_time` (`username`,`attempt_time`);

--
-- Indexes for table `security_logs`
--
ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_severity_created` (`severity`,`created_at`);

--
-- Indexes for table `splitter_cores`
--
ALTER TABLE `splitter_cores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `core_id` (`core_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_power` (`power`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_type` (`log_type`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_severity` (`severity`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`log_time`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_actions`
--
ALTER TABLE `admin_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `boxes`
--
ALTER TABLE `boxes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `connections`
--
ALTER TABLE `connections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cores`
--
ALTER TABLE `cores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `security_logs`
--
ALTER TABLE `security_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `splitter_cores`
--
ALTER TABLE `splitter_cores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `boxes`
--
ALTER TABLE `boxes`
  ADD CONSTRAINT `boxes_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `connections`
--
ALTER TABLE `connections`
  ADD CONSTRAINT `connections_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `cores`
--
ALTER TABLE `cores`
  ADD CONSTRAINT `cores_ibfk_1` FOREIGN KEY (`box_id`) REFERENCES `boxes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `splitter_cores`
--
ALTER TABLE `splitter_cores`
  ADD CONSTRAINT `splitter_cores_ibfk_1` FOREIGN KEY (`core_id`) REFERENCES `cores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD CONSTRAINT `user_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
