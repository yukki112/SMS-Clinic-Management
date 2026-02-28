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
$reporter_data = null;
$search_error = '';
$reporter_search_error = '';
$student_id_search = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$reporter_id_search = isset($_GET['reporter_id']) ? $_GET['reporter_id'] : '';
$success_message = '';
$error_message = '';
$show_verification_modal = false;
$show_reporter_verification_modal = false;

// Check if verification was completed
if (isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search) {
    $show_verification_modal = false;
} elseif (!empty($student_id_search) && !isset($_POST['action'])) {
    $show_verification_modal = true;
}

// Check if reporter verification was completed
if (isset($_SESSION['verified_reporter_id']) && $_SESSION['verified_reporter_id'] === $reporter_id_search) {
    $show_reporter_verification_modal = false;
} elseif (!empty($reporter_id_search) && !isset($_POST['action'])) {
    $show_reporter_verification_modal = true;
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
        if (isset($_POST['verify_student'])) {
            $_SESSION['verified_student_id'] = $_POST['student_id'];
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($_POST['student_id']) . "&reporter_id=" . urlencode($reporter_id_search));
        } elseif (isset($_POST['verify_reporter'])) {
            $_SESSION['verified_reporter_id'] = $_POST['reporter_id'];
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?student_id=" . urlencode($student_id_search) . "&reporter_id=" . urlencode($_POST['reporter_id']));
        }
        exit();
    } else {
        if (isset($_POST['verify_student'])) {
            $verification_error = "Invalid password. Access denied.";
            $show_verification_modal = true;
        } elseif (isset($_POST['verify_reporter'])) {
            $reporter_verification_error = "Invalid password. Access denied.";
            $show_reporter_verification_modal = true;
        }
    }
}

// Get clinic stock for medicine dropdown
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

