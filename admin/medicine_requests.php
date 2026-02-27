<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get current user info
$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['username'] ?? 'Clinic Staff';

// Initialize variables
$success_message = '';
$error_message = '';

// Create necessary tables if they don't exist
try {
    // Clinic stock table (what's inside the clinic)
    $db->exec("CREATE TABLE IF NOT EXISTS `clinic_stock` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `item_code` varchar(50) NOT NULL,
        `item_name` varchar(200) NOT NULL,
        `category` enum('Medicine','Supply') NOT NULL,
        `quantity` int(11) NOT NULL DEFAULT 0,
        `unit` varchar(20) NOT NULL,
        `expiry_date` date DEFAULT NULL,
        `date_received` date NOT NULL,
        `minimum_stock` int(11) NOT NULL DEFAULT 10,
        `received_from` varchar(200) DEFAULT NULL,
        `request_id` int(11) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `item_code` (`item_code`),
        KEY `request_id` (`request_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Medicine requests table
    $db->exec("CREATE TABLE IF NOT EXISTS `medicine_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `request_code` varchar(50) NOT NULL,
        `item_code` varchar(50) NOT NULL,
        `item_name` varchar(200) NOT NULL,
        `category` enum('Medicine','Supply') NOT NULL,
        `quantity_requested` int(11) NOT NULL,
        `quantity_approved` int(11) DEFAULT NULL,
        `reason` text NOT NULL,
        `urgency` enum('normal','urgent') DEFAULT 'normal',
        `status` enum('pending','approved','released','rejected','cancelled') DEFAULT 'pending',
        `requested_by` int(11) NOT NULL,
        `requested_by_name` varchar(100) NOT NULL,
        `requested_date` timestamp NOT NULL DEFAULT current_timestamp(),
        `approved_by` int(11) DEFAULT NULL,
        `approved_date` datetime DEFAULT NULL,
        `released_date` datetime DEFAULT NULL,
        `notes` text DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `request_code` (`request_code`),
        KEY `requested_by` (`requested_by`),
        KEY `approved_by` (`approved_by`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Dispensing log table
    $db->exec("CREATE TABLE IF NOT EXISTS `dispensing_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `visit_id` int(11) DEFAULT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `item_code` varchar(50) NOT NULL,
        `item_name` varchar(200) NOT NULL,
        `category` enum('Medicine','Supply') NOT NULL,
        `quantity` int(11) NOT NULL,
        `unit` varchar(20) NOT NULL,
        `dispensed_date` timestamp NOT NULL DEFAULT current_timestamp(),
        `dispensed_by` int(11) NOT NULL,
        `reason` text NOT NULL,
        PRIMARY KEY (`id`),
        KEY `visit_id` (`visit_id`),
        KEY `student_id` (`student_id`),
        KEY `item_code` (`item_code`),
        KEY `dispensed_by` (`dispensed_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Create new request to property custodian
    if (isset($_POST['action']) && $_POST['action'] == 'create_request') {
        try {
            // Fetch item details from property custodian API
            $api_url = "https://qcprotektado.com/api/clinic.php/";
            if ($_POST['category'] == 'Medicine') {
                $api_url .= "medicines?id=" . $_POST['item_id'];
            } else {
                $api_url .= "supplies?id=" . $_POST['item_id'];
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $response) {
                $item_data = json_decode($response, true);
                
                if (isset($item_data['data'])) {
                    $item = $item_data['data'];
                    
                    // Generate request code
                    $prefix = $_POST['category'] == 'Medicine' ? 'MEDREQ' : 'SUPREQ';
                    $date = date('Ymd');
                    $random = rand(1000, 9999);
                    $request_code = $prefix . '-' . $date . '-' . $random;
                    
                    // In the create request section, update the INSERT query:
$query = "INSERT INTO medicine_requests (
    request_code, item_code, item_name, category, unit,
    quantity_requested, reason, urgency, requested_by, 
    requested_by_name, notes
) VALUES (
    :request_code, :item_code, :item_name, :category, :unit,
    :quantity, :reason, :urgency, :requested_by,
    :requested_by_name, :notes
)";

// And add this line before binding:
$unit = $_POST['category'] == 'Medicine' ? ($item['unit'] ?? 'tablet') : ($item['unit'] ?? 'piece');
$stmt->bindParam(':unit', $unit);
                    
                    $stmt = $db->prepare($query);
                    $item_code = $_POST['category'] == 'Medicine' ? $item['medicine_code'] : $item['supply_code'];
                    $item_name = $_POST['category'] == 'Medicine' ? $item['generic_name'] . ' ' . $item['strength'] : $item['supply_name'];
                    
                    $stmt->bindParam(':request_code', $request_code);
                    $stmt->bindParam(':item_code', $item_code);
                    $stmt->bindParam(':item_name', $item_name);
                    $stmt->bindParam(':category', $_POST['category']);
                    $stmt->bindParam(':quantity', $_POST['quantity']);
                    $stmt->bindParam(':reason', $_POST['reason']);
                    $stmt->bindParam(':urgency', $_POST['urgency']);
                    $stmt->bindParam(':requested_by', $current_user_id);
                    $stmt->bindParam(':requested_by_name', $current_user_name);
                    $stmt->bindParam(':notes', $_POST['notes']);
                    
                    if ($stmt->execute()) {
                        $success_message = "Request submitted successfully! Request Code: " . $request_code;
                    } else {
                        $error_message = "Failed to submit request.";
                    }
                }
            } else {
                $error_message = "Failed to fetch item details from property custodian.";
            }
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
    
    // Dispense medicine to student
    if (isset($_POST['action']) && $_POST['action'] == 'dispense') {
        try {
            // Check if enough stock
            $check_query = "SELECT quantity FROM clinic_stock WHERE item_code = :item_code";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':item_code', $_POST['item_code']);
            $check_stmt->execute();
            $stock = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stock && $stock['quantity'] >= $_POST['quantity']) {
                
                // Start transaction
                $db->beginTransaction();
                
                // Insert dispensing log
                $query = "INSERT INTO dispensing_log (
                    visit_id, student_id, student_name, item_code, 
                    item_name, category, quantity, unit, dispensed_by, reason
                ) VALUES (
                    :visit_id, :student_id, :student_name, :item_code,
                    :item_name, :category, :quantity, :unit, :dispensed_by, :reason
                )";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':visit_id', $_POST['visit_id']);
                $stmt->bindParam(':student_id', $_POST['student_id']);
                $stmt->bindParam(':student_name', $_POST['student_name']);
                $stmt->bindParam(':item_code', $_POST['item_code']);
                $stmt->bindParam(':item_name', $_POST['item_name']);
                $stmt->bindParam(':category', $_POST['category']);
                $stmt->bindParam(':quantity', $_POST['quantity']);
                $stmt->bindParam(':unit', $_POST['unit']);
                $stmt->bindParam(':dispensed_by', $current_user_id);
                $stmt->bindParam(':reason', $_POST['reason']);
                $stmt->execute();
                
                // Update clinic stock
                $update_query = "UPDATE clinic_stock SET quantity = quantity - :quantity 
                                WHERE item_code = :item_code";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':quantity', $_POST['quantity']);
                $update_stmt->bindParam(':item_code', $_POST['item_code']);
                $update_stmt->execute();
                
                $db->commit();
                $success_message = "Medicine dispensed successfully!";
                
            } else {
                $error_message = "Insufficient stock. Available: " . ($stock['quantity'] ?? 0);
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Fetch data from property custodian API
function fetchPropertyItems($type) {
    $api_url = "https://qcprotektado.com/api/clinic.php/" . $type;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        return isset($data['data']) ? $data['data'] : [];
    }
    return [];
}

// Get clinic stock
function getClinicStock($db) {
    $query = "SELECT * FROM clinic_stock ORDER BY item_name ASC";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get low stock items
function getLowStockItems($db) {
    $query = "SELECT * FROM clinic_stock WHERE quantity <= minimum_stock ORDER BY quantity ASC";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get pending requests
function getPendingRequests($db) {
    $query = "SELECT * FROM medicine_requests WHERE status = 'pending' ORDER BY 
              CASE WHEN urgency = 'urgent' THEN 0 ELSE 1 END, requested_date DESC";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get request history
function getRequestHistory($db) {
    $query = "SELECT * FROM medicine_requests ORDER BY requested_date DESC LIMIT 20";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get dispensing history
function getDispensingHistory($db) {
    $query = "SELECT d.*, u.full_name as dispensed_by_name 
              FROM dispensing_log d
              LEFT JOIN users u ON d.dispensed_by = u.id
              ORDER BY d.dispensed_date DESC LIMIT 20";
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch data
$medicines = fetchPropertyItems('medicines');
$supplies = fetchPropertyItems('supplies');
$clinic_stock = getClinicStock($db);
$low_stock = getLowStockItems($db);
$pending_requests = getPendingRequests($db);
$request_history = getRequestHistory($db);
$dispensing_history = getDispensingHistory($db);

// Get counts
$total_medicines = count($medicines);
$total_supplies = count($supplies);
$total_stock_items = count($clinic_stock);
$low_stock_count = count($low_stock);
$pending_count = count($pending_requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Requests & Inventory | MedFlow Clinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #eceff1;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 20px 30px 30px 30px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: #eceff1;
        }

        .main-content.expanded {
            margin-left: 110px;
        }

        .dashboard-container {
            position: relative;
            z-index: 1;
        }

        .welcome-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            color: #546e7a;
            font-size: 1rem;
            font-weight: 400;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            border: 1px solid #ffcdd2;
            color: #c62828;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
            border-color: #191970;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: #546e7a;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-badge {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 20px;
            background: #ffebee;
            color: #c62828;
            font-weight: 600;
        }

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            overflow: hidden;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid #eceff1;
            background: #f5f5f5;
            overflow-x: auto;
        }

        .tab-btn {
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: #78909c;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: #191970;
            background: rgba(25, 25, 112, 0.05);
        }

        .tab-btn.active {
            color: #191970;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #191970;
        }

        .tab-content {
            padding: 30px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Grid Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title span {
            font-size: 0.8rem;
            color: #78909c;
            font-weight: normal;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 16px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #cfd8dc;
            background: #eceff1;
        }

        .data-table td {
            padding: 16px 12px;
            font-size: 0.9rem;
            color: #37474f;
            border-bottom: 1px solid #cfd8dc;
        }

        .data-table tr:hover td {
            background: #f5f5f5;
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #fff3e0;
            color: #e65100;
        }

        .status-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-released {
            background: #e3f2fd;
            color: #1565c0;
        }

        .status-rejected {
            background: #ffebee;
            color: #c62828;
        }

        .status-cancelled {
            background: #eceff1;
            color: #546e7a;
        }

        .status-urgent {
            background: #ffebee;
            color: #c62828;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .stock-low {
            color: #c62828;
            font-weight: 600;
        }

        .stock-normal {
            color: #2e7d32;
        }

        /* Forms */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #546e7a;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.95rem;
            border: 2px solid #cfd8dc;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: white;
            color: #37474f;
        }

        .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23546e7a' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #191970;
            color: white;
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .btn-secondary {
            background: #eceff1;
            color: #37474f;
            border: 1px solid #cfd8dc;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 1.3rem;
            color: #191970;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #78909c;
        }

        /* Low Stock Alert */
        .low-stock-alert {
            background: #ffebee;
            border: 1px solid #ffcdd2;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .low-stock-alert svg {
            color: #c62828;
            width: 24px;
            height: 24px;
        }

        .low-stock-alert .alert-text {
            flex: 1;
        }

        .low-stock-alert .alert-text strong {
            color: #c62828;
            display: block;
            margin-bottom: 4px;
        }

        .low-stock-alert .alert-text p {
            color: #37474f;
            font-size: 0.9rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #78909c;
        }

        .empty-state svg {
            width: 60px;
            height: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .empty-state small {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs-header {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                flex: 1;
                text-align: center;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="dashboard-container">
                <div class="welcome-section">
                    <h1>Medicine & Supplies Inventory</h1>
                    <p>Request items from property custodian and manage clinic stock.</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                            <path d="M22 4L12 14.01L9 11.01"/>
                        </svg>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_medicines + $total_supplies; ?></h3>
                            <p>Available Items</p>
                            <small style="color: #78909c;">From Property Custodian</small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                <path d="M2 17L12 22L22 17"/>
                                <path d="M2 12L12 17L22 12"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_stock_items; ?></h3>
                            <p>Clinic Stock</p>
                            <?php if ($low_stock_count > 0): ?>
                                <span class="stat-badge"><?php echo $low_stock_count; ?> low stock</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H15L21 9V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                <path d="M16 21V15H8V21"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $pending_count; ?></h3>
                            <p>Pending Requests</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($dispensing_history); ?></h3>
                            <p>Dispensed Today</p>
                        </div>
                    </div>
                </div>

                   
        

                <!-- Tabs Section -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showTab('request', event)">➕ Request Items</button>
                        <button class="tab-btn" onclick="showTab('stock', event)">📦 Clinic Stock</button>
                        <button class="tab-btn" onclick="showTab('pending', event)">⏳ Pending Requests <?php if ($pending_count > 0): ?><span class="status-urgent"><?php echo $pending_count; ?></span><?php endif; ?></button>
                        <button class="tab-btn" onclick="showTab('history', event)">📋 Request History</button>
                        <button class="tab-btn" onclick="showTab('dispense', event)">💊 Dispensing Log</button>
                    </div>

                    <div class="tab-content">
                        <!-- Request Items Tab -->
                        <div class="tab-pane active" id="request">
                            <div class="dashboard-grid">
                                <!-- Request Form -->
                                <div class="card">
                                    <div class="card-title">
                                        New Request to Property Custodian
                                        <span>Items from custodian inventory</span>
                                    </div>
                                    
                                    <form method="POST" onsubmit="return validateRequest()">
                                        <input type="hidden" name="action" value="create_request">
                                        
                                        <div class="form-group">
                                            <label>Category</label>
                                            <select name="category" id="requestCategory" class="form-control" required onchange="loadItems()">
                                                <option value="">Select Category</option>
                                                <option value="Medicine">Medicine</option>
                                                <option value="Supply">Medical Supply</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>Item</label>
                                            <select name="item_id" id="requestItem" class="form-control" required onchange="updateItemDetails()">
                                                <option value="">Select Item</option>
                                                <?php foreach ($medicines as $medicine): ?>
                                                    <option class="medicine-item" value="<?php echo $medicine['id']; ?>" 
                                                            data-code="<?php echo $medicine['medicine_code']; ?>"
                                                            data-name="<?php echo $medicine['generic_name'] . ' ' . ($medicine['strength'] ?? ''); ?>"
                                                            data-unit="<?php echo $medicine['unit']; ?>"
                                                            data-stock="<?php echo $medicine['current_stock']; ?>">
                                                        <?php echo $medicine['generic_name']; ?> 
                                                        <?php if (!empty($medicine['strength'])): ?>(<?php echo $medicine['strength']; ?>)<?php endif; ?>
                                                        - Stock: <?php echo $medicine['current_stock']; ?> <?php echo $medicine['unit']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <?php foreach ($supplies as $supply): ?>
                                                    <option class="supply-item" value="<?php echo $supply['id']; ?>" 
                                                            data-code="<?php echo $supply['supply_code']; ?>"
                                                            data-name="<?php echo $supply['supply_name']; ?>"
                                                            data-unit="<?php echo $supply['unit']; ?>"
                                                            data-stock="<?php echo $supply['current_stock']; ?>">
                                                        <?php echo $supply['supply_name']; ?> 
                                                        - Stock: <?php echo $supply['current_stock']; ?> <?php echo $supply['unit']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label>Quantity</label>
                                                <input type="number" name="quantity" id="requestQuantity" class="form-control" min="1" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Urgency</label>
                                                <select name="urgency" class="form-control">
                                                    <option value="normal">Normal</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>Reason for Request</label>
                                            <textarea name="reason" class="form-control" placeholder="e.g., Low stock, Emergency, Event preparation" required></textarea>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>Additional Notes (Optional)</label>
                                            <textarea name="notes" class="form-control" placeholder="Any special instructions..."></textarea>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">Submit Request</button>
                                    </form>
                                </div>
                                
                                <!-- Available Items Preview -->
                                <div class="card">
                                    <div class="card-title">
                                        Available Items
                                        <span>From property custodian</span>
                                    </div>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <select id="previewCategory" class="form-control" onchange="filterPreview()">
                                            <option value="all">All Items</option>
                                            <option value="Medicine">Medicines Only</option>
                                            <option value="Supply">Supplies Only</option>
                                        </select>
                                    </div>
                                    
                                    <div class="table-wrapper" style="max-height: 400px; overflow-y: auto;">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Stock</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($medicines as $medicine): ?>
                                                <tr class="preview-row medicine-item">
                                                    <td>
                                                        <strong><?php echo $medicine['generic_name']; ?></strong><br>
                                                        <small><?php echo $medicine['strength'] ?? ''; ?> | <?php echo $medicine['dosage_form'] ?? ''; ?></small>
                                                    </td>
                                                    <td><?php echo $medicine['current_stock']; ?> <?php echo $medicine['unit']; ?></td>
                                                    <td>
                                                        <?php if ($medicine['current_stock'] <= ($medicine['minimum_stock'] ?? 10)): ?>
                                                            <span class="status-badge status-pending">Low Stock</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-approved">Available</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php foreach ($supplies as $supply): ?>
                                                <tr class="preview-row supply-item">
                                                    <td>
                                                        <strong><?php echo $supply['supply_name']; ?></strong><br>
                                                        <small><?php echo $supply['description'] ?? ''; ?></small>
                                                    </td>
                                                    <td><?php echo $supply['current_stock']; ?> <?php echo $supply['unit']; ?></td>
                                                    <td>
                                                        <?php if ($supply['current_stock'] <= ($supply['minimum_stock'] ?? 10)): ?>
                                                            <span class="status-badge status-pending">Low Stock</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-approved">Available</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Clinic Stock Tab -->
                        <div class="tab-pane" id="stock">
                            <div class="card">
                                <div class="card-title">
                                    Current Clinic Stock
                                    <span>Items inside clinic cabinet</span>
                                </div>
                                
                                <?php if (!empty($clinic_stock)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Quantity</th>
                                                <th>Unit</th>
                                                <th>Expiry Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($clinic_stock as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small>Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $item['category'] == 'Medicine' ? 'status-approved' : 'status-pending'; ?>">
                                                        <?php echo $item['category']; ?>
                                                    </span>
                                                </td>
                                                <td class="<?php echo $item['quantity'] <= $item['minimum_stock'] ? 'stock-low' : 'stock-normal'; ?>">
                                                    <strong><?php echo $item['quantity']; ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                                <td>
                                                    <?php if ($item['expiry_date']): ?>
                                                        <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                                        <?php if (strtotime($item['expiry_date']) < time()): ?>
                                                            <br><small style="color: #c62828;">Expired</small>
                                                        <?php elseif (strtotime($item['expiry_date']) < strtotime('+30 days')): ?>
                                                            <br><small style="color: #e65100;">Expiring soon</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['quantity'] <= $item['minimum_stock']): ?>
                                                        <span class="status-badge status-pending">Low Stock</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-approved">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-small btn-secondary" onclick="showDispenseModal(
                                                        '<?php echo $item['item_code']; ?>',
                                                        '<?php echo addslashes($item['item_name']); ?>',
                                                        '<?php echo $item['category']; ?>',
                                                        '<?php echo $item['unit']; ?>',
                                                        <?php echo $item['quantity']; ?>
                                                    )">Dispense</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                        <path d="M2 17L12 22L22 17"/>
                                        <path d="M2 12L12 17L22 12"/>
                                    </svg>
                                    <p>No items in clinic stock</p>
                                    <small>Request items from property custodian to start building inventory</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Pending Requests Tab -->
                        <div class="tab-pane" id="pending">
                            <div class="card">
                                <div class="card-title">
                                    Pending Requests
                                    <span>Waiting for property custodian approval</span>
                                </div>
                                
                                <?php if (!empty($pending_requests)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Request Code</th>
                                                <th>Item</th>
                                                <th>Quantity</th>
                                                <th>Reason</th>
                                                <th>Requested Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($request['request_code']); ?></strong>
                                                    <?php if ($request['urgency'] == 'urgent'): ?>
                                                        <span class="status-urgent">URGENT</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['item_name']); ?><br>
                                                    <small><?php echo $request['category']; ?></small>
                                                </td>
                                                <td><?php echo $request['quantity_requested']; ?></td>
                                                <td><?php echo htmlspecialchars($request['reason']); ?></td>
                                                <td><?php echo date('M d, Y h:i A', strtotime($request['requested_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-pending">Pending</span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H15L21 9V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                        <path d="M16 21V15H8V21"/>
                                    </svg>
                                    <p>No pending requests</p>
                                    <small>All requests have been processed</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Request History Tab -->
                        <div class="tab-pane" id="history">
                            <div class="card">
                                <div class="card-title">
                                    Request History
                                    <span>Last 20 requests</span>
                                </div>
                                
                                <?php if (!empty($request_history)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Request Code</th>
                                                <th>Item</th>
                                                <th>Qty</th>
                                                <th>Requested By</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($request_history as $request): ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo htmlspecialchars($request['request_code']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['item_name']); ?><br>
                                                    <small><?php echo $request['category']; ?></small>
                                                </td>
                                                <td><?php echo $request['quantity_requested']; ?></td>
                                                <td><?php echo htmlspecialchars($request['requested_by_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($request['requested_date'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                                        <?php echo ucfirst($request['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z"/>
                                        <path d="M14 2V8H20"/>
                                    </svg>
                                    <p>No request history</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Dispensing Log Tab -->
                        <div class="tab-pane" id="dispense">
                            <div class="card">
                                <div class="card-title">
                                    Dispensing Log
                                    <span>Medicine dispensed to students</span>
                                </div>
                                
                                <?php if (!empty($dispensing_history)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date/Time</th>
                                                <th>Student</th>
                                                <th>Item</th>
                                                <th>Quantity</th>
                                                <th>Reason</th>
                                                <th>Dispensed By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dispensing_history as $log): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y h:i A', strtotime($log['dispensed_date'])); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($log['student_name']); ?></strong><br>
                                                    <small>ID: <?php echo htmlspecialchars($log['student_id']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($log['item_name']); ?><br>
                                                    <small><?php echo $log['category']; ?></small>
                                                </td>
                                                <td><?php echo $log['quantity'] . ' ' . $log['unit']; ?></td>
                                                <td><?php echo htmlspecialchars($log['reason']); ?></td>
                                                <td><small><?php echo htmlspecialchars($log['dispensed_by_name'] ?? 'N/A'); ?></small></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6V12L16 14"/>
                                    </svg>
                                    <p>No dispensing records</p>
                                    <small>Medicine dispensing will appear here</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dispense Modal -->
    <div class="modal" id="dispenseModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Dispense Medicine/Supply</h2>
                <button class="close-btn" onclick="closeDispenseModal()">&times;</button>
            </div>
            
            <form method="POST" onsubmit="return validateDispense()">
                <input type="hidden" name="action" value="dispense">
                <input type="hidden" name="item_code" id="dispenseItemCode">
                <input type="hidden" name="item_name" id="dispenseItemName">
                <input type="hidden" name="category" id="dispenseCategory">
                <input type="hidden" name="unit" id="dispenseUnit">
                
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="student_id" id="dispenseStudentId" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" name="student_name" id="dispenseStudentName" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Item</label>
                    <input type="text" id="dispenseItemDisplay" class="form-control" readonly disabled>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="dispenseQuantity" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Available Stock</label>
                        <input type="text" id="dispenseAvailable" class="form-control" readonly disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Reason for Dispensing</label>
                    <select name="reason" class="form-control" required>
                        <option value="">Select Reason</option>
                        <option value="Fever">Fever</option>
                        <option value="Headache">Headache</option>
                        <option value="Pain">Pain</option>
                        <option value="Allergy">Allergy</option>
                        <option value="First Aid">First Aid</option>
                        <option value="Routine">Routine</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Visit ID (Optional)</label>
                    <input type="number" name="visit_id" class="form-control" placeholder="Link to clinic visit">
                </div>
                
                <button type="submit" class="btn btn-primary">Dispense Item</button>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseSidebar');
        
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            });
        }

        // Tab functionality
        function showTab(tabName, event) {
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Filter preview items
        function filterPreview() {
            const category = document.getElementById('previewCategory').value;
            const rows = document.querySelectorAll('.preview-row');
            
            rows.forEach(row => {
                if (category === 'all') {
                    row.style.display = '';
                } else if (category === 'Medicine' && row.classList.contains('medicine-item')) {
                    row.style.display = '';
                } else if (category === 'Supply' && row.classList.contains('supply-item')) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Load items based on category
        function loadItems() {
            const category = document.getElementById('requestCategory').value;
            const itemSelect = document.getElementById('requestItem');
            const options = itemSelect.querySelectorAll('option');
            
            options.forEach(opt => {
                if (opt.value === '') return;
                if (category === 'Medicine' && opt.classList.contains('medicine-item')) {
                    opt.style.display = '';
                } else if (category === 'Supply' && opt.classList.contains('supply-item')) {
                    opt.style.display = '';
                } else if (category === '') {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            
            itemSelect.value = '';
        }

// Update this function in the JavaScript section
function updateItemDetails() {
    const select = document.getElementById('requestItem');
    const option = select.options[select.selectedIndex];
    
    if (option.value) {
        const maxStock = option.dataset.stock;
        const quantityInput = document.getElementById('requestQuantity');
        quantityInput.max = maxStock;
        quantityInput.min = 1;
        quantityInput.placeholder = `Max available: ${maxStock}`;
        
        // Add a helper text
        let helperText = document.getElementById('stockHelper');
        if (!helperText) {
            helperText = document.createElement('small');
            helperText.id = 'stockHelper';
            helperText.style.display = 'block';
            helperText.style.color = '#546e7a';
            helperText.style.marginTop = '4px';
            helperText.style.fontSize = '0.8rem';
            quantityInput.parentNode.appendChild(helperText);
        }
        helperText.innerHTML = `Available from property custodian: ${maxStock} ${option.dataset.unit || 'units'}`;
    }
}

// Update validation function
function validateRequest() {
    const category = document.getElementById('requestCategory').value;
    const item = document.getElementById('requestItem').value;
    const quantity = document.getElementById('requestQuantity').value;
    const max = document.getElementById('requestQuantity').max;
    const min = document.getElementById('requestQuantity').min || 1;
    
    if (!category || !item || !quantity) {
        alert('Please fill in all required fields');
        return false;
    }
    
    if (quantity < min) {
        alert(`Quantity must be at least ${min}`);
        return false;
    }
    
    if (quantity > max) {
        alert(`Cannot request more than what's available from property custodian. Available: ${max}`);
        return false;
    }
    
    return true;
}

        // Show dispense modal
        function showDispenseModal(code, name, category, unit, available) {
            document.getElementById('dispenseItemCode').value = code;
            document.getElementById('dispenseItemName').value = name;
            document.getElementById('dispenseCategory').value = category;
            document.getElementById('dispenseUnit').value = unit;
            document.getElementById('dispenseItemDisplay').value = name;
            document.getElementById('dispenseAvailable').value = available + ' ' + unit;
            document.getElementById('dispenseQuantity').max = available;
            document.getElementById('dispenseModal').classList.add('active');
        }

        function closeDispenseModal() {
            document.getElementById('dispenseModal').classList.remove('active');
        }

        function validateDispense() {
            const quantity = document.getElementById('dispenseQuantity').value;
            const max = document.getElementById('dispenseQuantity').max;
            
            if (!quantity || quantity < 1) {
                alert('Please enter a valid quantity');
                return false;
            }
            
            if (quantity > max) {
                alert(`Quantity cannot exceed available stock (${max})`);
                return false;
            }
            
            return true;
        }

        // Show request modal (for low stock alert)
        function showRequestModal() {
            showTab('request', {target: document.querySelector('.tab-btn')});
            document.querySelector('.tab-btn').click();
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadItems();
            
            // Set page title
            const pageTitle = document.getElementById('pageTitle');
            if (pageTitle) {
                pageTitle.textContent = 'Medicine Requests';
            }
        });
    </script>
</body>
</html>