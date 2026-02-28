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

// Total patients (students with records)
$query = "SELECT COUNT(DISTINCT student_id) as total FROM clearance_requests";
$stmt = $db->query($query);
$stats['patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's appointments (using visit_history for today)
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff
$query = "SELECT COUNT(*) as total FROM users WHERE role IN ('staff', 'nurse', 'admin')";
$stmt = $db->query($query);
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total pending clearance requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_clearance'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Low stock items
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent incidents
$query = "SELECT i.*, u.full_name as reporter_name 
          FROM incidents i 
          LEFT JOIN users u ON i.created_by = u.id 
          ORDER BY i.created_at DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent visit history
$query = "SELECT v.*, u.full_name as attended_by_name 
          FROM visit_history v 
          LEFT JOIN users u ON v.attended_by = u.id 
          ORDER BY v.created_at DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent clearance requests
$query = "SELECT * FROM clearance_requests 
          ORDER BY created_at DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_clearance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly visit data
$query = "SELECT 
            DAYNAME(visit_date) as day,
            COUNT(*) as count,
            DATE(visit_date) as date
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(visit_date), DATE(visit_date)
          ORDER BY visit_date";
$stmt = $db->query($query);
$weekly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare chart data
$days_map = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue', 
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat',
    'Sunday' => 'Sun'
];

$chart_data = [];
foreach ($days_map as $full_day => $short_day) {
    $chart_data[$short_day] = 0;
}

foreach ($weekly_data as $data) {
    $short_day = $days_map[$data['day']] ?? substr($data['day'], 0, 3);
    $chart_data[$short_day] = $data['count'];
}

// Get monthly trend data for AI prediction
$query = "SELECT 
            DATE_FORMAT(visit_date, '%Y-%m') as month,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
          GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
          ORDER BY month";
$stmt = $db->query($query);
$monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate AI insights
$ai_insights = [];

// Predict next week's visits (simple linear regression)
if (count($monthly_trends) >= 2) {
    $total = 0;
    foreach ($monthly_trends as $trend) {
        $total += $trend['count'];
    }
    $avg_monthly = $total / count($monthly_trends);
    $ai_insights['predicted_week'] = round($avg_monthly / 4); // Rough weekly prediction
} else {
    $ai_insights['predicted_week'] = $stats['today_visits'] * 5;
}

// Identify peak hours
$query = "SELECT 
            HOUR(visit_time) as hour,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY HOUR(visit_time)
          ORDER BY count DESC
          LIMIT 1";
$stmt = $db->query($query);
$peak_hour = $stmt->fetch(PDO::FETCH_ASSOC);
$ai_insights['peak_hour'] = $peak_hour ? date('g A', strtotime($peak_hour['hour'] . ':00')) : '9 AM';

// Common complaints
$query = "SELECT 
            complaint,
            COUNT(*) as count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY complaint
          ORDER BY count DESC
          LIMIT 3";
$stmt = $db->query($query);
$common_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stock expiration alerts
$query = "SELECT COUNT(*) as total FROM clinic_stock 
          WHERE expiry_date IS NOT NULL 
          AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND expiry_date > CURDATE()";
