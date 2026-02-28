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

// Get user role for personalized insights
$user_query = "SELECT role, full_name FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $current_user_id);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$user_role = $user_data['role'] ?? 'staff';
$user_fullname = $user_data['full_name'] ?? $current_user_name;

// Get comprehensive statistics from all tables
$stats = [];

// Basic counts
$queries = [
    'patients' => "SELECT COUNT(*) as total FROM patients",
    'students' => "SELECT COUNT(*) as total FROM students",
    'today_appointments' => "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE()",
    'staff' => "SELECT COUNT(*) as total FROM users WHERE role IN ('admin', 'staff', 'doctor', 'superadmin')",
    'today_visits' => "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) = CURDATE()",
    'pending_clearances' => "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'",
    'approved_clearances' => "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Approved'",
    'low_stock_items' => "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock",
    'total_incidents' => "SELECT COUNT(*) as total FROM incidents WHERE DATE(incident_date) = CURDATE()",
    'total_medicine_requests' => "SELECT COUNT(*) as total FROM medicine_requests WHERE status = 'pending'",
    'total_certificates' => "SELECT COUNT(*) as total FROM medical_certificates WHERE DATE(issued_date) = CURDATE()",
    'total_vaccinations' => "SELECT COUNT(*) as total FROM vaccination_records WHERE DATE(date_administered) = CURDATE()",
    'total_physical_exams' => "SELECT COUNT(*) as total FROM physical_exam_records WHERE DATE(exam_date) = CURDATE()",
    'total_deworming' => "SELECT COUNT(*) as total FROM deworming_records WHERE DATE(date_given) = CURDATE()"
];

foreach ($queries as $key => $query) {
    try {
        $stmt = $db->query($query);
        $stats[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } catch (PDOException $e) {
        $stats[$key] = 0;
        error_log("Error fetching $key: " . $e->getMessage());
    }
}

// Recent appointments
$query = "SELECT a.*, p.full_name as patient_name, p.patient_id, u.full_name as doctor_name 
          FROM appointments a 
          LEFT JOIN patients p ON a.patient_id = p.id 
          LEFT JOIN users u ON a.doctor_id = u.id 
          ORDER BY a.appointment_date DESC, a.appointment_time DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent clinic visits
$query = "SELECT v.*, u.full_name as attended_by_name 
          FROM visit_history v
          LEFT JOIN users u ON v.attended_by = u.id
          ORDER BY v.visit_date DESC, v.visit_time DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent incidents
$query = "SELECT i.*, u.full_name as created_by_name 
          FROM incidents i
          LEFT JOIN users u ON i.created_by = u.id
          ORDER BY i.incident_date DESC, i.incident_time DESC 
          LIMIT 5";
$stmt = $db->query($query);
$recent_incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Low stock alerts
$query = "SELECT * FROM clinic_stock WHERE quantity <= minimum_stock ORDER BY quantity ASC LIMIT 5";
$stmt = $db->query($query);
$low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending medicine requests
$query = "SELECT mr.* FROM medicine_requests mr WHERE status = 'pending' ORDER BY requested_date DESC LIMIT 5";
$stmt = $db->query($query);
$pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// ==================== AI-POWERED INSIGHTS ====================

$insights = [];

// 1. Peak Hours Analysis
$query = "SELECT 
            HOUR(visit_time) as hour,
            COUNT(*) as visit_count
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY HOUR(visit_time)
          ORDER BY visit_count DESC
          LIMIT 3";
$stmt = $db->query($query);
$peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($peak_hours)) {
    $peak_hours_text = [];
    foreach ($peak_hours as $hour) {
        $time = $hour['hour'] . ':00 - ' . ($hour['hour'] + 1) . ':00';
        $peak_hours_text[] = "$time ({$hour['visit_count']} visits)";
    }
    $insights[] = [
        'type' => 'info',
        'icon' => '⏰',
        'title' => 'Peak Hours',
        'message' => 'Busiest times: ' . implode(', ', $peak_hours_text),
        'action' => 'Consider adjusting staff schedule during these hours'
    ];
}

