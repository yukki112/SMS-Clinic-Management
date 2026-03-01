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
$student_data = null;
$search_error = '';
$student_id_search = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$success_message = '';
$error_message = '';
$show_verification_modal = false;

// Check if verification was completed
if (isset($_SESSION['verified_student_id_health']) && $_SESSION['verified_student_id_health'] === $student_id_search) {
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
        $_SESSION['verified_student_id_health'] = $_POST['student_id'];
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($_POST['student_id']));
        exit();
    } else {
        $verification_error = "Invalid password. Access denied.";
        $show_verification_modal = true;
    }
}

// Handle appointment approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_action'])) {
    $appointment_id = $_POST['appointment_id'];
    $action = $_POST['appointment_action']; // 'approve' or 'reject'
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Get appointment details first
        $query = "SELECT a.*, p.student_id, p.full_name as patient_name 
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  WHERE a.id = :appointment_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':appointment_id', $appointment_id);
        $stmt->execute();
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($appointment) {
            $new_status = ($action === 'approve') ? 'scheduled' : 'cancelled';
            $action_type = ($action === 'approve') ? 'approved' : 'rejected';
            
            // Update appointment status
            $update_query = "UPDATE appointments SET status = :status WHERE id = :appointment_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':status', $new_status);
            $update_stmt->bindParam(':appointment_id', $appointment_id);
            
            if ($update_stmt->execute()) {
                // Insert into appointment history
                $history_query = "INSERT INTO appointment_history (appointment_id, action, performed_by, notes, created_at) 
                                 VALUES (:appointment_id, :action, :performed_by, :notes, NOW())";
                $history_stmt = $db->prepare($history_query);
                $history_stmt->bindParam(':appointment_id', $appointment_id);
                $history_stmt->bindParam(':action', $action_type);
                $history_stmt->bindParam(':performed_by', $current_user_id);
                $notes = "Appointment " . $action_type . " by " . $current_user_name;
                $history_stmt->bindParam(':notes', $notes);
                $history_stmt->execute();
                
                $db->commit();
                
                if ($action === 'approve') {
                    $success_message = "Appointment #" . $appointment_id . " has been approved successfully!";
                } else {
                    $success_message = "Appointment #" . $appointment_id . " has been rejected.";
                }
                
                // Log the action
                error_log("Appointment " . $appointment_id . " " . $action_type . " by user " . $current_user_id);
            } else {
                $db->rollBack();
                $error_message = "Error updating appointment.";
            }
        } else {
            $error_message = "Appointment not found.";
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Database error: " . $e->getMessage();
        error_log("Error processing appointment: " . $e->getMessage());
    }
}

// Function to fetch data from school event management API
function fetchHealthProgramsFromAPI() {
    $api_url = "https://cps.qcprotektado.com/api/health_program.php";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . "?action=get_upcoming_events");
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

function fetchEventsNeedingClearance() {
    $api_url = "https://cps.qcprotektado.com/api/health_program.php?action=get_events_needing_clearance";
    
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

function fetchHealthMetrics() {
    $api_url = "https://cps.qcprotektado.com/api/health_program.php?action=get_health_metrics";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        return json_decode($response, true);
    }
    return ['data' => []];
}

function fetchClearanceStatus($event_id) {
    $api_url = "https://cps.qcprotektado.com/api/health_program.php?action=get_clearance_status&event_id=" . $event_id;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        return json_decode($response, true);
    }
    return null;
}

// Function to get pending appointments (status = 'scheduled' means approved)
function getPendingAppointments($db) {
    try {
        $query = "SELECT a.*, p.student_id, p.full_name as patient_name, p.phone, u.full_name as doctor_name 
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  LEFT JOIN users u ON a.doctor_id = u.id 
                  WHERE a.status = 'scheduled' 
                  ORDER BY a.appointment_date ASC, a.appointment_time ASC 
                  LIMIT 50";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching appointments: " . $e->getMessage());
        return [];
    }
}

// Function to get appointment history
function getAppointmentHistory($db) {
    try {
        $query = "SELECT ah.*, a.appointment_date, a.appointment_time, p.student_id, p.full_name as patient_name,
                  u.username as performed_by_name
                  FROM appointment_history ah
                  JOIN appointments a ON ah.appointment_id = a.id
                  JOIN patients p ON a.patient_id = p.id
                  JOIN users u ON ah.performed_by = u.id
                  ORDER BY ah.created_at DESC
                  LIMIT 50";
        $stmt = $db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching appointment history: " . $e->getMessage());
        return [];
    }
}

// Function to get appointments by date
function getAppointmentsByDate($db, $date) {
    try {
        $query = "SELECT a.*, p.student_id, p.full_name as patient_name, p.phone, u.full_name as doctor_name 
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  LEFT JOIN users u ON a.doctor_id = u.id 
                  WHERE a.appointment_date = :date 
                  ORDER BY a.appointment_time ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching appointments by date: " . $e->getMessage());
        return [];
    }
}

// Function to get calendar data
function getCalendarData($db, $month, $year) {
    try {
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $query = "SELECT 
                    appointment_date,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                  FROM appointments 
                  WHERE appointment_date BETWEEN :start_date AND :end_date
                  GROUP BY appointment_date
                  ORDER BY appointment_date";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->execute();
        
        $calendar_data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $calendar_data[$row['appointment_date']] = $row;
        }
        return $calendar_data;
    } catch (PDOException $e) {
        error_log("Error fetching calendar data: " . $e->getMessage());
        return [];
    }
}