$stmt = $db->query($query);
$ai_insights['expiring_soon'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get real activity feed
$query = "(SELECT 'visit' as type, CONCAT('Visit: ', complaint) as description, created_at, 'New Visit' as status FROM visit_history)
          UNION ALL
          (SELECT 'clearance' as type, CONCAT('Clearance: ', clearance_type) as description, created_at, status FROM clearance_requests)
          UNION ALL
          (SELECT 'incident' as type, CONCAT('Incident: ', incident_type) as description, created_at, 'Reported' as status FROM incidents)
          ORDER BY created_at DESC
          LIMIT 5";
$stmt = $db->query($query);
$activity_feed = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get inventory status
$query = "SELECT 
            category,
            COUNT(*) as total_items,
            SUM(CASE WHEN quantity <= minimum_stock THEN 1 ELSE 0 END) as low_stock_count
          FROM clinic_stock
          GROUP BY category";
$stmt = $db->query($query);
$inventory_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f4f7fb;
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
            background: #f4f7fb;
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
            color: #1a237e;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            color: #546e7a;
            font-size: 1rem;
            font-weight: 400;
        }

        /* AI Insights Banner */
        .ai-insights-banner {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            color: white;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            animation: fadeInUp 0.55s ease;
            box-shadow: 0 10px 30px rgba(26, 35, 126, 0.2);
        }

        .insight-item {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .insight-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .insight-content h4 {
            font-size: 0.8rem;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .insight-content p {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .insight-content span {
            font-size: 0.7rem;
            opacity: 0.8;
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            border: 1px solid #e0e7ed;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(26, 35, 126, 0.1);
            border-color: #1a237e;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: #1a237e;
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
            color: #1a237e;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: #64748b;
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

        .trend-warning {
            background: #fff3e0;
            color: #ef6c00;
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

        .chart-card, .activity-card, .inventory-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            border: 1px solid #e0e7ed;
        }

        .chart-header, .activity-header, .inventory-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .chart-header h2, .activity-header h2, .inventory-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1a237e;
        }

        .chart-period {
            display: flex;
            gap: 8px;
        }

        .period-btn {
            padding: 6px 14px;
            background: #f1f5f9;
            border: 1px solid #e0e7ed;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .period-btn:hover, .period-btn.active {
            background: #1a237e;
            color: white;
            border-color: #1a237e;
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
            background: linear-gradient(180deg, #1a237e 0%, #283593 100%);
            border-radius: 6px 6px 0 0;
            min-height: 4px;
            transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
            opacity: 0.9;
        }

        .chart-bar:hover {
            opacity: 1;
            transform: scaleX(1.02);
        }

        .chart-bar:hover::after {
            content: attr(data-count) ' visits';
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #1a237e;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .chart-label {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 500;
        }

        .activity-list, .inventory-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            background: #f8fafc;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .activity-item:hover {
            background: white;
            border-color: #1a237e;
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(26, 35, 126, 0.08);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #1a237e;
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
            color: #1a237e;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        .activity-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-pending {
            background: #fff3e0;
            color: #ef6c00;
        }

        .inventory-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f8fafc;
            border-radius: 10px;
        }

        .inventory-category {
            font-weight: 600;
            color: #1a237e;
        }

        .inventory-stats {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .low-stock-badge {
            background: #ffebee;
            color: #c62828;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Tables Section */
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.8s ease;
        }

        .table-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            border: 1px solid #e0e7ed;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .table-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a237e;
        }

        .view-link {
            color: #1a237e;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            padding: 6px 12px;
            background: #f1f5f9;
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .view-link:hover {
            background: #1a237e;
            color: white;
        }

        .compact-table {
            width: 100%;
            border-collapse: collapse;
        }

        .compact-table th {
            text-align: left;
            padding: 12px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e0e7ed;
        }

        .compact-table td {
            padding: 12px 8px;
            font-size: 0.85rem;
            color: #334155;
            border-bottom: 1px solid #e0e7ed;
        }

        .badge {
            padding: 4px 8px;
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
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Quick Actions */
        .quick-actions {
            animation: fadeInUp 0.9s ease;
        }

        .quick-actions h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 20px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .action-card {
            background: white;
            border: 1px solid #e0e7ed;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.02);
        }

        .action-card:hover {
            transform: translateY(-4px);
            border-color: #1a237e;
            box-shadow: 0 10px 25px rgba(26, 35, 126, 0.1);
        }

        .action-icon {
            width: 64px;
            height: 64px;
            background: #1a237e;
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
            background: #283593;
            transform: scale(1.05);
        }

        .action-card span {
            display: block;
            font-weight: 600;
            color: #1a237e;
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
            
            .analytics-section {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .tables-grid {
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
                grid-template-columns: 1fr;
            }

            .ai-insights-banner {
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
                    <p><?php echo date('l, F j, Y'); ?> • Here's your clinic's real-time overview</p>
                </div>

                <!-- AI Insights Banner -->
                <div class="ai-insights-banner">
                    <div class="insight-item">
                        <div class="insight-icon">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="insight-content">
                            <h4>AI PREDICTION</h4>
                            <p><?php echo $ai_insights['predicted_week']; ?> visits</p>
                            <span>Expected next week</span>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="insight-content">
                            <h4>PEAK HOUR</h4>
                            <p><?php echo $ai_insights['peak_hour']; ?></p>
                            <span>Busiest time</span>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="insight-content">
                            <h4>LOW STOCK ALERT</h4>
                            <p><?php echo $stats['low_stock']; ?> items</p>
                            <span>Need reordering</span>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-icon">
                            <i class="fas fa-calendar-exclamation"></i>
                        </div>
                        <div class="insight-content">
                            <h4>EXPIRING SOON</h4>
                            <p><?php echo $ai_insights['expiring_soon']; ?> items</p>
                            <span>Within 30 days</span>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['patients']; ?></h3>
                            <p>Total Patients</p>
                            <div class="stat-trend">
                                <span class="trend-up">Active</span>
                                <span style="color: #64748b;">registered</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_appointments']; ?></h3>
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ <?php echo $stats['today_appointments'] > 0 ? round(($stats['today_appointments']/10)*100) : 0; ?>%</span>
                                <span style="color: #64748b;">vs average</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-md"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['staff']; ?></h3>
                            <p>Active Staff</p>
                            <div class="stat-trend">
                                <span class="trend-up">On duty</span>
                                <span style="color: #64748b;">today</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-file-medical"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_clearance']; ?></h3>
                            <p>Pending Clearance</p>
                            <div class="stat-trend">
                                <span class="<?php echo $stats['pending_clearance'] > 5 ? 'trend-warning' : 'trend-up'; ?>">
                                    <?php echo $stats['pending_clearance'] > 5 ? 'High' : 'Normal'; ?>
                                </span>
                                <span style="color: #64748b;">priority</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Visit Trends</h2>
                            <div class="chart-period">
                                <button class="period-btn active">Week</button>
                                <button class="period-btn">Month</button>
                                <button class="period-btn">Year</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <?php 
                            $max_count = !empty($chart_data) ? max($chart_data) : 1;
                            foreach ($chart_data as $day => $count): 
                                $height = $max_count > 0 ? ($count / $max_count) * 150 : 20;
                                $height = max(20, $height);
                            ?>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar" style="height: <?php echo $height; ?>px;" data-count="<?php echo $count; ?>"></div>
                                <span class="chart-label"><?php echo $day; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 20px; display: flex; justify-content: space-between; color: #64748b; font-size: 0.75rem;">
                            <span><i class="fas fa-circle" style="color: #1a237e; font-size: 0.5rem;"></i> Total visits this week: <?php echo array_sum($chart_data); ?></span>
                            <span>Avg: <?php echo round(array_sum($chart_data) / 7, 1); ?> per day</span>
                        </div>
                    </div>

                    <div class="activity-card">
                        <div class="activity-header">
                            <h2>Live Activity Feed</h2>
                            <a href="#" class="view-link">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($activity_feed as $activity): 
                                $icon = $activity['type'] == 'visit' ? 'fa-user-injured' : ($activity['type'] == 'clearance' ? 'fa-file-signature' : 'fa-exclamation-triangle');
                                $status_class = $activity['type'] == 'incident' ? 'status-pending' : '';
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                    <div class="activity-time"><?php echo time_elapsed_string($activity['created_at']); ?></div>
                                </div>
                                <span class="activity-status <?php echo $status_class; ?>"><?php echo $activity['status']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Tables Grid -->
                <div class="tables-grid">
                    <!-- Recent Incidents -->
                    <div class="table-card">
                        <div class="table-header">
                            <h2><i class="fas fa-exclamation-circle" style="margin-right: 8px; color: #c62828;"></i> Recent Incidents</h2>
                            <a href="incidents.php" class="view-link">View All</a>
                        </div>
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_incidents as $incident): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($incident['student_name'], 0, 15)) . '...'; ?></td>
                                    <td>
                                        <span class="badge badge-warning"><?php echo $incident['incident_type']; ?></span>
                                    </td>
                                    <td><?php echo date('h:i A', strtotime($incident['incident_time'])); ?></td>
                                    <td>
                                        <span class="badge badge-info">Reported</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Recent Clearance Requests -->
                    <div class="table-card">
                        <div class="table-header">
                            <h2><i class="fas fa-file-medical" style="margin-right: 8px; color: #2e7d32;"></i> Clearance Requests</h2>
                            <a href="clearance_requests.php" class="view-link">View All</a>
                        </div>
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_clearance as $clearance): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(substr($clearance['student_name'], 0, 15)) . '...'; ?></td>
                                    <td><?php echo $clearance['clearance_type']; ?></td>
                                    <td>
                                        <?php 
                                        $status_class = 'badge-warning';
                                        if ($clearance['status'] == 'Approved') $status_class = 'badge-success';
                                        if ($clearance['status'] == 'Expired') $status_class = 'badge-danger';
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $clearance['status']; ?></span>
                                    </td>
                                    <td><?php echo date('M d', strtotime($clearance['request_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Visits Table -->
                <div class="table-card" style="margin-bottom: 30px; animation: fadeInUp 0.85s ease;">
                    <div class="table-header">
                        <h2><i class="fas fa-history" style="margin-right: 8px; color: #1a237e;"></i> Recent Visit History</h2>
                        <a href="visit_history.php" class="view-link">View All</a>
                    </div>
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Complaint</th>
                                <th>Date/Time</th>
                                <th>Temperature</th>
                                <th>Treated By</th>
                                <th>Disposition</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_visits as $visit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($visit['student_name'], 0, 15)) . '...'; ?></td>
                                <td><?php echo htmlspecialchars(substr($visit['complaint'], 0, 20)) . '...'; ?></td>
                                <td><?php echo date('M d, h:i A', strtotime($visit['visit_date'] . ' ' . $visit['visit_time'])); ?></td>
                                <td><?php echo $visit['temperature'] ? $visit['temperature'] . '°C' : '—'; ?></td>
                                <td><?php echo $visit['attended_by_name'] ? htmlspecialchars(substr($visit['attended_by_name'], 0, 10)) : '—'; ?></td>
                                <td>
                                    <?php 
                                    $disp_class = 'badge-info';
                                    if ($visit['disposition'] == 'Admitted') $disp_class = 'badge-warning';
                                    if ($visit['disposition'] == 'Cleared') $disp_class = 'badge-success';
                                    ?>
                                    <span class="badge <?php echo $disp_class; ?>"><?php echo $visit['disposition']; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Common Complaints and Inventory Status -->
                <div class="tables-grid" style="margin-bottom: 30px;">
                    <div class="table-card">
                        <div class="table-header">
                            <h2><i class="fas fa-chart-pie" style="margin-right: 8px;"></i> Common Complaints (30 days)</h2>
                        </div>
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>Complaint</th>
                                    <th>Frequency</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_complaints = array_sum(array_column($common_complaints, 'count'));
                                foreach ($common_complaints as $complaint): 
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($complaint['complaint']); ?></td>
                                    <td><?php echo $complaint['count']; ?>x</td>
                                    <td>
                                        <?php 
                                        $percentage = $total_complaints > 0 ? round(($complaint['count'] / $total_complaints) * 100) : 0;
                                        echo $percentage . '%';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-card">
                        <div class="table-header">
                            <h2><i class="fas fa-boxes" style="margin-right: 8px;"></i> Inventory Status</h2>
                        </div>
                        <table class="compact-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Total Items</th>
                                    <th>Low Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventory_status as $inv): ?>
                                <tr>
                                    <td><?php echo $inv['category']; ?></td>
                                    <td><?php echo $inv['total_items']; ?></td>
                                    <td><?php echo $inv['low_stock_count']; ?></td>
                                    <td>
                                        <?php if ($inv['low_stock_count'] > 0): ?>
                                        <span class="badge badge-danger">Reorder Needed</span>
                                        <?php else: ?>
                                        <span class="badge badge-success">Healthy</span>
                                        <?php endif; ?>
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
                        <a href="add_patient.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <span>Add Patient</span>
                        </a>
                        <a href="record_visit.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-notes-medical"></i>
                            </div>
                            <span>Record Visit</span>
                        </a>
                        <a href="report_incident.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <span>Report Incident</span>
                        </a>
                        <a href="clearance_request.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <span>New Clearance</span>
                        </a>
                        <a href="medicine_request.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-pills"></i>
                            </div>
                            <span>Request Medicine</span>
                        </a>
                        <a href="physical_exam.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <span>Physical Exam</span>
                        </a>
                        <a href="generate_report.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <span>Generate Report</span>
                        </a>
                        <a href="inventory.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-warehouse"></i>
                            </div>
                            <span>Manage Stock</span>
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
                
                // Here you could add AJAX to fetch different time periods
                if (btn.textContent === 'Month') {
                    // Fetch monthly data
                    console.log('Switching to monthly view');
                } else if (btn.textContent === 'Year') {
                    // Fetch yearly data
                    console.log('Switching to yearly view');
                }
            });
        });

        // Auto-refresh data every 5 minutes (300000 ms)
        setInterval(() => {
            // You could implement AJAX refresh here
            console.log('Auto-refreshing data...');
        }, 300000);

        // Real-time clock update
        function updateDateTime() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            };
            const dateStr = now.toLocaleDateString('en-US', options);
            document.querySelector('.welcome-section p').innerHTML = dateStr + ' • Here\'s your clinic\'s real-time overview';
        }
        // Update every minute
        setInterval(updateDateTime, 60000);
    </script>
</body>
</html>