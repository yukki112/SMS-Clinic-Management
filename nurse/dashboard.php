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

// Get real statistics from database
$stats = [];

// Total unique students/patients from various tables
$query = "SELECT COUNT(DISTINCT student_id) as total FROM (
            SELECT student_id FROM clearance_requests
            UNION
            SELECT student_id FROM incidents
            UNION
            SELECT student_id FROM visit_history
            UNION
            SELECT student_id FROM physical_exam_records
          ) as all_students";
$stmt = $db->query($query);
$stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's appointments (using visit_history for today)
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending clearance requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_clearances'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Low stock items (quantity <= minimum_stock)
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active staff/users
$query = "SELECT COUNT(*) as total FROM users WHERE role IN ('admin', 'nurse', 'staff')";
$stmt = $db->query($query);
$stats['active_staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent incidents/emergencies
$query = "SELECT i.*, u.full_name as reported_by_name 
          FROM incidents i
          LEFT JOIN users u ON i.created_by = u.id
          ORDER BY i.created_at DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent clearance requests
$query = "SELECT * FROM clearance_requests 
          ORDER BY created_at DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_clearances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly visit data
$query = "SELECT 
            DAYNAME(visit_date) as day,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(visit_date)
          ORDER BY FIELD(DAYNAME(visit_date), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$stmt = $db->query($query);
$weekly_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process weekly data
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$visit_counts = array_fill(0, 7, 0);
$days_map = [
    'Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 
    'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6
];

foreach ($weekly_visits as $data) {
    if (isset($days_map[$data['day']])) {
        $visit_counts[$days_map[$data['day']]] = (int)$data['count'];
    }
}

// Get stock usage data for analytics
$query = "SELECT 
            category,
            COUNT(*) as total_items,
            SUM(quantity) as total_quantity,
            SUM(CASE WHEN quantity <= minimum_stock THEN 1 ELSE 0 END) as low_stock_items
          FROM clinic_stock
          GROUP BY category";
$stmt = $db->query($query);
$stock_analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clearance type distribution
$query = "SELECT 
            clearance_type,
            COUNT(*) as count,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count
          FROM clearance_requests
          GROUP BY clearance_type";
$stmt = $db->query($query);
$clearance_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AI-Powered Analytics
$ai_insights = [];

// 1. Predict peak hours based on historical data
$query = "SELECT 
            HOUR(visit_time) as hour,
            COUNT(*) as visit_count,
            AVG(COUNT(*)) OVER() as avg_visits
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY HOUR(visit_time)
          ORDER BY visit_count DESC
          LIMIT 3";
$stmt = $db->query($query);
$peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($peak_hours)) {
    $peak_times = [];
    foreach ($peak_hours as $hour) {
        $time_period = $hour['hour'] < 12 ? 'morning' : ($hour['hour'] < 17 ? 'afternoon' : 'evening');
        $peak_times[] = date('g A', strtotime($hour['hour'] . ':00'));
    }
    $ai_insights[] = "📊 Peak visit times: " . implode(', ', $peak_times) . ". Consider scheduling more staff during these hours.";
}

// 2. Clearance approval rate prediction
$query = "SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending
          FROM clearance_requests
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$stmt = $db->query($query);
$clearance_stats = $stmt->fetch(PDO::FETCH_ASSOC);

if ($clearance_stats['total_requests'] > 0) {
    $approval_rate = round(($clearance_stats['approved'] / $clearance_stats['total_requests']) * 100);
    $pending_rate = round(($clearance_stats['pending'] / $clearance_stats['total_requests']) * 100);
    $ai_insights[] = "📋 Clearance approval rate is {$approval_rate}% with {$pending_rate}% pending. " . 
                     ($pending_rate > 30 ? "Consider reviewing pending requests to reduce backlog." : "Current processing efficiency is good.");
}

// 3. Stock depletion prediction
$query = "SELECT 
            item_name,
            quantity,
            minimum_stock,
            (SELECT AVG(quantity) FROM dispensing_log WHERE item_code = cs.item_code AND dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as avg_monthly_usage
          FROM clinic_stock cs
          WHERE quantity > 0";
$stmt = $db->query($query);
$stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$critical_items = [];
foreach ($stock_items as $item) {
    if ($item['avg_monthly_usage'] > 0) {
        $months_remaining = $item['quantity'] / $item['avg_monthly_usage'];
        if ($months_remaining < 1) {
            $critical_items[] = $item['item_name'] . " (will last " . round($months_remaining * 30) . " days)";
        }
    }
}

if (!empty($critical_items)) {
    $ai_insights[] = "⚠️ Critical stock alert: " . implode(', ', array_slice($critical_items, 0, 3)) . 
                     (count($critical_items) > 3 ? " and " . (count($critical_items) - 3) . " more items need reordering soon." : " need immediate reordering.");
} else {
    $ai_insights[] = "✅ Stock levels are healthy. No critical items identified in the next 30 days.";
}

// 4. Common symptoms/conditions trend
$query = "SELECT 
            complaint,
            COUNT(*) as frequency,
            CONCAT(ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1), '%') as percentage
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY complaint
          ORDER BY frequency DESC
          LIMIT 3";
$stmt = $db->query($query);
$common_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($common_complaints)) {
    $complaint_list = [];
    foreach ($common_complaints as $complaint) {
        $complaint_list[] = $complaint['complaint'] . " (" . $complaint['percentage'] . ")";
    }
    $ai_insights[] = "🩺 Most common complaints this month: " . implode(', ', $complaint_list) . 
                     ". Ensure adequate supplies for these conditions.";
}

