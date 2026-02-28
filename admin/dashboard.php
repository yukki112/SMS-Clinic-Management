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

// Get statistics with real data from all tables
$stats = [];

// Total patients (from patients table)
$query = "SELECT COUNT(*) as total FROM patients";
$stmt = $db->query($query);
$stats['patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's appointments
$query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff (excluding superadmin)
$query = "SELECT COUNT(*) as total FROM users WHERE role != 'superadmin'";
$stmt = $db->query($query);
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's visits (from visit_history)
$query = "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending clearances
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_clearances'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Low stock items
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent appointments
$query = "SELECT a.*, p.full_name as patient_name, p.id as patient_id, u.full_name as doctor_name 
          FROM appointments a 
          JOIN patients p ON a.patient_id = p.id 
          JOIN users u ON a.doctor_id = u.id 
          ORDER BY a.appointment_date DESC, a.appointment_time DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent activities from multiple tables
$recent_activities = [];

// Get recent patient registrations
$query = "SELECT 'patient' as type, full_name as title, created_at as time, 'New patient registered' as action 
          FROM patients ORDER BY created_at DESC LIMIT 3";
$stmt = $db->query($query);
$patient_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($patient_activities as $activity) {
    $activity['time_ago'] = time_elapsed_string($activity['time']);
    $recent_activities[] = $activity;
}

// Get recent clinic visits
$query = "SELECT v.*, 'visit' as type, u.full_name as attended_by_name 
          FROM visit_history v
          LEFT JOIN users u ON v.attended_by = u.id
          ORDER BY v.created_at DESC LIMIT 3";
$stmt = $db->query($query);
$visit_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($visit_activities as $activity) {
    $recent_activities[] = [
        'type' => 'visit',
        'title' => $activity['student_name'] ?? 'Student',
        'time' => $activity['created_at'],
        'action' => 'Clinic visit: ' . $activity['complaint'],
        'time_ago' => time_elapsed_string($activity['created_at'])
    ];
}

// Get recent incidents
$query = "SELECT i.*, 'incident' as type 
          FROM incidents i
          ORDER BY i.created_at DESC LIMIT 3";
$stmt = $db->query($query);
$incident_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($incident_activities as $activity) {
    $recent_activities[] = [
        'type' => 'incident',
        'title' => $activity['student_name'] ?? 'Student',
        'time' => $activity['created_at'],
        'action' => $activity['incident_type'] . ': ' . $activity['description'],
        'time_ago' => time_elapsed_string($activity['created_at'])
    ];
}

// Sort activities by time (most recent first)
usort($recent_activities, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
$recent_activities = array_slice($recent_activities, 0, 5);

// Helper function for time ago
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Weekly activity data for appointments
$query = "SELECT 
            DAYNAME(appointment_date) as day,
            COUNT(*) as count
          FROM appointments
          WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(appointment_date)
          ORDER BY FIELD(DAYNAME(appointment_date), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$stmt = $db->query($query);
$weekly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$days = [];
$counts = [];
$days_map = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue',
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat',
    'Sunday' => 'Sun'
];

foreach ($weekly_data as $data) {
    $days[] = $days_map[$data['day']] ?? substr($data['day'], 0, 3);
    $counts[] = $data['count'];
}

// Fill in missing days with zero
$all_days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$filled_counts = [];
foreach ($all_days as $day) {
    $index = array_search($day, $days);
    $filled_counts[] = $index !== false ? $counts[$index] : 0;
}
$counts = $filled_counts;
$days = $all_days;

// AI-Powered Insights based on real data
$insights = [];

// Insight 1: Busiest day of the week
$busiest_day_index = array_search(max($counts), $counts);
$busiest_day = $days[$busiest_day_index] ?? 'N/A';
$busiest_day_count = $counts[$busiest_day_index] ?? 0;
if ($busiest_day_count > 0) {
    $insights[] = [
        'type' => 'trend',
        'title' => 'Busiest Day',
        'message' => "{$busiest_day} is your busiest day with {$busiest_day_count} appointments this week.",
        'icon' => '📊',
        'color' => '#191970'
    ];
}

// Insight 2: Low stock alert
if ($stats['low_stock'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'title' => 'Low Stock Alert',
        'message' => "You have {$stats['low_stock']} item(s) below minimum stock level. Consider reordering.",
        'icon' => '⚠️',
        'color' => '#c62828'
    ];
} else {
    $insights[] = [
        'type' => 'success',
        'title' => 'Stock Status',
        'message' => "All inventory items are above minimum stock levels.",
        'icon' => '✅',
        'color' => '#2e7d32'
    ];
}

// Insight 3: Pending clearances
if ($stats['pending_clearances'] > 0) {
    $insights[] = [
        'type' => 'info',
        'title' => 'Pending Clearances',
        'message' => "There are {$stats['pending_clearances']} clearance request(s) waiting for review.",
        'icon' => '⏳',
        'color' => '#ed6c02'
    ];
}

// Insight 4: Most common complaint from visit history
$query = "SELECT complaint, COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY complaint 
          ORDER BY count DESC 
          LIMIT 1";
$stmt = $db->query($query);
$common_complaint = $stmt->fetch(PDO::FETCH_ASSOC);
if ($common_complaint) {
    $insights[] = [
        'type' => 'health',
        'title' => 'Common Health Issue',
        'message' => "'{$common_complaint['complaint']}' is the most common complaint this month ({$common_complaint['count']} cases).",
        'icon' => '🩺',
        'color' => '#1565c0'
    ];
}

// Insight 5: Staff activity
$query = "SELECT COUNT(*) as total FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$stmt = $db->query($query);
$active_staff = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$insights[] = [
    'type' => 'staff',
    'title' => 'Staff Activity',
    'message' => "{$active_staff} staff members have been active in the past week.",
    'icon' => '👥',
    'color' => '#6b2b5e'
];

// Insight 6: Upcoming appointments
$query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date >= CURDATE() AND status = 'scheduled'";
$stmt = $db->query($query);
$upcoming = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$insights[] = [
    'type' => 'appointment',
    'title' => 'Upcoming Appointments',
    'message' => "You have {$upcoming} scheduled appointments coming up.",
    'icon' => '📅',
    'color' => '#1976d2'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MedFlow Clinic Management System</title>
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

    /* AI Insights Section */
    .insights-section {
        margin-bottom: 30px;
        animation: fadeInUp 0.65s ease;
    }

    .insights-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .insights-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        color: #191970;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .insights-header h2 svg {
        width: 24px;
        height: 24px;
    }

    .insights-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }

    .insight-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        border-left: 4px solid;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        transition: all 0.3s ease;
    }

    .insight-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
    }

    .insight-icon {
        font-size: 24px;
        margin-bottom: 12px;
    }

    .insight-title {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 8px;
    }

    .insight-message {
        font-size: 0.9rem;
        color: #546e7a;
        line-height: 1.5;
    }

    /* Analytics Section */
    .analytics-section {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .chart-card, .activity-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-header, .activity-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }

    .chart-header h2, .activity-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
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
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .period-btn.active {
        background: #191970;
        color: white;
        border-color: #191970;
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
        background: #191970;
        border-radius: 6px 6px 0 0;
        min-height: 4px;
        transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        cursor: pointer;
        opacity: 0.9;
    }

    .chart-bar:hover {
        opacity: 1;
        background: #24248f;
    }

    .chart-bar:hover::after {
        content: attr(data-count) ' visits';
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        background: #191970;
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
        border-color: #191970;
        transform: translateX(5px);
        box-shadow: 0 2px 8px rgba(25, 25, 112, 0.1);
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

    .activity-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #e8f5e9;
        color: #2e7d32;
    }

    .activity-type-patient { background: #e3f2fd; color: #1565c0; }
    .activity-type-visit { background: #e8f5e9; color: #2e7d32; }
    .activity-type-incident { background: #ffebee; color: #c62828; }

    /* Recent Appointments */
    .recent-section {
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
    }

    .section-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .view-all {
        color: #191970;
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
        background: #191970;
        color: white;
        border-color: #191970;
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

    .patient-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .patient-avatar {
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

    .patient-details {
        display: flex;
        flex-direction: column;
    }

    .patient-name {
        font-weight: 600;
        color: #191970;
        margin-bottom: 2px;
    }

    .patient-id {
        font-size: 0.7rem;
        color: #90a4ae;
    }

    .doctor-info {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .doctor-avatar {
        width: 30px;
        height: 30px;
        background: #b0bec5;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 600;
        color: #37474f;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
    }

    .status-scheduled {
        background: #e3f2fd;
        color: #1565c0;
    }

    .status-completed {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .status-cancelled {
        background: #ffebee;
        color: #c62828;
    }

    .action-btn-small {
        padding: 8px 14px;
        background: #eceff1;
        border: 1px solid #cfd8dc;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #191970;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
    }

    .action-btn-small:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    /* Quick Actions */
    .quick-actions {
        animation: fadeInUp 0.9s ease;
    }

    .quick-actions h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }

    .action-card {
        background: white;
        border: 1px solid #cfd8dc;
        border-radius: 16px;
        padding: 24px;
        text-align: center;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        cursor: pointer;
    }

    .action-card:hover {
        transform: translateY(-4px);
        border-color: #191970;
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
    }

    .action-icon {
        width: 64px;
        height: 64px;
        background: #191970;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        color: white;
        font-size: 28px;
        transition: all 0.3s ease;
    }

    .action-card:hover .action-icon {
        background: #24248f;
        transform: scale(1.05);
    }

    .action-card span {
        display: block;
        font-weight: 600;
        color: #191970;
        font-size: 1rem;
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
        
        .insights-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .analytics-section {
            grid-template-columns: 1fr;
        }
        
        .actions-grid {
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
        
        .insights-grid {
            grid-template-columns: 1fr;
        }
        
        .actions-grid {
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
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                    <p>Here's what's happening with your clinic today.</p>
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
                            <h3><?php echo $stats['patients']; ?></h3>
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
                            <h3><?php echo $stats['today_appointments']; ?></h3>
                            <p>Today's Appointments</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 5%</span>
                                <span style="color: #546e7a;">vs yesterday</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M17 21V19C17 16.7909 15.2091 15 13 15H5C2.79086 15 1 16.7909 1 19V21"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21V19C22.9986 17.1771 21.765 15.5857 20 15.13"/>
                                <path d="M16 3.13C17.7699 3.58317 19.0077 5.17799 19.0077 7.005C19.0077 8.83201 17.7699 10.4268 16 10.88"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['staff']; ?></h3>
                            <p>Active Staff</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 2</span>
                                <span style="color: #546e7a;">new this month</span>
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
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 8%</span>
                                <span style="color: #546e7a;">vs yesterday</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI-Powered Insights Section -->
                <div class="insights-section">
                    <div class="insights-header">
                        <h2>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                            AI-Powered Insights
                        </h2>
                        <span style="color: #78909c; font-size: 0.9rem;">Based on your clinic's data</span>
                    </div>
                    <div class="insights-grid">
                        <?php foreach ($insights as $insight): ?>
                        <div class="insight-card" style="border-left-color: <?php echo $insight['color']; ?>">
                            <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                            <div class="insight-title"><?php echo $insight['title']; ?></div>
                            <div class="insight-message"><?php echo $insight['message']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Appointments</h2>
                            <div class="chart-period">
                                <button class="period-btn active" onclick="filterChart('week')">Week</button>
                                <button class="period-btn" onclick="filterChart('month')">Month</button>
                                <button class="period-btn" onclick="filterChart('year')">Year</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <?php 
                            $max_count = !empty($counts) ? max($counts) : 1;
                            foreach ($days as $index => $day): 
                                $count = isset($counts[$index]) ? $counts[$index] : 0;
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
                            <h2>Recent Activity</h2>
                            <a href="activity-log.php" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon activity-type-<?php echo $activity['type']; ?>">
                                    <?php if ($activity['type'] == 'patient'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                        </svg>
                                    <?php elseif ($activity['type'] == 'visit'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                            <path d="M2 17L12 22L22 17"/>
                                            <path d="M2 12L12 17L22 12"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                    <div class="activity-time"><?php echo $activity['action']; ?> • <?php echo $activity['time_ago']; ?></div>
                                </div>
                                <span class="activity-status activity-type-<?php echo $activity['type']; ?>">
                                    <?php echo ucfirst($activity['type']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Appointments -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>Recent Appointments</h2>
                        <a href="appointments.php" class="view-all">View All Appointments</a>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_appointments)): ?>
                                    <?php foreach ($recent_appointments as $appointment): ?>
                                    <tr>
                                        <td>
                                            <div class="patient-info">
                                                <div class="patient-avatar">
                                                    <?php echo strtoupper(substr($appointment['patient_name'], 0, 2)); ?>
                                                </div>
                                                <div class="patient-details">
                                                    <span class="patient-name"><?php echo htmlspecialchars($appointment['patient_name']); ?></span>
                                                    <span class="patient-id">ID: #<?php echo str_pad($appointment['patient_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="doctor-info">
                                                <div class="doctor-avatar">
                                                    <?php echo strtoupper(substr($appointment['doctor_name'], 0, 2)); ?>
                                                </div>
                                                <span><?php echo htmlspecialchars($appointment['doctor_name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                                <?php echo ucfirst($appointment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="appointment-details.php?id=<?php echo $appointment['id']; ?>" class="action-btn-small">View</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 40px; color: #78909c;">
                                            No recent appointments found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions (Now Functional) -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="student_records.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    <path d="M20 4L22 6L20 8"/>
                                    <path d="M22 4L20 6L22 8"/>
                                </svg>
                            </div>
                            <span>Student Records</span>
                        </a>
                        <a href="clinic_visits.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                    <path d="M8 2V6"/>
                                    <path d="M16 2V6"/>
                                </svg>
                            </div>
                            <span>Clinic Visit</span>
                        </a>
                        <a href="incidents.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                            </div>
                            <span>Incidents</span>
                        </a>
                        <a href="medicine_requests.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                </svg>
                            </div>
                            <span>Medicine Stock</span>
                        </a>
                        <a href="health_clearance.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                            </div>
                            <span>Clearance</span>
                        </a>
                        <a href="health_programs.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M3 3V21H21"/>
                                    <path d="M7 15L10 11L13 14L20 7"/>
                                </svg>
                            </div>
                            <span>Health Programs</span>
                        </a>
                        <a href="reports.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z"/>
                                    <path d="M14 2V8H20"/>
                                </svg>
                            </div>
                            <span>Reports</span>
                        </a>
                        <a href="backup.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                            </div>
                            <span>Backup</span>
                        </a>
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

        // Filter chart function (placeholder - would need AJAX for real implementation)
        function filterChart(period) {
            console.log('Filtering chart for period:', period);
            // In a real implementation, you would fetch new data via AJAX
            // and update the chart bars
        }

        // Update page title based on current page
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Dashboard';
        }
    </script>
</body>
</html>