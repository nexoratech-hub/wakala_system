-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 09, 2026 at 08:53 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wakala_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `employee_id`, `action`, `module`, `record_id`, `old_value`, `new_value`, `ip_address`, `user_agent`, `branch_id`, `created_at`) VALUES
(1, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 14:49:51'),
(2, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 15:02:45'),
(3, 2, 'Logout', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 15:03:33'),
(4, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 15:05:48'),
(5, 2, 'Add Evening Stock', 'Evening Stock', 1, '', 'New evening stock added', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 20:42:27'),
(6, 2, 'Add Morning Report', 'Morning Report', 1, '', 'New morning report added', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 20:51:56'),
(7, 2, 'Add Evening Stock', 'Evening Stock', 2, '', 'New evening stock added', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 21:35:38'),
(8, 2, 'Edit Evening Stock', 'Evening Stock', 2, '', 'Evening stock updated', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 22:09:45'),
(9, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-20 23:26:12'),
(10, 2, 'Add Evening Stock', 'Evening Stock', 3, '', 'New evening stock added for branch: Main Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 01:47:55'),
(11, 2, 'Add Evening Stock', 'Evening Stock', 5, '', 'New evening stock added for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 02:28:53'),
(12, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 15:24:59'),
(13, 2, 'Edit Evening Stock', 'Evening Stock', 5, '', 'Evening stock updated for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 16:03:35'),
(14, 2, 'Edit Evening Stock', 'Evening Stock', 5, '', 'Evening stock updated for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 18:23:46'),
(15, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 19:40:57'),
(16, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 23:15:43'),
(17, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-21 23:44:33'),
(18, 2, 'Add Deposit', 'Daily Report', 4, '', 'Deposit of TSh 1,000,000 from Airtel Money (Branch: Dar es Salaam Branch)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 02:01:23'),
(19, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 14:04:45'),
(20, 2, 'Add Morning Report', 'Morning Report', 6, '', 'New morning report added for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 14:33:41'),
(21, 2, 'Add Morning Report', 'Morning Report', 7, '', 'New morning report added for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 14:51:53'),
(22, 2, 'Add Deposit', 'Daily Report', 5, '', 'Deposit of TSh 800,000 from HaloPesa (Branch: Dar es Salaam Branch)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 14:53:00'),
(23, 2, 'Add Morning Report', 'Morning Report', 8, '', 'New morning report added for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 15:17:39'),
(24, 2, 'Add Morning Report', 'Morning Report', 9, '', 'New morning report added for branch: Dar es Salaam Branch', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-22 15:36:19'),
(25, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-23 02:24:08'),
(27, 3, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', NULL, '2026-08-23 02:56:11'),
(29, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-06 12:56:29'),
(30, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-06 20:18:47'),
(31, 2, 'Add Morning Report', 'Morning Report', 10, NULL, 'New morning report added for branch: Kinondoni B', NULL, NULL, 1, '2026-09-07 12:41:34'),
(32, 2, 'Update General Settings', 'Settings', NULL, NULL, '{\"company_name\":\"Mbembati Kelvin L, T\\/A Wakala\",\"company_address\":\"Dodoma, Tanzania\",\"company_phone\":\"+255 795100777\",\"company_email\":\"info@wakala.com\",\"timezone\":\"Africa\\/Dar_es_Salaam\",\"date_format\":\"d-m-Y\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-07 12:44:40'),
(33, 2, 'Add Morning Report', 'Morning Report', 11, NULL, 'New morning report added for branch: Kariakoo', NULL, NULL, 2, '2026-09-07 12:51:34'),
(34, 2, 'Add Morning Report', 'Morning Report', 12, NULL, 'New morning report added for branch: Mbezi Beach', NULL, NULL, 3, '2026-09-07 12:52:14'),
(35, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-07 17:27:52'),
(36, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-08 23:14:29'),
(37, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 00:20:55'),
(38, 3, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 00:21:37'),
(39, 2, 'Add Morning Report', 'Morning Report', 13, NULL, 'New morning report added for branch: Mbezi Beach', NULL, NULL, 3, '2026-09-09 00:26:27'),
(40, 2, 'Add Branch Providers', 'Branch Providers', 1, '', 'Added Airtel Money to Kinondoni B', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 01:58:30'),
(41, 2, 'Delete Branch Provider', 'Branch Providers', 1, '', 'Removed Airtel Money (123421) from Kinondoni B', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 01:58:43'),
(42, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 02:16:05'),
(43, 2, 'Login', 'Authentication', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', NULL, '2026-09-09 12:40:57');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `branch_code`, `branch_name`, `location`, `phone`, `email`, `manager_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'KND', 'Kinondoni B', 'Kinondoni, Dar es Salaam', '+255 700 000 200', 'kinondoni@wakala.com', NULL, 1, '2026-09-06 12:53:13', '2026-09-06 12:53:13'),
(2, 'KRK', 'Kariakoo', 'Kariakoo, Dar es Salaam', '+255 700 000 500', 'kariakoo@wakala.com', NULL, 1, '2026-09-06 12:53:13', '2026-09-06 12:53:13'),
(3, 'MBZ', 'Mbezi Beach', 'Mbezi Beach, Dar es Salaam', '+255 700 000 400', 'mbezi@wakala.com', NULL, 1, '2026-09-06 12:53:13', '2026-09-06 12:53:13');

-- --------------------------------------------------------

--
-- Table structure for table `branch_providers`
--

CREATE TABLE `branch_providers` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_code` varchar(50) NOT NULL COMMENT 'Unique code for this provider in this branch',
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branch_providers`
--

INSERT INTO `branch_providers` (`id`, `branch_id`, `provider_id`, `provider_code`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'NMB001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(2, 1, 2, 'CRDB001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(3, 1, 3, 'NBC001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(4, 1, 4, 'TPB001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(5, 1, 5, 'SELCOM001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(6, 1, 6, 'MPESA001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(7, 1, 7, 'YAS001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(8, 1, 8, 'AIRTEL001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(9, 1, 9, 'HALOPESA001', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(10, 2, 1, 'NMB002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(11, 2, 2, 'CRDB002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(12, 2, 3, 'NBC002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(13, 2, 4, 'TPB002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(14, 2, 5, 'SELCOM002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(15, 2, 6, 'MPESA002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(16, 2, 7, 'YAS002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(17, 2, 8, 'AIRTEL002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(18, 2, 9, 'HALOPESA002', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(19, 3, 1, 'NMB003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(20, 3, 2, 'CRDB003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(21, 3, 3, 'NBC003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(22, 3, 4, 'TPB003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(23, 3, 5, 'SELCOM003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(24, 3, 6, 'MPESA003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(25, 3, 7, 'YAS003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(26, 3, 8, 'AIRTEL003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(27, 3, 9, 'HALOPESA003', 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36');

-- --------------------------------------------------------

--
-- Table structure for table `capital_management`
--

CREATE TABLE `capital_management` (
  `id` int(11) NOT NULL,
  `capital_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('opening','additional','profit_allocation','cash_out','adjustment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_module` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commissions`
--

CREATE TABLE `commissions` (
  `id` int(11) NOT NULL,
  `commission_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `commission_date` date NOT NULL,
  `provider_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_data`)),
  `total_commission` decimal(15,2) DEFAULT 0.00,
  `other_income` decimal(15,2) DEFAULT 0.00,
  `total_business_income` decimal(15,2) DEFAULT 0.00,
  `allocate_to_capital` enum('yes','no') DEFAULT 'yes',
  `allocated_amount` decimal(15,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_reports`
--

CREATE TABLE `daily_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `provider_code` varchar(20) DEFAULT NULL,
  `provider_float` decimal(15,2) DEFAULT 0.00,
  `provider_cash` decimal(15,2) DEFAULT 0.00,
  `provider_deposits` decimal(15,2) DEFAULT 0.00,
  `provider_withdrawals` decimal(15,2) DEFAULT 0.00,
  `report_date` date NOT NULL,
  `morning_report_id` int(11) DEFAULT NULL,
  `evening_stock_id` int(11) DEFAULT NULL,
  `commission_id` int(11) DEFAULT NULL,
  `morning_total` decimal(15,2) DEFAULT 0.00,
  `evening_total` decimal(15,2) DEFAULT 0.00,
  `float_difference` decimal(15,2) DEFAULT 0.00,
  `total_commission` decimal(15,2) DEFAULT 0.00,
  `total_deposits` decimal(15,2) DEFAULT 0.00,
  `total_withdrawals` decimal(15,2) DEFAULT 0.00,
  `current_float` decimal(15,2) DEFAULT 0.00,
  `current_cash` decimal(15,2) DEFAULT 0.00,
  `other_income` decimal(15,2) DEFAULT 0.00,
  `total_business_income` decimal(15,2) DEFAULT 0.00,
  `total_expenses` decimal(15,2) DEFAULT 0.00,
  `total_cash_out` decimal(15,2) DEFAULT 0.00,
  `total_salaries` decimal(15,2) DEFAULT 0.00,
  `net_profit` decimal(15,2) DEFAULT 0.00,
  `net_profit_after_salaries` decimal(15,2) DEFAULT 0.00,
  `opening_capital` decimal(15,2) DEFAULT 0.00,
  `additional_capital` decimal(15,2) DEFAULT 0.00,
  `profit_allocated` decimal(15,2) DEFAULT 0.00,
  `current_capital` decimal(15,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_report_providers`
--

CREATE TABLE `daily_report_providers` (
  `id` int(11) NOT NULL,
  `daily_report_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_code` varchar(20) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `morning_float` decimal(15,2) DEFAULT 0.00,
  `morning_cash` decimal(15,2) DEFAULT 0.00,
  `current_float` decimal(15,2) DEFAULT 0.00,
  `current_cash` decimal(15,2) DEFAULT 0.00,
  `total_deposits` decimal(15,2) DEFAULT 0.00,
  `total_withdrawals` decimal(15,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_report_transactions`
--

CREATE TABLE `daily_report_transactions` (
  `id` int(11) NOT NULL,
  `daily_report_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_code` varchar(20) NOT NULL,
  `transaction_type` enum('deposit','withdrawal','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','super_admin','employee') DEFAULT 'employee',
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `base_salary` decimal(15,2) DEFAULT 0.00,
  `salary_currency` varchar(10) DEFAULT 'TSh',
  `hire_date` date DEFAULT NULL,
  `employment_status` enum('active','terminated','suspended','on_leave') DEFAULT 'active',
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `full_name`, `email`, `phone`, `username`, `password_hash`, `role`, `branch`, `branch_id`, `profile_pic`, `base_salary`, `salary_currency`, `hire_date`, `employment_status`, `emergency_contact`, `emergency_phone`, `address`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(2, 'EMP-001', 'Mbembati Kelvin', 'admin@wakala.com', '+255 700 000 001', 'admin', '$2y$10$alE5AAExHQDt//VgaYLbPeI2wgpo05KZ0B2XAufAp5/s0LhSgJlP6', 'super_admin', 'Main', NULL, 'uploads/profiles/profile_2_1788773725.png', 0.00, 'TSh', NULL, 'active', '', '', 'TANZANIA', 1, '2026-09-09 12:40:57', '2026-08-20 14:49:00', '2026-09-09 12:40:57'),
(3, 'EMP-002', 'Salma Issa', 'salma@wakala.com', '+255 700 000 002', 'salma', '$2y$10$alE5AAExHQDt//VgaYLbPeI2wgpo05KZ0B2XAufAp5/s0LhSgJlP6', 'employee', 'Main Branch', NULL, 'uploads/profiles/profile_3_1788903196.png', 0.00, 'TSh', NULL, 'active', NULL, NULL, '', 1, '2026-09-09 00:21:37', '2026-08-23 02:43:37', '2026-09-09 00:33:18');

-- --------------------------------------------------------

--
-- Table structure for table `employee_salaries`
--

CREATE TABLE `employee_salaries` (
  `id` int(11) NOT NULL,
  `salary_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `salary_month` date NOT NULL COMMENT 'First day of month e.g., 2026-08-01',
  `base_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(15,2) DEFAULT 0.00,
  `overtime_pay` decimal(15,2) DEFAULT 0.00,
  `allowances` decimal(15,2) DEFAULT 0.00,
  `total_gross` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `deductions` decimal(15,2) DEFAULT 0.00,
  `net_pay` decimal(15,2) DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_money','cheque') DEFAULT 'cash',
  `transaction_reference` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `paid_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('pending','paid','cancelled','reversed') DEFAULT 'paid',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `employee_salaries`
--
DELIMITER $$
CREATE TRIGGER `calculate_salary_net_pay` BEFORE INSERT ON `employee_salaries` FOR EACH ROW BEGIN
    SET NEW.total_gross = NEW.base_salary + NEW.bonus + NEW.overtime_pay + NEW.allowances;
    SET NEW.net_pay = NEW.total_gross - NEW.tax - NEW.deductions;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `log_salary_to_expenses` AFTER INSERT ON `employee_salaries` FOR EACH ROW BEGIN
    IF NEW.status = 'paid' THEN
        -- Generate expense number
        SET @expense_number = CONCAT('EXP-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID(), 6, '0'));
        
        INSERT INTO expenses (
            expense_number,
            employee_id,
            branch,
            expense_date,
            expense_name,
            category,
            amount,
            description,
            is_business_expense,
            is_salary_related,
            salary_reference,
            notes
        ) VALUES (
            @expense_number,
            NEW.paid_by,
            NEW.branch,
            NEW.payment_date,
            CONCAT('Salary - ', (SELECT full_name FROM employees WHERE id = NEW.employee_id)),
            'Salary',
            NEW.net_pay,
            CONCAT('Monthly salary for ', MONTHNAME(NEW.salary_month), ' ', YEAR(NEW.salary_month)),
            1,
            1,
            NEW.id,
            NEW.notes
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_salary_net_pay` BEFORE UPDATE ON `employee_salaries` FOR EACH ROW BEGIN
    SET NEW.total_gross = NEW.base_salary + NEW.bonus + NEW.overtime_pay + NEW.allowances;
    SET NEW.net_pay = NEW.total_gross - NEW.tax - NEW.deductions;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `evening_stocks`
--

CREATE TABLE `evening_stocks` (
  `id` int(11) NOT NULL,
  `stock_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `stock_date` date NOT NULL,
  `provider_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_data`)),
  `cash_balance` decimal(15,2) DEFAULT 0.00,
  `cumm_total` decimal(15,2) DEFAULT 0.00,
  `status` enum('waiting','approved','adjusted','rejected') NOT NULL DEFAULT 'waiting',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evening_stock_providers`
--

CREATE TABLE `evening_stock_providers` (
  `id` int(11) NOT NULL,
  `evening_stock_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_code` varchar(50) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `opening_float` decimal(15,2) DEFAULT 0.00,
  `opening_cash` decimal(15,2) DEFAULT 0.00,
  `closing_float` decimal(15,2) DEFAULT 0.00,
  `closing_cash` decimal(15,2) DEFAULT 0.00,
  `total_deposits` decimal(15,2) DEFAULT 0.00,
  `total_withdrawals` decimal(15,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `expense_name` varchar(200) NOT NULL,
  `category` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `is_business_expense` tinyint(4) DEFAULT 1,
  `is_salary_related` tinyint(4) DEFAULT 0,
  `salary_reference` int(11) DEFAULT NULL COMMENT 'Link to salary record if salary-related expense',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `is_system` tinyint(4) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`id`, `category_name`, `description`, `is_active`, `is_system`, `created_at`, `updated_at`) VALUES
(1, 'Rent', 'Office and store rent payments', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(2, 'Electricity', 'Electricity bills', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(3, 'Internet', 'Internet and communication bills', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(4, 'Transport', 'Transportation costs', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(5, 'Salary', 'Employee salaries and wages', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(6, 'Stationery', 'Office stationery and supplies', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(7, 'Maintenance', 'Equipment and facility maintenance', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(8, 'Bank Charges', 'Bank transaction fees', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(9, 'Communication', 'Phone and communication costs', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25'),
(10, 'Other', 'Other business expenses', 1, 0, '2026-08-20 00:21:10', '2026-08-20 10:07:25');

-- --------------------------------------------------------

--
-- Table structure for table `morning_reports`
--

CREATE TABLE `morning_reports` (
  `id` int(11) NOT NULL,
  `report_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `report_date` date NOT NULL,
  `provider_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_data`)),
  `cash_balance` decimal(15,2) DEFAULT 0.00,
  `cumm_total` decimal(15,2) DEFAULT 0.00,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  `source_type` enum('manual','auto_from_evening') DEFAULT 'manual',
  `source_evening_stock_id` int(11) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `morning_report_providers`
--

CREATE TABLE `morning_report_providers` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_code` varchar(50) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `float_balance` decimal(15,2) DEFAULT 0.00,
  `cash_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `providers`
--

CREATE TABLE `providers` (
  `id` int(11) NOT NULL,
  `provider_code` varchar(20) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `provider_type` enum('bank','mobile_money','other') DEFAULT 'bank',
  `category` varchar(50) DEFAULT 'Financial',
  `icon_class` varchar(50) DEFAULT 'fas fa-university',
  `color_code` varchar(7) DEFAULT '#0B5ED7',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(4) DEFAULT 1,
  `is_default` tinyint(4) DEFAULT 0,
  `requires_cash_balance` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `providers`
--

INSERT INTO `providers` (`id`, `provider_code`, `provider_name`, `provider_type`, `category`, `icon_class`, `color_code`, `display_order`, `is_active`, `is_default`, `requires_cash_balance`, `created_at`, `updated_at`, `created_by`, `branch_id`, `notes`) VALUES
(1, 'NMB', 'NMB Bank', 'bank', 'Financial', 'fas fa-university', '#0B5ED7', 1, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(2, 'CRDB', 'CRDB Bank', 'bank', 'Financial', 'fas fa-university', '#DC2626', 2, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(3, 'NBC', 'NBC Bank', 'bank', 'Financial', 'fas fa-university', '#059669', 3, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(4, 'TPB', 'TPB Bank', 'bank', 'Financial', 'fas fa-university', '#7C3AED', 4, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(5, 'SELCOM', 'Selcom', 'mobile_money', 'Financial', 'fas fa-mobile-alt', '#D97706', 5, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(6, 'MPESA', 'M-PESA', 'mobile_money', 'Financial', 'fas fa-mobile-alt', '#1E7BFA', 6, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(7, 'YAS', 'YAS Mobile', 'mobile_money', 'Financial', 'fas fa-mobile-alt', '#059669', 7, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(8, 'AIRTEL', 'Airtel Money', 'mobile_money', 'Financial', 'fas fa-mobile-alt', '#DC2626', 8, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL),
(9, 'HALOPESA', 'HaloPesa', 'mobile_money', 'Financial', 'fas fa-mobile-alt', '#7C3AED', 9, 1, 1, 1, '2026-08-20 00:21:10', '2026-08-20 00:21:10', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `store_cash_out`
--

CREATE TABLE `store_cash_out` (
  `id` int(11) NOT NULL,
  `cashout_number` varchar(50) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `branch_id` int(11) DEFAULT NULL,
  `cashout_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `taken_by` varchar(100) DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_group`, `description`, `updated_at`) VALUES
(1, 'company_name', 'Mbembati Kelvin L, T/A Wakala', 'general', 'Company/Business Name', '2026-08-20 00:21:10'),
(2, 'company_address', 'Dodoma, Tanzania', 'general', 'Company Address', '2026-08-20 00:21:10'),
(3, 'company_phone', '+255 795100777', 'general', 'Company Phone', '2026-09-07 12:44:40'),
(4, 'company_email', 'info@wakala.com', 'general', 'Company Email', '2026-08-20 00:21:10'),
(5, 'currency', 'TSh', 'general', 'Default Currency', '2026-08-20 00:21:10'),
(6, 'timezone', 'Africa/Dar_es_Salaam', 'general', 'System Timezone', '2026-08-20 00:21:10'),
(7, 'date_format', 'd-m-Y', 'general', 'Date Format', '2026-08-20 00:21:10'),
(8, 'opening_capital', '5000000.00', 'capital', 'Initial Opening Capital', '2026-08-20 00:21:10'),
(9, 'salary_month', '2026-08-01', 'salary', 'Current salary month', '2026-08-20 00:21:10'),
(10, 'tax_rate', '0.00', 'salary', 'Tax rate for salary calculations', '2026-08-20 00:21:10');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `transaction_number` varchar(50) NOT NULL,
  `transaction_type` enum('deposit','withdrawal','transfer','adjustment') NOT NULL DEFAULT 'deposit',
  `employee_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `branch` varchar(50) DEFAULT 'Main',
  `provider_id` int(11) DEFAULT NULL,
  `provider_code` varchar(20) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `transaction_time` time DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `role` enum('admin','super_admin','employee') NOT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(4) DEFAULT 0,
  `can_add` tinyint(4) DEFAULT 0,
  `can_edit` tinyint(4) DEFAULT 0,
  `can_delete` tinyint(4) DEFAULT 0,
  `can_export` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `role`, `module`, `can_view`, `can_add`, `can_edit`, `can_delete`, `can_export`) VALUES
(1, 'super_admin', 'dashboard', 1, 1, 1, 1, 1),
(2, 'super_admin', 'morning_report', 1, 1, 1, 1, 1),
(3, 'super_admin', 'evening_stock', 1, 1, 1, 1, 1),
(4, 'super_admin', 'commissions', 1, 1, 1, 1, 1),
(5, 'super_admin', 'expenses', 1, 1, 1, 1, 1),
(6, 'super_admin', 'store_cash_out', 1, 1, 1, 1, 1),
(7, 'super_admin', 'capital_management', 1, 1, 1, 1, 1),
(8, 'super_admin', 'daily_report', 1, 1, 1, 1, 1),
(9, 'super_admin', 'reports', 1, 1, 1, 1, 1),
(10, 'super_admin', 'employees', 1, 1, 1, 1, 1),
(11, 'super_admin', 'salaries', 1, 1, 1, 1, 1),
(12, 'super_admin', 'settings', 1, 1, 1, 1, 1),
(13, 'super_admin', 'activity_logs', 1, 1, 1, 0, 1),
(14, 'super_admin', 'profile', 1, 1, 1, 0, 0),
(15, 'admin', 'dashboard', 1, 1, 1, 1, 1),
(16, 'admin', 'morning_report', 1, 1, 1, 1, 1),
(17, 'admin', 'evening_stock', 1, 1, 1, 1, 1),
(18, 'admin', 'commissions', 1, 1, 1, 1, 1),
(19, 'admin', 'expenses', 1, 1, 1, 1, 1),
(20, 'admin', 'store_cash_out', 1, 1, 1, 1, 1),
(21, 'admin', 'capital_management', 1, 1, 1, 1, 1),
(22, 'admin', 'daily_report', 1, 1, 1, 1, 1),
(23, 'admin', 'reports', 1, 1, 1, 1, 1),
(24, 'admin', 'employees', 1, 1, 1, 1, 1),
(25, 'admin', 'salaries', 1, 1, 1, 1, 1),
(26, 'admin', 'settings', 1, 1, 1, 1, 1),
(27, 'admin', 'activity_logs', 1, 1, 1, 0, 1),
(28, 'admin', 'profile', 1, 1, 1, 0, 0),
(29, 'employee', 'dashboard', 1, 0, 0, 0, 0),
(30, 'employee', 'morning_report', 1, 1, 1, 0, 0),
(31, 'employee', 'evening_stock', 1, 1, 1, 0, 0),
(32, 'employee', 'commissions', 1, 1, 1, 0, 0),
(33, 'employee', 'expenses', 1, 1, 1, 0, 0),
(34, 'employee', 'store_cash_out', 1, 1, 1, 0, 0),
(35, 'employee', 'capital_management', 1, 0, 0, 0, 0),
(36, 'employee', 'daily_report', 1, 0, 0, 0, 0),
(37, 'employee', 'reports', 1, 0, 0, 0, 0),
(38, 'employee', 'profile', 1, 1, 1, 0, 0),
(39, 'employee', 'salaries', 1, 0, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_providers`
--

CREATE TABLE `user_providers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_providers`
--

INSERT INTO `user_providers` (`id`, `user_id`, `provider_id`, `branch_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(2, 2, 2, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(3, 2, 3, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(4, 2, 4, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(5, 2, 5, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(6, 2, 6, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(7, 2, 7, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(8, 2, 8, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(9, 2, 9, 1, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(10, 2, 1, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(11, 2, 2, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(12, 2, 3, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(13, 2, 4, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(14, 2, 5, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(15, 2, 6, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(16, 2, 7, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(17, 2, 8, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(18, 2, 9, 2, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(19, 2, 1, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(20, 2, 2, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(21, 2, 3, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(22, 2, 4, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(23, 2, 5, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(24, 2, 6, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(25, 2, 7, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(26, 2, 8, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36'),
(27, 2, 9, 3, 1, '2026-09-08 23:17:36', '2026-09-08 23:17:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_logs_employee_date` (`employee_id`,`created_at`),
  ADD KEY `fk_activity_logs_branch` (`branch_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branch_code` (`branch_code`),
  ADD KEY `idx_manager` (`manager_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `branch_providers`
--
ALTER TABLE `branch_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_branch_provider_code` (`branch_id`,`provider_code`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_provider` (`provider_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `capital_management`
--
ALTER TABLE `capital_management`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `capital_number` (`capital_number`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`transaction_date`),
  ADD KEY `idx_type` (`transaction_type`),
  ADD KEY `idx_capital_date_employee` (`transaction_date`,`employee_id`),
  ADD KEY `idx_reference` (`reference_id`,`reference_module`),
  ADD KEY `fk_capital_management_branch` (`branch_id`);

--
-- Indexes for table `commissions`
--
ALTER TABLE `commissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `commission_number` (`commission_number`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`commission_date`),
  ADD KEY `idx_commission_date_employee` (`commission_date`,`employee_id`),
  ADD KEY `idx_allocate_to_capital` (`allocate_to_capital`),
  ADD KEY `fk_commissions_branch` (`branch_id`);

--
-- Indexes for table `daily_reports`
--
ALTER TABLE `daily_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD UNIQUE KEY `unique_daily_report` (`employee_id`,`report_date`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`report_date`),
  ADD KEY `idx_morning_report` (`morning_report_id`),
  ADD KEY `idx_evening_stock` (`evening_stock_id`),
  ADD KEY `idx_commission` (`commission_id`),
  ADD KEY `idx_daily_date_employee` (`report_date`,`employee_id`),
  ADD KEY `fk_daily_reports_branch` (`branch_id`),
  ADD KEY `idx_provider_id` (`provider_id`);

--
-- Indexes for table `daily_report_providers`
--
ALTER TABLE `daily_report_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_daily_provider` (`daily_report_id`,`provider_id`),
  ADD KEY `idx_provider` (`provider_id`),
  ADD KEY `idx_daily_report` (`daily_report_id`);

--
-- Indexes for table `daily_report_transactions`
--
ALTER TABLE `daily_report_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_daily_report` (`daily_report_id`),
  ADD KEY `idx_provider` (`provider_id`),
  ADD KEY `idx_type` (`transaction_type`),
  ADD KEY `fk_drt_created_by` (`created_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_employment_status` (`employment_status`),
  ADD KEY `fk_employees_branch` (`branch_id`);

--
-- Indexes for table `employee_salaries`
--
ALTER TABLE `employee_salaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `salary_number` (`salary_number`),
  ADD UNIQUE KEY `unique_salary_employee_month` (`employee_id`,`salary_month`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_month` (`salary_month`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_salary_employee_month` (`employee_id`,`salary_month`),
  ADD KEY `idx_paid_by` (`paid_by`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `fk_employee_salaries_branch` (`branch_id`);

--
-- Indexes for table `evening_stocks`
--
ALTER TABLE `evening_stocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_number` (`stock_number`),
  ADD UNIQUE KEY `unique_stock_date_branch` (`employee_id`,`stock_date`,`branch_id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`stock_date`),
  ADD KEY `idx_evening_date_employee` (`stock_date`,`employee_id`),
  ADD KEY `fk_evening_stocks_branch` (`branch_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `evening_stock_providers`
--
ALTER TABLE `evening_stock_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_evening_stock_id` (`evening_stock_id`),
  ADD KEY `idx_provider_id` (`provider_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expense_number` (`expense_number`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`expense_date`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_expense_date_employee` (`expense_date`,`employee_id`),
  ADD KEY `idx_expense_category` (`category`),
  ADD KEY `idx_is_business_expense` (`is_business_expense`),
  ADD KEY `idx_salary_reference` (`salary_reference`),
  ADD KEY `fk_expenses_branch` (`branch_id`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `morning_reports`
--
ALTER TABLE `morning_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_number` (`report_number`),
  ADD UNIQUE KEY `unique_report_date_branch` (`employee_id`,`report_date`,`branch_id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`report_date`),
  ADD KEY `idx_morning_date_employee` (`report_date`,`employee_id`),
  ADD KEY `fk_morning_reports_branch` (`branch_id`),
  ADD KEY `idx_source_evening` (`source_evening_stock_id`);

--
-- Indexes for table `morning_report_providers`
--
ALTER TABLE `morning_report_providers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_id` (`report_id`),
  ADD KEY `idx_provider_id` (`provider_id`);

--
-- Indexes for table `providers`
--
ALTER TABLE `providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_code` (`provider_code`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_type` (`provider_type`),
  ADD KEY `idx_order` (`display_order`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `fk_providers_branch` (`branch_id`);

--
-- Indexes for table `store_cash_out`
--
ALTER TABLE `store_cash_out`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cashout_number` (`cashout_number`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_date` (`cashout_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_cashout_date_employee` (`cashout_date`,`employee_id`),
  ADD KEY `fk_store_cash_out_branch` (`branch_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_group` (`setting_group`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_number` (`transaction_number`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_provider` (`provider_id`),
  ADD KEY `idx_type` (`transaction_type`),
  ADD KEY `idx_date` (`transaction_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `fk_transactions_branch` (`branch_id`),
  ADD KEY `fk_transactions_provider` (`provider_id`),
  ADD KEY `fk_transactions_approved_by` (`approved_by`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_module` (`role`,`module`);

--
-- Indexes for table `user_providers`
--
ALTER TABLE `user_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_provider` (`user_id`,`provider_id`,`branch_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_provider_id` (`provider_id`),
  ADD KEY `idx_branch_id` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `branch_providers`
--
ALTER TABLE `branch_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `capital_management`
--
ALTER TABLE `capital_management`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `commissions`
--
ALTER TABLE `commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `daily_reports`
--
ALTER TABLE `daily_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `daily_report_providers`
--
ALTER TABLE `daily_report_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `daily_report_transactions`
--
ALTER TABLE `daily_report_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employee_salaries`
--
ALTER TABLE `employee_salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `evening_stocks`
--
ALTER TABLE `evening_stocks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `evening_stock_providers`
--
ALTER TABLE `evening_stock_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `morning_reports`
--
ALTER TABLE `morning_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `morning_report_providers`
--
ALTER TABLE `morning_report_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `providers`
--
ALTER TABLE `providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `store_cash_out`
--
ALTER TABLE `store_cash_out`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `user_providers`
--
ALTER TABLE `user_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_activity_logs_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `branches_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `branch_providers`
--
ALTER TABLE `branch_providers`
  ADD CONSTRAINT `fk_bp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bp_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `capital_management`
--
ALTER TABLE `capital_management`
  ADD CONSTRAINT `capital_management_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_capital_management_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `commissions`
--
ALTER TABLE `commissions`
  ADD CONSTRAINT `commissions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_commissions_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `daily_reports`
--
ALTER TABLE `daily_reports`
  ADD CONSTRAINT `daily_reports_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `daily_reports_ibfk_2` FOREIGN KEY (`morning_report_id`) REFERENCES `morning_reports` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `daily_reports_ibfk_3` FOREIGN KEY (`evening_stock_id`) REFERENCES `evening_stocks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `daily_reports_ibfk_4` FOREIGN KEY (`commission_id`) REFERENCES `commissions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_daily_reports_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_daily_reports_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `daily_report_providers`
--
ALTER TABLE `daily_report_providers`
  ADD CONSTRAINT `fk_drp_daily_report` FOREIGN KEY (`daily_report_id`) REFERENCES `daily_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_drp_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `daily_report_transactions`
--
ALTER TABLE `daily_report_transactions`
  ADD CONSTRAINT `fk_drt_created_by` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_drt_daily_report` FOREIGN KEY (`daily_report_id`) REFERENCES `daily_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_drt_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employee_salaries`
--
ALTER TABLE `employee_salaries`
  ADD CONSTRAINT `fk_employee_salaries_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salaries_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salaries_ibfk_2` FOREIGN KEY (`paid_by`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salaries_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `evening_stocks`
--
ALTER TABLE `evening_stocks`
  ADD CONSTRAINT `evening_stocks_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_evening_stocks_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `evening_stock_providers`
--
ALTER TABLE `evening_stock_providers`
  ADD CONSTRAINT `fk_esp_evening_stock` FOREIGN KEY (`evening_stock_id`) REFERENCES `evening_stocks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_esp_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`salary_reference`) REFERENCES `employee_salaries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expenses_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `morning_reports`
--
ALTER TABLE `morning_reports`
  ADD CONSTRAINT `fk_morning_reports_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `morning_reports_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `morning_report_providers`
--
ALTER TABLE `morning_report_providers`
  ADD CONSTRAINT `fk_mrp_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mrp_report` FOREIGN KEY (`report_id`) REFERENCES `morning_reports` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `providers`
--
ALTER TABLE `providers`
  ADD CONSTRAINT `fk_providers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `providers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `store_cash_out`
--
ALTER TABLE `store_cash_out`
  ADD CONSTRAINT `fk_store_cash_out_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `store_cash_out_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_providers`
--
ALTER TABLE `user_providers`
  ADD CONSTRAINT `fk_up_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_up_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