// Function to get appointment statistics
function getAppointmentStats($db) {
    try {
        $stats = [];
        
        // Total pending (waiting for approval)
        $query = "SELECT COUNT(*) as total FROM appointments WHERE status = 'scheduled'";
        $stmt = $db->query($query);
        $stats['pending'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Today's appointments
        $query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE()";
        $stmt = $db->query($query);
        $stats['today'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // This week's appointments
        $query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $stmt = $db->query($query);
        $stats['week'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // History stats
        $query = "SELECT 
                    SUM(CASE WHEN action = 'approved' THEN 1 ELSE 0 END) as total_approved,
                    SUM(CASE WHEN action = 'rejected' THEN 1 ELSE 0 END) as total_rejected,
                    SUM(CASE WHEN action = 'completed' THEN 1 ELSE 0 END) as total_completed
                  FROM appointment_history";
        $stmt = $db->query($query);
        $history_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['approved'] = $history_stats['total_approved'] ?? 0;
        $stats['rejected'] = $history_stats['total_rejected'] ?? 0;
        $stats['completed'] = $history_stats['total_completed'] ?? 0;
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error fetching appointment stats: " . $e->getMessage());
        return ['pending' => 0, 'today' => 0, 'week' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0];
    }
}

// Create local tables for health monitoring if not exists
try {
    // Vaccination records table
    $db->exec("CREATE TABLE IF NOT EXISTS `vaccination_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `vaccine_name` varchar(100) NOT NULL,
        `dose_number` int(11) DEFAULT 1,
        `date_administered` date NOT NULL,
        `administered_by` varchar(100) NOT NULL,
        `batch_number` varchar(50) DEFAULT NULL,
        `next_dose_date` date DEFAULT NULL,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `student_id` (`student_id`),
        KEY `vaccine_name` (`vaccine_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Physical exam records table
    $db->exec("CREATE TABLE IF NOT EXISTS `physical_exam_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `exam_date` date NOT NULL,
        `height` decimal(5,2) DEFAULT NULL,
        `weight` decimal(5,2) DEFAULT NULL,
        `bmi` decimal(4,2) DEFAULT NULL,
        `vision_left` varchar(10) DEFAULT NULL,
        `vision_right` varchar(10) DEFAULT NULL,
        `hearing_left` varchar(10) DEFAULT NULL,
        `hearing_right` varchar(10) DEFAULT NULL,
        `dental_findings` text DEFAULT NULL,
        `general_assessment` text DEFAULT NULL,
        `fit_for_school` enum('Yes','No','With Restrictions') DEFAULT 'Yes',
        `examined_by` varchar(100) NOT NULL,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Deworming records table
    $db->exec("CREATE TABLE IF NOT EXISTS `deworming_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `medicine_name` varchar(100) NOT NULL,
        `date_given` date NOT NULL,
        `dosage` varchar(50) DEFAULT NULL,
        `next_dose_date` date DEFAULT NULL,
        `adverse_reaction` text DEFAULT NULL,
        `administered_by` varchar(100) NOT NULL,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Health screening records table
    $db->exec("CREATE TABLE IF NOT EXISTS `health_screening_records` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `event_id` int(11) DEFAULT NULL,
        `event_name` varchar(200) DEFAULT NULL,
        `screening_date` date NOT NULL,
        `screening_type` varchar(100) NOT NULL,
        `blood_pressure` varchar(10) DEFAULT NULL,
        `heart_rate` int(11) DEFAULT NULL,
        `temperature` decimal(4,2) DEFAULT NULL,
        `oxygen_saturation` int(11) DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `cleared_for_participation` enum('Yes','No','With Restrictions') DEFAULT 'Yes',
        `restrictions` text DEFAULT NULL,
        `screened_by` varchar(100) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `student_id` (`student_id`),
        KEY `event_id` (`event_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Save vaccination record
    if ($_POST['action'] == 'save_vaccination') {
        try {
            $query = "INSERT INTO vaccination_records (
                student_id, student_name, vaccine_name, dose_number,
                date_administered, administered_by, batch_number, next_dose_date, remarks
            ) VALUES (
                :student_id, :student_name, :vaccine_name, :dose_number,
                :date_administered, :administered_by, :batch_number, :next_dose_date, :remarks
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':vaccine_name', $_POST['vaccine_name']);
            $stmt->bindParam(':dose_number', $_POST['dose_number']);
            $stmt->bindParam(':date_administered', $_POST['date_administered']);
            $stmt->bindParam(':administered_by', $_POST['administered_by']);
            $stmt->bindParam(':batch_number', $_POST['batch_number']);
            $stmt->bindParam(':next_dose_date', $_POST['next_dose_date']);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            if ($stmt->execute()) {
                $success_message = "Vaccination record saved successfully!";
            } else {
                $error_message = "Error saving vaccination record.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Save physical exam record
    if ($_POST['action'] == 'save_physical_exam') {
        try {
            // Calculate BMI
            $height = floatval($_POST['height']);
            $weight = floatval($_POST['weight']);
            $bmi = 0;
            if ($height > 0 && $weight > 0) {
                $height_m = $height / 100; // convert cm to m
                $bmi = round($weight / ($height_m * $height_m), 2);
            }
            
            $query = "INSERT INTO physical_exam_records (
                student_id, student_name, exam_date, height, weight, bmi,
                vision_left, vision_right, hearing_left, hearing_right,
                dental_findings, general_assessment, fit_for_school, examined_by, remarks
            ) VALUES (
                :student_id, :student_name, :exam_date, :height, :weight, :bmi,
                :vision_left, :vision_right, :hearing_left, :hearing_right,
                :dental_findings, :general_assessment, :fit_for_school, :examined_by, :remarks
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':exam_date', $_POST['exam_date']);
            $stmt->bindParam(':height', $_POST['height']);
            $stmt->bindParam(':weight', $_POST['weight']);
            $stmt->bindParam(':bmi', $bmi);
            $stmt->bindParam(':vision_left', $_POST['vision_left']);
            $stmt->bindParam(':vision_right', $_POST['vision_right']);
            $stmt->bindParam(':hearing_left', $_POST['hearing_left']);
            $stmt->bindParam(':hearing_right', $_POST['hearing_right']);
            $stmt->bindParam(':dental_findings', $_POST['dental_findings']);
            $stmt->bindParam(':general_assessment', $_POST['general_assessment']);
            $stmt->bindParam(':fit_for_school', $_POST['fit_for_school']);
            $stmt->bindParam(':examined_by', $_POST['examined_by']);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            if ($stmt->execute()) {
                $success_message = "Physical exam record saved successfully!";
            } else {
                $error_message = "Error saving physical exam record.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Save deworming record
    if ($_POST['action'] == 'save_deworming') {
        try {
            $query = "INSERT INTO deworming_records (
                student_id, student_name, medicine_name, date_given,
                dosage, next_dose_date, adverse_reaction, administered_by, remarks
            ) VALUES (
                :student_id, :student_name, :medicine_name, :date_given,
                :dosage, :next_dose_date, :adverse_reaction, :administered_by, :remarks
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':medicine_name', $_POST['medicine_name']);
            $stmt->bindParam(':date_given', $_POST['date_given']);
            $stmt->bindParam(':dosage', $_POST['dosage']);
            $stmt->bindParam(':next_dose_date', $_POST['next_dose_date']);
            $stmt->bindParam(':adverse_reaction', $_POST['adverse_reaction']);
            $stmt->bindParam(':administered_by', $_POST['administered_by']);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            if ($stmt->execute()) {
                $success_message = "Deworming record saved successfully!";
            } else {
                $error_message = "Error saving deworming record.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Save health screening record
    if ($_POST['action'] == 'save_screening') {
        try {
            $query = "INSERT INTO health_screening_records (
                student_id, student_name, event_id, event_name, screening_date,
                screening_type, blood_pressure, heart_rate, temperature,
                oxygen_saturation, findings, cleared_for_participation, restrictions, screened_by
            ) VALUES (
                :student_id, :student_name, :event_id, :event_name, :screening_date,
                :screening_type, :blood_pressure, :heart_rate, :temperature,
                :oxygen_saturation, :findings, :cleared_for_participation, :restrictions, :screened_by
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':event_id', $_POST['event_id']);
            $stmt->bindParam(':event_name', $_POST['event_name']);
            $stmt->bindParam(':screening_date', $_POST['screening_date']);
            $stmt->bindParam(':screening_type', $_POST['screening_type']);
            $stmt->bindParam(':blood_pressure', $_POST['blood_pressure']);
            $stmt->bindParam(':heart_rate', $_POST['heart_rate']);
            $stmt->bindParam(':temperature', $_POST['temperature']);
            $stmt->bindParam(':oxygen_saturation', $_POST['oxygen_saturation']);
            $stmt->bindParam(':findings', $_POST['findings']);
            $stmt->bindParam(':cleared_for_participation', $_POST['cleared_for_participation']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':screened_by', $_POST['screened_by']);
            
            if ($stmt->execute()) {
                $success_message = "Health screening record saved successfully!";
            } else {
                $error_message = "Error saving health screening record.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle calendar date selection
$selected_date = isset($_GET['date']) ? $_GET['date'] : '';
$selected_date_appointments = [];

if (!empty($selected_date)) {
    $selected_date_appointments = getAppointmentsByDate($db, $selected_date);
}

// Get current month for calendar
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validate month and year
if ($current_month < 1) $current_month = 1;
if ($current_month > 12) $current_month = 12;
if ($current_year < 2020) $current_year = 2020;
if ($current_year > 2030) $current_year = 2030;

$calendar_data = getCalendarData($db, $current_month, $current_year);

// Get previous and next month
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year = $current_year - 1;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year = $current_year + 1;
}

// Fetch data from API
$upcoming_events = fetchHealthProgramsFromAPI();
$events_needing_clearance = fetchEventsNeedingClearance();
$health_metrics = fetchHealthMetrics();

// Get appointment data
$pending_appointments = getPendingAppointments($db);
$appointment_history = getAppointmentHistory($db);
$appointment_stats = getAppointmentStats($db);

// Get local records
function getVaccinationRecords($db, $student_id = null) {
    try {
        $query = "SELECT * FROM vaccination_records";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY date_administered DESC LIMIT 50";
        
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

function getPhysicalExamRecords($db, $student_id = null) {
    try {
        $query = "SELECT * FROM physical_exam_records";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY exam_date DESC LIMIT 50";
        
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

function getDewormingRecords($db, $student_id = null) {
    try {
        $query = "SELECT * FROM deworming_records";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY date_given DESC LIMIT 50";
        
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

function getHealthScreeningRecords($db, $student_id = null) {
    try {
        $query = "SELECT * FROM health_screening_records";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY screening_date DESC LIMIT 50";
        
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

// Get summary statistics
$vaccination_count = count(getVaccinationRecords($db));
$physical_exam_count = count(getPhysicalExamRecords($db));
$deworming_count = count(getDewormingRecords($db));
$screening_count = count(getHealthScreeningRecords($db));

// Search for student if ID provided and verified
if (!empty($student_id_search) && isset($_SESSION['verified_student_id_health']) && $_SESSION['verified_student_id_health'] === $student_id_search && !isset($_POST['action'])) {
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
                    
                    // Get student's health records
                    $student_data['vaccinations'] = getVaccinationRecords($db, $student_id_search);
                    $student_data['physical_exams'] = getPhysicalExamRecords($db, $student_id_search);
                    $student_data['deworming'] = getDewormingRecords($db, $student_id_search);
                    $student_data['screenings'] = getHealthScreeningRecords($db, $student_id_search);
                    break;
                }
            }
            
            if (!$found) {
                $search_error = "Student ID not found in the system.";
                unset($_SESSION['verified_student_id_health']);
            }
        } else {
            $search_error = "Unable to fetch student data.";
            unset($_SESSION['verified_student_id_health']);
        }
    } else {
        $search_error = "Error connecting to student database.";
        unset($_SESSION['verified_student_id_health']);
    }
} elseif (!empty($student_id_search) && (!isset($_SESSION['verified_student_id_health']) || $_SESSION['verified_student_id_health'] !== $student_id_search)) {
    $show_verification_modal = true;
}

// Clear verification if no student ID
if (empty($student_id_search) && isset($_SESSION['verified_student_id_health'])) {
    unset($_SESSION['verified_student_id_health']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Programs Monitoring | MedFlow Clinic Management System</title>
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

        .warning-badge {
            background: #ffebee;
            color: #c62828;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 4px;
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

        .alert-info {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            color: #1565c0;
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
            font-size: 0.85rem;
        }

        .btn-success {
            background: #2e7d32;
            color: white;
        }

        .btn-success:hover {
            background: #1b5e20;
        }

        .btn-danger {
            background: #c62828;
            color: white;
        }

        .btn-danger:hover {
            background: #b71c1c;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .event-list {
            list-style: none;
            max-height: 300px;
            overflow-y: auto;
        }

        .event-item {
            padding: 12px 0;
            border-bottom: 1px solid #eceff1;
        }

        .event-item:last-child {
            border-bottom: none;
        }

        .event-name {
            font-weight: 600;
            color: #191970;
            margin-bottom: 4px;
        }

        .event-date {
            font-size: 0.8rem;
            color: #546e7a;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .event-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #eceff1;
            color: #191970;
        }

        .clearance-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #fff3cd;
            color: #856404;
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

        /* Calendar Styles */
        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #cfd8dc;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            padding: 8px 16px;
            background: #eceff1;
            border: 1px solid #cfd8dc;
            border-radius: 8px;
            color: #37474f;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-nav-btn:hover {
            background: #191970;
            color: white;
            border-color: #191970;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-bottom: 10px;
        }

        .weekday {
            text-align: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            padding: 8px;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day {
            background: #f8fafc;
            border: 1px solid #cfd8dc;
            border-radius: 8px;
            padding: 8px;
            min-height: 80px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            background: #e3f2fd;
            border-color: #191970;
            transform: translateY(-2px);
        }

        .calendar-day.empty {
            background: #eceff1;
            border-color: #b0bec5;
            cursor: default;
        }

        .calendar-day.empty:hover {
            background: #eceff1;
            border-color: #b0bec5;
            transform: none;
        }

        .day-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 4px;
        }

        .day-events {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .event-indicator {
            font-size: 0.7rem;
            padding: 2px 4px;
            border-radius: 4px;
            background: #e8f5e9;
            color: #2e7d32;
            display: flex;
            justify-content: space-between;
        }

        .event-indicator.approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .event-indicator.cancelled {
            background: #ffebee;
            color: #c62828;
        }

        .event-indicator.completed {
            background: #e3f2fd;
            color: #1565c0;
        }

        .calendar-day.selected {
            background: #e3f2fd;
            border: 2px solid #191970;
        }

        /* Appointment List Modal */
        .appointment-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            z-index: 10000;
            box-shadow: 0 8px 24px rgba(25, 25, 112, 0.2);
        }

        .appointment-modal-header {
            padding: 20px;
            border-bottom: 2px solid #eceff1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f5f5f5;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .appointment-modal-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #78909c;
            padding: 0 8px;
        }

        .close-modal:hover {
            color: #c62828;
        }

        .appointment-modal-body {
            padding: 20px;
        }

        .appointment-list-item {
            background: #f8fafc;
            border: 1px solid #cfd8dc;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .appointment-list-item:last-child {
            margin-bottom: 0;
        }

        .appointment-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .appointment-list-time {
            font-weight: 600;
            color: #191970;
            background: #e3f2fd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .appointment-list-status {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-cancelled {
            background: #ffebee;
            color: #c62828;
        }

        .status-completed {
            background: #e3f2fd;
            color: #1565c0;
        }

        .appointment-list-details {
            font-size: 0.9rem;
        }

        .appointment-list-details p {
            margin: 4px 0;
            color: #37474f;
        }

        .appointment-list-details strong {
            color: #191970;
        }

        /* History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th {
            text-align: left;
            padding: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            border-bottom: 2px solid #cfd8dc;
            background: #eceff1;
        }

        .history-table td {
            padding: 12px;
            font-size: 0.9rem;
            color: #37474f;
            border-bottom: 1px solid #eceff1;
        }

        .history-table tr:hover td {
            background: #f5f5f5;
        }

        .action-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-rejected {
            background: #ffebee;
            color: #c62828;
        }

        .badge-completed {
            background: #e3f2fd;
            color: #1565c0;
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

        .health-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Appointment Card */
        .appointment-card {
            background: #f8fafc;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .appointment-card:hover {
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
            border-color: #191970;
        }

        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #cfd8dc;
        }

        .appointment-id {
            font-weight: 700;
            color: #191970;
            background: #e3f2fd;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .appointment-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #fff3cd;
            color: #856404;
        }

        .appointment-body {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .appointment-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: #37474f;
        }

        .info-value strong {
            color: #191970;
        }

        .appointment-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn.approve {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }

        .action-btn.approve:hover {
            background: #2e7d32;
            color: white;
        }

        .action-btn.reject {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .action-btn.reject:hover {
            background: #c62828;
            color: white;
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

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .metric-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #cfd8dc;
        }

        .metric-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 15px;
        }

        .metric-list {
            list-style: none;
        }

        .metric-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eceff1;
        }

        .metric-item:last-child {
            border-bottom: none;
        }

        .metric-label {
            color: #546e7a;
            font-size: 0.9rem;
        }

        .metric-value {
            font-weight: 600;
            color: #191970;
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
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .appointment-body {
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
            
            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .student-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .appointment-actions {
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
                    <h1>🏥 School Health Programs Monitoring</h1>
                    <p>Track vaccination records, physical exams, deworming, and health screenings. Manage and approve student appointment requests.</p>
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
                        <div class="stat-icon">💉</div>
                        <div class="stat-info">
                            <h3><?php echo $vaccination_count; ?></h3>
                            <p>Vaccinations</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📏</div>
                        <div class="stat-info">
                            <h3><?php echo $physical_exam_count; ?></h3>
                            <p>Physical Exams</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🪱</div>
                        <div class="stat-info">
                            <h3><?php echo $deworming_count; ?></h3>
                            <p>Deworming</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🔍</div>
                        <div class="stat-info">
                            <h3><?php echo $screening_count; ?></h3>
                            <p>Health Screenings</p>
                        </div>
                    </div>
                </div>

                <!-- Appointment Stats Cards -->
                <div class="stats-grid" style="margin-top: 20px;">
                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-info">
                            <h3><?php echo $appointment_stats['pending']; ?></h3>
                            <p>Approved Appointments</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📆</div>
                        <div class="stat-info">
                            <h3><?php echo $appointment_stats['today']; ?></h3>
                            <p>Today's Appointments</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🗓️</div>
                        <div class="stat-info">
                            <h3><?php echo $appointment_stats['week']; ?></h3>
                            <p>This Week</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $appointment_stats['approved']; ?> / <?php echo $appointment_stats['rejected']; ?></h3>
                            <p>Approved/Rejected</p>
                        </div>
                    </div>
                </div>

                <!-- Main Tabs: Appointments, Calendar, History -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn <?php echo !isset($_GET['tab']) || $_GET['tab'] == 'appointments' ? 'active' : ''; ?>" onclick="window.location.href='?tab=appointments<?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>'">📋 Appointments</button>
                        <button class="tab-btn <?php echo isset($_GET['tab']) && $_GET['tab'] == 'calendar' ? 'active' : ''; ?>" onclick="window.location.href='?tab=calendar<?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>'">📅 Calendar View</button>
                        <button class="tab-btn <?php echo isset($_GET['tab']) && $_GET['tab'] == 'history' ? 'active' : ''; ?>" onclick="window.location.href='?tab=history<?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>'">📜 History</button>
                    </div>

                    <div class="tab-content">
                        <!-- Appointments Tab -->
                        <div class="tab-pane <?php echo !isset($_GET['tab']) || $_GET['tab'] == 'appointments' ? 'active' : ''; ?>" id="appointments-tab">
                            <?php if (!empty($pending_appointments)): ?>
                                <?php foreach ($pending_appointments as $appointment): ?>
                                    <div class="appointment-card">
                                        <div class="appointment-header">
                                            <span class="appointment-id">Appointment #<?php echo $appointment['id']; ?></span>
                                            <span class="appointment-status">Approved</span>
                                        </div>
                                        <div class="appointment-body">
                                            <div class="appointment-info">
                                                <span class="info-label">Student</span>
                                                <span class="info-value"><strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong> (<?php echo htmlspecialchars($appointment['student_id']); ?>)</span>
                                                <span class="info-label" style="margin-top: 5px;">Contact</span>
                                                <span class="info-value"><?php echo htmlspecialchars($appointment['phone'] ?: 'N/A'); ?></span>
                                            </div>
                                            <div class="appointment-info">
                                                <span class="info-label">Date & Time</span>
                                                <span class="info-value"><strong><?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?></strong> at <?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></span>
                                                <span class="info-label" style="margin-top: 5px;">Preferred Staff</span>
                                                <span class="info-value"><?php echo $appointment['doctor_name'] ? 'Dr. ' . htmlspecialchars($appointment['doctor_name']) : 'Any available'; ?></span>
                                            </div>
                                            <div class="appointment-info">
                                                <span class="info-label">Reason</span>
                                                <span class="info-value"><?php echo htmlspecialchars($appointment['reason']); ?></span>
                                                <span class="info-label" style="margin-top: 5px;">Requested On</span>
                                                <span class="info-value"><?php echo date('M d, Y', strtotime($appointment['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8v4M12 16h.01"/>
                                    </svg>
                                    <p>No approved appointments found</p>
                                    <small>Approved appointments will appear here</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Calendar Tab -->
                        <div class="tab-pane <?php echo isset($_GET['tab']) && $_GET['tab'] == 'calendar' ? 'active' : ''; ?>" id="calendar-tab">
                            <div class="calendar-container">
                                <div class="calendar-header">
                                    <span class="calendar-title"><?php echo date('F Y', strtotime("$current_year-$current_month-01")); ?></span>
                                    <div class="calendar-nav">
                                        <a href="?tab=calendar&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?><?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>" class="calendar-nav-btn">← Previous</a>
                                        <a href="?tab=calendar&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?><?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>" class="calendar-nav-btn">Today</a>
                                        <a href="?tab=calendar&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?><?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>" class="calendar-nav-btn">Next →</a>
                                    </div>
                                </div>

                                <div class="calendar-weekdays">
                                    <div class="weekday">Sun</div>
                                    <div class="weekday">Mon</div>
                                    <div class="weekday">Tue</div>
                                    <div class="weekday">Wed</div>
                                    <div class="weekday">Thu</div>
                                    <div class="weekday">Fri</div>
                                    <div class="weekday">Sat</div>
                                </div>

                                <div class="calendar-days">
                                    <?php
                                    $first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
                                    $days_in_month = date('t', $first_day);
                                    $start_day = date('w', $first_day);
                                    
                                    // Fill empty cells before first day
                                    for ($i = 0; $i < $start_day; $i++) {
                                        echo '<div class="calendar-day empty"></div>';
                                    }
                                    
                                    // Fill days of month
                                    for ($day = 1; $day <= $days_in_month; $day++) {
                                        $date = sprintf("%04d-%02d-%02d", $current_year, $current_month, $day);
                                        $day_data = isset($calendar_data[$date]) ? $calendar_data[$date] : null;
                                        $is_selected = ($selected_date == $date);
                                        ?>
                                        <div class="calendar-day <?php echo $is_selected ? 'selected' : ''; ?>" onclick="showAppointments('<?php echo $date; ?>')">
                                            <div class="day-number"><?php echo $day; ?></div>
                                            <?php if ($day_data && $day_data['total'] > 0): ?>
                                                <div class="day-events">
                                                    <?php if ($day_data['approved'] > 0): ?>
                                                        <div class="event-indicator approved">
                                                            <span>✓</span>
                                                            <span><?php echo $day_data['approved']; ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($day_data['completed'] > 0): ?>
                                                        <div class="event-indicator completed">
                                                            <span>✓</span>
                                                            <span><?php echo $day_data['completed']; ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                    }
                                    
                                    // Fill empty cells after last day
                                    $remaining_cells = 42 - ($start_day + $days_in_month);
                                    for ($i = 0; $i < $remaining_cells; $i++) {
                                        echo '<div class="calendar-day empty"></div>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <?php if (!empty($selected_date_appointments)): ?>
                                <div style="margin-top: 20px;">
                                    <h3 style="color: #191970; margin-bottom: 15px;">Appointments for <?php echo date('F j, Y', strtotime($selected_date)); ?></h3>
                                    <?php foreach ($selected_date_appointments as $appointment): ?>
                                        <div class="appointment-list-item">
                                            <div class="appointment-list-header">
                                                <span class="appointment-list-time"><?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></span>
                                                <span class="appointment-list-status status-<?php echo $appointment['status'] == 'scheduled' ? 'approved' : ($appointment['status'] == 'cancelled' ? 'cancelled' : 'completed'); ?>">
                                                    <?php echo ucfirst($appointment['status']); ?>
                                                </span>
                                            </div>
                                            <div class="appointment-list-details">
                                                <p><strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong> (<?php echo htmlspecialchars($appointment['student_id']); ?>)</p>
                                                <p>Reason: <?php echo htmlspecialchars($appointment['reason']); ?></p>
                                                <p>Doctor: <?php echo $appointment['doctor_name'] ? 'Dr. ' . htmlspecialchars($appointment['doctor_name']) : 'Any available'; ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- History Tab -->
                        <div class="tab-pane <?php echo isset($_GET['tab']) && $_GET['tab'] == 'history' ? 'active' : ''; ?>" id="history-tab">
                            <?php if (!empty($appointment_history)): ?>
                                <div class="table-wrapper">
                                    <table class="history-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Action</th>
                                                <th>Student</th>
                                                <th>Appointment</th>
                                                <th>Performed By</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($appointment_history as $history): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y h:i A', strtotime($history['created_at'])); ?></td>
                                                    <td>
                                                        <span class="action-badge badge-<?php echo $history['action']; ?>">
                                                            <?php echo ucfirst($history['action']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($history['patient_name']); ?></strong><br>
                                                        <small><?php echo htmlspecialchars($history['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d', strtotime($history['appointment_date'])); ?> at <?php echo date('h:i A', strtotime($history['appointment_time'])); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($history['performed_by_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($history['notes']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8v4M12 16h.01"/>
                                    </svg>
                                    <p>No appointment history found</p>
                                    <small>Approved or rejected appointments will appear here</small>
                                </div>
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
                            <input type="hidden" name="tab" value="<?php echo isset($_GET['tab']) ? $_GET['tab'] : 'appointments'; ?>">
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

                        <!-- Upcoming Events -->
                        <?php if (!empty($upcoming_events)): ?>
                            <div style="margin-top: 24px;">
                                <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                    📅 Upcoming Health Programs
                                </div>
                                <div class="event-list">
                                    <?php foreach ($upcoming_events as $event): ?>
                                        <div class="event-item">
                                            <div class="event-name"><?php echo htmlspecialchars($event['event_name'] ?? 'Health Program'); ?></div>
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                                                <span class="event-date">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                                    </svg>
                                                    <?php echo date('M d, Y', strtotime($event['event_date'] ?? date('Y-m-d'))); ?>
                                                </span>
                                                <span class="event-badge"><?php echo $event['event_type'] ?? 'Program'; ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Events Needing Clearance -->
                    <div class="quick-stats-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                <path d="M22 4L12 14.01L9 11.01"/>
                            </svg>
                            Events Requiring Clearance
                        </div>
                        
                        <?php if (!empty($events_needing_clearance)): ?>
                            <div class="event-list" style="max-height: 200px;">
                                <?php foreach ($events_needing_clearance as $event): ?>
                                    <div class="event-item">
                                        <div class="event-name"><?php echo htmlspecialchars($event['event_name'] ?? 'Event'); ?></div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                                            <span class="event-date">
                                                📅 <?php echo date('M d, Y', strtotime($event['event_date'] ?? date('Y-m-d'))); ?>
                                            </span>
                                            <span class="clearance-badge">Needs Clearance</span>
                                        </div>
                                        <?php if (!empty($event['expected_participants'])): ?>
                                            <div style="font-size: 0.7rem; color: #546e7a; margin-top: 4px;">
                                                👥 Expected: <?php echo $event['expected_participants']; ?> participants
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding: 30px 20px;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v4M12 16h.01"/>
                                </svg>
                                <p>No events needing clearance</p>
                                <small>All health programs are up to date</small>
                            </div>
                        <?php endif; ?>

                        <!-- Health Metrics Preview -->
                        <?php if (!empty($health_metrics['data']['common_conditions'])): ?>
                            <div style="margin-top: 20px;">
                                <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                    📊 Common Health Conditions
                                </div>
                                <div class="metric-list">
                                    <?php foreach (array_slice($health_metrics['data']['common_conditions'], 0, 3) as $condition): ?>
                                        <div class="metric-item">
                                            <span class="metric-label"><?php echo htmlspecialchars($condition['condition_name'] ?? 'Unknown'); ?></span>
                                            <span class="metric-value"><?php echo $condition['frequency'] ?? 0; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($student_data): ?>
                <!-- Student Health Records -->
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
                        <?php if (!empty($student_data['medical_conditions']) || !empty($student_data['allergies'])): ?>
                            <p style="margin-top: 8px;">
                                <?php if (!empty($student_data['medical_conditions'])): ?>
                                    <span class="health-badge badge-warning">⚕️ <?php echo htmlspecialchars($student_data['medical_conditions']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($student_data['allergies'])): ?>
                                    <span class="health-badge badge-danger" style="margin-left: 8px;">⚠️ Allergies: <?php echo htmlspecialchars($student_data['allergies']); ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs Section for Health Records -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showTab('vaccination', event)">💉 Vaccination</button>
                        <button class="tab-btn" onclick="showTab('physical', event)">📏 Physical Exam</button>
                        <button class="tab-btn" onclick="showTab('deworming', event)">🪱 Deworming</button>
                        <button class="tab-btn" onclick="showTab('screening', event)">🔍 Health Screening</button>
                    </div>

                    <div class="tab-content">
                        <!-- Vaccination Tab -->
                        <div class="tab-pane active" id="vaccination">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 4h2a2 2 0 0 1 2 2v2"/>
                                        <path d="M4 18h2a2 2 0 0 1 2 2v2"/>
                                        <path d="M6 4h2a2 2 0 0 1 2 2v2"/>
                                        <path d="M14 20h2a2 2 0 0 0 2-2v-2"/>
                                        <path d="M12 12v4"/>
                                        <path d="M12 8h.01"/>
                                    </svg>
                                    Add Vaccination Record
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="save_vaccination">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Vaccine Name</label>
                                            <select name="vaccine_name" class="form-control" required>
                                                <option value="">Select Vaccine</option>
                                                <option value="BCG">BCG</option>
                                                <option value="Hepatitis B">Hepatitis B</option>
                                                <option value="DPT">DPT</option>
                                                <option value="Polio (OPV)">Polio (OPV)</option>
                                                <option value="Polio (IPV)">Polio (IPV)</option>
                                                <option value="MMR">MMR</option>
                                                <option value="HPV">HPV</option>
                                                <option value="Influenza">Influenza</option>
                                                <option value="COVID-19">COVID-19</option>
                                                <option value="Pneumococcal">Pneumococcal</option>
                                                <option value="Rotavirus">Rotavirus</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Dose Number</label>
                                            <input type="number" name="dose_number" class="form-control" min="1" value="1" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Date Administered</label>
                                            <input type="date" name="date_administered" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Next Dose Date (if applicable)</label>
                                            <input type="date" name="next_dose_date" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Administered By</label>
                                            <input type="text" name="administered_by" class="form-control" value="<?php echo htmlspecialchars($current_user_name); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Batch Number</label>
                                            <input type="text" name="batch_number" class="form-control" placeholder="Optional">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Remarks</label>
                                        <textarea name="remarks" class="form-control" placeholder="Any notes about the vaccination..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Save Vaccination Record</button>
                                </form>
                            </div>
                            
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Vaccine</th>
                                            <th>Dose</th>
                                            <th>Administered By</th>
                                            <th>Batch</th>
                                            <th>Next Dose</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['vaccinations'])): ?>
                                            <?php foreach ($student_data['vaccinations'] as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($record['date_administered'])); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($record['vaccine_name']); ?></strong></td>
                                                    <td>Dose <?php echo $record['dose_number']; ?></td>
                                                    <td><?php echo htmlspecialchars($record['administered_by']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['batch_number'] ?: 'N/A'); ?></td>
                                                    <td>
                                                        <?php if (!empty($record['next_dose_date'])): ?>
                                                            <?php echo date('M d, Y', strtotime($record['next_dose_date'])); ?>
                                                        <?php else: ?>
                                                            <small>N/A</small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="empty-state">
                                                    <p>No vaccination records found</p>
                                                    <small>Use the form above to add vaccination records</small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Physical Exam Tab -->
                        <div class="tab-pane" id="physical">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    Add Physical Exam
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="save_physical_exam">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Exam Date</label>
                                            <input type="date" name="exam_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Examined By</label>
                                            <input type="text" name="examined_by" class="form-control" value="<?php echo htmlspecialchars($current_user_name); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <label>Height (cm)</label>
                                            <input type="number" name="height" class="form-control" step="0.1" min="0" max="250" placeholder="170.5">
                                        </div>
                                        <div class="form-group">
                                            <label>Weight (kg)</label>
                                            <input type="number" name="weight" class="form-control" step="0.1" min="0" max="200" placeholder="65.5">
                                        </div>
                                        <div class="form-group">
                                            <label>BMI (auto)</label>
                                            <input type="text" class="form-control" id="bmi_display" readonly placeholder="Will calculate">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Vision (Left)</label>
                                            <input type="text" name="vision_left" class="form-control" placeholder="20/20">
                                        </div>
                                        <div class="form-group">
                                            <label>Vision (Right)</label>
                                            <input type="text" name="vision_right" class="form-control" placeholder="20/20">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Hearing (Left)</label>
                                            <input type="text" name="hearing_left" class="form-control" placeholder="Normal">
                                        </div>
                                        <div class="form-group">
                                            <label>Hearing (Right)</label>
                                            <input type="text" name="hearing_right" class="form-control" placeholder="Normal">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Dental Findings</label>
                                        <textarea name="dental_findings" class="form-control" placeholder="e.g., No cavities, need cleaning..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>General Assessment</label>
                                        <textarea name="general_assessment" class="form-control" placeholder="Overall health assessment..."></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Fit for School</label>
                                            <select name="fit_for_school" class="form-control">
                                                <option value="Yes">Yes</option>
                                                <option value="With Restrictions">With Restrictions</option>
                                                <option value="No">No</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Remarks</label>
                                            <input type="text" name="remarks" class="form-control" placeholder="Additional notes">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Save Physical Exam</button>
                                </form>
                            </div>
                            
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Height/Weight</th>
                                            <th>BMI</th>
                                            <th>Vision</th>
                                            <th>Fit for School</th>
                                            <th>Examined By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['physical_exams'])): ?>
                                            <?php foreach ($student_data['physical_exams'] as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($record['exam_date'])); ?></td>
                                                    <td><?php echo $record['height']; ?> cm / <?php echo $record['weight']; ?> kg</td>
                                                    <td><strong><?php echo $record['bmi']; ?></strong></td>
                                                    <td>L: <?php echo $record['vision_left'] ?: 'N/A'; ?>, R: <?php echo $record['vision_right'] ?: 'N/A'; ?></td>
                                                    <td>
                                                        <span class="health-badge <?php echo $record['fit_for_school'] == 'Yes' ? 'badge-success' : ($record['fit_for_school'] == 'With Restrictions' ? 'badge-warning' : 'badge-danger'); ?>">
                                                            <?php echo $record['fit_for_school']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['examined_by']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="empty-state">
                                                    <p>No physical exam records found</p>
                                                    <small>Use the form above to add physical exam records</small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Deworming Tab -->
                        <div class="tab-pane" id="deworming">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M8 12h8"/>
                                    </svg>
                                    Add Deworming Record
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="save_deworming">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Medicine Name</label>
                                            <select name="medicine_name" class="form-control" required>
                                                <option value="">Select Medicine</option>
                                                <option value="Albendazole 400mg">Albendazole 400mg</option>
                                                <option value="Mebendazole 500mg">Mebendazole 500mg</option>
                                                <option value="Pyrantel Pamoate">Pyrantel Pamoate</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Dosage</label>
                                            <input type="text" name="dosage" class="form-control" placeholder="e.g., 1 tablet">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Date Given</label>
                                            <input type="date" name="date_given" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Next Dose Date</label>
                                            <input type="date" name="next_dose_date" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Administered By</label>
                                        <input type="text" name="administered_by" class="form-control" value="<?php echo htmlspecialchars($current_user_name); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Adverse Reaction (if any)</label>
                                        <textarea name="adverse_reaction" class="form-control" placeholder="e.g., Nausea, dizziness..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Remarks</label>
                                        <textarea name="remarks" class="form-control" placeholder="Additional notes..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Save Deworming Record</button>
                                </form>
                            </div>
                            
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Medicine</th>
                                            <th>Dosage</th>
                                            <th>Administered By</th>
                                            <th>Next Dose</th>
                                            <th>Reaction</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['deworming'])): ?>
                                            <?php foreach ($student_data['deworming'] as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($record['date_given'])); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($record['medicine_name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($record['dosage'] ?: 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($record['administered_by']); ?></td>
                                                    <td>
                                                        <?php if (!empty($record['next_dose_date'])): ?>
                                                            <?php echo date('M d, Y', strtotime($record['next_dose_date'])); ?>
                                                        <?php else: ?>
                                                            <small>N/A</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['adverse_reaction'] ?: 'None'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="empty-state">
                                                    <p>No deworming records found</p>
                                                    <small>Use the form above to add deworming records</small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Health Screening Tab -->
                        <div class="tab-pane" id="screening">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 12h-4l-3 9-4-18-3 9H2"/>
                                    </svg>
                                    Add Health Screening
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="save_screening">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Screening Date</label>
                                            <input type="date" name="screening_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Screening Type</label>
                                            <select name="screening_type" class="form-control" required>
                                                <option value="">Select Type</option>
                                                <option value="Pre-participation">Pre-participation (Sports)</option>
                                                <option value="Annual Physical">Annual Physical</option>
                                                <option value="Vision Screening">Vision Screening</option>
                                                <option value="Hearing Screening">Hearing Screening</option>
                                                <option value="Blood Pressure Check">Blood Pressure Check</option>
                                                <option value="General Check-up">General Check-up</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Event (if applicable)</label>
                                            <select name="event_id" class="form-control">
                                                <option value="">Select Event</option>
                                                <?php foreach ($upcoming_events as $event): ?>
                                                    <option value="<?php echo $event['id'] ?? ''; ?>">
                                                        <?php echo htmlspecialchars($event['event_name'] ?? 'Event'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Event Name (if not in list)</label>
                                            <input type="text" name="event_name" class="form-control" placeholder="Event name">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row-3">
                                        <div class="form-group">
                                            <label>Blood Pressure</label>
                                            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                        </div>
                                        <div class="form-group">
                                            <label>Heart Rate (bpm)</label>
                                            <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                        </div>
                                        <div class="form-group">
                                            <label>Temperature (°C)</label>
                                            <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Oxygen Saturation (%)</label>
                                        <input type="number" name="oxygen_saturation" class="form-control" min="70" max="100" placeholder="98">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Findings</label>
                                        <textarea name="findings" class="form-control" placeholder="Screening findings..."></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Cleared for Participation</label>
                                            <select name="cleared_for_participation" class="form-control">
                                                <option value="Yes">Yes</option>
                                                <option value="With Restrictions">With Restrictions</option>
                                                <option value="No">No</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Restrictions</label>
                                            <input type="text" name="restrictions" class="form-control" placeholder="e.g., No strenuous activity">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Screened By</label>
                                        <input type="text" name="screened_by" class="form-control" value="<?php echo htmlspecialchars($current_user_name); ?>" required>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Save Screening Record</button>
                                </form>
                            </div>
                            
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type/Event</th>
                                            <th>Vitals</th>
                                            <th>Findings</th>
                                            <th>Cleared</th>
                                            <th>Screened By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['screenings'])): ?>
                                            <?php foreach ($student_data['screenings'] as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($record['screening_date'])); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($record['screening_type']); ?></strong>
                                                        <?php if (!empty($record['event_name'])): ?>
                                                            <br><small><?php echo htmlspecialchars($record['event_name']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($record['blood_pressure'])): ?>BP: <?php echo $record['blood_pressure']; ?><br><?php endif; ?>
                                                        <?php if (!empty($record['heart_rate'])): ?>HR: <?php echo $record['heart_rate']; ?> bpm<?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(substr($record['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <span class="health-badge <?php echo $record['cleared_for_participation'] == 'Yes' ? 'badge-success' : ($record['cleared_for_participation'] == 'With Restrictions' ? 'badge-warning' : 'badge-danger'); ?>">
                                                            <?php echo $record['cleared_for_participation']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['screened_by']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="empty-state">
                                                    <p>No screening records found</p>
                                                    <small>Use the form above to add health screening records</small>
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

                <!-- Health Metrics Section -->
                <?php if (!empty($health_metrics['data'])): ?>
                <div class="tabs-section" style="margin-top: 20px;">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showMetricsTab('overview', event)">📊 Health Overview</button>
                        <button class="tab-btn" onclick="showMetricsTab('conditions', event)">⚕️ Common Conditions</button>
                        <button class="tab-btn" onclick="showMetricsTab('allergies', event)">⚠️ Common Allergies</button>
                    </div>

                    <div class="tab-content">
                        <!-- Overview Tab -->
                        <div class="tab-pane active" id="overview-metrics">
                            <div class="metrics-grid">
                                <?php if (!empty($health_metrics['data']['conditions_by_year'])): ?>
                                    <?php foreach ($health_metrics['data']['conditions_by_year'] as $year_data): ?>
                                        <div class="metric-card">
                                            <div class="metric-title"><?php echo htmlspecialchars($year_data['year_level'] ?? 'All'); ?></div>
                                            <div class="metric-list">
                                                <div class="metric-item">
                                                    <span class="metric-label">Total Students</span>
                                                    <span class="metric-value"><?php echo $year_data['total_students'] ?? 0; ?></span>
                                                </div>
                                                <div class="metric-item">
                                                    <span class="metric-label">With Conditions</span>
                                                    <span class="metric-value"><?php echo $year_data['with_conditions'] ?? 0; ?></span>
                                                </div>
                                                <div class="metric-item">
                                                    <span class="metric-label">With Allergies</span>
                                                    <span class="metric-value"><?php echo $year_data['with_allergies'] ?? 0; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if (!empty($health_metrics['data']['blood_type_distribution'])): ?>
                                    <div class="metric-card">
                                        <div class="metric-title">Blood Type Distribution</div>
                                        <div class="metric-list">
                                            <?php foreach ($health_metrics['data']['blood_type_distribution'] as $blood): ?>
                                                <div class="metric-item">
                                                    <span class="metric-label">Type <?php echo htmlspecialchars($blood['blood_type'] ?? 'Unknown'); ?></span>
                                                    <span class="metric-value"><?php echo $blood['count'] ?? 0; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Common Conditions Tab -->
                        <div class="tab-pane" id="conditions-metrics">
                            <div class="metric-card">
                                <div class="metric-title">Most Common Medical Conditions</div>
                                <div class="metric-list">
                                    <?php if (!empty($health_metrics['data']['common_conditions'])): ?>
                                        <?php foreach ($health_metrics['data']['common_conditions'] as $condition): ?>
                                            <div class="metric-item">
                                                <span class="metric-label"><?php echo htmlspecialchars($condition['condition_name'] ?? 'Unknown'); ?></span>
                                                <span class="metric-value"><?php echo $condition['frequency'] ?? 0; ?> students</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state" style="padding: 30px;">
                                            <p>No data available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Common Allergies Tab -->
                        <div class="tab-pane" id="allergies-metrics">
                            <div class="metric-card">
                                <div class="metric-title">Most Common Allergies</div>
                                <div class="metric-list">
                                    <?php if (!empty($health_metrics['data']['common_allergies'])): ?>
                                        <?php foreach ($health_metrics['data']['common_allergies'] as $allergy): ?>
                                            <div class="metric-item">
                                                <span class="metric-label"><?php echo htmlspecialchars($allergy['allergy_name'] ?? 'Unknown'); ?></span>
                                                <span class="metric-value"><?php echo $allergy['frequency'] ?? 0; ?> students</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state" style="padding: 30px;">
                                            <p>No data available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
                You are accessing confidential health records for<br>
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
                This helps us maintain confidentiality of student health records
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

        // Tab functionality for main tabs
        function showTab(tabName, event) {
            document.querySelectorAll('.tab-pane').forEach(pane => {
                if (pane.id !== 'overview-metrics' && pane.id !== 'conditions-metrics' && pane.id !== 'allergies-metrics') {
                    pane.classList.remove('active');
                }
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Tab functionality for metrics tabs
        function showMetricsTab(tabName, event) {
            document.querySelectorAll('#overview-metrics, #conditions-metrics, #allergies-metrics').forEach(pane => {
                pane.classList.remove('active');
            });
            document.querySelectorAll('.tabs-section .tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabName + '-metrics').classList.add('active');
            event.target.classList.add('active');
        }

        // Show appointments for selected date
        function showAppointments(date) {
            window.location.href = '?tab=calendar&date=' + date + '&month=<?php echo $current_month; ?>&year=<?php echo $current_year; ?><?php echo !empty($student_id_search) ? '&student_id=' . urlencode($student_id_search) : ''; ?>';
        }

        // BMI Calculator
        document.querySelectorAll('input[name="height"], input[name="weight"]').forEach(input => {
            input.addEventListener('input', function() {
                const height = parseFloat(document.querySelector('input[name="height"]').value);
                const weight = parseFloat(document.querySelector('input[name="weight"]').value);
                const bmiDisplay = document.getElementById('bmi_display');
                
                if (height > 0 && weight > 0) {
                    const heightM = height / 100;
                    const bmi = (weight / (heightM * heightM)).toFixed(2);
                    bmiDisplay.value = bmi;
                } else {
                    bmiDisplay.value = '';
                }
            });
        });

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
            pageTitle.textContent = 'Health Programs';
        }
    </script>
</body>
</html>