-- ===========================================================================
-- ANINAW migration 003: products, inventory, orders and printing services
-- ===========================================================================
-- Adds the Product and Services module. Run this ONCE on a database that has
-- schema.sql (+ migration 002). A fresh install of schema.sql already contains
-- these statements (Part 4). Safe to run more than once: tables are created
-- only if missing and seed rows are matched by their codes.
--
-- Design notes
-- * Every table carries institution_id, and every reference to another record
--   is a composite foreign key (institution_id, id), so a row can never point
--   at another institution's product, order, payment or member.
-- * Customers are participants (students, employees, walk-ins as type 'other');
--   payments reuse `payments` + `receipts`; sales_order_payments links a payment
--   to the order it settles (payment_allocations only accepts event obligations).
-- * Order lines store a snapshot of the price, cost and fulfilment rule, so
--   later price changes never alter existing orders.
-- * Stock on hand = SUM(inventory_movements.quantity) per variant. Supplier or
--   production orders (stock_batches) are NOT stock until received.
--
-- Money: DECIMAL(12,2). Quantities: whole units (INT).

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
