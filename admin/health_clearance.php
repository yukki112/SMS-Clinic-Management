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
$generated_pdf = '';
$show_verification_modal = false;

// Check if verification was completed
if (isset($_SESSION['verified_student_id_clearance']) && $_SESSION['verified_student_id_clearance'] === $student_id_search) {
    $show_verification_modal = false;
} elseif (!empty($student_id_search) && !isset($_POST['action'])) {
    $show_verification_modal = true;
}

// Handle verification submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_access'])) {
    $user_id = $_SESSION['user_id'];
    $password = $_POST['password'];
    
    // Verify password
    $query = "SELECT password FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['verified_student_id_clearance'] = $_POST['student_id'];
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($_POST['student_id']));
        exit();
    } else {
        $verification_error = "Invalid password. Access denied.";
        $show_verification_modal = true;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Create clearance request (PENDING - not auto-approved)
    if ($_POST['action'] == 'create_clearance') {
        try {
            // Generate clearance code
            $prefix = 'CLR';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $clearance_code = $prefix . '-' . $date . '-' . $random;
            
            $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
            $current_date = date('Y-m-d');
            
            // Insert as PENDING (waiting for approval)
            $query = "INSERT INTO clearance_requests (
                clearance_code, student_id, student_name, grade_section,
                clearance_type, purpose, request_date, status, 
                created_by, valid_until
            ) VALUES (
                :clearance_code, :student_id, :student_name, :grade_section,
                :clearance_type, :purpose, :request_date, 'Pending', 
                :created_by, :valid_until
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':clearance_code', $clearance_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':clearance_type', $_POST['clearance_type']);
            $stmt->bindParam(':purpose', $_POST['purpose']);
            $stmt->bindParam(':request_date', $_POST['request_date']);
            $stmt->bindParam(':created_by', $current_user_id);
            $stmt->bindParam(':valid_until', $valid_until);
            
            if ($stmt->execute()) {
                $clearance_id = $db->lastInsertId();
                $success_message = "Clearance request created successfully! Code: " . $clearance_code . " (Pending Approval)";
            } else {
                $error_message = "Error creating clearance request.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // APPROVE clearance request
    if ($_POST['action'] == 'approve_clearance') {
        try {
            $clearance_id = $_POST['clearance_id'];
            $approved_date = date('Y-m-d');
            
            $query = "UPDATE clearance_requests 
                      SET status = 'Approved', 
                          approved_date = :approved_date, 
                          approved_by = :approved_by
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $_POST['status']);
            $stmt->bindParam(':approved_date', $approved_date);
            $stmt->bindParam(':approved_by', $current_user_fullname);
            $stmt->bindParam(':id', $clearance_id);
            
            if ($stmt->execute()) {
                $success_message = "Clearance approved successfully! PDF is now available.";
            } else {
                $error_message = "Error approving clearance.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // DECLINE clearance request
    if ($_POST['action'] == 'decline_clearance') {
        try {
            $clearance_id = $_POST['clearance_id'];
            $remarks = $_POST['decline_remarks'] ?? 'Request declined';
            
            $query = "UPDATE clearance_requests 
                      SET status = 'Not Cleared', 
                          remarks = :remarks
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':remarks', $remarks);
            $stmt->bindParam(':id', $clearance_id);
            
            if ($stmt->execute()) {
                $success_message = "Clearance request declined.";
            } else {
                $error_message = "Error declining clearance.";
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
                
                // Automatically generate PDF if requested
                if (isset($_POST['generate_pdf']) && $_POST['generate_pdf'] == 'yes') {
                    $generated_pdf = 'generate_certificate.php?id=' . $certificate_id;
                    $success_message .= ' <a href="' . $generated_pdf . '" target="_blank" style="color: white; background: #24248f; padding: 5px 10px; border-radius: 5px; text-decoration: none; margin-left: 10px;">📄 Download Certificate</a>';
                }
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
                
                // Automatically generate PDF if requested
                if (isset($_POST['generate_pdf']) && $_POST['generate_pdf'] == 'yes') {
                    $generated_pdf = 'generate_fit_to_return.php?id=' . $slip_id;
                    $success_message .= ' <a href="' . $generated_pdf . '" target="_blank" style="color: white; background: #24248f; padding: 5px 10px; border-radius: 5px; text-decoration: none; margin-left: 10px;">📄 Download Slip</a>';
                }
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
    // Today's approved clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests WHERE DATE(approved_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_clearances'] = $result ? $result['total'] : 0;
    
    // Pending clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_clearances'] = $result ? $result['total'] : 0;
    
    // Approved clearances this month
    $query = "SELECT COUNT(*) as total FROM clearance_requests 
              WHERE status = 'Approved' AND MONTH(approved_date) = MONTH(CURDATE())";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['approved_this_month'] = $result ? $result['total'] : 0;
    
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
    
    // Expiring soon clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests 
              WHERE valid_until IS NOT NULL 
              AND valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['expiring_soon'] = $result ? $result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = [
        'today_clearances' => 0,
        'pending_clearances' => 0,
        'approved_this_month' => 0,
        'certificates_today' => 0,
        'fit_to_return_today' => 0,
        'expiring_soon' => 0
    ];
}

// Get all clearance requests
function getClearanceRequests($db, $status = null) {
    try {
        $query = "SELECT * FROM clearance_requests";
        if ($status) {
            $query .= " WHERE status = :status";
        }
        $query .= " ORDER BY request_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Get medical certificates
function getMedicalCertificates($db, $student_id = null) {
    try {
        $query = "SELECT * FROM medical_certificates";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY issued_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($student_id) {
            $stmt->bindParam(':student_id', $student_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Get fit-to-return slips
function getFitToReturnSlips($db, $student_id = null) {
    try {
        $query = "SELECT * FROM fit_to_return_slips";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY assessment_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($student_id) {
            $stmt->bindParam(':student_id', $student_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$pending_clearances = getClearanceRequests($db, 'Pending');
$approved_clearances = getClearanceRequests($db, 'Approved');
$all_certificates = getMedicalCertificates($db);
$all_fit_to_return = getFitToReturnSlips($db);

// Search for student if ID provided and verified
if (!empty($student_id_search) && isset($_SESSION['verified_student_id_clearance']) && $_SESSION['verified_student_id_clearance'] === $student_id_search && !isset($_POST['action'])) {
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
                    
                    // Get student's clearance history
                    $student_data['clearances'] = getClearanceRequests($db);
                    $student_data['certificates'] = getMedicalCertificates($db, $student_id_search);
                    $student_data['fit_to_return'] = getFitToReturnSlips($db, $student_id_search);
                    break;
                }
            }
            
            if (!$found) {
                $search_error = "Student ID not found in the system.";
                unset($_SESSION['verified_student_id_clearance']);
            }
        } else {
            $search_error = "Unable to fetch student data.";
            unset($_SESSION['verified_student_id_clearance']);
        }
    } else {
        $search_error = "Error connecting to student database.";
        unset($_SESSION['verified_student_id_clearance']);
    }
} elseif (!empty($student_id_search) && (!isset($_SESSION['verified_student_id_clearance']) || $_SESSION['verified_student_id_clearance'] !== $student_id_search)) {
    $show_verification_modal = true;
}

// Clear verification if no student ID
if (empty($student_id_search) && isset($_SESSION['verified_student_id_clearance'])) {
    unset($_SESSION['verified_student_id_clearance']);
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

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .modal-container {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 450px;
            padding: 30px;
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.2);
            animation: slideUp 0.3s ease;
        }

        .modal-icon {
            width: 70px;
            height: 70px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
        }

        .modal-icon svg {
            width: 35px;
            height: 35px;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            text-align: center;
            margin-bottom: 10px;
        }

        .modal-subtitle {
            color: #546e7a;
            text-align: center;
            margin-bottom: 25px;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .modal-form {
            margin-top: 20px;
        }

        .modal-form .form-group {
            margin-bottom: 20px;
        }

        .modal-form label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #546e7a;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-form .form-control {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            border: 2px solid #cfd8dc;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .modal-form .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .modal-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-btn.primary {
            background: #191970;
            color: white;
        }

        .modal-btn.primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .modal-btn.secondary {
            background: #eceff1;
            color: #37474f;
            border: 1px solid #cfd8dc;
        }

        .modal-btn.secondary:hover {
            background: #cfd8dc;
        }

        .modal-error {
            background: #ffebee;
            border: 1px solid #ffcdd2;
            border-radius: 12px;
            padding: 12px 16px;
            color: #c62828;
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
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
            width: 50px;
            height: 50px;
            background: #191970;
            border-radius: 12px;
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
            color: #191970;
            margin-bottom: 2px;
        }

        .stat-info p {
            color: #546e7a;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .warning-badge {
            background: #ffebee;
            color: #c62828;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 4px;
        }

        .pending-badge-count {
            background: #fff3cd;
            color: #856404;
            padding: 2px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
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

        .alert-success a {
            color: white;
            background: #24248f;
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
            font-weight: 500;
        }

        .alert-success a:hover {
            background: #191970;
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
            gap: 10px;
        }

        .card-title svg {
            width: 24px;
            height: 24px;
            color: #191970;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-not-cleared {
            background: #ffebee;
            color: #c62828;
        }

        .status-expired {
            background: #eceff1;
            color: #78909c;
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

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
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
            width: 100%;
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .btn-success {
            background: #2e7d32;
            color: white;
        }

        .btn-success:hover {
            background: #1b5e20;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
        }

        .btn-danger {
            background: #c62828;
            color: white;
        }

        .btn-danger:hover {
            background: #b71c1c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(198, 40, 40, 0.2);
        }

        .btn-secondary {
            background: #eceff1;
            color: #37474f;
            border: 1px solid #cfd8dc;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        .btn-icon {
            padding: 8px 12px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .pending-list {
            max-height: 250px;
            overflow-y: auto;
        }

        .pending-item {
            padding: 12px 0;
            border-bottom: 1px solid #eceff1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pending-item:last-child {
            border-bottom: none;
        }

        .pending-info {
            flex: 1;
        }

        .pending-name {
            font-weight: 600;
            color: #191970;
            font-size: 0.9rem;
        }

        .pending-meta {
            font-size: 0.7rem;
            color: #546e7a;
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }

        .pending-badge {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .action-icons {
            display: flex;
            gap: 8px;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .action-icon.approve {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .action-icon.approve:hover {
            background: #2e7d32;
            color: white;
        }

        .action-icon.decline {
            background: #ffebee;
            color: #c62828;
        }

        .action-icon.decline:hover {
            background: #c62828;
            color: white;
        }

        .action-icon.view {
            background: #e3f2fd;
            color: #1565c0;
        }

        .action-icon.view:hover {
            background: #1565c0;
            color: white;
        }

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            overflow: hidden;
            margin-bottom: 30px;
            animation: fadeInUp 0.8s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid #eceff1;
            background: #f5f5f5;
            overflow-x: auto;
            padding: 0 20px;
        }

        .tab-btn {
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 0.95rem;
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

        /* Form Cards */
        .form-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            border: 1px solid #cfd8dc;
        }

        .form-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card-title svg {
            width: 20px;
            height: 20px;
            color: #191970;
        }

        /* Student Info Bar */
        .student-info-bar {
            background: #eceff1;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #cfd8dc;
        }

        .student-avatar-sm {
            width: 70px;
            height: 70px;
            background: #191970;
            border-radius: 12px;
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
            color: #191970;
            margin-bottom: 6px;
        }

        .student-details p {
            color: #546e7a;
            font-size: 0.95rem;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
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
            border-bottom: 1px solid #eceff1;
        }

        .data-table tr:hover td {
            background: #f5f5f5;
        }

        .clearance-code {
            font-weight: 600;
            color: #191970;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pdf-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #eceff1;
            color: #191970;
            padding: 4px 8px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .pdf-link:hover {
            background: #191970;
            color: white;
        }

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
            color: #90a4ae;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
            color: #37474f;
        }

        .empty-state small {
            font-size: 0.85rem;
            color: #78909c;
        }

        /* PDF Options */
        .pdf-option {
            margin-top: 15px;
            padding: 15px;
            background: #eceff1;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #191970;
        }

        .info-box {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            color: #1565c0;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box.warning {
            background: #fff3cd;
            color: #856404;
        }

        .decline-form {
            margin-top: 15px;
            padding: 15px;
            background: #ffebee;
            border-radius: 12px;
            display: none;
        }

        .decline-form.active {
            display: block;
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
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .student-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
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
                    <p>Manage clearance requests, approve/decline, and issue certificates.</p>
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
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_clearances']; ?></h3>
                            <p>Today's Clearances</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_clearances']; ?></h3>
                            <p>Pending Requests</p>
                            <?php if ($stats['pending_clearances'] > 0): ?>
                                <span class="pending-badge-count">Awaiting approval</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['approved_this_month']; ?></h3>
                            <p>This Month</p>
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
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['expiring_soon']; ?></h3>
                            <p>Expiring Soon</p>
                            <?php if ($stats['expiring_soon'] > 0): ?>
                                <div class="warning-badge">Within 7 days</div>
                            <?php endif; ?>
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
                            Find Student
                        </div>
                        
                        <form method="GET" action="" class="search-form">
                            <div class="form-group">
                                <label for="student_id">Student ID</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       placeholder="Enter student ID" 
                                       value="<?php echo htmlspecialchars($student_id_search); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Search Student</button>
                        </form>

                        <?php if ($search_error): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #ffebee; border-radius: 12px; color: #c62828; font-size: 0.9rem;">
                                <?php echo $search_error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Pending Clearances List -->
                        <?php if (!empty($pending_clearances)): ?>
                            <div style="margin-top: 24px;">
                                <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                    ⏳ Pending Approval (<?php echo count($pending_clearances); ?>)
                                </div>
                                <div class="pending-list">
                                    <?php foreach (array_slice($pending_clearances, 0, 5) as $clearance): ?>
                                        <div class="pending-item">
                                            <div class="pending-info">
                                                <div class="pending-name"><?php echo htmlspecialchars($clearance['student_name']); ?></div>
                                                <div class="pending-meta">
                                                    <span><?php echo $clearance['clearance_type']; ?></span>
                                                    <span><?php echo date('M d', strtotime($clearance['request_date'])); ?></span>
                                                </div>
                                            </div>
                                            <span class="status-badge status-pending">Pending</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Stats Card -->
                    <div class="quick-stats-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                            Clearance Overview
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                            <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 700; color: #191970;"><?php echo count($approved_clearances); ?></div>
                                <div style="font-size: 0.8rem; color: #546e7a;">Total Approved</div>
                            </div>
                            <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 700; color: #191970;"><?php echo count($all_certificates); ?></div>
                                <div style="font-size: 0.8rem; color: #546e7a;">Certificates</div>
                            </div>
                        </div>

                        <?php if (!empty($pending_clearances)): ?>
                            <div class="info-box warning" style="margin-top: 10px;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <span><?php echo count($pending_clearances); ?> clearance request(s) pending approval</span>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                Quick Actions
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
                                <span class="status-badge status-not-cleared">⚕️ <?php echo htmlspecialchars($student_data['medical_conditions']); ?></span>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" data-tab="clearance" onclick="showTab('clearance', event)">📋 Request Clearance</button>
                        <button class="tab-btn" data-tab="certificate" onclick="showTab('certificate', event)">📄 Issue Certificate</button>
                        <button class="tab-btn" data-tab="fitreturn" onclick="showTab('fitreturn', event)">🔄 Fit-to-Return</button>
                        <button class="tab-btn" data-tab="history" onclick="showTab('history', event)">📚 History</button>
                    </div>

                    <div class="tab-content">
                        <!-- Request Clearance Tab (Pending) -->
                        <div class="tab-pane active" id="clearance">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Request Clearance (Pending Approval)
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
                                                <option value="Work Immersion">Work Immersion / OJT</option>
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
                                            <input type="date" name="valid_until" class="form-control">
                                            <small style="color: #546e7a; font-size: 0.7rem;">Leave empty if no expiry</small>
                                        </div>
                                    </div>
                                    
                                    <div class="info-box warning">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        <span>Clearance will be created as PENDING and requires approval before issuance.</span>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Submit Clearance Request</button>
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
                                    
                                    <div class="pdf-option">
                                        <div class="checkbox-group">
                                            <input type="checkbox" name="generate_pdf" id="generate_pdf_cert" value="yes" checked>
                                            <label for="generate_pdf_cert">Generate PDF certificate immediately</label>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Issue Certificate</button>
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
                                    
                                    <div class="pdf-option">
                                        <div class="checkbox-group">
                                            <input type="checkbox" name="generate_pdf" id="generate_pdf_ftr" value="yes" checked>
                                            <label for="generate_pdf_ftr">Generate PDF fit-to-return slip</label>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Create Fit-to-Return Slip</button>
                                </form>
                            </div>
                        </div>

                        <!-- History Tab -->
                        <div class="tab-pane" id="history">
                            <div class="form-card-title" style="margin-bottom: 20px;">
                                📚 Clearance History for <?php echo htmlspecialchars($student_data['full_name']); ?>
                            </div>
                            
                            <!-- Clearance Requests -->
                            <h3 style="color: #191970; margin: 20px 0 10px; font-size: 1rem;">Clearance Requests</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th>Request Date</th>
                                            <th>Purpose</th>
                                            <th>Status</th>
                                            <th>Approved Date</th>
                                            <th>Valid Until</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $student_clearances = getClearanceRequests($db);
                                        $student_clearances = array_filter($student_clearances, function($c) use ($student_data) {
                                            return $c['student_id'] == $student_data['student_id'];
                                        });
                                        ?>
                                        <?php if (!empty($student_clearances)): ?>
                                            <?php foreach ($student_clearances as $clearance): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                    <td><?php echo $clearance['clearance_type']; ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($clearance['request_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($clearance['purpose'], 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($clearance['status']); ?>">
                                                            <?php echo $clearance['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($clearance['approved_date']) ? date('M d, Y', strtotime($clearance['approved_date'])) : '-'; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($clearance['valid_until']) ? date('M d, Y', strtotime($clearance['valid_until'])) : 'No expiry'; ?>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <?php if ($clearance['status'] == 'Approved'): ?>
                                                                <a href="generate_clearance.php?id=<?php echo $clearance['id']; ?>" target="_blank" class="pdf-link">
                                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                                        <polyline points="14 2 14 8 20 8"/>
                                                                        <line x1="16" y1="13" x2="8" y2="13"/>
                                                                        <line x1="16" y1="17" x2="8" y2="17"/>
                                                                    </svg>
                                                                    PDF
                                                                </a>
                                                            <?php elseif ($clearance['status'] == 'Pending'): ?>
                                                                <span style="color: #856404; font-size: 0.7rem;">Awaiting approval</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="empty-state">
                                                    <p>No clearance requests found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Medical Certificates -->
                            <h3 style="color: #191970; margin: 30px 0 10px; font-size: 1rem;">Medical Certificates</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th>Issue Date</th>
                                            <th>Findings</th>
                                            <th>Valid Until</th>
                                            <th>Issued By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['certificates'])): ?>
                                            <?php foreach ($student_data['certificates'] as $cert): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($cert['certificate_code']); ?></span></td>
                                                    <td><?php echo $cert['certificate_type']; ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($cert['issued_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($cert['findings'], 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <?php echo !empty($cert['valid_until']) ? date('M d, Y', strtotime($cert['valid_until'])) : 'No expiry'; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($cert['issued_by']); ?></td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="generate_certificate.php?id=<?php echo $cert['id']; ?>" target="_blank" class="pdf-link">
                                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                                    <polyline points="14 2 14 8 20 8"/>
                                                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                                                </svg>
                                                                PDF
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="empty-state">
                                                    <p>No medical certificates found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Fit-to-Return Slips -->
                            <h3 style="color: #191970; margin: 30px 0 10px; font-size: 1rem;">Fit-to-Return Slips</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Assessment Date</th>
                                            <th>Findings</th>
                                            <th>Fit to Return</th>
                                            <th>Restrictions</th>
                                            <th>Issued By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['fit_to_return'])): ?>
                                            <?php foreach ($student_data['fit_to_return'] as $slip): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($slip['slip_code']); ?></span></td>
                                                    <td><?php echo date('M d, Y', strtotime($slip['assessment_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($slip['findings'], 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($slip['fit_to_return']); ?>">
                                                            <?php echo $slip['fit_to_return']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($slip['restrictions'] ?: 'None'); ?></td>
                                                    <td><?php echo htmlspecialchars($slip['issued_by']); ?></td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="generate_fit_to_return.php?id=<?php echo $slip['id']; ?>" target="_blank" class="pdf-link">
                                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                                                    <polyline points="14 2 14 8 20 8"/>
                                                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                                                </svg>
                                                                PDF
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="empty-state">
                                                    <p>No fit-to-return slips found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pending Clearances Management Section -->
                <?php if (!empty($pending_clearances)): ?>
                <div class="tabs-section" style="margin-top: 20px;">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showAllTab('pending', event)">⏳ Pending Approvals (<?php echo count($pending_clearances); ?>)</button>
                        <button class="tab-btn" onclick="showAllTab('approved', event)">✅ Recent Clearances</button>
                        <button class="tab-btn" onclick="showAllTab('certificates', event)">📄 Recent Certificates</button>
                    </div>

                    <div class="tab-content">
                        <!-- Pending Clearances Tab -->
                        <div class="tab-pane active" id="all-pending">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Request Date</th>
                                            <th>Purpose</th>
                                            <th>Requested By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_clearances as $clearance): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($clearance['student_name']); ?></strong><br>
                                                    <small><?php echo $clearance['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $clearance['clearance_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($clearance['request_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($clearance['purpose'], 0, 40)) . '...'; ?></td>
                                                <td>
                                                    <?php 
                                                    $creator_query = "SELECT full_name FROM users WHERE id = :id";
                                                    $creator_stmt = $db->prepare($creator_query);
                                                    $creator_stmt->bindParam(':id', $clearance['created_by']);
                                                    $creator_stmt->execute();
                                                    $creator = $creator_stmt->fetch(PDO::FETCH_ASSOC);
                                                    echo $creator ? htmlspecialchars($creator['full_name']) : 'Clinic Staff';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-icons">
                                                        <!-- Approve Button -->
                                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Approve this clearance request? PDF will be generated.');">
                                                            <input type="hidden" name="action" value="approve_clearance">
                                                            <input type="hidden" name="clearance_id" value="<?php echo $clearance['id']; ?>">
                                                            <button type="submit" class="action-icon approve" title="Approve">
                                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                                                    <path d="M20 6L9 17L4 12"/>
                                                                </svg>
                                                            </button>
                                                        </form>
                                                        
                                                        <!-- Decline Button -->
                                                        <button type="button" class="action-icon decline" title="Decline" onclick="showDeclineForm(<?php echo $clearance['id']; ?>)">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                                                <line x1="18" y1="6" x2="6" y2="18"/>
                                                                <line x1="6" y1="6" x2="18" y2="18"/>
                                                            </svg>
                                                        </button>
                                                        
                                                        <!-- View Details -->
                                                        <button type="button" class="action-icon view" title="View Details" onclick="viewClearanceDetails(<?php echo htmlspecialchars(json_encode($clearance)); ?>)">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                                                <circle cx="12" cy="12" r="3"/>
                                                                <path d="M22 12c-2.667 4.667-6 7-10 7s-7.333-2.333-10-7c2.667-4.667 6-7 10-7s7.333 2.333 10 7z"/>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                    
                                                    <!-- Decline Form (Hidden) -->
                                                    <div id="decline-form-<?php echo $clearance['id']; ?>" class="decline-form">
                                                        <form method="POST" action="" onsubmit="return confirm('Decline this clearance request?');">
                                                            <input type="hidden" name="action" value="decline_clearance">
                                                            <input type="hidden" name="clearance_id" value="<?php echo $clearance['id']; ?>">
                                                            <div class="form-group">
                                                                <label style="font-size: 0.7rem;">Reason for declining:</label>
                                                                <textarea name="decline_remarks" class="form-control" style="padding: 8px; font-size: 0.8rem;" required placeholder="Enter reason..."></textarea>
                                                            </div>
                                                            <div style="display: flex; gap: 8px; margin-top: 8px;">
                                                                <button type="submit" class="btn btn-danger btn-sm" style="padding: 6px 12px;">Confirm Decline</button>
                                                                <button type="button" class="btn btn-secondary btn-sm" onclick="hideDeclineForm(<?php echo $clearance['id']; ?>)">Cancel</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Approved Clearances Tab -->
                        <div class="tab-pane" id="all-approved">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Approved Date</th>
                                            <th>Purpose</th>
                                            <th>Valid Until</th>
                                            <th>Approved By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($approved_clearances, 0, 20) as $clearance): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($clearance['student_name']); ?></strong><br>
                                                    <small><?php echo $clearance['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $clearance['clearance_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($clearance['approved_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($clearance['purpose'], 0, 30)) . '...'; ?></td>
                                                <td><?php echo !empty($clearance['valid_until']) ? date('M d, Y', strtotime($clearance['valid_until'])) : 'No expiry'; ?></td>
                                                <td><?php echo htmlspecialchars($clearance['approved_by'] ?: 'N/A'); ?></td>
                                                <td>
                                                    <a href="generate_clearance.php?id=<?php echo $clearance['id']; ?>" target="_blank" class="btn btn-sm btn-secondary" style="text-decoration: none; padding: 4px 8px;">📄 PDF</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Certificates Tab -->
                        <div class="tab-pane" id="all-certificates">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Issue Date</th>
                                            <th>Issued By</th>
                                            <th>Valid Until</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($all_certificates, 0, 20) as $cert): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($cert['certificate_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($cert['student_name']); ?></strong><br>
                                                    <small><?php echo $cert['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $cert['certificate_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($cert['issued_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($cert['issued_by']); ?></td>
                                                <td><?php echo !empty($cert['valid_until']) ? date('M d, Y', strtotime($cert['valid_until'])) : 'No expiry'; ?></td>
                                                <td>
                                                    <a href="generate_certificate.php?id=<?php echo $cert['id']; ?>" target="_blank" class="btn btn-sm btn-secondary" style="text-decoration: none; padding: 4px 8px;">📄 PDF</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Security Verification Modal -->
    <?php if ($show_verification_modal && !empty($student_id_search)): ?>
    <div class="modal-overlay" id="verificationModal">
        <div class="modal-container">
            <div class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h2 class="modal-title">Secure Access Required</h2>
            <p class="modal-subtitle">
                You are accessing confidential clearance records for<br>
                <strong>Student ID: <?php echo htmlspecialchars($student_id_search); ?></strong>
            </p>
            
            <?php if (isset($verification_error)): ?>
                <div class="modal-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?php echo $verification_error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="modal-form">
                <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_id_search); ?>">
                <div class="form-group">
                    <label for="password">Enter Your Password to Continue</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="••••••••" required autofocus>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn secondary" onclick="cancelAccess()">Cancel</button>
                    <button type="submit" name="verify_access" class="modal-btn primary">Verify & Access</button>
                </div>
            </form>
            <p style="text-align: center; margin-top: 20px; font-size: 0.8rem; color: #546e7a;">
                This helps us maintain confidentiality of student clearance records
            </p>
        </div>
    </div>
    
    <script>
        // Prevent background scrolling when modal is open
        document.body.style.overflow = 'hidden';
        
        function cancelAccess() {
            window.location.href = window.location.pathname; // Redirect to same page without query string
        }
        
        // Close modal when clicking outside
        document.getElementById('verificationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                cancelAccess();
            }
        });
    </script>
    <?php endif; ?>

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
                if (pane.id !== 'all-pending' && pane.id !== 'all-approved' && pane.id !== 'all-certificates') {
                    pane.classList.remove('active');
                }
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // All tabs functionality
        function showAllTab(tabName, event) {
            document.querySelectorAll('#all-pending, #all-approved, #all-certificates').forEach(pane => {
                pane.classList.remove('active');
            });
            document.querySelectorAll('.tabs-section .tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById('all-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Decline form functions
        function showDeclineForm(id) {
            document.querySelectorAll('.decline-form').forEach(form => {
                form.classList.remove('active');
            });
            document.getElementById('decline-form-' + id).classList.add('active');
        }

        function hideDeclineForm(id) {
            document.getElementById('decline-form-' + id).classList.remove('active');
        }

        // View clearance details
        function viewClearanceDetails(clearance) {
            alert(`Student: ${clearance.student_name}\nType: ${clearance.clearance_type}\nPurpose: ${clearance.purpose}\nRequest Date: ${clearance.request_date}`);
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