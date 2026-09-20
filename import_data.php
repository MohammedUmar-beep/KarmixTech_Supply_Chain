<?php
require_once 'includes/auth_guard.php';

$user_role = strtolower($_SESSION['role'] ?? 'staff');
if ($user_role !== 'admin' && $user_role !== 'manager') {
    die("Unauthorized access to data import module.");
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $import_type = $_POST['import_type'] ?? '';

    // File validation
    $file = $_FILES['csv_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "Upload failed with error code: " . $file['error'];
        $messageType = "error";
    } elseif (strtolower($ext) !== 'csv') {
        $message = "Invalid file format. Please upload a .CSV file.";
        $messageType = "error";
    } elseif ($import_type !== 'products' && $import_type !== 'customers') {
        $message = "Invalid import destination selected.";
        $messageType = "error";
    } else {
        $handle = fopen($file['tmp_name'], "r");
        if ($handle === FALSE) {
            $message = "Could not open the uploaded file.";
            $messageType = "error";
        } else {
            // Read headers
            $headers = fgetcsv($handle, 1000, ",");
            if (!$headers) {
                $message = "The CSV file appears to be empty or malformed.";
                $messageType = "error";
            } else {
                // Normalize headers
                $headers = array_map(function ($h) {
                    return trim(strtolower($h)); }, $headers);

                $success_count = 0;
                $error_count = 0;
                $line_number = 1;
                $errors = [];

                if ($import_type === 'products') {
                    // Expected minimal headers: sku, name, price, stock
                    if (!in_array('sku', $headers) || !in_array('name', $headers)) {
                        $message = "CSV is missing mandatory header columns. Required: 'sku' and 'name'.";
                        $messageType = "error";
                    } else {
                        $conn->begin_transaction();
                        $stmt = $conn->prepare("INSERT INTO products (sku_code, product_name, price, stock_level, status) VALUES (?, ?, ?, ?, 'Available') ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), price = VALUES(price), stock_level = VALUES(stock_level)");

                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $line_number++;
                            $row = array_combine($headers, $data);

                            $sku = trim($row['sku'] ?? '');
                            $name = trim($row['name'] ?? '');
                            $price = floatval($row['price'] ?? 0);
                            $stock = intval($row['stock'] ?? 0);

                            if (empty($sku) || empty($name)) {
                                $error_count++;
                                $errors[] = "Line $line_number: Missing SKU or Name.";
                                continue;
                            }

                            $stmt->bind_param("ssdi", $sku, $name, $price, $stock);
                            if ($stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = "Line $line_number: Database error - " . $stmt->error;
                            }
                        }
                        $stmt->close();
                        $conn->commit();
                        log_activity($conn, 'Import', 'Products', 0, "Bulk imported $success_count products from CSV.");
                    }
                } elseif ($import_type === 'customers') {
                    // Expected minimal headers: name, email, phone
                    if (!in_array('name', $headers)) {
                        $message = "CSV is missing mandatory header columns. Required: 'name'.";
                        $messageType = "error";
                    } else {
                        $conn->begin_transaction();
                        $stmt = $conn->prepare("INSERT INTO customers (customer_name, email, phone, address) VALUES (?, ?, ?, ?)");

                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $line_number++;
                            // Handle misaligned rows
                            if (count($headers) !== count($data)) {
                                $error_count++;
                                $errors[] = "Line $line_number: Column count mismatch.";
                                continue;
                            }
                            $row = array_combine($headers, $data);

                            $name = trim($row['name'] ?? '');
                            $email = trim($row['email'] ?? '');
                            $phone = trim($row['phone'] ?? '');
                            $address = trim($row['address'] ?? '');

                            if (empty($name)) {
                                $error_count++;
                                $errors[] = "Line $line_number: Missing Customer Name.";
                                continue;
                            }

                            $stmt->bind_param("ssss", $name, $email, $phone, $address);
                            if ($stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = "Line $line_number: Database error - " . $stmt->error;
                            }
                        }
                        $stmt->close();
                        $conn->commit();
                        log_activity($conn, 'Import', 'Customers', 0, "Bulk imported $success_count customers from CSV.");
                    }
                }

                fclose($handle);

                if (empty($message)) { // If we didn't hit a header error
                    $message = "Import Completed: $success_count records imported successfully. $error_count errors encountered.";
                    $messageType = $error_count > 0 ? "warning" : "success";
                    if ($error_count > 0) {
                        $message .= "<br><br><b>Error Log:</b><br><div style='max-height: 150px; overflow-y: auto; font-size: 13px; margin-top:10px; padding: 10px; background: rgba(0,0,0,0.05); border-radius: 6px;'>" . implode("<br>", $errors) . "</div>";
                    }
                }
            }
        }
    }
}