// Function to send parent notification email for incidents
function sendIncidentNotification($student, $incident_data, $incident_code, $db, $current_user_id, $current_user_fullname) {
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
        $mail->Username   = 'Stephenviray12@gmail.com'; // Your email
        $mail->Password   = 'bubr nckn tgqf lvus'; // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('clinic@medflow.com', 'MedFlow Clinic');
        $mail->addAddress($student['emergency_email'], $student['emergency_contact'] ?? 'Parent/Guardian');
        $mail->addReplyTo($staff['email'] ?? 'clinic@medflow.com', $staff['full_name'] ?? 'Clinic Staff');

        // Content
        $mail->isHTML(true);
        
        // Set subject based on incident type
        $subject_prefix = match($incident_data['incident_type']) {
            'Emergency' => '🚨 EMERGENCY',
            'Minor Injury' => '🩹 Minor Injury',
            default => '📋 Incident Report'
        };
        
        $mail->Subject = $subject_prefix . ' - ' . $student['full_name'] . ' - ' . $incident_code;
        
        // Build email body
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { 
                    background: " . ($incident_data['incident_type'] == 'Emergency' ? '#dc2626' : ($incident_data['incident_type'] == 'Minor Injury' ? '#ea580c' : '#2563eb')) . "; 
                    color: white; 
                    padding: 20px; 
                    text-align: center; 
                    border-radius: 10px 10px 0 0; 
                }
                .content { background: #f5f5f5; padding: 30px; border-radius: 0 0 10px 10px; }
                .info-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid " . ($incident_data['incident_type'] == 'Emergency' ? '#dc2626' : ($incident_data['incident_type'] == 'Minor Injury' ? '#ea580c' : '#2563eb')) . "; border-radius: 5px; }
                .label { font-weight: bold; color: #1e293b; }
                .vital-sign { display: inline-block; background: #eceff1; padding: 5px 10px; margin: 2px; border-radius: 15px; font-size: 0.9em; }
                .footer { margin-top: 30px; font-size: 0.9em; color: #666; text-align: center; }
                .emergency-badge { background: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 30px; font-size: 0.8rem; display: inline-block; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>" . ($incident_data['incident_type'] == 'Emergency' ? '🚨 EMERGENCY NOTIFICATION' : ($incident_data['incident_type'] == 'Minor Injury' ? '🩹 Minor Injury Report' : '📋 Incident Report')) . "</h2>
                    <p>Incident Code: <strong>" . $incident_code . "</strong></p>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($student['emergency_contact'] ?? 'Parent/Guardian') . "</strong>,</p>
                    
                    <p>This is to inform you that an incident involving <strong>" . htmlspecialchars($student['full_name']) . "</strong> has been reported at the school.</p>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #1e293b;'>Student Information</h3>
                        <p><span class='label'>Student ID:</span> " . htmlspecialchars($student['student_id']) . "</p>
                        <p><span class='label'>Full Name:</span> " . htmlspecialchars($student['full_name']) . "</p>
                        <p><span class='label'>Grade & Section:</span> Grade " . htmlspecialchars($student['year_level'] ?? 'N/A') . " - " . htmlspecialchars($student['section'] ?? 'N/A') . "</p>
                    </div>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #1e293b;'>Reporter Information</h3>
                        <p><span class='label'>Reported By:</span> " . htmlspecialchars($incident_data['reporter_name'] ?? 'N/A') . "</p>
                        <p><span class='label'>Reporter Type:</span> " . htmlspecialchars($incident_data['reporter_type'] ?? 'N/A') . "</p>
                        " . (!empty($incident_data['reporter_id']) ? "<p><span class='label'>Reporter ID:</span> " . htmlspecialchars($incident_data['reporter_id']) . "</p>" : "") . "
                    </div>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #1e293b;'>Incident Details</h3>
                        <p><span class='label'>Incident Type:</span> <span class='emergency-badge'>" . htmlspecialchars($incident_data['incident_type']) . "</span></p>
                        <p><span class='label'>Date & Time:</span> " . date('F d, Y', strtotime($incident_data['incident_date'])) . " at " . date('h:i A', strtotime($incident_data['incident_time'])) . "</p>
                        <p><span class='label'>Location:</span> " . htmlspecialchars($incident_data['location']) . "</p>
                        <p><span class='label'>Description:</span> " . nl2br(htmlspecialchars($incident_data['description'])) . "</p>
                        " . (!empty($incident_data['witness']) ? "<p><span class='label'>Witness:</span> " . htmlspecialchars($incident_data['witness']) . "</p>" : "") . "
                    </div>";
        
        // Add vital signs if available
        if (!empty($incident_data['temperature']) || !empty($incident_data['blood_pressure']) || !empty($incident_data['heart_rate'])) {
            $body .= "<div class='info-box'>
                        <h3 style='margin-top: 0; color: #1e293b;'>Vital Signs</h3>
                        <div>";
            if (!empty($incident_data['temperature'])) {
                $body .= "<span class='vital-sign'>🌡️ Temperature: " . htmlspecialchars($incident_data['temperature']) . "°C</span> ";
            }
            if (!empty($incident_data['blood_pressure'])) {
                $body .= "<span class='vital-sign'>❤️ Blood Pressure: " . htmlspecialchars($incident_data['blood_pressure']) . "</span> ";
            }
            if (!empty($incident_data['heart_rate'])) {
                $body .= "<span class='vital-sign'>💓 Heart Rate: " . htmlspecialchars($incident_data['heart_rate']) . " bpm</span>";
            }
            $body .= "    </div>
                    </div>";
        }
        
        $body .= "<div class='info-box'>
                        <h3 style='margin-top: 0; color: #1e293b;'>Treatment & Action</h3>
                        <p><span class='label'>Action Taken:</span> " . nl2br(htmlspecialchars($incident_data['action_taken'] ?? 'None')) . "</p>
                        <p><span class='label'>Treatment Given:</span> " . nl2br(htmlspecialchars($incident_data['treatment_given'] ?? 'None')) . "</p>";
        
        // Add medicines given if any
        if (!empty($incident_data['medicine_given'])) {
            $body .= "<p><span class='label'>Medicines/Supplies Used:</span> " . htmlspecialchars($incident_data['medicine_given']) . "</p>";
        }
        
        $body .= "<p><span class='label'>Disposition:</span> <strong>" . htmlspecialchars($incident_data['disposition'] ?? 'Under evaluation') . "</strong></p>";
        
        if (!empty($incident_data['referred_to'])) {
            $body .= "<p><span class='label'>Referred To:</span> " . htmlspecialchars($incident_data['referred_to']) . "</p>";
        }
        
        $body .= "</div>";
        
        // Add emergency-specific details
        if ($incident_data['incident_type'] == 'Emergency') {
            $body .= "<div class='info-box'>
                        <h3 style='margin-top: 0; color: #dc2626;'>Emergency Response Details</h3>";
            
            if (!empty($incident_data['response_time'])) {
                $body .= "<p><span class='label'>Response Time:</span> " . htmlspecialchars($incident_data['response_time']) . "</p>";
            }
            if (!empty($incident_data['ambulance_called']) && $incident_data['ambulance_called'] == 'Yes') {
                $body .= "<p><span class='label'>Ambulance Called:</span> Yes at " . htmlspecialchars($incident_data['ambulance_time'] ?? 'N/A') . "</p>";
            }
            if (!empty($incident_data['hospital_referred'])) {
                $body .= "<p><span class='label'>Hospital Referred:</span> " . htmlspecialchars($incident_data['hospital_referred']) . "</p>";
            }
            
            $body .= "</div>";
        }
        
        $body .= "<p>If you have any questions or need to pick up your child, please contact the school clinic immediately.</p>
                    
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
        
        // Log notification
        $log_query = "INSERT INTO parent_notifications (
            incident_id, student_id, parent_name, contact_number, emergency_email,
            notification_date, notification_time, called_by, response, notes
        ) VALUES (
            :incident_id, :student_id, :parent_name, :contact_number, :emergency_email,
            :notification_date, :notification_time, :called_by, :response, :notes
        )";
        
        $log_stmt = $db->prepare($log_query);
        $notification_date = date('Y-m-d');
        $notification_time = date('H:i:s');
        $response = 'Email Sent';
        $notes = 'Parent notified via email about incident: ' . $incident_code;
        $called_by = $current_user_fullname;
        
        $log_stmt->bindParam(':incident_id', $incident_id);
        $log_stmt->bindParam(':student_id', $student['student_id']);
        $log_stmt->bindParam(':parent_name', $student['emergency_contact']);
        $log_stmt->bindParam(':contact_number', $student['emergency_phone']);
        $log_stmt->bindParam(':emergency_email', $student['emergency_email']);
        $log_stmt->bindParam(':notification_date', $notification_date);
        $log_stmt->bindParam(':notification_time', $notification_time);
        $log_stmt->bindParam(':called_by', $called_by);
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
            
            // Format vital signs
            $vital_signs = "Temp: " . ($_POST['temperature'] ?? 'N/A') . "°C, " .
                           "BP: " . ($_POST['blood_pressure'] ?? 'N/A') . ", " .
                           "HR: " . ($_POST['heart_rate'] ?? 'N/A') . " bpm";
            
            // Format medicine given
            $medicine_given = '';
            if (isset($_POST['items_used']) && is_array($_POST['items_used'])) {
                $medicines = [];
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
                            
                            $medicines[] = $item_quantity . ' ' . $item['unit'] . ' of ' . $item['item_name'];
                        }
                    }
                }
                $medicine_given = implode(', ', $medicines);
            }
            
            // Determine reporter info
            $reporter_name = $_POST['reporter_name'] ?? $_POST['student_name'];
            $reporter_id = $_POST['reporter_id'] ?? $_POST['student_id'];
            $reporter_type = $_POST['reporter_type'] ?? 'Student';
            
            // Insert incident
            $query = "INSERT INTO incidents (
                incident_code, student_id, student_name, grade_section,
                parent_name, parent_contact, emergency_email, incident_date, incident_time, 
                location, incident_type, description, witness, action_taken, 
                vital_signs, treatment_given, medicine_given, disposition, referred_to,
                reporter_name, reporter_id, reporter_type, created_by
            ) VALUES (
                :incident_code, :student_id, :student_name, :grade_section,
                :parent_name, :parent_contact, :emergency_email, :incident_date, :incident_time, 
                :location, :incident_type, :description, :witness, :action_taken, 
                :vital_signs, :treatment_given, :medicine_given, :disposition, :referred_to,
                :reporter_name, :reporter_id, :reporter_type, :created_by
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':incident_code', $incident_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':parent_name', $_POST['parent_name']);
            $stmt->bindParam(':parent_contact', $_POST['parent_contact']);
            $stmt->bindParam(':emergency_email', $_POST['emergency_email']);
            $stmt->bindParam(':incident_date', $_POST['incident_date']);
            $stmt->bindParam(':incident_time', $_POST['incident_time']);
            $stmt->bindParam(':location', $_POST['location']);
            $stmt->bindParam(':incident_type', $_POST['incident_type']);
            $stmt->bindParam(':description', $_POST['description']);
            $stmt->bindParam(':witness', $_POST['witness']);
            $stmt->bindParam(':action_taken', $_POST['action_taken']);
            $stmt->bindParam(':vital_signs', $vital_signs);
            $stmt->bindParam(':treatment_given', $_POST['treatment_given']);
            $stmt->bindParam(':medicine_given', $medicine_given);
            $stmt->bindParam(':disposition', $_POST['disposition']);
            $stmt->bindParam(':referred_to', $_POST['referred_to']);
            $stmt->bindParam(':reporter_name', $reporter_name);
            $stmt->bindParam(':reporter_id', $reporter_id);
            $stmt->bindParam(':reporter_type', $reporter_type);
            $stmt->bindParam(':created_by', $current_user_id);
            $stmt->execute();
            
            $incident_id = $db->lastInsertId();
            
            // Save parent notification if provided
            if (!empty($_POST['parent_name']) && !empty($_POST['parent_contact']) && !empty($_POST['notification_time'])) {
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
                $notif_stmt->bindParam(':parent_name', $_POST['parent_name']);
                $notif_stmt->bindParam(':contact_number', $_POST['parent_contact']);
                $notif_stmt->bindParam(':notification_date', $_POST['incident_date']);
                $notif_stmt->bindParam(':notification_time', $_POST['notification_time']);
                $notif_stmt->bindParam(':called_by', $current_user_fullname);
                $notif_stmt->bindParam(':response', $_POST['parent_response']);
                $notif_stmt->bindParam(':notes', $_POST['notification_notes']);
                $notif_stmt->execute();
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
                $emerg_stmt->bindParam(':vital_signs', $vital_signs);
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
            
            // Send email notification to parent if emergency email exists
            if (!empty($_POST['emergency_email'])) {
                // Prepare incident data for email
                $incident_data_for_email = [
                    'incident_type' => $_POST['incident_type'],
                    'incident_date' => $_POST['incident_date'],
                    'incident_time' => $_POST['incident_time'],
                    'location' => $_POST['location'],
                    'description' => $_POST['description'],
                    'witness' => $_POST['witness'],
                    'action_taken' => $_POST['action_taken'],
                    'temperature' => $_POST['temperature'],
                    'blood_pressure' => $_POST['blood_pressure'],
                    'heart_rate' => $_POST['heart_rate'],
                    'treatment_given' => $_POST['treatment_given'],
                    'medicine_given' => $medicine_given,
                    'disposition' => $_POST['disposition'],
                    'referred_to' => $_POST['referred_to'],
                    'response_time' => $_POST['response_time'] ?? null,
                    'ambulance_called' => $_POST['ambulance_called'] ?? null,
                    'ambulance_time' => $_POST['ambulance_time'] ?? null,
                    'hospital_referred' => $_POST['hospital_referred'] ?? null,
                    'reporter_name' => $reporter_name,
                    'reporter_id' => $reporter_id,
                    'reporter_type' => $reporter_type
                ];
                
                // Create student data array for email
                $student_data_for_email = [
                    'student_id' => $_POST['student_id'],
                    'full_name' => $_POST['student_name'],
                    'year_level' => explode(' - ', $_POST['grade_section'])[0] ?? 'N/A',
                    'section' => explode(' - ', $_POST['grade_section'])[1] ?? 'N/A',
                    'emergency_contact' => $_POST['parent_name'],
                    'emergency_phone' => $_POST['parent_contact'],
                    'emergency_email' => $_POST['emergency_email']
                ];
                
                $email_sent = sendIncidentNotification($student_data_for_email, $incident_data_for_email, $incident_code, $db, $current_user_id, $current_user_fullname);
                
                if ($email_sent) {
                    $success_message = "Incident logged successfully! Parent notification sent to " . $_POST['emergency_email'] . ". Incident Code: " . $incident_code;
                } else {
                    $success_message = "Incident logged successfully! (Parent notification failed to send). Incident Code: " . $incident_code;
                }
            } else {
                $success_message = "Incident logged successfully! (No emergency email found for parent notification). Incident Code: " . $incident_code;
            }
            
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

// Function to search for person by ID from API
function searchPersonById($id) {
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
            foreach ($api_response['records'] as $person) {
                if (isset($person['student_id']) && $person['student_id'] == $id) {
                    return $person;
                }
            }
        }
    }
    
    return null;
}

