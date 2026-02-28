<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: ../login.php');
    exit();
}

// Include FPDF library
require_once '../vendor/setasign/fpdf/fpdf.php';

$database = new Database();
$db = $database->getConnection();

// Get filter parameters
$report_type = isset($_REQUEST['report_type']) ? $_REQUEST['report_type'] : 'ai_insights';
$date_from = isset($_REQUEST['date_from']) ? $_REQUEST['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_REQUEST['date_to']) ? $_REQUEST['date_to'] : date('Y-m-d');
$export_type = isset($_REQUEST['export_type']) ? $_REQUEST['export_type'] : 'full';
$search = isset($_REQUEST['search']) ? $_REQUEST['search'] : '';

// Handle export
if (isset($_REQUEST['export']) && $_REQUEST['export'] == 'pdf') {
    exportToPDF($report_type, $date_from, $date_to, $export_type, $search, $db);
    exit();
}

// AI Functions
function getAIPredictions($db, $date_from, $date_to) {
    $predictions = [];
    
    // Predict next month's visits based on historical data
    $query = "SELECT 
                DAYOFWEEK(visit_date) as day_of_week,
                COUNT(*) as avg_visits
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY DAYOFWEEK(visit_date)
              ORDER BY day_of_week";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $daily_pattern = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average daily visits
    $total_visits = 0;
    $days_count = 0;
    foreach ($daily_pattern as $day) {
        $total_visits += $day['avg_visits'];
        $days_count++;
    }
    $avg_daily = $days_count > 0 ? round($total_visits / $days_count) : 0;
    
    // Predict next 30 days
    $predictions['next_month_visits'] = $avg_daily * 30;
    
    // Predict stock depletion
    $query = "SELECT 
                item_name,
                quantity,
                (SELECT COALESCE(AVG(quantity), 1) 
                 FROM dispensing_log 
                 WHERE item_code = cs.item_code 
                 AND dispensed_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as avg_monthly_usage
              FROM clinic_stock cs
              WHERE quantity > 0";
    $stmt = $db->query($query);
    $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stock_predictions = [];
    foreach ($stock_items as $item) {
        if ($item['avg_monthly_usage'] > 0) {
            $months_until_depletion = $item['quantity'] / $item['avg_monthly_usage'];
            if ($months_until_depletion <= 1) {
                $stock_predictions[] = [
                    'item' => $item['item_name'],
                    'months_left' => round($months_until_depletion, 1),
                    'status' => 'critical'
                ];
            } elseif ($months_until_depletion <= 2) {
                $stock_predictions[] = [
                    'item' => $item['item_name'],
                    'months_left' => round($months_until_depletion, 1),
                    'status' => 'warning'
                ];
            }
        }
    }
    $predictions['stock_alerts'] = $stock_predictions;
    
    // Predict busy periods
    $query = "SELECT 
                HOUR(visit_time) as hour,
                COUNT(*) as visit_count
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY HOUR(visit_time)
              ORDER BY visit_count DESC
              LIMIT 3";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $predictions['peak_hours'] = $peak_hours;
    
    // Predict common complaints trend
    $query = "SELECT 
                complaint,
                COUNT(*) as frequency,
                COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() as percentage
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY complaint
              ORDER BY frequency DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $common_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $predictions['common_complaints'] = $common_complaints;
    
    return $predictions;
}

function getAnomalyDetection($db, $date_from, $date_to) {
    $anomalies = [];
    
    // Detect unusual spike in incidents
    $query = "SELECT 
                DATE(incident_date) as date,
                COUNT(*) as daily_count,
                (SELECT AVG(cnt) FROM (
                    SELECT DATE(incident_date) as d, COUNT(*) as cnt
                    FROM incidents 
                    WHERE incident_date BETWEEN DATE_SUB(:date_to, INTERVAL 30 DAY) AND :date_to
                    GROUP BY DATE(incident_date)
                ) as avg_table) as avg_count
              FROM incidents 
              WHERE incident_date BETWEEN :date_from AND :date_to
              GROUP BY DATE(incident_date)
              HAVING daily_count > avg_count * 2";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $incident_spikes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($incident_spikes)) {
        $anomalies['incident_spikes'] = $incident_spikes;
    }
    
    // Detect unusual medicine usage
    $query = "SELECT 
                item_name,
                SUM(quantity) as total_used,
                (SELECT AVG(monthly_usage) FROM (
                    SELECT DATE_FORMAT(dispensed_date, '%Y-%m') as month, SUM(quantity) as monthly_usage
                    FROM dispensing_log 
                    WHERE dispensed_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                    GROUP BY DATE_FORMAT(dispensed_date, '%Y-%m')
                ) as avg_usage) as avg_monthly
              FROM dispensing_log 
              WHERE dispensed_date BETWEEN :date_from AND :date_to
              GROUP BY item_name
              HAVING total_used > avg_monthly * 1.5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $usage_anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($usage_anomalies)) {
        $anomalies['usage_spikes'] = $usage_anomalies;
    }
    
    return $anomalies;
}

