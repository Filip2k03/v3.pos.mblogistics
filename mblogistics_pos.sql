-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 26, 2025 at 04:30 AM
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
-- Database: `mblogistics_pos`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `customer_name` VARCHAR(100) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL UNIQUE,
  `email` VARCHAR(100) UNIQUE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `company_name` VARCHAR(100) DEFAULT NULL,
  `loyalty_points` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `description` TEXT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(5) NOT NULL, -- e.g., MMK, RM, BAT, SGD
  `expense_date` DATE NOT NULL,
  `created_by_user_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `regions`
--

CREATE TABLE `regions` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `region_name` VARCHAR(100) NOT NULL UNIQUE,
  `prefix` VARCHAR(10) NOT NULL UNIQUE, -- e.g., 'YGN', 'MDY'
  `price_per_kg` DECIMAL(10,2) DEFAULT 0.00, -- Default price for this region (if applicable)
  `current_sequence` INT(11) DEFAULT 0 -- For voucher code generation
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `regions`
--

INSERT INTO `regions` (`id`, `region_name`, `prefix`, `price_per_kg`, `current_sequence`) VALUES
(1, 'Yangon', 'YGN', 1000.00, 0),
(2, 'Mandalay', 'MDY', 1200.00, 0),
(3, 'Malaysia', 'MLY', 5.00, 0),
(4, 'Singapore', 'SGD', 3.00, 0),
(5, 'Thailand', 'BHT', 8.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL, -- Hashed password
  `email` VARCHAR(100) UNIQUE DEFAULT NULL,
  `phone` VARCHAR(20) UNIQUE DEFAULT NULL,
  `full_name` VARCHAR(100) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `user_type` ENUM('ADMIN', 'Myanmar', 'Malay', 'Staff', 'Driver') NOT NULL DEFAULT 'Staff',
  `region_id` INT(11) DEFAULT NULL, -- For regional users (Myanmar/Malay) or driver base
  `currency_preference` VARCHAR(5) DEFAULT 'MMK', -- e.g., 'MMK', 'RM', 'BAT', 'SGD'
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `phone`, `full_name`, `address`, `user_type`, `region_id`, `currency_preference`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$Q78oU3R7Z8Z8Z8Z8Z8Z8O.t0C9H/S/A/D/X/Y/Z/a/b/c/d/e/f/g', 'admin@example.com', '09123456789', 'Super Admin', 'Admin HQ', 'ADMIN', NULL, 'MMK', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(2, 'myanmar_user', '$2y$10$Q78oU3R7Z8Z8Z8Z8Z8Z8O.t0C9H/S/A/D/X/Y/Z/a/b/c/d/e/f/g', 'myanmar@example.com', '09987654321', 'Myanmar Branch Staff', 'Yangon Branch', 'Myanmar', 1, 'MMK', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
(3, 'malay_user', '$2y$10$Q78oU3R7Z8Z8Z8Z8Z8Z8O.t0C9H/S/A/D/X/Y/Z/a/b/c/d/e/f/g', 'malay@example.com', '01234567890', 'Malay Branch Staff', 'Kuala Lumpur', 'Malay', 3, 'RM', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);


-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `voucher_code` VARCHAR(20) NOT NULL UNIQUE,
  `sender_name` VARCHAR(100) NOT NULL,
  `sender_phone` VARCHAR(20) NOT NULL,
  `sender_address` TEXT DEFAULT NULL,
  `use_sender_address_for_checkout` TINYINT(1) DEFAULT 0,
  `receiver_name` VARCHAR(100) NOT NULL,
  `receiver_phone` VARCHAR(20) NOT NULL,
  `receiver_address` TEXT NOT NULL,
  `customer_id` INT(11) DEFAULT NULL, -- NEW: Link to customers table
  `payment_method` VARCHAR(50) NOT NULL,
  `weight_kg` DECIMAL(10,2) DEFAULT 0.00,
  `price_per_kg_at_voucher` DECIMAL(10,2) DEFAULT 0.00, -- Stored at time of creation for historical pricing
  `delivery_charge` DECIMAL(10,2) DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(5) NOT NULL,
  `delivery_type` VARCHAR(50) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned') NOT NULL DEFAULT 'Pending',
  `region_id` INT(11) NOT NULL, -- Origin Region
  `destination_region_id` INT(11) NOT NULL,
  `created_by_user_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`destination_region_id`) REFERENCES `regions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voucher_breakdowns`
--

CREATE TABLE `voucher_breakdowns` (
  `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
  `voucher_id` INT(11) NOT NULL,
  `item_type` VARCHAR(100) NOT NULL,
  `kg` DECIMAL(10,2) NOT NULL,
  `price_per_kg` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voucher_status_log`
--

CREATE TABLE `voucher_status_log` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
    `voucher_id` INT(11) NOT NULL,
    `old_status` VARCHAR(50) NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `notes` TEXT NULL,
    `changed_by_user_id` INT(11) NOT NULL,
    `change_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Removing the old `stock` table if it was only for status tracking
-- DROP TABLE IF EXISTS `stock`;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;