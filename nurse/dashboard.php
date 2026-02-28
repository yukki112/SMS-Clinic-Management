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

// Total patients (students)
$query = "SELECT COUNT(DISTINCT student_id) as total FROM clearance_requests 
          UNION ALL 
          SELECT COUNT(DISTINCT student_id) FROM incidents
          UNION ALL
          SELECT COUNT(DISTINCT student_id) FROM visit_history";
$stmt = $db->query($query);
$results = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stats['patients'] = max($results); // Unique students across all tables

// Today's appointments/visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff (excluding superadmin)
$query = "SELECT COUNT(*) as total FROM users WHERE role IN ('admin', 'staff', 'nurse')";
$stmt = $db->query($query);
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's visits (medical records)
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Additional stats for AI analytics
// Pending clearance requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_clearance'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Low stock items
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active incidents today
$query = "SELECT COUNT(*) as total FROM incidents WHERE incident_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_incidents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent appointments (from visit_history)
$query = "SELECT v.*, u.full_name as doctor_name 
          FROM visit_history v 
          LEFT JOIN users u ON v.attended_by = u.id 
          ORDER BY v.visit_date DESC, v.visit_time DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly activity data
$query = "SELECT 
            DAYNAME(visit_date) as day,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(visit_date)
          ORDER BY FIELD(DAYNAME(visit_date), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
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

// Get recent activity for AI analysis
$query = "SELECT 'visit' as type, v.student_name, v.visit_date, v.visit_time, v.complaint 
          FROM visit_history v 
          WHERE v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          UNION ALL
          SELECT 'incident' as type, i.student_name, i.incident_date, i.incident_time, i.description 
          FROM incidents i 
          WHERE i.incident_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          UNION ALL
          SELECT 'clearance' as type, c.student_name, c.request_date, NULL, c.purpose 
          FROM clearance_requests c 
          WHERE c.request_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
          ORDER BY visit_date DESC, visit_time DESC
          LIMIT 10";
$stmt = $db->query($query);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AI Analytics: Calculate trends and predictions
$trends = [];

// Patient visit trend (compare with last week)
$query = "SELECT 
            COUNT(*) as this_week,
            (SELECT COUNT(*) FROM visit_history WHERE visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as last_week
          FROM visit_history 
          WHERE visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()";
$stmt = $db->query($query);
$visit_trend = $stmt->fetch(PDO::FETCH_ASSOC);
$trends['visit_change'] = $visit_trend['last_week'] > 0 
    ? round((($visit_trend['this_week'] - $visit_trend['last_week']) / $visit_trend['last_week']) * 100, 1)
    : 100;

// Most common complaints
$query = "SELECT complaint, COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY complaint 
          ORDER BY count DESC 
          LIMIT 3";
$stmt = $db->query($query);
$common_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Medicine usage prediction
$query = "SELECT item_name, SUM(quantity) as total_used 
          FROM dispensing_log 
          WHERE dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          GROUP BY item_name 
          ORDER BY total_used DESC 
          LIMIT 5";
$stmt = $db->query($query);
$medicine_usage = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Peak hours analysis
$query = "SELECT 
            CASE 
              WHEN HOUR(visit_time) BETWEEN 8 AND 11 THEN 'Morning (8-11)'
              WHEN HOUR(visit_time) BETWEEN 12 AND 16 THEN 'Afternoon (12-4)'
              WHEN HOUR(visit_time) BETWEEN 17 AND 20 THEN 'Evening (5-8)'
              ELSE 'Night (8+)'
            END as time_slot,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY time_slot
          ORDER BY count DESC";
$stmt = $db->query($query);
$peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Clearance approval rate
$query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved
          FROM clearance_requests
          WHERE request_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $db->query($query);
$clearance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$approval_rate = $clearance_stats['total'] > 0 
    ? round(($clearance_stats['approved'] / $clearance_stats['total']) * 100, 1)
    : 0;

// Stock prediction (items that will run out soon)
$query = "SELECT item_name, quantity, minimum_stock, 
          ROUND(quantity / NULLIF((SELECT AVG(quantity) FROM dispensing_log WHERE item_name = clinic_stock.item_name AND dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0)) as days_remaining
          FROM clinic_stock 
          WHERE quantity <= minimum_stock * 2
          ORDER BY quantity ASC 
          LIMIT 5";
$stmt = $db->query($query);
$low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        .welcome-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .ai-badge {
            background: linear-gradient(135deg, #191970 0%, #4a4a9e 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px rgba(25, 25, 112, 0.3);
        }

        .ai-badge svg {
            width: 18px;
            height: 18px;
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
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #191970, #4a4a9e);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .stat-card:hover::before {
            transform: scaleX(1);
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

        .trend-neutral {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        /* AI Analytics Section */
        .ai-analytics-section {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.65s ease;
        }

        .ai-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #cfd8dc;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .ai-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
            border-color: #191970;
        }

        .ai-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .ai-card-header svg {
            width: 24px;
            height: 24px;
            color: #191970;
        }

        .ai-card-header h3 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ai-card-value {
            font-size: 2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
        }

        .ai-card-trend {
            font-size: 0.8rem;
            color: #546e7a;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .ai-insight {
            background: #eceff1;
            border-radius: 8px;
            padding: 10px;
            margin-top: 12px;
            font-size: 0.8rem;
            color: #37474f;
            border-left: 3px solid #191970;
        }

        /* Analytics Section */
        .analytics-section {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .chart-card, .activity-card, .insights-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .chart-header, .activity-header, .insights-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .chart-header h2, .activity-header h2, .insights-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
            display: flex;
            align-items: center;
            gap: 8px;
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
            height: 250px;
            position: relative;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 400px;
            overflow-y: auto;
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

        .insights-grid {
            display: grid;
            gap: 16px;
        }

        .insight-item {
            background: #eceff1;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid #191970;
        }

        .insight-item h4 {
            font-size: 0.9rem;
            color: #191970;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .insight-item p {
            font-size: 0.85rem;
            color: #37474f;
            margin-bottom: 8px;
        }

        .insight-item .recommendation {
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-warning {
            background: #fff3e0;
            color: #e65100;
        }

        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Recent Section */
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

        .status-scheduled, .status-Pending {
            background: #e3f2fd;
            color: #1565c0;
        }

        .status-completed, .status-Approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-cancelled, .status-Not\ Cleared {
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
            .stats-grid, .ai-analytics-section {
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
            
            .stats-grid, .ai-analytics-section {
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
                    <div>
                        <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                        <p>Here's what's happening with your clinic today.</p>
                    </div>
                    <div class="ai-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        AI Analytics Active
                    </div>
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
                            <p>Total Students</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ Active</span>
                                <span style="color: #546e7a;">unique students</span>
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
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="<?php echo $trends['visit_change'] > 0 ? 'trend-up' : ($trends['visit_change'] < 0 ? 'trend-down' : 'trend-neutral'); ?>">
                                    <?php echo $trends['visit_change'] > 0 ? '↑' : ($trends['visit_change'] < 0 ? '↓' : '→'); ?> 
                                    <?php echo abs($trends['visit_change']); ?>%
                                </span>
                                <span style="color: #546e7a;">vs last week</span>
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
                                <span class="trend-up">↑ Online</span>
                                <span style="color: #546e7a;">medical team</span>
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
                            <p>Today's Records</p>
                            <div class="stat-trend">
                                <span class="trend-neutral">→ Processed</span>
                                <span style="color: #546e7a;">medical records</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI Analytics Cards -->
                <div class="ai-analytics-section">
                    <div class="ai-card">
                        <div class="ai-card-header">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            <h3>Pending Clearances</h3>
                        </div>
                        <div class="ai-card-value"><?php echo $stats['pending_clearance']; ?></div>
                        <div class="ai-card-trend">
                            <span class="badge badge-warning">Needs attention</span>
                        </div>
                        <div class="ai-insight">
                            <strong>AI Insight:</strong> <?php echo $stats['pending_clearance'] > 0 ? 'Review pending clearances to avoid delays' : 'All clearances are up to date'; ?>
                        </div>
                    </div>

                    <div class="ai-card">
                        <div class="ai-card-header">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                            </svg>
                            <h3>Low Stock Items</h3>
                        </div>
                        <div class="ai-card-value"><?php echo $stats['low_stock']; ?></div>
                        <div class="ai-card-trend">
                            <span class="badge badge-warning">Reorder soon</span>
                        </div>
                        <div class="ai-insight">
                            <strong>AI Prediction:</strong> 
                            <?php 
                            if (!empty($low_stock_items)) {
                                echo $low_stock_items[0]['item_name'] . ' will run out soon';
                            } else {
                                echo 'Stock levels are healthy';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="ai-card">
                        <div class="ai-card-header">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                            <h3>Today's Incidents</h3>
                        </div>
                        <div class="ai-card-value"><?php echo $stats['today_incidents']; ?></div>
                        <div class="ai-card-trend">
                            <span class="badge badge-info">Monitor</span>
                        </div>
                        <div class="ai-insight">
                            <strong>Alert:</strong> <?php echo $stats['today_incidents'] > 0 ? 'Incidents reported today - review safety protocols' : 'No incidents reported today'; ?>
                        </div>
                    </div>

                    <div class="ai-card">
                        <div class="ai-card-header">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 12h-4l-3 9-4-18-3 9H2"/>
                            </svg>
                            <h3>Approval Rate</h3>
                        </div>
                        <div class="ai-card-value"><?php echo $approval_rate; ?>%</div>
                        <div class="ai-card-trend">
                            <span class="<?php echo $approval_rate > 70 ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $approval_rate > 70 ? 'Good' : 'Needs improvement'; ?>
                            </span>
                        </div>
                        <div class="ai-insight">
                            <strong>Efficiency:</strong> Clearance approval rate this month
                        </div>
                    </div>
                </div>

                <!-- Analytics Section with Charts -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <path d="M3 3v18h18"/>
                                    <path d="M18 17V9"/>
                                    <path d="M12 17V5"/>
                                    <path d="M6 17v-3"/>
                                </svg>
                                Weekly Visits
                            </h2>
                            <div class="chart-period">
                                <button class="period-btn active" onclick="updateChart('week')">Week</button>
                                <button class="period-btn" onclick="updateChart('month')">Month</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="visitsChart"></canvas>
                        </div>
                    </div>

                    <div class="activity-card">
                        <div class="activity-header">
                            <h2>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v8"/>
                                    <path d="M8 12h8"/>
                                </svg>
                                Recent Activity
                            </h2>
                            <a href="activity-log.php" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php if ($activity['type'] == 'visit'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                        </svg>
                                    <?php elseif ($activity['type'] == 'incident'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <circle cx="12" cy="12" r="10"/>
                                            <path d="M12 8v4M12 16h.01"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                            <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php 
                                        echo htmlspecialchars(substr($activity['student_name'], 0, 20)) . ' - ';
                                        echo $activity['type'] == 'visit' ? 'Visit' : ($activity['type'] == 'incident' ? 'Incident' : 'Clearance');
                                        ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php 
                                        echo date('M d, Y', strtotime($activity['visit_date'] ?? $activity['incident_date'] ?? $activity['request_date']));
                                        if (!empty($activity['visit_time'])) {
                                            echo ' at ' . date('h:i A', strtotime($activity['visit_time']));
                                        }
                                        ?>
                                    </div>
                                </div>
                                <span class="activity-status">
                                    <?php echo ucfirst($activity['type']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- AI Insights Section -->
                <div class="analytics-section">
                    <div class="insights-card">
                        <div class="insights-header">
                            <h2>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 16v-4M12 8h.01"/>
                                </svg>
                                AI Health Insights
                            </h2>
                        </div>
                        <div class="insights-grid">
                            <!-- Common Complaints -->
                            <div class="insight-item">
                                <h4>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    Most Common Complaints
                                </h4>
                                <?php foreach ($common_complaints as $complaint): ?>
                                <p>• <?php echo htmlspecialchars($complaint['complaint']); ?> (<?php echo $complaint['count']; ?> cases)</p>
                                <?php endforeach; ?>
                                <div class="recommendation">
                                    <strong>Recommendation:</strong> Stock up on relevant medicines
                                </div>
                            </div>

                            <!-- Peak Hours -->
                            <div class="insight-item">
                                <h4>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6v6l4 2"/>
                                    </svg>
                                    Peak Visit Hours
                                </h4>
                                <?php foreach ($peak_hours as $hour): ?>
                                <p>• <?php echo $hour['time_slot']; ?>: <?php echo $hour['count']; ?> visits</p>
                                <?php endforeach; ?>
                                <div class="recommendation">
                                    <strong>Staffing:</strong> Ensure adequate staff during peak hours
                                </div>
                            </div>

                            <!-- Medicine Usage -->
                            <div class="insight-item">
                                <h4>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                                    </svg>
                                    Most Used Medicines
                                </h4>
                                <?php foreach ($medicine_usage as $medicine): ?>
                                <p>• <?php echo htmlspecialchars($medicine['item_name']); ?>: <?php echo $medicine['total_used']; ?> units</p>
                                <?php endforeach; ?>
                                <div class="recommendation">
                                    <strong>Prediction:</strong> Order more of these items soon
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Prediction Card -->
                    <div class="insights-card">
                        <div class="insights-header">
                            <h2>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                                </svg>
                                Stock Alerts
                            </h2>
                        </div>
                        <div class="insights-grid">
                            <?php if (!empty($low_stock_items)): ?>
                                <?php foreach ($low_stock_items as $item): ?>
                                <div class="insight-item">
                                    <h4><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                    <p>Current: <?php echo $item['quantity']; ?> units | Min: <?php echo $item['minimum_stock']; ?></p>
                                    <?php if (isset($item['days_remaining']) && $item['days_remaining'] > 0): ?>
                                    <p class="badge badge-warning">Estimated <?php echo round($item['days_remaining']); ?> days left</p>
                                    <?php endif; ?>
                                    <div class="recommendation">
                                        <strong>Action:</strong> Reorder immediately
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="insight-item">
                                    <p>All stock levels are healthy</p>
                                    <div class="recommendation">
                                        <strong>Status:</strong> No reorder needed
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Visits -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>Recent Visits</h2>
                        <a href="visit-history.php" class="view-all">View All Visits</a>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Complaint</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Temperature</th>
                                    <th>Attended By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_visits as $visit): ?>
                                <tr>
                                    <td>
                                        <div class="patient-info">
                                            <div class="patient-avatar">
                                                <?php echo strtoupper(substr($visit['student_name'], 0, 2)); ?>
                                            </div>
                                            <div class="patient-details">
                                                <span class="patient-name"><?php echo htmlspecialchars(substr($visit['student_name'], 0, 20)); ?></span>
                                                <span class="patient-id">ID: #<?php echo htmlspecialchars($visit['student_id']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($visit['complaint'], 0, 30)); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($visit['visit_date'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($visit['visit_time'])); ?></td>
                                    <td><?php echo $visit['temperature'] ? $visit['temperature'] . '°C' : 'N/A'; ?></td>
                                    <td>
                                        <div class="doctor-info">
                                            <div class="doctor-avatar">
                                                <?php echo $visit['doctor_name'] ? strtoupper(substr($visit['doctor_name'], 0, 2)) : 'N/A'; ?>
                                            </div>
                                            <span><?php echo $visit['doctor_name'] ? htmlspecialchars(explode(' ', $visit['doctor_name'])[0]) : 'N/A'; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="action-btn-small" onclick="viewVisitDetails(<?php echo $visit['id']; ?>)">View</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="add-patient.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    <path d="M20 4L22 6L20 8"/>
                                    <path d="M22 4L20 6L22 8"/>
                                </svg>
                            </div>
                            <span>Add Student</span>
                        </a>
                        <a href="record-visit.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                    <path d="M8 2V6"/>
                                    <path d="M16 2V6"/>
                                </svg>
                            </div>
                            <span>Record Visit</span>
                        </a>
                        <a href="clearance-requests.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                                </svg>
                            </div>
                            <span>Clearance</span>
                        </a>
                        <a href="inventory.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                                </svg>
                            </div>
                            <span>Inventory</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize Chart
        const ctx = document.getElementById('visitsChart').getContext('2d');
        let visitsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($days); ?>,
                datasets: [{
                    label: 'Number of Visits',
                    data: <?php echo json_encode($counts); ?>,
                    borderColor: '#191970',
                    backgroundColor: 'rgba(25, 25, 112, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#191970',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#191970',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#cfd8dc',
                            drawBorder: false
                        },
                        ticks: {
                            stepSize: 1,
                            color: '#546e7a'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#546e7a'
                        }
                    }
                }
            }
        });

        // Update chart function
        function updateChart(period) {
            // In a real application, you would fetch new data via AJAX
            // For now, we'll just update the active button state
            document.querySelectorAll('.period-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // You could implement AJAX here to fetch different time periods
            if (period === 'month') {
                // Fetch monthly data
                console.log('Fetching monthly data...');
            }
        }

        // View visit details
        function viewVisitDetails(visitId) {
            window.location.href = 'visit-details.php?id=' + visitId;
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

        // Auto-refresh data every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000);

        // Real-time notifications (simplified)
        function checkForAlerts() {
            <?php if ($stats['low_stock'] > 0): ?>
            // Show notification for low stock
            if (Notification.permission === 'granted') {
                new Notification('Low Stock Alert', {
                    body: '<?php echo $stats['low_stock']; ?> items are running low on stock',
                    icon: '../assets/images/icon.png'
                });
            }
            <?php endif; ?>
        }

        // Request notification permission
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // Check for alerts on load
        window.addEventListener('load', checkForAlerts);
    </script>
</body>
</html>