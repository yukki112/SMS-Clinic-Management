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

// Initialize variables
$student_data = null;
$search_error = '';
$student_id_search = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$show_verification_modal = false;

// Check if verification was completed
if (isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search) {
    $show_verification_modal = false;
} elseif (!empty($student_id_search)) {
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
        $_SESSION['verified_student_id'] = $_POST['student_id'];
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($_POST['student_id']));
        exit();
    } else {
        $verification_error = "Invalid password. Access denied.";
        $show_verification_modal = true;
    }
}

// Initialize stats with default values
$stats = [
    'total_students_visited' => 0,
    'today_visits' => 0
];

// Get statistics with error handling
try {
    // Check if tables exist before querying
    $tables = ['visit_history', 'incident_history', 'clearance_history'];
    foreach ($tables as $table) {
        $check_query = "SHOW TABLES LIKE '$table'";
        $check_stmt = $db->query($check_query);
        if ($check_stmt->rowCount() == 0) {
            // Table doesn't exist, create it
            if ($table == 'visit_history') {
                $db->exec("CREATE TABLE IF NOT EXISTS `visit_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `student_id` varchar(20) NOT NULL,
                    `visit_date` date NOT NULL,
                    `visit_time` time NOT NULL,
                    `complaint` text NOT NULL,
                    `temperature` decimal(4,2) DEFAULT NULL,
                    `blood_pressure` varchar(10) DEFAULT NULL,
                    `heart_rate` int(11) DEFAULT NULL,
                    `treatment_given` text DEFAULT NULL,
                    `disposition` enum('Sent Home','Referred','Admitted','Cleared') DEFAULT 'Sent Home',
                    `attended_by` int(11) DEFAULT NULL,
                    `notes` text DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `student_id` (`student_id`),
                    KEY `attended_by` (`attended_by`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            } elseif ($table == 'incident_history') {
                $db->exec("CREATE TABLE IF NOT EXISTS `incident_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `student_id` varchar(20) NOT NULL,
                    `incident_date` date NOT NULL,
                    `incident_type` varchar(100) NOT NULL,
                    `description` text NOT NULL,
                    `action_taken` text DEFAULT NULL,
                    `reported_by` varchar(100) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `student_id` (`student_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            } elseif ($table == 'clearance_history') {
                $db->exec("CREATE TABLE IF NOT EXISTS `clearance_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `student_id` varchar(20) NOT NULL,
                    `clearance_date` date NOT NULL,
                    `clearance_type` varchar(100) NOT NULL,
                    `purpose` text DEFAULT NULL,
                    `valid_until` date DEFAULT NULL,
                    `issued_by` varchar(100) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `student_id` (`student_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            }
        }
    }

    // Now safely query the tables
    $query = "SELECT COUNT(DISTINCT student_id) as total FROM visit_history";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_students_visited'] = $result ? $result['total'] : 0;

    $query = "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_visits'] = $result ? $result['total'] : 0;

} catch (PDOException $e) {
    // Log error but don't display to user
    error_log("Database error: " . $e->getMessage());
    $stats = [
        'total_students_visited' => 0,
        'today_visits' => 0
    ];
}

// Function to fetch clinic visit history
function getClinicVisits($db, $student_id) {
    try {
        $query = "SELECT v.*, u.full_name as attended_by_name 
                  FROM visit_history v
                  LEFT JOIN users u ON v.attended_by = u.id
                  WHERE v.student_id = :student_id 
                  ORDER BY v.visit_date DESC, v.visit_time DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching visits: " . $e->getMessage());
        return [];
    }
}

// Function to fetch incident history
function getIncidentHistory($db, $student_id) {
    try {
        $query = "SELECT * FROM incident_history 
                  WHERE student_id = :student_id 
                  ORDER BY incident_date DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching incidents: " . $e->getMessage());
        return [];
    }
}

// Function to fetch clearance history
function getClearanceHistory($db, $student_id) {
    try {
        $query = "SELECT * FROM clearance_history 
                  WHERE student_id = :student_id 
                  ORDER BY clearance_date DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching clearances: " . $e->getMessage());
        return [];
    }
}

// Search for student if ID provided and verified
if (!empty($student_id_search) && isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search) {
    $api_url = "https://ttm.qcprotektado.com/api/students.php";
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing only
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        $api_response = json_decode($response, true);
        
        if (isset($api_response['records']) && is_array($api_response['records'])) {
            // Search for specific student ID
            $found = false;
            foreach ($api_response['records'] as $student) {
                if (isset($student['student_id']) && $student['student_id'] == $student_id_search) {
                    $student_data = $student;
                    $found = true;
                    
                    // Fetch clinic-specific data from local database
                    $student_data['clinic_visits'] = getClinicVisits($db, $student_id_search);
                    $student_data['incident_history'] = getIncidentHistory($db, $student_id_search);
                    $student_data['clearance_history'] = getClearanceHistory($db, $student_id_search);
                    break;
                }
            }
            
            if (!$found) {
                $search_error = "Student ID not found in the system.";
                unset($_SESSION['verified_student_id']);
            }
        } else {
            $search_error = "Unable to fetch student data.";
            unset($_SESSION['verified_student_id']);
        }
    } else {
        $search_error = "Error connecting to student database. Please try again later.";
        unset($_SESSION['verified_student_id']);
    }
} elseif (!empty($student_id_search) && (!isset($_SESSION['verified_student_id']) || $_SESSION['verified_student_id'] !== $student_id_search)) {
    // Don't fetch data, just show verification modal
    $show_verification_modal = true;
}

// Clear verification if no student ID
if (empty($student_id_search) && isset($_SESSION['verified_student_id'])) {
    unset($_SESSION['verified_student_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Medical Records | MedFlow Clinic Management System</title>
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

        /* Search Section */
        .search-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.6s ease;
        }

        .search-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 20px;
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #546e7a;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: white;
            color: #37474f;
        }

        .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        .search-btn {
            padding: 14px 32px;
            background: #191970;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .search-btn:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .search-btn svg {
            width: 20px;
            height: 20px;
        }

        .error-message {
            margin-top: 15px;
            padding: 12px 16px;
            background: #ffebee;
            border: 1px solid #ffcdd2;
            border-radius: 12px;
            color: #c62828;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
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

        /* Student Profile */
        .student-profile {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.8s ease;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eceff1;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            background: #191970;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 600;
            color: white;
        }

        .profile-title h2 {
            font-size: 2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
        }

        .profile-title p {
            color: #546e7a;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            padding: 4px 12px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 16px;
            background: #eceff1;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
        }

        .info-value.small {
            font-size: 0.9rem;
            font-weight: 400;
            color: #37474f;
        }

        .medical-tag {
            display: inline-block;
            padding: 4px 12px;
            background: #fff3e0;
            color: #e65100;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .allergy-tag {
            display: inline-block;
            padding: 4px 12px;
            background: #ffebee;
            color: #c62828;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .blood-tag {
            display: inline-block;
            padding: 4px 12px;
            background: #e3f2fd;
            color: #1565c0;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
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
            border-radius: 24px;
            width: 90%;
            max-width: 450px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        .modal-icon {
            width: 70px;
            height: 70px;
            background: #191970;
            border-radius: 20px;
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
            border-radius: 12px;
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
            border-radius: 12px;
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

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            overflow: hidden;
            animation: fadeInUp 0.9s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid #eceff1;
            background: #f5f5f5;
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

        .visit-complaint {
            font-weight: 600;
            color: #191970;
        }

        .vital-signs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .vital-item {
            background: #eceff1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            color: #37474f;
        }

        .treatment-given {
            max-width: 250px;
            color: #37474f;
        }

        .disposition-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .disposition-sent-home {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .disposition-referred {
            background: #fff3e0;
            color: #e65100;
        }

        .disposition-admitted {
            background: #ffebee;
            color: #c62828;
        }

        .disposition-cleared {
            background: #e3f2fd;
            color: #1565c0;
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
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .empty-state small {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        /* Action Buttons */
        .action-btn {
            padding: 8px 16px;
            background: #191970;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: #24248f;
            transform: translateY(-2px);
        }

        .action-btn.outline {
            background: transparent;
            border: 2px solid #191970;
            color: #191970;
        }

        .action-btn.outline:hover {
            background: #191970;
            color: white;
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
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .search-form {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
                justify-content: center;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
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
        <!-- Include your sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <!-- Include your header -->
            <?php include 'header.php'; ?>
            
            <div class="dashboard-container">
                <div class="welcome-section">
                    <h1>Student Medical Records</h1>
                    <p>Search and manage student clinic records.</p>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students_visited']; ?></h3>
                            <p>Students with Records</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                <path d="M2 17L12 22L22 17"/>
                                <path d="M2 12L12 17L22 12"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H15L21 9V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                <path d="M16 21V15H8V21"/>
                                <path d="M8 7H12"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students_visited'] > 0 ? 'Active' : 'Ready'; ?></h3>
                            <p>Clinic Monitor</p>
                        </div>
                    </div>
                </div>

                <!-- Search Section -->
                <div class="search-section">
                    <div class="search-title">🔍 Search Student Record</div>
                    <form method="GET" action="" class="search-form">
                        <div class="form-group">
                            <label for="student_id">Student ID / LRN</label>
                            <input type="text" class="form-control" id="student_id" name="student_id" 
                                   placeholder="Enter student ID (e.g., 2024-0001)" 
                                   value="<?php echo htmlspecialchars($student_id_search); ?>" required>
                        </div>
                        <button type="submit" class="search-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="M21 21L16.65 16.65"/>
                            </svg>
                            Search Record
                        </button>
                    </form>
                    
                    <?php if (!empty($search_error)): ?>
                        <div class="error-message">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <?php echo $search_error; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($student_data): ?>
                <!-- Student Profile -->
                <div class="student-profile">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($student_data['full_name'] ?? 'NA', 0, 2)); ?>
                        </div>
                        <div class="profile-title">
                            <h2><?php echo htmlspecialchars($student_data['full_name'] ?? 'N/A'); ?></h2>
                            <p>
                                Student ID: <?php echo htmlspecialchars($student_data['student_id'] ?? 'N/A'); ?>
                                <span class="badge">Active</span>
                            </p>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Grade & Section</div>
                            <div class="info-value">
                                Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - 
                                <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>
                                <?php if (!empty($student_data['semester'])): ?>
                                    (<?php echo htmlspecialchars($student_data['semester']); ?>)
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Contact Information</div>
                            <div class="info-value small">
                                📧 <?php echo htmlspecialchars($student_data['email'] ?? 'No email'); ?><br>
                                📞 <?php echo htmlspecialchars($student_data['contact_no'] ?? 'No contact'); ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Blood Type</div>
                            <div class="info-value">
                                <span class="blood-tag">
                                    <?php echo htmlspecialchars($student_data['blood_type'] ?? 'Not specified'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Declared Medical Conditions</div>
                            <div class="info-value">
                                <?php if (!empty($student_data['medical_conditions'])): ?>
                                    <?php 
                                    $conditions = is_array($student_data['medical_conditions']) ? 
                                        $student_data['medical_conditions'] : 
                                        explode(',', $student_data['medical_conditions']);
                                    foreach ($conditions as $condition): 
                                        if (trim($condition)): 
                                    ?>
                                        <span class="medical-tag"><?php echo htmlspecialchars(trim($condition)); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                <?php else: ?>
                                    <span class="info-value small">No known condition declared</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Allergy Information</div>
                            <div class="info-value">
                                <?php if (!empty($student_data['allergies'])): ?>
                                    <?php 
                                    $allergies = is_array($student_data['allergies']) ? 
                                        $student_data['allergies'] : 
                                        explode(',', $student_data['allergies']);
                                    foreach ($allergies as $allergy): 
                                        if (trim($allergy)): 
                                    ?>
                                        <span class="allergy-tag"><?php echo htmlspecialchars(trim($allergy)); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                <?php else: ?>
                                    <span class="info-value small">No known allergies</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Emergency Contact</div>
                            <div class="info-value small">
                                <?php echo htmlspecialchars($student_data['emergency_contact'] ?? 'No contact name'); ?><br>
                                📞 <?php echo htmlspecialchars($student_data['emergency_phone'] ?? 'No phone'); ?><br>
                                <?php if (!empty($student_data['emergency_email'])): ?>
                                    📧 <?php echo htmlspecialchars($student_data['emergency_email']); ?>
                                <?php else: ?>
                                    📧 No emergency email
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showTab('visits', event)">Visit History</button>
                        <button class="tab-btn" onclick="showTab('incidents', event)">Incident History</button>
                        <button class="tab-btn" onclick="showTab('clearance', event)">Clearance History</button>
                        <button class="tab-btn" onclick="showTab('add', event)">+ New Record</button>
                    </div>

                    <div class="tab-content">
                        <!-- Visit History Tab -->
                        <div class="tab-pane active" id="visits">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h3 style="color: #191970;">Clinic Visit History</h3>
                                <button class="action-btn" onclick="window.location.href='add-visit.php?student_id=<?php echo $student_data['student_id']; ?>'">
                                    + Log New Visit
                                </button>
                            </div>
                            
                            <?php if (!empty($student_data['clinic_visits'])): ?>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Complaint</th>
                                            <th>Vital Signs</th>
                                            <th>Treatment Given</th>
                                            <th>Disposition</th>
                                            <th>Attended By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($student_data['clinic_visits'] as $visit): ?>
                                        <tr>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($visit['visit_date'])); ?><br>
                                                <small style="color: #78909c;"><?php echo date('h:i A', strtotime($visit['visit_time'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="visit-complaint"><?php echo htmlspecialchars($visit['complaint']); ?></span>
                                            </td>
                                            <td>
                                                <div class="vital-signs">
                                                    <?php if (!empty($visit['temperature'])): ?>
                                                        <span class="vital-item">🌡️ <?php echo $visit['temperature']; ?>°C</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($visit['blood_pressure'])): ?>
                                                        <span class="vital-item">❤️ <?php echo $visit['blood_pressure']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($visit['heart_rate'])): ?>
                                                        <span class="vital-item">💓 <?php echo $visit['heart_rate']; ?> bpm</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="treatment-given">
                                                    <?php echo nl2br(htmlspecialchars($visit['treatment_given'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="disposition-badge disposition-<?php echo strtolower(str_replace(' ', '-', $visit['disposition'])); ?>">
                                                    <?php echo htmlspecialchars($visit['disposition']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($visit['attended_by_name'] ?? 'N/A'); ?></small>
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
                                <p>No clinic visits recorded yet</p>
                                <small>This student hasn't visited the clinic before</small>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Incident History Tab -->
                        <div class="tab-pane" id="incidents">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h3 style="color: #191970;">Incident History</h3>
                                <button class="action-btn" onclick="window.location.href='add-incident.php?student_id=<?php echo $student_data['student_id']; ?>'">
                                    + Log Incident
                                </button>
                            </div>
                            
                            <?php if (!empty($student_data['incident_history'])): ?>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Incident Type</th>
                                            <th>Description</th>
                                            <th>Action Taken</th>
                                            <th>Reported By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($student_data['incident_history'] as $incident): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($incident['incident_date'])); ?></td>
                                            <td>
                                                <span class="medical-tag"><?php echo htmlspecialchars($incident['incident_type']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($incident['description']); ?></td>
                                            <td><?php echo htmlspecialchars($incident['action_taken']); ?></td>
                                            <td><small><?php echo htmlspecialchars($incident['reported_by']); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <p>No incidents recorded</p>
                                <small>No reported incidents for this student</small>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Clearance History Tab -->
                        <div class="tab-pane" id="clearance">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h3 style="color: #191970;">Medical Clearance History</h3>
                                <button class="action-btn" onclick="window.location.href='add-clearance.php?student_id=<?php echo $student_data['student_id']; ?>'">
                                    + Issue Clearance
                                </button>
                            </div>
                            
                            <?php if (!empty($student_data['clearance_history'])): ?>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date Issued</th>
                                            <th>Clearance Type</th>
                                            <th>Purpose</th>
                                            <th>Valid Until</th>
                                            <th>Status</th>
                                            <th>Issued By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($student_data['clearance_history'] as $clearance): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($clearance['clearance_date'])); ?></td>
                                            <td>
                                                <span class="disposition-badge disposition-cleared">
                                                    <?php echo htmlspecialchars($clearance['clearance_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($clearance['purpose']); ?></td>
                                            <td>
                                                <?php if ($clearance['valid_until']): ?>
                                                    <?php echo date('M d, Y', strtotime($clearance['valid_until'])); ?>
                                                <?php else: ?>
                                                    <small>No expiry</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $is_valid = strtotime($clearance['valid_until']) > time();
                                                $status_class = $is_valid ? 'disposition-sent-home' : 'disposition-cancelled';
                                                $status_text = $is_valid ? 'Valid' : 'Expired';
                                                ?>
                                                <span class="badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($clearance['issued_by']); ?></small></td>
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
                                <p>No clearance records</p>
                                <small>No medical clearances issued for this student</small>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Add New Record Tab -->
                        <div class="tab-pane" id="add">
                            <h3 style="color: #191970; margin-bottom: 20px;">Quick Actions</h3>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                                <a href="add-visit.php?student_id=<?php echo $student_data['student_id']; ?>" style="text-decoration: none;">
                                    <div style="padding: 30px; background: #eceff1; border-radius: 12px; text-align: center; border: 1px solid #cfd8dc; transition: all 0.3s ease;">
                                        <div style="width: 60px; height: 60px; background: #191970; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                                <circle cx="12" cy="12" r="10"/>
                                                <path d="M12 6V12L16 14"/>
                                            </svg>
                                        </div>
                                        <span style="font-weight: 600; color: #191970;">Log Clinic Visit</span>
                                        <p style="font-size: 0.8rem; color: #78909c; margin-top: 8px;">Record a new clinic visit with vitals</p>
                                    </div>
                                </a>

                                <a href="add-incident.php?student_id=<?php echo $student_data['student_id']; ?>" style="text-decoration: none;">
                                    <div style="padding: 30px; background: #eceff1; border-radius: 12px; text-align: center; border: 1px solid #cfd8dc; transition: all 0.3s ease;">
                                        <div style="width: 60px; height: 60px; background: #191970; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                                <circle cx="12" cy="12" r="10"/>
                                                <line x1="12" y1="8" x2="12" y2="12"/>
                                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                                            </svg>
                                        </div>
                                        <span style="font-weight: 600; color: #191970;">Report Incident</span>
                                        <p style="font-size: 0.8rem; color: #78909c; margin-top: 8px;">Log an incident or injury</p>
                                    </div>
                                </a>

                                <a href="add-clearance.php?student_id=<?php echo $student_data['student_id']; ?>" style="text-decoration: none;">
                                    <div style="padding: 30px; background: #eceff1; border-radius: 12px; text-align: center; border: 1px solid #cfd8dc; transition: all 0.3s ease;">
                                        <div style="width: 60px; height: 60px; background: #191970; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white;">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                                <path d="M22 4L12 14.01L9 11.01"/>
                                            </svg>
                                        </div>
                                        <span style="font-weight: 600; color: #191970;">Issue Clearance</span>
                                        <p style="font-size: 0.8rem; color: #78909c; margin-top: 8px;">Issue medical clearance</p>
                                    </div>
                                </a>
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
                You are accessing confidential medical records for<br>
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
            <p style="text-align: center; margin-top: 20px; font-size: 0.8rem; color: #78909c;">
                This helps us maintain confidentiality of student records
            </p>
        </div>
    </div>
    
    <script>
        // Prevent background scrolling when modal is open
        document.body.style.overflow = 'hidden';
        
        function cancelAccess() {
            window.location.href = window.location.pathname; // Redirect to same page without query string
        }
        
        // Close modal when clicking outside (optional)
        document.getElementById('verificationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                cancelAccess();
            }
        });
    </script>
    <?php endif; ?>

    <script>
        // Sidebar toggle sync
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
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab and activate button
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Student Medical Records';
        }
    </script>
</body>
</html>