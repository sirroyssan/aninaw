-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 16, 2026 at 03:49 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `aninaw_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `before_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_data`)),
  `after_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_data`)),
  `reason` text DEFAULT NULL,
  `request_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `budget_number` varchar(80) NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `budget_name` varchar(180) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('draft','pending','approved','active','closed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `budget_lines`
--

CREATE TABLE `budget_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `budget_id` bigint(20) UNSIGNED NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `allocated_amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_advances`
--

CREATE TABLE `cash_advances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `advance_number` varchar(80) NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `financial_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recipient_membership_id` bigint(20) UNSIGNED NOT NULL,
  `purpose` text NOT NULL,
  `advance_type` enum('temporary_borrowing','activity_advance') NOT NULL,
  `requested_amount` decimal(12,2) NOT NULL,
  `approved_amount` decimal(12,2) DEFAULT NULL,
  `released_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `request_date` date NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `liquidation_deadline` date DEFAULT NULL,
  `status` enum('draft','pending_approval','approved','released','partially_liquidated','submitted_for_review','returned_for_correction','liquidated','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `requested_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `released_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_advance_expenses`
--

CREATE TABLE `cash_advance_expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `cash_advance_id` bigint(20) UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `vendor_name` varchar(180) DEFAULT NULL,
  `description` text NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `receipt_attachment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `verification_status` enum('pending','verified','rejected','needs_correction') NOT NULL DEFAULT 'pending',
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_advance_returns`
--

CREATE TABLE `cash_advance_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `cash_advance_id` bigint(20) UNSIGNED NOT NULL,
  `return_number` varchar(80) NOT NULL,
  `financial_account_id` bigint(20) UNSIGNED NOT NULL,
  `returned_amount` decimal(12,2) NOT NULL,
  `returned_at` datetime NOT NULL,
  `received_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `payment_method` enum('cash','gcash','bank_transfer','other') NOT NULL DEFAULT 'cash',
  `reference_number` varchar(150) DEFAULT NULL,
  `acknowledgment_number` varchar(80) DEFAULT NULL,
  `status` enum('pending_verification','confirmed','reversed') NOT NULL DEFAULT 'pending_verification',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_flow_transactions`
--

CREATE TABLE `cash_flow_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `transaction_number` varchar(80) NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `financial_account_id` bigint(20) UNSIGNED NOT NULL,
  `transaction_date` datetime NOT NULL,
  `transaction_type` enum('collection','direct_expense','advance_release','advance_return','deposit','withdrawal','transfer','refund','adjustment','opening_balance') NOT NULL,
  `direction` enum('inflow','outflow') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `source_type` varchar(60) NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('pending','posted','reversed') NOT NULL DEFAULT 'pending',
  `recorded_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_turnovers`
--

CREATE TABLE `cash_turnovers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `turnover_number` varchar(80) NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `submitted_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `received_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `declared_amount` decimal(12,2) NOT NULL,
  `received_amount` decimal(12,2) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `status` enum('draft','submitted','received_pending_verification','verified','discrepancy','cancelled') NOT NULL DEFAULT 'draft',
  `discrepancy_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `cash_turnover_details`
--

CREATE TABLE `cash_turnover_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `turnover_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `amount_included` decimal(12,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `collection_rules`
--

CREATE TABLE `collection_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `rule_name` varchar(180) NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `applies_to_type` enum('all','group','participant') NOT NULL DEFAULT 'all',
  `applies_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_until` date DEFAULT NULL,
  `allow_partial_payment` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','inactive') DEFAULT 'active'
) ;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `department_code` varchar(60) NOT NULL,
  `department_name` varchar(180) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deposits`
--

CREATE TABLE `deposits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `deposit_number` varchar(80) NOT NULL,
  `source_account_id` bigint(20) UNSIGNED NOT NULL,
  `destination_account_id` bigint(20) UNSIGNED NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reference_number` varchar(150) DEFAULT NULL,
  `status` enum('recorded','pending_confirmation','confirmed','reversed') NOT NULL DEFAULT 'recorded',
  `recorded_by` bigint(20) UNSIGNED NOT NULL,
  `confirmed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_code` varchar(60) NOT NULL,
  `event_name` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `collection_deadline` date DEFAULT NULL,
  `status` enum('draft','pending','approved','collecting','completed','closed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_funds`
--

CREATE TABLE `event_funds` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `allocation_purpose` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_group_assignments`
--

CREATE TABLE `event_group_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `collector_membership_id` bigint(20) UNSIGNED NOT NULL,
  `cashier_membership_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_obligations`
--

CREATE TABLE `event_obligations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `collection_rule_id` bigint(20) UNSIGNED NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `waived_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('unpaid','partially_paid','paid','waived','cancelled') NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `expense_number` varchar(80) NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `financial_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expense_date` date NOT NULL,
  `payee` varchar(180) NOT NULL,
  `description` text NOT NULL,
  `category` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','gcash','bank_transfer','other') NOT NULL,
  `receipt_attachment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('draft','submitted','approved','paid','rejected','reversed') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `paid_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `file_attachments`
--

CREATE TABLE `file_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `uploaded_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `storage_key` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `checksum` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `financial_accounts`
--

CREATE TABLE `financial_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `account_name` varchar(180) NOT NULL,
  `account_type` enum('cash_custody','bank','e_wallet','other') NOT NULL,
  `account_reference` varchar(255) DEFAULT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'PHP',
  `status` enum('active','inactive','closed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fund_access_assignments`
--

CREATE TABLE `fund_access_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `membership_id` bigint(20) UNSIGNED NOT NULL,
  `permission_level` enum('view','collect','receive_turnover','spend','approve','reconcile','manage') NOT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fund_accounts`
--

CREATE TABLE `fund_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `fund_code` varchar(60) NOT NULL,
  `fund_name` varchar(180) NOT NULL,
  `fund_category` varchar(100) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opening_date` date DEFAULT NULL,
  `custodian_membership_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('draft','pending','active','suspended','closed') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fund_account_mappings`
--

CREATE TABLE `fund_account_mappings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `financial_account_id` bigint(20) UNSIGNED NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fund_transfers`
--

CREATE TABLE `fund_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_number` varchar(80) NOT NULL,
  `from_fund_id` bigint(20) UNSIGNED NOT NULL,
  `to_fund_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `transfer_date` date NOT NULL,
  `purpose` text NOT NULL,
  `status` enum('draft','submitted','approved','posted','rejected','reversed') NOT NULL DEFAULT 'draft',
  `requested_by` bigint(20) UNSIGNED NOT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `department_id` bigint(20) UNSIGNED DEFAULT NULL,
  `group_code` varchar(80) NOT NULL,
  `group_name` varchar(180) NOT NULL,
  `group_type` enum('class','section','team','organization','other') NOT NULL DEFAULT 'class',
  `academic_year` varchar(30) DEFAULT NULL,
  `term` varchar(40) DEFAULT NULL,
  `status` enum('active','inactive','closed') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_memberships`
--

CREATE TABLE `group_memberships` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `academic_year` varchar(30) DEFAULT NULL,
  `term` varchar(40) DEFAULT NULL,
  `status` enum('active','inactive','ended') DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_officers`
--

CREATE TABLE `group_officers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `membership_id` bigint(20) UNSIGNED NOT NULL,
  `officer_type` enum('president','adviser','collector','other') NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `institutions`
--

