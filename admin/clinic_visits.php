<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

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
$show_verification_modal = false;

// Check if verification was completed
if (isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search) {
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
        $_SESSION['verified_student_id'] = $_POST['student_id'];
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($_POST['student_id']));
        exit();
    } else {
        $verification_error = "Invalid password. Access denied.";
        $show_verification_modal = true;
    }
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

// Function to send parent notification email
function sendParentNotification($student, $visit_data, $db, $current_user_id, $current_user_fullname) {
    try {
        // Check if student has emergency contact email
        if (empty($student['emergency_email'])) {
            error_log("No emergency email found for student: " . $student['student_id']);
            return false;
        }

        // Get clinic staff info
        $staff_query = "SELECT full_name, email FROM users WHERE id = :user_id";
        $staff_stmt = $db->prepare($staff_query);
        $staff_stmt->bindParam(':user_id', $current_user_id);
        $staff_stmt->execute();
        $staff = $staff_stmt->fetch(PDO::FETCH_ASSOC);

        $mail = new PHPMailer(true);

        // Server settings - UPDATE THESE WITH YOUR EMAIL CONFIGURATION
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@gmail.com'; // Your email
        $mail->Password   = 'your-app-password'; // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('clinic@medflow.com', 'MedFlow Clinic');
        $mail->addAddress($student['emergency_email'], $student['emergency_contact'] ?? 'Parent/Guardian');
        $mail->addReplyTo($staff['email'] ?? 'clinic@medflow.com', $staff['full_name'] ?? 'Clinic Staff');

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Clinic Visit Notification - ' . $student['full_name'];
        
        // Build email body
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #191970; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .info-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #191970; border-radius: 5px; }
                .label { font-weight: bold; color: #191970; }
                .vital-sign { display: inline-block; background: #eceff1; padding: 5px 10px; margin: 2px; border-radius: 15px; font-size: 0.9em; }
                .footer { margin-top: 30px; font-size: 0.9em; color: #666; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🏥 MedFlow Clinic - Visit Notification</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($student['emergency_contact'] ?? 'Parent/Guardian') . "</strong>,</p>
                    
                    <p>This is to inform you that <strong>" . htmlspecialchars($student['full_name']) . "</strong> visited the school clinic today. Here are the details:</p>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #191970;'>Student Information</h3>
                        <p><span class='label'>Student ID:</span> " . htmlspecialchars($student['student_id']) . "</p>
                        <p><span class='label'>Full Name:</span> " . htmlspecialchars($student['full_name']) . "</p>
                        <p><span class='label'>Grade & Section:</span> Grade " . htmlspecialchars($student['year_level'] ?? 'N/A') . " - " . htmlspecialchars($student['section'] ?? 'N/A') . "</p>
                    </div>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #191970;'>Visit Details</h3>
                        <p><span class='label'>Date & Time:</span> " . date('F d, Y', strtotime($visit_data['visit_date'])) . " at " . date('h:i A', strtotime($visit_data['visit_time'])) . "</p>
                        <p><span class='label'>Chief Complaint:</span> " . htmlspecialchars($visit_data['complaint']) . "</p>
                    </div>";
        
        // Add vital signs if available
        if (!empty($visit_data['temperature']) || !empty($visit_data['blood_pressure']) || !empty($visit_data['heart_rate'])) {
            $body .= "<div class='info-box'>
                        <h3 style='margin-top: 0; color: #191970;'>Vital Signs</h3>
                        <div>";
            if (!empty($visit_data['temperature'])) {
                $body .= "<span class='vital-sign'>🌡️ Temperature: " . htmlspecialchars($visit_data['temperature']) . "°C</span> ";
            }
            if (!empty($visit_data['blood_pressure'])) {
                $body .= "<span class='vital-sign'>❤️ Blood Pressure: " . htmlspecialchars($visit_data['blood_pressure']) . "</span> ";
            }
            if (!empty($visit_data['heart_rate'])) {
                $body .= "<span class='vital-sign'>💓 Heart Rate: " . htmlspecialchars($visit_data['heart_rate']) . " bpm</span>";
            }
            $body .= "    </div>
                    </div>";
        }
        
        $body .= "<div class='info-box'>
                        <h3 style='margin-top: 0; color: #191970;'>Assessment & Treatment</h3>
                        <p><span class='label'>Assessment Notes:</span> " . nl2br(htmlspecialchars($visit_data['notes'] ?? 'None')) . "</p>
                        <p><span class='label'>Treatment Given:</span> " . nl2br(htmlspecialchars($visit_data['treatment_given'])) . "</p>
                        <p><span class='label'>Disposition:</span> <strong>" . htmlspecialchars($visit_data['disposition']) . "</strong></p>
                    </div>";
        
        // Add items used if any
        if (isset($_POST['items_used']) && is_array($_POST['items_used'])) {
            $items_used_text = [];
            foreach ($_POST['items_used'] as $index => $item_id) {
                if (!empty($item_id) && isset($_POST['item_quantity'][$index]) && $_POST['item_quantity'][$index] > 0) {
                    // Get item details from database
                    $item_query = "SELECT item_name, category, unit FROM clinic_stock WHERE id = :id";
                    $item_stmt = $db->prepare($item_query);
                    $item_stmt->bindParam(':id', $item_id);
                    $item_stmt->execute();
                    $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($item) {
                        $items_used_text[] = $_POST['item_quantity'][$index] . " " . $item['unit'] . " of " . $item['item_name'];
                    }
                }
            }
            if (!empty($items_used_text)) {
                $body .= "<div class='info-box'>
                            <h3 style='margin-top: 0; color: #191970;'>Medicines/Supplies Used</h3>
                            <p>" . implode(', ', $items_used_text) . "</p>
                          </div>";
            }
        }
        
        $body .= "<p>If you have any questions or concerns, please don't hesitate to contact the school clinic.</p>
                    
                    <div class='footer'>
                        <p>This is an automated notification from MedFlow Clinic Management System.<br>
                        School Clinic Contact: (02) 1234-5678 | clinic@medflow.com</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));
        
        $mail->send();
        
        // Log notification - FIXED: Make sure called_by is not null
        $log_query = "INSERT INTO parent_notifications (
            incident_id, student_id, parent_name, contact_number, emergency_email,
            notification_date, notification_time, called_by, response, notes
        ) VALUES (
            :incident_id, :student_id, :parent_name, :contact_number, :emergency_email,
            :notification_date, :notification_time, :called_by, :response, :notes
        )";
        
        $log_stmt = $db->prepare($log_query);
        $incident_id = 0; // For visit notifications, we can use 0 or modify table structure
        $notification_date = date('Y-m-d');
        $notification_time = date('H:i:s');
        $response = 'Email Sent';
        $notes = 'Parent notified via email about clinic visit';
        $called_by = $current_user_fullname; // FIXED: Use the full name from database
        
        $log_stmt->bindParam(':incident_id', $incident_id);
        $log_stmt->bindParam(':student_id', $student['student_id']);
        $log_stmt->bindParam(':parent_name', $student['emergency_contact']);
        $log_stmt->bindParam(':contact_number', $student['emergency_phone']);
        $log_stmt->bindParam(':emergency_email', $student['emergency_email']);
        $log_stmt->bindParam(':notification_date', $notification_date);
        $log_stmt->bindParam(':notification_time', $notification_time);
        $log_stmt->bindParam(':called_by', $called_by); // FIXED: Now has a value
        $log_stmt->bindParam(':response', $response);
        $log_stmt->bindParam(':notes', $notes);
        $log_stmt->execute();
        
        return true;
        
    } catch (Exception $e) {
        error_log("Email notification failed: " . $e->getMessage());
        return false;
    } catch (PDOException $e) {
        error_log("Database error in notification logging: " . $e->getMessage());
        return false;
    }
}

