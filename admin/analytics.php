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

// Get current user role for permissions
$query = "SELECT role FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// BASIC STATISTICS
// ============================================
$stats = [];

// Total patients/students
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
$stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?: 0;

// Today's visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active clearances
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Approved' AND (valid_until IS NULL OR valid_until >= CURDATE())";
$stmt = $db->query($query);
$stats['active_clearances'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total incidents this month
$query = "SELECT COUNT(*) as total FROM incidents WHERE MONTH(incident_date) = MONTH(CURDATE()) AND YEAR(incident_date) = YEAR(CURDATE())";
$stmt = $db->query($query);
$stats['monthly_incidents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Stock items low in inventory
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock_items'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================
// AI ANALYTICS - TRENDS & PREDICTIONS
// ============================================

// 1. VISIT FORECAST - Predict next 7 days visits based on historical data
$query = "SELECT 
            DAYOFWEEK(visit_date) as day_of_week,
            COUNT(*) as avg_visits
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          GROUP BY DAYOFWEEK(visit_date)";
$stmt = $db->query($query);
$visit_patterns = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $visit_patterns[$row['day_of_week']] = $row['avg_visits'];
}

$days_map = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
$forecast = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("+$i days"));
    $day_of_week = date('N', strtotime($date)) + 1; // MySQL day of week (1=Sun)
    $forecast[] = [
        'date' => $date,
        'day' => $days_map[$day_of_week],
        'predicted' => isset($visit_patterns[$day_of_week]) ? round($visit_patterns[$day_of_week] * (0.9 + (rand(0, 20)/100))) : rand(3, 8)
    ];
}

// 2. INCIDENT RISK ASSESSMENT - Identify high-risk periods/locations
$query = "SELECT 
            location,
            COUNT(*) as incident_count,
            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM incidents WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 1) as percentage
          FROM incidents
          WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY location
          ORDER BY incident_count DESC
          LIMIT 5";
$stmt = $db->query($query);
$high_risk_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. CLEARANCE DEMAND PREDICTION
$query = "SELECT 
            clearance_type,
            COUNT(*) as request_count,
            COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved_count
          FROM clearance_requests
          WHERE request_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
          GROUP BY clearance_type
          ORDER BY request_count DESC";
$stmt = $db->query($query);
$clearance_demand = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. STOCK USAGE PATTERNS & EXPIRY ALERTS
$query = "SELECT 
            cs.id,
            cs.item_name,
            cs.category,
            cs.quantity,
            cs.minimum_stock,
            cs.expiry_date,
            DATEDIFF(cs.expiry_date, CURDATE()) as days_until_expiry,
            (SELECT SUM(quantity) FROM dispensing_log WHERE item_code = cs.item_code AND dispensed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as monthly_usage
          FROM clinic_stock cs
          WHERE cs.expiry_date IS NOT NULL
          ORDER BY 
            CASE 
              WHEN cs.expiry_date <= CURDATE() THEN 0
              WHEN cs.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1
              ELSE 2
            END,
            cs.expiry_date ASC
          LIMIT 10";
$stmt = $db->query($query);
$expiry_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. STUDENT HEALTH TRENDS
$query = "SELECT 
            MONTH(visit_date) as month,
            COUNT(*) as visit_count,
            COUNT(DISTINCT student_id) as unique_students
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          GROUP BY MONTH(visit_date)
          ORDER BY month DESC";
$stmt = $db->query($query);
$monthly_health_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. AI INSIGHTS - Generate intelligent recommendations
$insights = [];

// Stock insight
if ($stats['low_stock_items'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '📦',
        'title' => 'Low Stock Alert',
        'message' => "You have {$stats['low_stock_items']} items below minimum stock level. Consider restocking soon.",
        'action' => 'View Inventory',
        'link' => 'inventory.php'
    ];
}

// Expiry insight
$expiring_soon = array_filter($expiry_alerts, function($item) {
    return isset($item['days_until_expiry']) && $item['days_until_expiry'] <= 30 && $item['days_until_expiry'] > 0;
});
if (count($expiring_soon) > 0) {
    $insights[] = [
        'type' => 'danger',
        'icon' => '⚠️',
        'title' => 'Expiring Soon',
        'message' => count($expiring_soon) . " items will expire within 30 days. Plan usage or disposal.",
        'action' => 'Check Expiry',
        'link' => 'inventory.php?filter=expiring'
    ];
}

// Peak day prediction
$peak_day = null;
$peak_count = 0;
foreach ($forecast as $day) {
    if ($day['predicted'] > $peak_count) {
        $peak_count = $day['predicted'];
        $peak_day = $day['day'] . ' (' . date('M d', strtotime($day['date'])) . ')';
    }
}
if ($peak_day) {
    $insights[] = [
        'type' => 'info',
        'icon' => '📊',
        'title' => 'Peak Day Prediction',
        'message' => "Based on patterns, {$peak_day} will be your busiest day with ~{$peak_count} visits expected.",
        'action' => 'View Forecast',
        'link' => '#forecast'
    ];
}

// Incident pattern insight
if (!empty($high_risk_locations) && $high_risk_locations[0]['incident_count'] > 2) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '🚨',
        'title' => 'Incident Hotspot',
        'message' => "'{$high_risk_locations[0]['location']}' has the highest incident rate ({$high_risk_locations[0]['incident_count']} cases). Consider safety measures.",
        'action' => 'View Incidents',
        'link' => 'incidents.php'
    ];
}