function getSmartRecommendations($db, $date_from, $date_to) {
    $recommendations = [];
    
    // Stock level recommendations
    $query = "SELECT 
                item_name,
                quantity,
                minimum_stock,
                expiry_date,
                DATEDIFF(expiry_date, CURDATE()) as days_to_expiry
              FROM clinic_stock 
              WHERE quantity <= minimum_stock * 1.5 
                 OR expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              ORDER BY 
                CASE 
                    WHEN quantity <= minimum_stock THEN 1
                    WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 2
                    ELSE 3
                END";
    $stmt = $db->query($query);
    $stock_needs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stock_needs as $item) {
        if ($item['quantity'] <= $item['minimum_stock']) {
            $recommendations[] = [
                'type' => 'restock',
                'priority' => 'high',
                'message' => "Restock {$item['item_name']} - Current: {$item['quantity']}, Minimum: {$item['minimum_stock']}"
            ];
        } elseif ($item['days_to_expiry'] <= 30 && $item['days_to_expiry'] > 0) {
            $recommendations[] = [
                'type' => 'expiry',
                'priority' => 'medium',
                'message' => "{$item['item_name']} expires in {$item['days_to_expiry']} days"
            ];
        }
    }
    
    // Staff allocation recommendations based on peak hours
    $query = "SELECT 
                HOUR(visit_time) as hour,
                COUNT(*) as visit_count,
                DAYNAME(visit_date) as day_name
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY HOUR(visit_time), DAYNAME(visit_date)
              HAVING visit_count > (
                SELECT AVG(cnt) FROM (
                  SELECT COUNT(*) as cnt
                  FROM visit_history 
                  WHERE visit_date BETWEEN :date_from AND :date_to
                  GROUP BY HOUR(visit_time), DATE(visit_date)
                ) as avg_visits
              )
              ORDER BY visit_count DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $peak_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($peak_times)) {
        $peak = $peak_times[0];
        $recommendations[] = [
            'type' => 'staffing',
            'priority' => 'medium',
            'message' => "Consider additional staff on {$peak['day_name']}s around " . date('g A', strtotime($peak['hour'] . ':00'))
        ];
    }
    
    // Preventive measures based on common complaints
    $query = "SELECT 
                complaint,
                COUNT(*) as frequency
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY complaint
              ORDER BY frequency DESC
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $top_complaint = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($top_complaint && $top_complaint['frequency'] > 10) {
        $recommendations[] = [
            'type' => 'prevention',
            'priority' => 'low',
            'message' => "High frequency of '{$top_complaint['complaint']}' cases. Consider preventive measures or awareness campaign"
        ];
    }
    
    return $recommendations;
}

// Get AI data
$ai_predictions = getAIPredictions($db, $date_from, $date_to);
$anomalies = getAnomalyDetection($db, $date_from, $date_to);
$recommendations = getSmartRecommendations($db, $date_from, $date_to);

// Get chart data
$chart_data = [];

// Incident types chart
$query = "SELECT incident_type, COUNT(*) as count FROM incidents WHERE incident_date BETWEEN :date_from AND :date_to GROUP BY incident_type";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$incident_chart = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['incident_types'] = $incident_chart;

// Clearance status chart
$query = "SELECT status, COUNT(*) as count FROM clearance_requests WHERE request_date BETWEEN :date_from AND :date_to GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$clearance_chart = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['clearance_status'] = $clearance_chart;

// Medicine request status chart
$query = "SELECT status, COUNT(*) as count FROM medicine_requests WHERE DATE(requested_date) BETWEEN :date_from AND :date_to GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$request_chart = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['request_status'] = $request_chart;