// Handle form submission for new visit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'save_visit') {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Insert visit history
            $query = "INSERT INTO visit_history (
                student_id, visit_date, visit_time, complaint, 
                temperature, blood_pressure, heart_rate, 
                treatment_given, disposition, attended_by, notes
            ) VALUES (
                :student_id, :visit_date, :visit_time, :complaint,
                :temperature, :blood_pressure, :heart_rate,
                :treatment_given, :disposition, :attended_by, :notes
            )";
            
            $stmt = $db->prepare($query);
            
            // Get current date/time if not provided
            $visit_date = $_POST['visit_date'] ?? date('Y-m-d');
            $visit_time = $_POST['visit_time'] ?? date('H:i:s');
            
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':visit_date', $visit_date);
            $stmt->bindParam(':visit_time', $visit_time);
            $stmt->bindParam(':complaint', $_POST['complaint']);
            $stmt->bindParam(':temperature', $_POST['temperature']);
            $stmt->bindParam(':blood_pressure', $_POST['blood_pressure']);
            $stmt->bindParam(':heart_rate', $_POST['heart_rate']);
            $stmt->bindParam(':treatment_given', $_POST['treatment_given']);
            $stmt->bindParam(':disposition', $_POST['disposition']);
            $stmt->bindParam(':attended_by', $current_user_id);
            $stmt->bindParam(':notes', $_POST['notes']);
            
            $stmt->execute();
            $visit_id = $db->lastInsertId();
            
            // Process medicines/supplies used
            if (isset($_POST['items_used']) && is_array($_POST['items_used'])) {
                foreach ($_POST['items_used'] as $index => $item_id) {
                    if (!empty($item_id) && isset($_POST['item_quantity'][$index]) && $_POST['item_quantity'][$index] > 0) {
                        $item_quantity = intval($_POST['item_quantity'][$index]);
                        
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
                            
                            // Insert dispensing log
                            $log_query = "INSERT INTO dispensing_log (
                                visit_id, student_id, student_name, item_code,
                                item_name, category, quantity, unit, dispensed_by, reason
                            ) VALUES (
                                :visit_id, :student_id, :student_name, :item_code,
                                :item_name, :category, :quantity, :unit, :dispensed_by, :reason
                            )";
                            
                            $log_stmt = $db->prepare($log_query);
                            $log_stmt->bindParam(':visit_id', $visit_id);
                            $log_stmt->bindParam(':student_id', $_POST['student_id']);
                            $log_stmt->bindParam(':student_name', $_POST['student_name']);
                            $log_stmt->bindParam(':item_code', $item['item_code']);
                            $log_stmt->bindParam(':item_name', $item['item_name']);
                            $log_stmt->bindParam(':category', $item['category']);
                            $log_stmt->bindParam(':quantity', $item_quantity);
                            $log_stmt->bindParam(':unit', $item['unit']);
                            $log_stmt->bindParam(':dispensed_by', $current_user_id);
                            $log_stmt->bindParam(':reason', $_POST['complaint']);
                            $log_stmt->execute();
                        }
                    }
                }
            }
            
            $db->commit();
            
            // Fetch student data for email notification
            $student_data_for_notification = null;
            
            // Fetch from API
            $api_url = "https://ttm.qcprotektado.com/api/students.php";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $response) {
                $api_response = json_decode($response, true);
                if (isset($api_response['records']) && is_array($api_response['records'])) {
                    foreach ($api_response['records'] as $student) {
                        if (isset($student['student_id']) && $student['student_id'] == $_POST['student_id']) {
                            $student_data_for_notification = $student;
                            break;
                        }
                    }
                }
            }
            
            // Send email notification to parent
            if ($student_data_for_notification && !empty($student_data_for_notification['emergency_email'])) {
                $email_sent = sendParentNotification($student_data_for_notification, $_POST, $db, $current_user_id, $current_user_fullname);
                if ($email_sent) {
                    $success_message = "Clinic visit logged successfully! Parent notification sent to " . $student_data_for_notification['emergency_email'];
                } else {
                    $success_message = "Clinic visit logged successfully! (Parent notification failed to send)";
                }
            } else {
                $success_message = "Clinic visit logged successfully! (No emergency email found for parent notification)";
            }
            
            // Refresh student data to show new visit
            $student_id_search = $_POST['student_id'];
            
            // Fetch updated student data
            if ($http_code == 200 && $response) {
                $api_response = json_decode($response, true);
                if (isset($api_response['records']) && is_array($api_response['records'])) {
                    foreach ($api_response['records'] as $student) {
                        if (isset($student['student_id']) && $student['student_id'] == $_POST['student_id']) {
                            $student_data = $student;
                            $student_data['clinic_visits'] = getClinicVisits($db, $_POST['student_id']);
                            break;
                        }
                    }
                }
            }
            
            // Refresh clinic stock
            $clinic_stock = getClinicStock($db);
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get statistics
$stats = [];