// 2. Common Complaints Analysis
$query = "SELECT 
            complaint,
            COUNT(*) as frequency
          FROM visit_history
          WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY complaint
          ORDER BY frequency DESC
          LIMIT 3";
$stmt = $db->query($query);
$common_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($common_complaints)) {
    $complaints_text = [];
    foreach ($common_complaints as $complaint) {
        $complaints_text[] = "{$complaint['complaint']} ({$complaint['frequency']}x)";
    }
    $insights[] = [
        'type' => 'warning',
        'icon' => '🩺',
        'title' => 'Common Health Issues',
        'message' => 'Most frequent: ' . implode(', ', $complaints_text),
        'action' => 'Ensure adequate medicine stock for these conditions'
    ];
}

// 3. Stock Prediction (Items that will run out soon)
$query = "SELECT 
            item_name,
            quantity,
            minimum_stock,
            ROUND(quantity / GREATEST(1, (SELECT AVG(quantity) FROM dispensing_log WHERE item_code = clinic_stock.item_code AND dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)))) as days_remaining
          FROM clinic_stock
          WHERE quantity > 0
          ORDER BY quantity / GREATEST(1, (SELECT COALESCE(AVG(quantity), 1) FROM dispensing_log WHERE item_code = clinic_stock.item_code AND dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY))) ASC
          LIMIT 3";
$stmt = $db->query($query);
$low_stock_prediction = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($low_stock_prediction)) {
    $prediction_text = [];
    foreach ($low_stock_prediction as $item) {
        $days = $item['days_remaining'] ? round($item['days_remaining']) : 'N/A';
        $prediction_text[] = "{$item['item_name']} (~{$days} days left)";
    }
    $insights[] = [
        'type' => 'danger',
        'icon' => '📦',
        'title' => 'Stock Alert',
        'message' => 'Items running low: ' . implode(', ', $prediction_text),
        'action' => 'Consider restocking soon'
    ];
}

// 4. Clearance Trends
$query = "SELECT 
            clearance_type,
            COUNT(*) as total
          FROM clearance_requests
          WHERE request_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY clearance_type
          ORDER BY total DESC
          LIMIT 1";
$stmt = $db->query($query);
$top_clearance = $stmt->fetch(PDO::FETCH_ASSOC);
if (!empty($top_clearance)) {
    $insights[] = [
        'type' => 'info',
        'icon' => '✅',
        'title' => 'Clearance Demand',
        'message' => "Most requested clearance: {$top_clearance['clearance_type']} ({$top_clearance['total']} this month)",
        'action' => 'Prepare forms and requirements'
    ];
}

// 5. Incident Patterns
$query = "SELECT 
            location,
            COUNT(*) as frequency
          FROM incidents
          WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY location
          ORDER BY frequency DESC
          LIMIT 1";
$stmt = $db->query($query);
$common_location = $stmt->fetch(PDO::FETCH_ASSOC);
if (!empty($common_location)) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '📍',
        'title' => 'Incident Hotspot',
        'message' => "Most incidents occur at: {$common_location['location']}",
        'action' => 'Consider safety inspection in this area'
    ];
}

// 6. Staff Workload
$query = "SELECT 
            u.full_name,
            COUNT(v.id) as visits_handled
          FROM users u
          LEFT JOIN visit_history v ON u.id = v.attended_by AND v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          WHERE u.role IN ('admin', 'staff', 'doctor')
          GROUP BY u.id
          ORDER BY visits_handled DESC
          LIMIT 3";
$stmt = $db->query($query);
$staff_workload = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($staff_workload) && $staff_workload[0]['visits_handled'] > 0) {
    $workload_text = "Top: {$staff_workload[0]['full_name']} ({$staff_workload[0]['visits_handled']} visits)";
    $insights[] = [
        'type' => 'info',
        'icon' => '👥',
        'title' => 'Staff Workload',
        'message' => $workload_text,
        'action' => 'Balance tasks if needed'
    ];
}

// 7. Vaccination Due
$query = "SELECT 
            COUNT(*) as total
          FROM vaccination_records
          WHERE next_dose_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
