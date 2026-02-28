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

// Define available permissions
$permissions = [
    'dashboard' => [
        'name' => 'Dashboard Access',
        'description' => 'View main dashboard',
        'modules' => [
            'view_dashboard' => 'View Dashboard',
            'view_analytics' => 'View Analytics',
            'export_dashboard_data' => 'Export Dashboard Data'
        ]
    ],
    'patient_records' => [
        'name' => 'Patient Records',
        'description' => 'Manage patient/student medical records',
        'modules' => [
            'view_patients' => 'View Patients',
            'add_patient' => 'Add New Patient',
            'edit_patient' => 'Edit Patient Details',
            'delete_patient' => 'Delete Patient',
            'view_medical_history' => 'View Medical History',
            'export_patient_data' => 'Export Patient Data'
        ]
    ],
    'clinic_visits' => [
        'name' => 'Clinic Visits',
        'description' => 'Manage clinic visits and consultations',
        'modules' => [
            'view_visits' => 'View Visits',
            'add_visit' => 'Record New Visit',
            'edit_visit' => 'Edit Visit Records',
            'delete_visit' => 'Delete Visit Records',
            'view_vital_signs' => 'View/Record Vital Signs',
            'export_visits' => 'Export Visit Data'
        ]
    ],
    'inventory' => [
        'name' => 'Medicine & Supplies',
        'description' => 'Manage clinic inventory',
        'modules' => [
            'view_inventory' => 'View Inventory',
            'add_inventory' => 'Add Items',
            'edit_inventory' => 'Edit Items',
            'delete_inventory' => 'Delete Items',
            'adjust_stock' => 'Adjust Stock Levels',
            'view_low_stock' => 'View Low Stock Alerts',
            'view_expiring' => 'View Expiring Items',
            'export_inventory' => 'Export Inventory'
        ]
    ],
    'medicine_requests' => [
        'name' => 'Medicine Requests',
        'description' => 'Handle medicine and supply requests',
        'modules' => [
            'view_requests' => 'View Requests',
            'create_request' => 'Create Requests',
            'approve_request' => 'Approve Requests',
            'release_request' => 'Release Items',
            'reject_request' => 'Reject Requests',
            'export_requests' => 'Export Request Data'
        ]
    ],
    'incidents' => [
        'name' => 'Incidents & Emergencies',
        'description' => 'Manage incidents and emergency cases',
        'modules' => [
            'view_incidents' => 'View Incidents',
            'report_incident' => 'Report Incident',
            'edit_incident' => 'Edit Incident Reports',
            'delete_incident' => 'Delete Incidents',
            'manage_emergency' => 'Handle Emergencies',
            'export_incidents' => 'Export Incident Data'
        ]
    ],
    'health_clearance' => [
        'name' => 'Health Clearance',
        'description' => 'Manage health clearance requests',
        'modules' => [
            'view_clearance' => 'View Clearance Requests',
            'process_clearance' => 'Process Clearance',
            'approve_clearance' => 'Approve Clearance',
            'reject_clearance' => 'Reject Clearance',
            'print_certificate' => 'Print Certificates',
            'export_clearance' => 'Export Clearance Data'
        ]
    ],
    'physical_exams' => [
        'name' => 'Physical Examinations',
        'description' => 'Manage physical exam records',
        'modules' => [
            'view_exams' => 'View Exams',
            'add_exam' => 'Add Exam Records',
            'edit_exam' => 'Edit Exam Records',
            'delete_exam' => 'Delete Exam Records',
            'export_exams' => 'Export Exam Data'
        ]
    ],
    'medical_certificates' => [
        'name' => 'Medical Certificates',
        'description' => 'Issue and manage medical certificates',
        'modules' => [
            'view_certificates' => 'View Certificates',
            'issue_certificate' => 'Issue Certificate',
            'revoke_certificate' => 'Revoke Certificate',
            'print_certificate' => 'Print Certificate',
            'export_certificates' => 'Export Certificate Data'
        ]
    ],
    'vaccination' => [
        'name' => 'Vaccination Records',
        'description' => 'Manage vaccination records',
        'modules' => [
            'view_vaccinations' => 'View Vaccinations',
            'add_vaccination' => 'Add Vaccination Record',
            'edit_vaccination' => 'Edit Vaccination',
            'delete_vaccination' => 'Delete Vaccination',
            'export_vaccinations' => 'Export Vaccination Data'
        ]
    ],
    'deworming' => [
        'name' => 'Deworming Records',
        'description' => 'Manage deworming activities',
        'modules' => [
            'view_deworming' => 'View Deworming',
            'add_deworming' => 'Add Deworming Record',
            'edit_deworming' => 'Edit Deworming',
            'delete_deworming' => 'Delete Deworming',
            'export_deworming' => 'Export Deworming Data'
        ]
    ],
    'health_screening' => [
        'name' => 'Health Screening',
        'description' => 'Manage health screening records',
        'modules' => [
            'view_screening' => 'View Screenings',
            'add_screening' => 'Add Screening',
            'edit_screening' => 'Edit Screening',
            'delete_screening' => 'Delete Screening',
            'export_screening' => 'Export Screening Data'
        ]
    ],
    'reports' => [
        'name' => 'Reports',
        'description' => 'Generate and view reports',
        'modules' => [
            'view_reports' => 'View Reports',
            'generate_reports' => 'Generate Reports',
            'export_reports' => 'Export Reports',
            'print_reports' => 'Print Reports',
            'schedule_reports' => 'Schedule Reports'
        ]
    ],
    'user_management' => [
        'name' => 'User Management',
        'description' => 'Manage system users',
        'modules' => [
            'view_users' => 'View Users',
            'add_user' => 'Add Users',
            'edit_user' => 'Edit Users',
            'delete_user' => 'Delete Users',
            'reset_password' => 'Reset Passwords',
            'assign_roles' => 'Assign Roles'
        ]
    ],
    'system_settings' => [
        'name' => 'System Settings',
        'description' => 'Configure system settings',
        'modules' => [
            'view_settings' => 'View Settings',
            'edit_settings' => 'Edit Settings',
            'backup_data' => 'Backup Data',
            'restore_data' => 'Restore Data',
            'view_logs' => 'View System Logs',
            'clear_logs' => 'Clear Logs'
        ]
    ]
];

