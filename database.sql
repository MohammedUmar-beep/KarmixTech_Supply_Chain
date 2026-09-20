-- ============================================================
-- AGILE INVENTORY SYSTEM — Full Database Schema
-- Single source of truth: core tables + all feature tables
-- Run once on a fresh database to get the complete schema.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `agile-inventory-system-5`;
USE `agile-inventory-system-5`;

-- ────────────────────────────────────────────────────────────
-- CORE TABLES
-- ────────────────────────────────────────────────────────────

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_name` varchar(255) NOT NULL,
  `warehouse_id_str` varchar(100) NOT NULL,
  `contact_number` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `state` varchar(100) NOT NULL,
  `pincode` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `capacity` int(11) NOT NULL,
  `status` enum('Active','Inactive','Deleted') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouse_id_str` (`warehouse_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id_str` varchar(50) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `tax_rate` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id_str` (`category_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id_str` varchar(50) NOT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_id_str` (`supplier_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','manager','staff') NOT NULL DEFAULT 'staff',
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin User',   'admin@example.com',   'admin123',   'admin',   '2026-03-07 08:58:14'),
(2, 'Manager User', 'manager@example.com', 'manager123', 'manager', '2026-03-07 08:58:14'),
(3, 'Staff User',   'staff@example.com',   'staff123',   'staff',   '2026-03-07 08:58:14');

CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `employee_id_str` varchar(50) NOT NULL,
  `warehouse_id` varchar(50) DEFAULT NULL,
  `department_id` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `government_id_type` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `status` enum('On Duty','On Break','Off Duty','Inactive','Deleted') DEFAULT 'On Duty',
  `document_paths` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id_str` (`employee_id_str`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTE: `document_paths` and `profile_picture` are included in the CREATE TABLE above.
-- If upgrading an older database that predates both columns, run:
-- ALTER TABLE `employees` ADD COLUMN `document_paths` TEXT DEFAULT NULL AFTER `status`;
-- ALTER TABLE `employees` ADD COLUMN `profile_picture` VARCHAR(255) DEFAULT NULL AFTER `document_paths`;

-- customers: includes loyalty_points_balance (Phase 2 Loyalty)
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id_str` varchar(50) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `loyalty_points_balance` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_id_str` (`customer_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- products: includes is_serialised (Phase 1) and discount columns
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(255) NOT NULL,
  `product_id_str` varchar(100) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `supplier_id` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL,
  `sku_code` varchar(100) NOT NULL,
  `barcode_number` varchar(100) NOT NULL,
  `weight` decimal(10,2) NOT NULL,
  `stock_level` int(11) NOT NULL DEFAULT 0,
  `warning_threshold` int(11) NOT NULL DEFAULT 10,
  `purchasing_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `selling_price_margin` varchar(50) NOT NULL,
  `dimensions` varchar(100) NOT NULL,
  `dimension_unit` varchar(20) NOT NULL,
  `grn_number` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('Available','Out of Stock','Deleted') NOT NULL DEFAULT 'Available',
  `discount_type` enum('percentage','fixed') DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT NULL,
  `discounted_price` decimal(10,2) DEFAULT NULL,
  `is_serialised` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_str` (`product_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `applies_to` varchar(100) DEFAULT 'All Products',
  `min_order_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `times_used` int(11) DEFAULT 0,
  `status` varchar(20) DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tax_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_name` varchar(50) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `discount_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discount_code` varchar(50) NOT NULL,
  `discount_type` enum('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_order_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `discount_code` (`discount_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `business_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `business_settings` (`setting_key`, `setting_value`) VALUES
('company_name',    'Agile Inventory, Inc.'),
('company_address', '123 Business Avenue'),
('company_city',    'Tech City'),
('company_state',   'Maharashtra'),
('company_pincode', '400001'),
('company_country', 'India'),
('company_phone',   '+91 98765 43210'),
('company_email',   'admin@agileinventory.com'),
('company_website', 'www.agileinventory.com'),
('company_tax_id',  '00XXXXXX1234X0XX'),
('invoice_notes',   'Please pay within 15 days of receiving this invoice. Thank you for your business!');

-- system_settings: stores runtime settings like currency
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('currency_symbol', '₹'),
('currency_code',   'INR');

-- tax_rules: default GST
INSERT IGNORE INTO `tax_rules` (`tax_name`, `rate_percent`, `is_active`) VALUES
('GST 18%', 18.00, 1);

-- purchase_orders: includes parent_po_id and is_backorder (Phase 2 Backorder)
CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id_str` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Draft','Issued','Received','Cancelled') DEFAULT 'Draft',
  `parent_po_id` int(11) DEFAULT NULL,
  `is_backorder` tinyint(1) NOT NULL DEFAULT 0,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_id_str` (`purchase_id_str`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- purchase_order_items: includes qty_received and qty_pending (Phase 2 Backorder)
CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  `qty_pending` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number_str` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number_str` (`return_number_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `purchase_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shipment_number_str` varchar(50) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `carrier` varchar(100) NOT NULL,
  `tracking_number` varchar(100) NOT NULL,
  `status` varchar(50) NOT NULL,
  `shipment_date` date NOT NULL,
  `expected_delivery` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_number_str` (`shipment_number_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `supplier_credits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `credit_note_str` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `date_issued` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_note_str` (`credit_note_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sales_orders: includes points_earned, points_redeemed, loyalty_discount (Phase 2 Loyalty)
CREATE TABLE `sales_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id_str` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('Pending','Completed','Cancelled') DEFAULT 'Pending',
  `fulfillment_type` enum('Delivery','Take Away') DEFAULT 'Take Away',
  `delivery_charge` decimal(10,2) DEFAULT 0.00,
  `shipping_address` text DEFAULT NULL,
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `loyalty_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id_str` (`order_id_str`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sales_order_items: includes bundle_sale_id (Bundles) and serial_id (Phase 1)
CREATE TABLE `sales_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `bundle_sale_id` int(11) DEFAULT NULL,
  `serial_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_order_id` (`sales_order_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `sales_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id_str` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_order_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `total_refund_amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_id_str` (`return_id_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `credit_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `credit_note_str` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_return_id` int(11) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `credit_amount` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_note_str` (`credit_note_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_order_id` int(11) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('Draft','Sent','Partial','Paid','Overdue') DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `invoices_ibfk_1` (`customer_id`),
  KEY `invoices_ibfk_2` (`sales_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payments_received` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_ref_str` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','Credit Card','Cheque') DEFAULT 'Bank Transfer',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_ref_str` (`payment_ref_str`),
  KEY `payments_received_ibfk_1` (`customer_id`),
  KEY `payments_received_ibfk_2` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payments_made` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_ref_str` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('Fully Paid','Partially Paid','On Credit') DEFAULT 'Fully Paid',
  `payment_method` enum('Cash','Bank Transfer','Credit Card','Cheque') DEFAULT 'Bank Transfer',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_ref_str` (`payment_ref_str`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_made_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payment_installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_made_id` int(11) DEFAULT NULL,
  `payment_received_id` int(11) DEFAULT NULL,
  `installment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payment_made_id` (`payment_made_id`),
  KEY `payment_received_id` (`payment_received_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_ref` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `transfer_date` date NOT NULL,
  `creator_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_ref` (`transfer_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- BUNDLE SYSTEM
-- ────────────────────────────────────────────────────────────

CREATE TABLE `bundles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id_str` varchar(50) NOT NULL,
  `bundle_name` varchar(255) NOT NULL,
  `sku_code` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percentage','fixed','custom') NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bundle_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `normal_sell_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `profit_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `profit_margin_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `image_path` varchar(255) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('Active','Inactive','Expired') NOT NULL DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `bundle_id_str` (`bundle_id_str`),
  UNIQUE KEY `sku_code` (`sku_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bundle_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_cost_snapshot` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_sell_snapshot` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_sell` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `bundle_id` (`bundle_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bundle_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_order_id` int(11) NOT NULL,
  `bundle_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `bundle_price_at_sale` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `cost_at_sale` decimal(10,2) NOT NULL DEFAULT 0.00,
  `profit_at_sale` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sales_order_id` (`sales_order_id`),
  KEY `bundle_id` (`bundle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- SERIAL NUMBER TRACKING
-- ────────────────────────────────────────────────────────────

CREATE TABLE `product_serials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `status` enum('Available','Sold','Returned','Damaged','Reserved') NOT NULL DEFAULT 'Available',
  `warehouse_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `serial_number` (`serial_number`),
  KEY `product_id` (`product_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `serial_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `serial_id` int(11) NOT NULL,
  `movement_type` enum('Purchase','Sale','Return','Transfer','Adjustment') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) DEFAULT NULL,
  `moved_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `serial_id` (`serial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- AUTOMATED NOTIFICATIONS
-- ────────────────────────────────────────────────────────────

CREATE TABLE `notification_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(100) NOT NULL,
  `label` varchar(150) NOT NULL,
  `channel` enum('email','sms','both') NOT NULL DEFAULT 'email',
  `subject` varchar(255) DEFAULT NULL,
  `body_html` text DEFAULT NULL,
  `body_sms` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_name` (`event_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `notification_templates` (`event_name`,`label`,`channel`,`subject`,`body_html`,`body_sms`) VALUES
('order_confirmed',       'Order Confirmed',       'both',  'Your order {order_id} is confirmed',           '<p>Hi {customer_name},<br>Your order <strong>{order_id}</strong> has been confirmed. Total: {total}.</p>',                          'Hi {customer_name}, your order {order_id} for {total} is confirmed.'),
('invoice_due',           'Invoice Due Reminder',  'email', 'Invoice {invoice_number} is due on {due_date}','<p>Hi {customer_name},<br>Invoice <strong>{invoice_number}</strong> of {amount} is due on {due_date}.</p>',                        NULL),
('shipment_dispatched',   'Shipment Dispatched',   'both',  'Your order {order_id} has been dispatched',    '<p>Hi {customer_name},<br>Your shipment is on its way! Tracking: {tracking_number}.</p>',                                          'Hi {customer_name}, your order {order_id} is dispatched. Tracking: {tracking_number}.'),
('low_stock_alert',       'Low Stock Alert',       'email', 'Stock alert: {product_name} is running low',  '<p>Hi Admin,<br><strong>{product_name}</strong> has only {stock_level} units left (threshold: {threshold}).</p>',                   NULL),
('sales_return_approved', 'Sales Return Approved', 'email', 'Your return for {order_id} has been approved','<p>Hi {customer_name},<br>Your return request for order {order_id} has been approved. Refund: {refund_amount}.</p>',               NULL);

CREATE TABLE `notification_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `recipient_phone` varchar(30) DEFAULT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `payload_json` text DEFAULT NULL,
  `status` enum('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notification_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_id` int(11) NOT NULL,
  `channel` varchar(20) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `response_code` varchar(20) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `queue_id` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- RFQ (REQUEST FOR QUOTATION)
-- ────────────────────────────────────────────────────────────

CREATE TABLE `rfq_headers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfq_number_str` varchar(50) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `deadline_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Draft','Sent','Responses Received','Converted','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfq_number_str` (`rfq_number_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `rfq_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfq_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_needed` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `rfq_id` (`rfq_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `rfq_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `validity_date` date DEFAULT NULL,
  `status` enum('Pending','Responded','Accepted','Rejected') NOT NULL DEFAULT 'Pending',
  `response_token` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rfq_id` (`rfq_id`),
  UNIQUE KEY `rfq_supplier` (`rfq_id`,`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `rfq_response_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `response_id` int(11) NOT NULL,
  `rfq_item_id` int(11) NOT NULL,
  `quoted_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `lead_days` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `response_id` (`response_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- LOYALTY POINTS
-- ────────────────────────────────────────────────────────────

CREATE TABLE `loyalty_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `points_per_unit` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'Points earned per 1 currency unit spent',
  `redemption_rate` decimal(10,4) NOT NULL DEFAULT 0.0500 COMMENT 'Currency value of 1 point',
  `min_points_redeem` int(11) NOT NULL DEFAULT 100,
  `max_redemption_pct` decimal(5,2) NOT NULL DEFAULT 25.00 COMMENT 'Max % of order value redeemable',
  `points_expiry_days` int(11) DEFAULT 365,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `loyalty_config` (`id`,`points_per_unit`,`redemption_rate`,`min_points_redeem`,`max_redemption_pct`,`points_expiry_days`,`is_active`)
VALUES (1, 1.0000, 0.0500, 100, 25.00, 365, 1);

CREATE TABLE `loyalty_points_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `transaction_type` enum('Earned','Redeemed','Expired','Adjusted','Reversed') NOT NULL,
  `points` int(11) NOT NULL,
  `balance_after` int(11) NOT NULL DEFAULT 0,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `transaction_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- CUSTOMER PORTAL
-- ────────────────────────────────────────────────────────────

CREATE TABLE `customer_portal_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` timestamp NULL DEFAULT NULL,
  `invited_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- RETURN LINE ITEMS
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `sales_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_returned` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_return_id` (`sales_return_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `purchase_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_returned` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_return_id` (`purchase_return_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `purchase_returns`
  ADD COLUMN IF NOT EXISTS `reason` text DEFAULT NULL AFTER `status`;
