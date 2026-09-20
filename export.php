<?php
require_once 'includes/auth_guard.php';

$module = $_GET['module'] ?? '';
$format = $_GET['format'] ?? 'csv';

// Intercept PDF Receipt/Invoice requests
if ($module === 'receipt') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    header("Location: export_pdf.php?type=receipt&id=" . $id);
    exit();
}

// Map valid modules to their database tables
$valid_modules = [
    'products' => 'products',
    'categories' => 'categories',
    'warehouses' => 'warehouses',
    'stock' => 'stock',
    'employees' => 'employees',
    'customers' => 'customers',
    'suppliers' => 'suppliers',
    'sales_orders' => 'sales_orders',
    'invoices' => 'invoices',
    'payments_received' => 'payments_received',
    'sales_returns' => 'sales_returns',
    'credit_notes' => 'credit_notes',
    'purchase_orders' => 'purchase_orders',
    'payments_made' => 'payments_made',
    'purchase_returns' => 'purchase_returns',
    'supplier_credits' => 'supplier_credits',
    'shipment' => 'shipment'
];

// Custom Report Intercepts
$custom_reports = [
    'report_sales' => [
        'filename' => "Export_Sales_Report_" . date('Y-m-d_H-i') . ".csv",
        'query' => "
            SELECT p.sku_code as 'SKU', p.product_name as 'Product Name', SUM(soi.quantity) as 'Total Quantity Sold', SUM(soi.subtotal) as 'Gross Revenue'
            FROM products p
            JOIN sales_order_items soi ON p.id = soi.product_id
            JOIN sales_orders so ON soi.sales_order_id = so.id
            WHERE so.status = 'Completed'
            GROUP BY p.id
            ORDER BY SUM(soi.subtotal) DESC
        "
    ],
    'report_inventory' => [
        'filename' => "Export_Inventory_Summary_" . date('Y-m-d_H-i') . ".csv",
        'query' => "
            SELECT sku_code as 'SKU', product_name as 'Product Name', stock_level as 'Stock Level', purchasing_price as 'Unit Cost', selling_price as 'Unit Retail', (stock_level * purchasing_price) as 'Total Asset Value'
            FROM products
            WHERE stock_level > 0
            ORDER BY (stock_level * purchasing_price) DESC
        "
    ],
    'report_purchases' => [
        'filename' => "Export_Purchase_Orders_" . date('Y-m-d_H-i') . ".csv",
        'query' => "
            SELECT po.order_date as 'Date', po.purchase_id_str as 'Order ID', s.supplier_name as 'Supplier', po.status as 'Status', po.total_amount as 'Total Amount'
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            ORDER BY po.order_date DESC
        "
    ],
    'report_customers' => [
        'filename' => "Export_Customer_Balances_" . date('Y-m-d_H-i') . ".csv",
        'query' => "
            SELECT 
                c.customer_name as 'Customer Name', c.email as 'Email', c.contact_number as 'Contact Number',
                COALESCE(SUM(i.amount), 0) as 'Total Invoiced',
                COALESCE((SELECT SUM(pr.amount) FROM payments_received pr JOIN invoices inv ON pr.invoice_id = inv.id WHERE inv.customer_id = c.id), 0) as 'Total Paid',
                (COALESCE(SUM(i.amount), 0) - COALESCE((SELECT SUM(pr.amount) FROM payments_received pr JOIN invoices inv ON pr.invoice_id = inv.id WHERE inv.customer_id = c.id), 0)) as 'Outstanding Balance'
            FROM customers c
            LEFT JOIN invoices i ON c.id = i.customer_id AND i.status != 'Draft'
            GROUP BY c.id
            ORDER BY 6 DESC
        "
    ]
];

if (array_key_exists($module, $custom_reports)) {
    $report = $custom_reports[$module];
    $result = $conn->query($report['query']);
    if (!$result)
        die("Error generating export: " . $conn->error);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report['filename'] . '"');
    $output = fopen('php://output', 'w');
    if ($result->num_rows > 0) {
        $first = true;
        while ($row = $result->fetch_assoc()) {
            if ($first) {
                fputcsv($output, array_keys($row));
                $first = false;
            }
            fputcsv($output, array_values($row));
        }
    } else {
        fputcsv($output, ['No records found.']);
    }
    fclose($output);
    exit();
}

