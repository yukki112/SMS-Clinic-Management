<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php';



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

// Get current user full name from database
$user_query = "SELECT full_name FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $current_user_id);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$current_user_fullname = $user_data ? $user_data['full_name'] : $current_user_name;

// Initialize variables
$student_data = null;
$search_error = '';
$student_id_search = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$success_message = '';
$error_message = '';
$generated_clearance_id = isset($_GET['generated']) ? intval($_GET['generated']) : 0;
$show_print_modal = false;

// Create clearance tables if not exists
try {
    // Clearance requests table
    $db->exec("CREATE TABLE IF NOT EXISTS `clearance_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `clearance_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `clearance_type` enum('Sports','Event','Work Immersion','After Illness','After Hospitalization','After Injury','General') NOT NULL,
        `purpose` text NOT NULL,
        `request_date` date NOT NULL,
        `status` enum('Pending','Approved','Not Cleared','Expired') DEFAULT 'Approved',
        `approved_date` date DEFAULT NULL,
        `approved_by` varchar(100) DEFAULT NULL,
        `remarks` text DEFAULT NULL,
        `valid_until` date DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `clearance_code` (`clearance_code`),
        KEY `student_id` (`student_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Medical certificates table
    $db->exec("CREATE TABLE IF NOT EXISTS `medical_certificates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `certificate_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `certificate_type` enum('Fit to Return','Fit for PE','Fit for Activities','Medical Leave','General') NOT NULL,
        `issued_date` date NOT NULL,
        `valid_until` date DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `recommendations` text DEFAULT NULL,
        `restrictions` text DEFAULT NULL,
        `issued_by` varchar(100) NOT NULL,
        `issuer_id` int(11) NOT NULL,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `certificate_code` (`certificate_code`),
        KEY `student_id` (`student_id`),
        KEY `certificate_type` (`certificate_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Fit-to-return slips table
    $db->exec("CREATE TABLE IF NOT EXISTS `fit_to_return_slips` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `slip_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `absence_days` int(11) DEFAULT NULL,
        `absence_reason` text DEFAULT NULL,
        `assessment_date` date NOT NULL,
        `temperature` decimal(4,2) DEFAULT NULL,
        `blood_pressure` varchar(10) DEFAULT NULL,
        `heart_rate` int(11) DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `fit_to_return` enum('Yes','No','With Restrictions') DEFAULT 'Yes',
        `restrictions` text DEFAULT NULL,
        `recommended_rest_days` int(11) DEFAULT NULL,
        `next_checkup_date` date DEFAULT NULL,
        `issued_by` varchar(100) NOT NULL,
        `issuer_id` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `slip_code` (`slip_code`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Create clearance request (auto-approved for walk-in)
    if ($_POST['action'] == 'create_clearance') {
        try {
            // Generate clearance code
            $prefix = 'CLR';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $clearance_code = $prefix . '-' . $date . '-' . $random;
            
            $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : date('Y-m-d', strtotime('+1 year'));
            $approved_date = date('Y-m-d');
            
            $query = "INSERT INTO clearance_requests (
                clearance_code, student_id, student_name, grade_section,
                clearance_type, purpose, request_date, status, 
                approved_date, approved_by, valid_until, created_by
            ) VALUES (
                :clearance_code, :student_id, :student_name, :grade_section,
                :clearance_type, :purpose, :request_date, 'Approved',
                :approved_date, :approved_by, :valid_until, :created_by
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':clearance_code', $clearance_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':clearance_type', $_POST['clearance_type']);
            $stmt->bindParam(':purpose', $_POST['purpose']);
            $stmt->bindParam(':request_date', $_POST['request_date']);
            $stmt->bindParam(':approved_date', $approved_date);
            $stmt->bindParam(':approved_by', $current_user_fullname);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':created_by', $current_user_id);
            
            if ($stmt->execute()) {
                $clearance_id = $db->lastInsertId();
                $success_message = "Clearance generated successfully! Code: " . $clearance_code;
                
                // Redirect to PDF generation
                header('Location: generate_clearance.php?id=' . $clearance_id);
                exit();
            } else {
                $error_message = "Error creating clearance request.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Issue medical certificate
    if ($_POST['action'] == 'issue_certificate') {
        try {
            // Generate certificate code
            $prefix = 'CERT';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $certificate_code = $prefix . '-' . $date . '-' . $random;
            
            $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
            
            $query = "INSERT INTO medical_certificates (
                certificate_code, student_id, student_name, grade_section,
                certificate_type, issued_date, valid_until, findings,
                recommendations, restrictions, issued_by, issuer_id, remarks
            ) VALUES (
                :certificate_code, :student_id, :student_name, :grade_section,
                :certificate_type, :issued_date, :valid_until, :findings,
                :recommendations, :restrictions, :issued_by, :issuer_id, :remarks
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':certificate_code', $certificate_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':certificate_type', $_POST['certificate_type']);
            $stmt->bindParam(':issued_date', $_POST['issued_date']);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':findings', $_POST['findings']);
            $stmt->bindParam(':recommendations', $_POST['recommendations']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':issued_by', $current_user_fullname);
            $stmt->bindParam(':issuer_id', $current_user_id);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            if ($stmt->execute()) {
                $certificate_id = $db->lastInsertId();
                $success_message = "Medical certificate issued successfully! Code: " . $certificate_code;
                
                // Redirect to PDF generation
                header('Location: generate_certificate.php?id=' . $certificate_id);
                exit();
            } else {
                $error_message = "Error issuing medical certificate.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Create fit-to-return slip
    if ($_POST['action'] == 'create_fit_to_return') {
        try {
            // Generate slip code
            $prefix = 'FTR';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $slip_code = $prefix . '-' . $date . '-' . $random;
            
            $next_checkup = !empty($_POST['next_checkup_date']) ? $_POST['next_checkup_date'] : null;
            
            $query = "INSERT INTO fit_to_return_slips (
                slip_code, student_id, student_name, grade_section,
                absence_days, absence_reason, assessment_date,
                temperature, blood_pressure, heart_rate,
                findings, fit_to_return, restrictions,
                recommended_rest_days, next_checkup_date, issued_by, issuer_id
            ) VALUES (
                :slip_code, :student_id, :student_name, :grade_section,
                :absence_days, :absence_reason, :assessment_date,
                :temperature, :blood_pressure, :heart_rate,
                :findings, :fit_to_return, :restrictions,
                :recommended_rest_days, :next_checkup_date, :issued_by, :issuer_id
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':slip_code', $slip_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':absence_days', $_POST['absence_days']);
            $stmt->bindParam(':absence_reason', $_POST['absence_reason']);
            $stmt->bindParam(':assessment_date', $_POST['assessment_date']);
            $stmt->bindParam(':temperature', $_POST['temperature']);
            $stmt->bindParam(':blood_pressure', $_POST['blood_pressure']);
            $stmt->bindParam(':heart_rate', $_POST['heart_rate']);
            $stmt->bindParam(':findings', $_POST['findings']);
            $stmt->bindParam(':fit_to_return', $_POST['fit_to_return']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':recommended_rest_days', $_POST['recommended_rest_days']);
            $stmt->bindParam(':next_checkup_date', $next_checkup);
            $stmt->bindParam(':issued_by', $current_user_fullname);
            $stmt->bindParam(':issuer_id', $current_user_id);
            
            if ($stmt->execute()) {
                $slip_id = $db->lastInsertId();
                $success_message = "Fit-to-return slip created successfully! Code: " . $slip_code;
                
                // Redirect to PDF generation
                header('Location: generate_fit_to_return.php?id=' . $slip_id);
                exit();
            } else {
                $error_message = "Error creating fit-to-return slip.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Get statistics
$stats = [];

try {
    // Clearances issued today
    $query = "SELECT COUNT(*) as total FROM clearance_requests WHERE DATE(request_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['clearances_today'] = $result ? $result['total'] : 0;
    
    // Certificates issued today
    $query = "SELECT COUNT(*) as total FROM medical_certificates WHERE DATE(issued_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['certificates_today'] = $result ? $result['total'] : 0;
    
    // Fit-to-return slips today
    $query = "SELECT COUNT(*) as total FROM fit_to_return_slips WHERE DATE(assessment_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['fit_to_return_today'] = $result ? $result['total'] : 0;
    
    // Active clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests 
              WHERE status = 'Approved' 
              AND (valid_until IS NULL OR valid_until >= CURDATE())";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active_clearances'] = $result ? $result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = [
        'clearances_today' => 0,
        'certificates_today' => 0,
        'fit_to_return_today' => 0,
        'active_clearances' => 0
    ];
}

// Get recent clearances
function getRecentClearances($db, $limit = 10) {
    try {
        $query = "SELECT * FROM clearance_requests 
                  ORDER BY request_date DESC 
                  LIMIT :limit";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$recent_clearances = getRecentClearances($db);

// Search for student if ID provided
if (!empty($student_id_search)) {
    $api_url = "https://ttm.qcprotektado.com/api/students.php";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        $api_response = json_decode($response, true);
        
        if (isset($api_response['records']) && is_array($api_response['records'])) {
            $found = false;
            foreach ($api_response['records'] as $student) {
                if (isset($student['student_id']) && $student['student_id'] == $student_id_search) {
                    $student_data = $student;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $search_error = "Student ID not found in the system.";
            }
        } else {
            $search_error = "Unable to fetch student data.";
        }
    } else {
        $search_error = "Error connecting to student database.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Clearance & Certification | MedFlow Clinic Management System</title>
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
            background: #f8f0f5;
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
            background: #f8f0f5;
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
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            color: #7a4b6b;
            font-size: 1rem;
            font-weight: 400;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(107, 43, 94, 0.2), 0 10px 10px -5px rgba(107, 43, 94, 0.1);
            border-color: #a14a76;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #6b2b5e;
            margin-bottom: 2px;
        }

        .stat-info p {
            color: #7a4b6b;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            background: #e0f2e9;
            border: 1px solid #b8e0d2;
            color: #1e7b5c;
        }

        .alert-error {
            background: #fde7e9;
            border: 1px solid #fbc1c6;
            color: #c44545;
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

        /* Print Modal */
        .print-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .print-modal-content {
            background: white;
            border-radius: 24px;
            padding: 40px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: slideUp 0.3s ease;
        }

        .print-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 40px;
        }

        .print-modal h3 {
            color: #6b2b5e;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .print-modal p {
            color: #7a4b6b;
            margin-bottom: 25px;
        }

        .print-buttons {
            display: flex;
            gap: 15px;
        }

        .print-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .print-btn-primary {
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            color: white;
        }

        .print-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(107, 43, 94, 0.3);
        }

        .print-btn-secondary {
            background: #f0e2ea;
            color: #6b2b5e;
            border: 1px solid #e9d0df;
        }

        .print-btn-secondary:hover {
            background: #e9d0df;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Main Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title svg {
            width: 24px;
            height: 24px;
            color: #a14a76;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-approved {
            background: #d4edda;
            color: #1e7b5c;
        }

        .search-form {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #7a4b6b;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.95rem;
            border: 2px solid #e9d0df;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: white;
            color: #4a2e40;
        }

        .form-control:focus {
            outline: none;
            border-color: #a14a76;
            box-shadow: 0 0 0 3px rgba(161, 74, 118, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%237a4b6b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
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
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(107, 43, 94, 0.3);
        }

        .btn-secondary {
            background: #f0e2ea;
            color: #6b2b5e;
            border: 1px solid #e9d0df;
        }

        .btn-secondary:hover {
            background: #e9d0df;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        /* Form Cards */
        .form-card {
            background: #fdf8fa;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            border: 1px solid #e9d0df;
        }

        .form-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card-title svg {
            width: 20px;
            height: 20px;
            color: #a14a76;
        }

        /* Student Info Bar */
        .student-info-bar {
            background: #f0e2ea;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9d0df;
        }

        .student-avatar-sm {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 600;
            color: white;
        }

        .student-details h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 6px;
        }

        .student-details p {
            color: #7a4b6b;
            font-size: 0.95rem;
        }

        /* Recent Clearances Table */
        .recent-section {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-top: 30px;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #e9d0df;
            margin-top: 20px;
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
            color: #7a4b6b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e9d0df;
            background: #f0e2ea;
        }

        .data-table td {
            padding: 16px 12px;
            font-size: 0.9rem;
            color: #4a2e40;
            border-bottom: 1px solid #f0e2ea;
        }

        .data-table tr:hover td {
            background: #fdf8fa;
        }

        .clearance-code {
            font-weight: 600;
            color: #a14a76;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #7a4b6b;
        }

        .empty-state svg {
            width: 60px;
            height: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #a14a76;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
            color: #6b2b5e;
        }

        .empty-state small {
            font-size: 0.85rem;
            color: #7a4b6b;
        }

        .print-link {
            color: #a14a76;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }

        .print-link:hover {
            text-decoration: underline;
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
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .student-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }

            .print-buttons {
                flex-direction: column;
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
                    <h1>📋 Health Clearance & Certification</h1>
                    <p>Generate instant clearances for walk-in students. Print immediately.</p>
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
                        <div class="stat-icon">📋</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['clearances_today']; ?></h3>
                            <p>Clearances Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📄</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['certificates_today']; ?></h3>
                            <p>Certificates Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🔄</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['fit_to_return_today']; ?></h3>
                            <p>Fit-to-Return Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['active_clearances']; ?></h3>
                            <p>Active Clearances</p>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Search Card -->
                    <div class="search-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="M21 21L16.65 16.65"/>
                            </svg>
                            Find Student (Walk-in)
                        </div>
                        
                        <form method="GET" action="" class="search-form">
                            <div class="form-group">
                                <label for="student_id">Student ID / LRN</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       placeholder="Enter student ID" 
                                       value="<?php echo htmlspecialchars($student_id_search); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Find Student</button>
                        </form>

                        <?php if ($search_error): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #fde7e9; border-radius: 12px; color: #c44545; font-size: 0.9rem;">
                                <?php echo $search_error; ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 20px; padding: 15px; background: #f0e2ea; border-radius: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px; color: #6b2b5e;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="12" x2="12" y2="16"/>
                                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                                <span style="font-size: 0.9rem;">Clearances are generated immediately and ready for printing.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats Card -->
                    <div class="quick-stats-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                            Quick Actions
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div style="background: #f0e2ea; padding: 20px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: #6b2b5e;"><?php echo $stats['clearances_today']; ?></div>
                                <div style="font-size: 0.8rem; color: #7a4b6b;">Issued Today</div>
                            </div>
                            <div style="background: #f0e2ea; padding: 20px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: #6b2b5e;"><?php echo count($recent_clearances); ?></div>
                                <div style="font-size: 0.8rem; color: #7a4b6b;">Total Records</div>
                            </div>
                        </div>

                        <div style="margin-top: 10px;">
                            <div style="font-size: 0.9rem; font-weight: 600; color: #6b2b5e; margin-bottom: 10px;">
                                Generate:
                            </div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"clearance\"]').click()">+ Clearance</button>
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"certificate\"]').click()">+ Certificate</button>
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"fitreturn\"]').click()">+ Fit-to-Return</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($student_data): ?>
                <!-- Student Info Bar -->
                <div class="student-info-bar">
                    <div class="student-avatar-sm">
                        <?php echo strtoupper(substr($student_data['full_name'] ?? 'NA', 0, 2)); ?>
                    </div>
                    <div class="student-details">
                        <h3><?php echo htmlspecialchars($student_data['full_name'] ?? 'N/A'); ?></h3>
                        <p>
                            Student ID: <?php echo htmlspecialchars($student_data['student_id']); ?> | 
                            Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - 
                            <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>
                        </p>
                        <?php if (!empty($student_data['medical_conditions'])): ?>
                            <p style="margin-top: 8px;">
                                <span class="status-badge status-approved">⚕️ <?php echo htmlspecialchars($student_data['medical_conditions']); ?></span>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" data-tab="clearance" onclick="showTab('clearance', event)">📋 Generate Clearance</button>
                        <button class="tab-btn" data-tab="certificate" onclick="showTab('certificate', event)">📄 Issue Certificate</button>
                        <button class="tab-btn" data-tab="fitreturn" onclick="showTab('fitreturn', event)">🔄 Fit-to-Return</button>
                    </div>

                    <div class="tab-content">
                        <!-- Create Clearance Tab (Auto-print) -->
                        <div class="tab-pane active" id="clearance">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Generate Instant Clearance
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_clearance">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Clearance Type</label>
                                            <select name="clearance_type" class="form-control" required>
                                                <option value="">Select Type</option>
                                                <option value="Sports">Sports / Intramurals</option>
                                                <option value="Event">School Event</option>
                                                <option value="Work Immersion">Work Immersion</option>
                                                <option value="After Illness">After Illness</option>
                                                <option value="After Hospitalization">After Hospitalization</option>
                                                <option value="After Injury">After Injury</option>
                                                <option value="General">General Clearance</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Request Date</label>
                                            <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Purpose / Reason</label>
                                        <textarea name="purpose" class="form-control" placeholder="Why is this clearance needed?" required></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Valid Until (Optional)</label>
                                            <input type="date" name="valid_until" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                                            <small style="color: #7a4b6b; font-size: 0.7rem;">Default: 1 year validity</small>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 20px; padding: 15px; background: #f0e2ea; border-radius: 12px; margin-bottom: 20px;">
                                        <div style="display: flex; align-items: center; gap: 10px; color: #6b2b5e;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                                <path d="M6 9L12 15L18 9"/>
                                            </svg>
                                            <span style="font-size: 0.9rem;">Clearance will be generated as PDF immediately after submission.</span>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Generate Clearance PDF</button>
                                </form>
                            </div>
                        </div>

                        <!-- Issue Certificate Tab -->
                        <div class="tab-pane" id="certificate">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                        <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                    </svg>
                                    Issue Medical Certificate
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="issue_certificate">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Certificate Type</label>
                                            <select name="certificate_type" class="form-control" required>
                                                <option value="">Select Type</option>
                                                <option value="Fit to Return">Fit to Return to Class</option>
                                                <option value="Fit for PE">Fit for PE Participation</option>
                                                <option value="Fit for Activities">Fit for School Activities</option>
                                                <option value="Medical Leave">Medical Leave</option>
                                                <option value="General">General Medical Certificate</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Issue Date</label>
                                            <input type="date" name="issued_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Valid Until (Optional)</label>
                                            <input type="date" name="valid_until" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Findings / Assessment</label>
                                        <textarea name="findings" class="form-control" placeholder="Clinical findings..." required></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Recommendations</label>
                                        <textarea name="recommendations" class="form-control" placeholder="Recommendations for student..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Restrictions (if any)</label>
                                        <input type="text" name="restrictions" class="form-control" placeholder="e.g., No strenuous activities for 1 week">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Additional Remarks</label>
                                        <textarea name="remarks" class="form-control" placeholder="Any additional notes..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Generate Certificate PDF</button>
                                </form>
                            </div>
                        </div>

                        <!-- Fit-to-Return Tab -->
                        <div class="tab-pane" id="fitreturn">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 12H4M12 4v16"/>
                                    </svg>
                                    Create Fit-to-Return Slip
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_fit_to_return">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Assessment Date</label>
                                            <input type="date" name="assessment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Absence Days</label>
                                            <input type="number" name="absence_days" class="form-control" min="0" placeholder="Number of days absent">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Reason for Absence</label>
                                        <textarea name="absence_reason" class="form-control" placeholder="Why was the student absent?"></textarea>
                                    </div>
                                    
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <label>Temperature (°C)</label>
                                            <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                        </div>
                                        <div class="form-group">
                                            <label>Blood Pressure</label>
                                            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                        </div>
                                        <div class="form-group">
                                            <label>Heart Rate</label>
                                            <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Findings</label>
                                        <textarea name="findings" class="form-control" placeholder="Assessment findings..." required></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Fit to Return</label>
                                            <select name="fit_to_return" class="form-control" required>
                                                <option value="Yes">Yes - Cleared to return</option>
                                                <option value="With Restrictions">With Restrictions</option>
                                                <option value="No">No - Not cleared</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Recommended Rest Days</label>
                                            <input type="number" name="recommended_rest_days" class="form-control" min="0" placeholder="If not cleared">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Restrictions</label>
                                        <input type="text" name="restrictions" class="form-control" placeholder="e.g., No PE for 3 days">
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Next Check-up Date</label>
                                            <input type="date" name="next_checkup_date" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Generate Fit-to-Return PDF</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Clearances -->
                <?php if (!empty($recent_clearances)): ?>
                <div class="recent-section">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z"/>
                            <path d="M14 2V8H20"/>
                        </svg>
                        Recently Generated Clearances
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Clearance Code</th>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Valid Until</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_clearances as $clearance): ?>
                                    <tr>
                                        <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($clearance['student_name']); ?></strong><br>
                                            <small><?php echo $clearance['student_id']; ?></small>
                                        </td>
                                        <td><?php echo $clearance['clearance_type']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($clearance['request_date'])); ?></td>
                                        <td>
                                            <?php echo !empty($clearance['valid_until']) ? date('M d, Y', strtotime($clearance['valid_until'])) : 'No expiry'; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-approved"><?php echo $clearance['status']; ?></span>
                                        </td>
                                        <td>
                                            <a href="generate_clearance.php?id=<?php echo $clearance['id']; ?>" target="_blank" class="print-link">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                                    <path d="M6 18L18 18"/>
                                                    <path d="M6 14L18 14"/>
                                                    <path d="M4 6L20 6"/>
                                                    <path d="M4 10L20 10"/>
                                                    <path d="M4 6V18"/>
                                                    <path d="M20 6V18"/>
                                                </svg>
                                                Print Again
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Health Clearance';
        }
    </script>
</body>
</html>