$stmt = $db->query($query);
$due_vaccinations = $stmt->fetch(PDO::FETCH_ASSOC);
if ($due_vaccinations['total'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '💉',
        'title' => 'Upcoming Vaccinations',
        'message' => "{$due_vaccinations['total']} students due for next dose within 7 days",
        'action' => 'Prepare vaccination schedule'
    ];
}

// 8. Health Program Participation
$query = "SELECT 
            COUNT(DISTINCT student_id) as participants
          FROM health_screening_records
          WHERE screening_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $db->query($query);
$screening_participants = $stmt->fetch(PDO::FETCH_ASSOC);
if ($screening_participants['participants'] > 0) {
    $insights[] = [
        'type' => 'success',
        'icon' => '📊',
        'title' => 'Health Screening',
        'message' => "{$screening_participants['participants']} students screened this month",
        'action' => 'Great participation rate!'
    ];
}

// 9. Emergency Readiness
$query = "SELECT 
            COUNT(*) as total
          FROM emergency_cases
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$stmt = $db->query($query);
$recent_emergencies = $stmt->fetch(PDO::FETCH_ASSOC);
if ($recent_emergencies['total'] > 0) {
    $insights[] = [
        'type' => 'danger',
        'icon' => '🚨',
        'title' => 'Recent Emergencies',
        'message' => "{$recent_emergencies['total']} emergency cases this week",
        'action' => 'Review emergency protocols'
    ];
} else {
    $insights[] = [
        'type' => 'success',
        'icon' => '✅',
        'title' => 'Emergency Status',
        'message' => 'No emergencies in the past week',
        'action' => 'All clear!'
    ];
}

// 10. Expiring Certificates
$query = "SELECT 
            COUNT(*) as total
          FROM medical_certificates
          WHERE valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
