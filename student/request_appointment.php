<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$student_id = $_SESSION['student_id'];
$student_data = $_SESSION['student_data'];

// Get appointment types/categories - REMOVED Consultation
$appointment_types = [
    'Vaccination' => 'Vaccination',
    'Physical Exam' => 'Physical Examination',
    'Deworming' => 'Deworming',
    'Health Screening' => 'Health Screening',
    'Medical Clearance' => 'Medical Clearance',
    'Follow-up' => 'Follow-up Visit',
    'Other' => 'Other'
];

// Get all appointments for the student
$query = "SELECT a.*, 
          DATE_FORMAT(a.appointment_date, '%Y-%m-%d') as formatted_date,
          DATE_FORMAT(a.appointment_time, '%h:%i %p') as formatted_time,
          CASE 
              WHEN a.status = 'scheduled' THEN 'pending'
              WHEN a.status = 'approved' THEN 'approved'
              WHEN a.status = 'completed' THEN 'completed'
              WHEN a.status = 'cancelled' THEN 'cancelled'
          END as calendar_status,
          CASE 
              WHEN a.status = 'scheduled' THEN 'bg-yellow-500'
              WHEN a.status = 'approved' THEN 'bg-green-500'
              WHEN a.status = 'completed' THEN 'bg-blue-500'
              WHEN a.status = 'cancelled' THEN 'bg-red-500'
          END as status_color
          FROM appointments a 
          WHERE a.patient_id IN (SELECT id FROM patients WHERE student_id = :student_id)
          ORDER BY a.appointment_date DESC, a.appointment_time DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group appointments by date for calendar
$calendar_events = [];
foreach ($appointments as $appt) {
    $date = $appt['formatted_date'];
    if (!isset($calendar_events[$date])) {
        $calendar_events[$date] = [];
    }
    $calendar_events[$date][] = $appt;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $appointment_type = $_POST['appointment_type'];
    $reason = $_POST['reason'];
    $doctor_id = !empty($_POST['doctor_id']) ? $_POST['doctor_id'] : null;
    $notes = $_POST['notes'] ?? '';
    
    // First, get or create patient record
    $query = "SELECT id FROM patients WHERE student_id = :student_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        $patient_id = $patient['id'];
    } else {
        // Create patient record
        $query = "INSERT INTO patients (patient_id, student_id, full_name, email, phone) 
                  VALUES (:patient_id, :student_id, :full_name, :email, :phone)";
        $stmt = $db->prepare($query);
        $patient_id_code = 'P' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->bindParam(':patient_id', $patient_id_code);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':full_name', $student_data['full_name']);
        $stmt->bindParam(':email', $student_data['email']);
        $stmt->bindParam(':phone', $student_data['contact_no']);
        $stmt->execute();
        $patient_id = $db->lastInsertId();
    }
    
    // Create appointment with type
    $full_reason = $appointment_type . ': ' . $reason;
    if (!empty($notes)) {
        $full_reason .= ' (Notes: ' . $notes . ')';
    }
    
    $query = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, reason, status) 
              VALUES (:patient_id, :doctor_id, :appointment_date, :appointment_time, :reason, 'scheduled')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':patient_id', $patient_id);
    $stmt->bindParam(':doctor_id', $doctor_id);
    $stmt->bindParam(':appointment_date', $appointment_date);
    $stmt->bindParam(':appointment_time', $appointment_time);
    $stmt->bindParam(':reason', $full_reason);
    
    if ($stmt->execute()) {
        $success_message = "Appointment requested successfully! The clinic staff will review your request.";
        // Refresh the page to show new appointment
        header('Location: request_appointment.php?success=1');
        exit();
    } else {
        $error_message = "Failed to request appointment. Please try again.";
    }
}

