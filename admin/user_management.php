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

// Check if user is admin or superadmin
$user_query = "SELECT role, full_name FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $current_user_id);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user_data['role'] ?? 'staff';
$user_fullname = $user_data['full_name'] ?? $_SESSION['username'];

// Restrict access to non-admin users
if (!in_array($user_role, ['admin', 'superadmin'])) {
    header('Location: ../dashboard.php');
    exit();
}

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Add new user
    if (isset($_POST['action']) && $_POST['action'] == 'add_user') {
        try {
            // Check if username already exists
            $check_query = "SELECT id FROM users WHERE username = :username OR email = :email";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $_POST['username']);
            $check_stmt->bindParam(':email', $_POST['email']);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = "Username or email already exists!";
            } else {
                // Hash password
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                
                $query = "INSERT INTO users (username, email, password, full_name, role) 
                          VALUES (:username, :email, :password, :full_name, :role)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $_POST['username']);
                $stmt->bindParam(':email', $_POST['email']);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':full_name', $_POST['full_name']);
                $stmt->bindParam(':role', $_POST['role']);
                
                if ($stmt->execute()) {
                    $success_message = "User added successfully!";
                } else {
                    $error_message = "Error adding user.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Update user
    if (isset($_POST['action']) && $_POST['action'] == 'update_user') {
        try {
            // Check if updating password
            if (!empty($_POST['password'])) {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $query = "UPDATE users SET username = :username, email = :email, 
                          password = :password, full_name = :full_name, role = :role 
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password', $hashed_password);
            } else {
                $query = "UPDATE users SET username = :username, email = :email, 
                          full_name = :full_name, role = :role WHERE id = :id";
                $stmt = $db->prepare($query);
            }
            
            $stmt->bindParam(':username', $_POST['username']);
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':full_name', $_POST['full_name']);
            $stmt->bindParam(':role', $_POST['role']);
            $stmt->bindParam(':id', $_POST['user_id']);
            
            if ($stmt->execute()) {
                $success_message = "User updated successfully!";
            } else {
                $error_message = "Error updating user.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Delete user
    if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
        try {
            // Don't allow deleting own account
            if ($_POST['user_id'] == $current_user_id) {
                $error_message = "You cannot delete your own account!";
            } else {
                $query = "DELETE FROM users WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $_POST['user_id']);
                
                if ($stmt->execute()) {
                    $success_message = "User deleted successfully!";
                } else {
                    $error_message = "Error deleting user.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Get all users
$query = "SELECT * FROM users ORDER BY created_at DESC";
$stmt = $db->query($query);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$stats = [];

// Total users
$stats['total_users'] = count($users);

// Users by role
$stats['admins'] = 0;
$stats['superadmins'] = 0;
$stats['staff'] = 0;
$stats['doctors'] = 0;

foreach ($users as $user) {
    switch ($user['role']) {
        case 'admin':
            $stats['admins']++;
            break;
        case 'superadmin':
            $stats['superadmins']++;
            break;
        case 'doctor':
            $stats['doctors']++;
            break;
        default:
            $stats['staff']++;
            break;
    }
}

// New users this month
$query = "SELECT COUNT(*) as total FROM users WHERE MONTH(created_at) = MONTH(CURDATE())";
$stmt = $db->query($query);
$stats['new_this_month'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active today (based on sessions if table exists)
try {
    $query = "SELECT COUNT(DISTINCT user_id) as total FROM user_sessions WHERE DATE(created_at) = CURDATE()";
    $stmt = $db->query($query);
    $stats['active_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $stats['active_today'] = 0; // Table might not exist
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | MedFlow Clinic Management System</title>
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

        .admin-badge {
            background: linear-gradient(135deg, #191970 0%, #24248f 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(25, 25, 112, 0.3);
        }

        .admin-badge svg {
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

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.7rem;
            margin-top: 8px;
        }

        .trend-up {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        /* Role Distribution */
        .role-distribution {
            display: flex;
            gap: 12px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .role-admin {
            background: #e3f2fd;
            color: #1565c0;
        }

        .role-superadmin {
            background: #f3e5f5;
            color: #6a1b9a;
        }

        .role-doctor {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .role-staff {
            background: #fff3e0;
            color: #e65100;
        }

        /* Users Section */
        .users-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.7s ease;
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

        .add-btn {
            background: #191970;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .add-btn:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .add-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Table Styles */
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: #191970;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            color: white;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: #191970;
            margin-bottom: 2px;
        }

        .user-username {
            font-size: 0.7rem;
            color: #90a4ae;
        }

        .role-badge-table {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .role-badge-table.admin {
            background: #e3f2fd;
            color: #1565c0;
        }

        .role-badge-table.superadmin {
            background: #f3e5f5;
            color: #6a1b9a;
        }

        .role-badge-table.doctor {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .role-badge-table.staff {
            background: #fff3e0;
            color: #e65100;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn.edit {
            background: #e3f2fd;
            color: #1565c0;
        }

        .action-btn.edit:hover {
            background: #1565c0;
            color: white;
        }

        .action-btn.delete {
            background: #ffebee;
            color: #c62828;
        }

        .action-btn.delete:hover {
            background: #c62828;
            color: white;
        }

        .action-btn svg {
            width: 14px;
            height: 14px;
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
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 500px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #78909c;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            color: #191970;
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
            padding: 12px 16px;
            font-size: 0.95rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: white;
            color: #37474f;
        }

        .modal-form .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        .modal-form select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23546e7a' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
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

        .modal-btn.danger {
            background: #c62828;
            color: white;
        }

        .modal-btn.danger:hover {
            background: #b71c1c;
        }

        .password-note {
            font-size: 0.7rem;
            color: #78909c;
            margin-top: 4px;
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
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
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
                    <div class="welcome-text">
                        <h1>👥 User Management</h1>
                        <p>Manage system users, roles, and permissions.</p>
                    </div>
                    <div class="admin-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        Admin Access
                    </div>
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

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_users']; ?></h3>
                            <p>Total Users</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ <?php echo $stats['new_this_month']; ?></span>
                                <span style="color: #546e7a;">new this month</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">👑</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['admins'] + $stats['superadmins']; ?></h3>
                            <p>Administrators</p>
                            <div class="role-distribution">
                                <?php if ($stats['superadmins'] > 0): ?>
                                    <span class="role-badge role-superadmin">Super: <?php echo $stats['superadmins']; ?></span>
                                <?php endif; ?>
                                <?php if ($stats['admins'] > 0): ?>
                                    <span class="role-badge role-admin">Admin: <?php echo $stats['admins']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">👩‍⚕️</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['doctors']; ?></h3>
                            <p>Doctors</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">💼</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['staff']; ?></h3>
                            <p>Staff</p>
                            <div class="stat-trend">
                                <span class="trend-up"><?php echo $stats['active_today']; ?> active</span>
                                <span style="color: #546e7a;">today</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users List Section -->
                <div class="users-section">
                    <div class="section-header">
                        <h2>📋 System Users</h2>
                        <button class="add-btn" onclick="openAddModal()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Add New User
                        </button>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                                <span class="user-username">@<?php echo htmlspecialchars($user['username']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge-table <?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                        <?php if ($user['id'] == $current_user_id): ?>
                                            <span style="font-size: 0.6rem; color: #78909c; margin-left: 4px;">(You)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal(<?php echo $user['id']; ?>)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                                                </svg>
                                                Edit
                                            </button>
                                            <?php if ($user['id'] != $current_user_id): ?>
                                                <button class="action-btn delete" onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"/>
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                    </svg>
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Role Permissions Info -->
                <div class="users-section" style="margin-top: 20px;">
                    <div class="section-header">
                        <h2>🔐 Role Permissions</h2>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; border-left: 4px solid #6a1b9a;">
                            <h3 style="color: #6a1b9a; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                <span>👑</span> Superadmin
                            </h3>
                            <ul style="list-style: none; color: #546e7a; font-size: 0.9rem;">
                                <li style="margin-bottom: 8px;">✓ Full system access</li>
                                <li style="margin-bottom: 8px;">✓ User management</li>
                                <li style="margin-bottom: 8px;">✓ System configuration</li>
                                <li style="margin-bottom: 8px;">✓ Backup & restore</li>
                                <li style="margin-bottom: 8px;">✓ Audit logs</li>
                            </ul>
                        </div>

                        <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; border-left: 4px solid #1565c0;">
                            <h3 style="color: #1565c0; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                <span>👤</span> Admin
                            </h3>
                            <ul style="list-style: none; color: #546e7a; font-size: 0.9rem;">
                                <li style="margin-bottom: 8px;">✓ User management</li>
                                <li style="margin-bottom: 8px;">✓ View all records</li>
                                <li style="margin-bottom: 8px;">✓ Generate reports</li>
                                <li style="margin-bottom: 8px;">✓ Manage inventory</li>
                                <li style="margin-bottom: 8px;">✗ System configuration</li>
                            </ul>
                        </div>

                        <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; border-left: 4px solid #2e7d32;">
                            <h3 style="color: #2e7d32; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                <span>👩‍⚕️</span> Doctor
                            </h3>
                            <ul style="list-style: none; color: #546e7a; font-size: 0.9rem;">
                                <li style="margin-bottom: 8px;">✓ View medical records</li>
                                <li style="margin-bottom: 8px;">✓ Add diagnoses</li>
                                <li style="margin-bottom: 8px;">✓ Prescribe medicine</li>
                                <li style="margin-bottom: 8px;">✓ Issue certificates</li>
                                <li style="margin-bottom: 8px;">✗ Manage users</li>
                            </ul>
                        </div>

                        <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; border-left: 4px solid #e65100;">
                            <h3 style="color: #e65100; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                <span>💼</span> Staff
                            </h3>
                            <ul style="list-style: none; color: #546e7a; font-size: 0.9rem;">
                                <li style="margin-bottom: 8px;">✓ Log clinic visits</li>
                                <li style="margin-bottom: 8px;">✓ Request medicine</li>
                                <li style="margin-bottom: 8px;">✓ View inventory</li>
                                <li style="margin-bottom: 8px;">✓ Basic records</li>
                                <li style="margin-bottom: 8px;">✗ Administrative functions</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal-overlay" id="addUserModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="close-btn" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form method="POST" action="" class="modal-form">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="Enter full name">
                </div>
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required placeholder="Enter username">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" required placeholder="Enter email">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required placeholder="Enter password" minlength="6">
                    <div class="password-note">Minimum 6 characters</div>
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control" required>
                        <option value="">Select role</option>
                        <option value="staff">Staff</option>
                        <option value="doctor">Doctor</option>
                        <?php if ($user_role == 'superadmin'): ?>
                            <option value="admin">Admin</option>
                            <option value="superadmin">Superadmin</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="modal-btn primary">Add User</button>
                    <button type="button" class="modal-btn secondary" onclick="closeAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form method="POST" action="" class="modal-form" id="editForm">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" id="edit_username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Password (leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter new password">
                    <div class="password-note">Minimum 6 characters if changing</div>
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit_role" class="form-control" required>
                        <option value="staff">Staff</option>
                        <option value="doctor">Doctor</option>
                        <?php if ($user_role == 'superadmin'): ?>
                            <option value="admin">Admin</option>
                            <option value="superadmin">Superadmin</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="modal-btn primary">Update User</button>
                    <button type="button" class="modal-btn secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal-overlay" id="deleteUserModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Delete User</h2>
                <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            
            <form method="POST" action="" class="modal-form">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="delete_user_id">
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="font-size: 48px; color: #c62828; margin-bottom: 15px;">⚠️</div>
                    <p style="color: #37474f; margin-bottom: 10px;">Are you sure you want to delete this user?</p>
                    <p style="font-weight: 600; color: #191970;" id="delete_user_name"></p>
                    <p style="color: #c62828; font-size: 0.9rem; margin-top: 10px;">This action cannot be undone!</p>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="modal-btn danger">Delete User</button>
                    <button type="button" class="modal-btn secondary" onclick="closeDeleteModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Store users data for JavaScript
        const users = <?php echo json_encode($users); ?>;

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

        // Add modal functions
        function openAddModal() {
            document.getElementById('addUserModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addUserModal').classList.remove('active');
        }

        // Edit modal functions
        function openEditModal(userId) {
            const user = users.find(u => u.id == userId);
            if (user) {
                document.getElementById('edit_user_id').value = user.id;
                document.getElementById('edit_full_name').value = user.full_name;
                document.getElementById('edit_username').value = user.username;
                document.getElementById('edit_email').value = user.email;
                document.getElementById('edit_role').value = user.role;
                document.getElementById('editUserModal').classList.add('active');
            }
        }

        function closeEditModal() {
            document.getElementById('editUserModal').classList.remove('active');
        }

        // Delete modal functions
        function openDeleteModal(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;
            document.getElementById('deleteUserModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteUserModal').classList.remove('active');
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
            pageTitle.textContent = 'User Management';
        }
    </script>
</body>
</html>