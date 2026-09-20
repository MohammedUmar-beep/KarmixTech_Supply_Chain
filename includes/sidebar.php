<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';

// Role based access checks for rendering
$show_overview = true; // Everyone sees dashboard overview? Or maybe just simple welcome for staff. Admin/manager sees full dashboard.
$show_inventory = in_array($role, ['admin', 'manager', 'staff']); // Staff gets "stock", which is inventory
$show_sales = in_array($role, ['admin', 'manager', 'staff']);
$show_customers = in_array($role, ['admin', 'manager', 'staff']); // user request: staff also access customers tab
$show_purchase = in_array($role, ['admin', 'manager']);
$show_employees = in_array($role, ['admin']); // manager controls everything except employee tab
$show_reports = in_array($role, ['admin', 'manager']);
?>
<div class="sidebar">
    <div class="sidebar-logo">
        <img src="assets/images/logo.png" alt="Agile Inventory" class="sidebar-logo-img">
        <div class="sidebar-logo-text">
            <span class="sidebar-logo-name">Agile Inventory</span>
            <span class="sidebar-logo-sub">System</span>
        </div>
    </div>

    <!-- Sidebar Search feature removed via user request to avoid confusion with module-level global search -->

    <ul class="nav-links">
        <?php if ($show_overview): ?>
            <li class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <a href="dashboard.php">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    Overview
                </a>
            </li>
        <?php endif; ?>
        <?php if ($show_inventory): ?>
            <li
                class="has-submenu
 <?= in_array($current_page, ['warehouses.php', 'products.php', 'categories.php', 'price_list.php', 'stock.php', 'serial_numbers.php']) ? 'active open' : '' ?>">
                <a href="#" class="submenu-toggle">
                    <svg viewBox="0 0 24 24">
                        <path
                            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                        </path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    Inventory <span class="dropdown-icon">▼</span>
                </a>
                <ul class="submenu">
                    <li class="<?= $current_page == 'warehouses.php' ? 'active' : '' ?>">
                        <a href="warehouses.php">
                            <svg viewBox="0 0 24 24">
                                <path
                                    d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                                </path>
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                <line x1="12" y1="22.08" x2="12" y2="12"></line>
                            </svg>
                            Warehouse <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('warehouses.php')){ document.getElementById('addWarehouseModal').classList.add('active'); } else { window.location.href='warehouses.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= $current_page == 'products.php' ? 'active' : '' ?>">
                        <a href="products.php">
                            <svg viewBox="0 0 24 24">
                                <polyline points="9 11 12 14 22 4"></polyline>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                            </svg>
                            Products <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('products.php')){ document.getElementById('addProductModal').classList.add('active'); } else { window.location.href='products.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= $current_page == 'categories.php' ? 'active' : '' ?>">
                        <a href="categories.php">
                            <svg viewBox="0 0 24 24">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                            Category <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('categories.php')){ document.getElementById('addCategoryModal').classList.add('active'); } else { window.location.href='categories.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= $current_page == 'price_list.php' ? 'active' : '' ?>">
                        <a href="price_list.php">
                            <svg viewBox="0 0 24 24">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                            Price List
                        </a>
                    </li>
                    <li class="<?= $current_page == 'stock.php' ? 'active' : '' ?>">
                        <a href="stock.php">
                            <svg viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                <path d="M11 8v6"></path>
                                <path d="M8 11h6"></path>
                            </svg>
                            Stock
                        </a>
                    </li>
                    <?php if (in_array($role, ['admin','manager'])): ?>
                    <li class="<?= $current_page == 'serial_numbers.php' ? 'active' : '' ?>">
                        <a href="serial_numbers.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                                <line x1="8" y1="14" x2="8" y2="14"/>
                                <line x1="12" y1="14" x2="16" y2="14"/>
                                <line x1="8" y1="18" x2="8" y2="18"/>
                                <line x1="12" y1="18" x2="16" y2="18"/>
                            </svg>
                            Serial Numbers
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($show_sales): ?>
            <li
                class="has-submenu <?= in_array($current_page, ['sales_orders.php', 'payments_received.php', 'sales_returns.php', 'credit_notes.php', 'invoices.php', 'coupons.php', 'bundles.php', 'add_bundle.php', 'edit_bundle.php', 'view_bundle.php', 'loyalty_program.php']) ? 'active open' : '' ?>">
                <a href="#" class="submenu-toggle">
                    <svg viewBox="0 0 24 24">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                    Sales <span class="dropdown-icon">▼</span>
                </a>
                <ul class="submenu">
                    <li class="<?= $current_page == 'pos.php' ? 'active' : '' ?>">
                        <a href="pos.php">
                            <svg viewBox="0 0 24 24">
                                <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                                <rect x="9" y="9" width="6" height="6"></rect>
                                <line x1="9" y1="1" x2="9" y2="4"></line>
                                <line x1="15" y1="1" x2="15" y2="4"></line>
                                <line x1="9" y1="20" x2="9" y2="23"></line>
                                <line x1="15" y1="20" x2="15" y2="23"></line>
                                <line x1="20" y1="9" x2="23" y2="9"></line>
                                <line x1="20" y1="14" x2="23" y2="14"></line>
                                <line x1="1" y1="9" x2="4" y2="9"></line>
                                <line x1="1" y1="14" x2="4" y2="14"></line>
                            </svg>
                            Point of Sale (POS)
                        </a>
                    </li>
                    <li class="<?= $current_page == 'customers.php' ? 'active' : '' ?>">
                        <a href="customers.php">
                            <svg viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            Customers <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('customers.php')){ document.getElementById('addCustomerModal').classList.add('active'); } else { window.location.href='customers.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= in_array($current_page, ['sales_orders.php', 'payments_received.php', 'sales_returns.php', 'credit_notes.php']) ? 'active' : '' ?>">
                        <a href="sales_orders.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 16 16 12 12 8"></polyline>
                                <line x1="8" y1="12" x2="16" y2="12"></line>
                            </svg>
                            Sales Hub
                        </a>
                    </li>
                    <li class="<?= $current_page == 'invoices.php' ? 'active' : '' ?>">
                        <a href="invoices.php">
                            <svg viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            Invoices <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('invoices.php')){ document.getElementById('addInvoiceModal').classList.add('active'); } else { window.location.href='invoices.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <?php if (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'manager'])): ?>
                        <li class="<?= in_array($current_page, ['bundles.php','add_bundle.php','edit_bundle.php','view_bundle.php']) ? 'active' : '' ?>">
                            <a href="bundles.php">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="7" width="20" height="14" rx="2"/>
                                    <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                                    <line x1="12" y1="12" x2="12" y2="16"/>
                                    <line x1="10" y1="14" x2="14" y2="14"/>
                                </svg>
                                Bundles
                            </a>
                        </li>
                        <li class="<?= $current_page == 'coupons.php' ? 'active' : '' ?>">
                            <a href="coupons.php">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="9" cy="21" r="1"></circle>
                                    <circle cx="20" cy="21" r="1"></circle>
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                                    <path d="M10 10l6-6"></path>
                                </svg>
                                Coupons
                            </a>
                        </li>
                        <li class="<?= $current_page == 'loyalty_program.php' ? 'active' : '' ?>">
                            <a href="loyalty_program.php">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                                Loyalty
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($show_purchase): ?>
            <li
                class="has-submenu <?= in_array($current_page, ['purchase_orders.php', 'payments_made.php', 'purchase_returns.php', 'supplier_credits.php', 'suppliers.php', 'rfq.php']) ? 'active open' : '' ?>">
                <a href="#" class="submenu-toggle">
                    <svg viewBox="0 0 24 24">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                    Purchases <span class="dropdown-icon">▼</span>
                </a>
                <ul class="submenu">
                    <li class="<?= $current_page == 'suppliers.php' ? 'active' : '' ?>">
                        <a href="suppliers.php">
                            <svg viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            Suppliers <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('suppliers.php')){ document.getElementById('addSupplierModal').classList.add('active'); } else { window.location.href='suppliers.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= in_array($current_page, ['purchase_orders.php', 'payments_made.php', 'purchase_returns.php', 'supplier_credits.php']) ? 'active' : '' ?>">
                        <a href="purchase_orders.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="8 12 12 16 16 12"></polyline>
                                <line x1="12" y1="8" x2="12" y2="16"></line>
                            </svg>
                            Purchase Hub
                        </a>
                    </li>
                    <li class="<?= $current_page == 'shipment.php' ? 'active' : '' ?>">
                        <a href="shipment.php">
                            <svg viewBox="0 0 24 24">
                                <rect x="1" y="3" width="15" height="13"></rect>
                                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                <circle cx="18.5" cy="18.5" r="2.5"></circle>
                            </svg>
                            Shipments <span class="plus-icon"
                                onclick="event.preventDefault(); event.stopPropagation(); if(window.location.pathname.endsWith('shipment.php')){ document.getElementById('addShipmentModal').classList.add('active'); } else { window.location.href='shipment.php?add=true'; }">+</span>
                        </a>
                    </li>
                    <li class="<?= $current_page == 'rfq.php' ? 'active' : '' ?>">
                        <a href="rfq.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" y1="13" x2="8" y2="13"/>
                                <line x1="16" y1="17" x2="8" y2="17"/>
                                <line x1="10" y1="9" x2="8" y2="9"/>
                            </svg>
                            RFQ
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($show_employees): ?>
            <li class="<?= $current_page == 'employees.php' ? 'active' : '' ?>">
                <a href="employees.php">
                    <svg viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    Employees
                </a>
            </li>
        <?php endif; ?>

        <?php if ($show_reports): ?>
            <li class="has-submenu <?= in_array($current_page, ['reports.php','report_profit_loss.php','notification_templates.php']) ? 'active open' : '' ?>">
                <a href="#" class="submenu-toggle">
                    <svg viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    Reports <span class="dropdown-icon">▼</span>
                </a>
                <ul class="submenu">
                    <li class="<?= $current_page == 'reports.php' ? 'active' : '' ?>">
                        <a href="reports.php">
                            <svg viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                            All Reports
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_profit_loss.php' ? 'active' : '' ?>">
                        <a href="report_profit_loss.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"/>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                            </svg>
                            P&amp;L / Balance Sheet
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_analytics.php' ? 'active' : '' ?>">
                        <a href="report_analytics.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                            </svg>
                            Analytics
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_sales.php' ? 'active' : '' ?>">
                        <a href="report_sales.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                            Sales Report
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_purchases.php' ? 'active' : '' ?>">
                        <a href="report_purchases.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                            </svg>
                            Purchases Report
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_inventory.php' ? 'active' : '' ?>">
                        <a href="report_inventory.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            </svg>
                            Inventory Report
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_customers.php' ? 'active' : '' ?>">
                        <a href="report_customers.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            Customers Report
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_discounts.php' ? 'active' : '' ?>">
                        <a href="report_discounts.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="19" y1="5" x2="5" y2="19"></line>
                                <circle cx="6.5" cy="6.5" r="2.5"></circle>
                                <circle cx="17.5" cy="17.5" r="2.5"></circle>
                            </svg>
                            Discounts Report
                        </a>
                    </li>
                    <li class="<?= $current_page == 'report_financials.php' ? 'active' : '' ?>">
                        <a href="report_financials.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                <line x1="1" y1="10" x2="23" y2="10"></line>
                            </svg>
                            Financials Report
                        </a>
                    </li>
                    <?php if ($role === 'admin'): ?>
                    <li class="<?= $current_page == 'notification_templates.php' ? 'active' : '' ?>">
                        <a href="notification_templates.php">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                            Notifications
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <li class="<?= $current_page == 'activity_logs.php' ? 'active' : '' ?>">
                <a href="activity_logs.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                    </svg>
                    Activity Logs
                </a>
            </li>
        <?php endif; ?>

        <li class="<?= $current_page == 'help.php' ? 'active' : '' ?>">
            <a href="help.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                Help
            </a>
        </li>
    </ul>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleBtns = document.querySelectorAll('.submenu-toggle');
        toggleBtns.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const parentLi = this.parentElement;
                parentLi.classList.toggle('open');

                const icon = this.querySelector('.dropdown-icon');
                if (parentLi.classList.contains('open')) {
                    icon.textContent = '▲';
                } else {
                    icon.textContent = '▼';
                }
            });
        });
    });
</script>