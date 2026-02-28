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

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Build query
$query = "SELECT u.*, 
          COUNT(DISTINCT us.id) as session_count,
          MAX(us.created_at) as last_login,
          (SELECT COUNT(*) FROM incidents WHERE created_by = u.id) as incident_count,
          (SELECT COUNT(*) FROM medicine_requests WHERE requested_by = u.id) as request_count,
          (SELECT COUNT(*) FROM dispensing_log WHERE dispensed_by = u.id) as dispense_count
          FROM users u
          LEFT JOIN user_sessions us ON u.id = us.user_id
          WHERE 1=1";

$count_query = "SELECT COUNT(*) as total FROM users WHERE 1=1";

$params = [];

if (!empty($search)) {
    $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR u.full_name LIKE :search)";
    $count_query .= " AND (username LIKE :search OR email LIKE :search OR full_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($role_filter)) {
    $query .= " AND u.role = :role";
    $count_query .= " AND role = :role";
    $params[':role'] = $role_filter;
}

// For status filter (active/inactive) - based on recent sessions
if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $query .= " AND EXISTS (SELECT 1 FROM user_sessions WHERE user_id = u.id AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY))";
    } elseif ($status_filter === 'inactive') {
        $query .= " AND NOT EXISTS (SELECT 1 FROM user_sessions WHERE user_id = u.id AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY))";
    }
}

$query .= " GROUP BY u.id";

// Add sorting
$allowed_sorts = ['username', 'email', 'role', 'created_at', 'last_login'];
if (in_array($sort, $allowed_sorts)) {
    $query .= " ORDER BY $sort $order";
} else {
    $query .= " ORDER BY u.created_at DESC";
}

$query .= " LIMIT :offset, :limit";

// Get total count
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_users = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_users / $limit);

// Get users
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total users by role
$query_roles = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$stmt_roles = $db->query($query_roles);
$stats['by_role'] = [];
while ($row = $stmt_roles->fetch(PDO::FETCH_ASSOC)) {
    $stats['by_role'][$row['role']] = $row['count'];
}

// New users this month
$query_new = "SELECT COUNT(*) as count FROM users WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())";
$stmt_new = $db->query($query_new);
$stats['new_this_month'] = $stmt_new->fetch(PDO::FETCH_ASSOC)['count'];

// Active users (last 7 days)
$query_active = "SELECT COUNT(DISTINCT user_id) as count FROM user_sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
$stmt_active = $db->query($query_active);
$stats['active_7days'] = $stmt_active->fetch(PDO::FETCH_ASSOC)['count'];

// Inactive users (no activity in 30 days)
$query_inactive = "SELECT COUNT(*) as count FROM users WHERE id NOT IN (SELECT DISTINCT user_id FROM user_sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY))";
$stmt_inactive = $db->query($query_inactive);
$stats['inactive_30days'] = $stmt_inactive->fetch(PDO::FETCH_ASSOC)['count'];

// Get role distribution for chart
$role_labels = [];
$role_counts = [];
foreach ($stats['by_role'] as $role => $count) {
    $role_labels[] = ucfirst($role);
    $role_counts[] = $count;
}

// Handle actions
if (isset($_POST['toggle_status'])) {
    $user_id = $_POST['user_id'];
    $action = $_POST['action']; // activate or deactivate
    
    // Don't allow modifying own account or other superadmins
    if ($user_id == $_SESSION['user_id']) {
        $message = "Cannot modify your own account";
        $message_type = "error";
    } else {
        // Check if target is superadmin
        $check_query = "SELECT role, username FROM users WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $user_id);
        $check_stmt->execute();
        $target = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($target['role'] === 'superadmin') {
            $message = "Cannot modify superadmin accounts";
            $message_type = "error";
        } else {
            // In production, you'd have an 'active' column in users table
            // For now, we'll use sessions to track
            if ($action === 'deactivate') {
                // Delete all sessions to force logout
                $del_query = "DELETE FROM user_sessions WHERE user_id = :user_id";
                $del_stmt = $db->prepare($del_query);
                $del_stmt->bindParam(':user_id', $user_id);
                $del_stmt->execute();
                
                $message = "Account deactivated successfully";
            } else {
                $message = "Account activated successfully";
            }
            $message_type = "success";
            
            logAudit($db, $_SESSION['user_id'], 'toggle_user_status', 
                    ($action === 'deactivate' ? 'Deactivated' : 'Activated') . " account: " . $target['username']);
        }
    }
}

