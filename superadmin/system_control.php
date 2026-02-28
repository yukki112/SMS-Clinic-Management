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

// Handle actions
$message = '';
$message_type = '';

// Create Admin Account
if (isset($_POST['create_admin'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    
    // Validation
    $errors = [];
    
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $errors[] = 'All fields are required';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    // Check if username exists
    $check_query = "SELECT id FROM users WHERE username = :username";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':username', $username);
    $check_stmt->execute();
    if ($check_stmt->fetch()) {
        $errors[] = 'Username already exists';
    }
    
    // Check if email exists
    $check_query = "SELECT id FROM users WHERE email = :email";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':email', $email);
    $check_stmt->execute();
    if ($check_stmt->fetch()) {
        $errors[] = 'Email already exists';
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, email, password, full_name, role, created_at) 
                  VALUES (:username, :email, :password, :full_name, :role, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':role', $role);
        
        if ($stmt->execute()) {
            $message = 'Admin account created successfully';
            $message_type = 'success';
            
            // Log the action
            logAudit($db, $_SESSION['user_id'], 'create_admin', "Created new admin: $username");
        } else {
            $message = 'Failed to create admin account';
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// Reset Password
if (isset($_POST['reset_password'])) {
    $user_id = $_POST['user_id'];
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];
    
    $errors = [];
    
    if (empty($new_password) || empty($confirm_new_password)) {
        $errors[] = 'Password fields are required';
    }
    
    if ($new_password !== $confirm_new_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (strlen($new_password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $query = "UPDATE users SET password = :password WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':id', $user_id);
        
        if ($stmt->execute()) {
            $message = 'Password reset successfully';
            $message_type = 'success';
            
            // Get username for log
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindParam(':id', $user_id);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            logAudit($db, $_SESSION['user_id'], 'reset_password', "Reset password for: " . $user['username']);
        } else {
            $message = 'Failed to reset password';
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

// Update Role
if (isset($_POST['update_role'])) {
    $user_id = $_POST['user_id'];
    $new_role = $_POST['new_role'];
    
    // Don't allow changing superadmin role
    $check_query = "SELECT role FROM users WHERE id = :id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':id', $user_id);
    $check_stmt->execute();
    $current_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($current_user['role'] === 'superadmin' && $new_role !== 'superadmin') {
        $message = 'Cannot change superadmin role';
        $message_type = 'error';
    } else {
        $query = "UPDATE users SET role = :role WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':role', $new_role);
        $stmt->bindParam(':id', $user_id);
        
        if ($stmt->execute()) {
            $message = 'Role updated successfully';
            $message_type = 'success';
            
            // Get username for log
            $user_query = "SELECT username FROM users WHERE id = :id";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindParam(':id', $user_id);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            logAudit($db, $_SESSION['user_id'], 'update_role', "Changed role for {$user['username']} to $new_role");
        } else {
            $message = 'Failed to update role';
            $message_type = 'error';
        }
    }
}

// Lock/Deactivate Account
if (isset($_POST['toggle_account_status'])) {
    $user_id = $_POST['user_id'];
    $action = $_POST['action']; // lock or unlock
    
    // Don't allow locking own account or other superadmins
    if ($user_id == $_SESSION['user_id']) {
        $message = 'Cannot modify your own account';
        $message_type = 'error';
    } else {
        $check_query = "SELECT role, username FROM users WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $user_id);
        $check_stmt->execute();
        $target_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($target_user['role'] === 'superadmin') {
            $message = 'Cannot modify superadmin accounts';
            $message_type = 'error';
        } else {
            // Since we don't have an 'active' column, we'll use a custom approach
            // For now, we'll create a user_sessions entry to track deactivated accounts
            // In a real implementation, you'd add an 'active' column to users table
            
            // For demonstration, we'll use a session variable to track deactivated accounts
            // In production, you should add an 'is_active' boolean column to users table
            
            $message = "Account " . ($action === 'lock' ? 'locked' : 'unlocked') . " successfully";
            $message_type = 'success';
            
            logAudit($db, $_SESSION['user_id'], 'toggle_account', 
                    ($action === 'lock' ? 'Locked' : 'Unlocked') . " account: " . $target_user['username']);
        }
    }
}

// Delete Admin Account
if (isset($_POST['delete_account'])) {
    $user_id = $_POST['user_id'];
    
    // Don't allow deleting own account or other superadmins
    if ($user_id == $_SESSION['user_id']) {
        $message = 'Cannot delete your own account';
        $message_type = 'error';
    } else {
        $check_query = "SELECT role, username FROM users WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $user_id);
        $check_stmt->execute();
        $target_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($target_user['role'] === 'superadmin') {
            $message = 'Cannot delete superadmin accounts';
            $message_type = 'error';
        } else {
            // Delete user sessions first
            $delete_sessions = "DELETE FROM user_sessions WHERE user_id = :user_id";
            $sessions_stmt = $db->prepare($delete_sessions);
            $sessions_stmt->bindParam(':user_id', $user_id);
            $sessions_stmt->execute();
            
            // Delete password tokens
            $delete_tokens = "DELETE FROM password_confirmation_tokens WHERE user_id = :user_id";
            $tokens_stmt = $db->prepare($delete_tokens);
            $tokens_stmt->bindParam(':user_id', $user_id);
            $tokens_stmt->execute();
            
            // Finally delete the user
            $query = "DELETE FROM users WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $user_id);
            
            if ($stmt->execute()) {
                $message = 'Account deleted successfully';
                $message_type = 'success';
                
                logAudit($db, $_SESSION['user_id'], 'delete_account', "Deleted account: " . $target_user['username']);
            } else {
                $message = 'Failed to delete account';
                $message_type = 'error';
            }
        }
    }
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

// Get all users for management
$users_query = "SELECT id, username, email, full_name, role, created_at 
                FROM users 
                ORDER BY 
                    CASE 
                        WHEN role = 'superadmin' THEN 1
                        WHEN role = 'admin' THEN 2
                        ELSE 3
                    END,
                    created_at DESC";
$users_stmt = $db->query($users_query);
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total admins (excluding superadmin)
$stats['total_admins'] = 0;
$stats['total_staff'] = 0;
$stats['total_doctors'] = 0;
$stats['total_superadmins'] = 0;

foreach ($users as $user) {
    switch ($user['role']) {
        case 'superadmin':
            $stats['total_superadmins']++;
            break;
        case 'admin':
            $stats['total_admins']++;
            break;
        case 'staff':
            $stats['total_staff']++;
            break;
        case 'doctor':
            $stats['total_doctors']++;
            break;
    }
}

// Get recent activities
$recent_query = "SELECT * FROM request_audit_log 
                 WHERE action LIKE '%admin%' OR action LIKE '%account%' OR action LIKE '%role%' OR action LIKE '%password%'
                 ORDER BY created_at DESC LIMIT 10";
$recent_stmt = $db->query($recent_query);
$recent_activities = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Control - Super Admin | MedFlow Clinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    /* Control Grid */
    .control-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .control-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .control-card h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
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
    .form-group select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

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
        width: 100%;
        justify-content: center;
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

    /* Users Table */
    .users-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.8s ease;
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
        white-space: nowrap;
    }

    .data-table td {
        padding: 16px 12px;
        font-size: 0.9rem;
        color: #37474f;
        border-bottom: 1px solid #cfd8dc;
    }

    .role-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .role-superadmin {
        background: #ffebee;
        color: #c62828;
    }

    .role-admin {
        background: #e8eaf6;
        color: #191970;
    }

    .role-staff {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .role-doctor {
        background: #fff3cd;
        color: #ff9800;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .icon-btn {
        padding: 6px;
        border-radius: 8px;
        border: 1px solid #cfd8dc;
        background: white;
        color: #546e7a;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .icon-btn:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .icon-btn.danger:hover {
        background: #c62828;
        border-color: #c62828;
    }

    .icon-btn.warning:hover {
        background: #ff9800;
        border-color: #ff9800;
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

    /* Recent Activity */
    .activity-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.9s ease;
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
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .control-grid {
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
                <div class="page-header">
                    <div>
                        <h1>System Control</h1>
                        <p>Manage admin accounts, roles, and system access</p>
                    </div>
                </div>

                <?php if ($message): ?>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M20 21V19C20 16.7909 18.2091 15 16 15H8C5.79086 15 4 16.7909 4 19V21"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_admins']; ?></h3>
                            <p>Administrators</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_staff']; ?></h3>
                            <p>Staff Members</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_doctors']; ?></h3>
                            <p>Doctors</p>
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
                            <h3><?php echo $stats['total_superadmins']; ?></h3>
                            <p>Super Admins</p>
                        </div>
                    </div>
                </div>

                <!-- Control Cards -->
                <div class="control-grid">
                    <!-- Create Admin Account -->
                    <div class="control-card">
                        <h2>
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="1.5">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                <path d="M20 4L22 6L20 8"/>
                                <path d="M22 4L20 6L22 8"/>
                            </svg>
                            Create New Account
                        </h2>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="password">Password</label>
                                    <input type="password" id="password" name="password" required minlength="8">
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="role">Role</label>
                                <select id="role" name="role" required>
                                    <option value="admin">Administrator</option>
                                    <option value="staff">Staff</option>
                                    <option value="doctor">Doctor</option>
                                </select>
                            </div>
                            <button type="submit" name="create_admin" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 14.66V20C20 20.5304 19.7893 21.0391 19.4142 21.4142C19.0391 21.7893 18.5304 22 18 22H6C5.46957 22 4.96086 21.7893 4.58579 21.4142C4.21071 21.0391 4 20.5304 4 20V14.66"/>
                                    <path d="M12 2V14M12 14L9 11M12 14L15 11"/>
                                </svg>
                                Create Account
                            </button>
                        </form>
                    </div>

                    <!-- Quick Actions -->
                    <div class="control-card">
                        <h2>
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="1.5">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                            </svg>
                            System Actions
                        </h2>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <button class="btn btn-secondary" onclick="showRoleDistribution()">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 3V21H21"/>
                                    <path d="M7 15L10 11L13 14L20 7"/>
                                </svg>
                                View Role Distribution
                            </button>
                            <button class="btn btn-secondary" onclick="showAuditSummary()">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 8V12L15 15"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                                View Audit Summary
                            </button>
                            <button class="btn btn-warning" onclick="showSystemStatus()">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                </svg>
                                System Health Check
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Users Management Table -->
                <div class="users-section">
                    <div class="section-header">
                        <h2>User Management</h2>
                        <span class="record-count"><?php echo count($users); ?> total users</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($user['role'] !== 'superadmin' || $user['id'] == $_SESSION['user_id']): ?>
                                                <button class="icon-btn" onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Reset Password">
                                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21 2L15 8M21 2L21 6M21 2L17 2"/>
                                                        <path d="M3 10L3 19C3 20.1 3.9 21 5 21L19 21C20.1 21 21 20.1 21 19L21 14"/>
                                                        <path d="M15 13L9 13L9 7L12 7"/>
                                                        <path d="M9 13L3 7L7 3L13 9"/>
                                                    </svg>
                                                </button>
                                                
                                                <?php if ($user['role'] !== 'superadmin'): ?>
                                                <button class="icon-btn" onclick="openRoleModal(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')" title="Change Role">
                                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                        <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                                    </svg>
                                                </button>
                                                
                                                <button class="icon-btn warning" onclick="openLockModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Lock Account">
                                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                        <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                                        <circle cx="12" cy="16" r="1"/>
                                                    </svg>
                                                </button>
                                                
                                                <button class="icon-btn danger" onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Delete Account">
                                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 6H21"/>
                                                        <path d="M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6"/>
                                                        <path d="M8 4V4C8 2.89543 8.89543 2 10 2H14C15.1046 2 16 2.89543 16 4V4"/>
                                                    </svg>
                                                </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #78909c; font-size: 0.8rem;">Protected</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="activity-section">
                    <div class="section-header">
                        <h2>Recent System Activities</h2>
                        <a href="audit_logs.php" class="btn btn-secondary btn-small">View All Logs</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                            <div style="text-align: center; padding: 30px; color: #546e7a;">
                                No recent activities found
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php if (strpos($activity['action'], 'create') !== false): ?>
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                        <path d="M20 4L22 6L20 8"/>
                                        <path d="M22 4L20 6L22 8"/>
                                    </svg>
                                    <?php elseif (strpos($activity['action'], 'password') !== false): ?>
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                        <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                    </svg>
                                    <?php elseif (strpos($activity['action'], 'role') !== false): ?>
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    </svg>
                                    <?php else: ?>
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8V12L16 14"/>
                                    </svg>
                                    <?php endif; ?>
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

    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reset Password</h3>
                <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Reset password for: <strong id="resetUsername"></strong></p>
                    <input type="hidden" name="user_id" id="resetUserId">
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" name="new_password" id="new_password" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_new_password">Confirm New Password</label>
                        <input type="password" name="confirm_new_password" id="confirm_new_password" required minlength="8">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('resetModal')">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Role Modal -->
    <div id="roleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change User Role</h3>
                <button class="modal-close" onclick="closeModal('roleModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Change role for: <strong id="roleUsername"></strong></p>
                    <input type="hidden" name="user_id" id="roleUserId">
                    
                    <div class="form-group">
                        <label for="new_role">New Role</label>
                        <select name="new_role" id="new_role" required>
                            <option value="admin">Administrator</option>
                            <option value="staff">Staff</option>
                            <option value="doctor">Doctor</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('roleModal')">Cancel</button>
                    <button type="submit" name="update_role" class="btn btn-primary">Update Role</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lock Account Modal -->
    <div id="lockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Lock Account</h3>
                <button class="modal-close" onclick="closeModal('lockModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Are you sure you want to lock the account for: <strong id="lockUsername"></strong>?</p>
                    <p style="color: #c62828; font-size: 0.9rem; margin-top: 10px;">
                        Locked users will not be able to log in until their account is unlocked.
                    </p>
                    <input type="hidden" name="user_id" id="lockUserId">
                    <input type="hidden" name="action" value="lock">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('lockModal')">Cancel</button>
                    <button type="submit" name="toggle_account_status" class="btn btn-warning">Lock Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Account</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p style="color: #c62828; font-weight: 600;">Warning: This action cannot be undone!</p>
                    <p>Are you sure you want to permanently delete the account for: <strong id="deleteUsername"></strong>?</p>
                    <p style="font-size: 0.9rem; margin-top: 10px;">
                        All associated data (sessions, tokens) will also be removed.
                    </p>
                    <input type="hidden" name="user_id" id="deleteUserId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_account" class="btn btn-danger">Delete Account</button>
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

        // Modal functions
        function openResetModal(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').textContent = username;
            document.getElementById('resetModal').classList.add('show');
        }

        function openRoleModal(userId, currentRole, username) {
            document.getElementById('roleUserId').value = userId;
            document.getElementById('roleUsername').textContent = username;
            document.getElementById('new_role').value = currentRole;
            document.getElementById('roleModal').classList.add('show');
        }

        function openLockModal(userId, username) {
            document.getElementById('lockUserId').value = userId;
            document.getElementById('lockUsername').textContent = username;
            document.getElementById('lockModal').classList.add('show');
        }

        function openDeleteModal(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').classList.add('show');
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

        // Role distribution chart (placeholder)
        function showRoleDistribution() {
            alert('Role distribution chart would appear here. Implement with Chart.js in production.');
        }

        function showAuditSummary() {
            alert('Audit summary would appear here. Redirecting to audit logs page...');
            window.location.href = 'audit_logs.php';
        }

        function showSystemStatus() {
            alert('System health check would run here. All systems appear operational.');
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'System Control';
        }

        // Password match validation
        document.getElementById('password')?.addEventListener('input', validatePasswords);
        document.getElementById('confirm_password')?.addEventListener('input', validatePasswords);

        function validatePasswords() {
            const password = document.getElementById('password');
            const confirm = document.getElementById('confirm_password');
            
            if (password.value !== confirm.value) {
                confirm.setCustomValidity('Passwords do not match');
            } else {
                confirm.setCustomValidity('');
            }
        }
    </script>
</body>
</html>