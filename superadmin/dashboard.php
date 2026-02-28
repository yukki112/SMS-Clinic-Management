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

// Get super admin statistics
$stats = [];

// Total users
$query = "SELECT COUNT(*) as total FROM users";
$stmt = $db->query($query);
$stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Users by role
$query = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$stmt = $db->query($query);
$role_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $role_counts[$row['role']] = $row['count'];
}
$stats['admins'] = $role_counts['admin'] ?? 0;
$stats['staff'] = $role_counts['staff'] ?? 0;
$stats['doctors'] = $role_counts['doctor'] ?? 0;
$stats['superadmins'] = $role_counts['superadmin'] ?? 0;

// Total patients
$query = "SELECT COUNT(*) as total FROM patients";
$stmt = $db->query($query);
$stats['total_patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total appointments
$query = "SELECT COUNT(*) as total FROM appointments";
$stmt = $db->query($query);
$stats['total_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total incidents
$query = "SELECT COUNT(*) as total FROM incidents";
$stmt = $db->query($query);
$stats['total_incidents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total medicine requests
$query = "SELECT COUNT(*) as total FROM medicine_requests";
$stmt = $db->query($query);
$stats['total_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent audit logs (simulated - you may need to create this table)
$recent_activities = [];

// Get recent user registrations
$query = "SELECT 'user_registration' as type, full_name, created_at, 'New user registered' as description 
          FROM users 
          ORDER BY created_at DESC 
          LIMIT 3";
$stmt = $db->query($query);
$user_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent incidents
$query = "SELECT 'incident' as type, CONCAT('Incident: ', description) as description, created_at 
          FROM incidents 
          ORDER BY created_at DESC 
          LIMIT 3";
$stmt = $db->query($query);
$incident_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge and sort activities
$recent_activities = array_merge($user_activities, $incident_activities);
usort($recent_activities, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
$recent_activities = array_slice($recent_activities, 0, 5);

// System health metrics
$system_health = [
    'database_size' => '156 MB',
    'backup_status' => 'Successful',
    'last_backup' => '2026-02-28 03:00 AM',
    'server_uptime' => '15 days',
    'active_sessions' => 23
];

// Weekly activity data for chart
$query = "SELECT 
            DAYNAME(created_at) as day,
            COUNT(*) as count
          FROM users
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(created_at)
          ORDER BY FIELD(DAYNAME(created_at), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$stmt = $db->query($query);
$weekly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$user_counts = array_fill(0, 7, 0);
$days_map = [
    'Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 
    'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6
];

foreach ($weekly_data as $data) {
    if (isset($days_map[$data['day']])) {
        $user_counts[$days_map[$data['day']]] = $data['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | MedFlow Clinic Management System</title>
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
        color: #8b0000;
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }

    .welcome-section p {
        color: #546e7a;
        font-size: 1rem;
        font-weight: 400;
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
        box-shadow: 0 8px 16px rgba(139, 0, 0, 0.1);
        border-color: #8b0000;
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        background: #8b0000;
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
        color: #8b0000;
        margin-bottom: 4px;
    }

    .stat-info p {
        color: #546e7a;
        font-size: 0.8rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }

    .stat-trend {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.7rem;
    }

    .trend-up {
        background: #e8f5e9;
        color: #2e7d32;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 600;
    }

    .trend-down {
        background: #ffebee;
        color: #c62828;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 600;
    }

    /* Analytics Section */
    .analytics-section {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .chart-card, .activity-card, .health-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-header, .activity-header, .health-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }

    .chart-header h2, .activity-header h2, .health-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #8b0000;
    }

    .chart-period {
        display: flex;
        gap: 8px;
    }

    .period-btn {
        padding: 6px 14px;
        background: #eceff1;
        border: 1px solid #cfd8dc;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #546e7a;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .period-btn:hover {
        background: #8b0000;
        color: white;
        border-color: #8b0000;
    }

    .period-btn.active {
        background: #8b0000;
        color: white;
        border-color: #8b0000;
    }

    .chart-container {
        height: 200px;
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }

    .chart-bar-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }

    .chart-bar {
        width: 100%;
        background: #8b0000;
        border-radius: 6px 6px 0 0;
        min-height: 4px;
        transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        cursor: pointer;
        opacity: 0.9;
    }

    .chart-bar:hover {
        opacity: 1;
        background: #a52a2a;
    }

    .chart-bar:hover::after {
        content: attr(data-count) ' activities';
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        background: #8b0000;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.7rem;
        white-space: nowrap;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .chart-label {
        font-size: 0.7rem;
        color: #546e7a;
        font-weight: 500;
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
        border: 1px solid transparent;
    }

    .activity-item:hover {
        background: white;
        border-color: #8b0000;
        transform: translateX(5px);
        box-shadow: 0 2px 8px rgba(139, 0, 0, 0.1);
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        background: #8b0000;
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
        color: #8b0000;
        margin-bottom: 4px;
    }

    .activity-time {
        font-size: 0.7rem;
        color: #78909c;
    }

    .activity-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #e8f5e9;
        color: #2e7d32;
    }

    /* Health Metrics */
    .health-metrics {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .metric-item {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .metric-label {
        width: 120px;
        font-size: 0.9rem;
        color: #546e7a;
    }

    .metric-value {
        flex: 1;
        font-weight: 600;
        color: #1e293b;
    }

    .metric-status {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-healthy {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .status-warning {
        background: #fff3cd;
        color: #ff9800;
    }

    .status-critical {
        background: #ffebee;
        color: #c62828;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #eceff1;
        border-radius: 4px;
        margin-top: 4px;
    }

    .progress-fill {
        height: 100%;
        background: #8b0000;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    /* Quick Access Grid */
    .quick-access-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
    }

    .access-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        text-align: center;
        text-decoration: none;
        transition: all 0.3s ease;
        border: 1px solid #cfd8dc;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }

    .access-card:hover {
        transform: translateY(-4px);
        border-color: #8b0000;
        box-shadow: 0 8px 16px rgba(139, 0, 0, 0.1);
    }

    .access-icon {
        width: 64px;
        height: 64px;
        background: #8b0000;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        color: white;
        font-size: 28px;
        transition: all 0.3s ease;
    }

    .access-card:hover .access-icon {
        background: #a52a2a;
        transform: scale(1.05);
    }

    .access-card span {
        display: block;
        font-weight: 600;
        color: #8b0000;
        font-size: 1rem;
        margin-bottom: 8px;
    }

    .access-card small {
        color: #546e7a;
        font-size: 0.8rem;
    }

    /* Recent Section */
    .recent-section {
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
    }

    .section-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #8b0000;
    }

    .view-all {
        color: #8b0000;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        padding: 6px 14px;
        background: #eceff1;
        border-radius: 20px;
        border: 1px solid #cfd8dc;
        transition: all 0.3s ease;
    }

    .view-all:hover {
        background: #8b0000;
        color: white;
        border-color: #8b0000;
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

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }

    .badge-success {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .badge-warning {
        background: #fff3cd;
        color: #ff9800;
    }

    .badge-danger {
        background: #ffebee;
        color: #c62828;
    }

    .badge-info {
        background: #e0f2fe;
        color: #0284c7;
    }

    .action-btn-small {
        padding: 8px 14px;
        background: #eceff1;
        border: 1px solid #cfd8dc;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #8b0000;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .action-btn-small:hover {
        background: #8b0000;
        color: white;
        border-color: #8b0000;
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
        
        .analytics-section {
            grid-template-columns: 1fr;
        }
        
        .quick-access-grid {
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
        
        .quick-access-grid {
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
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 🔐</h1>
                    <p>System overview and management at a glance.</p>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_users']; ?></h3>
                            <p>Total Users</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 8%</span>
                                <span style="color: #546e7a;">this month</span>
                            </div>
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
                            <h3><?php echo $stats['total_patients']; ?></h3>
                            <p>Total Patients</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 12%</span>
                                <span style="color: #546e7a;">vs last month</span>
                            </div>
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
                            <h3><?php echo $stats['total_appointments']; ?></h3>
                            <p>Appointments</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 5%</span>
                                <span style="color: #546e7a;">total</span>
                            </div>
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
                            <h3><?php echo $stats['total_incidents']; ?></h3>
                            <p>Incidents</p>
                            <div class="stat-trend">
                                <span class="trend-down">↓ 3%</span>
                                <span style="color: #546e7a;">this month</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Access Grid -->
                <div class="quick-access-grid">
                    <a href="reports.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                        </div>
                        <span>Reports</span>
                        <small>Generate system reports</small>
                    </a>
                    <a href="audit_logs.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M12 8V12L15 15"/>
                                <circle cx="12" cy="12" r="10"/>
                            </svg>
                        </div>
                        <span>Audit Logs</span>
                        <small>View system activities</small>
                    </a>
                    <a href="system_control.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                            </svg>
                        </div>
                        <span>System Control</span>
                        <small>Manage system settings</small>
                    </a>
                    <a href="role_permission.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                            </svg>
                        </div>
                        <span>Role & Permission</span>
                        <small>Manage user access</small>
                    </a>
                    <a href="system_config.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z"/>
                                <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                            </svg>
                        </div>
                        <span>System Config</span>
                        <small>Configure system</small>
                    </a>
                    <a href="backup_restore.php" class="access-card">
                        <div class="access-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M19 11V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V11"/>
                                <path d="M12 2V13M12 13L15 10M12 13L9 10"/>
                                <path d="M3 7H21"/>
                            </svg>
                        </div>
                        <span>Backup & Restore</span>
                        <small>Data protection</small>
                    </a>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly User Activity</h2>
                            <div class="chart-period">
                                <button class="period-btn active">Week</button>
                                <button class="period-btn">Month</button>
                                <button class="period-btn">Year</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <?php 
                            $max_count = !empty($user_counts) ? max($user_counts) : 1;
                            foreach ($days as $index => $day): 
                                $count = isset($user_counts[$index]) ? $user_counts[$index] : 0;
                                $height = $max_count > 0 ? ($count / $max_count) * 150 : 20;
                                $height = max(20, $height);
                            ?>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar" style="height: <?php echo $height; ?>px;" data-count="<?php echo $count; ?>"></div>
                                <span class="chart-label"><?php echo $day; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="activity-card">
                        <div class="activity-header">
                            <h2>Recent Activities</h2>
                            <a href="audit_logs.php" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                        <?php if ($activity['type'] == 'user_registration'): ?>
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                        <?php else: ?>
                                        <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                        <path d="M2 17L12 22L22 17"/>
                                        <path d="M2 12L12 17L22 12"/>
                                        <?php endif; ?>
                                    </svg>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                    <div class="activity-time"><?php echo date('M d, h:i A', strtotime($activity['created_at'])); ?></div>
                                </div>
                                <span class="activity-status">New</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- System Health Card -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>System Health Status</h2>
                        <a href="system_control.php" class="view-all">Manage System</a>
                    </div>
                    <div class="health-metrics">
                        <div class="metric-item">
                            <span class="metric-label">Database Size</span>
                            <span class="metric-value"><?php echo $system_health['database_size']; ?></span>
                            <span class="metric-status status-healthy">Optimal</span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">Last Backup</span>
                            <span class="metric-value"><?php echo $system_health['last_backup']; ?></span>
                            <span class="metric-status status-healthy"><?php echo $system_health['backup_status']; ?></span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">Server Uptime</span>
                            <span class="metric-value"><?php echo $system_health['server_uptime']; ?></span>
                            <span class="metric-status status-healthy">Healthy</span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">Active Sessions</span>
                            <span class="metric-value"><?php echo $system_health['active_sessions']; ?> users</span>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 75%"></div>
                            </div>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label">System Load</span>
                            <span class="metric-value">42%</span>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 42%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Distribution Table -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>User Distribution by Role</h2>
                        <a href="all_users.php" class="view-all">Manage Users</a>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Super Administrators</td>
                                    <td><strong><?php echo $stats['superadmins']; ?></strong></td>
                                    <td><?php echo round(($stats['superadmins'] / max($stats['total_users'], 1)) * 100, 1); ?>%</td>
                                    <td><span class="badge badge-success">Active</span></td>
                                    <td><button class="action-btn-small">View</button></td>
                                </tr>
                                <tr>
                                    <td>Administrators</td>
                                    <td><strong><?php echo $stats['admins']; ?></strong></td>
                                    <td><?php echo round(($stats['admins'] / max($stats['total_users'], 1)) * 100, 1); ?>%</td>
                                    <td><span class="badge badge-success">Active</span></td>
                                    <td><button class="action-btn-small">View</button></td>
                                </tr>
                                <tr>
                                    <td>Staff</td>
                                    <td><strong><?php echo $stats['staff']; ?></strong></td>
                                    <td><?php echo round(($stats['staff'] / max($stats['total_users'], 1)) * 100, 1); ?>%</td>
                                    <td><span class="badge badge-success">Active</span></td>
                                    <td><button class="action-btn-small">View</button></td>
                                </tr>
                                <tr>
                                    <td>Doctors</td>
                                    <td><strong><?php echo $stats['doctors']; ?></strong></td>
                                    <td><?php echo round(($stats['doctors'] / max($stats['total_users'], 1)) * 100, 1); ?>%</td>
                                    <td><span class="badge badge-warning">Mixed</span></td>
                                    <td><button class="action-btn-small">View</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
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

        // Chart animation
        const chartBars = document.querySelectorAll('.chart-bar');
        chartBars.forEach(bar => {
            const originalHeight = bar.style.height;
            bar.style.height = '0';
            setTimeout(() => {
                bar.style.height = originalHeight;
            }, 200);
        });

        // Period buttons
        const periodBtns = document.querySelectorAll('.period-btn');
        periodBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                periodBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Super Admin Dashboard';
        }
    </script>
</body>
</html>