// 5. Incident trend analysis
$query = "SELECT 
            incident_type,
            COUNT(*) as count,
            DATE_FORMAT(incident_date, '%Y-%m') as month
          FROM incidents
          WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
          GROUP BY incident_type, DATE_FORMAT(incident_date, '%Y-%m')
          ORDER BY month DESC, count DESC";
$stmt = $db->query($query);
$incident_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

$incident_types = [];
foreach ($incident_trends as $trend) {
    if (!isset($incident_types[$trend['incident_type']])) {
        $incident_types[$trend['incident_type']] = 0;
    }
    $incident_types[$trend['incident_type']] += $trend['count'];
}

if (!empty($incident_types)) {
    arsort($incident_types);
    $top_incident = key($incident_types);
    $ai_insights[] = "🚨 Most frequent incident type: {$top_incident}. " . 
                     "Consider implementing preventive measures in this area.";
}

// 6. Deworming schedule prediction
$query = "SELECT 
            COUNT(*) as students_due
          FROM deworming_records
          WHERE next_dose_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND next_dose_date >= CURDATE()";
$stmt = $db->query($query);
$deworming_due = $stmt->fetch(PDO::FETCH_ASSOC)['students_due'];

if ($deworming_due > 0) {
    $ai_insights[] = "💊 {$deworming_due} student(s) are due for deworming in the next 7 days. Schedule appointments accordingly.";
}
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

    /* AI Insights Banner */
    .ai-insights {
        background: linear-gradient(135deg, #191970 0%, #2a2a8a 100%);
        border-radius: 16px;
        padding: 20px 24px;
        margin-bottom: 30px;
        color: white;
        box-shadow: 0 4px 20px rgba(25, 25, 112, 0.3);
        animation: fadeInUp 0.55s ease;
        border: 1px solid rgba(255,255,255,0.1);
    }

    .ai-insights-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .ai-insights-header h3 {
        font-size: 1.2rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ai-badge {
        background: rgba(255,255,255,0.2);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
        letter-spacing: 0.5px;
    }

    .insights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 16px;
    }

    .insight-item {
        background: rgba(255,255,255,0.1);
        border-radius: 12px;
        padding: 14px 16px;
        font-size: 0.9rem;
        line-height: 1.5;
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255,255,255,0.05);
        transition: all 0.3s ease;
    }

    .insight-item:hover {
        background: rgba(255,255,255,0.15);
        transform: translateX(5px);
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
        gap: 14px;
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
        width: 52px;
        height: 52px;
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
        font-size: 1.8rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 2px;
    }

    .stat-info p {
        color: #546e7a;
        font-size: 0.7rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 6px;
    }

    .stat-trend {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.65rem;
    }

    .trend-up {
        background: #e8f5e9;
        color: #2e7d32;
        padding: 3px 6px;
        border-radius: 16px;
        font-weight: 600;
    }

    .trend-down {
        background: #ffebee;
        color: #c62828;
        padding: 3px 6px;
        border-radius: 16px;
        font-weight: 600;
    }

    .trend-warning {
        background: #fff3e0;
        color: #ef6c00;
        padding: 3px 6px;
        border-radius: 16px;
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

    .chart-card, .distribution-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-header, .distribution-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }

    .chart-header h2, .distribution-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
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
        background: linear-gradient(180deg, #191970 0%, #2a2a8a 100%);
        border-radius: 8px 8px 0 0;
        min-height: 4px;
        transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        cursor: pointer;
        opacity: 0.9;
    }

    .chart-bar:hover {
        opacity: 1;
        box-shadow: 0 -4px 12px rgba(25, 25, 112, 0.3);
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

    .distribution-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .distribution-item {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .distribution-label {
        width: 120px;
        font-size: 0.9rem;
        color: #37474f;
        font-weight: 500;
    }

    .distribution-bar-container {
        flex: 1;
        height: 8px;
        background: #eceff1;
        border-radius: 4px;
        overflow: hidden;
    }

    .distribution-bar {
        height: 100%;
        background: #191970;
        border-radius: 4px;
        transition: width 0.5s ease;
    }

    .distribution-value {
        min-width: 50px;
        text-align: right;
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
    }

    /* Tables Section */
    .tables-section {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
    }

    .recent-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .card-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .view-all {
        color: #191970;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        padding: 5px 12px;
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

    .incident-list, .clearance-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .incident-item, .clearance-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        background: #eceff1;
        border-radius: 12px;
        transition: all 0.3s ease;
        border: 1px solid transparent;
    }

    .incident-item:hover, .clearance-item:hover {
        background: white;
        border-color: #191970;
        transform: translateX(5px);
        box-shadow: 0 2px 8px rgba(25, 25, 112, 0.1);
    }

    .incident-icon, .clearance-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }

    .incident-icon.emergency { background: #d32f2f; }
    .incident-icon.incident { background: #1976d2; }
    .incident-icon.minor { background: #f57c00; }
    .clearance-icon { background: #191970; }

    .incident-content, .clearance-content {
        flex: 1;
    }

    .incident-title {
        font-weight: 600;
        font-size: 0.95rem;
        color: #191970;
        margin-bottom: 4px;
    }

    .incident-meta, .clearance-meta {
        display: flex;
        gap: 12px;
        font-size: 0.7rem;
        color: #78909c;
    }

    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-approved { background: #e8f5e9; color: #2e7d32; }
    .status-pending { background: #fff3e0; color: #ef6c00; }
    .status-expired { background: #ffebee; color: #c62828; }

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
        grid-template-columns: repeat(6, 1fr);
        gap: 16px;
    }

    .action-card {
        background: white;
        border: 1px solid #cfd8dc;
        border-radius: 16px;
        padding: 20px;
        text-align: center;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }

    .action-card:hover {
        transform: translateY(-4px);
        border-color: #191970;
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
    }

    .action-icon {
        width: 52px;
        height: 52px;
        background: #191970;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        color: white;
        font-size: 24px;
        transition: all 0.3s ease;
    }

    .action-card:hover .action-icon {
        background: #2a2a8a;
        transform: scale(1.05);
    }

    .action-card span {
        display: block;
        font-weight: 600;
        color: #191970;
        font-size: 0.85rem;
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

    @media (max-width: 1400px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .actions-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 1200px) {
        .analytics-section {
            grid-template-columns: 1fr;
        }
        .tables-section {
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
        .actions-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .insights-grid {
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
                    <p>Here's your clinic overview and AI-powered insights for today.</p>
                </div>

                <!-- AI Insights Banner -->
                <div class="ai-insights">
                    <div class="ai-insights-header">
                        <h3>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16V12"/>
                                <circle cx="12" cy="8" r="1" fill="white"/>
                            </svg>
                            AI-Powered Analytics
                        </h3>
                        <span class="ai-badge">Updated real-time</span>
                    </div>
                    <div class="insights-grid">
                        <?php foreach ($ai_insights as $insight): ?>
                        <div class="insight-item">
                            <?php echo $insight; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students']; ?></h3>
                            <p>Total Students</p>
                            <div class="stat-trend">
                                <span class="trend-up">Active patients</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ <?php echo $stats['today_visits'] > 0 ? 'Active' : 'No visits yet'; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                <path d="M22 4L12 14.01L9 11.01"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_clearances']; ?></h3>
                            <p>Pending Clearances</p>
                            <div class="stat-trend">
                                <span class="<?php echo $stats['pending_clearances'] > 0 ? 'trend-warning' : 'trend-up'; ?>">
                                    <?php echo $stats['pending_clearances'] > 0 ? 'Needs attention' : 'All cleared'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                <path d="M20 12V18C20 19.1046 19.1046 20 18 20H6C4.89543 20 4 19.1046 4 18V12"/>
                                <path d="M12 2V14M12 14L9 11M12 14L15 11"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['low_stock']; ?></h3>
                            <p>Low Stock Items</p>
                            <div class="stat-trend">
                                <span class="<?php echo $stats['low_stock'] > 0 ? 'trend-down' : 'trend-up'; ?>">
                                    <?php echo $stats['low_stock'] > 0 ? 'Reorder needed' : 'Stock OK'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                <path d="M17 21V19C17 16.7909 15.2091 15 13 15H5C2.79086 15 1 16.7909 1 19V21"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21V19C22.9986 17.1771 21.765 15.5857 20 15.13"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['active_staff']; ?></h3>
                            <p>Active Staff</p>
                            <div class="stat-trend">
                                <span class="trend-up">On duty</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Visit Trends</h2>
                        </div>
                        <div class="chart-container">
                            <?php 
                            $max_visits = !empty($visit_counts) ? max($visit_counts) : 1;
                            foreach ($days as $index => $day): 
                                $count = $visit_counts[$index] ?? 0;
                                $height = $max_visits > 0 ? ($count / $max_visits) * 150 : 20;
                                $height = max(20, $height);
                            ?>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar" style="height: <?php echo $height; ?>px;" data-count="<?php echo $count; ?> visits"></div>
                                <span class="chart-label"><?php echo $day; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="distribution-card">
                        <div class="distribution-header">
                            <h2>Clearance Types</h2>
                        </div>
                        <div class="distribution-list">
                            <?php 
                            $total_clearances = array_sum(array_column($clearance_types, 'count')) ?: 1;
                            foreach ($clearance_types as $type): 
                                $percentage = round(($type['count'] / $total_clearances) * 100);
                            ?>
                            <div class="distribution-item">
                                <span class="distribution-label"><?php echo $type['clearance_type']; ?></span>
                                <div class="distribution-bar-container">
                                    <div class="distribution-bar" style="width: <?php echo $percentage; ?>%;"></div>
                                </div>
                                <span class="distribution-value"><?php echo $type['count']; ?> (<?php echo $percentage; ?>%)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Incidents and Clearances -->
                <div class="tables-section">
                    <div class="recent-card">
                        <div class="card-header">
                            <h2>Recent Incidents</h2>
                            <a href="incidents.php" class="view-all">View All</a>
                        </div>
                        <div class="incident-list">
                            <?php foreach ($recent_incidents as $incident): ?>
                            <div class="incident-item">
                                <div class="incident-icon <?php echo strtolower($incident['incident_type']); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                        <circle cx="12" cy="16" r="1" fill="currentColor"/>
                                    </svg>
                                </div>
                                <div class="incident-content">
                                    <div class="incident-title"><?php echo htmlspecialchars($incident['student_name']); ?></div>
                                    <div class="incident-meta">
                                        <span><?php echo $incident['incident_type']; ?></span>
                                        <span><?php echo date('M d, Y', strtotime($incident['incident_date'])); ?></span>
                                        <span><?php echo $incident['incident_code']; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($recent_incidents)): ?>
                            <div style="text-align: center; padding: 30px; color: #78909c;">
                                No recent incidents reported
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="recent-card">
                        <div class="card-header">
                            <h2>Clearance Requests</h2>
                            <a href="clearance_requests.php" class="view-all">View All</a>
                        </div>
                        <div class="clearance-list">
                            <?php foreach ($recent_clearances as $clearance): ?>
                            <div class="clearance-item">
                                <div class="clearance-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                        <path d="M22 4L12 14.01L9 11.01"/>
                                    </svg>
                                </div>
                                <div class="clearance-content">
                                    <div class="incident-title"><?php echo htmlspecialchars($clearance['student_name']); ?></div>
                                    <div class="incident-meta">
                                        <span><?php echo $clearance['clearance_type']; ?></span>
                                        <span class="status-badge status-<?php echo strtolower($clearance['status']); ?>">
                                            <?php echo $clearance['status']; ?>
                                        </span>
                                        <span><?php echo $clearance['clearance_code']; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($recent_clearances)): ?>
                            <div style="text-align: center; padding: 30px; color: #78909c;">
                                No clearance requests found
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="add_incident.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                            </div>
                            <span>Report Incident</span>
                        </a>
                        <a href="clearance_request.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                </svg>
                            </div>
                            <span>New Clearance</span>
                        </a>
                        <a href="add_visit.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                </svg>
                            </div>
                            <span>Log Visit</span>
                        </a>
                        <a href="stock_request.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <path d="M20 12V18C20 19.1046 19.1046 20 18 20H6C4.89543 20 4 19.1046 4 18V12"/>
                                    <path d="M12 2V14M12 14L9 11M12 14L15 11"/>
                                </svg>
                            </div>
                            <span>Request Stock</span>
                        </a>
                        <a href="physical_exam.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                    <path d="M2 17L12 22L22 17"/>
                                    <path d="M2 12L12 17L22 12"/>
                                </svg>
                            </div>
                            <span>Physical Exam</span>
                        </a>
                        <a href="medical_certificate.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                </svg>
                            </div>
                            <span>Issue Certificate</span>
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

        // Auto-refresh data every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000);

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Dashboard';
        }
    </script>
</body>
</html>