// Get available doctors
$query = "SELECT id, full_name FROM users WHERE role IN ('doctor', 'nurse') AND status = 'active' ORDER BY full_name";
$stmt = $db->query($query);
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected appointment details for modal
$selected_appointment = null;
if (isset($_GET['view'])) {
    $query = "SELECT a.*, 
              DATE_FORMAT(a.appointment_date, '%M %d, %Y') as display_date,
              DATE_FORMAT(a.appointment_time, '%h:%i %p') as display_time,
              p.full_name as patient_name,
              p.student_id,
              u.full_name as doctor_name
              FROM appointments a
              LEFT JOIN patients p ON a.patient_id = p.id
              LEFT JOIN users u ON a.doctor_id = u.id
              WHERE a.id = :id AND p.student_id = :student_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_GET['view']);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $selected_appointment = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Appointment | Student Portal | ICARE Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.css" />
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

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-text p {
            color: #546e7a;
            font-size: 1rem;
            font-weight: 400;
        }

        .student-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .student-badge svg {
            width: 20px;
            height: 20px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #e8f5e9;
            border: 1px solid #81c784;
            color: #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            border: 1px solid #ef5350;
            color: #c62828;
        }

        .alert svg {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Tabs */
        .tabs-container {
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            background: white;
            padding: 10px;
            border-radius: 16px;
            border: 1px solid #cfd8dc;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .tab-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            background: transparent;
            color: #546e7a;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-btn.active {
            background: #191970;
            color: white;
        }

        .tab-btn svg {
            width: 20px;
            height: 20px;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Calendar Styles */
        .calendar-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #cfd8dc;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eceff1;
        }

        .calendar-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-nav-btn {
            width: 40px;
            height: 40px;
            border: 1px solid #cfd8dc;
            background: white;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #191970;
        }

        .calendar-nav-btn:hover {
            background: #191970;
            color: white;
            border-color: #191970;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
        }

        .calendar-weekday {
            text-align: center;
            font-weight: 600;
            color: #191970;
            padding: 10px;
            font-size: 0.9rem;
        }

        .calendar-day {
            min-height: 120px;
            background: #f8f9fa;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            padding: 10px;
            transition: all 0.3s ease;
        }

        .calendar-day:hover {
            border-color: #191970;
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
        }

        .calendar-day.empty {
            background: transparent;
            border: 1px dashed #cfd8dc;
        }

        .day-number {
            font-weight: 600;
            color: #191970;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .calendar-events {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .calendar-event {
            font-size: 0.75rem;
            padding: 4px 6px;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .calendar-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .event-pending { background: #f59e0b; }
        .event-approved { background: #10b981; }
        .event-completed { background: #3b82f6; }
        .event-cancelled { background: #ef4444; }

        /* Appointments List */
        .appointments-list {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #cfd8dc;
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eceff1;
        }

        .list-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

        .appointment-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .appointment-item:hover {
            border-color: #191970;
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
        }

        .appointment-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 15px;
        }

        .status-pending { background: #f59e0b; }
        .status-approved { background: #10b981; }
        .status-completed { background: #3b82f6; }
        .status-cancelled { background: #ef4444; }

        .appointment-info {
            flex: 1;
        }

        .appointment-date {
            font-weight: 600;
            color: #191970;
            margin-bottom: 3px;
        }

        .appointment-reason {
            font-size: 0.9rem;
            color: #37474f;
            margin-bottom: 3px;
        }

        .appointment-meta {
            font-size: 0.8rem;
            color: #78909c;
            display: flex;
            gap: 15px;
        }

        .appointment-status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background: #d4edda;
            color: #155724;
        }

        .badge-completed {
            background: #cce5ff;
            color: #004085;
        }

        .badge-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 2px solid #eceff1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: #eceff1;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #546e7a;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #191970;
            color: white;
        }

        .modal-body {
            padding: 20px;
        }

        .detail-group {
            margin-bottom: 20px;
        }

        .detail-label {
            font-size: 0.85rem;
            color: #78909c;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1rem;
            color: #191970;
            font-weight: 500;
        }

        .detail-value.large {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .status-display {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .modal-footer {
            padding: 20px;
            border-top: 2px solid #eceff1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
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
        }

        .btn-secondary {
            background: #eceff1;
            color: #37474f;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
        }

        /* Form Styles */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #cfd8dc;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eceff1;
        }

        .form-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }

        .form-title h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 5px;
        }

        .form-title p {
            color: #546e7a;
            font-size: 0.95rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
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
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23191970' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        /* Info Card */
        .info-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #cfd8dc;
        }

        .info-title {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-title svg {
            width: 20px;
            height: 20px;
            color: #667eea;
        }

        .appointment-types {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .type-item {
            background: white;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 500;
            color: #37474f;
            transition: all 0.3s ease;
            cursor: default;
        }

        .type-item:hover {
            border-color: #191970;
            background: #f0f0ff;
            transform: translateY(-2px);
        }

        .type-item i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        /* Quick Tips */
        .tips-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .tips-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tips-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #191970;
        }

        .tips-list {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .tip-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
        }

        .tip-icon {
            width: 36px;
            height: 36px;
            background: #191970;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .tip-text {
            font-size: 0.85rem;
            color: #37474f;
            line-height: 1.4;
        }

        .tip-text strong {
            color: #191970;
            display: block;
            margin-bottom: 2px;
        }

        /* Status Summary */
        .status-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .status-badge {
            flex: 1;
            padding: 15px;
            border-radius: 12px;
            background: white;
            border: 1px solid #cfd8dc;
            text-align: center;
        }

        .status-badge .count {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
        }

        .status-badge .label {
            font-size: 0.85rem;
            color: #546e7a;
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
            .appointment-types {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .tips-list {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .calendar-grid {
                gap: 5px;
            }
            
            .calendar-day {
                min-height: 100px;
                padding: 8px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .appointment-types {
                grid-template-columns: 1fr;
            }
            
            .tips-list {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
                overflow-x: auto;
            }
            
            .calendar-day {
                min-width: 120px;
            }
            
            .status-summary {
                flex-wrap: wrap;
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
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="welcome-text">
                        <h1>Request Appointment</h1>
                        <p>Schedule your visit to the clinic and track your appointments.</p>
                    </div>
                    <div class="student-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        Student Portal
                    </div>
                </div>

                <!-- Status Summary -->
                <div class="status-summary">
                    <?php
                    $status_counts = [
                        'scheduled' => 0,
                        'approved' => 0,
                        'completed' => 0,
                        'cancelled' => 0
                    ];
                    foreach ($appointments as $appt) {
                        $status_counts[$appt['status']] = ($status_counts[$appt['status']] ?? 0) + 1;
                    }
                    ?>
                    <div class="status-badge">
                        <div class="count"><?php echo $status_counts['scheduled']; ?></div>
                        <div class="label">Pending</div>
                    </div>
                    <div class="status-badge">
                        <div class="count"><?php echo $status_counts['approved']; ?></div>
                        <div class="label">Approved</div>
                    </div>
                    <div class="status-badge">
                        <div class="count"><?php echo $status_counts['completed']; ?></div>
                        <div class="label">Completed</div>
                    </div>
                    <div class="status-badge">
                        <div class="count"><?php echo $status_counts['cancelled']; ?></div>
                        <div class="label">Cancelled</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tabs">
                        <button class="tab-btn active" onclick="switchTab('calendar')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            Calendar View
                        </button>
                        <button class="tab-btn" onclick="switchTab('list')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="8" y1="6" x2="21" y2="6"/>
                                <line x1="8" y1="12" x2="21" y2="12"/>
                                <line x1="8" y1="18" x2="21" y2="18"/>
                                <line x1="3" y1="6" x2="3.01" y2="6"/>
                                <line x1="3" y1="12" x2="3.01" y2="12"/>
                                <line x1="3" y1="18" x2="3.01" y2="18"/>
                            </svg>
                            List View
                        </button>
                        <button class="tab-btn" onclick="switchTab('new')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="16"/>
                                <line x1="8" y1="12" x2="16" y2="12"/>
                            </svg>
                            New Appointment
                        </button>
                    </div>
                </div>

                <!-- Calendar View Tab -->
                <div id="calendarTab" class="tab-content active">
                    <div class="calendar-container">
                        <div class="calendar-header">
                            <h2 id="currentMonthYear"><?php echo date('F Y'); ?></h2>
                            <div class="calendar-nav">
                                <button class="calendar-nav-btn" onclick="changeMonth(-1)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <path d="M15 18l-6-6 6-6"/>
                                    </svg>
                                </button>
                                <button class="calendar-nav-btn" onclick="changeMonth(1)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <path d="M9 18l6-6-6-6"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div id="calendarGrid" class="calendar-grid">
                            <!-- Calendar will be populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- List View Tab -->
                <div id="listTab" class="tab-content">
                    <div class="appointments-list">
                        <div class="list-header">
                            <h3>Your Appointments</h3>
                            <span><?php echo count($appointments); ?> total</span>
                        </div>
                        <?php if (empty($appointments)): ?>
                            <div style="text-align: center; padding: 40px; color: #78909c;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48" style="margin-bottom: 15px;">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <p>No appointments found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($appointments as $appt): ?>
                                <div class="appointment-item" onclick="viewAppointment(<?php echo $appt['id']; ?>)">
                                    <div class="appointment-status status-<?php echo $appt['status']; ?>"></div>
                                    <div class="appointment-info">
                                        <div class="appointment-date"><?php echo date('F j, Y', strtotime($appt['appointment_date'])); ?> at <?php echo date('g:i A', strtotime($appt['appointment_time'])); ?></div>
                                        <div class="appointment-reason"><?php echo htmlspecialchars($appt['reason']); ?></div>
                                        <div class="appointment-meta">
                                            <span>Requested: <?php echo date('M j, Y', strtotime($appt['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="appointment-status-badge badge-<?php echo $appt['status']; ?>">
                                        <?php echo ucfirst($appt['status']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- New Appointment Tab -->
                <div id="newTab" class="tab-content">
                    <!-- Appointment Types Info Card -->
                    <div class="info-card">
                        <div class="info-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                            Available Appointment Types
                        </div>
                        <div class="appointment-types">
                            <div class="type-item">
                                <i>💉</i>
                                Vaccination
                            </div>
                            <div class="type-item">
                                <i>📋</i>
                                Physical Exam
                            </div>
                            <div class="type-item">
                                <i>💊</i>
                                Deworming
                            </div>
                            <div class="type-item">
                                <i>🏥</i>
                                Health Screening
                            </div>
                            <div class="type-item">
                                <i>✅</i>
                                Medical Clearance
                            </div>
                            <div class="type-item">
                                <i>🔄</i>
                                Follow-up
                            </div>
                            <div class="type-item">
                                <i>📌</i>
                                Other
                            </div>
                        </div>
                    </div>

                    <!-- Main Form Card -->
                    <div class="form-card">
                        <div class="form-header">
                            <div class="form-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                </svg>
                            </div>
                            <div class="form-title">
                                <h2>Appointment Details</h2>
                                <p>Please fill in the information below to request an appointment.</p>
                            </div>
                        </div>

                        <?php if (isset($_GET['success'])): ?>
                            <div class="alert alert-success">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                    <path d="M22 4L12 14.01L9 11.01"/>
                                </svg>
                                Appointment requested successfully! The clinic staff will review your request.
                            </div>
                        <?php endif; ?>

                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-error">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="appointment_date">Preferred Date</label>
                                    <input type="date" class="form-control" id="appointment_date" name="appointment_date" 
                                           min="<?php echo date('Y-m-d'); ?>" 
                                           value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="appointment_time">Preferred Time</label>
                                    <input type="time" class="form-control" id="appointment_time" name="appointment_time" 
                                           value="09:00" min="08:00" max="17:00" required>
                                    <small style="color: #78909c; font-size: 0.7rem;">Clinic hours: 8:00 AM - 5:00 PM</small>
                                </div>

                                <div class="form-group">
                                    <label for="appointment_type">Appointment Type</label>
                                    <select class="form-control" id="appointment_type" name="appointment_type" required>
                                        <option value="">Select type</option>
                                        <?php foreach ($appointment_types as $key => $value): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="doctor_id">Preferred Staff (Optional)</label>
                                    <select class="form-control" id="doctor_id" name="doctor_id">
                                        <option value="">Any available staff</option>
                                        <?php foreach ($doctors as $doctor): ?>
                                            <option value="<?php echo $doctor['id']; ?>"><?php echo htmlspecialchars($doctor['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group full-width">
                                    <label for="reason">Reason for Appointment</label>
                                    <textarea class="form-control" id="reason" name="reason" rows="3" 
                                              placeholder="Please describe your concern or reason for visiting..." required></textarea>
                                </div>

                                <div class="form-group full-width">
                                    <label for="notes">Additional Notes (Optional)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" 
                                              placeholder="Any specific requests or information..."></textarea>
                                </div>
                            </div>

                            <div class="form-actions">
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                                    </svg>
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                        <path d="M5 12h14M12 5l7 7-7 7"/>
                                    </svg>
                                    Submit Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Tips Section -->
                <div class="tips-section">
                    <div class="tips-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color: #191970;">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        <h3>Appointment Tips</h3>
                    </div>
                    <div class="tips-list">
                        <div class="tip-item">
                            <div class="tip-icon">📅</div>
                            <div class="tip-text">
                                <strong>Book in Advance</strong>
                                Schedule at least 1 day ahead for better availability
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">⏰</div>
                            <div class="tip-text">
                                <strong>Be on Time</strong>
                                Arrive 5-10 minutes before your scheduled time
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📋</div>
                            <div class="tip-text">
                                <strong>Bring Requirements</strong>
                                Bring your student ID and any relevant medical records
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">✅</div>
                            <div class="tip-text">
                                <strong>Check Status</strong>
                                Monitor your appointment status in the dashboard
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📞</div>
                            <div class="tip-text">
                                <strong>Emergency?</strong>
                                For urgent concerns, visit the clinic immediately
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">🔄</div>
                            <div class="tip-text">
                                <strong>Reschedule</strong>
                                Contact the clinic if you need to reschedule
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Appointment Details Modal -->
    <div id="appointmentModal" class="modal">
        <div class="modal-content">
            <?php if ($selected_appointment): ?>
                <div class="modal-header">
                    <h3>Appointment Details</h3>
                    <button class="modal-close" onclick="closeModal()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="detail-group">
                        <div class="detail-label">Status</div>
                        <div class="status-display badge-<?php echo $selected_appointment['status']; ?>">
                            <?php echo ucfirst($selected_appointment['status']); ?>
                        </div>
                    </div>

                    <div class="detail-group">
                        <div class="detail-label">Date & Time</div>
                        <div class="detail-value large"><?php echo $selected_appointment['display_date']; ?></div>
                        <div class="detail-value">at <?php echo $selected_appointment['display_time']; ?></div>
                    </div>

                    <div class="detail-group">
                        <div class="detail-label">Reason</div>
                        <div class="detail-value"><?php echo nl2br(htmlspecialchars($selected_appointment['reason'])); ?></div>
                    </div>

                    <?php if ($selected_appointment['doctor_name']): ?>
                        <div class="detail-group">
                            <div class="detail-label">Assigned Staff</div>
                            <div class="detail-value"><?php echo htmlspecialchars($selected_appointment['doctor_name']); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="detail-group">
                        <div class="detail-label">Requested On</div>
                        <div class="detail-value"><?php echo date('F j, Y g:i A', strtotime($selected_appointment['created_at'])); ?></div>
                    </div>

                    <?php if ($selected_appointment['status'] === 'approved'): ?>
                        <div class="detail-group">
                            <div class="detail-label">Approval Note</div>
                            <div class="detail-value">Your appointment has been approved. Please arrive on time.</div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                    <?php if ($selected_appointment['status'] === 'scheduled'): ?>
                        <button class="btn btn-primary" onclick="cancelAppointment(<?php echo $selected_appointment['id']; ?>)">Cancel Appointment</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Appointment data for calendar
        const appointments = <?php echo json_encode($appointments); ?>;

        // Current month and year for calendar
        let currentDate = new Date();

        // Initialize calendar when page loads
        document.addEventListener('DOMContentLoaded', function() {
            renderCalendar();
            
            // Check if we should open modal from URL
            <?php if (isset($_GET['view'])): ?>
            document.getElementById('appointmentModal').classList.add('active');
            <?php endif; ?>
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });

        // Tab switching function
        function switchTab(tab) {
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tab + 'Tab').classList.add('active');
        }

        // Render calendar
        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            
            // Update month/year display
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            document.getElementById('currentMonthYear').textContent = monthNames[month] + ' ' + year;
            
            // Get first day of month and total days
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
            // Create calendar grid
            let calendarHtml = '';
            
            // Add weekday headers
            const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            weekdays.forEach(day => {
                calendarHtml += `<div class="calendar-weekday">${day}</div>`;
            });
            
            // Add empty cells for days before month starts
            for (let i = 0; i < firstDay; i++) {
                calendarHtml += '<div class="calendar-day empty"></div>';
            }
            
            // Add days of month
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dayAppointments = appointments.filter(a => a.formatted_date === dateStr);
                
                calendarHtml += `<div class="calendar-day">`;
                calendarHtml += `<div class="day-number">${day}</div>`;
                
                if (dayAppointments.length > 0) {
                    calendarHtml += '<div class="calendar-events">';
                    dayAppointments.forEach(appt => {
                        const time = appt.appointment_time.substring(0, 5);
                        calendarHtml += `<div class="calendar-event event-${appt.calendar_status}" onclick="viewAppointment(${appt.id})">`;
                        calendarHtml += `${time} - ${appt.reason.substring(0, 30)}${appt.reason.length > 30 ? '...' : ''}`;
                        calendarHtml += '</div>';
                    });
                    calendarHtml += '</div>';
                }
                
                calendarHtml += '</div>';
            }
            
            document.getElementById('calendarGrid').innerHTML = calendarHtml;
        }

        // Change month
        function changeMonth(delta) {
            currentDate.setMonth(currentDate.getMonth() + delta);
            renderCalendar();
        }

        // View appointment details
        function viewAppointment(id) {
            window.location.href = 'request_appointment.php?view=' + id;
        }

        // Close modal
        function closeModal() {
            document.getElementById('appointmentModal').classList.remove('active');
            // Remove view parameter from URL without refreshing
            const url = new URL(window.location);
            url.searchParams.delete('view');
            window.history.pushState({}, '', url);
        }

        // Cancel appointment
        function cancelAppointment(id) {
            if (confirm('Are you sure you want to cancel this appointment?')) {
                // Implement cancel functionality
                window.location.href = 'cancel_appointment.php?id=' + id;
            }
        }

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

        // Set minimum time based on clinic hours
        const timeInput = document.getElementById('appointment_time');
        if (timeInput) {
            timeInput.addEventListener('change', function() {
                const selectedTime = this.value;
                const hour = parseInt(selectedTime.split(':')[0]);
                
                if (hour < 8 || hour >= 17) {
                    alert('Please select a time between 8:00 AM and 5:00 PM (clinic hours).');
                    this.value = '09:00';
                }
            });
        }
    </script>
</body>
</html>