// Intercept for Analytics Export
if ($module === 'analytics') {
    $days_back = isset($_GET['days']) ? intval($_GET['days']) : 30;
    $date_limit = date('Y-m-d H:i:s', strtotime("-$days_back days"));
    $filename = "Export_DeadStock_Last_{$days_back}_Days_" . date('Y-m-d_H-i') . ".csv";

    $query = "
        SELECT 
            p.sku_code as 'SKU',
            p.product_name as 'Product Name', 
            p.stock_level as 'Current Stock', 
            p.price as 'Unit Price',
            (p.stock_level * p.price) as 'Trapped Capital'
        FROM products p
        WHERE p.stock_level > 0 
        AND p.id NOT IN (
            SELECT DISTINCT soi.product_id 
            FROM sales_order_items soi 
            JOIN sales_orders so ON soi.sales_order_id = so.id 
            WHERE so.order_date >= '$date_limit' AND so.status != 'Cancelled'
        )
        ORDER BY Trapped Capital DESC
    ";
    $result = $conn->query($query);
    if (!$result)
        die("Error generating analytics export.");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    if ($result->num_rows > 0) {
        $first = true;
        while ($row = $result->fetch_assoc()) {
            if ($first) {
                fputcsv($output, array_keys($row));
                $first = false;
            }
            fputcsv($output, array_values($row));
        }
    } else {
        fputcsv($output, ['No dead stock found.']);
    }
    fclose($output);
    exit();
}

if (!array_key_exists($module, $valid_modules)) {
    die("Error: Invalid module specified for export.");
}

$table_name = $valid_modules[$module];
$filename = "Export_{$module}_" . date('Y-m-d_H-i') . "." . $format;

// Fetch data
$query = "SELECT * FROM `$table_name` ORDER BY id DESC";
$result = $conn->query($query);

if (!$result) {
    die("Error fetching data: " . $conn->error);
}

if ($format === 'csv' || $format === 'txt') {
    header('Content-Type: text/' . ($format === 'csv' ? 'csv' : 'plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    $delimiter = ($format === 'csv') ? ',' : "\t";
    if ($result->num_rows > 0) {
        $first_row = true;
        while ($row = $result->fetch_assoc()) {
            if ($first_row) { fputcsv($output, array_keys($row), $delimiter); $first_row = false; }
            fputcsv($output, array_values($row), $delimiter);
        }
    } else {
        fputcsv($output, ['No records found.'], $delimiter);
    }
    fclose($output);
    exit();
} elseif ($format === 'excel') {
    // Excel XML Spreadsheet format (.xls) — opens natively in Excel, no library needed
    $excel_filename = "Export_{$module}_" . date('Y-m-d_H-i') . ".xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $excel_filename . '"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Worksheet ss:Name="' . htmlspecialchars(ucfirst($module)) . '">' . "\n";
    echo '<Table>' . "\n";

    if ($result->num_rows > 0) {
        $first_row = true;
        while ($row = $result->fetch_assoc()) {
            if ($first_row) {
                // Header row — bold
                echo '<Row>' . "\n";
                foreach (array_keys($row) as $col) {
                    $col_label = ucwords(str_replace('_', ' ', $col));
                    echo '<Cell><Data ss:Type="String"><![CDATA[' . $col_label . ']]></Data></Cell>' . "\n";
                }
                echo '</Row>' . "\n";
                $first_row = false;
            }
            echo '<Row>' . "\n";
            foreach ($row as $val) {
                $type = is_numeric($val) ? 'Number' : 'String';
                $display = ($val === null) ? '' : $val;
                echo '<Cell><Data ss:Type="' . $type . '"><![CDATA[' . $display . ']]></Data></Cell>' . "\n";
            }
            echo '</Row>' . "\n";
        }
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    exit();
} elseif ($format === 'pdf') {
    require_once 'includes/fpdf/fpdf.php';

    $pdf = new FPDF('L', 'mm', 'A4'); // Landscape for wide tables
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 10, ucfirst(str_replace('_', ' ', $module)) . ' — Export ' . date('d M Y'), 0, 1, 'C');
    $pdf->Ln(2);

    if ($result->num_rows > 0) {
        // Collect all rows first
        $all_rows = [];
        $headers  = [];
        while ($row = $result->fetch_assoc()) {
            if (empty($headers)) {
                $headers = array_keys($row);
            }
            $all_rows[] = $row;
        }

        $col_count  = count($headers);
        $page_width = 277; // A4 landscape usable width in mm
        $col_width  = max(20, floor($page_width / $col_count));

        // Header row
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(245, 245, 250);
        foreach ($headers as $h) {
            $label = ucwords(str_replace('_', ' ', $h));
            $pdf->Cell($col_width, 8, substr($label, 0, 18), 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Data rows
        $pdf->SetFont('Helvetica', '', 7);
        $fill = false;
        foreach ($all_rows as $row) {
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 255 : 255);
            foreach ($row as $val) {
                $display = ($val === null) ? '' : substr((string)$val, 0, 22);
                $pdf->Cell($col_width, 7, $display, 1, 0, 'L', true);
            }
            $pdf->Ln();
            $fill = !$fill;
        }
    } else {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 10, 'No records found.', 0, 1, 'C');
    }

    $pdf_filename = "Export_{$module}_" . date('Y-m-d_H-i') . ".pdf";
    $pdf->Output('D', $pdf_filename);
    exit();
} else {
    die("Requested format not supported.");
}
?>