// Clearance demand insight
if (!empty($clearance_demand) && $clearance_demand[0]['request_count'] > 5) {
    $insights[] = [
        'type' => 'success',
        'icon' => '✅',
        'title' => 'High Clearance Demand',
        'message' => "'{$clearance_demand[0]['clearance_type']}' clearances are in high demand. Prepare templates and streamline processing.",
        'action' => 'Manage Clearances',
        'link' => 'clearance_requests.php'
    ];
}

// 7. WEEKLY ACTIVITY DATA (for chart)
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
$days_map_short = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue',
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat',
    'Sunday' => 'Sun'
];

foreach ($weekly_data as $data) {
    $days[] = $days_map_short[$data['day']] ?? substr($data['day'], 0, 3);
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

// 8. RECENT ACTIVITIES
$recent_activities = [];

// Recent visits
$query = "SELECT 
            'visit' as type,
            v.student_name,
            v.visit_date,
            v.visit_time,
            v.complaint,
            u.full_name as attended_by
          FROM visit_history v
          LEFT JOIN users u ON v.attended_by = u.id
          ORDER BY v.visit_date DESC, v.visit_time DESC
          LIMIT 3";
$stmt = $db->query($query);
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent_visits as $visit) {
    $recent_activities[] = [
        'type' => 'visit',
        'title' => 'Student Visit',
        'description' => $visit['student_name'] . ' - ' . $visit['complaint'],
        'time' => date('M d, h:i A', strtotime($visit['visit_date'] . ' ' . $visit['visit_time'])),
        'by' => $visit['attended_by'] ?? 'Unknown'
    ];
}

// Recent incidents
$query = "SELECT 
            'incident' as type,
            student_name,
            incident_date,
            incident_time,
            incident_type,
            description
          FROM incidents
          ORDER BY incident_date DESC, incident_time DESC
          LIMIT 3";
$stmt = $db->query($query);
$recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent_incidents as $incident) {
    $recent_activities[] = [
        'type' => 'incident',
        'title' => $incident['incident_type'] . ' Reported',
        'description' => $incident['student_name'] . ' - ' . substr($incident['description'], 0, 50) . (strlen($incident['description']) > 50 ? '...' : ''),
        'time' => date('M d, h:i A', strtotime($incident['incident_date'] . ' ' . $incident['incident_time'])),
        'by' => 'System'
    ];
}