// Search for student if ID provided and verified
if (!empty($student_id_search) && isset($_SESSION['verified_student_id']) && $_SESSION['verified_student_id'] === $student_id_search && !isset($_POST['action'])) {
    $student_data = searchPersonById($student_id_search);
    
    if (!$student_data) {
        $search_error = "Student ID not found in the system.";
        unset($_SESSION['verified_student_id']);
    }
} elseif (!empty($student_id_search) && (!isset($_SESSION['verified_student_id']) || $_SESSION['verified_student_id'] !== $student_id_search)) {
    $show_verification_modal = true;
}

// Search for reporter if ID provided and verified
if (!empty($reporter_id_search) && isset($_SESSION['verified_reporter_id']) && $_SESSION['verified_reporter_id'] === $reporter_id_search && !isset($_POST['action'])) {
    $reporter_data = searchPersonById($reporter_id_search);
    
    if (!$reporter_data) {
        $reporter_search_error = "Reporter ID not found in the system.";
        unset($_SESSION['verified_reporter_id']);
    }
} elseif (!empty($reporter_id_search) && (!isset($_SESSION['verified_reporter_id']) || $_SESSION['verified_reporter_id'] !== $reporter_id_search)) {
    $show_reporter_verification_modal = true;
}

// Clear verification if no student ID
if (empty($student_id_search) && isset($_SESSION['verified_student_id'])) {
    unset($_SESSION['verified_student_id']);
}

