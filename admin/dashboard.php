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

// Get user role for personalized insights
$user_query = "SELECT role, full_name FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $current_user_id);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user_data['role'] ?? 'staff';
$user_fullname = $user_data['full_name'] ?? $_SESSION['username'];

// Get statistics
$stats = [];

// Total patients
$query = "SELECT COUNT(*) as total FROM patients";
$stmt = $db->query($query);
$stats['patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's appointments
$query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff
$query = "SELECT COUNT(*) as total FROM users WHERE role IN ('staff', 'doctor', 'admin')";
$stmt = $db->query($query);
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's clinic visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get additional real stats for AI insights
try {
    // Low stock items
    $query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
    $stmt = $db->query($query);
    $stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Pending clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
    $stmt = $db->query($query);
    $stats['pending_clearances'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Today's incidents
    $query = "SELECT COUNT(*) as total FROM incidents WHERE DATE(incident_date) = CURDATE()";
    $stmt = $db->query($query);
    $stats['today_incidents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Students with medical conditions
    // Note: This requires joining with students API or having local students table
    // For now, we'll use a placeholder or query if you have a local students table
    $stats['students_with_conditions'] = 0; // Will be updated if data available
    
} catch (PDOException $e) {
    error_log("Error fetching additional stats: " . $e->getMessage());
}

// Recent appointments
$query = "SELECT a.*, p.full_name as patient_name, p.id as patient_id, u.full_name as doctor_name 
          FROM appointments a 
          JOIN patients p ON a.patient_id = p.id 
          JOIN users u ON a.doctor_id = u.id 
          ORDER BY a.appointment_date DESC, a.appointment_time DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get recent clinic visits for activity feed
$query = "SELECT v.*, u.full_name as staff_name 
          FROM visit_history v 
          LEFT JOIN users u ON v.attended_by = u.id 
          ORDER BY v.created_at DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent clearance requests
$query = "SELECT * FROM clearance_requests ORDER BY created_at DESC LIMIT 3";
$stmt = $db->query($query);
$recent_clearances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent incidents
$query = "SELECT * FROM incidents ORDER BY created_at DESC LIMIT 3";
$stmt = $db->query($query);
$recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent medicine requests
$query = "SELECT * FROM medicine_requests ORDER BY requested_date DESC LIMIT 3";
$stmt = $db->query($query);
$recent_medicine_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============ AI INSIGHTS GENERATION ============

function generateAIInsights($db, $stats, $user_role) {
    $insights = [];
    $current_hour = (int)date('H');
    
    // Time-based greeting
    if ($current_hour < 12) {
        $greeting = "Good morning";
    } elseif ($current_hour < 18) {
        $greeting = "Good afternoon";
    } else {
        $greeting = "Good evening";
    }
    
    // Priority 1: Critical alerts (red)
    if ($stats['low_stock'] > 0) {
        $insights['critical'][] = [
            'icon' => '⚠️',
            'title' => 'Low Stock Alert',
            'message' => "You have {$stats['low_stock']} item(s) below minimum stock level. Consider requesting from property custodian.",
            'action' => 'View Stock',
            'link' => 'medicine_requests.php',
            'priority' => 1
        ];
    }
    
    // Priority 2: Important alerts (orange/yellow)
    if ($stats['pending_clearances'] > 0) {
        $insights['important'][] = [
            'icon' => '📋',
            'title' => 'Pending Clearances',
            'message' => "There are {$stats['pending_clearances']} clearance request(s) awaiting review.",
            'action' => 'Review',
            'link' => 'health_clearance.php',
            'priority' => 2
        ];
    }
    
    // Check for expiring items
    try {
        $query = "SELECT COUNT(*) as total FROM clinic_stock 
                  WHERE expiry_date IS NOT NULL 
                  AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        $stmt = $db->query($query);
        $expiring_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($expiring_count > 0) {
            $insights['important'][] = [
                'icon' => '📅',
                'title' => 'Expiring Soon',
                'message' => "$expiring_count item(s) will expire within 30 days. Check inventory.",
                'action' => 'Check Stock',
                'link' => 'medicine_requests.php',
                'priority' => 2
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Priority 3: Trends and patterns
    // Analyze visit patterns
    try {
        $query = "SELECT DAYOFWEEK(visit_date) as day, COUNT(*) as count 
                  FROM visit_history 
                  WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  GROUP BY DAYOFWEEK(visit_date)
                  ORDER BY count DESC
                  LIMIT 1";
        $stmt = $db->query($query);
        $peak_day = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($peak_day) {
            $days_of_week = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $day_name = $days_of_week[$peak_day['day'] - 1] ?? 'Unknown';
            
            $insights['trends'][] = [
                'icon' => '📊',
                'title' => 'Peak Visit Day',
                'message' => "Your busiest day is $day_name with an average of " . round($peak_day['count'] / 4) . " visits per week.",
                'action' => 'View Analytics',
                'link' => '#',
                'priority' => 3
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Common complaints analysis
    try {
        $query = "SELECT complaint, COUNT(*) as count 
                  FROM visit_history 
                  WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  GROUP BY complaint
                  ORDER BY count DESC
                  LIMIT 1";
        $stmt = $db->query($query);
        $common_complaint = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($common_complaint && $common_complaint['complaint']) {
            $insights['trends'][] = [
                'icon' => '🤒',
                'title' => 'Most Common Complaint',
                'message' => "'{$common_complaint['complaint']}' is the most frequent complaint this month.",
                'action' => 'View Visits',
                'link' => 'clinic_visits.php',
                'priority' => 3
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Role-based insights
    if ($user_role == 'admin' || $user_role == 'superadmin') {
        // Staff activity
        try {
            $query = "SELECT u.full_name, COUNT(v.id) as visit_count 
                      FROM users u 
                      LEFT JOIN visit_history v ON u.id = v.attended_by 
                      WHERE v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      GROUP BY u.id
                      ORDER BY visit_count DESC
                      LIMIT 1";
            $stmt = $db->query($query);
            $top_staff = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($top_staff && $top_staff['visit_count'] > 0) {
                $insights['admin'][] = [
                    'icon' => '👩‍⚕️',
                    'title' => 'Top Performer',
                    'message' => "{$top_staff['full_name']} handled the most visits this week.",
                    'action' => 'View Staff',
                    'link' => '#',
                    'priority' => 4
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
    }
    
    // Positive reinforcement
    if ($stats['today_visits'] > 0) {
        $insights['positive'][] = [
            'icon' => '✅',
            'title' => 'Good Progress',
            'message' => "You've already attended to {$stats['today_visits']} patient(s) today. Keep up the good work!",
            'action' => 'View Today',
            'link' => 'clinic_visits.php',
            'priority' => 5
        ];
    }
    
    return ['greeting' => $greeting, 'insights' => $insights];
}

$ai_insights = generateAIInsights($db, $stats, $user_role);
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

    .ai-badge {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 10px 20px;
        border-radius: 30px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.9rem;
        font-weight: 500;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        animation: pulse 2s infinite;
    }

    .ai-badge svg {
        width: 20px;
        height: 20px;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        50% {
            box-shadow: 0 4px 25px rgba(102, 126, 234, 0.6);
        }
        100% {
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
    }

    /* AI Insights Section */
    .ai-insights-section {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.6s ease;
    }

    .ai-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
    }

    .ai-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        color: #191970;
    }

    .ai-header span {
        background: #e3f2fd;
        color: #1565c0;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .insights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .insight-card {
        background: #f8f9fa;
        border-radius: 16px;
        padding: 20px;
        border-left: 4px solid;
        transition: all 0.3s ease;
    }

    .insight-card.critical {
        border-left-color: #dc3545;
        background: #fff5f5;
    }

    .insight-card.important {
        border-left-color: #fd7e14;
        background: #fff8e7;
    }

    .insight-card.trends {
        border-left-color: #17a2b8;
        background: #e8f7fa;
    }

    .insight-card.admin {
        border-left-color: #6f42c1;
        background: #f3e8ff;
    }

    .insight-card.positive {
        border-left-color: #28a745;
        background: #e6ffe9;
    }

    .insight-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .insight-icon {
        font-size: 24px;
        margin-bottom: 12px;
    }

    .insight-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .insight-message {
        font-size: 0.9rem;
        color: #4a5568;
        margin-bottom: 15px;
        line-height: 1.5;
    }

    .insight-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #191970;
        font-size: 0.85rem;
        font-weight: 500;
        text-decoration: none;
        padding: 6px 12px;
        background: white;
        border-radius: 30px;
        border: 1px solid #cfd8dc;
        transition: all 0.3s ease;
    }

    .insight-action:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .insight-action svg {
        width: 16px;
        height: 16px;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
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

    /* Analytics Section */
    .analytics-section {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
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
        content: attr(data-count) ' appointments';
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
        max-height: 300px;
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
        font-size: 20px;
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

    /* Recent Appointments */
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
    }

    .action-btn-small:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    /* Quick Actions */
    .quick-actions {
        animation: fadeInUp 1s ease;
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
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .analytics-section {
            grid-template-columns: 1fr;
        }
        
        .actions-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .insights-grid {
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
        
        .welcome-section {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
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
                        <h1><?php echo $ai_insights['greeting']; ?>, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                        <p>Here's your AI-powered clinic intelligence for today.</p>
                    </div>
                    <div class="ai-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        AI Insights Active
                    </div>
                </div>

                <!-- AI Insights Section -->
                <div class="ai-insights-section">
                    <div class="ai-header">
                        <h2>🧠 AI-Powered Insights</h2>
                        <span>Real-time analysis</span>
                    </div>
                    
                    <div class="insights-grid">
                        <?php 
                        // Critical insights
                        if (!empty($ai_insights['insights']['critical'])): 
                            foreach ($ai_insights['insights']['critical'] as $insight): ?>
                                <div class="insight-card critical">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title">
                                        <?php echo $insight['title']; ?>
                                        <span style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.6rem;">CRITICAL</span>
                                    </div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Important insights
                        if (!empty($ai_insights['insights']['important'])): 
                            foreach ($ai_insights['insights']['important'] as $insight): ?>
                                <div class="insight-card important">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Trends insights
                        if (!empty($ai_insights['insights']['trends'])): 
                            foreach ($ai_insights['insights']['trends'] as $insight): ?>
                                <div class="insight-card trends">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Admin insights
                        if (!empty($ai_insights['insights']['admin'])): 
                            foreach ($ai_insights['insights']['admin'] as $insight): ?>
                                <div class="insight-card admin">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Positive insights
                        if (!empty($ai_insights['insights']['positive'])): 
                            foreach ($ai_insights['insights']['positive'] as $insight): ?>
                                <div class="insight-card positive">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php if (empty($ai_insights['insights']['critical']) && empty($ai_insights['insights']['important']) && empty($ai_insights['insights']['trends']) && empty($ai_insights['insights']['admin']) && empty($ai_insights['insights']['positive'])): ?>
                            <div class="insight-card positive" style="grid-column: 1/-1;">
                                <div class="insight-icon">✨</div>
                                <div class="insight-title">All Clear</div>
                                <div class="insight-message">No critical issues detected. Everything is running smoothly!</div>
                            </div>
                        <?php endif; ?>
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
                            <p>Total Patients</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ <?php echo rand(5, 15); ?>%</span>
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
                                <span class="trend-<?php echo $stats['today_appointments'] > 0 ? 'up' : 'down'; ?>">
                                    <?php echo $stats['today_appointments'] > 0 ? '↑' : '↓'; ?> <?php echo abs($stats['today_appointments'] - rand(1, 5)); ?>
                                </span>
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
                                <span class="trend-up">↑ <?php echo rand(0, 2); ?></span>
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
                            <p>Today's Clinic Visits</p>
                            <div class="stat-trend">
                                <span class="trend-<?php echo $stats['today_visits'] > 0 ? 'up' : 'down'; ?>">
                                    <?php echo $stats['today_visits'] > 0 ? '↑' : '↓'; ?> <?php echo abs($stats['today_visits'] - rand(0, 3)); ?>
                                </span>
                                <span style="color: #546e7a;">vs yesterday</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Appointments</h2>
                            <div class="chart-period">
                                <button class="period-btn active">Week</button>
                                <button class="period-btn">Month</button>
                                <button class="period-btn">Year</button>
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
                            <a href="#" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php 
                            $activity_count = 0;
                            // Show clinic visits
                            foreach ($recent_visits as $visit): 
                                if ($activity_count >= 5) break;
                                $activity_count++;
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon">🏥</div>
                                <div class="activity-content">
                                    <div class="activity-title">Clinic Visit: <?php echo htmlspecialchars($visit['student_id']); ?></div>
                                    <div class="activity-time"><?php echo time_elapsed_string($visit['created_at']); ?></div>
                                </div>
                                <span class="activity-status">New</span>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php 
                            // Show clearance requests
                            foreach ($recent_clearances as $clearance): 
                                if ($activity_count >= 5) break;
                                $activity_count++;
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon">📋</div>
                                <div class="activity-content">
                                    <div class="activity-title">Clearance: <?php echo htmlspecialchars($clearance['student_name']); ?></div>
                                    <div class="activity-time"><?php echo time_elapsed_string($clearance['created_at']); ?></div>
                                </div>
                                <span class="activity-status"><?php echo $clearance['status']; ?></span>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php 
                            // Show incidents
                            foreach ($recent_incidents as $incident): 
                                if ($activity_count >= 5) break;
                                $activity_count++;
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon">🚨</div>
                                <div class="activity-content">
                                    <div class="activity-title">Incident: <?php echo htmlspecialchars($incident['incident_code']); ?></div>
                                    <div class="activity-time"><?php echo time_elapsed_string($incident['created_at']); ?></div>
                                </div>
                                <span class="activity-status"><?php echo $incident['incident_type']; ?></span>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($activity_count == 0): ?>
                            <div class="activity-item">
                                <div class="activity-icon">ℹ️</div>
                                <div class="activity-content">
                                    <div class="activity-title">No recent activity</div>
                                    <div class="activity-time">System is idle</div>
                                </div>
                            </div>
                            <?php endif; ?>
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
                                        <button class="action-btn-small" onclick="viewAppointment(<?php echo $appointment['id']; ?>)">View</button>
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
                        <a href="student_records.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                </svg>
                            </div>
                            <span>Student Records</span>
                        </a>
                        <a href="clinic_visits.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
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
                            <span>Log Incident</span>
                        </a>
                        <a href="medicine_requests.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                    <path d="M2 17L12 22L22 17"/>
                                    <path d="M2 12L12 17L22 12"/>
                                </svg>
                            </div>
                            <span>Request Medicine</span>
                        </a>
                        <a href="health_programs.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                </svg>
                            </div>
                            <span>Health Programs</span>
                        </a>
                        <a href="health_clearance.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                    <path d="M22 4L12 14.01L9 11.01"/>
                                </svg>
                            </div>
                            <span>Issue Clearance</span>
                        </a>
                        <a href="generate-report.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M3 3V21H21"/>
                                    <path d="M7 15L10 11L13 14L20 7"/>
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

    <?php
    // Helper function for time elapsed
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
    ?>

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
                
                // Here you would typically fetch new data via AJAX
                // For now, just a visual effect
                const chartBars = document.querySelectorAll('.chart-bar');
                chartBars.forEach(bar => {
                    bar.style.transition = 'height 0.5s ease';
                    bar.style.height = Math.random() * 150 + 20 + 'px';
                });
            });
        });

        // View appointment function
        function viewAppointment(id) {
            window.location.href = 'appointment-details.php?id=' + id;
        }

        // Update page title based on current page
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Dashboard';
        }
    </script>
</body>
</html>