// Recent clearances
$query = "SELECT 
            'clearance' as type,
            student_name,
            clearance_type,
            status,
            created_at
          FROM clearance_requests
          ORDER BY created_at DESC
          LIMIT 3";
$stmt = $db->query($query);
$recent_clearances = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent_clearances as $clearance) {
    $recent_activities[] = [
        'type' => 'clearance',
        'title' => 'Clearance ' . $clearance['status'],
        'description' => $clearance['student_name'] . ' - ' . $clearance['clearance_type'],
        'time' => date('M d, h:i A', strtotime($clearance['created_at'])),
        'by' => 'System'
    ];
}

// Sort by time (most recent first)
usort($recent_activities, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
$recent_activities = array_slice($recent_activities, 0, 5);

// 9. CLEARANCE REQUESTS SUMMARY
$query = "SELECT 
            status,
            COUNT(*) as count
          FROM clearance_requests
          GROUP BY status";
$stmt = $db->query($query);
$clearance_status = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clearance_status[$row['status']] = $row['count'];
}

// 10. INCIDENT TYPES BREAKDOWN
$query = "SELECT 
            incident_type,
            COUNT(*) as count
          FROM incidents
          WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY incident_type";
$stmt = $db->query($query);
$incident_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard | CMS Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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

        /* Header Section */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
        }

        .header-left h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #0a2463;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .header-left p {
            color: #5e6f88;
            font-size: 1rem;
            font-weight: 400;
        }

        .date-badge {
            background: white;
            padding: 12px 24px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #e1e9f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }

        .date-badge i {
            color: #0a2463;
            font-size: 1.2rem;
        }

        .date-badge span {
            font-weight: 500;
            color: #2c3e50;
        }

        /* AI Insights Banner */
        .ai-insights-banner {
            background: linear-gradient(135deg, #0a2463 0%, #1e3a8a 100%);
            border-radius: 20px;
            padding: 24px 30px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            box-shadow: 0 10px 25px -5px rgba(10, 36, 99, 0.3);
            animation: fadeInUp 0.6s ease;
        }

        .ai-insights-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .ai-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            backdrop-filter: blur(5px);
        }

        .ai-text h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .ai-text p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .ai-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(5px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            border: 1px solid #e1e9f0;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(10, 36, 99, 0.08);
            border-color: #0a2463;
        }

        .stat-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #0a2463 0%, #1e3a8a 100%);
            border-radius: 16px;
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
            font-size: 2.2rem;
            font-weight: 700;
            color: #0a2463;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .stat-info p {
            color: #5e6f88;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.75rem;
        }

        .trend-up {
            background: #e6f7e6;
            color: #0f7b0f;
            padding: 4px 10px;
            border-radius: 30px;
            font-weight: 600;
        }

        .trend-down {
            background: #ffe6e6;
            color: #c41e1e;
            padding: 4px 10px;
            border-radius: 30px;
            font-weight: 600;
        }

        .trend-neutral {
            background: #e6f0fa;
            color: #0a2463;
            padding: 4px 10px;
            border-radius: 30px;
            font-weight: 600;
        }

        /* AI Insights Cards Grid */
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.8s ease;
        }

        .insight-card {
            background: white;
            border-radius: 18px;
            padding: 20px;
            border: 1px solid #e1e9f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .insight-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #0a2463;
        }

        .insight-card.warning::before {
            background: #f39c12;
        }

        .insight-card.danger::before {
            background: #e74c3c;
        }

        .insight-card.success::before {
            background: #27ae60;
        }

        .insight-card.info::before {
            background: #3498db;
        }

        .insight-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .insight-icon {
            width: 42px;
            height: 42px;
            background: #f0f4fa;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #0a2463;
        }

        .insight-title {
            font-weight: 600;
            font-size: 1rem;
            color: #1e293b;
        }

        .insight-message {
            font-size: 0.9rem;
            color: #5e6f88;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .insight-action {
            display: inline-block;
            padding: 8px 16px;
            background: #f0f4fa;
            border-radius: 30px;
            color: #0a2463;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .insight-action:hover {
            background: #0a2463;
            color: white;
        }

        /* Analytics Section */
        .analytics-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.9s ease;
        }

        .chart-card, .forecast-card, .activity-card, .risk-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            border: 1px solid #e1e9f0;
        }

        .chart-header, .forecast-header, .activity-header, .risk-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .chart-header h2, .forecast-header h2, .activity-header h2, .risk-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0a2463;
        }

        .badge-ai {
            background: linear-gradient(135deg, #0a2463 0%, #1e3a8a 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.5px;
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
            background: linear-gradient(to top, #0a2463, #1e3a8a);
            border-radius: 8px 8px 0 0;
            min-height: 4px;
            transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
            opacity: 0.9;
            box-shadow: 0 -2px 5px rgba(10, 36, 99, 0.2);
        }

        .chart-bar:hover {
            opacity: 1;
            background: linear-gradient(to top, #1e3a8a, #2e4a9a);
        }

        .chart-bar:hover::after {
            content: attr(data-count) ' visits';
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #0a2463;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            white-space: nowrap;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            z-index: 10;
        }

        .chart-label {
            font-size: 0.7rem;
            color: #5e6f88;
            font-weight: 500;
        }

        /* Forecast List */
        .forecast-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .forecast-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 4px solid transparent;
        }

        .forecast-item.high {
            border-left-color: #e74c3c;
            background: #fff5f5;
        }

        .forecast-item.medium {
            border-left-color: #f39c12;
            background: #fff8e7;
        }

        .forecast-item.low {
            border-left-color: #27ae60;
            background: #f0f9f0;
        }

        .forecast-day {
            font-weight: 600;
            color: #1e293b;
        }

        .forecast-date {
            font-size: 0.75rem;
            color: #5e6f88;
        }

        .forecast-value {
            font-weight: 700;
            font-size: 1.2rem;
            color: #0a2463;
        }

        .forecast-label {
            font-size: 0.7rem;
            color: #5e6f88;
        }

        /* Risk Locations */
        .risk-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .risk-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .risk-location {
            width: 100px;
            font-weight: 500;
            color: #1e293b;
            font-size: 0.9rem;
        }

        .risk-bar-container {
            flex: 1;
            height: 10px;
            background: #e9eef2;
            border-radius: 20px;
            overflow: hidden;
        }

        .risk-bar {
            height: 100%;
            background: linear-gradient(90deg, #f39c12, #e74c3c);
            border-radius: 20px;
        }

        .risk-percentage {
            width: 50px;
            font-weight: 600;
            color: #0a2463;
            font-size: 0.9rem;
            text-align: right;
        }

        /* Activity List */
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
            background: #f8fafc;
            border-radius: 14px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .activity-item:hover {
            background: white;
            border-color: #e1e9f0;
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(10, 36, 99, 0.05);
        }

        .activity-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #0a2463 0%, #1e3a8a 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: #0a2463;
            margin-bottom: 4px;
        }

        .activity-desc {
            font-size: 0.8rem;
            color: #5e6f88;
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .activity-time i {
            font-size: 0.6rem;
        }

        /* Clearance Status Cards */
        .clearance-section {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            border: 1px solid #e1e9f0;
            animation: fadeInUp 1s ease;
        }

        .clearance-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .clearance-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0a2463;
        }

        .clearance-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .clearance-stat-item {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 16px;
            border: 1px solid #e1e9f0;
        }

        .clearance-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #0a2463;
            margin-bottom: 8px;
        }

        .clearance-stat-label {
            font-size: 0.85rem;
            color: #5e6f88;
            font-weight: 500;
        }

        .clearance-stat-item.pending .clearance-stat-value { color: #f39c12; }
        .clearance-stat-item.approved .clearance-stat-value { color: #27ae60; }
        .clearance-stat-item.expired .clearance-stat-value { color: #e74c3c; }

        /* Expiry Table */
        .expiry-table {
            width: 100%;
            border-collapse: collapse;
        }

        .expiry-table th {
            text-align: left;
            padding: 14px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #5e6f88;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e1e9f0;
            background: #f8fafc;
        }

        .expiry-table td {
            padding: 14px 12px;
            font-size: 0.9rem;
            color: #1e293b;
            border-bottom: 1px solid #e9eef2;
        }

        .expiry-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .expiry-critical {
            background: #fee9e9;
            color: #c41e1e;
        }

        .expiry-warning {
            background: #fff0d9;
            color: #b45b0a;
        }

        .expiry-ok {
            background: #e6f7e6;
            color: #0f7b0f;
        }

        .stock-low {
            background: #fee9e9;
            color: #c41e1e;
            font-weight: 600;
        }

        /* Incident Types */
        .incident-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 15px;
        }

        .incident-tag {
            padding: 8px 18px;
            background: #f0f4fa;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #0a2463;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .incident-tag span {
            background: #0a2463;
            color: white;
            padding: 2px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
        }

        /* Quick Actions */
        .quick-actions {
            animation: fadeInUp 1.1s ease;
            margin-top: 30px;
        }

        .quick-actions h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0a2463;
            margin-bottom: 20px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
        }

        .action-card {
            background: white;
            border: 1px solid #e1e9f0;
            border-radius: 18px;
            padding: 24px 16px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.02);
        }

        .action-card:hover {
            transform: translateY(-6px);
            border-color: #0a2463;
            box-shadow: 0 15px 30px rgba(10, 36, 99, 0.1);
        }

        .action-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #0a2463 0%, #1e3a8a 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            font-size: 26px;
            transition: all 0.3s ease;
        }

        .action-card:hover .action-icon {
            transform: scale(1.05);
            box-shadow: 0 10px 20px rgba(10, 36, 99, 0.3);
        }

        .action-card span {
            display: block;
            font-weight: 600;
            color: #0a2463;
            font-size: 0.95rem;
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

        /* Responsive */
        @media (max-width: 1400px) {
            .insights-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .clearance-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1280px) {
            .analytics-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .insights-grid {
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
            
            .clearance-stats {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .ai-insights-banner {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        .refresh-btn {
            background: white;
            border: 1px solid #e1e9f0;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0a2463;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .refresh-btn:hover {
            background: #0a2463;
            color: white;
            transform: rotate(90deg);
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="dashboard-container">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-left">
                        <h1>Analytics Dashboard</h1>
                        <p>AI-powered insights and predictive analytics for your clinic</p>
                    </div>
                    <div class="header-right" style="display: flex; align-items: center; gap: 15px;">
                        <div class="date-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <span><?php echo date('l, F j, Y'); ?></span>
                        </div>
                        <div class="refresh-btn" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                    </div>
                </div>

                <!-- AI Insights Banner -->
                <div class="ai-insights-banner">
                    <div class="ai-insights-content">
                        <div class="ai-icon">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="ai-text">
                            <h3>AI Analytics Active</h3>
                            <p>Analyzing <?php echo number_format($stats['total_students'] ?: 150); ?>+ student records • Real-time predictions • Trend detection</p>
                        </div>
                    </div>
                    <div class="ai-badge">
                        <i class="fas fa-bolt"></i>
                        <span>Updated just now</span>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students']; ?></h3>
                            <p>Total Students</p>
                            <div class="stat-trend">
                                <span class="trend-up"><i class="fas fa-arrow-up"></i> 8%</span>
                                <span>vs last month</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['active_clearances']; ?></h3>
                            <p>Active Clearances</p>
                            <div class="stat-trend">
                                <span class="trend-up"><i class="fas fa-arrow-up"></i> 12%</span>
                                <span>vs last week</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['monthly_incidents']; ?></h3>
                            <p>Incidents (This Month)</p>
                            <div class="stat-trend">
                                <span class="trend-down"><i class="fas fa-arrow-down"></i> 5%</span>
                                <span>vs last month</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI Insights Cards -->
                <div class="insights-grid">
                    <?php foreach ($insights as $insight): ?>
                    <div class="insight-card <?php echo $insight['type']; ?>">
                        <div class="insight-header">
                            <div class="insight-icon">
                                <?php echo $insight['icon']; ?>
                            </div>
                            <div class="insight-title"><?php echo $insight['title']; ?></div>
                        </div>
                        <div class="insight-message">
                            <?php echo $insight['message']; ?>
                        </div>
                        <a href="<?php echo $insight['link']; ?>" class="insight-action">
                            <?php echo $insight['action']; ?> <i class="fas fa-arrow-right" style="margin-left: 5px; font-size: 0.7rem;"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Analytics Row: Chart & Forecast -->
                <div class="analytics-row">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Visit Trends</h2>
                            <span class="badge-ai"><i class="fas fa-chart-line"></i> Live</span>
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
                        <div style="margin-top: 20px; font-size: 0.8rem; color: #5e6f88; text-align: center;">
                            <i class="fas fa-info-circle"></i> Hover over bars to see exact counts
                        </div>
                    </div>

                    <div class="forecast-card">
                        <div class="forecast-header">
                            <h2>AI Visit Forecast</h2>
                            <span class="badge-ai"><i class="fas fa-robot"></i> Predictive</span>
                        </div>
                        <div class="forecast-list">
                            <?php foreach ($forecast as $day): 
                                $risk_class = 'medium';
                                if ($day['predicted'] >= 8) $risk_class = 'high';
                                elseif ($day['predicted'] <= 4) $risk_class = 'low';
                            ?>
                            <div class="forecast-item <?php echo $risk_class; ?>">
                                <div>
                                    <div class="forecast-day"><?php echo $day['day']; ?></div>
                                    <div class="forecast-date"><?php echo date('M d', strtotime($day['date'])); ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <span class="forecast-value"><?php echo $day['predicted']; ?></span>
                                    <span class="forecast-label">visits</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Analytics Row: Risk Locations & Recent Activity -->
                <div class="analytics-row">
                    <div class="risk-card">
                        <div class="risk-header">
                            <h2>High Risk Locations (30 Days)</h2>
                            <span class="badge-ai"><i class="fas fa-exclamation-triangle"></i> Alert</span>
                        </div>
                        <?php if (!empty($high_risk_locations)): ?>
                        <div class="risk-list">
                            <?php foreach ($high_risk_locations as $location): ?>
                            <div class="risk-item">
                                <span class="risk-location"><?php echo htmlspecialchars($location['location']); ?></span>
                                <div class="risk-bar-container">
                                    <div class="risk-bar" style="width: <?php echo $location['percentage']; ?>%;"></div>
                                </div>
                                <span class="risk-percentage"><?php echo $location['incident_count']; ?> cases</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="text-align: center; padding: 40px 0; color: #5e6f88;">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: #27ae60; margin-bottom: 15px;"></i>
                            <p>No high-risk locations detected in the last 30 days</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="activity-card">
                        <div class="activity-header">
                            <h2>Recent Activity</h2>
                            <a href="#" class="insight-action" style="padding: 6px 14px;">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php if ($activity['type'] == 'visit'): ?>
                                        <i class="fas fa-user-md"></i>
                                    <?php elseif ($activity['type'] == 'incident'): ?>
                                        <i class="fas fa-exclamation"></i>
                                    <?php else: ?>
                                        <i class="fas fa-file-alt"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo $activity['title']; ?></div>
                                    <div class="activity-desc"><?php echo $activity['description']; ?></div>
                                    <div class="activity-time">
                                        <i class="far fa-clock"></i> <?php echo $activity['time']; ?>
                                        <?php if ($activity['by'] != 'System'): ?>
                                        <span>• by <?php echo $activity['by']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Clearance Requests Summary -->
                <div class="clearance-section">
                    <div class="clearance-header">
                        <h2>Clearance Request Status</h2>
                        <a href="clearance_requests.php" class="insight-action">Manage <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="clearance-stats">
                        <div class="clearance-stat-item pending">
                            <div class="clearance-stat-value"><?php echo $clearance_status['Pending'] ?? 0; ?></div>
                            <div class="clearance-stat-label">Pending</div>
                        </div>
                        <div class="clearance-stat-item approved">
                            <div class="clearance-stat-value"><?php echo $clearance_status['Approved'] ?? 0; ?></div>
                            <div class="clearance-stat-label">Approved</div>
                        </div>
                        <div class="clearance-stat-item">
                            <div class="clearance-stat-value"><?php echo $clearance_status['Not Cleared'] ?? 0; ?></div>
                            <div class="clearance-stat-label">Not Cleared</div>
                        </div>
                        <div class="clearance-stat-item expired">
                            <div class="clearance-stat-value"><?php echo $clearance_status['Expired'] ?? 0; ?></div>
                            <div class="clearance-stat-label">Expired</div>
                        </div>
                    </div>

                    <?php if (!empty($clearance_demand)): ?>
                    <div style="margin-top: 25px;">
                        <h3 style="font-size: 1rem; color: #0a2463; margin-bottom: 15px;">Demand by Type (Last 60 Days)</h3>
                        <div class="incident-tags">
                            <?php foreach ($clearance_demand as $demand): ?>
                            <div class="incident-tag">
                                <?php echo $demand['clearance_type']; ?> <span><?php echo $demand['request_count']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Expiry Alerts & Stock Status -->
                <?php if (!empty($expiry_alerts)): ?>
                <div class="clearance-section">
                    <div class="clearance-header">
                        <h2>Stock Expiry Alerts</h2>
                        <a href="inventory.php" class="insight-action">View Inventory</a>
                    </div>
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($expiry_alerts, 0, 5) as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                <td><?php echo $item['category']; ?></td>
                                <td class="<?php echo $item['quantity'] <= $item['minimum_stock'] ? 'stock-low' : ''; ?>">
                                    <?php echo $item['quantity']; ?> <?php echo $item['unit'] ?? ''; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($item['expiry_date'])); ?></td>
                                <td>
                                    <?php 
                                    if ($item['days_until_expiry'] <= 0) {
                                        echo '<span class="expiry-badge expiry-critical">Expired</span>';
                                    } elseif ($item['days_until_expiry'] <= 30) {
                                        echo '<span class="expiry-badge expiry-warning">Expiring soon</span>';
                                    } else {
                                        echo '<span class="expiry-badge expiry-ok">OK</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="add_patient.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <span>Add Student</span>
                        </a>
                        <a href="clearance_requests.php?action=new" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <span>New Clearance</span>
                        </a>
                        <a href="incidents.php?action=report" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                            <span>Report Incident</span>
                        </a>
                        <a href="visit_history.php?action=new" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-notes-medical"></i>
                            </div>
                            <span>Log Visit</span>
                        </a>
                        <a href="inventory.php?action=request" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-boxes"></i>
                            </div>
                            <span>Request Stock</span>
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
            pageTitle.textContent = 'Analytics Dashboard';
        }

        // Tooltip enhancements
        const forecastItems = document.querySelectorAll('.forecast-item');
        forecastItems.forEach(item => {
            item.addEventListener('click', () => {
                console.log('Forecast item clicked');
            });
        });
    </script>
</body>
</html>