// Clear verification if no reporter ID
if (empty($reporter_id_search) && isset($_SESSION['verified_reporter_id'])) {
    unset($_SESSION['verified_reporter_id']);
}

// Get incidents by type
function getIncidentsByType($db, $type = null) {
    try {
        $query = "SELECT i.*, u.full_name as reported_by_name,
                         pn.response as parent_response
                  FROM incidents i
                  LEFT JOIN users u ON i.created_by = u.id
                  LEFT JOIN parent_notifications pn ON i.id = pn.incident_id";
        
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

        /* Search Cards Container */
        .search-cards-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        .incident-type-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .type-emergency {
            background: #ffebee;
            color: #c62828;
        }

        .type-minor {
            background: #fff3e0;
            color: #e65100;
        }

        .type-incident {
            background: #e3f2fd;
            color: #1565c0;
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

        .btn-danger {
            background: #c62828;
            color: white;
        }

        .btn-danger:hover {
            background: #b71c1c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(198, 40, 40, 0.2);
        }

        .btn-warning {
            background: #e65100;
            color: white;
        }

        .btn-warning:hover {
            background: #bf360c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 81, 0, 0.2);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .type-list {
            list-style: none;
        }

        .type-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eceff1;
        }

        .type-item:last-child {
            border-bottom: none;
        }

        .type-name {
            font-weight: 500;
            color: #37474f;
        }

        .type-count {
            background: #eceff1;
            color: #37474f;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Incident Form Card */
        .incident-form-card {
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

        .reporter-info-bar {
            background: #e3f2fd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #90caf9;
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

        .reporter-avatar-sm {
            width: 70px;
            height: 70px;
            background: #1565c0;
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

        .reporter-details h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1565c0;
            margin-bottom: 6px;
        }

        .student-details p, .reporter-details p {
            color: #546e7a;
            font-size: 0.95rem;
        }

        .reporter-type-badge {
            background: #bbdefb;
            color: #1565c0;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 8px;
        }

        .medical-condition-tag {
            background: #ffebee;
            color: #c62828;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 8px;
        }

        /* Emergency Contact Display */
        .emergency-contact-info {
            margin-top: 12px;
            padding: 12px 16px;
            background: #fff3e0;
            border: 1px solid #ffb74d;
            border-radius: 12px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
        }

        .emergency-contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #e65100;
            font-size: 0.9rem;
        }

        .emergency-contact-item svg {
            width: 18px;
            height: 18px;
            color: #e65100;
        }

        .email-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Incident Type Selector */
        .incident-type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 30px;
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
            padding: 20px;
            background: #eceff1;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .type-option input[type="radio"]:checked + label {
            background: #e3f2fd;
            border-color: #191970;
        }

        .type-option input[type="radio"]:checked + label .type-icon,
        .type-option input[type="radio"]:checked + label .type-title {
            color: #191970;
        }

        .type-option label:hover {
            border-color: #191970;
            transform: translateY(-2px);
        }

        .type-icon {
            font-size: 28px;
            margin-bottom: 10px;
            color: #546e7a;
            transition: color 0.3s ease;
        }

        .type-title {
            font-weight: 600;
            font-size: 1rem;
            color: #37474f;
            transition: color 0.3s ease;
        }

        .type-desc {
            font-size: 0.75rem;
            color: #78909c;
            margin-top: 6px;
        }

        /* Form Sections */
        .form-section {
            background: #eceff1;
            border-radius: 12px;
            padding: 24px;
            margin: 24px 0;
            border: 1px solid #cfd8dc;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #191970;
            font-weight: 600;
            font-size: 1rem;
        }

        .section-header svg {
            width: 20px;
            height: 20px;
            color: #191970;
        }

        /* Vital Signs Grid */
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
            font-size: 0.75rem;
            color: #546e7a;
            margin-bottom: 6px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .vital-input input {
            text-align: center;
            font-size: 1rem;
            background: white;
        }

        /* Items Used Section */
        .items-section {
            background: white;
            border-radius: 10px;
            padding: 16px;
            margin: 16px 0;
            border: 1px solid #cfd8dc;
        }

        .items-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .items-header h4 {
            font-size: 0.9rem;
            color: #191970;
            font-weight: 600;
        }

        .item-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
            background: #eceff1;
            padding: 10px;
            border-radius: 10px;
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
            color: #546e7a;
            text-align: center;
        }

        .remove-item {
            background: #ffebee;
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
            border-radius: 10px;
            width: 100%;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .add-item-btn:hover {
            background: #e3f2fd;
            border-color: #24248f;
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
            padding: 12px;
            background: #eceff1;
            border: 2px solid #cfd8dc;
            border-radius: 10px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
            color: #37474f;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .disposition-option input[type="radio"]:checked + label {
            background: #e3f2fd;
            border-color: #191970;
            color: #191970;
        }

        .disposition-option label:hover {
            border-color: #191970;
        }

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            overflow: hidden;
            margin-top: 30px;
            animation: fadeInUp 0.9s ease;
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

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
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

        .incident-code {
            font-weight: 600;
            color: #191970;
        }

        .reporter-info {
            font-size: 0.85rem;
            color: #1565c0;
        }

        .reporter-type-badge-small {
            background: #e3f2fd;
            color: #1565c0;
            padding: 2px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .parent-response {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .parent-response.not-reachable {
            background: #ffebee;
            color: #c62828;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #78909c;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #90a4ae;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 5px;
            color: #37474f;
        }

        .empty-state small {
            font-size: 0.85rem;
            color: #78909c;
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
            .main-grid, .search-cards-container {
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
            
            .incident-type-grid {
                grid-template-columns: 1fr;
            }
            
            .vital-signs-grid,
            .form-row,
            .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .disposition-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .student-info-bar, .reporter-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .item-row {
                grid-template-columns: 1fr;
            }
            
            .emergency-contact-info {
                flex-direction: column;
                align-items: flex-start;
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
                    <p>Document and manage school incidents, minor injuries, and emergency cases with parent notifications. Track who reported the incident.</p>
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
                            <p>Emergencies (30d)</p>
                            <?php if ($emergency_count > 0): ?>
                                <div class="warning-badge">⚠️ Requires attention</div>
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

                <!-- Search Cards -->
                <div class="search-cards-container">
                    <!-- Search Student Card -->
                    <div class="search-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="8" r="5"/>
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            </svg>
                            Find Student Involved
                        </div>
                        
                        <form method="GET" action="" class="search-form">
                            <?php if (!empty($reporter_id_search)): ?>
                                <input type="hidden" name="reporter_id" value="<?php echo htmlspecialchars($reporter_id_search); ?>">
                            <?php endif; ?>
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

                        <?php if ($student_data && !$search_error): ?>
                            <div style="margin-top: 20px; padding: 16px; background: #e8f5e9; border-radius: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 50px; height: 50px; background: #2e7d32; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                        <?php echo strtoupper(substr($student_data['full_name'] ?? 'NA', 0, 2)); ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($student_data['full_name'] ?? 'N/A'); ?></strong><br>
                                        <small>Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Search Reporter Card -->
                    <div class="search-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            Find Reporter
                        </div>
                        <p style="color: #546e7a; font-size: 0.85rem; margin-bottom: 15px;">
                            Who reported the incident? (Teacher, Faculty, Staff, or Student)
                        </p>
                        
                        <form method="GET" action="" class="search-form">
                            <?php if (!empty($student_id_search)): ?>
                                <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_id_search); ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="reporter_id">Reporter ID</label>
                                <input type="text" class="form-control" id="reporter_id" name="reporter_id" 
                                       placeholder="Enter reporter's ID" 
                                       value="<?php echo htmlspecialchars($reporter_id_search); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Search Reporter</button>
                        </form>

                        <?php if ($reporter_search_error): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #ffebee; border-radius: 12px; color: #c62828; font-size: 0.9rem;">
                                <?php echo $reporter_search_error; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($reporter_data && !$reporter_search_error): ?>
                            <div style="margin-top: 20px; padding: 16px; background: #e3f2fd; border-radius: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 50px; height: 50px; background: #1565c0; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                        <?php echo strtoupper(substr($reporter_data['full_name'] ?? 'NA', 0, 2)); ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($reporter_data['full_name'] ?? 'N/A'); ?></strong><br>
                                        <?php if (isset($reporter_data['role'])): ?>
                                            <span class="reporter-type-badge-small"><?php echo htmlspecialchars($reporter_data['role']); ?></span>
                                        <?php else: ?>
                                            <span class="reporter-type-badge-small">Student</span>
                                        <?php endif; ?>
                                        <small style="color: #546e7a; display: block;">
                                            <?php if (!empty($reporter_data['year_level'])): ?>
                                                Grade <?php echo htmlspecialchars($reporter_data['year_level']); ?> - <?php echo htmlspecialchars($reporter_data['section']); ?>
                                            <?php else: ?>
                                                Faculty/Staff
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats Card -->
                <div class="quick-stats-card" style="margin-bottom: 30px;">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 3V21H21"/>
                            <path d="M7 15L10 11L13 14L20 7"/>
                        </svg>
                        Quick Overview
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.9rem; color: #546e7a; margin-bottom: 8px;">Total Incidents This Month</div>
                        <div style="font-size: 2rem; font-weight: 700; color: #191970;">
                            <?php 
                            $total = 0;
                            foreach ($stats['by_type'] as $type) {
                                $total += $type['count'];
                            }
                            echo $total;
                            ?>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: #e65100;"><?php echo count($incidents_minor); ?></div>
                            <div style="font-size: 0.8rem; color: #546e7a;">Minor Injuries</div>
                        </div>
                        <div style="background: #eceff1; padding: 16px; border-radius: 12px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: #c62828;"><?php echo count($incidents_emergency); ?></div>
                            <div style="font-size: 0.8rem; color: #546e7a;">Emergencies</div>
                        </div>
                    </div>

                    <?php if (!empty($stats['by_type'])): ?>
                        <div style="margin-top: 20px;">
                            <div style="font-size: 0.9rem; font-weight: 600; color: #191970; margin-bottom: 12px;">
                                Incidents by Type (30 days)
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

                <?php if ($student_data && $reporter_data): ?>
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

                    <!-- Student Info -->
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
                                <span class="medical-condition-tag">
                                    ⚕️ <?php echo htmlspecialchars($student_data['medical_conditions']); ?>
                                </span>
                            <?php endif; ?>
                            
                            <!-- Emergency Contact Information -->
                            <?php if (!empty($student_data['emergency_contact']) || !empty($student_data['emergency_phone']) || !empty($student_data['emergency_email'])): ?>
                                <div class="emergency-contact-info">
                                    <?php if (!empty($student_data['emergency_contact'])): ?>
                                        <div class="emergency-contact-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                                <circle cx="12" cy="7" r="4"/>
                                            </svg>
                                            <?php echo htmlspecialchars($student_data['emergency_contact']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($student_data['emergency_phone'])): ?>
                                        <div class="emergency-contact-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                            </svg>
                                            <?php echo htmlspecialchars($student_data['emergency_phone']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($student_data['emergency_email'])): ?>
                                        <div class="emergency-contact-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                                <polyline points="22,6 12,13 2,6"/>
                                            </svg>
                                            <span class="email-badge">
                                                <?php echo htmlspecialchars($student_data['emergency_email']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Reporter Info -->
                    <div class="reporter-info-bar">
                        <div class="reporter-avatar-sm">
                            <?php echo strtoupper(substr($reporter_data['full_name'] ?? 'NA', 0, 2)); ?>
                        </div>
                        <div class="reporter-details">
                            <h3>Reported By: <?php echo htmlspecialchars($reporter_data['full_name'] ?? 'N/A'); ?></h3>
                            <p>
                                ID: <?php echo htmlspecialchars($reporter_data['student_id']); ?>
                            </p>
                            <?php if (isset($reporter_data['role'])): ?>
                                <span class="reporter-type-badge"><?php echo htmlspecialchars($reporter_data['role']); ?></span>
                            <?php else: ?>
                                <span class="reporter-type-badge">Student</span>
                            <?php endif; ?>
                            
                            <?php if (!empty($reporter_data['year_level'])): ?>
                                <span style="color: #546e7a; font-size: 0.9rem; display: block; margin-top: 4px;">
                                    Grade <?php echo htmlspecialchars($reporter_data['year_level']); ?> - <?php echo htmlspecialchars($reporter_data['section']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #546e7a; font-size: 0.9rem; display: block; margin-top: 4px;">
                                    Faculty / Staff
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" action="" id="incidentForm">
                        <input type="hidden" name="action" value="save_incident">
                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                        <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                        <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                        <input type="hidden" name="emergency_email" id="emergency_email" value="<?php echo htmlspecialchars($student_data['emergency_email'] ?? ''); ?>">
                        
                        <!-- Reporter Information -->
                        <input type="hidden" name="reporter_id" value="<?php echo htmlspecialchars($reporter_data['student_id']); ?>">
                        <input type="hidden" name="reporter_name" value="<?php echo htmlspecialchars($reporter_data['full_name']); ?>">
                        <input type="hidden" name="reporter_type" value="<?php echo isset($reporter_data['role']) ? htmlspecialchars($reporter_data['role']) : 'Student'; ?>">

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
                                    <label>Date</label>
                                    <input type="date" name="incident_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Time</label>
                                    <input type="time" name="incident_time" class="form-control" value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Location</label>
                                <select name="location" class="form-control" required>
                                    <option value="">Select location</option>
                                    <option value="Classroom">Classroom</option>
                                    <option value="Gym">Gym / PE Area</option>
                                    <option value="Field">Sports Field</option>
                                    <option value="Hallway">Hallway / Corridor</option>
                                    <option value="Canteen">Canteen</option>
                                    <option value="Comfort Room">Comfort Room</option>
                                    <option value="Library">Library</option>
                                    <option value="Stairs">Stairs</option>
                                    <option value="School Grounds">School Grounds</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Description of Incident</label>
                                <textarea name="description" class="form-control" placeholder="What happened? Be specific..." required></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Witness (Optional)</label>
                                    <input type="text" name="witness" class="form-control" placeholder="Name of witness">
                                </div>
                                <div class="form-group">
                                    <label>Action Taken</label>
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
                                Treatment
                            </div>
                            
                            <div class="form-group">
                                <label>Treatment Given</label>
                                <input type="text" name="treatment_given" class="form-control" placeholder="e.g., Wound cleaned, cold compress">
                            </div>

                            <!-- Medicine Selection -->
                            <div class="items-section">
                                <div class="items-header">
                                    <h4>💊 Medicines / Supplies Used</h4>
                                    <span style="font-size: 0.75rem; color: #546e7a;">Items will be deducted from inventory</span>
                                </div>
                                
                                <div id="items-container">
                                    <!-- Item rows will be added here dynamically -->
                                </div>
                                
                                <button type="button" class="add-item-btn" onclick="addItemRow()">
                                    + Add Medicine or Supply
                                </button>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Disposition</label>
                                    <select name="disposition" class="form-control">
                                        <option value="">Select disposition</option>
                                        <option value="Returned to class">Returned to class</option>
                                        <option value="Sent home">Sent home</option>
                                        <option value="Referred to hospital">Referred to hospital</option>
                                        <option value="Observed in clinic">Observed in clinic</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Referred To (if applicable)</label>
                                    <input type="text" name="referred_to" class="form-control" placeholder="e.g., Hospital, Health Center">
                                </div>
                            </div>
                        </div>

                        <!-- Emergency Case Section -->
                        <div class="form-section" id="emergency-section" style="display: none;">
                            <div class="section-header" style="color: #c62828;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 10v4M12 6v8M6 14v-2"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                                Emergency Case Details
                            </div>
                            
                            <div class="form-group">
                                <label>Symptoms</label>
                                <textarea name="symptoms" class="form-control" placeholder="Detailed symptoms..."></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Response Time</label>
                                    <input type="time" name="response_time" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Ambulance Called</label>
                                    <select name="ambulance_called" class="form-control">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Ambulance Time</label>
                                    <input type="time" name="ambulance_time" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Hospital Referred</label>
                                    <input type="text" name="hospital_referred" class="form-control" placeholder="Hospital name">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Parent Contacted</label>
                                    <select name="parent_contacted_emergency" class="form-control">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Contact Time</label>
                                    <input type="time" name="parent_contact_time" class="form-control">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Outcome</label>
                                <textarea name="emergency_outcome" class="form-control" placeholder="Final outcome of emergency..."></textarea>
                            </div>
                        </div>

                        <!-- Parent Notification Section (Auto-filled) -->
                        <div class="form-section">
                            <div class="section-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                                Parent/Guardian Notification
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Parent Name</label>
                                    <input type="text" name="parent_name" id="parent_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($student_data['emergency_contact'] ?? ''); ?>" 
                                           placeholder="Auto-fills from student record" required>
                                </div>
                                <div class="form-group">
                                    <label>Contact Number</label>
                                    <input type="text" name="parent_contact" id="parent_contact" class="form-control" 
                                           value="<?php echo htmlspecialchars($student_data['emergency_phone'] ?? ''); ?>" 
                                           placeholder="Auto-fills from student record" required>
                                </div>
                            </div>

                            <?php if (!empty($student_data['emergency_email'])): ?>
                                <div class="form-group">
                                    <label>Emergency Email</label>
                                    <div style="padding: 12px; background: #e3f2fd; border-radius: 10px; color: #1565c0; display: flex; align-items: center; gap: 10px;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                            <polyline points="22,6 12,13 2,6"/>
                                        </svg>
                                        <strong><?php echo htmlspecialchars($student_data['emergency_email']); ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Notification Time</label>
                                    <input type="time" name="notification_time" class="form-control" value="<?php echo date('H:i'); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Parent Response</label>
                                    <select name="parent_response" class="form-control">
                                        <option value="">Select response</option>
                                        <option value="Will pick up">Will pick up</option>
                                        <option value="On the way">On the way</option>
                                        <option value="Not reachable">Not reachable</option>
                                        <option value="Will call back">Will call back</option>
                                        <option value="Declined">Declined</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Notification Notes</label>
                                <textarea name="notification_notes" class="form-control" placeholder="Additional notes about parent contact..."></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 20px;" onclick="return validateForm()">
                            Save Incident Record & Notify Parent
                        </button>
                    </form>
                </div>
                <?php elseif ($student_data && !$reporter_data): ?>
                <div class="alert alert-info" style="margin-top: 20px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="12" x2="12" y2="16"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    Please search for the reporter (teacher, faculty, or student who reported the incident) to continue.
                </div>
                <?php elseif ($reporter_data && !$student_data): ?>
                <div class="alert alert-info" style="margin-top: 20px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="12" x2="12" y2="16"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    Please search for the student involved to continue.
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
                            <div class="section-header">
                                <h2 style="color: #191970;">Recent Incidents</h2>
                                <span style="color: #546e7a;">Last 50 records</span>
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
                                                <th>Reported By</th>
                                                <th>Location</th>
                                                <th>Description</th>
                                                <th>Parent Contact</th>
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
                                                        <small style="color: #546e7a;"><?php echo htmlspecialchars($incident['student_id']); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y', strtotime($incident['incident_date'])); ?><br>
                                                        <small style="color: #546e7a;"><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="incident-type-badge type-<?php echo strtolower($incident['incident_type']); ?>">
                                                            <?php echo $incident['incident_type']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="reporter-info">
                                                            <?php echo htmlspecialchars($incident['reporter_name'] ?? $incident['student_name']); ?><br>
                                                            <small>
                                                                <span class="reporter-type-badge-small">
                                                                    <?php echo htmlspecialchars($incident['reporter_type'] ?? 'Student'); ?>
                                                                </span>
                                                            </small>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars(substr($incident['description'], 0, 50)) . '...'; ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($incident['parent_name'])): ?>
                                                            <span class="parent-response">
                                                                <?php echo htmlspecialchars($incident['parent_name']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <small style="color: #90a4ae;">Not notified</small>
                                                        <?php endif; ?>
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
                            <div class="section-header">
                                <h2 style="color: #c62828;">🚨 Emergency Cases</h2>
                                <span style="color: #546e7a;">Requires immediate attention</span>
                            </div>
                            
                            <?php if (!empty($incidents_emergency)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Reported By</th>
                                                <th>Location</th>
                                                <th>Vital Signs</th>
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
                                                    <td>
                                                        <span class="reporter-info">
                                                            <?php echo htmlspecialchars($incident['reporter_name'] ?? $incident['student_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($incident['vital_signs']); ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($incident['action_taken']); ?></small>
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
                            <div class="section-header">
                                <h2 style="color: #e65100;">🩹 Minor Injuries</h2>
                                <span style="color: #546e7a;">Quick treatment cases</span>
                            </div>
                            
                            <?php if (!empty($incidents_minor)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Reported By</th>
                                                <th>Injury</th>
                                                <th>Treatment</th>
                                                <th>Medicine Given</th>
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
                                                    <td>
                                                        <span class="reporter-info">
                                                            <?php echo htmlspecialchars($incident['reporter_name'] ?? $incident['student_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(substr($incident['description'], 0, 30)); ?></td>
                                                    <td><?php echo htmlspecialchars($incident['treatment_given']); ?></td>
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
                            <div class="section-header">
                                <h2 style="color: #1565c0;">📝 Regular Incidents</h2>
                                <span style="color: #546e7a;">School-related events</span>
                            </div>
                            
                            <?php if (!empty($incidents_regular)): ?>
                                <div class="table-wrapper">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Incident Code</th>
                                                <th>Student</th>
                                                <th>Date/Time</th>
                                                <th>Reported By</th>
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
                                                    <td>
                                                        <span class="reporter-info">
                                                            <?php echo htmlspecialchars($incident['reporter_name'] ?? $incident['student_name']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($incident['location']); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($incident['description'], 0, 30)); ?></td>
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

    <!-- Security Verification Modal for Student -->
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
                <input type="hidden" name="verify_student" value="1">
                <?php if (!empty($reporter_id_search)): ?>
                    <input type="hidden" name="reporter_id" value="<?php echo htmlspecialchars($reporter_id_search); ?>">
                <?php endif; ?>
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
                This helps us maintain confidentiality of student records
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Security Verification Modal for Reporter -->
    <?php if ($show_reporter_verification_modal && !empty($reporter_id_search)): ?>
    <div class="modal-overlay" id="reporterVerificationModal">
        <div class="modal-container">
            <div class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h2 class="modal-title">Secure Access Required</h2>
            <p class="modal-subtitle">
                You are verifying the reporter's identity<br>
                <strong>Reporter ID: <?php echo htmlspecialchars($reporter_id_search); ?></strong>
            </p>
            
            <?php if (isset($reporter_verification_error)): ?>
                <div class="modal-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?php echo $reporter_verification_error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="modal-form">
                <input type="hidden" name="reporter_id" value="<?php echo htmlspecialchars($reporter_id_search); ?>">
                <input type="hidden" name="verify_reporter" value="1">
                <?php if (!empty($student_id_search)): ?>
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_id_search); ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label for="password_reporter">Enter Your Password to Continue</label>
                    <input type="password" class="form-control" id="password_reporter" name="password" 
                           placeholder="••••••••" required autofocus>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn secondary" onclick="cancelReporterAccess()">Cancel</button>
                    <button type="submit" name="verify_access" class="modal-btn primary">Verify & Access</button>
                </div>
            </form>
            <p style="text-align: center; margin-top: 20px; font-size: 0.8rem; color: #546e7a;">
                This helps us maintain confidentiality of school personnel records
            </p>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let itemCount = 0;

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
                emergencySection.style.display = 'none';
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

        // Add new item row
        function addItemRow() {
            const container = document.getElementById('items-container');
            const template = document.getElementById('item-row-template');
            const clone = template.content.cloneNode(true);
            
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
        function validateForm() {
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
            pageTitle.textContent = 'Incidents & Emergencies';
        }

        // Add first item row by default
        document.addEventListener('DOMContentLoaded', function() {
            addItemRow();
        });

        // Cancel access functions
        function cancelAccess() {
            window.location.href = window.location.pathname; // Redirect to same page without query string
        }

        function cancelReporterAccess() {
            window.location.href = window.location.pathname; // Redirect to same page without query string
        }

        // Close modal when clicking outside (Student modal)
        const studentModal = document.getElementById('verificationModal');
        if (studentModal) {
            studentModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    cancelAccess();
                }
            });
        }

        // Close modal when clicking outside (Reporter modal)
        const reporterModal = document.getElementById('reporterVerificationModal');
        if (reporterModal) {
            reporterModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    cancelReporterAccess();
                }
            });
        }

        // Prevent background scrolling when modals are open
        if (document.getElementById('verificationModal') || document.getElementById('reporterVerificationModal')) {
            document.body.style.overflow = 'hidden';
        }
    </script>

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
                   placeholder="Quantity" min="1" step="1" required onchange="validateQuantity(this)">
            <div class="item-stock" id="stock-info"></div>
            <button type="button" class="remove-item" onclick="removeItemRow(this)">✕</button>
        </div>
    </template>
</body>
</html>