// Define default role permissions
$default_permissions = [
    'superadmin' => [
        'name' => 'Super Administrator',
        'color' => 'danger',
        'description' => 'Full system access with all permissions',
        'permissions' => []
    ],
    'admin' => [
        'name' => 'Administrator',
        'color' => 'primary',
        'description' => 'Manage clinic operations and users',
        'permissions' => [
            'view_dashboard', 'view_analytics',
            'view_patients', 'add_patient', 'edit_patient', 'view_medical_history',
            'view_visits', 'add_visit', 'edit_visit', 'view_vital_signs',
            'view_inventory', 'add_inventory', 'edit_inventory', 'adjust_stock', 'view_low_stock', 'view_expiring',
            'view_requests', 'create_request', 'approve_request', 'release_request',
            'view_incidents', 'report_incident', 'edit_incident', 'manage_emergency',
            'view_clearance', 'process_clearance', 'approve_clearance', 'print_certificate',
            'view_exams', 'add_exam', 'edit_exam',
            'view_certificates', 'issue_certificate', 'print_certificate',
            'view_vaccinations', 'add_vaccination', 'edit_vaccination',
            'view_deworming', 'add_deworming', 'edit_deworming',
            'view_screening', 'add_screening', 'edit_screening',
            'view_reports', 'generate_reports', 'export_reports', 'print_reports',
            'view_users', 'add_user', 'edit_user', 'reset_password'
        ]
    ],
    'staff' => [
        'name' => 'Nurse / Staff',
        'color' => 'success',
        'description' => 'Daily clinic operations and patient care',
        'permissions' => [
            'view_dashboard',
            'view_patients', 'add_patient', 'edit_patient', 'view_medical_history',
            'view_visits', 'add_visit', 'edit_visit', 'view_vital_signs',
            'view_inventory', 'view_low_stock', 'view_expiring',
            'view_requests', 'create_request',
            'view_incidents', 'report_incident',
            'view_clearance', 'process_clearance',
            'view_exams', 'add_exam',
            'view_certificates',
            'view_vaccinations', 'add_vaccination',
            'view_deworming', 'add_deworming',
            'view_screening', 'add_screening',
            'view_reports'
        ]
    ],
    'doctor' => [
        'name' => 'Doctor',
        'color' => 'warning',
        'description' => 'Medical consultations and diagnoses',
        'permissions' => [
            'view_dashboard', 'view_analytics',
            'view_patients', 'view_medical_history',
            'view_visits', 'add_visit', 'edit_visit', 'view_vital_signs',
            'view_incidents', 'report_incident', 'manage_emergency',
            'view_clearance', 'approve_clearance', 'print_certificate',
            'view_exams', 'add_exam', 'edit_exam',
            'view_certificates', 'issue_certificate', 'print_certificate',
            'view_vaccinations', 'add_vaccination',
            'view_deworming', 'add_deworming',
            'view_screening', 'add_screening',
            'view_reports', 'generate_reports'
        ]
    ],
    'property_custodian' => [
        'name' => 'Property Custodian',
        'color' => 'info',
        'description' => 'Manage inventory and supplies',
        'permissions' => [
            'view_dashboard',
            'view_inventory', 'add_inventory', 'edit_inventory', 'delete_inventory', 'adjust_stock', 'view_low_stock', 'view_expiring', 'export_inventory',
            'view_requests', 'approve_request', 'release_request', 'reject_request',
            'view_reports', 'generate_reports', 'export_reports'
        ]
    ]
];