$current_page = 'import_data.php';
$page_title = 'Data Import Wizard';
$extra_head = '<style>
    .wizard-container { max-width: 800px; margin: 0 auto; background: var(--card-bg); border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-color); overflow: hidden; }
    .wizard-header { background: var(--primary-bg); padding: 30px; text-align: center; border-bottom: 1px solid var(--border-color); }
    .wizard-header h2 { color: var(--primary-color); margin-bottom: 10px; font-weight: 700; display:flex; align-items:center; justify-content:center; gap:10px;}
    .wizard-body { padding: 40px; }
    .step-indicator { display: flex; margin-bottom: 40px; position: relative; justify-content: space-between; }
    .step-indicator::before { content: ""; position: absolute; top: 15px; left: 10%; right: 10%; height: 2px; background: var(--border-color); z-index: 1; }
    .step { position: relative; z-index: 2; text-align: center; }
    .step-circle { width: 32px; height: 32px; border-radius: 50%; background: var(--card-bg); border: 2px solid var(--border-color); color: var(--text-muted); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: 600; box-shadow: 0 0 0 4px var(--card-bg); }
    .step.active .step-circle { border-color: var(--primary-color); background: var(--primary-color); color: white; }
    .step-label { font-size: 13px; font-weight: 500; color: var(--text-muted); }
    .step.active .step-label { color: var(--primary-color); font-weight: 600; }
    
    .file-upload-area { border: 2px dashed var(--border-color); border-radius: 8px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.2s; background: #f9fafb;}
    .file-upload-area:hover { border-color: var(--primary-color); background: var(--primary-bg); }
    .file-upload-area svg { color: var(--text-muted); margin-bottom: 15px; }
    .file-upload-area.dragover { border-color: var(--primary-color); background: var(--primary-bg); }
    
    .template-card { padding: 15px; border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; background: var(--bg-body); }
    .template-info h4 { margin: 0 0 5px 0; font-size: 14px; color: var(--text-dark); }
    .template-info p { margin: 0; font-size: 12px; color: var(--text-muted); }
</style>';

include 'includes/header.php';
?>

<main class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <a href="settings.php" class="back-link">
                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" fill="none" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Settings
            </a>
            <h1>Data Importer Wizard</h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'error' ? 'danger' : ($messageType === 'warning' ? 'warning' : 'success') ?>"
            style="max-width: 800px; margin: 0 auto 20px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="wizard-container">
        <div class="wizard-header">
            <h2>
                <svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" fill="none" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Bulk CSV Import
            </h2>
            <p style="color: var(--text-muted); font-size: 14px; margin:0;">Quickly migrate your legacy inventory and
                customer databases.</p>
        </div>

        <div class="wizard-body">
            <div class="step-indicator">
                <div class="step active">
                    <div class="step-circle">1</div>
                    <div class="step-label">Select Entity</div>
                </div>
                <div class="step active">
                    <div class="step-circle">2</div>
                    <div class="step-label">Upload CSV</div>
                </div>
                <div class="step">
                    <div class="step-circle">3</div>
                    <div class="step-label">Validate & Import</div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="importForm">
                <div class="form-group" style="margin-bottom: 25px;">
                    <label>What are you importing?</label>
                    <select name="import_type" id="importType" class="form-control" onchange="updateTemplates()"
                        required style="height: 48px; font-size:15px; font-weight:500;">
                        <option value="products">Products & Inventory</option>
                        <option value="customers">Customer Directory</option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;" id="templateSection">
                    <label style="font-size: 13px; margin-bottom: 10px; display:block;">Download Templates
                        (Optional)</label>

                    <div class="template-card" id="productTemplate">
                        <div class="template-info">
                            <h4>Products Template</h4>
                            <p>Required columns: <b>sku, name</b>. Optional: price, stock</p>
                            <code
                                style="font-size:11px; background: #e5e7eb; padding: 2px 5px; border-radius: 3px; display:inline-block; margin-top:5px; color:#4b5563;">sku,name,price,stock<br>PROD-1,Widget A,19.99,150</code>
                        </div>
                    </div>

                    <div class="template-card" id="customerTemplate" style="display:none;">
                        <div class="template-info">
                            <h4>Customers Template</h4>
                            <p>Required columns: <b>name</b>. Optional: email, phone, address</p>
                            <code
                                style="font-size:11px; background: #e5e7eb; padding: 2px 5px; border-radius: 3px; display:inline-block; margin-top:5px; color:#4b5563;">name,email,phone,address<br>John Doe,john@example.com,555-0100,123 Main St</code>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Upload File Data</label>
                    <div class="file-upload-area" id="dropZone" onclick="document.getElementById('fileInput').click()">
                        <svg viewBox="0 0 24 24" width="48" height="48" stroke="currentColor" fill="none"
                            stroke-width="1.5">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <line x1="9" y1="15" x2="15" y2="15"></line>
                        </svg>
                        <h3 style="margin-bottom: 5px; color: var(--text-dark);">Click to browse or drag inside</h3>
                        <p style="color: var(--text-muted); font-size: 14px; margin: 0;" id="fileLabel">PNG, JPG, CSV up
                            to 10MB</p>
                        <input type="file" name="csv_file" id="fileInput" accept=".csv" required style="display:none;"
                            onchange="updateFileName(this)">
                    </div>
                </div>

                <div
                    style="border-top: 1px solid var(--border-color); padding-top: 20px; display:flex; justify-content:flex-end; gap:15px; margin-top:30px;">
                    <a href="settings.php" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="startImportBtn"
                        onclick="this.innerHTML='Processing...';">Start Import</button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    function updateTemplates() {
        const type = document.getElementById('importType').value;
        const pt = document.getElementById('productTemplate');
        const ct = document.getElementById('customerTemplate');
        if (type === 'products') {
            pt.style.display = 'flex';
            ct.style.display = 'none';
        } else {
            pt.style.display = 'none';
            ct.style.display = 'flex';
        }
    }

    function updateFileName(input) {
        const label = document.getElementById('fileLabel');
        const icon = document.querySelector('.file-upload-area svg');
        if (input.files && input.files.length > 0) {
            label.innerText = "Selected: " + input.files[0].name;
            label.style.color = "var(--primary-color)";
            label.style.fontWeight = "600";
            icon.style.color = "var(--primary-color)";
        } else {
            label.innerText = "Only .CSV format allowed up to 10MB";
            label.style.color = "var(--text-muted)";
            label.style.fontWeight = "normal";
            icon.style.color = "var(--text-muted)";
        }
    }

    // Basic drag and drop visual logic
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });

    function highlight(e) { dropZone.classList.add('dragover'); }
    function unhighlight(e) { dropZone.classList.remove('dragover'); }

    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        fileInput.files = files;
        updateFileName(fileInput);
    }
</script>

<?php include 'includes/footer.php'; ?>