```markdown
# Agile Inventory System

![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)
![MySQL Version](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

A lightweight, self-hosted inventory and business management application. It handles the full lifecycle of inventory — from procurement and stock tracking to sales, invoicing, and reporting — all through a clean, responsive web interface with dark and light mode support.

Built entirely with vanilla **PHP**, **MySQL**, and plain **JavaScript** (no heavy frameworks), making it simple to deploy on any standard LAMP/WAMP environment.

---

## Features

### 🏠 Dashboard
* **At-a-Glance KPI Cards:** Real-time metrics for today's revenue, total orders, new customers, low-stock alerts, and pending returns.
* **Interactive Charts (Chart.js):** Switchable views for Products, Customers, Sales, Purchases, and Shipments.
* **Flexible Date Range Filtering:** Filter data by Today, This Week, This Month, This Year, or define a Custom Range.
* **Trend Indicators:** Period-over-period percentage change badges.
* **Recent Activity Feed:** Live log of the latest actions across the system.

---

## Prerequisites

Ensure your system meets the following requirements:

* **PHP:** Version `8.0` or higher
* **Database:** MySQL `8.0+` or MariaDB `10.4+`
* **Web Server:** Apache (with `mod_rewrite` enabled) or Nginx
* **Local Development Stack (Recommended):** [XAMPP](https://www.apachefriends.org/), WAMP, Laragon, or MAMP

---

## Installation & Setup

> **Recommendation:** Using **XAMPP** and **phpMyAdmin** is the easiest way to get up and running locally.

### 1. Clone or Download Repository
Place the project files inside your server's public directory (e.g., `C:/xampp/htdocs/` for XAMPP or `/var/www/html/` for Apache on Linux):

```bash
git clone [https://github.com/your-username/agile-inventory-system.git](https://github.com/your-username/agile-inventory-system.git)

```

*(Alternatively, extract the downloaded ZIP file into that folder).*

---

### 2. Create the Database

1. Open your browser and navigate to **phpMyAdmin** (usually `http://localhost/phpmyadmin`).
2. Click **New** in the left sidebar.
3. Name the database using backticks (due to hyphens):
```sql
CREATE DATABASE `agile-inventory-system-5`;

```



---

### 3. Import the Schema

Import the provided `database.sql` file to create the tables and seed initial data:

* **Via phpMyAdmin:**
1. Select the `agile-inventory-system-5` database.
2. Click the **Import** tab in the top navigation.
3. Click **Browse / Choose File**, select `database.sql`, and click **Import**.


* **Via Command Line:**
```bash
mysql -u root -p agile-inventory-system-5 < database.sql

```



---

### 4. Configure Database Connection (Not needed for this project as it is already setup in the files)

Open `includes/db.php` in a code editor and update the credentials to match your environment:

```php
$host     = "localhost";
$username = "root";                    // Your MySQL username
$password = "";                        // Your MySQL password (default is empty in XAMPP)
$dbname   = "agile-inventory-system-5";

```

---


## Default Credentials

The initial database seed includes demo accounts for testing different user roles:

| Role | Email | Password |
| --- | --- | --- |
| **Admin** | `admin@example.com` | `admin123` |
| **Manager** | `manager@example.com` | `manager123` |
| **Staff** | `staff@example.com` | `staff123` |

> **Security Note:** Change these default passwords immediately after initial setup before using in any live environment.

---

## License

Distributed under the MIT License. See `LICENSE` for more information.

```

```
