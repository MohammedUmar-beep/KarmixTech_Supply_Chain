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