try {
    // Today's visits
    $query = "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_visits'] = $result ? $result['total'] : 0;
    
    // This week's visits
    $query = "SELECT COUNT(*) as total FROM visit_history 
              WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['week_visits'] = $result ? $result['total'] : 0;
    
    // Common complaints
    $query = "SELECT complaint, COUNT(*) as count 
              FROM visit_history 
              WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              GROUP BY complaint 
              ORDER BY count DESC 
              LIMIT 5";
    $stmt = $db->query($query);
    $stats['common_complaints'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent visits
    $query = "SELECT v.*, u.full_name as attended_by_name 
              FROM visit_history v
              LEFT JOIN users u ON v.attended_by = u.id
              ORDER BY v.visit_date DESC, v.visit_time DESC 
              LIMIT 10";
    $stmt = $db->query($query);
    $stats['recent_visits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Low stock count
    $query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['low_stock'] = $result ? $result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = [
        'today_visits' => 0,
        'week_visits' => 0,
        'common_complaints' => [],
        'recent_visits' => [],
        'low_stock' => 0
    ];
}

// Function to get clinic visits for a student
function getClinicVisits($db, $student_id) {
    try {
        $query = "SELECT v.*, u.full_name as attended_by_name,
                         GROUP_CONCAT(CONCAT(d.quantity, ' ', d.unit, ' of ', d.item_name) SEPARATOR ', ') as items_used
                  FROM visit_history v
                  LEFT JOIN users u ON v.attended_by = u.id
                  LEFT JOIN dispensing_log d ON v.id = d.visit_id
                  WHERE v.student_id = :student_id 
                  GROUP BY v.id
                  ORDER BY v.visit_date DESC, v.visit_time DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Search for student if ID provided and verified
if (!empty($student_id_search) && isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search && !isset($_POST['action'])) {
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
                    $student_data['clinic_visits'] = getClinicVisits($db, $student_id_search);
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
        $search_error = "Error connecting to student database.";
        unset($_SESSION['verified_student_id']);
    }
} elseif (!empty($student_id_search) && (!isset($_SESSION['verified_student_id']) || $_SESSION['verified_student_id'] !== $student_id_search)) {
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
    <title>Clinic Visits & Consultation | MedFlow Clinic Management System</title>
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

        .stock-warning {
            color: #c62828;
            font-size: 0.7rem;
            font-weight: 600;
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

        .btn-danger {
            background: #c62828;
            color: white;
        }

        .btn-danger:hover {
            background: #b71c1c;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .complaint-list {
            list-style: none;
        }

        .complaint-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eceff1;
        }

        .complaint-item:last-child {
            border-bottom: none;
        }

        .complaint-name {
            font-weight: 500;
            color: #37474f;
        }

        .complaint-count {
            background: #191970;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Visit Form Card */
        .visit-form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.8s ease;
        }

        .student-info-bar {
            background: #eceff1;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #cfd8dc;
        }

        .student-avatar-sm {
            width: 60px;
            height: 60px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
            color: white;
        }

        .student-details h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 4px;
        }

        .student-details p {
            color: #546e7a;
            font-size: 0.9rem;
        }

        .emergency-contact-info {
            margin-top: 8px;
            padding: 8px 12px;
            background: #fff3e0;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #e65100;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .emergency-contact-info svg {
            width: 16px;
            height: 16px;
        }

        .vital-signs-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .vital-input {
            text-align: center;
        }

        .vital-input label {
            display: block;
            font-size: 0.7rem;
            color: #78909c;
            margin-bottom: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .vital-input input {
            text-align: center;
            font-size: 1.1rem;
        }

        /* Items Used Section */
        .items-section {
            background: #f5f5f5;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #cfd8dc;
        }

        .items-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .items-header h3 {
            font-size: 1rem;
            color: #191970;
            font-weight: 600;
        }

        .item-row {
            display: grid;
            grid-template-columns: 3fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
            background: white;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #cfd8dc;
        }

        .item-select {
            width: 100%;
        }

        .item-quantity {
            width: 100%;
        }

        .item-stock {
            font-size: 0.7rem;
            color: #78909c;
        }

        .remove-item {
            background: #ffebee;
            color: #c62828;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .remove-item:hover {
            background: #c62828;
            color: white;
        }

        .add-item-btn {
            background: none;
            border: 2px dashed #191970;
            color: #191970;
            padding: 10px;
            border-radius: 8px;
            width: 100%;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .add-item-btn:hover {
            background: rgba(25, 25, 112, 0.1);
        }

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
            padding: 12px;
            background: #eceff1;
            border: 2px solid #cfd8dc;
            border-radius: 10px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 600;
            color: #37474f;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .disposition-option input[type="radio"]:checked + label {
            background: #191970;
            border-color: #191970;
            color: white;
        }

        .disposition-option label:hover {
            border-color: #191970;
        }

        /* Visit History */
        .visit-history-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.9s ease;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

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

        .complaint-cell {
            font-weight: 600;
            color: #191970;
        }

        .vital-badge {
            background: #eceff1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            color: #37474f;
            display: inline-block;
            margin-right: 4px;
            margin-bottom: 4px;
        }

        .disposition-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
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

        .items-used-badge {
            background: #e8eaf6;
            color: #191970;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            display: inline-block;
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
            
            .vital-signs-grid {
                grid-template-columns: 1fr;
            }
            
            .disposition-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .student-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .item-row {
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
                    <h1>Clinic Visits & Consultation</h1>
                    <p>Log and manage student clinic visits with medicine tracking and parent notifications.</p>
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
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
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
                            <h3><?php echo $stats['week_visits']; ?></h3>
                            <p>This Week</p>
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
                            <h3><?php echo count($stats['recent_visits']); ?></h3>
                            <p>Recent Visits</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['low_stock']; ?></h3>
                            <p>Low Stock Items</p>
                            <?php if ($stats['low_stock'] > 0): ?>
                                <div class="stock-warning">⚠️ Needs attention</div>
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
                                <label for="student_id">Student ID / LRN</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       placeholder="Enter student ID" 
                                       value="<?php echo htmlspecialchars($student_id_search); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Search Student</button>
                        </form>

                        <?php if ($search_error): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #ffebee; border-radius: 8px; color: #c62828; font-size: 0.9rem;">
                                <?php echo $search_error; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($stats['common_complaints'])): ?>
                            <div style="margin-top: 24px;">
                                <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                    Common Complaints (30 days)
                                </div>
                                <ul class="complaint-list">
                                    <?php foreach ($stats['common_complaints'] as $complaint): ?>
                                        <li class="complaint-item">
                                            <span class="complaint-name"><?php echo htmlspecialchars($complaint['complaint']); ?></span>
                                            <span class="complaint-count"><?php echo $complaint['count']; ?></span>
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
                        
                        <div style="margin-bottom: 20px;">
                            <div style="font-size: 0.9rem; color: #546e7a; margin-bottom: 8px;">Total Visits This Month</div>
                            <div style="font-size: 2rem; font-weight: 700; color: #191970;"><?php echo array_sum(array_column($stats['common_complaints'], 'count')); ?></div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: #191970;"><?php echo $stats['today_visits']; ?></div>
                                <div style="font-size: 0.8rem; color: #546e7a;">Today</div>
                            </div>
                            <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: #191970;"><?php echo $stats['week_visits']; ?></div>
                                <div style="font-size: 0.8rem; color: #546e7a;">This Week</div>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                Common Dispositions
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <span class="disposition-badge disposition-sent-home">Sent Home</span>
                                <span class="disposition-badge disposition-referred">Referred</span>
                                <span class="disposition-badge disposition-cleared">Cleared</span>
                                <span class="disposition-badge disposition-admitted">Admitted</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($student_data): ?>
                <!-- Visit Form -->
                <div class="visit-form-card">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6V12L16 14"/>
                        </svg>
                        Log New Clinic Visit
                    </div>

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
                                <p style="font-size: 0.8rem; color: #e65100; margin-top: 4px;">
                                    ⚕️ Declared: <?php echo htmlspecialchars($student_data['medical_conditions']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- Emergency Contact Info -->
                            <?php if (!empty($student_data['emergency_contact']) || !empty($student_data['emergency_phone']) || !empty($student_data['emergency_email'])): ?>
                            <div class="emergency-contact-info">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.92v3a1.999 1.999 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                                <span>
                                    <strong>Emergency Contact:</strong> 
                                    <?php echo htmlspecialchars($student_data['emergency_contact'] ?? 'N/A'); ?> | 
                                    <?php echo htmlspecialchars($student_data['emergency_phone'] ?? 'No phone'); ?>
                                    <?php if (!empty($student_data['emergency_email'])): ?>
                                        <br>📧 <?php echo htmlspecialchars($student_data['emergency_email']); ?>
                                        <span style="color: #2e7d32;">(Parent will be notified via email)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" action="" id="visitForm">
                        <input type="hidden" name="action" value="save_visit">
                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                        <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Visit Date</label>
                                <input type="date" name="visit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Visit Time</label>
                                <input type="time" name="visit_time" class="form-control" value="<?php echo date('H:i'); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Chief Complaint / Reason for Visit</label>
                            <input type="text" name="complaint" class="form-control" 
                                   placeholder="e.g., Headache, Fever, Stomachache, Injury during PE" 
                                   list="common-complaints" required>
                            <datalist id="common-complaints">
                                <option value="Headache">
                                <option value="Fever">
                                <option value="Stomachache">
                                <option value="Dizziness">
                                <option value="Nausea">
                                <option value="Cough">
                                <option value="Cold">
                                <option value="Injury during PE">
                                <option value="Menstrual cramps">
                                <option value="Toothache">
                                <option value="Sore throat">
                                <option value="Fatigue">
                                <option value="Allergic reaction">
                                <option value="Asthma attack">
                                <option value="Sprain">
                            </datalist>
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

                        <div class="form-group">
                            <label>Assessment (Brief Notes)</label>
                            <textarea name="notes" class="form-control" placeholder="e.g., Possible viral fever, Mild dehydration, Minor sprain..."></textarea>
                        </div>

                        <!-- Items Used Section -->
                        <div class="items-section">
                            <div class="items-header">
                                <h3>💊 Medicines / Supplies Used</h3>
                                <span style="font-size: 0.8rem; color: #78909c;">Items will be deducted from inventory</span>
                            </div>
                            
                            <div id="items-container">
                                <!-- Item rows will be added here dynamically -->
                            </div>
                            
                            <button type="button" class="add-item-btn" onclick="addItemRow()">
                                + Add Medicine or Supply
                            </button>
                        </div>

                        <div class="form-group">
                            <label>Treatment Given</label>
                            <textarea name="treatment_given" class="form-control" placeholder="e.g., Paracetamol 500mg given, ORS given, Wound cleaned & dressed, Rested for 30 minutes..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label>Disposition (Final Decision)</label>
                            <div class="disposition-grid">
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-sent-home" value="Sent Home" checked>
                                    <label for="disp-sent-home">🏠 Sent Home</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-referred" value="Referred">
                                    <label for="disp-referred">🏥 Referred</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-admitted" value="Admitted">
                                    <label for="disp-admitted">🛏️ Admitted</label>
                                </div>
                                <div class="disposition-option">
                                    <input type="radio" name="disposition" id="disp-cleared" value="Cleared">
                                    <label for="disp-cleared">✅ Cleared</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 10px;" onclick="return validateItems()">
                            Save Clinic Visit & Notify Parent
                        </button>
                    </form>
                </div>

                <!-- Student Visit History -->
                <div class="visit-history-section">
                    <div class="section-header">
                        <h2>Visit History - <?php echo htmlspecialchars($student_data['full_name']); ?></h2>
                        <span style="color: #78909c; font-size: 0.9rem;"><?php echo count($student_data['clinic_visits']); ?> total visits</span>
                    </div>

                    <?php if (!empty($student_data['clinic_visits'])): ?>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Complaint</th>
                                        <th>Vital Signs</th>
                                        <th>Items Used</th>
                                        <th>Treatment</th>
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
                                                <span class="complaint-cell"><?php echo htmlspecialchars($visit['complaint']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($visit['temperature'])): ?>
                                                    <span class="vital-badge">🌡️ <?php echo $visit['temperature']; ?>°C</span>
                                                <?php endif; ?>
                                                <?php if (!empty($visit['blood_pressure'])): ?>
                                                    <span class="vital-badge">❤️ <?php echo $visit['blood_pressure']; ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($visit['heart_rate'])): ?>
                                                    <span class="vital-badge">💓 <?php echo $visit['heart_rate']; ?> bpm</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($visit['items_used'])): ?>
                                                    <span class="items-used-badge"><?php echo htmlspecialchars($visit['items_used']); ?></span>
                                                <?php else: ?>
                                                    <small style="color: #78909c;">None</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars(substr($visit['treatment_given'], 0, 50)) . (strlen($visit['treatment_given']) > 50 ? '...' : ''); ?></small>
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
                <?php endif; ?>

                <!-- Recent All Visits -->
                <?php if (!empty($stats['recent_visits']) && !$student_data): ?>
                <div class="visit-history-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h2>Recent Clinic Visits</h2>
                        <span style="color: #78909c; font-size: 0.9rem;">Last 10 visits</span>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Date & Time</th>
                                    <th>Complaint</th>
                                    <th>Treatment</th>
                                    <th>Disposition</th>
                                    <th>Attended By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_visits'] as $visit): ?>
                                    <tr>
                                        <td>
                                            <span style="font-weight: 600; color: #191970;"><?php echo htmlspecialchars($visit['student_id']); ?></span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($visit['visit_date'])); ?><br>
                                            <small style="color: #78909c;"><?php echo date('h:i A', strtotime($visit['visit_time'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="complaint-cell"><?php echo htmlspecialchars($visit['complaint']); ?></span>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars(substr($visit['treatment_given'], 0, 40)) . (strlen($visit['treatment_given']) > 40 ? '...' : ''); ?></small>
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
        
        // Close modal when clicking outside
        document.getElementById('verificationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                cancelAccess();
            }
        });
    </script>
    <?php endif; ?>

    <!-- Template for item row -->
    <template id="item-row-template">
        <div class="item-row">
            <select name="items_used[]" class="form-control item-select" onchange="updateStockInfo(this)" required>
                <option value="">Select item</option>
                <?php foreach ($clinic_stock as $item): ?>
                    <option value="<?php echo $item['id']; ?>" 
                            data-stock="<?php echo $item['quantity']; ?>"
                            data-unit="<?php echo $item['unit']; ?>">
                        <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo $item['category']; ?>) - Stock: <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="item_quantity[]" class="form-control item-quantity" 
                   placeholder="Qty" min="1" step="1" required onchange="validateQuantity(this)">
            <div class="item-stock" id="stock-info"></div>
            <button type="button" class="remove-item" onclick="removeItemRow(this)">✕</button>
        </div>
    </template>

    <script>
        let itemCount = 0;

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

        // Add new item row
        function addItemRow() {
            const container = document.getElementById('items-container');
            const template = document.getElementById('item-row-template');
            const clone = template.content.cloneNode(true);
            
            // Add unique identifier
            const row = clone.querySelector('.item-row');
            row.dataset.index = itemCount;
            itemCount++;
            
            container.appendChild(clone);
        }

        // Remove item row
        function removeItemRow(button) {
            const row = button.closest('.item-row');
            row.remove();
        }

        // Update stock information when item is selected
        function updateStockInfo(select) {
            const row = select.closest('.item-row');
            const stockInfo = row.querySelector('.item-stock');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                const stock = selectedOption.dataset.stock;
                const unit = selectedOption.dataset.unit;
                stockInfo.textContent = `Available: ${stock} ${unit}`;
                
                // Update max attribute for quantity input
                const quantityInput = row.querySelector('.item-quantity');
                quantityInput.max = stock;
            } else {
                stockInfo.textContent = '';
            }
        }

        // Validate quantity against available stock
        function validateQuantity(input) {
            const row = input.closest('.item-row');
            const select = row.querySelector('.item-select');
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

        // Validate form before submission
        function validateItems() {
            const itemRows = document.querySelectorAll('.item-row');
            let hasErrors = false;
            
            itemRows.forEach(row => {
                const select = row.querySelector('.item-select');
                const quantity = row.querySelector('.item-quantity');
                
                if (select.value && (!quantity.value || quantity.value < 1)) {
                    alert('Please enter quantity for selected items');
                    hasErrors = true;
                }
                
                if (select.value && quantity.value) {
                    const selectedOption = select.options[select.selectedIndex];
                    const maxStock = parseInt(selectedOption.dataset.stock);
                    if (parseInt(quantity.value) > maxStock) {
                        alert(`Insufficient stock for ${selectedOption.textContent}`);
                        hasErrors = true;
                    }
                }
            });
            
            return !hasErrors;
        }

        // Auto-hide alerts after 5 seconds
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
            pageTitle.textContent = 'Clinic Visits';
        }

        // Add first item row by default
        document.addEventListener('DOMContentLoaded', function() {
            addItemRow();
        });
    </script>
</body>
</html>