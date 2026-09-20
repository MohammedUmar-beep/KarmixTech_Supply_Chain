Overview
Agile Inventory System is a lightweight, self-hosted inventory and business management application. It handles the full lifecycle of inventory — from procurement and stock tracking to sales, invoicing, and reporting — all through a clean, responsive web interface with dark/light theme support.

Built entirely with vanilla PHP, MySQL, and plain JavaScript (no heavy frameworks), it is easy to deploy on any standard LAMP/WAMP stack.

Features
🏠 Dashboard
At-a-glance KPI cards: today's revenue, orders, new customers, low-stock alerts, pending returns
Interactive charts (Chart.js) with switchable views: Products, Customers, Sales, Purchases, Shipments
Flexible date range filtering: Today, This Week, This Month, This Year, Custom range
Period-over-period percentage change badges
Recent activity feed.

Getting Started

Prerequisites
PHP 8.0 or higher
MySQL 8.0 or MariaDB 10.4+
A web server: Apache (with mod_rewrite) or Nginx
OR a local stack: XAMPP, WAMP, Laragon, or MAMP

Installation (PLEASE PREFER XAMPP AND PHPMYADMIN FOR A SMOOTH SET UP)
Clone or download the repository:

git clone https://github.com/your-username/agile-inventory-system.git
Or extract the downloaded ZIP into your web server's root directory (e.g., htdocs/ or www/).

Create the database:

Open phpMyAdmin (or your MySQL client) and run:

CREATE DATABASE `agile-inventory-system-5`;
Import the schema:

Import the provided SQL file to create all tables and seed default data:

mysql -u root -p agile-inventory-system-5 < database.sql
Or via phpMyAdmin: select the database → Import → choose database.sql.

Configure the database connection:

Open includes/db.php and update the credentials to match your environment:

$host     = "localhost";
$username = "root";       // your MySQL username
$password = "";           // your MySQL password
$dbname   = "agile-inventory-system-5";
Set up the uploads directory permissions (Linux/macOS):

Role	Email	Password
Admin	admin@example.com	admin123
Manager	manager@example.com	manager123
Staff	staff@example.com	staff123

----------------------------------
Project Structure
agile-inventory-system-5/
│
├── includes/                  # Shared PHP includes
│   ├── db.php                 # Database connection + global helpers
│   ├── auth_guard.php         # Session authentication guard
│   ├── header.php             # HTML <head> and page header
│   ├── footer.php             # HTML footer and scripts
│   ├── sidebar.php            # Navigation sidebar
│   ├── topbar_right.php       # Top bar (notifications, profile)
│   ├── loyalty_helper.php     # Loyalty points calculation helpers
│   ├── notify_helper.php      # Notification dispatch helpers
│   └── fpdf/                  # Bundled FPDF library for PDF generation
│
├── portal/                    # Customer self-service portal
│   ├── login.php
│   ├── dashboard.php
│   ├── orders.php
│   ├── invoices.php
│   ├── shipments.php
│   ├── returns.php
│   └── loyalty.php
│
├── assets/
│   ├── css/style.css          # Global stylesheet
│   ├── js/app.js              # Global JavaScript
│   └── images/                # Logo and screenshots
│
├── uploads/                   # User-uploaded files (product images, etc.)
│
├── api/v1/                    # API endpoint directory (extensible)
│
├── database.sql               # Full database schema + seed data
│
├── index.php                  # Landing / splash page
├── login.php                  # Login page
├── logout.php                 # Session destroy + redirect
├── dashboard.php              # Main dashboard
│
├── products.php               # Product list & management
├── add_product.php            # Add product form handler
├── edit_product.php           # Edit product form handler
├── categories.php             # Category management
├── warehouses.php             # Warehouse management
├── stock.php                  # Stock overview
├── stock_transfers.php        # Inter-warehouse stock transfers
├── serial_numbers.php         # Serialised product tracking
├── price_list.php             # Product price list
├── backorders.php             # Backorder management
│
├── pos.php                    # Point of Sale interface
├── sales_orders.php           # Sales order list & management
├── customers.php              # Customer management
├── invoices.php               # Invoice management
├── view_invoice.php           # Invoice detail / PDF preview
├── bundles.php                # Product bundle management
├── coupons.php                # Coupon management
├── loyalty_program.php        # Loyalty program settings
├── sales_returns.php          # Sales return management
├── credit_notes.php           # Credit note management
├── payments_received.php      # Incoming payments
│
├── suppliers.php              # Supplier management
├── purchase_orders.php        # Purchase order management
├── view_purchase_order.php    # PO detail view
├── shipment.php               # Shipment tracking
├── rfq.php                    # Request for Quotation
├── purchase_returns.php       # Purchase return management
├── supplier_credits.php       # Supplier credit management
├── payments_made.php          # Outgoing payments
├── generate_restock_pos.php   # Auto-generate restock POs
│
├── employees.php              # Employee management
│
├── reports.php                # Reports hub
├── report_analytics.php       # Analytics report
├── report_profit_loss.php     # Profit & Loss report
├── report_sales.php           # Sales report
├── report_purchases.php       # Purchase report
├── report_inventory.php       # Inventory report
├── report_customers.php       # Customer report
├── report_discounts.php       # Discount/coupon report
├── report_financials.php      # Financial report
│
├── settings.php               # Business settings + tax rules
├── notification_templates.php # Notification template editor
├── activity_logs.php          # Activity log viewer
├── audit_logs.php             # Audit log viewer
│
├── profile.php                # User profile page
├── notifications.php          # Notification centre
├── cron_send_notifications.php # Cron-based notification sender
│
├── export.php                 # CSV export handler
├── export_pdf.php             # PDF export handler
├── import_data.php            # CSV import handler
├── global_search_ajax.php     # AJAX global search
│
└── help.php                   # In-app knowledge base / help centre