CREATE TABLE `institutions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_code` varchar(50) NOT NULL,
  `institution_name` varchar(200) NOT NULL,
  `institution_type` varchar(80) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `timezone` varchar(60) NOT NULL DEFAULT 'Asia/Manila',
  `currency_code` char(3) NOT NULL DEFAULT 'PHP',
  `status` enum('pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `institution_memberships`
--

CREATE TABLE `institution_memberships` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `membership_status` enum('pending','active','suspended','ended') NOT NULL DEFAULT 'pending',
  `joined_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `institution_settings`
--

CREATE TABLE `institution_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `journal_number` varchar(80) NOT NULL,
  `journal_date` date NOT NULL,
  `description` text NOT NULL,
  `source_type` varchar(60) NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `posted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `reversal_of_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `journal_entry_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `financial_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `account_code` varchar(60) NOT NULL,
  `account_name` varchar(180) NOT NULL,
  `debit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `identifier_hash` char(64) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `membership_roles`
--

CREATE TABLE `membership_roles` (
  `membership_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `recipient_user_id` bigint(20) UNSIGNED NOT NULL,
  `notification_type` varchar(80) NOT NULL,
  `title` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `number_sequences`
--

CREATE TABLE `number_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `document_type` varchar(60) NOT NULL,
  `current_number` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `participants`
--

CREATE TABLE `participants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `participant_number` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `participant_type` enum('student','employee','member','other') NOT NULL DEFAULT 'student',
  `status` enum('active','inactive','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `payment_number` varchar(80) NOT NULL,
  `payer_participant_id` bigint(20) UNSIGNED NOT NULL,
  `received_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `financial_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payment_date` datetime NOT NULL,
  `payment_method` enum('cash','gcash','bank_transfer','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `external_reference` varchar(150) DEFAULT NULL,
  `status` enum('pending','posted','reversed','refunded') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `payment_allocations`
--

CREATE TABLE `payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `obligation_id` bigint(20) UNSIGNED NOT NULL,
  `allocated_amount` decimal(12,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `permission_code` varchar(100) NOT NULL,
  `permission_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipts`
--

CREATE TABLE `receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `receipt_number` varchar(80) NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `issued_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `issued_at` datetime NOT NULL,
  `receipt_status` enum('issued','voided') NOT NULL DEFAULT 'issued'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_code` varchar(60) NOT NULL,
  `role_name` varchar(120) NOT NULL,
  `scope_type` enum('platform','institution') NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `turnover_acknowledgments`
--

CREATE TABLE `turnover_acknowledgments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `turnover_id` bigint(20) UNSIGNED NOT NULL,
  `acknowledged_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `acknowledged_amount` decimal(12,2) NOT NULL,
  `acknowledgment_type` enum('received','verified','adjustment') NOT NULL,
  `notes` text DEFAULT NULL,
  `acknowledged_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `turnover_discrepancies`
--

CREATE TABLE `turnover_discrepancies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `turnover_id` bigint(20) UNSIGNED NOT NULL,
  `expected_amount` decimal(12,2) NOT NULL,
  `actual_amount` decimal(12,2) NOT NULL,
  `difference_amount` decimal(12,2) NOT NULL,
  `reason` text NOT NULL,
  `resolution_notes` text DEFAULT NULL,
  `reported_by_membership_id` bigint(20) UNSIGNED NOT NULL,
  `resolved_by_membership_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reported_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `status` enum('open','under_review','resolved','accepted_exception') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `status` enum('pending','active','locked','disabled') NOT NULL DEFAULT 'pending',
  `email_verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `session_token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_scope` (`institution_id`,`created_at`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `actor_user_id` (`actor_user_id`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_budget_number` (`institution_id`,`budget_number`),
  ADD UNIQUE KEY `uq_budget_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_2` (`institution_id`,`event_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `budget_lines`
--
ALTER TABLE `budget_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`budget_id`);

--
-- Indexes for table `cash_advances`
--
ALTER TABLE `cash_advances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_advance_number` (`institution_id`,`advance_number`),
  ADD UNIQUE KEY `uq_advance_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_2` (`institution_id`,`event_id`),
  ADD KEY `institution_id_3` (`institution_id`,`financial_account_id`),
  ADD KEY `institution_id_4` (`institution_id`,`recipient_membership_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `released_by` (`released_by`);

--
-- Indexes for table `cash_advance_expenses`
--
ALTER TABLE `cash_advance_expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_advance_expense_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`cash_advance_id`),
  ADD KEY `institution_id_2` (`institution_id`,`receipt_attachment_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `cash_advance_returns`
--
ALTER TABLE `cash_advance_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_return_number` (`institution_id`,`return_number`),
  ADD KEY `institution_id` (`institution_id`,`cash_advance_id`),
  ADD KEY `institution_id_2` (`institution_id`,`financial_account_id`),
  ADD KEY `institution_id_3` (`institution_id`,`received_by_membership_id`);

--
-- Indexes for table `cash_flow_transactions`
--
ALTER TABLE `cash_flow_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cash_flow_number` (`institution_id`,`transaction_number`),
  ADD KEY `idx_cash_flow_fund_date` (`institution_id`,`fund_id`,`transaction_date`),
  ADD KEY `idx_cash_flow_source` (`source_type`,`source_id`),
  ADD KEY `institution_id` (`institution_id`,`financial_account_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `cash_turnovers`
--
ALTER TABLE `cash_turnovers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_turnover_number` (`institution_id`,`turnover_number`),
  ADD UNIQUE KEY `uq_turnover_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`event_id`),
  ADD KEY `institution_id_2` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_3` (`institution_id`,`group_id`),
  ADD KEY `institution_id_4` (`institution_id`,`submitted_by_membership_id`),
  ADD KEY `institution_id_5` (`institution_id`,`received_by_membership_id`);

--
-- Indexes for table `cash_turnover_details`
--
ALTER TABLE `cash_turnover_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_turnover_payment` (`turnover_id`,`payment_id`),
  ADD KEY `institution_id` (`institution_id`,`turnover_id`),
  ADD KEY `institution_id_2` (`institution_id`,`payment_id`);

--
-- Indexes for table `collection_rules`
--
ALTER TABLE `collection_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`event_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_department_code` (`institution_id`,`department_code`),
  ADD UNIQUE KEY `uq_department_tenant` (`institution_id`,`id`);

--
-- Indexes for table `deposits`
--
ALTER TABLE `deposits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_deposit_number` (`institution_id`,`deposit_number`),
  ADD KEY `institution_id` (`institution_id`,`source_account_id`),
  ADD KEY `institution_id_2` (`institution_id`,`destination_account_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_event_code` (`institution_id`,`event_code`),
  ADD UNIQUE KEY `uq_event_tenant` (`institution_id`,`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `event_funds`
--
ALTER TABLE `event_funds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_event_fund` (`event_id`,`fund_id`),
  ADD KEY `institution_id` (`institution_id`,`event_id`),
  ADD KEY `institution_id_2` (`institution_id`,`fund_id`);

--
-- Indexes for table `event_group_assignments`
--
ALTER TABLE `event_group_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_event_group` (`event_id`,`group_id`),
  ADD KEY `institution_id` (`institution_id`,`event_id`),
  ADD KEY `institution_id_2` (`institution_id`,`group_id`),
  ADD KEY `institution_id_3` (`institution_id`,`collector_membership_id`),
  ADD KEY `institution_id_4` (`institution_id`,`cashier_membership_id`);

--
-- Indexes for table `event_obligations`
--
ALTER TABLE `event_obligations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_obligation` (`event_id`,`participant_id`,`collection_rule_id`),
  ADD UNIQUE KEY `uq_obligation_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`event_id`),
  ADD KEY `institution_id_2` (`institution_id`,`participant_id`),
  ADD KEY `collection_rule_id` (`collection_rule_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_expense_number` (`institution_id`,`expense_number`),
  ADD UNIQUE KEY `uq_expense_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_2` (`institution_id`,`event_id`),
  ADD KEY `institution_id_3` (`institution_id`,`financial_account_id`),
  ADD KEY `institution_id_4` (`institution_id`,`receipt_attachment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `paid_by` (`paid_by`);

--
-- Indexes for table `file_attachments`
--
ALTER TABLE `file_attachments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_storage_key` (`storage_key`),
  ADD UNIQUE KEY `uq_attachment_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`uploaded_by_membership_id`);

--
-- Indexes for table `financial_accounts`
--
ALTER TABLE `financial_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_financial_account_tenant` (`institution_id`,`id`);

--
-- Indexes for table `fund_access_assignments`
--
ALTER TABLE `fund_access_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fund_access` (`institution_id`,`fund_id`,`membership_id`,`permission_level`),
  ADD KEY `institution_id` (`institution_id`,`membership_id`);

--
-- Indexes for table `fund_accounts`
--
ALTER TABLE `fund_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fund_code` (`institution_id`,`fund_code`),
  ADD UNIQUE KEY `uq_fund_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`custodian_membership_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `fund_account_mappings`
--
ALTER TABLE `fund_account_mappings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_2` (`institution_id`,`financial_account_id`);

--
-- Indexes for table `fund_transfers`
--
ALTER TABLE `fund_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_transfer_number` (`institution_id`,`transfer_number`),
  ADD KEY `institution_id` (`institution_id`,`from_fund_id`),
  ADD KEY `institution_id_2` (`institution_id`,`to_fund_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_code` (`institution_id`,`group_code`),
  ADD UNIQUE KEY `uq_group_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`department_id`);

--
-- Indexes for table `group_memberships`
--
ALTER TABLE `group_memberships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_participant` (`institution_id`,`group_id`,`participant_id`,`academic_year`,`term`),
  ADD KEY `institution_id` (`institution_id`,`participant_id`);

--
-- Indexes for table `group_officers`
--
ALTER TABLE `group_officers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`group_id`),
  ADD KEY `institution_id_2` (`institution_id`,`membership_id`);

--
-- Indexes for table `institutions`
--
ALTER TABLE `institutions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `institution_code` (`institution_code`);

--
-- Indexes for table `institution_memberships`
--
ALTER TABLE `institution_memberships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_membership` (`institution_id`,`user_id`),
  ADD UNIQUE KEY `uq_membership_tenant` (`institution_id`,`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `institution_settings`
--
ALTER TABLE `institution_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_institution_setting` (`institution_id`,`setting_key`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_journal_number` (`institution_id`,`journal_number`),
  ADD UNIQUE KEY `uq_journal_tenant` (`institution_id`,`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `posted_by` (`posted_by`),
  ADD KEY `institution_id` (`institution_id`,`reversal_of_id`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`journal_entry_id`),
  ADD KEY `institution_id_2` (`institution_id`,`fund_id`),
  ADD KEY `institution_id_3` (`institution_id`,`financial_account_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_identifier` (`identifier_hash`,`attempted_at`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `membership_roles`
--
ALTER TABLE `membership_roles`
  ADD PRIMARY KEY (`membership_id`,`role_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notification_recipient` (`institution_id`,`recipient_user_id`,`read_at`),
  ADD KEY `recipient_user_id` (`recipient_user_id`);

--
-- Indexes for table `number_sequences`
--
ALTER TABLE `number_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_number_sequence` (`institution_id`,`document_type`);

--
-- Indexes for table `participants`
--
ALTER TABLE `participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_participant_number` (`institution_id`,`participant_number`),
  ADD UNIQUE KEY `uq_participant_tenant` (`institution_id`,`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_number` (`institution_id`,`payment_number`),
  ADD UNIQUE KEY `uq_payment_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`payer_participant_id`),
  ADD KEY `institution_id_2` (`institution_id`,`received_by_membership_id`),
  ADD KEY `institution_id_3` (`institution_id`,`financial_account_id`);

--
-- Indexes for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_obligation` (`payment_id`,`obligation_id`),
  ADD KEY `institution_id` (`institution_id`,`payment_id`),
  ADD KEY `institution_id_2` (`institution_id`,`obligation_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_code` (`permission_code`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_receipt_number` (`institution_id`,`receipt_number`),
  ADD UNIQUE KEY `uq_receipt_payment` (`institution_id`,`payment_id`),
  ADD UNIQUE KEY `uq_receipt_tenant` (`institution_id`,`id`),
  ADD KEY `institution_id` (`institution_id`,`issued_by_membership_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_code` (`role_code`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `turnover_acknowledgments`
--
ALTER TABLE `turnover_acknowledgments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`turnover_id`),
  ADD KEY `institution_id_2` (`institution_id`,`acknowledged_by_membership_id`);

--
-- Indexes for table `turnover_discrepancies`
--
ALTER TABLE `turnover_discrepancies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `institution_id` (`institution_id`,`turnover_id`),
  ADD KEY `institution_id_2` (`institution_id`,`reported_by_membership_id`),
  ADD KEY `institution_id_3` (`institution_id`,`resolved_by_membership_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token_hash` (`session_token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_lines`
--
ALTER TABLE `budget_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_advances`
--
ALTER TABLE `cash_advances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_advance_expenses`
--
ALTER TABLE `cash_advance_expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_advance_returns`
--
ALTER TABLE `cash_advance_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_flow_transactions`
--
ALTER TABLE `cash_flow_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_turnovers`
--
ALTER TABLE `cash_turnovers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_turnover_details`
--
ALTER TABLE `cash_turnover_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `collection_rules`
--
ALTER TABLE `collection_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deposits`
--
ALTER TABLE `deposits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_funds`
--
ALTER TABLE `event_funds`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_group_assignments`
--
ALTER TABLE `event_group_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_obligations`
--
ALTER TABLE `event_obligations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_attachments`
--
ALTER TABLE `file_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_accounts`
--
ALTER TABLE `financial_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fund_access_assignments`
--
ALTER TABLE `fund_access_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fund_accounts`
--
ALTER TABLE `fund_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fund_account_mappings`
--
ALTER TABLE `fund_account_mappings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fund_transfers`
--
ALTER TABLE `fund_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_memberships`
--
ALTER TABLE `group_memberships`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_officers`
--
ALTER TABLE `group_officers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `institutions`
--
ALTER TABLE `institutions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `institution_memberships`
--
ALTER TABLE `institution_memberships`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `institution_settings`
--
ALTER TABLE `institution_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `number_sequences`
--
ALTER TABLE `number_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `participants`
--
ALTER TABLE `participants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `turnover_acknowledgments`
--
ALTER TABLE `turnover_acknowledgments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `turnover_discrepancies`
--
ALTER TABLE `turnover_discrepancies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `budgets`
--
ALTER TABLE `budgets`
  ADD CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `budgets_ibfk_2` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `budgets_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `budgets_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `budget_lines`
--
ALTER TABLE `budget_lines`
  ADD CONSTRAINT `budget_lines_ibfk_1` FOREIGN KEY (`institution_id`,`budget_id`) REFERENCES `budgets` (`institution_id`, `id`);

--
-- Constraints for table `cash_advances`
--
ALTER TABLE `cash_advances`
  ADD CONSTRAINT `cash_advances_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advances_ibfk_2` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advances_ibfk_3` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advances_ibfk_4` FOREIGN KEY (`institution_id`,`recipient_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advances_ibfk_5` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `cash_advances_ibfk_6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `cash_advances_ibfk_7` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `cash_advance_expenses`
--
ALTER TABLE `cash_advance_expenses`
  ADD CONSTRAINT `cash_advance_expenses_ibfk_1` FOREIGN KEY (`institution_id`,`cash_advance_id`) REFERENCES `cash_advances` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advance_expenses_ibfk_2` FOREIGN KEY (`institution_id`,`receipt_attachment_id`) REFERENCES `file_attachments` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advance_expenses_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `cash_advance_returns`
--
ALTER TABLE `cash_advance_returns`
  ADD CONSTRAINT `cash_advance_returns_ibfk_1` FOREIGN KEY (`institution_id`,`cash_advance_id`) REFERENCES `cash_advances` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advance_returns_ibfk_2` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_advance_returns_ibfk_3` FOREIGN KEY (`institution_id`,`received_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `cash_flow_transactions`
--
ALTER TABLE `cash_flow_transactions`
  ADD CONSTRAINT `cash_flow_transactions_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_flow_transactions_ibfk_2` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_flow_transactions_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `cash_turnovers`
--
ALTER TABLE `cash_turnovers`
  ADD CONSTRAINT `cash_turnovers_ibfk_1` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_turnovers_ibfk_2` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_turnovers_ibfk_3` FOREIGN KEY (`institution_id`,`group_id`) REFERENCES `groups` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_turnovers_ibfk_4` FOREIGN KEY (`institution_id`,`submitted_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_turnovers_ibfk_5` FOREIGN KEY (`institution_id`,`received_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `cash_turnover_details`
--
ALTER TABLE `cash_turnover_details`
  ADD CONSTRAINT `cash_turnover_details_ibfk_1` FOREIGN KEY (`institution_id`,`turnover_id`) REFERENCES `cash_turnovers` (`institution_id`, `id`),
  ADD CONSTRAINT `cash_turnover_details_ibfk_2` FOREIGN KEY (`institution_id`,`payment_id`) REFERENCES `payments` (`institution_id`, `id`);

--
-- Constraints for table `collection_rules`
--
ALTER TABLE `collection_rules`
  ADD CONSTRAINT `collection_rules_ibfk_1` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`);

--
-- Constraints for table `deposits`
--
ALTER TABLE `deposits`
  ADD CONSTRAINT `deposits_ibfk_1` FOREIGN KEY (`institution_id`,`source_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `deposits_ibfk_2` FOREIGN KEY (`institution_id`,`destination_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `deposits_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `deposits_ibfk_4` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `events_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `event_funds`
--
ALTER TABLE `event_funds`
  ADD CONSTRAINT `event_funds_ibfk_1` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `event_funds_ibfk_2` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`);

--
-- Constraints for table `event_group_assignments`
--
ALTER TABLE `event_group_assignments`
  ADD CONSTRAINT `event_group_assignments_ibfk_1` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `event_group_assignments_ibfk_2` FOREIGN KEY (`institution_id`,`group_id`) REFERENCES `groups` (`institution_id`, `id`),
  ADD CONSTRAINT `event_group_assignments_ibfk_3` FOREIGN KEY (`institution_id`,`collector_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `event_group_assignments_ibfk_4` FOREIGN KEY (`institution_id`,`cashier_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `event_obligations`
--
ALTER TABLE `event_obligations`
  ADD CONSTRAINT `event_obligations_ibfk_1` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `event_obligations_ibfk_2` FOREIGN KEY (`institution_id`,`participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  ADD CONSTRAINT `event_obligations_ibfk_3` FOREIGN KEY (`collection_rule_id`) REFERENCES `collection_rules` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`institution_id`,`event_id`) REFERENCES `events` (`institution_id`, `id`),
  ADD CONSTRAINT `expenses_ibfk_3` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `expenses_ibfk_4` FOREIGN KEY (`institution_id`,`receipt_attachment_id`) REFERENCES `file_attachments` (`institution_id`, `id`),
  ADD CONSTRAINT `expenses_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `expenses_ibfk_6` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `expenses_ibfk_7` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `file_attachments`
--
ALTER TABLE `file_attachments`
  ADD CONSTRAINT `file_attachments_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `file_attachments_ibfk_2` FOREIGN KEY (`institution_id`,`uploaded_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `financial_accounts`
--
ALTER TABLE `financial_accounts`
  ADD CONSTRAINT `financial_accounts_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`);

--
-- Constraints for table `fund_access_assignments`
--
ALTER TABLE `fund_access_assignments`
  ADD CONSTRAINT `fund_access_assignments_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `fund_access_assignments_ibfk_2` FOREIGN KEY (`institution_id`,`membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `fund_accounts`
--
ALTER TABLE `fund_accounts`
  ADD CONSTRAINT `fund_accounts_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `fund_accounts_ibfk_2` FOREIGN KEY (`institution_id`,`custodian_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `fund_accounts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fund_accounts_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `fund_account_mappings`
--
ALTER TABLE `fund_account_mappings`
  ADD CONSTRAINT `fund_account_mappings_ibfk_1` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `fund_account_mappings_ibfk_2` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`);

--
-- Constraints for table `fund_transfers`
--
ALTER TABLE `fund_transfers`
  ADD CONSTRAINT `fund_transfers_ibfk_1` FOREIGN KEY (`institution_id`,`from_fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `fund_transfers_ibfk_2` FOREIGN KEY (`institution_id`,`to_fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `fund_transfers_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fund_transfers_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `groups`
--
ALTER TABLE `groups`
  ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `groups_ibfk_2` FOREIGN KEY (`institution_id`,`department_id`) REFERENCES `departments` (`institution_id`, `id`);

--
-- Constraints for table `group_memberships`
--
ALTER TABLE `group_memberships`
  ADD CONSTRAINT `group_memberships_ibfk_1` FOREIGN KEY (`institution_id`,`group_id`) REFERENCES `groups` (`institution_id`, `id`),
  ADD CONSTRAINT `group_memberships_ibfk_2` FOREIGN KEY (`institution_id`,`participant_id`) REFERENCES `participants` (`institution_id`, `id`);

--
-- Constraints for table `group_officers`
--
ALTER TABLE `group_officers`
  ADD CONSTRAINT `group_officers_ibfk_1` FOREIGN KEY (`institution_id`,`group_id`) REFERENCES `groups` (`institution_id`, `id`),
  ADD CONSTRAINT `group_officers_ibfk_2` FOREIGN KEY (`institution_id`,`membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `institution_memberships`
--
ALTER TABLE `institution_memberships`
  ADD CONSTRAINT `institution_memberships_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `institution_memberships_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `institution_settings`
--
ALTER TABLE `institution_settings`
  ADD CONSTRAINT `institution_settings_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `institution_settings_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_3` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_4` FOREIGN KEY (`institution_id`,`reversal_of_id`) REFERENCES `journal_entries` (`institution_id`, `id`);

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `journal_lines_ibfk_1` FOREIGN KEY (`institution_id`,`journal_entry_id`) REFERENCES `journal_entries` (`institution_id`, `id`),
  ADD CONSTRAINT `journal_lines_ibfk_2` FOREIGN KEY (`institution_id`,`fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  ADD CONSTRAINT `journal_lines_ibfk_3` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`);

--
-- Constraints for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD CONSTRAINT `login_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `membership_roles`
--
ALTER TABLE `membership_roles`
  ADD CONSTRAINT `membership_roles_ibfk_1` FOREIGN KEY (`membership_id`) REFERENCES `institution_memberships` (`id`),
  ADD CONSTRAINT `membership_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `number_sequences`
--
ALTER TABLE `number_sequences`
  ADD CONSTRAINT `number_sequences_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`);

--
-- Constraints for table `participants`
--
ALTER TABLE `participants`
  ADD CONSTRAINT `participants_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`);

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`institution_id`,`payer_participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`institution_id`,`received_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`institution_id`,`financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`);

--
-- Constraints for table `payment_allocations`
--
ALTER TABLE `payment_allocations`
  ADD CONSTRAINT `payment_allocations_ibfk_1` FOREIGN KEY (`institution_id`,`payment_id`) REFERENCES `payments` (`institution_id`, `id`),
  ADD CONSTRAINT `payment_allocations_ibfk_2` FOREIGN KEY (`institution_id`,`obligation_id`) REFERENCES `event_obligations` (`institution_id`, `id`);

--
-- Constraints for table `receipts`
--
ALTER TABLE `receipts`
  ADD CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`institution_id`,`payment_id`) REFERENCES `payments` (`institution_id`, `id`),
  ADD CONSTRAINT `receipts_ibfk_2` FOREIGN KEY (`institution_id`,`issued_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`);

--
-- Constraints for table `turnover_acknowledgments`
--
ALTER TABLE `turnover_acknowledgments`
  ADD CONSTRAINT `turnover_acknowledgments_ibfk_1` FOREIGN KEY (`institution_id`,`turnover_id`) REFERENCES `cash_turnovers` (`institution_id`, `id`),
  ADD CONSTRAINT `turnover_acknowledgments_ibfk_2` FOREIGN KEY (`institution_id`,`acknowledged_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `turnover_discrepancies`
--
ALTER TABLE `turnover_discrepancies`
  ADD CONSTRAINT `turnover_discrepancies_ibfk_1` FOREIGN KEY (`institution_id`,`turnover_id`) REFERENCES `cash_turnovers` (`institution_id`, `id`),
  ADD CONSTRAINT `turnover_discrepancies_ibfk_2` FOREIGN KEY (`institution_id`,`reported_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  ADD CONSTRAINT `turnover_discrepancies_ibfk_3` FOREIGN KEY (`institution_id`,`resolved_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`);

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


-- ===========================================================================
-- ANINAW ADDITIONS (appended to the original dump; adds one table and one column)
-- ===========================================================================

-- Part 1 of 2: schema additions.
--
-- Why this migration exists (the base schema cannot express these two things):
--
-- 1. Platform-scoped roles have nowhere to be assigned.
--    `roles.scope_type` supports 'platform', but `membership_roles` links roles
--    only to `institution_memberships`, which always belong to one institution.
--    Without this table, a Platform Super Administrator would have to be a
--    member of some institution, which would wrongly mix platform and
--    institution privileges. `platform_user_roles` assigns platform roles
--    directly to users.
--
-- 2. A login account cannot be linked to its participant (student/member) record.
--    `participants` has no reference to `users`, so the Student / Member
--    dashboard could only guess the link by matching e-mail addresses, which is
--    unsafe. `participants.user_id` makes the link explicit, and the unique key
--    allows at most one participant record per user per institution.
--
-- Both changes are additive. No existing column or row is changed.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `platform_user_roles` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `role_id` (`role_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `platform_user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `platform_user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `platform_user_roles_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `participants`
  ADD COLUMN `user_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `institution_id`,
  ADD UNIQUE KEY `uq_participant_user` (`institution_id`, `user_id`),
  ADD CONSTRAINT `participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

COMMIT;


-- ANINAW seed: roles, permissions and role-permission mapping.
-- Part 2 of 2: initial seed data. The original dump has empty roles/permissions tables.
-- Safe to re-run: rows are matched by their unique codes, never by numeric IDs.

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------------
INSERT INTO `roles` (`role_code`, `role_name`, `scope_type`, `description`) VALUES
('platform_super_admin', 'Platform Super Administrator', 'platform',    'Manages the ANINAW platform and institution onboarding. Has no institution access unless given a membership.'),
('institution_admin',    'Institution Administrator',    'institution', 'Manages users, roles and institution-level settings.'),
('treasurer',            'Treasurer / Finance Officer',  'institution', 'Manages funds, collections, cash flow and financial monitoring.'),
('event_organizer',      'Event Organizer',              'institution', 'Manages events, collection requirements and event reports.'),
('cashier',              'Faculty Cashier / Collection Officer', 'institution', 'Receives funds, records collections and processes cash turnovers.'),
('collector',            'Class President / Collector',  'institution', 'Records assigned group collections and submits turnovers.'),
('adviser',              'Adviser',                      'institution', 'Monitors assigned groups, collections and turnovers.'),
('auditor',              'Auditor',                      'institution', 'Reviews transactions, ledger entries, discrepancies and audit logs. Read-only.'),
('member',               'Student / Member',             'institution', 'Views own obligations, payments and receipts.'),
('advance_recipient',    'Cash Advance Recipient',       'institution', 'Views assigned cash advances and submits liquidation.')
ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`), `scope_type` = VALUES(`scope_type`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('platform.institutions.view',   'View institutions (platform)',   'List and view institution profiles across the platform.'),
('platform.institutions.manage', 'Manage institutions (platform)', 'Onboard, activate and suspend institutions.'),
('platform.users.view',          'View users (platform)',          'View user accounts across the platform.'),
('platform.audit.view',          'View platform audit trail',      'View platform-level audit logs.'),
('dashboard.view',               'View institution dashboard',     'Open the institution dashboard.'),
('institution.settings.manage',  'Manage institution settings',    'Change institution profile and settings.'),
('users.view',                   'View users',                     'View institution members.'),
('users.manage',                 'Manage users',                   'Create, edit and deactivate institution members.'),
('roles.assign',                 'Assign roles',                   'Assign institution roles to members.'),
('funds.view',                   'View funds',                     'View assigned fund accounts.'),
('funds.view_all',               'View all funds',                 'View every fund account in the institution.'),
('funds.manage',                 'Manage funds',                   'Create and configure fund accounts.'),
('events.view',                  'View events',                    'View events and their collection rules.'),
('events.manage',                'Manage events',                  'Create events and collection rules.'),
('groups.monitor',               'Monitor groups',                 'Monitor collections of advised groups.'),
('collections.view',             'View collections',               'View obligations and payments.'),
('collections.record',           'Record collections',             'Record participant payments.'),
('receipts.issue',               'Issue receipts',                 'Issue official receipts.'),
('turnovers.view',               'View turnovers',                 'View cash turnover records.'),
('turnovers.submit',             'Submit turnovers',               'Prepare and submit cash turnovers.'),
('turnovers.receive',            'Receive turnovers',              'Count, acknowledge and verify cash turnovers.'),
('expenses.view',                'View expenses',                  'View direct expenses.'),
('expenses.record',              'Record expenses',                'Record direct expenses.'),
('expenses.approve',             'Approve expenses',               'Approve or reject expenses.'),
('advances.view',                'View cash advances',             'View all cash advances.'),
('advances.request',             'Request cash advances',          'Submit cash advance requests.'),
('advances.approve',             'Approve cash advances',          'Approve cash advance requests.'),
('advances.release',             'Release cash advances',          'Disburse approved cash advances.'),
('liquidation.submit',           'Submit liquidation',             'Submit expenses and returns for an advance.'),
('liquidation.review',           'Review liquidation',             'Verify and approve liquidations.'),
('cashflow.view',                'View cash flow',                 'View the cash movement register.'),
('deposits.manage',              'Manage deposits',                'Record and confirm deposits.'),
('transfers.manage',             'Manage transfers',               'Request and post inter-fund transfers.'),
('reconciliation.manage',        'Manage reconciliation',          'Reconcile custodians, accounts and advances.'),
('ledger.view',                  'View ledger',                    'View journal entries and ledger lines.'),
('reports.view',                 'View reports',                   'View financial and collection reports.'),
('audit.view',                   'View audit trail',               'View institution audit logs.'),
('self.obligations.view',        'View own obligations',           'View own obligations, payments and receipts.'),
('self.advances.view',           'View own cash advances',         'View cash advances assigned to self.')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------------
-- Role -> permission mapping (resolved by codes)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'platform_super_admin' AS role_code, 'platform.institutions.view' AS permission_code UNION ALL
    SELECT 'platform_super_admin', 'platform.institutions.manage' UNION ALL
    SELECT 'platform_super_admin', 'platform.users.view' UNION ALL
    SELECT 'platform_super_admin', 'platform.audit.view' UNION ALL

    SELECT 'institution_admin', 'dashboard.view' UNION ALL
    SELECT 'institution_admin', 'institution.settings.manage' UNION ALL
    SELECT 'institution_admin', 'users.view' UNION ALL
    SELECT 'institution_admin', 'users.manage' UNION ALL
    SELECT 'institution_admin', 'roles.assign' UNION ALL
    SELECT 'institution_admin', 'funds.view' UNION ALL
    SELECT 'institution_admin', 'funds.view_all' UNION ALL
    SELECT 'institution_admin', 'events.view' UNION ALL
    SELECT 'institution_admin', 'reports.view' UNION ALL
    SELECT 'institution_admin', 'audit.view' UNION ALL

    SELECT 'treasurer', 'dashboard.view' UNION ALL
    SELECT 'treasurer', 'funds.view' UNION ALL
    SELECT 'treasurer', 'funds.view_all' UNION ALL
    SELECT 'treasurer', 'funds.manage' UNION ALL
    SELECT 'treasurer', 'events.view' UNION ALL
    SELECT 'treasurer', 'collections.view' UNION ALL
    SELECT 'treasurer', 'turnovers.view' UNION ALL
    SELECT 'treasurer', 'expenses.view' UNION ALL
    SELECT 'treasurer', 'expenses.record' UNION ALL
    SELECT 'treasurer', 'expenses.approve' UNION ALL
    SELECT 'treasurer', 'advances.view' UNION ALL
    SELECT 'treasurer', 'advances.approve' UNION ALL
    SELECT 'treasurer', 'advances.release' UNION ALL
    SELECT 'treasurer', 'liquidation.review' UNION ALL
    SELECT 'treasurer', 'cashflow.view' UNION ALL
    SELECT 'treasurer', 'deposits.manage' UNION ALL
    SELECT 'treasurer', 'transfers.manage' UNION ALL
    SELECT 'treasurer', 'reconciliation.manage' UNION ALL
    SELECT 'treasurer', 'ledger.view' UNION ALL
    SELECT 'treasurer', 'reports.view' UNION ALL

    SELECT 'event_organizer', 'dashboard.view' UNION ALL
    SELECT 'event_organizer', 'events.view' UNION ALL
    SELECT 'event_organizer', 'events.manage' UNION ALL
    SELECT 'event_organizer', 'collections.view' UNION ALL
    SELECT 'event_organizer', 'expenses.view' UNION ALL
    SELECT 'event_organizer', 'advances.request' UNION ALL
    SELECT 'event_organizer', 'reports.view' UNION ALL

    SELECT 'cashier', 'dashboard.view' UNION ALL
    SELECT 'cashier', 'collections.view' UNION ALL
    SELECT 'cashier', 'collections.record' UNION ALL
    SELECT 'cashier', 'receipts.issue' UNION ALL
    SELECT 'cashier', 'turnovers.view' UNION ALL
    SELECT 'cashier', 'turnovers.receive' UNION ALL
    SELECT 'cashier', 'advances.release' UNION ALL

    SELECT 'collector', 'dashboard.view' UNION ALL
    SELECT 'collector', 'collections.view' UNION ALL
    SELECT 'collector', 'collections.record' UNION ALL
    SELECT 'collector', 'turnovers.view' UNION ALL
    SELECT 'collector', 'turnovers.submit' UNION ALL

    SELECT 'adviser', 'dashboard.view' UNION ALL
    SELECT 'adviser', 'groups.monitor' UNION ALL
    SELECT 'adviser', 'collections.view' UNION ALL
    SELECT 'adviser', 'turnovers.view' UNION ALL

    SELECT 'auditor', 'dashboard.view' UNION ALL
    SELECT 'auditor', 'funds.view' UNION ALL
    SELECT 'auditor', 'funds.view_all' UNION ALL
    SELECT 'auditor', 'events.view' UNION ALL
    SELECT 'auditor', 'collections.view' UNION ALL
    SELECT 'auditor', 'turnovers.view' UNION ALL
    SELECT 'auditor', 'expenses.view' UNION ALL
    SELECT 'auditor', 'advances.view' UNION ALL
    SELECT 'auditor', 'cashflow.view' UNION ALL
    SELECT 'auditor', 'ledger.view' UNION ALL
    SELECT 'auditor', 'reports.view' UNION ALL
    SELECT 'auditor', 'audit.view' UNION ALL

    SELECT 'member', 'dashboard.view' UNION ALL
    SELECT 'member', 'self.obligations.view' UNION ALL

    SELECT 'advance_recipient', 'dashboard.view' UNION ALL
    SELECT 'advance_recipient', 'self.advances.view' UNION ALL
    SELECT 'advance_recipient', 'advances.request' UNION ALL
    SELECT 'advance_recipient', 'liquidation.submit'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

COMMIT;


-- ===========================================================================
-- Part 3: migration 002 (financial modules). Existing installs run
-- database/migration_002_financial_modules.sql instead.
-- ===========================================================================
START TRANSACTION;

ALTER TABLE `financial_accounts`
  ADD COLUMN `custodian_membership_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `account_reference`,
  ADD KEY `idx_financial_account_custodian` (`institution_id`, `custodian_membership_id`),
  ADD CONSTRAINT `financial_accounts_ibfk_2` FOREIGN KEY (`institution_id`, `custodian_membership_id`)
      REFERENCES `institution_memberships` (`institution_id`, `id`);

ALTER TABLE `file_attachments`
  ADD COLUMN `entity_type` varchar(60) DEFAULT NULL AFTER `uploaded_by_membership_id`,
  ADD COLUMN `entity_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `entity_type`,
  ADD KEY `idx_attachment_entity` (`institution_id`, `entity_type`, `entity_id`),
  ADD KEY `idx_attachment_checksum` (`institution_id`, `checksum`);

CREATE TABLE IF NOT EXISTS `event_organizers` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `membership_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_organizer` (`event_id`, `membership_id`),
  KEY `idx_event_organizer_member` (`institution_id`, `membership_id`),
  CONSTRAINT `event_organizers_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_organizers_ibfk_2` FOREIGN KEY (`institution_id`, `membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  CONSTRAINT `event_organizers_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('organization.manage', 'Manage organization',    'Manage departments, groups, participants and officers.'),
('participants.view',   'View participants',      'Search and view participant records.'),
('payments.reverse',    'Reverse payments',       'Void receipts and reverse posted payments with a reason.'),
('transfers.approve',   'Approve transfers',      'Approve or reject fund transfer requests.'),
('ledger.post',         'Post journal entries',   'Post balanced adjusting entries and reversals.'),
('budgets.view',        'View budgets',           'View budgets and budget-versus-actual reports.'),
('budgets.manage',      'Manage budgets',         'Create budgets, lines and revisions.'),
('budgets.approve',     'Approve budgets',        'Approve, activate and close budgets.')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'institution_admin' AS role_code, 'organization.manage' AS permission_code UNION ALL
    SELECT 'institution_admin', 'participants.view' UNION ALL
    SELECT 'institution_admin', 'expenses.view' UNION ALL
    SELECT 'institution_admin', 'expenses.approve' UNION ALL
    SELECT 'institution_admin', 'advances.view' UNION ALL
    SELECT 'institution_admin', 'advances.approve' UNION ALL
    SELECT 'institution_admin', 'transfers.approve' UNION ALL
    SELECT 'institution_admin', 'budgets.view' UNION ALL
    SELECT 'institution_admin', 'budgets.approve' UNION ALL

    SELECT 'treasurer', 'participants.view' UNION ALL
    SELECT 'treasurer', 'collections.record' UNION ALL
    SELECT 'treasurer', 'receipts.issue' UNION ALL
    SELECT 'treasurer', 'turnovers.receive' UNION ALL
    SELECT 'treasurer', 'payments.reverse' UNION ALL
    SELECT 'treasurer', 'transfers.approve' UNION ALL
    SELECT 'treasurer', 'ledger.post' UNION ALL
    SELECT 'treasurer', 'budgets.view' UNION ALL
    SELECT 'treasurer', 'budgets.manage' UNION ALL

    SELECT 'event_organizer', 'participants.view' UNION ALL
    SELECT 'event_organizer', 'budgets.view' UNION ALL
    SELECT 'event_organizer', 'budgets.manage' UNION ALL

    SELECT 'cashier', 'participants.view' UNION ALL
    SELECT 'collector', 'participants.view' UNION ALL
    SELECT 'adviser', 'participants.view' UNION ALL

    SELECT 'auditor', 'participants.view' UNION ALL
    SELECT 'auditor', 'budgets.view'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

COMMIT;

-- ===========================================================================
-- Part 4: migration 003 (products, inventory, orders, printing). Existing
-- installs run database/migration_003_products_orders.sql instead.
-- ===========================================================================
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `category_name` varchar(120) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_category_name` (`institution_id`, `category_name`),
  UNIQUE KEY `uq_product_category_tenant` (`institution_id`, `id`),
  CONSTRAINT `product_categories_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(60) NOT NULL,
  `product_name` varchar(180) NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `product_type` enum('physical','service') NOT NULL DEFAULT 'physical',
  `fulfillment` enum('stock','made_to_order','service') NOT NULL DEFAULT 'stock'
    COMMENT 'stock = sold from inventory; made_to_order = ordered first, produced/received later; service = performed on demand',
  `fund_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Fund that receives the sales',
  `unit_of_measure` varchar(30) NOT NULL DEFAULT 'piece' COMMENT 'piece, set, page, copy, sheet, job, sqm, ream...',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `retail_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` int(11) NOT NULL DEFAULT 0,
  `allow_partial_payment` tinyint(1) NOT NULL DEFAULT 0,
  `image_attachment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_sku` (`institution_id`, `sku`),
  UNIQUE KEY `uq_product_tenant` (`institution_id`, `id`),
  KEY `idx_product_category` (`institution_id`, `category_id`),
  KEY `idx_product_fund` (`institution_id`, `fund_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`institution_id`, `category_id`) REFERENCES `product_categories` (`institution_id`, `id`),
  CONSTRAINT `products_ibfk_3` FOREIGN KEY (`institution_id`, `fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  CONSTRAINT `products_ibfk_4` FOREIGN KEY (`institution_id`, `image_attachment_id`) REFERENCES `file_attachments` (`institution_id`, `id`),
  CONSTRAINT `products_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_product_amounts` CHECK (`unit_cost` >= 0 AND `retail_price` >= 0 AND `reorder_level` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Variations (shirt sizes, paper sizes, colour/B&W). Every product has at least
-- one; products without variations use a single "Standard" variant.
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `variant_name` varchar(80) NOT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL COMMENT 'NULL = use the product cost',
  `retail_price` decimal(12,2) DEFAULT NULL COMMENT 'NULL = use the product price',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variant_name` (`product_id`, `variant_name`),
  UNIQUE KEY `uq_variant_tenant` (`institution_id`, `id`),
  KEY `idx_variant_product` (`institution_id`, `product_id`),
  CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`institution_id`, `product_id`) REFERENCES `products` (`institution_id`, `id`),
  CONSTRAINT `chk_variant_amounts` CHECK ((`unit_cost` IS NULL OR `unit_cost` >= 0) AND (`retail_price` IS NULL OR `retail_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Supplier purchases and production runs (e.g. a shirt order to the printer).
-- Ordered quantities are not stock; only received quantities become stock.
CREATE TABLE IF NOT EXISTS `stock_batches` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `batch_number` varchar(80) NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_name` varchar(180) DEFAULT NULL,
  `status` enum('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `ordered_at` date DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `expense_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Expense that paid the supplier, when recorded',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_number` (`institution_id`, `batch_number`),
  UNIQUE KEY `uq_batch_tenant` (`institution_id`, `id`),
  KEY `idx_batch_product` (`institution_id`, `product_id`, `status`),
  CONSTRAINT `stock_batches_ibfk_1` FOREIGN KEY (`institution_id`, `product_id`) REFERENCES `products` (`institution_id`, `id`),
  CONSTRAINT `stock_batches_ibfk_2` FOREIGN KEY (`institution_id`, `expense_id`) REFERENCES `expenses` (`institution_id`, `id`),
  CONSTRAINT `stock_batches_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stock_batch_items` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL,
  `quantity_ordered` int(11) NOT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_variant` (`batch_id`, `variant_id`),
  UNIQUE KEY `uq_batch_item_tenant` (`institution_id`, `id`),
  CONSTRAINT `stock_batch_items_ibfk_1` FOREIGN KEY (`institution_id`, `batch_id`) REFERENCES `stock_batches` (`institution_id`, `id`),
  CONSTRAINT `stock_batch_items_ibfk_2` FOREIGN KEY (`institution_id`, `variant_id`) REFERENCES `product_variants` (`institution_id`, `id`),
  CONSTRAINT `chk_batch_quantities` CHECK (`quantity_ordered` > 0 AND `quantity_received` >= 0 AND `quantity_received` <= `quantity_ordered` AND `unit_cost` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sales_orders` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `order_number` varchar(80) NOT NULL,
  `customer_participant_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL COMMENT 'All lines of an order belong to one fund',
  `order_date` date NOT NULL,
  `order_status` enum('pending','confirmed','in_production','ready_for_release','partially_released','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status` enum('unpaid','partially_paid','fully_paid') NOT NULL DEFAULT 'unpaid',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `discount_approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `cancelled_reason` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_number` (`institution_id`, `order_number`),
  UNIQUE KEY `uq_order_tenant` (`institution_id`, `id`),
  KEY `idx_order_customer` (`institution_id`, `customer_participant_id`),
  KEY `idx_order_status` (`institution_id`, `order_status`, `payment_status`),
  KEY `idx_order_date` (`institution_id`, `order_date`),
  CONSTRAINT `sales_orders_ibfk_1` FOREIGN KEY (`institution_id`, `customer_participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `sales_orders_ibfk_2` FOREIGN KEY (`institution_id`, `fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  CONSTRAINT `sales_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `sales_orders_ibfk_4` FOREIGN KEY (`discount_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_order_amounts` CHECK (`subtotal` >= 0 AND `discount_amount` >= 0 AND `discount_amount` <= `subtotal` AND `total_amount` = `subtotal` - `discount_amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sales_order_items` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL,
  `description` varchar(255) NOT NULL COMMENT 'Product and variant name at the time of the order',
  `fulfillment` enum('stock','made_to_order','service') NOT NULL COMMENT 'Snapshot',
  `pricing_unit` varchar(30) NOT NULL COMMENT 'Snapshot of the unit of measure',
  `allow_partial_payment` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Snapshot',
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL COMMENT 'Snapshot of the selling price',
  `unit_cost` decimal(12,2) NOT NULL COMMENT 'Snapshot of the cost',
  `additional_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `additional_charge_note` varchar(180) DEFAULT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `quantity_released` int(11) NOT NULL DEFAULT 0,
  -- Printing / service job details
  `service_status` enum('pending','in_progress','completed','released') DEFAULT NULL,
  `job_pages` int(11) DEFAULT NULL,
  `job_copies` int(11) DEFAULT NULL,
  `job_instructions` text DEFAULT NULL,
  `service_date` date DEFAULT NULL,
  `assigned_membership_id` bigint(20) UNSIGNED DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_item_tenant` (`institution_id`, `id`),
  KEY `idx_order_item_order` (`institution_id`, `order_id`),
  KEY `idx_order_item_variant` (`institution_id`, `variant_id`),
  KEY `idx_order_item_service` (`institution_id`, `service_status`),
  CONSTRAINT `sales_order_items_ibfk_1` FOREIGN KEY (`institution_id`, `order_id`) REFERENCES `sales_orders` (`institution_id`, `id`),
  CONSTRAINT `sales_order_items_ibfk_2` FOREIGN KEY (`institution_id`, `product_id`) REFERENCES `products` (`institution_id`, `id`),
  CONSTRAINT `sales_order_items_ibfk_3` FOREIGN KEY (`institution_id`, `variant_id`) REFERENCES `product_variants` (`institution_id`, `id`),
  CONSTRAINT `sales_order_items_ibfk_4` FOREIGN KEY (`institution_id`, `assigned_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  CONSTRAINT `chk_order_item_amounts` CHECK (`quantity` > 0 AND `unit_price` >= 0 AND `unit_cost` >= 0 AND `additional_charge` >= 0
    AND `quantity_released` >= 0 AND `quantity_released` <= `quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which order a payment settles (a payment settles exactly one order).
CREATE TABLE IF NOT EXISTS `sales_order_payments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_payment` (`payment_id`),
  KEY `idx_order_payment_order` (`institution_id`, `order_id`),
  CONSTRAINT `sales_order_payments_ibfk_1` FOREIGN KEY (`institution_id`, `order_id`) REFERENCES `sales_orders` (`institution_id`, `id`),
  CONSTRAINT `sales_order_payments_ibfk_2` FOREIGN KEY (`institution_id`, `payment_id`) REFERENCES `payments` (`institution_id`, `id`),
  CONSTRAINT `chk_order_payment_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every physical stock change. quantity > 0 adds stock, < 0 removes it.
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` enum('beginning','received','released','consumed','damaged','lost','returned','adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `movement_date` date NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `order_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `negative_override` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = an administrator allowed stock to go below zero',
  `recorded_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_movement_tenant` (`institution_id`, `id`),
  KEY `idx_movement_variant` (`institution_id`, `variant_id`, `movement_date`),
  KEY `idx_movement_type` (`institution_id`, `movement_type`, `movement_date`),
  CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`institution_id`, `variant_id`) REFERENCES `product_variants` (`institution_id`, `id`),
  CONSTRAINT `inventory_movements_ibfk_2` FOREIGN KEY (`institution_id`, `batch_id`) REFERENCES `stock_batches` (`institution_id`, `id`),
  CONSTRAINT `inventory_movements_ibfk_3` FOREIGN KEY (`institution_id`, `order_item_id`) REFERENCES `sales_order_items` (`institution_id`, `id`),
  CONSTRAINT `inventory_movements_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_movement_quantity` CHECK (`quantity` <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Hand-over of order items (and completed services) to the customer.
CREATE TABLE IF NOT EXISTS `sales_order_releases` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `order_item_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(11) NOT NULL,
  `received_by_name` varchar(180) NOT NULL COMMENT 'Person who claimed the items',
  `released_by` bigint(20) UNSIGNED NOT NULL,
  `released_at` datetime NOT NULL,
  `movement_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_release_order` (`institution_id`, `order_id`),
  CONSTRAINT `sales_order_releases_ibfk_1` FOREIGN KEY (`institution_id`, `order_id`) REFERENCES `sales_orders` (`institution_id`, `id`),
  CONSTRAINT `sales_order_releases_ibfk_2` FOREIGN KEY (`institution_id`, `order_item_id`) REFERENCES `sales_order_items` (`institution_id`, `id`),
  CONSTRAINT `sales_order_releases_ibfk_3` FOREIGN KEY (`institution_id`, `movement_id`) REFERENCES `inventory_movements` (`institution_id`, `id`),
  CONSTRAINT `sales_order_releases_ibfk_4` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_release_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Supplies a service consumes (e.g. 1 sheet of A4 paper per printed page).
-- No rule = the service never touches stock.
CREATE TABLE IF NOT EXISTS `service_consumption_rules` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `service_variant_id` bigint(20) UNSIGNED NOT NULL,
  `supply_variant_id` bigint(20) UNSIGNED NOT NULL,
  `quantity_per_unit` decimal(10,3) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_consumption_rule` (`service_variant_id`, `supply_variant_id`),
  KEY `idx_consumption_tenant` (`institution_id`, `service_variant_id`),
  CONSTRAINT `service_consumption_rules_ibfk_1` FOREIGN KEY (`institution_id`, `service_variant_id`) REFERENCES `product_variants` (`institution_id`, `id`),
  CONSTRAINT `service_consumption_rules_ibfk_2` FOREIGN KEY (`institution_id`, `supply_variant_id`) REFERENCES `product_variants` (`institution_id`, `id`),
  CONSTRAINT `chk_consumption_quantity` CHECK (`quantity_per_unit` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Roles and permissions (matched by code; safe to re-run)
-- ---------------------------------------------------------------------------
INSERT INTO `roles` (`role_code`, `role_name`, `scope_type`, `description`) VALUES
('inventory_custodian', 'Inventory Custodian', 'institution', 'Records stock receipts, releases, deliveries and inventory adjustments.'),
('order_staff',         'Order Staff',         'institution', 'Creates and manages product orders and printing jobs.')
ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`), `description` = VALUES(`description`);

INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('products.view',      'View products',            'View products, services, prices and variants.'),
('products.manage',    'Manage products',          'Create, edit and deactivate products, services, categories and variants.'),
('products.pricing',   'Change prices and costs',  'Set or change unit costs and retail prices.'),
('inventory.view',     'View inventory',           'View stock levels, movements and deliveries.'),
('inventory.manage',   'Manage stock',             'Record beginning stock, supplier/production orders and deliveries.'),
('inventory.adjust',   'Adjust inventory',         'Record damaged, lost, returned or adjusted stock with a reason.'),
('inventory.override', 'Allow negative stock',     'Explicitly allow a stock movement that takes stock below zero.'),
('orders.view',        'View orders',              'View product orders, printing jobs and their payments.'),
('orders.manage',      'Manage orders',            'Create orders and printing jobs, confirm, track production and release.'),
('orders.discount',    'Approve discounts',        'Apply a discount to an order, with a reason.'),
('orders.cancel',      'Cancel orders',            'Cancel an order, with a reason.')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'institution_admin' AS role_code, 'products.view' AS permission_code UNION ALL
    SELECT 'institution_admin', 'products.manage' UNION ALL
    SELECT 'institution_admin', 'products.pricing' UNION ALL
    SELECT 'institution_admin', 'inventory.view' UNION ALL
    SELECT 'institution_admin', 'inventory.manage' UNION ALL
    SELECT 'institution_admin', 'inventory.adjust' UNION ALL
    SELECT 'institution_admin', 'inventory.override' UNION ALL
    SELECT 'institution_admin', 'orders.view' UNION ALL
    SELECT 'institution_admin', 'orders.manage' UNION ALL
    SELECT 'institution_admin', 'orders.discount' UNION ALL
    SELECT 'institution_admin', 'orders.cancel' UNION ALL

    SELECT 'treasurer', 'products.view' UNION ALL
    SELECT 'treasurer', 'inventory.view' UNION ALL
    SELECT 'treasurer', 'orders.view' UNION ALL

    SELECT 'cashier', 'products.view' UNION ALL
    SELECT 'cashier', 'orders.view' UNION ALL

    SELECT 'auditor', 'products.view' UNION ALL
    SELECT 'auditor', 'inventory.view' UNION ALL
    SELECT 'auditor', 'orders.view' UNION ALL

    SELECT 'inventory_custodian', 'dashboard.view' UNION ALL
    SELECT 'inventory_custodian', 'products.view' UNION ALL
    SELECT 'inventory_custodian', 'inventory.view' UNION ALL
    SELECT 'inventory_custodian', 'inventory.manage' UNION ALL
    SELECT 'inventory_custodian', 'inventory.adjust' UNION ALL
    SELECT 'inventory_custodian', 'orders.view' UNION ALL

    SELECT 'order_staff', 'dashboard.view' UNION ALL
    SELECT 'order_staff', 'products.view' UNION ALL
    SELECT 'order_staff', 'inventory.view' UNION ALL
    SELECT 'order_staff', 'orders.view' UNION ALL
    SELECT 'order_staff', 'orders.manage' UNION ALL
    SELECT 'order_staff', 'participants.view'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

COMMIT;

-- ===========================================================================
-- Part 5: migration 004 (people added directly to an event). Existing installs
-- run database/migration_004_event_people.sql instead.
-- ===========================================================================
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `event_participants` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('active','removed') NOT NULL DEFAULT 'active',
  `added_by` bigint(20) UNSIGNED NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_participant` (`event_id`, `participant_id`),
  KEY `idx_event_participants` (`institution_id`, `event_id`, `status`),
  KEY `idx_participant_events` (`institution_id`, `participant_id`),
  CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`institution_id`, `participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `event_participants_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

-- ===========================================================================
-- Part 6: migration 005 (cash advance requesters; event document requirements).
-- Existing installs run database/migration_005_advance_roles_event_requirements.sql instead.
-- ===========================================================================
START TRANSACTION;

INSERT INTO `roles` (`role_code`, `role_name`, `scope_type`, `description`) VALUES
('faculty',       'Faculty',       'institution', 'Faculty member: may request cash advances and submit liquidations.'),
('dean',          'Dean',          'institution', 'Dean: may request cash advances and submit liquidations.'),
('program_chair', 'Program Chair', 'institution', 'Program chair: may request cash advances and submit liquidations.')
ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`), `description` = VALUES(`description`);

INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('events.requirements', 'Check event documents', 'Record and check the documents people submit for any event (waivers, medical certificates, IDs).')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'faculty' AS role_code, 'dashboard.view' AS permission_code UNION ALL
    SELECT 'faculty', 'advances.request' UNION ALL
    SELECT 'faculty', 'liquidation.submit' UNION ALL
    SELECT 'dean', 'dashboard.view' UNION ALL
    SELECT 'dean', 'advances.request' UNION ALL
    SELECT 'dean', 'liquidation.submit' UNION ALL
    SELECT 'program_chair', 'dashboard.view' UNION ALL
    SELECT 'program_chair', 'advances.request' UNION ALL
    SELECT 'program_chair', 'liquidation.submit' UNION ALL
    SELECT 'collector', 'advances.request' UNION ALL
    SELECT 'collector', 'liquidation.submit' UNION ALL
    SELECT 'adviser', 'advances.request' UNION ALL
    SELECT 'adviser', 'liquidation.submit' UNION ALL
    SELECT 'event_organizer', 'liquidation.submit' UNION ALL
    SELECT 'faculty', 'self.advances.view' UNION ALL
    SELECT 'dean', 'self.advances.view' UNION ALL
    SELECT 'program_chair', 'self.advances.view' UNION ALL
    SELECT 'collector', 'self.advances.view' UNION ALL
    SELECT 'adviser', 'self.advances.view' UNION ALL
    SELECT 'event_organizer', 'self.advances.view' UNION ALL
    SELECT 'institution_admin', 'events.requirements'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

CREATE TABLE IF NOT EXISTS `event_requirements` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `requirement_name` varchar(180) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `applies_to_type` enum('all','student','employee','member','other') NOT NULL DEFAULT 'all' COMMENT 'Which kind of person must submit it',
  `is_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = optional, not counted for clearance',
  `due_date` date DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_requirement_tenant` (`institution_id`, `id`),
  KEY `idx_event_requirements` (`institution_id`, `event_id`, `status`),
  CONSTRAINT `event_requirements_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_requirements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `event_requirement_submissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `requirement_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('submitted','approved','rejected','waived') NOT NULL DEFAULT 'submitted' COMMENT 'No row = not yet submitted',
  `remarks` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `recorded_by` bigint(20) UNSIGNED NOT NULL,
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requirement_person` (`requirement_id`, `participant_id`),
  UNIQUE KEY `uq_requirement_submission_tenant` (`institution_id`, `id`),
  KEY `idx_submissions_event` (`institution_id`, `event_id`, `participant_id`),
  CONSTRAINT `event_requirement_submissions_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_2` FOREIGN KEY (`institution_id`, `requirement_id`) REFERENCES `event_requirements` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_3` FOREIGN KEY (`institution_id`, `participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `event_requirement_submissions_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

-- ===========================================================================
-- Part 7: migration 006 (people details, academic programs). Existing installs
-- run database/migration_006_people_details.sql instead.
-- ===========================================================================
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `programs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `program_code` varchar(30) NOT NULL,
  `program_name` varchar(180) NOT NULL,
  `department_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_program_code` (`institution_id`, `program_code`),
  UNIQUE KEY `uq_program_tenant` (`institution_id`, `id`),
  CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `programs_ibfk_2` FOREIGN KEY (`institution_id`, `department_id`) REFERENCES `departments` (`institution_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `programs` (`institution_id`, `program_code`, `program_name`)
SELECT i.id, p.code, p.name FROM `institutions` i
JOIN (
    SELECT 'BSIT' AS code, 'Bachelor of Science in Information Technology' AS name UNION ALL
    SELECT 'BSCS', 'Bachelor of Science in Computer Science' UNION ALL
    SELECT 'BSIS', 'Bachelor of Science in Information Systems' UNION ALL
    SELECT 'ACT', 'Associate in Computer Technology' UNION ALL
    SELECT 'BEEd', 'Bachelor of Elementary Education' UNION ALL
    SELECT 'BECEd', 'Bachelor of Early Childhood Education' UNION ALL
    SELECT 'BECAEd', 'Bachelor of Culture and Arts Education'
) AS p;

ALTER TABLE `participants`
  ADD COLUMN IF NOT EXISTS `no_middle_name` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = confirmed the person has no middle name' AFTER `middle_name`,
  ADD COLUMN IF NOT EXISTS `birth_date` date DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `address` varchar(500) DEFAULT NULL AFTER `birth_date`,
  ADD COLUMN IF NOT EXISTS `program_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `guardian_name` varchar(180) DEFAULT NULL AFTER `program_id`,
  ADD COLUMN IF NOT EXISTS `guardian_relationship` varchar(60) DEFAULT NULL AFTER `guardian_name`,
  ADD COLUMN IF NOT EXISTS `guardian_phone` varchar(50) DEFAULT NULL AFTER `guardian_relationship`;

ALTER TABLE `participants`
  ADD KEY IF NOT EXISTS `idx_participant_program` (`institution_id`, `program_id`);

ALTER TABLE `participants`
  ADD CONSTRAINT `participants_program_fk` FOREIGN KEY IF NOT EXISTS (`institution_id`, `program_id`) REFERENCES `programs` (`institution_id`, `id`);

COMMIT;

-- ===========================================================================
-- Part 8: migration 008 (what a collection includes). Existing installs
-- run database/migration_008_rule_inclusions.sql instead.
-- ===========================================================================
START TRANSACTION;

ALTER TABLE `collection_rules`
  ADD COLUMN IF NOT EXISTS `inclusions` text DEFAULT NULL AFTER `rule_name`;

COMMIT;