// Handle permission save
if (isset($_POST['save_permissions'])) {
    $role = $_POST['role'];
    $selected_permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
    
    // In a real implementation, you would save this to a database table
    // For now, we'll store in session as demonstration
    $_SESSION['role_permissions'][$role] = $selected_permissions;
    
    $message = "Permissions updated successfully for " . $default_permissions[$role]['name'];
    $message_type = 'success';
    
    // Log the action
    logAudit($db, $_SESSION['user_id'], 'update_permissions', "Updated permissions for role: $role");
}

// Handle reset to default
if (isset($_POST['reset_default'])) {
    $role = $_POST['role'];
    
    // Clear custom permissions
    unset($_SESSION['role_permissions'][$role]);
    
    $message = "Permissions reset to default for " . $default_permissions[$role]['name'];
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'reset_permissions', "Reset permissions to default for role: $role");
}

// Handle apply to all
if (isset($_POST['apply_to_all'])) {
    $source_role = $_POST['source_role'];
    $target_roles = isset($_POST['target_roles']) ? $_POST['target_roles'] : [];
    
    $source_permissions = isset($_SESSION['role_permissions'][$source_role]) 
        ? $_SESSION['role_permissions'][$source_role] 
        : $default_permissions[$source_role]['permissions'];
    
    foreach ($target_roles as $target_role) {
        $_SESSION['role_permissions'][$target_role] = $source_permissions;
    }
    
    $message = "Permissions copied from " . $default_permissions[$source_role]['name'] . " to selected roles";
    $message_type = 'success';
    
    logAudit($db, $_SESSION['user_id'], 'copy_permissions', "Copied permissions from $source_role to " . implode(', ', $target_roles));
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

// Get current role for editing
$current_role = isset($_GET['role']) ? $_GET['role'] : 'admin';
$current_permissions = isset($_SESSION['role_permissions'][$current_role]) 
    ? $_SESSION['role_permissions'][$current_role] 
    : $default_permissions[$current_role]['permissions'];

// Get statistics
$stats = [];

// Count users by role
$query = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$stmt = $db->query($query);
$role_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $role_counts[$row['role']] = $row['count'];
}

