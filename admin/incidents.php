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

// Create incidents table if not exists
try {
    // Main incidents table
    $db->exec("CREATE TABLE IF NOT EXISTS `incidents` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `incident_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `parent_name` varchar(100) DEFAULT NULL,
        `parent_contact` varchar(20) DEFAULT NULL,
        `incident_date` date NOT NULL,
        `incident_time` time NOT NULL,
        `location` varchar(100) NOT NULL,
        `incident_type` enum('Incident','Minor Injury','Emergency') NOT NULL,
        `description` text NOT NULL,
        `witness` varchar(100) DEFAULT NULL,
        `action_taken` text DEFAULT NULL,
        `temperature` decimal(4,2) DEFAULT NULL,
        `blood_pressure` varchar(10) DEFAULT NULL,
        `heart_rate` int(11) DEFAULT NULL,
        `treatment_given` text DEFAULT NULL,
        `medicine_given` text DEFAULT NULL,
        `disposition` varchar(100) DEFAULT NULL,
        `referred_to` varchar(100) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `incident_code` (`incident_code`),
        KEY `student_id` (`student_id`),
        KEY `incident_type` (`incident_type`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Parent notifications table
    $db->exec("CREATE TABLE IF NOT EXISTS `parent_notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `incident_id` int(11) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `parent_name` varchar(100) NOT NULL,
        `contact_number` varchar(20) NOT NULL,
        `notification_date` date NOT NULL,
        `notification_time` time NOT NULL,
        `called_by` varchar(100) NOT NULL,
        `response` enum('Will pick up','On the way','Not reachable','Will call back','Declined') NOT NULL,
        `notes` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `incident_id` (`incident_id`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Emergency cases table
    $db->exec("CREATE TABLE IF NOT EXISTS `emergency_cases` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `incident_id` int(11) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `symptoms` text NOT NULL,
        `vital_signs` text NOT NULL,
        `response_time` time NOT NULL,
        `ambulance_called` enum('Yes','No') DEFAULT 'No',
        `ambulance_time` time DEFAULT NULL,
        `hospital_referred` varchar(100) DEFAULT NULL,
        `parent_contacted` enum('Yes','No') DEFAULT 'No',
        `parent_contact_time` time DEFAULT NULL,
        `outcome` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `incident_id` (`incident_id`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}

// Get clinic stock for dropdown
function getClinicStock($db) {
    try {
        $query = "SELECT * FROM clinic_stock 
                  WHERE quantity > 0 
                  ORDER BY category, item_name ASC";
        $stmt = $db->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$clinic_stock = getClinicStock($db);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Save incident
    if ($_POST['action'] == 'save_incident') {
        try {
            $db->beginTransaction();
            
            // Generate incident code
            $prefix = match($_POST['incident_type']) {
                'Emergency' => 'EMG',
                'Minor Injury' => 'MIN',
                default => 'INC'
            };
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $incident_code = $prefix . '-' . $date . '-' . $random;
            
            // Get student name from form
            $student_name = $_POST['student_name'] ?? '';
            $grade_section = $_POST['grade_section'] ?? '';
            $parent_name = $_POST['parent_name'] ?? '';
            $parent_contact = $_POST['parent_contact'] ?? '';
            
            // Process medicines given
            $medicines_given = [];
            if (isset($_POST['medicine_items']) && is_array($_POST['medicine_items'])) {
                foreach ($_POST['medicine_items'] as $index => $item_id) {
                    if (!empty($item_id) && isset($_POST['medicine_quantity'][$index]) && $_POST['medicine_quantity'][$index] > 0) {
                        $item_quantity = intval($_POST['medicine_quantity'][$index]);
                        
                        // Get item details
                        $item_query = "SELECT * FROM clinic_stock WHERE id = :id";
                        $item_stmt = $db->prepare($item_query);
                        $item_stmt->bindParam(':id', $item_id);
                        $item_stmt->execute();
                        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($item && $item['quantity'] >= $item_quantity) {
                            // Update stock
                            $update_query = "UPDATE clinic_stock 
                                           SET quantity = quantity - :quantity 
                                           WHERE id = :id";
                            $update_stmt = $db->prepare($update_query);
                            $update_stmt->bindParam(':quantity', $item_quantity);
                            $update_stmt->bindParam(':id', $item_id);
                            $update_stmt->execute();
                            
                            $medicines_given[] = $item_quantity . ' ' . $item['unit'] . ' of ' . $item['item_name'];
                        }
                    }
                }
            }
            
            $medicine_given_text = !empty($medicines_given) ? implode(', ', $medicines_given) : null;
            
            // Insert incident
            $query = "INSERT INTO incidents (
                incident_code, student_id, student_name, grade_section, parent_name, parent_contact,
                incident_date, incident_time, location, incident_type,
                description, witness, action_taken, 
                temperature, blood_pressure, heart_rate,
                treatment_given, medicine_given, disposition, referred_to,
                created_by
            ) VALUES (
                :incident_code, :student_id, :student_name, :grade_section, :parent_name, :parent_contact,
                :incident_date, :incident_time, :location, :incident_type,
                :description, :witness, :action_taken,
                :temperature, :blood_pressure, :heart_rate,
                :treatment_given, :medicine_given, :disposition, :referred_to,
                :created_by
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':incident_code', $incident_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $student_name);
            $stmt->bindParam(':grade_section', $grade_section);
            $stmt->bindParam(':parent_name', $parent_name);
            $stmt->bindParam(':parent_contact', $parent_contact);
            $stmt->bindParam(':incident_date', $_POST['incident_date']);
            $stmt->bindParam(':incident_time', $_POST['incident_time']);
            $stmt->bindParam(':location', $_POST['location']);
            $stmt->bindParam(':incident_type', $_POST['incident_type']);
            $stmt->bindParam(':description', $_POST['description']);
            $stmt->bindParam(':witness', $_POST['witness']);
            $stmt->bindParam(':action_taken', $_POST['action_taken']);
            $stmt->bindParam(':temperature', $_POST['temperature']);
            $stmt->bindParam(':blood_pressure', $_POST['blood_pressure']);
            $stmt->bindParam(':heart_rate', $_POST['heart_rate']);
            $stmt->bindParam(':treatment_given', $_POST['treatment_given']);
            $stmt->bindParam(':medicine_given', $medicine_given_text);
            $stmt->bindParam(':disposition', $_POST['disposition']);
            $stmt->bindParam(':referred_to', $_POST['referred_to']);
            $stmt->bindParam(':created_by', $current_user_id);
            $stmt->execute();
            
            $incident_id = $db->lastInsertId();
            
            // Save parent notification if provided
            if (!empty($_POST['parent_notification']) && $_POST['parent_notification'] == 'yes') {
                if (!empty($parent_name) && !empty($parent_contact)) {
                    $notif_query = "INSERT INTO parent_notifications (
                        incident_id, student_id, parent_name, contact_number,
                        notification_date, notification_time, called_by, response, notes
                    ) VALUES (
                        :incident_id, :student_id, :parent_name, :contact_number,
                        :notification_date, :notification_time, :called_by, :response, :notes
                    )";
                    
                    $notif_stmt = $db->prepare($notif_query);
                    $notif_stmt->bindParam(':incident_id', $incident_id);
                    $notif_stmt->bindParam(':student_id', $_POST['student_id']);
                    $notif_stmt->bindParam(':parent_name', $parent_name);
                    $notif_stmt->bindParam(':contact_number', $parent_contact);
                    $notif_stmt->bindParam(':notification_date', $_POST['incident_date']);
                    $notif_stmt->bindParam(':notification_time', $_POST['notification_time']);
                    $notif_stmt->bindParam(':called_by', $current_user_name);
                    $notif_stmt->bindParam(':response', $_POST['parent_response']);
                    $notif_stmt->bindParam(':notes', $_POST['notification_notes']);
                    $notif_stmt->execute();
                }
            }
            
            // Save emergency case if type is Emergency
            if ($_POST['incident_type'] == 'Emergency') {
                $emerg_query = "INSERT INTO emergency_cases (
                    incident_id, student_id, symptoms, vital_signs,
                    response_time, ambulance_called, ambulance_time,
                    hospital_referred, parent_contacted, parent_contact_time, outcome
                ) VALUES (
                    :incident_id, :student_id, :symptoms, :vital_signs,
                    :response_time, :ambulance_called, :ambulance_time,
                    :hospital_referred, :parent_contacted, :parent_contact_time, :outcome
                )";
                
                $emerg_stmt = $db->prepare($emerg_query);
                $emerg_stmt->bindParam(':incident_id', $incident_id);
                $emerg_stmt->bindParam(':student_id', $_POST['student_id']);
                $emerg_stmt->bindParam(':symptoms', $_POST['symptoms']);
                
                // Combine vitals for emergency
                $emergency_vitals = "Temp: " . ($_POST['temperature'] ?? 'N/A') . 
                                   "°C, BP: " . ($_POST['blood_pressure'] ?? 'N/A') . 
                                   ", HR: " . ($_POST['heart_rate'] ?? 'N/A') . " bpm";
                $emerg_stmt->bindParam(':vital_signs', $emergency_vitals);
                
                $emerg_stmt->bindParam(':response_time', $_POST['response_time']);
                $emerg_stmt->bindParam(':ambulance_called', $_POST['ambulance_called']);
                $emerg_stmt->bindParam(':ambulance_time', $_POST['ambulance_time']);
                $emerg_stmt->bindParam(':hospital_referred', $_POST['hospital_referred']);
                $emerg_stmt->bindParam(':parent_contacted', $_POST['parent_contacted_emergency']);
                $emerg_stmt->bindParam(':parent_contact_time', $_POST['parent_contact_time']);
                $emerg_stmt->bindParam(':outcome', $_POST['emergency_outcome']);
                $emerg_stmt->execute();
            }
            
            $db->commit();
            $success_message = "Incident logged successfully! Incident Code: " . $incident_code;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get statistics
$stats = [];

try {
    // Today's incidents
    $query = "SELECT COUNT(*) as total FROM incidents WHERE DATE(incident_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_incidents'] = $result ? $result['total'] : 0;
    
    // This week's incidents
    $query = "SELECT COUNT(*) as total FROM incidents 
              WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['week_incidents'] = $result ? $result['total'] : 0;
    
    // Incidents by type
    $query = "SELECT incident_type, COUNT(*) as count 
              FROM incidents 
              WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              GROUP BY incident_type";
    $stmt = $db->query($query);
    $stats['by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent incidents
    $query = "SELECT i.*, u.full_name as reported_by_name 
              FROM incidents i
              LEFT JOIN users u ON i.created_by = u.id
              ORDER BY i.incident_date DESC, i.incident_time DESC 
              LIMIT 10";
    $stmt = $db->query($query);
    $stats['recent_incidents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pending parent notifications
    $query = "SELECT COUNT(*) as total FROM parent_notifications 
              WHERE response = 'Not reachable' AND DATE(notification_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_notifications'] = $result ? $result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = [
        'today_incidents' => 0,
        'week_incidents' => 0,
        'by_type' => [],
        'recent_incidents' => [],
        'pending_notifications' => 0
    ];
}

// Function to extract parent info from API response
function extractParentInfo($student) {
    $parent_name = '';
    $parent_contact = '';
    
    // Try to get from emergency_contact field (might contain name and number)
    if (!empty($student['emergency_contact'])) {
        // If it contains both name and number separated by common delimiters
        if (strpos($student['emergency_contact'], '/') !== false) {
            $parts = explode('/', $student['emergency_contact']);
            $parent_name = trim($parts[0]);
            if (isset($parts[1])) {
                $parent_contact = trim($parts[1]);
            }
        } elseif (strpos($student['emergency_contact'], ',') !== false) {
            $parts = explode(',', $student['emergency_contact']);
            $parent_name = trim($parts[0]);
            if (isset($parts[1])) {
                $parent_contact = trim($parts[1]);
            }
        } else {
            // Assume it's just a name
            $parent_name = $student['emergency_contact'];
        }
    }
    
    // Try to get from emergency_phone if available
    if (empty($parent_contact) && !empty($student['emergency_phone'])) {
        $parent_contact = $student['emergency_phone'];
    }
    
    // If still empty, try contact_no as fallback
    if (empty($parent_contact) && !empty($student['contact_no'])) {
        $parent_contact = $student['contact_no'];
    }
    
    return [
        'name' => $parent_name,
        'contact' => $parent_contact
    ];
}

// Search for student if ID provided
if (!empty($student_id_search) && !isset($_POST['action'])) {
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
                    
                    // Extract parent info
                    $parent_info = extractParentInfo($student);
                    $student_data['parent_name'] = $parent_info['name'];
                    $student_data['parent_contact'] = $parent_info['contact'];
                    
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

// Get incidents for display
function getIncidentsByType($db, $type = null) {
    try {
        $query = "SELECT i.*, u.full_name as reported_by_name
                  FROM incidents i
                  LEFT JOIN users u ON i.created_by = u.id";
        
        if ($type) {
            $query .= " WHERE i.incident_type = :type";
        }
        
        $query .= " ORDER BY i.incident_date DESC, i.incident_time DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($type) {
            $stmt->bindParam(':type', $type);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$incidents_all = getIncidentsByType($db);
$incidents_emergency = getIncidentsByType($db, 'Emergency');
$incidents_minor = getIncidentsByType($db, 'Minor Injury');
$incidents_regular = getIncidentsByType($db, 'Incident');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incidents & Emergencies | MedFlow Clinic Management System</title>
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
            background: #f4f7fb;
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
            padding: 25px 35px 35px 35px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: #f4f7fb;
        }

        .main-content.expanded {
            margin-left: 110px;
        }

        .dashboard-container {
            position: relative;
            z-index: 1;
        }

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            padding: 30px 35px;
            border-radius: 24px;
            color: white;
            box-shadow: 0 10px 30px rgba(25, 25, 112, 0.2);
        }

        .welcome-section h1 {
            font-size: 2.4rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            font-size: 1rem;
            opacity: 0.9;
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
            border-radius: 20px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(207, 216, 220, 0.5);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(25, 25, 112, 0.15);
            border-color: #191970;
        }

        .stat-icon {
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 30px;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .warning-badge {
            background: #ff4757;
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 6px;
            box-shadow: 0 3px 8px rgba(255, 71, 87, 0.3);
        }

        /* Alert Messages */
        .alert {
            padding: 18px 24px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #2e7d32 0%, #3b9240 100%);
            color: white;
        }

        .alert-error {
            background: linear-gradient(135deg, #c62828 0%, #d32f2f 100%);
            color: white;
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
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(207, 216, 220, 0.3);
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #f0f4f8;
            padding-bottom: 15px;
        }

        .card-title svg {
            width: 24px;
            height: 24px;
            stroke: #191970;
        }

        .incident-type-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .type-emergency {
            background: #ff4757;
            color: white;
            box-shadow: 0 3px 8px rgba(255, 71, 87, 0.3);
        }

        .type-minor {
            background: #ffa502;
            color: white;
            box-shadow: 0 3px 8px rgba(255, 165, 2, 0.3);
        }

        .type-incident {
            background: #1e90ff;
            color: white;
            box-shadow: 0 3px 8px rgba(30, 144, 255, 0.3);
        }

        .search-form {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            font-size: 0.95rem;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            transition: all 0.3s ease;
            background: white;
            color: #1e293b;
        }

        .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 4px rgba(25, 25, 112, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            background-size: 16px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 14px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            color: white;
            width: 100%;
            box-shadow: 0 5px 15px rgba(25, 25, 112, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(25, 25, 112, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #c62828 0%, #d32f2f 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(198, 40, 40, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(198, 40, 40, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffa502 0%, #ffb236 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(255, 165, 2, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(255, 165, 2, 0.4);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(207, 216, 220, 0.3);
        }

        .type-list {
            list-style: none;
        }

        .type-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid #f0f4f8;
        }

        .type-item:last-child {
            border-bottom: none;
        }

        .type-name {
            font-weight: 500;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .type-count {
            background: #f0f4f8;
            color: #191970;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Incident Form Card */
        .incident-form-card {
            background: white;
            border-radius: 24px;
            padding: 35px;
            margin-bottom: 35px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(207, 216, 220, 0.3);
            animation: fadeInUp 0.8s ease;
        }

        .student-info-bar {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 18px;
            padding: 25px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 25px;
            border: 1px solid #e2e8f0;
        }

        .student-avatar-lg {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            color: white;
            box-shadow: 0 8px 20px rgba(25, 25, 112, 0.3);
        }

        .student-details h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
        }

        .student-details .student-meta {
            display: flex;
            gap: 20px;
            color: #64748b;
            font-size: 0.95rem;
        }

        .student-details .student-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .medical-alert {
            background: #ff4757;
            color: white;
            padding: 12px 18px;
            border-radius: 12px;
            margin-top: 12px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(255, 71, 87, 0.3);
        }

        /* Incident Type Selector */
        .incident-type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 35px;
        }

        .type-option {
            position: relative;
        }

        .type-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .type-option label {
            display: block;
            padding: 25px 20px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .type-option input[type="radio"]:checked + label {
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            border-color: #191970;
            color: white;
            transform: scale(1.02);
            box-shadow: 0 10px 25px rgba(25, 25, 112, 0.3);
        }

        .type-option input[type="radio"]:checked + label .type-icon,
        .type-option input[type="radio"]:checked + label .type-title,
        .type-option input[type="radio"]:checked + label .type-desc {
            color: white;
        }

        .type-option label:hover {
            border-color: #191970;
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(25, 25, 112, 0.1);
        }

        .type-icon {
            font-size: 32px;
            margin-bottom: 12px;
            color: #191970;
        }

        .type-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .type-desc {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        /* Sections */
        .form-section {
            background: #f8fafc;
            border-radius: 18px;
            padding: 25px;
            margin: 25px 0;
            border: 1px solid #e2e8f0;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #191970;
            font-weight: 700;
            font-size: 1.1rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
        }

        .section-header svg {
            width: 22px;
            height: 22px;
            stroke: #191970;
        }

        /* Vital Signs */
        .vital-signs-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            background: white;
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .vital-input {
            text-align: center;
        }

        .vital-input label {
            display: block;
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .vital-input input {
            text-align: center;
            font-size: 1.1rem;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            width: 100%;
        }

        .vital-input input:focus {
            border-color: #191970;
            outline: none;
        }

        /* Medicine Items */
        .medicine-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }

        .medicine-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .medicine-header h4 {
            color: #191970;
            font-size: 1rem;
            font-weight: 600;
        }

        .medicine-row {
            display: grid;
            grid-template-columns: 3fr 1fr 1fr auto;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
            background: #f8fafc;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .medicine-select {
            width: 100%;
        }

        .medicine-quantity {
            width: 100%;
        }

        .medicine-stock {
            font-size: 0.7rem;
            color: #64748b;
        }

        .remove-medicine {
            background: #fee2e2;
            color: #c62828;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 1.2rem;
        }

        .remove-medicine:hover {
            background: #c62828;
            color: white;
        }

        .add-medicine-btn {
            background: none;
            border: 2px dashed #191970;
            color: #191970;
            padding: 12px;
            border-radius: 12px;
            width: 100%;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .add-medicine-btn:hover {
            background: rgba(25, 25, 112, 0.1);
        }

        /* Parent Notification Section */
        .parent-info {
            background: linear-gradient(135deg, #e8f4fd 0%, #d9eafb 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #b8d6f5;
        }

        .parent-details {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: center;
        }

        .auto-filled {
            background: #e8f5e9;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            color: #2e7d32;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }

        .notification-toggle {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #191970;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .notification-details {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .notification-details.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Disposition Grid */
        .disposition-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin: 20px 0;
        }

        .disposition-option {
            position: relative;
        }

        .disposition-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .disposition-option label {
            display: block;
            padding: 14px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .disposition-option input[type="radio"]:checked + label {
            background: linear-gradient(135deg, #191970 0%, #2a2a9e 100%);
            border-color: #191970;
            color: white;
        }

        .disposition-option label:hover {
            border-color: #191970;
        }

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(207, 216, 220, 0.3);
            overflow: hidden;
            margin-top: 35px;
            animation: fadeInUp 0.9s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid #f0f4f8;
            background: #f8fafc;
            overflow-x: auto;
            padding: 5px;
        }

        .tab-btn {
            padding: 16px 28px;
            background: none;
            border: none;
            font-size: 0.95rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
            border-radius: 12px;
            margin: 0 2px;
        }

        .tab-btn:hover {
            color: #191970;
            background: white;
        }

        .tab-btn.active {
            color: #191970;
            background: white;
            box-shadow: 0 5px 15px rgba(25, 25, 112, 0.1);
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
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 18px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
        }

        .data-table td {
            padding: 18px 16px;
            font-size: 0.9rem;
            color: #1e293b;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table tr:hover td {
            background: #f8fafc;
        }

        .incident-code {
            font-weight: 600;
            color: #191970;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
            stroke: #64748b;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .empty-state small {
            font-size: 0.9rem;
            opacity: 0.6;
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
            
            .incident-type-grid,
            .disposition-grid {
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
            
            .parent-details {
                grid-template-columns: 1fr;
            }
            
            .medicine-row {
                grid-template-columns: 1fr;
            }
            
            .vital-signs-grid {
                grid-template-columns: 1fr;
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
                    <h1>🚑 Incidents & Emergencies</h1>
                    <p>Document and manage school incidents, minor injuries, and emergency cases with complete parent notification tracking.</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                            <path d="M22 4L12 14.01L9 11.01"/>
                        </svg>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
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
                            <h3><?php echo $stats['today_incidents']; ?></h3>
                            <p>Today's Incidents</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['week_incidents']; ?></h3>
                            <p>This Week</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🚨</div>
                        <div class="stat-info">
                            <h3>
                                <?php 
                                $emergency_count = 0;
                                foreach ($stats['by_type'] as $type) {
                                    if ($type['incident_type'] == 'Emergency') {
                                        $emergency_count = $type['count'];
                                        break;
                                    }
                                }
                                echo $emergency_count;
                                ?>
                            </h3>
                            <p>Emergencies</p>
                            <?php if ($emergency_count > 0): ?>
                                <div class="warning-badge">⚠️ Needs Review</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📞</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_notifications']; ?></h3>
                            <p>Pending Calls</p>
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
                                <label for="student_id">Student ID / LRN</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       placeholder="Enter student ID" 
                                       value="<?php echo htmlspecialchars($student_id_search); ?>" 
                                       required
                                       onkeyup="if(event.key === 'Enter') this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-primary">Search Student</button>
                        </form>

                        <?php if ($search_error): ?>
                            <div style="margin-top: 20px; padding: 15px; background: #fee2e2; border-radius: 12px; color: #c62828; font-size: 0.95rem; border: 1px solid #fecaca;">
                                <?php echo $search_error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Quick Type Stats -->
                        <?php if (!empty($stats['by_type'])): ?>
                            <div style="margin-top: 30px;">
                                <div style="font-size: 1rem; font-weight: 600; color: #191970; margin-bottom: 15px;">
                                    📊 Incidents by Type (30 days)
                                </div>
                                <ul class="type-list">
                                    <?php foreach ($stats['by_type'] as $type): ?>
                                        <li class="type-item">
                                            <span class="type-name">
                                                <span class="incident-type-badge type-<?php echo strtolower($type['incident_type']); ?>">
                                                    <?php echo $type['incident_type']; ?>
                                                </span>
                                            </span>
                                            <span class="type-count"><?php echo $type['count']; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
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
                            Quick Overview
                        </div>
                        
                        <div style="margin-bottom: 25px;">
                            <div style="font-size: 1rem; color: #64748b; margin-bottom: 8px;">Total Incidents This Month</div>
                            <div style="font-size: 3rem; font-weight: 700; color: #191970;">
                                <?php 
                                $total = 0;
                                foreach ($stats['by_type'] as $type) {
                                    $total += $type['count'];
                                }
                                echo $total;
                                ?>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 20px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: #ffa502;"><?php echo count($incidents_minor); ?></div>
                                <div style="font-size: 0.85rem; color: #64748b;">Minor Injuries</div>
                            </div>
                            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 20px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: #ff4757;"><?php echo count($incidents_emergency); ?></div>
                                <div style="font-size: 0.85rem; color: #64748b;">Emergencies</div>
                            </div>
                        </div>

                        <div style="margin-top: 25px;">
                            <div style="font-size: 0.95rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                Common Locations
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <span class="incident-type-badge type-incident">🏫 Classroom</span>
                                <span class="incident-type-badge type-incident">🏃 Gym</span>
                                <span class="incident-type-badge type-incident">⚽ Field</span>
                                <span class="incident-type-badge type-incident">🚶 Hallway</span>
                                <span class="incident-type-badge type-incident">🍽️ Canteen</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($student_data): ?>
                <!-- Incident Form -->
                <div class="incident-form-card">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        Log New Incident / Emergency
                    </div>

                    <div class="student-info-bar">
                        <div class="student-avatar-lg">
                            <?php echo strtoupper(substr($student_data['full_name'] ?? 'NA', 0, 2)); ?>
                        </div>
                        <div class="student-details">
                            <h2><?php echo htmlspecialchars($student_data['full_name'] ?? 'N/A'); ?></h2>
                            <div class="student-meta">
                                <span>🆔 <?php echo htmlspecialchars($student_data['student_id']); ?></span>
                                <span>📚 Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?></span>
                                <?php if (!empty($student_data['blood_type'])): ?>
                                    <span>🩸 Blood Type: <?php echo htmlspecialchars($student_data['blood_type']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($student_data['medical_conditions'])): ?>
                                <div class="medical-alert">
                                    <span>⚠️</span>
                                    <span><strong>Medical Condition:</strong> <?php echo htmlspecialchars($student_data['medical_conditions']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" action="" id="incidentForm">
                        <input type="hidden" name="action" value="save_incident">
                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                        <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                        <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">

                        <!-- Incident Type Selection -->
                        <div class="incident-type-grid">
                            <div class="type-option">
                                <input type="radio" name="incident_type" id="type-incident" value="Incident" checked onchange="toggleIncidentSections()">
                                <label for="type-incident">
                                    <div class="type-icon">📋</div>
                                    <div class="type-title">Incident Record</div>
                                    <div class="type-desc">School-related events</div>
                                </label>
                            </div>
                            <div class="type-option">
                                <input type="radio" name="incident_type" id="type-minor" value="Minor Injury" onchange="toggleIncidentSections()">
                                <label for="type-minor">
                                    <div class="type-icon">🩹</div>
                                    <div class="type-title">Minor Injury</div>
                                    <div class="type-desc">Small injuries, quick treatment</div>
                                </label>
                            </div>
                            <div class="type-option">
                                <input type="radio" name="incident_type" id="type-emergency" value="Emergency" onchange="toggleIncidentSections()">
                                <label for="type-emergency">
                                    <div class="type-icon">🚨</div>
                                    <div class="type-title">Emergency Case</div>
                                    <div class="type-desc">Serious, requires immediate action</div>
                                </label>
                            </div>
                        </div>

                        <!-- Parent Information (Auto-filled) -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                Parent/Guardian Information
                                <span class="auto-filled">✓ Auto-filled from student record</span>
                            </div>
                            
                            <div class="parent-info">
                                <div class="parent-details">
                                    <div>
                                        <label style="font-size: 0.8rem; color: #64748b; margin-bottom: 5px; display: block;">Parent Name</label>
                                        <input type="text" name="parent_name" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['parent_name'] ?? ''); ?>" 
                                               placeholder="Parent/Guardian name" id="parent_name" readonly
                                               style="background: #f0f4f8; font-weight: 500;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: #64748b; margin-bottom: 5px; display: block;">Contact Number</label>
                                        <input type="text" name="parent_contact" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['parent_contact'] ?? ''); ?>" 
                                               placeholder="Contact number" id="parent_contact" readonly
                                               style="background: #f0f4f8; font-weight: 500;">
                                    </div>
                                    <div style="display: flex; align-items: center; justify-content: center;">
                                        <span style="background: #2e7d32; color: white; padding: 5px 15px; border-radius: 30px; font-size: 0.8rem;">
                                            Emergency Contact
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Basic Incident Information -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                Incident Details
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>📅 Date</label>
                                    <input type="date" name="incident_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>⏰ Time</label>
                                    <input type="time" name="incident_time" class="form-control" value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>📍 Location</label>
                                <select name="location" class="form-control" required>
                                    <option value="">Select location</option>
                                    <option value="Classroom">🏫 Classroom</option>
                                    <option value="Gym">🏃 Gym / PE Area</option>
                                    <option value="Field">⚽ Sports Field</option>
                                    <option value="Hallway">🚶 Hallway / Corridor</option>
                                    <option value="Canteen">🍽️ Canteen</option>
                                    <option value="Comfort Room">🚻 Comfort Room</option>
                                    <option value="Library">📚 Library</option>
                                    <option value="Stairs">🪜 Stairs</option>
                                    <option value="School Grounds">🏛️ School Grounds</option>
                                    <option value="Other">📍 Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>📝 Description of Incident</label>
                                <textarea name="description" class="form-control" placeholder="What happened? Be specific..." required></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>👁️ Witness (Optional)</label>
                                    <input type="text" name="witness" class="form-control" placeholder="Name of witness">
                                </div>
                                <div class="form-group">
                                    <label>⚡ Action Taken</label>
                                    <input type="text" name="action_taken" class="form-control" placeholder="e.g., First aid given, sent to clinic">
                                </div>
                            </div>
                        </div>

                        <!-- Vital Signs Section -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 12h-4l-3 9-4-18-3 9H2"/>
                                </svg>
                                Vital Signs
                            </div>
                            
                            <div class="vital-signs-grid">
                                <div class="vital-input">
                                    <label>🌡️ Temperature (°C)</label>
                                    <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                </div>
                                <div class="vital-input">
                                    <label>❤️ Blood Pressure</label>
                                    <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                </div>
                                <div class="vital-input">
                                    <label>💓 Heart Rate (bpm)</label>
                                    <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                </div>
                            </div>
                        </div>

                        <!-- Treatment Section -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                    <path d="M2 17L12 22L22 17"/>
                                    <path d="M2 12L12 17L22 12"/>
                                </svg>
                                Assessment & Treatment
                            </div>
                            
                            <div class="form-group">
                                <label>💊 Treatment Given</label>
                                <input type="text" name="treatment_given" class="form-control" placeholder="e.g., Wound cleaned, cold compress applied">
                            </div>

                            <!-- Medicine Given Section -->
                            <div class="medicine-section">
                                <div class="medicine-header">
                                    <h4>💊 Medicine Given (if any)</h4>
                                    <span style="font-size: 0.8rem; color: #64748b;">Items will be deducted from inventory</span>
                                </div>
                                
                                <div id="medicine-container">
                                    <!-- Medicine rows will be added here dynamically -->
                                </div>
                                
                                <button type="button" class="add-medicine-btn" onclick="addMedicineRow()">
                                    + Add Medicine or Supply
                                </button>
                            </div>
                        </div>

                        <!-- Disposition Section -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                </svg>
                                Disposition & Referral
                            </div>
                            
                            <div class="disposition-grid">
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-return" value="Returned to class" checked>
                                    <label for="disp-return">🏠 Return to Class</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-home" value="Sent home">
                                    <label for="disp-home">🚶 Sent Home</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-refer" value="Referred to hospital">
                                    <label for="disp-refer">🏥 Refer to Hospital</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-observe" value="Observed in clinic">
                                    <label for="disp-observe">👁️ Observe in Clinic</label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>🏥 Referred To (if applicable)</label>
                                <input type="text" name="referred_to" class="form-control" placeholder="e.g., Hospital, Health Center">
                            </div>
                        </div>

                        <!-- Emergency Case Section (Shown only for Emergency) -->
                        <div class="form-section" id="emergency-section" style="display: none; border-left: 4px solid #ff4757;">
                            <div class="section-header" style="color: #c62828;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 10v4M12 6v8M6 14v-2"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                                Emergency Case Details
                            </div>
                            
                            <div class="form-group">
                                <label>🚨 Symptoms</label>
                                <textarea name="symptoms" class="form-control" placeholder="Detailed symptoms..."></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>⏱️ Response Time</label>
                                    <input type="time" name="response_time" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>🚑 Ambulance Called</label>
                                    <select name="ambulance_called" class="form-control">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>⏱️ Ambulance Time</label>
                                    <input type="time" name="ambulance_time" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>🏥 Hospital Referred</label>
                                    <input type="text" name="hospital_referred" class="form-control" placeholder="Hospital name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>📝 Outcome</label>
                                <textarea name="emergency_outcome" class="form-control" placeholder="Final outcome of emergency..."></textarea>
                            </div>
                        </div>

                        <!-- Parent Notification Toggle -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                                Parent Notification
                            </div>
                            
                            <div class="notification-toggle">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="parentNotificationToggle" onchange="toggleNotificationSection()">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span style="color: #1e293b; font-weight: 500;">Notify parent/guardian about this incident</span>
                            </div>

                            <div class="notification-details" id="notificationDetails">
                                <input type="hidden" name="parent_notification" id="parent_notification" value="no">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>📞 Notification Time</label>
                                        <input type="time" name="notification_time" class="form-control" value="<?php echo date('H:i'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>📋 Response</label>
                                        <select name="parent_response" class="form-control">
                                            <option value="">Select response</option>
                                            <option value="Will pick up">Will pick up</option>
                                            <option value="On the way">On the way</option>
                                            <option value="Will call back">Will call back</option>
                                            <option value="Not reachable">Not reachable</option>
                                            <option value="Declined">Declined</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>📝 Notes</label>
                                    <textarea name="notification_notes" class="form-control" placeholder="Additional notes about parent contact..."></textarea>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 20px; padding: 16px;">
                            Save Incident Record
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Tabs Section for Incident Lists -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showTab('all', event)">📋 All Incidents</button>
                        <button class="tab-btn" onclick="showTab('emergency', event)">🚨 Emergencies</button>
                        <button class="tab-btn" onclick="showTab('minor', event)">🩹 Minor Injuries</button>
                        <button class="tab-btn" onclick="showTab('regular', event)">📝 Regular Incidents</button>
                    </div>

                    <div class="tab-content">
                        <!-- All Incidents Tab -->
                        <div class="tab-pane active" id="all">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h2 style="color: #191970;">📋 Recent Incidents</h2>
                                <span style="color: #64748b;">Last 50 records</span>
                            </div>
                            
                            <?php if (!empty($incidents_all)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Type</th>
                                                <th>Location</th>
                                                <th>Parent Notified</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($incidents_all as $incident): ?>
                                                <tr>
                                                    <td>
                                                        <span class="incident-code"><?php echo htmlspecialchars($incident['incident_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($incident['student_name']); ?></strong><br>
                                                        <small style="color: #64748b;">ID: <?php echo htmlspecialchars($incident['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($incident['incident_date'])); ?><br>
                                                        <small style="color: #64748b;"><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="incident-type-badge type-<?php echo strtolower($incident['incident_type']); ?>">
                                                            <?php echo $incident['incident_type']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td>
                                                        <?php if (!empty($incident['parent_name'])): ?>
                                                            <span style="background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 30px; font-size: 0.75rem;">
                                                                ✓ Contact on file
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="background: #fee2e2; color: #c62828; padding: 4px 10px; border-radius: 30px; font-size: 0.75rem;">
                                                                No contact
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary" onclick="viewIncident(<?php echo $incident['id']; ?>)">View</button>
                                                    </td>
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
                                    <small>Use the form above to log incidents</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Emergencies Tab -->
                        <div class="tab-pane" id="emergency">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h2 style="color: #ff4757;">🚨 Emergency Cases</h2>
                                <span style="color: #64748b;">Requires immediate attention</span>
                            </div>
                            
                            <?php if (!empty($incidents_emergency)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Location</th>
                                                <th>Vitals</th>
                                                <th>Action Taken</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($incidents_emergency as $incident): ?>
                                                <tr>
                                                    <td>
                                                        <span class="incident-code"><?php echo htmlspecialchars($incident['incident_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($incident['student_name']); ?></strong><br>
                                                        <small><?php echo htmlspecialchars($incident['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($incident['incident_date'])); ?><br>
                                                        <small><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td>
                                                        <?php if ($incident['temperature']): ?>
                                                            <span class="vital-badge">🌡️ <?php echo $incident['temperature']; ?>°C</span>
                                                        <?php endif; ?>
                                                        <?php if ($incident['blood_pressure']): ?>
                                                            <span class="vital-badge">❤️ <?php echo $incident['blood_pressure']; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($incident['action_taken'] ?: 'N/A'); ?></small>
                                                    </td>
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
                                    <p>No emergency cases</p>
                                    <small>All clear! No emergencies recorded</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Minor Injuries Tab -->
                        <div class="tab-pane" id="minor">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h2 style="color: #ffa502;">🩹 Minor Injuries</h2>
                                <span style="color: #64748b;">Quick treatment cases</span>
                            </div>
                            
                            <?php if (!empty($incidents_minor)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Injury</th>
                                                <th>Treatment</th>
                                                <th>Medicine</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($incidents_minor as $incident): ?>
                                                <tr>
                                                    <td>
                                                        <span class="incident-code"><?php echo htmlspecialchars($incident['incident_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($incident['student_name']); ?></strong><br>
                                                        <small><?php echo htmlspecialchars($incident['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($incident['incident_date'])); ?><br>
                                                        <small><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(substr($incident['description'], 0, 50)); ?></td>
                                                    <td><?php echo htmlspecialchars($incident['treatment_given'] ?: 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($incident['medicine_given'] ?: 'None'); ?></td>
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
                                    <p>No minor injuries</p>
                                    <small>All students are safe</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Regular Incidents Tab -->
                        <div class="tab-pane" id="regular">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h2 style="color: #1e90ff;">📝 Regular Incidents</h2>
                                <span style="color: #64748b;">School-related events</span>
                            </div>
                            
                            <?php if (!empty($incidents_regular)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Location</th>
                                                <th>Incident</th>
                                                <th>Witness</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($incidents_regular as $incident): ?>
                                                <tr>
                                                    <td>
                                                        <span class="incident-code"><?php echo htmlspecialchars($incident['incident_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($incident['student_name']); ?></strong><br>
                                                        <small><?php echo htmlspecialchars($incident['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($incident['incident_date'])); ?><br>
                                                        <small><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($incident['description'], 0, 50)); ?></td>
                                                    <td><?php echo htmlspecialchars($incident['witness'] ?: 'None'); ?></td>
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
                                    <p>No regular incidents</p>
                                    <small>No school-related incidents reported</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Template for medicine row -->
    <template id="medicine-row-template">
        <div class="medicine-row">
            <select name="medicine_items[]" class="form-control medicine-select" onchange="updateMedicineStock(this)">
                <option value="">Select medicine/supply</option>
                <?php foreach ($clinic_stock as $item): ?>
                    <option value="<?php echo $item['id']; ?>" 
                            data-stock="<?php echo $item['quantity']; ?>"
                            data-unit="<?php echo $item['unit']; ?>">
                        <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo $item['category']; ?>) - Stock: <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="medicine_quantity[]" class="form-control medicine-quantity" 
                   placeholder="Qty" min="1" step="1" required onchange="validateMedicineQuantity(this)">
            <div class="medicine-stock" id="medicine-stock-info"></div>
            <button type="button" class="remove-medicine" onclick="removeMedicineRow(this)">✕</button>
        </div>
    </template>

    <script>
        let medicineCount = 0;

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

        // Toggle emergency section based on incident type
        function toggleIncidentSections() {
            const emergencyType = document.getElementById('type-emergency');
            const emergencySection = document.getElementById('emergency-section');
            
            if (emergencyType && emergencyType.checked) {
                emergencySection.style.display = 'block';
            } else {
                if (emergencySection) emergencySection.style.display = 'none';
            }
        }

        // Toggle notification section
        function toggleNotificationSection() {
            const toggle = document.getElementById('parentNotificationToggle');
            const details = document.getElementById('notificationDetails');
            const hiddenInput = document.getElementById('parent_notification');
            
            if (toggle.checked) {
                details.classList.add('show');
                hiddenInput.value = 'yes';
            } else {
                details.classList.remove('show');
                hiddenInput.value = 'no';
            }
        }

        // Add medicine row
        function addMedicineRow() {
            const container = document.getElementById('medicine-container');
            const template = document.getElementById('medicine-row-template');
            const clone = template.content.cloneNode(true);
            
            const row = clone.querySelector('.medicine-row');
            row.dataset.index = medicineCount;
            medicineCount++;
            
            container.appendChild(clone);
        }

        // Remove medicine row
        function removeMedicineRow(button) {
            const row = button.closest('.medicine-row');
            row.remove();
        }

        // Update stock info when medicine selected
        function updateMedicineStock(select) {
            const row = select.closest('.medicine-row');
            const stockInfo = row.querySelector('.medicine-stock');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                const stock = selectedOption.dataset.stock;
                const unit = selectedOption.dataset.unit;
                stockInfo.textContent = `Available: ${stock} ${unit}`;
                
                const quantityInput = row.querySelector('.medicine-quantity');
                quantityInput.max = stock;
                quantityInput.placeholder = `Max: ${stock}`;
            } else {
                stockInfo.textContent = '';
            }
        }

        // Validate medicine quantity
        function validateMedicineQuantity(input) {
            const row = input.closest('.medicine-row');
            const select = row.querySelector('.medicine-select');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                const maxStock = parseInt(selectedOption.dataset.stock);
                const quantity = parseInt(input.value);
                
                if (quantity > maxStock) {
                    alert(`Quantity exceeds available stock (${maxStock} ${selectedOption.dataset.unit})`);
                    input.value = '';
                }
            }
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

        // View incident details (placeholder)
        function viewIncident(id) {
            alert('View incident details for ID: ' + id + ' (Implement modal view)');
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Add first medicine row by default
        document.addEventListener('DOMContentLoaded', function() {
            addMedicineRow();
            
            // Set page title
            const pageTitle = document.getElementById('pageTitle');
            if (pageTitle) {
                pageTitle.textContent = 'Incidents & Emergencies';
            }
        });
    </script>
</body>
</html>