$stmt = $db->query($query);
$expiring_certs = $stmt->fetch(PDO::FETCH_ASSOC);
if ($expiring_certs['total'] > 0) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '📄',
        'title' => 'Expiring Certificates',
        'message' => "{$expiring_certs['total']} certificates expiring within 7 days",
        'action' => 'Notify students for renewal'
    ];
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
            background: #f0f4f8;
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
            background: #f0f4f8;
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
            background: linear-gradient(135deg, #0b4f6c 0%, #1a7f7a 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-text p {
            color: #2c5f6e;
            font-size: 1rem;
            font-weight: 400;
        }

        .date-badge {
            background: white;
            padding: 10px 20px;
            border-radius: 30px;
            border: 1px solid #cbd5e0;
            color: #0b4f6c;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* AI Insights Section */
        .ai-insights-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .insights-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .insights-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0b4f6c;
        }

        .ai-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }

        .insight-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        .insight-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .insight-card.info {
            border-left-color: #4299e1;
        }
        .insight-card.success {
            border-left-color: #48bb78;
        }
        .insight-card.warning {
            border-left-color: #ecc94b;
        }
        .insight-card.danger {
            border-left-color: #f56565;
        }

        .insight-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .insight-icon {
            width: 40px;
            height: 40px;
            background: #f7fafc;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .insight-title {
            font-weight: 600;
            color: #1a202c;
            font-size: 1rem;
        }

        .insight-message {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .insight-action {
            background: #f7fafc;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .insight-action svg {
            width: 16px;
            height: 16px;
            color: #718096;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(11, 79, 108, 0.1), 0 2px 4px -1px rgba(11, 79, 108, 0.06);
            border: 1px solid #cbd5e0;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(11, 79, 108, 0.2), 0 10px 10px -5px rgba(11, 79, 108, 0.1);
            border-color: #1a7f7a;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #0b4f6c 0%, #1a7f7a 100%);
            border-radius: 14px;
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
            font-size: 1.6rem;
            font-weight: 700;
            color: #0b4f6c;
            margin-bottom: 2px;
        }

        .stat-info p {
            color: #2c5f6e;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.7rem;
            margin-top: 4px;
        }

        .trend-up {
            background: #c6f6d5;
            color: #22543d;
            padding: 2px 6px;
            border-radius: 20px;
            font-weight: 600;
        }

        .trend-down {
            background: #fed7d7;
            color: #742a2a;
            padding: 2px 6px;
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

        .chart-card, .alerts-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(11, 79, 108, 0.1), 0 2px 4px -1px rgba(11, 79, 108, 0.06);
            border: 1px solid #cbd5e0;
        }

        .chart-header, .alerts-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .chart-header h2, .alerts-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0b4f6c;
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
            background: linear-gradient(135deg, #0b4f6c 0%, #1a7f7a 100%);
            border-radius: 6px 6px 0 0;
            min-height: 4px;
            transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
        }

        .chart-bar:hover::after {
            content: attr(data-count) ' visits';
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #0b4f6c;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .chart-label {
            font-size: 0.7rem;
            color: #2c5f6e;
            font-weight: 500;
        }

        /* Alerts List */
        .alerts-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .alert-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            background: #f8fafc;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .alert-item:hover {
            background: white;
            border-color: #1a7f7a;
            transform: translateX(5px);
        }

        .alert-icon {
            width: 40px;
            height: 40px;
            background: #e6f7f5;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1a7f7a;
            font-size: 20px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #0b4f6c;
            margin-bottom: 4px;
        }

        .alert-desc {
            font-size: 0.8rem;
            color: #2c5f6e;
        }

        .alert-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .alert-badge.warning {
            background: #fefcbf;
            color: #975f0d;
        }

        .alert-badge.danger {
            background: #fed7d7;
            color: #972b2b;
        }

        .alert-badge.success {
            background: #c6f6d5;
            color: #22543d;
        }

        /* Recent Sections Grid */
        .recent-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.9s ease;
        }

        .recent-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(11, 79, 108, 0.1), 0 2px 4px -1px rgba(11, 79, 108, 0.06);
            border: 1px solid #cbd5e0;
        }

        .recent-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .recent-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #0b4f6c;
        }

        .view-link {
            color: #1a7f7a;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 10px;
        }

        .recent-avatar {
            width: 40px;
            height: 40px;
            background: #e6f7f5;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #1a7f7a;
        }

        .recent-details {
            flex: 1;
        }

        .recent-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1a202c;
            margin-bottom: 2px;
        }

        .recent-meta {
            font-size: 0.7rem;
            color: #718096;
            display: flex;
            gap: 8px;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-indicator.completed {
            background: #48bb78;
        }
        .status-indicator.pending {
            background: #ecc94b;
        }
        .status-indicator.cancelled {
            background: #f56565;
        }

        /* Quick Actions */
        .quick-actions {
            animation: fadeInUp 1s ease;
        }

        .quick-actions h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0b4f6c;
            margin-bottom: 20px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
        }

        .action-card {
            background: white;
            border: 1px solid #cbd5e0;
            border-radius: 16px;
            padding: 24px 16px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            cursor: pointer;
        }

        .action-card:hover {
            transform: translateY(-4px);
            border-color: #1a7f7a;
            box-shadow: 0 8px 16px rgba(11, 79, 108, 0.1);
        }

        .action-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #0b4f6c 0%, #1a7f7a 100%);
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
            transform: scale(1.05);
        }

        .action-card span {
            display: block;
            font-weight: 600;
            color: #0b4f6c;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .action-desc {
            font-size: 0.7rem;
            color: #718096;
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
                grid-template-columns: repeat(3, 1fr);
            }
            
            .recent-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .analytics-section {
                grid-template-columns: 1fr;
            }
            
            .recent-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
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
                    <div class="welcome-text">
                        <h1>Welcome back, <?php echo htmlspecialchars($user_fullname); ?>! 👋</h1>
                        <p>Here's what's happening in your clinic today.</p>
                    </div>
                    <div class="date-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        <?php echo date('l, F d, Y'); ?>
                    </div>
                </div>

                <!-- AI-Powered Insights Section -->
                <div class="ai-insights-section">
                    <div class="insights-header">
                        <h2>AI-Powered Insights</h2>
                        <span class="ai-badge">LIVE ANALYSIS</span>
                    </div>
                    <div class="insights-grid">
                        <?php foreach ($insights as $index => $insight): ?>
                        <div class="insight-card <?php echo $insight['type']; ?>">
                            <div class="insight-header">
                                <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                <span class="insight-title"><?php echo $insight['title']; ?></span>
                            </div>
                            <div class="insight-message"><?php echo $insight['message']; ?></div>
                            <div class="insight-action">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="16" x2="12" y2="12"/>
                                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                                <span><?php echo $insight['action']; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['patients'] + $stats['students']; ?></h3>
                            <p>Total Patients</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 12%</span>
                                <span>vs last month</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_appointments']; ?></h3>
                            <p>Today's Appointments</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 5%</span>
                                <span>vs yesterday</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🏥</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 8%</span>
                                <span>vs yesterday</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_clearances']; ?></h3>
                            <p>Pending Clearances</p>
                            <?php if ($stats['pending_clearances'] > 0): ?>
                            <div class="stat-trend">
                                <span class="trend-down">Action needed</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📦</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['low_stock_items']; ?></h3>
                            <p>Low Stock Items</p>
                            <?php if ($stats['low_stock_items'] > 0): ?>
                            <div class="stat-trend">
                                <span class="trend-down">Reorder soon</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Visit Activity</h2>
                            <div class="chart-period">
                                <button class="period-btn active">Week</button>
                                <button class="period-btn">Month</button>
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

                    <div class="alerts-card">
                        <div class="alerts-header">
                            <h2>Priority Alerts</h2>
                            <a href="alerts.php" class="view-link">View All</a>
                        </div>
                        <div class="alerts-list">
                            <?php if ($stats['low_stock_items'] > 0): ?>
                            <div class="alert-item">
                                <div class="alert-icon">📦</div>
                                <div class="alert-content">
                                    <div class="alert-title">Low Stock Alert</div>
                                    <div class="alert-desc"><?php echo $stats['low_stock_items']; ?> items need reordering</div>
                                </div>
                                <span class="alert-badge danger">URGENT</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['pending_clearances'] > 0): ?>
                            <div class="alert-item">
                                <div class="alert-icon">✅</div>
                                <div class="alert-content">
                                    <div class="alert-title">Pending Clearances</div>
                                    <div class="alert-desc"><?php echo $stats['pending_clearances']; ?> clearances awaiting approval</div>
                                </div>
                                <span class="alert-badge warning">Pending</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['pending_medicine_requests'] > 0): ?>
                            <div class="alert-item">
                                <div class="alert-icon">💊</div>
                                <div class="alert-content">
                                    <div class="alert-title">Medicine Requests</div>
                                    <div class="alert-desc"><?php echo $stats['pending_medicine_requests']; ?> requests pending</div>
                                </div>
                                <span class="alert-badge warning">Pending</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (empty($low_stock_items) && $stats['pending_clearances'] == 0 && $stats['pending_medicine_requests'] == 0): ?>
                            <div class="alert-item">
                                <div class="alert-icon">✅</div>
                                <div class="alert-content">
                                    <div class="alert-title">All Clear</div>
                                    <div class="alert-desc">No urgent alerts at this time</div>
                                </div>
                                <span class="alert-badge success">Good</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Grid -->
                <div class="recent-grid">
                    <!-- Recent Visits -->
                    <div class="recent-card">
                        <div class="recent-header">
                            <h3>Recent Clinic Visits</h3>
                            <a href="clinic_visits.php" class="view-link">View All</a>
                        </div>
                        <div class="recent-list">
                            <?php if (!empty($recent_visits)): ?>
                                <?php foreach ($recent_visits as $visit): ?>
                                <div class="recent-item">
                                    <div class="recent-avatar">👤</div>
                                    <div class="recent-details">
                                        <div class="recent-name">Student #<?php echo htmlspecialchars($visit['student_id']); ?></div>
                                        <div class="recent-meta">
                                            <span><?php echo date('M d, h:i A', strtotime($visit['visit_date'] . ' ' . $visit['visit_time'])); ?></span>
                                            <span>•</span>
                                            <span><?php echo htmlspecialchars($visit['complaint']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="recent-item">
                                    <div class="recent-details">
                                        <div class="recent-meta">No recent visits</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Incidents -->
                    <div class="recent-card">
                        <div class="recent-header">
                            <h3>Recent Incidents</h3>
                            <a href="incidents.php" class="view-link">View All</a>
                        </div>
                        <div class="recent-list">
                            <?php if (!empty($recent_incidents)): ?>
                                <?php foreach ($recent_incidents as $incident): ?>
                                <div class="recent-item">
                                    <div class="recent-avatar">🚨</div>
                                    <div class="recent-details">
                                        <div class="recent-name"><?php echo htmlspecialchars($incident['student_name']); ?></div>
                                        <div class="recent-meta">
                                            <span><?php echo date('M d, h:i A', strtotime($incident['incident_date'] . ' ' . $incident['incident_time'])); ?></span>
                                            <span>•</span>
                                            <span><?php echo $incident['incident_type']; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="recent-item">
                                    <div class="recent-details">
                                        <div class="recent-meta">No recent incidents</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Low Stock Items -->
                    <div class="recent-card">
                        <div class="recent-header">
                            <h3>Low Stock Items</h3>
                            <a href="medicine_requests.php" class="view-link">Manage</a>
                        </div>
                        <div class="recent-list">
                            <?php if (!empty($low_stock_items)): ?>
                                <?php foreach ($low_stock_items as $item): ?>
                                <div class="recent-item">
                                    <div class="recent-avatar">📦</div>
                                    <div class="recent-details">
                                        <div class="recent-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="recent-meta">
                                            <span>Stock: <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?></span>
                                            <span>•</span>
                                            <span>Min: <?php echo $item['minimum_stock']; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="recent-item">
                                    <div class="recent-details">
                                        <div class="recent-meta">All items adequately stocked</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="student_records.php" class="action-card">
                            <div class="action-icon">👤</div>
                            <span>Student Records</span>
                            <div class="action-desc">View medical records</div>
                        </a>
                        <a href="clinic_visits.php" class="action-card">
                            <div class="action-icon">🏥</div>
                            <span>Clinic Visit</span>
                            <div class="action-desc">Log new visit</div>
                        </a>
                        <a href="incidents.php" class="action-card">
                            <div class="action-icon">🚨</div>
                            <span>Report Incident</span>
                            <div class="action-desc">Log incident/emergency</div>
                        </a>
                        <a href="medicine_requests.php" class="action-card">
                            <div class="action-icon">💊</div>
                            <span>Medicine Request</span>
                            <div class="action-desc">Request supplies</div>
                        </a>
                        <a href="health_clearance.php" class="action-card">
                            <div class="action-icon">✅</div>
                            <span>Issue Clearance</span>
                            <div class="action-desc">Create clearance</div>
                        </a>
                        <a href="health_programs.php" class="action-card">
                            <div class="action-icon">📊</div>
                            <span>Health Programs</span>
                            <div class="action-desc">Track programs</div>
                        </a>
                        <a href="appointments.php" class="action-card">
                            <div class="action-icon">📅</div>
                            <span>Appointments</span>
                            <div class="action-desc">Schedule/view</div>
                        </a>
                        <a href="reports.php" class="action-card">
                            <div class="action-icon">📈</div>
                            <span>Reports</span>
                            <div class="action-desc">Generate reports</div>
                        </a>
                        <a href="backup.php" class="action-card">
                            <div class="action-icon">💾</div>
                            <span>Backup</span>
                            <div class="action-desc">Database backup</div>
                        </a>
                        <a href="settings.php" class="action-card">
                            <div class="action-icon">⚙️</div>
                            <span>Settings</span>
                            <div class="action-desc">System config</div>
                        </a>
                    </div>
                </div>
            </div>
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
                
                // Here you would update the chart data based on period
                if (btn.textContent.includes('Month')) {
                    // Load monthly data
                } else {
                    // Load weekly data
                }
            });
        });

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Dashboard';
        }

        // Auto-refresh insights every 30 seconds
        setInterval(() => {
            fetch('api/get_insights.php')
                .then(response => response.json())
                .then(data => {
                    // Update insights section with new data
                    console.log('Insights refreshed');
                })
                .catch(error => console.error('Error refreshing insights:', error));
        }, 30000);
    </script>
</body>
</html>