$stats['superadmin_count'] = $role_counts['superadmin'] ?? 1;
$stats['admin_count'] = $role_counts['admin'] ?? 0;
$stats['staff_count'] = $role_counts['staff'] ?? 0;
$stats['doctor_count'] = $role_counts['doctor'] ?? 0;
$stats['property_count'] = 0; // Property Custodian not in users table yet

// Get recent permission changes
$recent_query = "SELECT * FROM request_audit_log 
                 WHERE action LIKE '%permission%' OR action LIKE '%role%'
                 ORDER BY created_at DESC LIMIT 10";
$recent_stmt = $db->query($recent_query);
$recent_activities = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role & Permission Management - Super Admin | MedFlow Clinic Management System</title>
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
        grid-template-columns: repeat(5, 1fr);
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
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
    }

    .stat-icon.superadmin { background: #c62828; }
    .stat-icon.admin { background: #191970; }
    .stat-icon.staff { background: #2e7d32; }
    .stat-icon.doctor { background: #ff9800; }
    .stat-icon.property { background: #0284c7; }

    .stat-info {
        flex: 1;
    }

    .stat-info h3 {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 2px;
    }

    .stat-info p {
        color: #546e7a;
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Role Navigation */
    .role-nav {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        animation: fadeInUp 0.7s ease;
    }

    .role-btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: 2px solid transparent;
        background: white;
        color: #1e293b;
        border: 1px solid #cfd8dc;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .role-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .role-btn.active {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .role-badge {
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        margin-left: 8px;
    }

    .role-badge.superadmin { background: #ffebee; color: #c62828; }
    .role-badge.admin { background: #e8eaf6; color: #191970; }
    .role-badge.staff { background: #e8f5e9; color: #2e7d32; }
    .role-badge.doctor { background: #fff3cd; color: #ff9800; }
    .role-badge.property { background: #e0f2fe; color: #0284c7; }

    /* Role Info Card */
    .role-info-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.8s ease;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
    }

    .role-info-left {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .role-icon-large {
        width: 70px;
        height: 70px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        color: white;
    }

    .role-icon-large.superadmin { background: #c62828; }
    .role-icon-large.admin { background: #191970; }
    .role-icon-large.staff { background: #2e7d32; }
    .role-icon-large.doctor { background: #ff9800; }
    .role-icon-large.property { background: #0284c7; }

    .role-details h2 {
        font-size: 1.8rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 4px;
    }

    .role-details p {
        color: #546e7a;
        font-size: 0.95rem;
    }

    .role-info-right {
        display: flex;
        gap: 12px;
    }

    /* Permission Grid */
    .permissions-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.9s ease;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .section-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        color: #191970;
    }

    .section-actions {
        display: flex;
        gap: 12px;
    }

    .permission-category {
        margin-bottom: 30px;
        border: 1px solid #eceff1;
        border-radius: 16px;
        overflow: hidden;
    }

    .category-header {
        background: #f8fafc;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        border-bottom: 2px solid #191970;
    }

    .category-header h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #191970;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .category-header span {
        font-size: 0.85rem;
        color: #546e7a;
        font-weight: normal;
    }

    .category-content {
        padding: 20px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 16px;
    }

    .permission-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        background: #f8fafc;
        border-radius: 10px;
        transition: all 0.2s ease;
    }

    .permission-item:hover {
        background: #eceff1;
    }

    .permission-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: #191970;
        cursor: pointer;
    }

    .permission-label {
        font-size: 0.9rem;
        color: #1e293b;
        cursor: pointer;
        flex: 1;
    }

    .permission-description {
        font-size: 0.75rem;
        color: #78909c;
        margin-left: 28px;
        margin-top: 2px;
    }

    .select-all {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 20px;
        background: #eceff1;
        border-radius: 10px;
        margin-bottom: 20px;
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

    /* Role Checkboxes */
    .role-checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin: 15px 0;
    }

    .role-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background: #f8fafc;
        border-radius: 10px;
    }

    .role-checkbox input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: #191970;
    }

    /* Recent Activity */
    .activity-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 1s ease;
    }

    .activity-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .activity-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        background: #eceff1;
        border-radius: 12px;
        transition: all 0.3s ease;
    }

    .activity-item:hover {
        background: white;
        border: 1px solid #191970;
        transform: translateX(5px);
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        background: #191970;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }

    .activity-content {
        flex: 1;
    }

    .activity-title {
        font-weight: 600;
        font-size: 0.9rem;
        color: #191970;
        margin-bottom: 4px;
    }

    .activity-time {
        font-size: 0.7rem;
        color: #78909c;
    }

    /* Summary Cards */
    .summary-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .summary-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid #cfd8dc;
    }

    .summary-card h4 {
        font-size: 0.9rem;
        color: #546e7a;
        margin-bottom: 8px;
    }

    .summary-value {
        font-size: 1.5rem;
        font-weight: 700;
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
        
        .role-info-left {
            flex-direction: column;
            text-align: center;
        }
        
        .role-info-right {
            width: 100%;
            justify-content: center;
        }
        
        .category-content {
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
                <div class="page-header">
                    <div>
                        <h1>Role & Permission Management</h1>
                        <p>Define access levels and permissions for different user roles</p>
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
                        <div class="stat-icon superadmin">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21V19C20 16.7909 18.2091 15 16 15H8C5.79086 15 4 16.7909 4 19V21"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['superadmin_count']; ?></h3>
                            <p>Super Admins</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon admin">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21V19C20 16.7909 18.2091 15 16 15H8C5.79086 15 4 16.7909 4 19V21"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['admin_count']; ?></h3>
                            <p>Admins</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon staff">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['staff_count']; ?></h3>
                            <p>Staff/Nurses</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon doctor">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['doctor_count']; ?></h3>
                            <p>Doctors</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon property">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="3" y1="9" x2="21" y2="9"/>
                                <line x1="9" y1="21" x2="9" y2="9"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['property_count']; ?></h3>
                            <p>Property Custodians</p>
                        </div>
                    </div>
                </div>

                <!-- Role Navigation -->
                <div class="role-nav">
                    <?php foreach ($default_permissions as $role_key => $role): ?>
                    <a href="?role=<?php echo $role_key; ?>" class="role-btn <?php echo $current_role == $role_key ? 'active' : ''; ?>">
                        <?php echo $role['name']; ?>
                        <span class="role-badge <?php echo $role_key; ?>">
                            <?php echo $role_key == 'superadmin' ? $stats['superadmin_count'] : 
                                ($role_key == 'admin' ? $stats['admin_count'] : 
                                ($role_key == 'staff' ? $stats['staff_count'] : 
                                ($role_key == 'doctor' ? $stats['doctor_count'] : '0'))); ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Role Info Card -->
                <div class="role-info-card">
                    <div class="role-info-left">
                        <div class="role-icon-large <?php echo $current_role; ?>">
                            <?php
                            switch($current_role) {
                                case 'superadmin': echo '👑'; break;
                                case 'admin': echo '⚙️'; break;
                                case 'staff': echo '👩‍⚕️'; break;
                                case 'doctor': echo '👨‍⚕️'; break;
                                case 'property_custodian': echo '📦'; break;
                                default: echo '👤';
                            }
                            ?>
                        </div>
                        <div class="role-details">
                            <h2><?php echo $default_permissions[$current_role]['name']; ?></h2>
                            <p><?php echo $default_permissions[$current_role]['description']; ?></p>
                        </div>
                    </div>
                    <div class="role-info-right">
                        <button class="btn btn-secondary btn-small" onclick="openCopyModal('<?php echo $current_role; ?>')">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                <path d="M5 15H4C2.9 15 2 14.1 2 13V4C2 2.9 2.9 2 4 2H13C14.1 2 15 2.9 15 4V5"/>
                            </svg>
                            Copy Permissions
                        </button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reset to default permissions for this role?');">
                            <input type="hidden" name="role" value="<?php echo $current_role; ?>">
                            <button type="submit" name="reset_default" class="btn btn-warning btn-small">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M23 4V10H17"/>
                                    <path d="M1 20V14H7"/>
                                    <path d="M3.51 9C4.01 7.6 4.91 6.3 6.11 5.3C8.41 3.3 11.61 2.3 14.91 3.3C17.41 4.1 19.51 6.1 20.61 8.6"/>
                                    <path d="M20.49 15C19.99 16.4 19.09 17.7 17.89 18.7C15.59 20.7 12.39 21.7 9.09 20.7C6.59 19.9 4.49 17.9 3.39 15.4"/>
                                </svg>
                                Reset to Default
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Permissions Form -->
                <form method="POST" action="" id="permissionForm">
                    <input type="hidden" name="role" value="<?php echo $current_role; ?>">
                    
                    <div class="permissions-section">
                        <div class="section-header">
                            <h2>Module Permissions</h2>
                            <div class="section-actions">
                                <div class="select-all">
                                    <input type="checkbox" id="selectAll" onclick="toggleAllPermissions()">
                                    <label for="selectAll">Select All</label>
                                </div>
                            </div>
                        </div>

                        <?php foreach ($permissions as $category_key => $category): ?>
                        <div class="permission-category">
                            <div class="category-header" onclick="toggleCategory('<?php echo $category_key; ?>')">
                                <h3>
                                    <?php 
                                    $icons = [
                                        'dashboard' => '📊',
                                        'patient_records' => '📋',
                                        'clinic_visits' => '🏥',
                                        'inventory' => '📦',
                                        'medicine_requests' => '📝',
                                        'incidents' => '⚠️',
                                        'health_clearance' => '✅',
                                        'physical_exams' => '🩺',
                                        'medical_certificates' => '📄',
                                        'vaccination' => '💉',
                                        'deworming' => '🪱',
                                        'health_screening' => '🔍',
                                        'reports' => '📊',
                                        'user_management' => '👥',
                                        'system_settings' => '⚙️'
                                    ];
                                    echo $icons[$category_key] ?? '📌';
                                    ?>
                                    <?php echo $category['name']; ?>
                                    <span><?php echo $category['description']; ?></span>
                                </h3>
                                <span class="category-toggle">▼</span>
                            </div>
                            <div class="category-content" id="category-<?php echo $category_key; ?>">
                                <?php foreach ($category['modules'] as $perm_key => $perm_name): ?>
                                <div class="permission-item">
                                    <input type="checkbox" 
                                           name="permissions[]" 
                                           value="<?php echo $perm_key; ?>"
                                           id="perm_<?php echo $perm_key; ?>"
                                           class="permission-checkbox"
                                           data-category="<?php echo $category_key; ?>"
                                           <?php echo in_array($perm_key, $current_permissions) ? 'checked' : ''; ?>
                                           <?php echo $current_role == 'superadmin' ? 'disabled' : ''; ?>>
                                    <label for="perm_<?php echo $perm_key; ?>" class="permission-label">
                                        <?php echo $perm_name; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($current_role != 'superadmin'): ?>
                        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                            <button type="submit" name="save_permissions" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M17 21V15H7V21"/>
                                    <path d="M7 3V8H15"/>
                                </svg>
                                Save Permissions
                            </button>
                        </div>
                        <?php else: ?>
                        <div style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 12px; color: #546e7a;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8V12L16 14"/>
                            </svg>
                            <p style="margin-top: 10px;">Super Administrators have full system access and cannot be modified.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Summary Cards -->
                <div class="summary-stats">
                    <div class="summary-card">
                        <h4>Total Permissions</h4>
                        <div class="summary-value">
                            <?php 
                            $total_perms = 0;
                            foreach ($permissions as $cat) {
                                $total_perms += count($cat['modules']);
                            }
                            echo $total_perms;
                            ?>
                        </div>
                    </div>
                    <div class="summary-card">
                        <h4>Assigned to <?php echo $default_permissions[$current_role]['name']; ?></h4>
                        <div class="summary-value">
                            <?php echo count($current_permissions); ?> / <?php echo $total_perms; ?>
                        </div>
                        <div class="progress-bar" style="margin-top: 10px;">
                            <div class="progress-fill" style="width: <?php echo (count($current_permissions) / $total_perms) * 100; ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="activity-section">
                    <div class="section-header">
                        <h2>Recent Permission Changes</h2>
                        <a href="audit_logs.php" class="btn btn-secondary btn-small">View All Logs</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                            <div style="text-align: center; padding: 30px; color: #546e7a;">
                                No recent permission changes
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                    </svg>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php echo htmlspecialchars($activity['notes'] ?? $activity['action']); ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?> 
                                        by <?php echo htmlspecialchars($activity['user']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Copy Permissions Modal -->
    <div id="copyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Copy Permissions</h3>
                <button class="modal-close" onclick="closeModal('copyModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Copy permissions from <strong id="sourceRoleName"></strong> to:</p>
                    <input type="hidden" name="source_role" id="sourceRole" value="">
                    
                    <div class="role-checkbox-group">
                        <?php foreach ($default_permissions as $role_key => $role): ?>
                            <?php if ($role_key != 'superadmin' && $role_key != $current_role): ?>
                            <div class="role-checkbox">
                                <input type="checkbox" name="target_roles[]" value="<?php echo $role_key; ?>" id="target_<?php echo $role_key; ?>">
                                <label for="target_<?php echo $role_key; ?>">
                                    <?php echo $role['name']; ?>
                                </label>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('copyModal')">Cancel</button>
                    <button type="submit" name="apply_to_all" class="btn btn-primary">Copy Permissions</button>
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

        // Toggle category visibility
        function toggleCategory(categoryId) {
            const content = document.getElementById('category-' + categoryId);
            const header = content.previousElementSibling;
            const toggle = header.querySelector('.category-toggle');
            
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'grid';
                toggle.textContent = '▼';
            } else {
                content.style.display = 'none';
                toggle.textContent = '▶';
            }
        }

        // Select all permissions
        function toggleAllPermissions() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.permission-checkbox:not(:disabled)');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Update select all based on individual checkboxes
        document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('.permission-checkbox:not(:disabled)');
                const checkedCheckboxes = document.querySelectorAll('.permission-checkbox:checked:not(:disabled)');
                const selectAll = document.getElementById('selectAll');
                
                if (selectAll) {
                    selectAll.checked = allCheckboxes.length === checkedCheckboxes.length;
                }
            });
        });

        // Modal functions
        function openCopyModal(role) {
            document.getElementById('sourceRole').value = role;
            
            const roleNames = {
                'superadmin': 'Super Administrator',
                'admin': 'Administrator',
                'staff': 'Staff/Nurse',
                'doctor': 'Doctor',
                'property_custodian': 'Property Custodian'
            };
            
            document.getElementById('sourceRoleName').textContent = roleNames[role] || role;
            document.getElementById('copyModal').classList.add('show');
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
            pageTitle.textContent = 'Role & Permission Management';
        }

        // Category toggle initial state (all open)
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.category-content').forEach(content => {
                content.style.display = 'grid';
            });
        });

        // Prevent form submission if no permissions selected
        document.getElementById('permissionForm')?.addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.permission-checkbox:checked:not(:disabled)');
            if (checkboxes.length === 0) {
                if (!confirm('No permissions selected. This will remove all access for this role. Continue?')) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>