// Daily activity for last 7 days
$last_7_days = date('Y-m-d', strtotime('-7 days', strtotime($date_to)));
$query = "SELECT 
            DATE(visit_date) as date,
            COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date BETWEEN :last_7_days AND :date_to 
          GROUP BY DATE(visit_date) 
          ORDER BY date ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':last_7_days', $last_7_days);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$daily_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['daily_visits'] = $daily_visits;

// Monthly trend
$query = "SELECT 
            DATE_FORMAT(visit_date, '%Y-%m') as month,
            COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date BETWEEN DATE_SUB(:date_to, INTERVAL 6 MONTH) AND :date_to
          GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
          ORDER BY month ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$monthly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['monthly_trend'] = $monthly_trend;

// Stock status
$query = "SELECT 
            CASE 
                WHEN quantity <= minimum_stock THEN 'Low Stock'
                WHEN expiry_date < CURDATE() THEN 'Expired'
                ELSE 'Normal'
            END as status,
            COUNT(*) as count 
          FROM clinic_stock 
          GROUP BY status";
$stmt = $db->query($query);
$stock_chart = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['stock_status'] = $stock_chart;

// Get preview data
$preview_data = getReportData($report_type, $date_from, $date_to, 'full', $search, $db);
$preview_count = count($preview_data);
$preview_data = array_slice($preview_data, 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Admin | MedFlow Clinic Management System</title>
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

    .btn {
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
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
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.2);
    }

    .btn-success {
        background: #2e7d32;
        color: white;
    }

    .btn-success:hover {
        background: #3a8e3f;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(46, 125, 50, 0.2);
    }

    .btn-secondary {
        background: #eceff1;
        color: #191970;
        border: 1px solid #cfd8dc;
    }

    .btn-secondary:hover {
        background: #cfd8dc;
        transform: translateY(-2px);
    }

    .btn-info {
        background: #0288d1;
        color: white;
    }

    .btn-info:hover {
        background: #039be5;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(2, 136, 209, 0.2);
    }

    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.6s ease;
    }

    .filter-section h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .filter-group label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
    }

    .filter-group input,
    .filter-group select {
        padding: 12px 16px;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .export-options {
        display: flex;
        gap: 24px;
        margin: 20px 0;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
    }

    .export-option {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .export-option input[type="radio"] {
        accent-color: #191970;
        width: 16px;
        height: 16px;
    }

    .filter-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    /* AI Insights Section */
    .ai-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        padding: 30px;
        margin-bottom: 30px;
        color: white;
        animation: fadeInUp 0.5s ease;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    }

    .ai-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
    }

    .ai-header h2 {
        font-size: 1.8rem;
        font-weight: 700;
    }

    .ai-header i {
        font-size: 2rem;
        background: rgba(255, 255, 255, 0.2);
        padding: 12px;
        border-radius: 12px;
    }

    .ai-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
    }

    .ai-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .ai-card h3 {
        font-size: 1rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 16px;
        opacity: 0.9;
    }

    .ai-card .value {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .ai-card .label {
        font-size: 0.9rem;
        opacity: 0.8;
    }

    .ai-card .trend {
        display: flex;
        align-items: center;
        gap: 4px;
        margin-top: 12px;
        font-size: 0.9rem;
    }

    .trend.up { color: #4caf50; }
    .trend.down { color: #f44336; }

    /* Anomaly Alert */
    .anomaly-alert {
        background: rgba(244, 67, 54, 0.1);
        border-left: 4px solid #f44336;
        padding: 16px 20px;
        margin-bottom: 30px;
        border-radius: 12px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(244, 67, 54, 0); }
        100% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0); }
    }

    /* Recommendations */
    .recommendations-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .recommendations-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
    }

    .recommendations-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .recommendations-grid {
        display: grid;
        gap: 16px;
    }

    .recommendation-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
        border-left: 4px solid;
        transition: all 0.3s ease;
    }

    .recommendation-item:hover {
        transform: translateX(4px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .recommendation-item.priority-high { border-left-color: #f44336; }
    .recommendation-item.priority-medium { border-left-color: #ff9800; }
    .recommendation-item.priority-low { border-left-color: #4caf50; }

    .recommendation-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    .recommendation-content {
        flex: 1;
    }

    .recommendation-title {
        font-weight: 600;
        color: #191970;
        margin-bottom: 4px;
    }

    .recommendation-message {
        color: #546e7a;
        font-size: 0.9rem;
    }

    .recommendation-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .badge-high {
        background: #ffebee;
        color: #f44336;
    }

    .badge-medium {
        background: #fff3e0;
        color: #ff9800;
    }

    .badge-low {
        background: #e8f5e9;
        color: #4caf50;
    }

    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .chart-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-card h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .chart-container {
        height: 250px;
        position: relative;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
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

    /* Preview Table */
    .preview-section {
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

    .no-data {
        text-align: center;
        padding: 40px;
        color: #546e7a;
        font-style: italic;
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
        .ai-grid {
            grid-template-columns: 1fr;
        }
        
        .charts-grid {
            grid-template-columns: 1fr;
        }
        
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
        
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .export-options {
            flex-direction: column;
            gap: 12px;
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
                        <h1>Analytics Dashboard</h1>
                        <p>AI-powered insights and predictive analytics for clinic management</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-secondary" onclick="resetFilters()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6H21M6 12H18M10 18H14" stroke-linecap="round"/>
                            </svg>
                            Reset Filters
                        </button>
                        <button class="btn btn-info" onclick="refreshAIInsights()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 4V10H17"/>
                                <path d="M1 20V14H7"/>
                                <path d="M3.51 9C4.01717 7.56678 4.87913 6.2854 6.01547 5.27542C7.1518 4.26544 8.52547 3.55976 10.0083 3.22426C11.4911 2.88875 13.0348 2.93434 14.4971 3.35714C15.9594 3.77994 17.2919 4.56427 18.37 5.64L23 10M1 14L5.64 18.36C6.71815 19.4357 8.05064 20.2201 9.51294 20.6429C10.9752 21.0657 12.5189 21.1113 14.0017 20.7757C15.4845 20.4402 16.8582 19.7346 17.9845 18.7246C19.1109 17.7146 19.9728 16.4332 20.48 15"/>
                            </svg>
                            Refresh AI Insights
                        </button>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h2>Analytics Filters</h2>
                    <form method="GET" action="" id="reportForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="report_type">Analysis Type</label>
                                <select name="report_type" id="report_type">
                                    <option value="ai_insights" <?php echo $report_type == 'ai_insights' ? 'selected' : ''; ?>>AI Insights & Predictions</option>
                                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>System Summary</option>
                                    <option value="users" <?php echo $report_type == 'users' ? 'selected' : ''; ?>>Users Report</option>
                                    <option value="patients" <?php echo $report_type == 'patients' ? 'selected' : ''; ?>>Patients Report</option>
                                    <option value="incidents" <?php echo $report_type == 'incidents' ? 'selected' : ''; ?>>Incidents Report</option>
                                    <option value="medicine_dispensed" <?php echo $report_type == 'medicine_dispensed' ? 'selected' : ''; ?>>Medicine Dispensed</option>
                                    <option value="medicine_requests" <?php echo $report_type == 'medicine_requests' ? 'selected' : ''; ?>>Medicine Requests</option>
                                    <option value="clearance_requests" <?php echo $report_type == 'clearance_requests' ? 'selected' : ''; ?>>Health Clearance</option>
                                    <option value="visit_history" <?php echo $report_type == 'visit_history' ? 'selected' : ''; ?>>Visit History</option>
                                    <option value="clinic_stock" <?php echo $report_type == 'clinic_stock' ? 'selected' : ''; ?>>Clinic Stock</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="date_from">Date From</label>
                                <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="filter-group">
                                <label for="date_to">Date To</label>
                                <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="filter-group">
                                <label for="search">Search</label>
                                <input type="text" name="search" id="search" placeholder="Search records..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <div class="export-options">
                            <span style="font-weight: 600; color: #191970;">Export Options:</span>
                            <div class="export-option">
                                <input type="radio" name="export_type" id="full" value="full" <?php echo $export_type == 'full' ? 'checked' : ''; ?>>
                                <label for="full">Full Report (All records in date range)</label>
                            </div>
                            <div class="export-option">
                                <input type="radio" name="export_type" id="partial" value="partial" <?php echo $export_type == 'partial' ? 'checked' : ''; ?>>
                                <label for="partial">Partial Report (Filtered by search)</label>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" name="apply" value="1" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21L16.5 16.5"/>
                                </svg>
                                Apply Filters
                            </button>
                            <button type="submit" name="export" value="pdf" class="btn btn-success">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15"/>
                                    <path d="M7 10L12 15L17 10"/>
                                    <path d="M12 15V3"/>
                                </svg>
                                Export to PDF
                            </button>
                        </div>
                    </form>
                </div>

                <!-- AI Insights Section -->
                <div class="ai-section">
                    <div class="ai-header">
                        <i>🤖</i>
                        <h2>AI-Powered Insights</h2>
                    </div>
                    
                    <div class="ai-grid">
                        <div class="ai-card">
                            <h3>Predicted Next Month</h3>
                            <div class="value"><?php echo $ai_predictions['next_month_visits']; ?></div>
                            <div class="label">Estimated Clinic Visits</div>
                            <div class="trend up">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 15L12 9L6 15"/>
                                </svg>
                                Based on 30-day trend
                            </div>
                        </div>
                        
                        <div class="ai-card">
                            <h3>Peak Hours</h3>
                            <?php if (!empty($ai_predictions['peak_hours'])): ?>
                                <?php foreach ($ai_predictions['peak_hours'] as $hour): ?>
                                    <div class="value"><?php echo date('g A', strtotime($hour['hour'] . ':00')); ?></div>
                                    <div class="label"><?php echo $hour['visit_count']; ?> visits during this hour</div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="value">N/A</div>
                                <div class="label">Insufficient data</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="ai-card">
                            <h3>Common Complaints</h3>
                            <?php if (!empty($ai_predictions['common_complaints'])): ?>
                                <?php foreach ($ai_predictions['common_complaints'] as $complaint): ?>
                                    <div class="value"><?php echo round($complaint['percentage']); ?>%</div>
                                    <div class="label"><?php echo $complaint['complaint']; ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="value">N/A</div>
                                <div class="label">No data available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Anomaly Alerts -->
                <?php if (!empty($anomalies)): ?>
                    <?php if (!empty($anomalies['incident_spikes'])): ?>
                        <div class="anomaly-alert">
                            <strong>⚠️ Anomaly Detected:</strong> Unusual spike in incidents on 
                            <?php foreach ($anomalies['incident_spikes'] as $spike): ?>
                                <?php echo date('M d', strtotime($spike['date'])); ?> 
                                (<?php echo $spike['daily_count']; ?> incidents)
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($anomalies['usage_spikes'])): ?>
                        <div class="anomaly-alert">
                            <strong>⚠️ Usage Anomaly:</strong> Unusual medicine consumption for 
                            <?php foreach ($anomalies['usage_spikes'] as $spike): ?>
                                <?php echo $spike['item_name']; ?> 
                                (<?php echo $spike['total_used']; ?> units used)
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Smart Recommendations -->
                <?php if (!empty($recommendations)): ?>
                <div class="recommendations-section">
                    <div class="recommendations-header">
                        <i style="font-size: 24px;">💡</i>
                        <h2>Smart Recommendations</h2>
                    </div>
                    
                    <div class="recommendations-grid">
                        <?php foreach ($recommendations as $rec): ?>
                        <div class="recommendation-item priority-<?php echo $rec['priority']; ?>">
                            <div class="recommendation-icon" style="background: <?php 
                                echo $rec['type'] == 'restock' ? '#ffebee' : 
                                    ($rec['type'] == 'expiry' ? '#fff3e0' : 
                                    ($rec['type'] == 'staffing' ? '#e3f2fd' : '#e8f5e9')); 
                            ?>">
                                <?php 
                                echo $rec['type'] == 'restock' ? '📦' : 
                                    ($rec['type'] == 'expiry' ? '⚠️' : 
                                    ($rec['type'] == 'staffing' ? '👥' : '🏥')); 
                                ?>
                            </div>
                            <div class="recommendation-content">
                                <div class="recommendation-title"><?php echo ucfirst($rec['type']); ?> Recommendation</div>
                                <div class="recommendation-message"><?php echo $rec['message']; ?></div>
                            </div>
                            <div class="recommendation-badge badge-<?php echo $rec['priority']; ?>">
                                <?php echo $rec['priority']; ?> priority
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Charts Section -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Incidents by Type</h3>
                        <div class="chart-container">
                            <canvas id="incidentChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Clearance Request Status</h3>
                        <div class="chart-container">
                            <canvas id="clearanceChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Medicine Request Status</h3>
                        <div class="chart-container">
                            <canvas id="requestChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Monthly Trend</h3>
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <?php
                    // Get totals for stats
                    $total_incidents = 0;
                    foreach ($incident_chart as $item) {
                        $total_incidents += $item['count'];
                    }
                    
                    $total_clearance = 0;
                    foreach ($clearance_chart as $item) {
                        $total_clearance += $item['count'];
                    }
                    
                    $total_requests = 0;
                    foreach ($request_chart as $item) {
                        $total_requests += $item['count'];
                    }
                    
                    $total_visits = 0;
                    foreach ($daily_visits as $item) {
                        $total_visits += $item['count'];
                    }
                    ?>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                <path d="M2 17L12 22L22 17"/>
                                <path d="M2 12L12 17L22 12"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_incidents; ?></h3>
                            <p>Total Incidents</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_clearance; ?></h3>
                            <p>Clearance Requests</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M10.5 4.5L19.5 9.5L12 14L3 9.5L10.5 4.5Z"/>
                                <path d="M3 14.5L10.5 19L19.5 14.5"/>
                                <path d="M3 9.5V19.5"/>
                                <path d="M19.5 9.5V19.5"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_requests; ?></h3>
                            <p>Medicine Requests</p>
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
                            <h3><?php echo $total_visits; ?></h3>
                            <p>Clinic Visits</p>
                        </div>
                    </div>
                </div>

                <!-- Data Preview -->
                <div class="preview-section">
                    <div class="section-header">
                        <h2>Data Preview - <?php echo ucwords(str_replace('_', ' ', $report_type)); ?></h2>
                        <span class="record-count"><?php echo $preview_count; ?> records found</span>
                    </div>
                    <div class="table-wrapper">
                        <?php if (empty($preview_data)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#546e7a" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8V12L12 16"/>
                                </svg>
                                <p style="margin-top: 16px;">No data available for the selected filters.</p>
                            </div>
                        <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php
                                    if (!empty($preview_data)) {
                                        $first_row = $preview_data[0];
                                        foreach (array_keys($first_row) as $key) {
                                            if (!in_array($key, ['id', 'password', 'token'])) {
                                                echo '<th>' . ucwords(str_replace('_', ' ', $key)) . '</th>';
                                            }
                                        }
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_data as $row): ?>
                                <tr>
                                    <?php foreach ($row as $key => $value): ?>
                                        <?php if (!in_array($key, ['id', 'password', 'token'])): ?>
                                        <td>
                                            <?php 
                                            if (is_null($value) || $value === '') {
                                                echo 'N/A';
                                            } elseif (strpos($key, 'date') !== false || strpos($key, 'Date') !== false) {
                                                if (strtotime($value)) {
                                                    echo date('Y-m-d', strtotime($value));
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                            } elseif (strlen($value) > 30) {
                                                echo htmlspecialchars(substr($value, 0, 30)) . '...';
                                            } else {
                                                echo htmlspecialchars($value);
                                            }
                                            ?>
                                        </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
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

        // Reset filters
        function resetFilters() {
            document.getElementById('report_type').value = 'ai_insights';
            document.getElementById('date_from').value = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('search').value = '';
            document.getElementById('full').checked = true;
            document.getElementById('reportForm').submit();
        }

        // Refresh AI Insights
        function refreshAIInsights() {
            location.reload();
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Incident Chart
            const incidentCtx = document.getElementById('incidentChart').getContext('2d');
            new Chart(incidentCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($incident_chart, 'incident_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($incident_chart, 'count')); ?>,
                        backgroundColor: ['#191970', '#ff9800', '#f44336'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Clearance Chart
            const clearanceCtx = document.getElementById('clearanceChart').getContext('2d');
            new Chart(clearanceCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($clearance_chart, 'status')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($clearance_chart, 'count')); ?>,
                        backgroundColor: ['#4caf50', '#ff9800', '#f44336', '#9e9e9e'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Request Chart
            const requestCtx = document.getElementById('requestChart').getContext('2d');
            new Chart(requestCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($request_chart, 'status')); ?>,
                    datasets: [{
                        label: 'Number of Requests',
                        data: <?php echo json_encode(array_column($request_chart, 'count')); ?>,
                        backgroundColor: '#191970',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const monthlyLabels = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
            const monthlyCounts = <?php echo json_encode(array_column($monthly_trend, 'count')); ?>;
            
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: monthlyLabels.map(month => {
                        const [year, monthNum] = month.split('-');
                        return new Date(year, monthNum - 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Monthly Visits',
                        data: monthlyCounts,
                        borderColor: '#191970',
                        backgroundColor: 'rgba(25, 25, 112, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#191970',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#eceff1'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        });

        // Form validation
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (dateFrom && dateTo && dateFrom > dateTo) {
                e.preventDefault();
                alert('Date From cannot be later than Date To');
            }
        });
    </script>
</body>
</html>