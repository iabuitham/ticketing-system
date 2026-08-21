-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql103.ezyro.com
-- Generation Time: Jul 05, 2026 at 06:39 PM
-- Server version: 11.4.12-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ezyro_41780028_ticketing_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_credit_notes`
--

CREATE TABLE `archived_credit_notes` (
  `id` int(11) NOT NULL,
  `archived_reservation_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_by` varchar(100) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_events`
--

CREATE TABLE `archived_events` (
  `id` int(11) NOT NULL,
  `event_name` varchar(200) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `venue` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT 0,
  `ticket_price_adult` decimal(10,2) DEFAULT 10.00,
  `ticket_price_teen` decimal(10,2) DEFAULT 10.00,
  `ticket_price_kid` decimal(10,2) DEFAULT 0.00,
  `tickets_sold` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'completed',
  `closed_at` datetime NOT NULL,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `total_attendees` int(11) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_reservations`
--

CREATE TABLE `archived_reservations` (
  `id` int(11) NOT NULL,
  `archived_event_id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `sequential_number` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `adults` int(11) DEFAULT 0,
  `teens` int(11) DEFAULT 0,
  `kids` int(11) DEFAULT 0,
  `table_id` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `additional_amount_due` decimal(10,2) DEFAULT 0.00,
  `price_tier` enum('regular','loyalty') DEFAULT 'regular',
  `status` varchar(50) DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `archived_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_split_payments`
--

CREATE TABLE `archived_split_payments` (
  `id` int(11) NOT NULL,
  `archived_reservation_id` varchar(50) NOT NULL,
  `payment_method` enum('cash','cliq','visa') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `receipt_id` varchar(100) DEFAULT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `payment_type` enum('initial','additional') DEFAULT 'initial',
  `received_by` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_fnb` tinyint(1) DEFAULT 0,
  `fnb_amount` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_ticket_codes`
--

CREATE TABLE `archived_ticket_codes` (
  `id` int(11) NOT NULL,
  `archived_reservation_id` varchar(50) NOT NULL,
  `ticket_code` varchar(50) NOT NULL,
  `guest_type` enum('adult','teen','kid') NOT NULL,
  `guest_number` int(11) NOT NULL,
  `is_scanned` tinyint(1) DEFAULT 0,
  `scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit_notes`
--

CREATE TABLE `credit_notes` (
  `id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','processed','cancelled') DEFAULT 'pending',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `total_visits` int(11) DEFAULT 1,
  `total_spent` decimal(10,2) DEFAULT 0.00,
  `last_visit_date` datetime DEFAULT NULL,
  `first_visit_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_vip` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_settings`
--

CREATE TABLE `event_settings` (
  `id` int(11) NOT NULL,
  `event_name` varchar(200) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `venue` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT 0,
  `ticket_price_adult` decimal(10,2) DEFAULT 10.00,
  `ticket_price_teen` decimal(10,2) DEFAULT 10.00,
  `ticket_price_kid` decimal(10,2) DEFAULT 0.00,
  `status` enum('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
  `is_closed` tinyint(1) DEFAULT 0,
  `closed_at` datetime DEFAULT NULL,
  `floor_plan_image` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `sequential_number` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `adults` int(11) DEFAULT 0,
  `teens` int(11) DEFAULT 0,
  `kids` int(11) DEFAULT 0,
  `table_id` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `price_tier` enum('regular','loyalty') DEFAULT 'regular',
  `status` enum('pending','registered','paid','cancelled') DEFAULT 'pending',
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `additional_amount_due` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `event_date` date DEFAULT NULL,
  `time_slot` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_sequences`
--

CREATE TABLE `reservation_sequences` (
  `event_id` int(11) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scan_logs`
--

CREATE TABLE `scan_logs` (
  `id` int(11) NOT NULL,
  `ticket_code` varchar(50) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `scanned_by` varchar(100) NOT NULL,
  `scanned_at` datetime NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `split_payments`
--

CREATE TABLE `split_payments` (
  `id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `payment_method` enum('cash','cliq','visa') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `receipt_id` varchar(100) DEFAULT NULL,
  `payment_type` enum('initial','additional') DEFAULT 'initial',
  `received_by` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `proof_file` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tables`
--

CREATE TABLE `tables` (
  `id` int(11) NOT NULL,
  `table_number` varchar(20) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `status` enum('available','reserved','occupied','maintenance') DEFAULT 'available',
  `is_active` tinyint(1) DEFAULT 1,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_reservation_id` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_codes`
--

CREATE TABLE `ticket_codes` (
  `id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `ticket_code` varchar(50) NOT NULL,
  `guest_type` enum('adult','teen','kid') NOT NULL,
  `guest_number` int(11) NOT NULL,
  `is_scanned` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_transfers`
--

CREATE TABLE `ticket_transfers` (
  `id` int(11) NOT NULL,
  `ticket_code` varchar(50) NOT NULL,
  `from_reservation_id` varchar(50) NOT NULL,
  `from_customer_name` varchar(100) NOT NULL,
  `from_customer_phone` varchar(20) NOT NULL,
  `to_customer_name` varchar(100) NOT NULL,
  `to_customer_phone` varchar(20) NOT NULL,
  `transfer_code` varchar(20) NOT NULL,
  `status` enum('pending','approved','completed','cancelled') DEFAULT 'pending',
  `requested_at` datetime DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `processed_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `archived_credit_notes`
--
ALTER TABLE `archived_credit_notes`
  ADD KEY `idx_archived_reservation` (`archived_reservation_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `archived_events`
--
ALTER TABLE `archived_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_date` (`event_date`),
  ADD KEY `idx_archived_at` (`archived_at`);

--
-- Indexes for table `archived_reservations`
--
ALTER TABLE `archived_reservations`
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_archived_event` (`archived_event_id`),
  ADD KEY `idx_archived_at` (`archived_at`);

--
-- Indexes for table `archived_split_payments`
--
ALTER TABLE `archived_split_payments`
  ADD KEY `idx_archived_reservation` (`archived_reservation_id`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_archived_at` (`archived_at`);

--
-- Indexes for table `archived_ticket_codes`
--
ALTER TABLE `archived_ticket_codes`
  ADD KEY `idx_archived_reservation` (`archived_reservation_id`),
  ADD KEY `idx_ticket_code` (`ticket_code`),
  ADD KEY `idx_scanned` (`is_scanned`);

--
-- Indexes for table `credit_notes`
--
ALTER TABLE `credit_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_vip` (`is_vip`),
  ADD KEY `idx_total_spent` (`total_spent`),
  ADD KEY `idx_customers_phone` (`phone`),
  ADD KEY `idx_customers_vip` (`is_vip`);

--
-- Indexes for table `event_settings`
--
ALTER TABLE `event_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_is_closed` (`is_closed`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reservation_id` (`reservation_id`),
  ADD UNIQUE KEY `unique_event_sequential` (`event_id`,`sequential_number`),
  ADD UNIQUE KEY `unique_table_time` (`table_id`,`event_date`,`time_slot`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_status_date` (`status`,`event_date`),
  ADD KEY `idx_event_id` (`event_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_table_id` (`table_id`),
  ADD KEY `idx_sequential` (`sequential_number`),
  ADD KEY `idx_phone_status` (`phone`,`status`),
  ADD KEY `idx_event_status` (`event_id`,`status`),
  ADD KEY `idx_reservations_event_status` (`event_id`,`status`),
  ADD KEY `idx_reservations_phone` (`phone`),
  ADD KEY `idx_reservations_created` (`created_at`),
  ADD KEY `idx_reservations_status` (`status`),
  ADD KEY `idx_reservations_event` (`event_id`);

--
-- Indexes for table `reservation_sequences`
--
ALTER TABLE `reservation_sequences`
  ADD PRIMARY KEY (`event_id`);

--
-- Indexes for table `scan_logs`
--
ALTER TABLE `scan_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket` (`ticket_code`),
  ADD KEY `idx_reservation` (`reservation_id`),
  ADD KEY `idx_scanned_by` (`scanned_by`),
  ADD KEY `idx_scanned_at` (`scanned_at`),
  ADD KEY `idx_scans_ticket` (`ticket_code`),
  ADD KEY `idx_scans_date` (`scanned_at`);

--
-- Indexes for table `split_payments`
--
ALTER TABLE `split_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_payment_method_amount` (`payment_method`,`amount`),
  ADD KEY `idx_created_date` (`created_at`),
  ADD KEY `idx_payments_reservation` (`reservation_id`),
  ADD KEY `idx_payments_method` (`payment_method`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `tables`
--
ALTER TABLE `tables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `table_number` (`table_number`),
  ADD KEY `idx_is_used` (`is_used`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_table_number` (`table_number`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_status_active` (`status`,`is_active`),
  ADD KEY `idx_tables_status` (`status`),
  ADD KEY `idx_tables_active` (`is_active`);

--
-- Indexes for table `ticket_codes`
--
ALTER TABLE `ticket_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_code` (`ticket_code`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `idx_ticket_code` (`ticket_code`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_is_scanned` (`is_scanned`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_reservation_active` (`reservation_id`,`is_active`),
  ADD KEY `idx_scanned_active` (`is_scanned`,`is_active`),
  ADD KEY `idx_tickets_reservation` (`reservation_id`),
  ADD KEY `idx_tickets_code` (`ticket_code`),
  ADD KEY `idx_tickets_scanned` (`is_scanned`);

--
-- Indexes for table `ticket_transfers`
--
ALTER TABLE `ticket_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transfer_code` (`transfer_code`),
  ADD KEY `idx_ticket_code` (`ticket_code`),
  ADD KEY `idx_transfer_code` (`transfer_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_from_phone` (`from_customer_phone`),
  ADD KEY `idx_to_phone` (`to_customer_phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit_notes`
--
ALTER TABLE `credit_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_settings`
--
ALTER TABLE `event_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scan_logs`
--
ALTER TABLE `scan_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `split_payments`
--
ALTER TABLE `split_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tables`
--
ALTER TABLE `tables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_codes`
--
ALTER TABLE `ticket_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_transfers`
--
ALTER TABLE `ticket_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `archived_reservations`
--
ALTER TABLE `archived_reservations`
  ADD CONSTRAINT `archived_reservations_ibfk_1` FOREIGN KEY (`archived_event_id`) REFERENCES `archived_events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `credit_notes`
--
ALTER TABLE `credit_notes`
  ADD CONSTRAINT `fk_credit_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservations_event` FOREIGN KEY (`event_id`) REFERENCES `event_settings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `event_settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `split_payments`
--
ALTER TABLE `split_payments`
  ADD CONSTRAINT `fk_split_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_codes`
--
ALTER TABLE `ticket_codes`
  ADD CONSTRAINT `fk_ticket_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_codes_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