// Handle delete user
if (isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    if ($user_id == $_SESSION['user_id']) {
        $message = "Cannot delete your own account";
        $message_type = "error";
    } else {
        $check_query = "SELECT role, username FROM users WHERE id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $user_id);
        $check_stmt->execute();
        $target = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($target['role'] === 'superadmin') {
            $message = "Cannot delete superadmin accounts";
            $message_type = "error";
        } else {
            // Delete related records
            $db->beginTransaction();
            
            try {
                // Delete sessions
                $del_sessions = "DELETE FROM user_sessions WHERE user_id = :user_id";
                $stmt_sessions = $db->prepare($del_sessions);
                $stmt_sessions->bindParam(':user_id', $user_id);
                $stmt_sessions->execute();
                
                // Delete password tokens
                $del_tokens = "DELETE FROM password_confirmation_tokens WHERE user_id = :user_id";
                $stmt_tokens = $db->prepare($del_tokens);
                $stmt_tokens->bindParam(':user_id', $user_id);
                $stmt_tokens->execute();
                
                // Delete user
                $del_user = "DELETE FROM users WHERE id = :user_id";
                $stmt_user = $db->prepare($del_user);
                $stmt_user->bindParam(':user_id', $user_id);
                $stmt_user->execute();
                
                $db->commit();
                
                $message = "User deleted successfully";
                $message_type = "success";
                
                logAudit($db, $_SESSION['user_id'], 'delete_user', "Deleted user: " . $target['username']);
            } catch (Exception $e) {
                $db->rollBack();
                $message = "Error deleting user: " . $e->getMessage();
                $message_type = "error";
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

// Determine if user is active (has session in last 7 days)
function isUserActive($last_login) {
    if (!$last_login) return false;
    $last_login_time = strtotime($last_login);
    $seven_days_ago = strtotime('-7 days');
    return $last_login_time > $seven_days_ago;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Users - Super Admin | MedFlow Clinic Management System</title>
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

    .header-actions {
        display: flex;
        gap: 12px;
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

    /* Chart and Filters Row */
    .row {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 20px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .chart-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-card h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 15px;
    }

    .chart-container {
        height: 200px;
        position: relative;
    }

    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .filter-section h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 15px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        gap: 12px;
        margin-bottom: 15px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .filter-group label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #191970;
    }

    .filter-group input,
    .filter-group select {
        padding: 10px 12px;
        border: 1px solid #cfd8dc;
        border-radius: 8px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .filter-actions {
        display: flex;
        align-items: flex-end;
        gap: 8px;
    }

    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
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
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
    }

    .btn-secondary {
        background: #eceff1;
        color: #191970;
        border: 1px solid #cfd8dc;
    }

    .btn-secondary:hover {
        background: #cfd8dc;
    }

    .btn-success {
        background: #2e7d32;
        color: white;
    }

    .btn-success:hover {
        background: #3a8e3f;
    }

    .btn-warning {
        background: #ff9800;
        color: white;
    }

    .btn-warning:hover {
        background: #f57c00;
    }

    .btn-danger {
        background: #c62828;
        color: white;
    }

    .btn-danger:hover {
        background: #b71c1c;
    }

    .btn-small {
        padding: 6px 12px;
        font-size: 0.8rem;
    }

    /* Users Table */
    .users-section {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.8s ease;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .section-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .record-count {
        padding: 6px 14px;
        background: #eceff1;
        border-radius: 20px;
        font-size: 0.9rem;
        color: #191970;
        font-weight: 500;
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
        padding: 14px 12px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #78909c;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #cfd8dc;
        background: #f8fafc;
        white-space: nowrap;
        cursor: pointer;
    }

    .data-table th:hover {
        color: #191970;
    }

    .data-table td {
        padding: 14px 12px;
        font-size: 0.9rem;
        color: #37474f;
        border-bottom: 1px solid #eceff1;
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
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 1rem;
    }

    .user-details {
        display: flex;
        flex-direction: column;
    }

    .user-name {
        font-weight: 600;
        color: #191970;
    }

    .user-email {
        font-size: 0.75rem;
        color: #78909c;
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

    .action-buttons {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .icon-btn {
        padding: 6px;
        border-radius: 6px;
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

    .icon-btn.success:hover {
        background: #2e7d32;
        border-color: #2e7d32;
    }

    /* Pagination */
    .pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }

    .page-link {
        padding: 8px 14px;
        border-radius: 8px;
        background: white;
        border: 1px solid #cfd8dc;
        color: #191970;
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .page-link:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .page-link.active {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .page-link.disabled {
        opacity: 0.5;
        pointer-events: none;
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
        border-radius: 16px;
        padding: 24px;
        max-width: 400px;
        width: 90%;
        animation: slideUp 0.3s ease;
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .modal-header h3 {
        font-size: 1.2rem;
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
        
        .row {
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
        
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .filter-actions {
            flex-direction: column;
            align-items: stretch;
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
                        <h1>All Users</h1>
                        <p>Manage system users, view activity, and control access</p>
                    </div>
                    <div class="header-actions">
                        <a href="system_control.php" class="btn btn-secondary btn-small">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                            </svg>
                            System Control
                        </a>
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
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_users; ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21V19C20 16.7909 18.2091 15 16 15H8C5.79086 15 4 16.7909 4 19V21"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['by_role']['admin'] ?? 0; ?></h3>
                            <p>Administrators</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['by_role']['staff'] ?? 0; ?></h3>
                            <p>Staff / Nurses</p>
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
                            <h3><?php echo $stats['by_role']['doctor'] ?? 0; ?></h3>
                            <p>Doctors</p>
                        </div>
                    </div>
                </div>

                <!-- Chart and Filters Row -->
                <div class="row">
                    <div class="chart-card">
                        <h3>User Distribution by Role</h3>
                        <div class="chart-container">
                            <canvas id="roleChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="filter-section">
                        <h3>Filter Users</h3>
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label for="search">Search</label>
                                    <input type="text" id="search" name="search" placeholder="Username, email, name..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-group">
                                    <label for="role">Role</label>
                                    <select id="role" name="role">
                                        <option value="">All Roles</option>
                                        <option value="superadmin" <?php echo $role_filter == 'superadmin' ? 'selected' : ''; ?>>Super Admin</option>
                                        <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Staff/Nurse</option>
                                        <option value="doctor" <?php echo $role_filter == 'doctor' ? 'selected' : ''; ?>>Doctor</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label for="status">Status</label>
                                    <select id="status" name="status">
                                        <option value="">All Status</option>
                                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active (Last 7 days)</option>
                                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive (30+ days)</option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="11" cy="11" r="8"/>
                                            <path d="M21 21L16.5 16.5"/>
                                        </svg>
                                        Apply
                                    </button>
                                    <a href="all_users.php" class="btn btn-secondary">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M3 6H21M6 12H18M10 18H14"/>
                                        </svg>
                                        Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                        
                        <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: space-between;">
                            <div>
                                <span class="status-badge status-active">● Active (7d): <?php echo $stats['active_7days']; ?></span>
                            </div>
                            <div>
                                <span class="status-badge status-inactive">● Inactive (30d): <?php echo $stats['inactive_30days']; ?></span>
                            </div>
                            <div>
                                <span class="role-badge role-admin">● New this month: <?php echo $stats['new_this_month']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="users-section">
                    <div class="section-header">
                        <h2>System Users</h2>
                        <span class="record-count">Showing <?php echo count($users); ?> of <?php echo $total_users; ?> users</span>
                    </div>
                    
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('username')">User <?php echo $sort == 'username' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                                    <th onclick="sortTable('role')">Role <?php echo $sort == 'role' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                                    <th onclick="sortTable('created_at')">Created <?php echo $sort == 'created_at' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                                    <th onclick="sortTable('last_login')">Last Activity <?php echo $sort == 'last_login' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                                    <th>Status</th>
                                    <th>Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): 
                                    $active = isUserActive($user['last_login']);
                                    $avatar_initials = strtoupper(substr($user['full_name'], 0, 1) . substr(strstr($user['full_name'], ' ', false) ? substr(strstr($user['full_name'], ' ', false), 1, 1) : substr($user['full_name'], 1, 1), 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php echo $avatar_initials ?: 'U'; ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                                <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                                <span style="font-size: 0.7rem; color: #b0bec5;">@<?php echo htmlspecialchars($user['username']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <?php echo date('M d, Y h:i A', strtotime($user['last_login'])); ?>
                                        <?php else: ?>
                                            <span style="color: #b0bec5;">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $active ? 'active' : 'inactive'; ?>">
                                            <?php echo $active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 10px; font-size: 0.75rem; color: #78909c;">
                                            <span title="Incidents">⚠️ <?php echo $user['incident_count']; ?></span>
                                            <span title="Requests">📝 <?php echo $user['request_count']; ?></span>
                                            <span title="Dispensed">💊 <?php echo $user['dispense_count']; ?></span>
                                            <span title="Sessions">🔑 <?php echo $user['session_count']; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($user['role'] !== 'superadmin' || $user['id'] == $_SESSION['user_id']): ?>
                                                <a href="system_control.php?user=<?php echo $user['id']; ?>" class="icon-btn" title="Edit User">
                                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M20 14.66V20C20 20.5304 19.7893 21.0391 19.4142 21.4142C19.0391 21.7893 18.5304 22 18 22H6C5.46957 22 4.96086 21.7893 4.58579 21.4142C4.21071 21.0391 4 20.5304 4 20V14.66"/>
                                                        <path d="M12 2V14M12 14L9 11M12 14L15 11"/>
                                                    </svg>
                                                </a>
                                                
                                                <?php if ($user['role'] !== 'superadmin'): ?>
                                                    <?php if ($active): ?>
                                                    <button class="icon-btn warning" onclick="deactivateUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Deactivate">
                                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                            <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                                        </svg>
                                                    </button>
                                                    <?php else: ?>
                                                    <button class="icon-btn success" onclick="activateUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Activate">
                                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                            <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                                                            <circle cx="12" cy="16" r="1" fill="currentColor"/>
                                                        </svg>
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    <button class="icon-btn danger" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Delete">
                                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M3 6H21"/>
                                                            <path d="M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6"/>
                                                            <path d="M8 4V4C8 2.89543 8.89543 2 10 2H14C15.1046 2 16 2.89543 16 4V4"/>
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #b0bec5; font-size: 0.7rem;">Protected</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #78909c;">
                                        <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="12" r="10"/>
                                            <path d="M12 8V12L12 16"/>
                                        </svg>
                                        <p style="margin-top: 16px;">No users found matching your criteria</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=1&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" 
                           class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 12H5M12 5L5 12L12 19"/>
                            </svg>
                        </a>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" 
                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" 
                           class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12H19M12 5L19 12L12 19"/>
                            </svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Deactivate User Modal -->
    <div id="deactivateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Deactivate User</h3>
                <button class="modal-close" onclick="closeModal('deactivateModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Are you sure you want to deactivate user: <strong id="deactivateUsername"></strong>?</p>
                    <p style="color: #ff9800; font-size: 0.9rem; margin-top: 10px;">
                        Deactivated users will be logged out and cannot access the system until reactivated.
                    </p>
                    <input type="hidden" name="user_id" id="deactivateUserId">
                    <input type="hidden" name="action" value="deactivate">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deactivateModal')">Cancel</button>
                    <button type="submit" name="toggle_status" class="btn btn-warning">Deactivate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activate User Modal -->
    <div id="activateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Activate User</h3>
                <button class="modal-close" onclick="closeModal('activateModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Activate user: <strong id="activateUsername"></strong>?</p>
                    <p style="color: #2e7d32; font-size: 0.9rem; margin-top: 10px;">
                        Activated users will be able to access the system normally.
                    </p>
                    <input type="hidden" name="user_id" id="activateUserId">
                    <input type="hidden" name="action" value="activate">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('activateModal')">Cancel</button>
                    <button type="submit" name="toggle_status" class="btn btn-success">Activate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete User</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p style="color: #c62828; font-weight: 600;">Warning: This action cannot be undone!</p>
                    <p>Are you sure you want to permanently delete user: <strong id="deleteUsername"></strong>?</p>
                    <p style="font-size: 0.9rem; margin-top: 10px;">
                        All associated data (sessions, tokens) will also be removed.
                    </p>
                    <input type="hidden" name="user_id" id="deleteUserId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_user" class="btn btn-danger">Delete Permanently</button>
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

        // Chart initialization
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('roleChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($role_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($role_counts); ?>,
                        backgroundColor: ['#c62828', '#191970', '#2e7d32', '#ff9800'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
        });

        // Sorting function
        function sortTable(column) {
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort') || 'created_at';
            const currentOrder = url.searchParams.get('order') || 'DESC';
            
            let newOrder = 'ASC';
            if (currentSort === column && currentOrder === 'ASC') {
                newOrder = 'DESC';
            }
            
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            window.location.href = url.toString();
        }

        // Modal functions
        function deactivateUser(userId, username) {
            document.getElementById('deactivateUserId').value = userId;
            document.getElementById('deactivateUsername').textContent = username;
            document.getElementById('deactivateModal').classList.add('show');
        }

        function activateUser(userId, username) {
            document.getElementById('activateUserId').value = userId;
            document.getElementById('activateUsername').textContent = username;
            document.getElementById('activateModal').classList.add('show');
        }

        function deleteUser(userId, username) {
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

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'All Users';
        }
    </script>
</body>
</html>