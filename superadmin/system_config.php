<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Initialize or load configuration
$config = [
    'clinic_name' => 'I CARE Clinic',
    'clinic_address' => '123 Medical Street, Health City',
    'clinic_phone' => '(02) 1234-5678',
    'clinic_email' => 'clinic@icare.edu.ph',
    'school_year' => '2025-2026',
    'semester' => '2nd Semester',
    'academic_start' => '2025-08-01',
    'academic_end' => '2026-05-31',
    'timezone' => 'Asia/Manila',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'currency' => 'PHP',
    'language' => 'English',
    'maintenance_mode' => false,
    'auto_backup' => true,
    'backup_frequency' => 'daily',
    'backup_time' => '02:00',
    'retention_days' => 30,
    'log_retention_days' => 90,
    'session_timeout' => 30, // minutes
    'max_login_attempts' => 5,
    'password_expiry' => 90, // days
    'two_factor_auth' => false,
    'email_notifications' => true,
    'sms_notifications' => false,
];

// Module configuration
$modules = [
    'appointments' => [
        'name' => 'Appointments',
        'description' => 'Schedule and manage patient appointments',
        'icon' => '📅',
        'enabled' => true,
        'dependencies' => []
    ],
    'patient_records' => [
        'name' => 'Patient Records',
        'description' => 'Manage patient demographics and medical history',
        'icon' => '📋',
        'enabled' => true,
        'dependencies' => []
    ],
    'clinic_visits' => [
        'name' => 'Clinic Visits',
        'description' => 'Record daily clinic visits and consultations',
        'icon' => '🏥',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'inventory' => [
        'name' => 'Inventory Management',
        'description' => 'Manage medicine and supply inventory',
        'icon' => '📦',
        'enabled' => true,
        'dependencies' => []
    ],
    'medicine_requests' => [
        'name' => 'Medicine Requests',
        'description' => 'Handle medicine and supply requests',
        'icon' => '📝',
        'enabled' => true,
        'dependencies' => ['inventory']
    ],
    'incidents' => [
        'name' => 'Incidents & Emergencies',
        'description' => 'Track incidents and emergency cases',
        'icon' => '⚠️',
        'enabled' => true,
        'dependencies' => []
    ],
    'health_clearance' => [
        'name' => 'Health Clearance',
        'description' => 'Process health clearance requests',
        'icon' => '✅',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'physical_exams' => [
        'name' => 'Physical Examinations',
        'description' => 'Record physical examination results',
        'icon' => '🩺',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'medical_certificates' => [
        'name' => 'Medical Certificates',
        'description' => 'Issue and manage medical certificates',
        'icon' => '📄',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'vaccination' => [
        'name' => 'Vaccination Records',
        'description' => 'Track student vaccinations',
        'icon' => '💉',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'deworming' => [
        'name' => 'Deworming',
        'description' => 'Manage deworming activities',
        'icon' => '🪱',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'health_screening' => [
        'name' => 'Health Screening',
        'description' => 'Conduct health screenings',
        'icon' => '🔍',
        'enabled' => true,
        'dependencies' => ['patient_records']
    ],
    'reports' => [
        'name' => 'Reports',
        'description' => 'Generate system reports',
        'icon' => '📊',
        'enabled' => true,
        'dependencies' => []
    ],
    'user_management' => [
        'name' => 'User Management',
        'description' => 'Manage system users and roles',
        'icon' => '👥',
        'enabled' => true,
        'dependencies' => []
    ],
    'audit_logs' => [
        'name' => 'Audit Logs',
        'description' => 'View system audit trail',
        'icon' => '📜',
        'enabled' => true,
        'dependencies' => []
    ],
    'api_access' => [
        'name' => 'API Access',
        'description' => 'REST API for integrations',
        'icon' => '🔌',
        'enabled' => false,
        'dependencies' => []
    ],
    'mobile_app' => [
        'name' => 'Mobile App Support',
        'description' => 'Enable mobile app features',
        'icon' => '📱',
        'enabled' => false,
        'dependencies' => ['api_access']
    ],
    'telemedicine' => [
        'name' => 'Telemedicine',
        'description' => 'Online consultations',
        'icon' => '📹',
        'enabled' => false,
        'dependencies' => ['api_access']
    ]
];

// Handle configuration save
if (isset($_POST['save_config'])) {
    $config['clinic_name'] = $_POST['clinic_name'];
    $config['clinic_address'] = $_POST['clinic_address'];
    $config['clinic_phone'] = $_POST['clinic_phone'];
    $config['clinic_email'] = $_POST['clinic_email'];
    $config['school_year'] = $_POST['school_year'];
    $config['semester'] = $_POST['semester'];
    $config['academic_start'] = $_POST['academic_start'];
    $config['academic_end'] = $_POST['academic_end'];
    $config['timezone'] = $_POST['timezone'];
    $config['date_format'] = $_POST['date_format'];
    $config['time_format'] = $_POST['time_format'];
    $config['language'] = $_POST['language'];
    $config['maintenance_mode'] = isset($_POST['maintenance_mode']);
    $config['auto_backup'] = isset($_POST['auto_backup']);
    $config['backup_frequency'] = $_POST['backup_frequency'];
    $config['backup_time'] = $_POST['backup_time'];
    $config['retention_days'] = (int)$_POST['retention_days'];
    $config['log_retention_days'] = (int)$_POST['log_retention_days'];
    $config['session_timeout'] = (int)$_POST['session_timeout'];
    $config['max_login_attempts'] = (int)$_POST['max_login_attempts'];
    $config['password_expiry'] = (int)$_POST['password_expiry'];
    $config['two_factor_auth'] = isset($_POST['two_factor_auth']);
    $config['email_notifications'] = isset($_POST['email_notifications']);
    $config['sms_notifications'] = isset($_POST['sms_notifications']);
    
    $message = "System configuration saved successfully!";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'config_update', "Updated system configuration");
}

// Handle module toggle
if (isset($_POST['toggle_modules'])) {
    foreach ($modules as $key => $module) {
        $modules[$key]['enabled'] = isset($_POST['module_' . $key]);
    }
    
    $message = "Module settings updated successfully!";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'modules_update', "Updated module configurations");
}

// Handle backup
if (isset($_POST['create_backup'])) {
    $backup_type = $_POST['backup_type'];
    $backup_note = $_POST['backup_note'];
    
    // Simulate backup creation
    $backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $backup_size = rand(10, 100) . ' MB';
    
    $message = "Backup created successfully: $backup_file ($backup_size)";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'backup_create', "Created $backup_type backup: $backup_note");
}

// Handle restore
if (isset($_POST['restore_backup'])) {
    $backup_file = $_POST['backup_file'];
    
    $message = "System restored from backup: $backup_file";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'backup_restore', "Restored system from backup: $backup_file");
}

// Handle archive
if (isset($_POST['archive_data'])) {
    $archive_table = $_POST['archive_table'];
    $archive_date = $_POST['archive_date'];
    
    $message = "Data archived successfully for $archive_table older than $archive_date";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'data_archive', "Archived $archive_table data older than $archive_date");
}

// Helper function to log audit
function logAudit($db, $user_id, $action, $notes) {
    $username_query = "SELECT username FROM users WHERE id = :id";
    $username_stmt = $db->prepare($username_query);
    $username_stmt->bindParam(':id', $user_id);
    $username_stmt->execute();
    $user = $username_stmt->fetch(PDO::FETCH_ASSOC);
    
    $query = "INSERT INTO request_audit_log (request_id, action, user, quantity, notes, created_at) 
              VALUES (0, :action, :user, 0, :notes, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':user', $user['username']);
    $stmt->bindParam(':notes', $notes);
    $stmt->execute();
}

// Get system statistics
$stats = [];

// Database size
$stats['db_size'] = '156 MB'; // Placeholder - calculate in production

// Total records
$tables = [
    'users' => 'users',
    'patients' => 'patients',
    'incidents' => 'incidents',
    'visits' => 'visit_history',
    'medicine_requests' => 'medicine_requests',
    'clearance_requests' => 'clearance_requests',
    'clinic_stock' => 'clinic_stock'
];

foreach ($tables as $key => $table) {
    $query = "SELECT COUNT(*) as count FROM $table";
    $stmt = $db->query($query);
    $stats[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

// Last backup
$stats['last_backup'] = '2026-02-28 03:00 AM';
$stats['backup_status'] = 'Successful';

// Available backups
$backups = [
    [
        'file' => 'backup_2026-02-28_030000.sql',
        'size' => '156 MB',
        'date' => '2026-02-28 03:00:00',
        'type' => 'Full'
    ],
    [
        'file' => 'backup_2026-02-27_030000.sql',
        'size' => '152 MB',
        'date' => '2026-02-27 03:00:00',
        'type' => 'Full'
    ],
    [
        'file' => 'backup_2026-02-26_030000.sql',
        'size' => '148 MB',
        'date' => '2026-02-26 03:00:00',
        'type' => 'Full'
    ],
    [
        'file' => 'backup_2026-02-25_030000.sql',
        'size' => '145 MB',
        'date' => '2026-02-25 03:00:00',
        'type' => 'Incremental'
    ],
    [
        'file' => 'backup_2026-02-24_030000.sql',
        'size' => '140 MB',
        'date' => '2026-02-24 03:00:00',
        'type' => 'Incremental'
    ]
];

// Archive candidates
$archive_candidates = [
    [
        'table' => 'visit_history',
        'records' => 1250,
        'oldest' => '2025-01-15',
        'newest' => '2026-02-28',
        'size' => '45 MB'
    ],
    [
        'table' => 'incidents',
        'records' => 350,
        'oldest' => '2025-02-10',
        'newest' => '2026-02-28',
        'size' => '12 MB'
    ],
    [
        'table' => 'medicine_requests',
        'records' => 890,
        'oldest' => '2025-03-05',
        'newest' => '2026-02-28',
        'size' => '8 MB'
    ],
    [
        'table' => 'dispensing_log',
        'records' => 567,
        'oldest' => '2025-04-20',
        'newest' => '2026-02-28',
        'size' => '5 MB'
    ],
    [
        'table' => 'clearance_requests',
        'records' => 234,
        'oldest' => '2025-05-12',
        'newest' => '2026-02-28',
        'size' => '3 MB'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configuration - Super Admin | MedFlow Clinic Management System</title>
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

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 30px;
        animation: fadeInUp 0.5s ease;
    }

    .page-header h1 {
        font-size: 2.2rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }

    .page-header p {
        color: #546e7a;
        font-size: 1rem;
        font-weight: 400;
    }

    /* Alert Messages */
    .alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
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
        font-size: 1.5rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 4px;
    }

    .stat-info p {
        color: #546e7a;
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Tab Navigation */
    .config-tabs {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        border-bottom: 2px solid #cfd8dc;
        padding-bottom: 12px;
    }

    .tab-btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        background: transparent;
        color: #546e7a;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tab-btn:hover {
        color: #191970;
        background: #eceff1;
    }

    .tab-btn.active {
        background: #191970;
        color: white;
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.5s ease;
    }

    .tab-content.active {
        display: block;
    }

    /* Config Cards */
    .config-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 24px;
        margin-bottom: 30px;
    }

    .config-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #191970;
    }

    .card-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 8px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 10px 0;
    }

    .checkbox-group input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: #191970;
    }

    /* Module Grid */
    .module-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }

    .module-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid #cfd8dc;
        transition: all 0.3s ease;
    }

    .module-card:hover {
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .module-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .module-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
    }

    .module-icon {
        font-size: 1.5rem;
    }

    .module-toggle {
        width: 40px;
        height: 20px;
        background: #cfd8dc;
        border-radius: 20px;
        position: relative;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .module-toggle.active {
        background: #191970;
    }

    .module-toggle::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        background: white;
        border-radius: 50%;
        top: 2px;
        left: 2px;
        transition: all 0.3s ease;
    }

    .module-toggle.active::after {
        left: 22px;
    }

    .module-description {
        font-size: 0.85rem;
        color: #546e7a;
        margin-bottom: 8px;
    }

    .module-deps {
        font-size: 0.75rem;
        color: #78909c;
        padding-top: 8px;
        border-top: 1px dashed #cfd8dc;
    }

    /* Backup List */
    .backup-list {
        margin-top: 20px;
    }

    .backup-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px;
        background: #f8fafc;
        border-radius: 10px;
        margin-bottom: 8px;
        transition: all 0.3s ease;
    }

    .backup-item:hover {
        background: #eceff1;
    }

    .backup-info {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .backup-icon {
        width: 40px;
        height: 40px;
        background: #191970;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
    }

    .backup-details h4 {
        font-size: 0.95rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 4px;
    }

    .backup-details p {
        font-size: 0.8rem;
        color: #78909c;
    }

    .backup-actions {
        display: flex;
        gap: 8px;
    }

    /* Archive Table */
    .archive-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .archive-table th {
        text-align: left;
        padding: 12px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #78909c;
        text-transform: uppercase;
        border-bottom: 2px solid #cfd8dc;
        background: #f8fafc;
    }

    .archive-table td {
        padding: 12px;
        font-size: 0.9rem;
        color: #37474f;
        border-bottom: 1px solid #eceff1;
    }

    /* Action Buttons */
    .btn {
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: #191970;
        color: white;
    }

    .btn-primary:hover {
        background: #24248f;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.2);
    }

    .btn-success {
        background: #2e7d32;
        color: white;
    }

    .btn-success:hover {
        background: #3a8e3f;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(46, 125, 50, 0.2);
    }

    .btn-warning {
        background: #ff9800;
        color: white;
    }

    .btn-warning:hover {
        background: #f57c00;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(255, 152, 0, 0.2);
    }

    .btn-danger {
        background: #c62828;
        color: white;
    }

    .btn-danger:hover {
        background: #b71c1c;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(198, 40, 40, 0.2);
    }

    .btn-secondary {
        background: #eceff1;
        color: #191970;
        border: 1px solid #cfd8dc;
    }

    .btn-secondary:hover {
        background: #cfd8dc;
    }

    .btn-small {
        padding: 8px 16px;
        font-size: 0.8rem;
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
        z-index: 2000;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: white;
        border-radius: 20px;
        padding: 30px;
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        animation: slideUp 0.3s ease;
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .modal-header h3 {
        font-size: 1.3rem;
        font-weight: 600;
        color: #191970;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #546e7a;
    }

    .modal-body {
        margin-bottom: 20px;
    }

    .modal-footer {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    /* Progress Bar */
    .progress-bar {
        width: 100%;
        height: 8px;
        background: #eceff1;
        border-radius: 4px;
        margin: 10px 0;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: #191970;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-active {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .status-inactive {
        background: #ffebee;
        color: #c62828;
    }

    .status-warning {
        background: #fff3cd;
        color: #ff9800;
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

    @keyframes fadeIn {
        from { background: rgba(0, 0, 0, 0); }
        to { background: rgba(0, 0, 0, 0.5); }
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

    @media (max-width: 1280px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .config-grid {
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
        
        .config-tabs {
            flex-direction: column;
        }
        
        .tab-btn {
            width: 100%;
            justify-content: center;
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
                <div class="page-header">
                    <div>
                        <h1>System Configuration</h1>
                        <p>Configure system settings, modules, backup, and archive options</p>
                    </div>
                </div>

                <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                        <?php if ($message_type === 'success'): ?>
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M8 12L11 15L16 9"/>
                        <?php else: ?>
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 8V12L12 16"/>
                        <?php endif; ?>
                    </svg>
                    <?php echo $message; ?>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="3" y1="9" x2="21" y2="9"/>
                                <line x1="9" y1="21" x2="9" y2="9"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['db_size']; ?></h3>
                            <p>Database Size</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['last_backup']; ?></h3>
                            <p>Last Backup</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 11.5V19C21 20.1 20.1 21 19 21H5C3.9 21 3 20.1 3 19V5C3 3.9 3.9 3 5 3H12.5"/>
                                <polyline points="16 2 22 8 16 8"/>
                                <line x1="10" y1="14" x2="21" y2="14"/>
                                <line x1="10" y1="18" x2="18" y2="18"/>
                                <line x1="3" y1="10" x2="8" y2="10"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($backups); ?></h3>
                            <p>Available Backups</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2"/>
                                <line x1="8" y1="10" x2="16" y2="10"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($archive_candidates); ?></h3>
                            <p>Archive Candidates</p>
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="config-tabs">
                    <button class="tab-btn active" onclick="showTab('general')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                        </svg>
                        General Settings
                    </button>
                    <button class="tab-btn" onclick="showTab('modules')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="8" height="8" rx="2"/>
                            <rect x="13" y="3" width="8" height="8" rx="2"/>
                            <rect x="3" y="13" width="8" height="8" rx="2"/>
                            <rect x="13" y="13" width="8" height="8" rx="2"/>
                        </svg>
                        Module Management
                    </button>
                    <button class="tab-btn" onclick="showTab('backup')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                            <path d="M16 21V15H8V21"/>
                            <path d="M8 3V8H15"/>
                        </svg>
                        Backup & Restore
                    </button>
                    <button class="tab-btn" onclick="showTab('archive')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                            <line x1="8" y1="14" x2="16" y2="14"/>
                            <line x1="12" y1="14" x2="12" y2="18"/>
                        </svg>
                        Data Archive
                    </button>
                    <button class="tab-btn" onclick="showTab('security')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                        </svg>
                        Security Settings
                    </button>
                </div>

                <!-- General Settings Tab -->
                <div id="tab-general" class="tab-content active">
                    <form method="POST" action="">
                        <div class="config-grid">
                            <div class="config-card">
                                <div class="card-header">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    </svg>
                                    <h2>Clinic Information</h2>
                                </div>
                                
                                <div class="form-group">
                                    <label for="clinic_name">Clinic Name</label>
                                    <input type="text" id="clinic_name" name="clinic_name" value="<?php echo $config['clinic_name']; ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="clinic_address">Clinic Address</label>
                                    <textarea id="clinic_address" name="clinic_address" rows="2"><?php echo $config['clinic_address']; ?></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="clinic_phone">Phone Number</label>
                                        <input type="text" id="clinic_phone" name="clinic_phone" value="<?php echo $config['clinic_phone']; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="clinic_email">Email Address</label>
                                        <input type="email" id="clinic_email" name="clinic_email" value="<?php echo $config['clinic_email']; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="config-card">
                                <div class="card-header">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <h2>Academic Year</h2>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="school_year">School Year</label>
                                        <select id="school_year" name="school_year">
                                            <option value="2024-2025" <?php echo $config['school_year'] == '2024-2025' ? 'selected' : ''; ?>>2024-2025</option>
                                            <option value="2025-2026" <?php echo $config['school_year'] == '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                                            <option value="2026-2027" <?php echo $config['school_year'] == '2026-2027' ? 'selected' : ''; ?>>2026-2027</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="semester">Semester</label>
                                        <select id="semester" name="semester">
                                            <option value="1st Semester" <?php echo $config['semester'] == '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                                            <option value="2nd Semester" <?php echo $config['semester'] == '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                                            <option value="Summer" <?php echo $config['semester'] == 'Summer' ? 'selected' : ''; ?>>Summer</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="academic_start">Start Date</label>
                                        <input type="date" id="academic_start" name="academic_start" value="<?php echo $config['academic_start']; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="academic_end">End Date</label>
                                        <input type="date" id="academic_end" name="academic_end" value="<?php echo $config['academic_end']; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="config-card">
                                <div class="card-header">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6V12L16 14"/>
                                    </svg>
                                    <h2>Regional Settings</h2>
                                </div>
                                
                                <div class="form-group">
                                    <label for="timezone">Timezone</label>
                                    <select id="timezone" name="timezone">
                                        <option value="Asia/Manila" <?php echo $config['timezone'] == 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila (UTC+8)</option>
                                        <option value="Asia/Singapore" <?php echo $config['timezone'] == 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore (UTC+8)</option>
                                        <option value="Asia/Tokyo" <?php echo $config['timezone'] == 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (UTC+9)</option>
                                    </select>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="date_format">Date Format</label>
                                        <select id="date_format" name="date_format">
                                            <option value="Y-m-d" <?php echo $config['date_format'] == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="m/d/Y" <?php echo $config['date_format'] == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="d/m/Y" <?php echo $config['date_format'] == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="time_format">Time Format</label>
                                        <select id="time_format" name="time_format">
                                            <option value="H:i:s" <?php echo $config['time_format'] == 'H:i:s' ? 'selected' : ''; ?>>24 Hour (14:30:00)</option>
                                            <option value="h:i:s A" <?php echo $config['time_format'] == 'h:i:s A' ? 'selected' : ''; ?>>12 Hour (02:30:00 PM)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="language">Default Language</label>
                                    <select id="language" name="language">
                                        <option value="English" <?php echo $config['language'] == 'English' ? 'selected' : ''; ?>>English</option>
                                        <option value="Filipino" <?php echo $config['language'] == 'Filipino' ? 'selected' : ''; ?>>Filipino</option>
                                        <option value="Cebuano" <?php echo $config['language'] == 'Cebuano' ? 'selected' : ''; ?>>Cebuano</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                            <button type="submit" name="save_config" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M17 21V15H7V21"/>
                                    <path d="M7 3V8H15"/>
                                </svg>
                                Save General Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Module Management Tab -->
                <div id="tab-modules" class="tab-content">
                    <form method="POST" action="">
                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <rect x="3" y="3" width="8" height="8" rx="2"/>
                                    <rect x="13" y="3" width="8" height="8" rx="2"/>
                                    <rect x="3" y="13" width="8" height="8" rx="2"/>
                                    <rect x="13" y="13" width="8" height="8" rx="2"/>
                                </svg>
                                <h2>System Modules</h2>
                            </div>
                            
                            <p style="color: #546e7a; margin-bottom: 20px;">Enable or disable system modules. Disabling a module will hide it from the user interface.</p>
                            
                            <div class="module-grid">
                                <?php foreach ($modules as $key => $module): ?>
                                <div class="module-card">
                                    <div class="module-header">
                                        <div class="module-title">
                                            <span class="module-icon"><?php echo $module['icon']; ?></span>
                                            <?php echo $module['name']; ?>
                                        </div>
                                        <label class="module-toggle <?php echo $module['enabled'] ? 'active' : ''; ?>">
                                            <input type="checkbox" name="module_<?php echo $key; ?>" style="display: none;" <?php echo $module['enabled'] ? 'checked' : ''; ?> onchange="this.parentElement.classList.toggle('active')">
                                        </label>
                                    </div>
                                    <div class="module-description">
                                        <?php echo $module['description']; ?>
                                    </div>
                                    <?php if (!empty($module['dependencies'])): ?>
                                    <div class="module-deps">
                                        <strong>Depends on:</strong> <?php echo implode(', ', array_map(function($dep) use ($modules) { return $modules[$dep]['name']; }, $module['dependencies'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                                <button type="submit" name="toggle_modules" class="btn btn-primary">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                        <path d="M17 21V15H7V21"/>
                                        <path d="M7 3V8H15"/>
                                    </svg>
                                    Save Module Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Backup & Restore Tab -->
                <div id="tab-backup" class="tab-content">
                    <div class="config-grid">
                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M16 21V15H8V21"/>
                                    <path d="M8 3V8H15"/>
                                </svg>
                                <h2>Create Backup</h2>
                            </div>
                            
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label for="backup_type">Backup Type</label>
                                    <select id="backup_type" name="backup_type">
                                        <option value="full">Full Backup (All data)</option>
                                        <option value="incremental">Incremental Backup (Changes only)</option>
                                        <option value="structure">Structure Only (No data)</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="backup_note">Backup Note (Optional)</label>
                                    <input type="text" id="backup_note" name="backup_note" placeholder="e.g., Before semester start">
                                </div>
                                
                                <button type="submit" name="create_backup" class="btn btn-success" style="width: 100%;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                        <path d="M16 21V15H8V21"/>
                                        <path d="M8 3V8H15"/>
                                    </svg>
                                    Create Backup Now
                                </button>
                            </form>
                        </div>

                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                                    <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                                    <path d="M15 13L19 9L22 13"/>
                                </svg>
                                <h2>Auto-Backup Settings</h2>
                            </div>
                            
                            <form method="POST" action="">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="auto_backup" name="auto_backup" <?php echo $config['auto_backup'] ? 'checked' : ''; ?>>
                                    <label for="auto_backup">Enable automatic backups</label>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="backup_frequency">Frequency</label>
                                        <select id="backup_frequency" name="backup_frequency">
                                            <option value="hourly" <?php echo $config['backup_frequency'] == 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                            <option value="daily" <?php echo $config['backup_frequency'] == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                            <option value="weekly" <?php echo $config['backup_frequency'] == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                            <option value="monthly" <?php echo $config['backup_frequency'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="backup_time">Time</label>
                                        <input type="time" id="backup_time" name="backup_time" value="<?php echo $config['backup_time']; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="retention_days">Keep backups for (days)</label>
                                    <input type="number" id="retention_days" name="retention_days" value="<?php echo $config['retention_days']; ?>" min="1" max="365">
                                </div>
                                
                                <button type="submit" name="save_config" class="btn btn-primary" style="width: 100%;">
                                    Save Backup Settings
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="config-card">
                        <div class="card-header">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                <line x1="8" y1="9" x2="16" y2="9"/>
                                <line x1="8" y1="13" x2="16" y2="13"/>
                                <line x1="8" y1="17" x2="12" y2="17"/>
                            </svg>
                            <h2>Available Backups</h2>
                        </div>
                        
                        <div class="backup-list">
                            <?php foreach ($backups as $backup): ?>
                            <div class="backup-item">
                                <div class="backup-info">
                                    <div class="backup-icon">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="white" stroke-width="2">
                                            <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                            <line x1="8" y1="9" x2="16" y2="9"/>
                                            <line x1="8" y1="13" x2="16" y2="13"/>
                                            <line x1="8" y1="17" x2="12" y2="17"/>
                                        </svg>
                                    </div>
                                    <div class="backup-details">
                                        <h4><?php echo $backup['file']; ?></h4>
                                        <p><?php echo $backup['date']; ?> • <?php echo $backup['size']; ?> • <?php echo $backup['type']; ?></p>
                                    </div>
                                </div>
                                <div class="backup-actions">
                                    <button class="btn btn-small btn-secondary" onclick="downloadBackup('<?php echo $backup['file']; ?>')">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15"/>
                                            <path d="M7 10L12 15L17 10"/>
                                            <path d="M12 15V3"/>
                                        </svg>
                                    </button>
                                    <button class="btn btn-small btn-warning" onclick="restoreBackup('<?php echo $backup['file']; ?>')">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                                            <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                                            <path d="M15 13L19 9L22 13"/>
                                        </svg>
                                    </button>
                                    <button class="btn btn-small btn-danger" onclick="deleteBackup('<?php echo $backup['file']; ?>')">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M3 6H21"/>
                                            <path d="M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6"/>
                                            <path d="M8 4V4C8 2.89543 8.89543 2 10 2H14C15.1046 2 16 2.89543 16 4V4"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Data Archive Tab -->
                <div id="tab-archive" class="tab-content">
                    <div class="config-card">
                        <div class="card-header">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2"/>
                                <line x1="2" y1="10" x2="22" y2="10"/>
                                <line x1="8" y1="14" x2="16" y2="14"/>
                                <line x1="12" y1="14" x2="12" y2="18"/>
                            </svg>
                            <h2>Archive Old Data</h2>
                        </div>
                        
                        <p style="color: #546e7a; margin-bottom: 20px;">Archive data older than the specified date to improve system performance. Archived data will be moved to separate tables and can be restored if needed.</p>
                        
                        <table class="archive-table">
                            <thead>
                                <tr>
                                    <th>Table</th>
                                    <th>Records</th>
                                    <th>Oldest</th>
                                    <th>Newest</th>
                                    <th>Size</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archive_candidates as $candidate): ?>
                                <tr>
                                    <td><strong><?php echo $candidate['table']; ?></strong></td>
                                    <td><?php echo number_format($candidate['records']); ?></td>
                                    <td><?php echo $candidate['oldest']; ?></td>
                                    <td><?php echo $candidate['newest']; ?></td>
                                    <td><?php echo $candidate['size']; ?></td>
                                    <td>
                                        <button class="btn btn-small btn-warning" onclick="archiveTable('<?php echo $candidate['table']; ?>')">
                                            Archive
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div style="margin-top: 20px; padding: 20px; background: #f8fafc; border-radius: 12px;">
                            <h3 style="font-size: 1rem; color: #191970; margin-bottom: 15px;">Bulk Archive</h3>
                            <form method="POST" action="">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="archive_table">Table to Archive</label>
                                        <select id="archive_table" name="archive_table">
                                            <option value="visit_history">Visit History</option>
                                            <option value="incidents">Incidents</option>
                                            <option value="medicine_requests">Medicine Requests</option>
                                            <option value="dispensing_log">Dispensing Log</option>
                                            <option value="clearance_requests">Clearance Requests</option>
                                            <option value="all">All Tables</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="archive_date">Archive Before</label>
                                        <input type="date" id="archive_date" name="archive_date" value="<?php echo date('Y-m-d', strtotime('-1 year')); ?>">
                                    </div>
                                </div>
                                <button type="submit" name="archive_data" class="btn btn-warning">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="2" y="4" width="20" height="16" rx="2"/>
                                        <line x1="2" y1="10" x2="22" y2="10"/>
                                        <line x1="8" y1="14" x2="16" y2="14"/>
                                        <line x1="12" y1="14" x2="12" y2="18"/>
                                    </svg>
                                    Archive Selected Data
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Settings Tab -->
                <div id="tab-security" class="tab-content">
                    <form method="POST" action="">
                        <div class="config-grid">
                            <div class="config-card">
                                <div class="card-header">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                    </svg>
                                    <h2>Security Settings</h2>
                                </div>
                                
                                <div class="form-group">
                                    <label for="session_timeout">Session Timeout (minutes)</label>
                                    <input type="number" id="session_timeout" name="session_timeout" value="<?php echo $config['session_timeout']; ?>" min="5" max="480">
                                </div>
                                
                                <div class="form-group">
                                    <label for="max_login_attempts">Max Login Attempts</label>
                                    <input type="number" id="max_login_attempts" name="max_login_attempts" value="<?php echo $config['max_login_attempts']; ?>" min="3" max="10">
                                </div>
                                
                                <div class="form-group">
                                    <label for="password_expiry">Password Expiry (days)</label>
                                    <input type="number" id="password_expiry" name="password_expiry" value="<?php echo $config['password_expiry']; ?>" min="0" max="365">
                                    <small style="color: #78909c;">Set to 0 to disable password expiry</small>
                                </div>
                                
                                <div class="checkbox-group">
                                    <input type="checkbox" id="two_factor_auth" name="two_factor_auth" <?php echo $config['two_factor_auth'] ? 'checked' : ''; ?>>
                                    <label for="two_factor_auth">Enable Two-Factor Authentication</label>
                                </div>
                            </div>

                            <div class="config-card">
                                <div class="card-header">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                        <path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z"/>
                                        <path d="M22 6L12 13L2 6"/>
                                    </svg>
                                    <h2>Notification Settings</h2>
                                </div>
                                
                                <div class="checkbox-group">
                                    <input type="checkbox" id="email_notifications" name="email_notifications" <?php echo $config['email_notifications'] ? 'checked' : ''; ?>>
                                    <label for="email_notifications">Enable Email Notifications</label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <input type="checkbox" id="sms_notifications" name="sms_notifications" <?php echo $config['sms_notifications'] ? 'checked' : ''; ?>>
                                    <label for="sms_notifications">Enable SMS Notifications</label>
                                </div>
                                
                                <div class="form-group">
                                    <label for="log_retention_days">Audit Log Retention (days)</label>
                                    <input type="number" id="log_retention_days" name="log_retention_days" value="<?php echo $config['log_retention_days']; ?>" min="30" max="365">
                                </div>
                                
                                <div class="checkbox-group">
                                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" <?php echo $config['maintenance_mode'] ? 'checked' : ''; ?>>
                                    <label for="maintenance_mode">Maintenance Mode</label>
                                </div>
                                <small style="color: #78909c;">When enabled, only super admins can access the system</small>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                            <button type="submit" name="save_config" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M17 21V15H7V21"/>
                                    <path d="M7 3V8H15"/>
                                </svg>
                                Save Security Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Restore Backup</h3>
                <button class="modal-close" onclick="closeModal('restoreModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Are you sure you want to restore from backup: <strong id="restoreFileName"></strong>?</p>
                    <p style="color: #c62828; font-size: 0.9rem; margin-top: 10px;">
                        Warning: This will overwrite current data. Current data will be backed up automatically.
                    </p>
                    <input type="hidden" name="backup_file" id="restoreFileInput">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
                    <button type="submit" name="restore_backup" class="btn btn-warning">Restore</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Backup Modal -->
    <div id="deleteBackupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Backup</h3>
                <button class="modal-close" onclick="closeModal('deleteBackupModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Are you sure you want to delete backup: <strong id="deleteFileName"></strong>?</p>
                    <p style="color: #c62828; font-size: 0.9rem; margin-top: 10px;">
                        This action cannot be undone.
                    </p>
                    <input type="hidden" name="backup_file" id="deleteFileInput">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteBackupModal')">Cancel</button>
                    <button type="submit" name="delete_backup" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div id="archiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Archive Table</h3>
                <button class="modal-close" onclick="closeModal('archiveModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Archive data from table: <strong id="archiveTableName"></strong>?</p>
                    <p>This will move older records to an archive table.</p>
                    <input type="hidden" name="archive_table" id="archiveTableInput">
                    <input type="hidden" name="archive_date" value="<?php echo date('Y-m-d', strtotime('-1 year')); ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('archiveModal')">Cancel</button>
                    <button type="submit" name="archive_data" class="btn btn-warning">Archive</button>
                </div>
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

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Modal functions
        function restoreBackup(fileName) {
            document.getElementById('restoreFileName').textContent = fileName;
            document.getElementById('restoreFileInput').value = fileName;
            document.getElementById('restoreModal').classList.add('show');
        }

        function deleteBackup(fileName) {
            document.getElementById('deleteFileName').textContent = fileName;
            document.getElementById('deleteFileInput').value = fileName;
            document.getElementById('deleteBackupModal').classList.add('show');
        }

        function downloadBackup(fileName) {
            alert('Downloading: ' + fileName + '\n(In production, this would download the backup file)');
        }

        function archiveTable(tableName) {
            document.getElementById('archiveTableName').textContent = tableName;
            document.getElementById('archiveTableInput').value = tableName;
            document.getElementById('archiveModal').classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'System Configuration';
        }

        // Module toggle handling
        document.querySelectorAll('.module-toggle input').forEach(toggle => {
            toggle.addEventListener('change', function() {
                if (this.checked) {
                    // Check dependencies
                    const moduleCard = this.closest('.module-card');
                    const deps = moduleCard.querySelector('.module-deps');
                    if (deps) {
                        // In production, you'd check if dependencies are enabled
                        console.log('Checking dependencies...');
                    }
                }
            });
        });
    </script>
</body>
</html>