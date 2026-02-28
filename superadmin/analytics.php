<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin (or superadmin)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get filter parameters
$date_range = isset($_REQUEST['date_range']) ? $_REQUEST['date_range'] : '30days';
$custom_date_from = isset($_REQUEST['custom_date_from']) ? $_REQUEST['custom_date_from'] : date('Y-m-d', strtotime('-30 days'));
$custom_date_to = isset($_REQUEST['custom_date_to']) ? $_REQUEST['custom_date_to'] : date('Y-m-d');

// Set date range based on selection
switch ($date_range) {
    case '7days':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = date('Y-m-d');
        break;
    case '30days':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
        break;
    case '90days':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        $date_to = date('Y-m-d');
        break;
    case 'year':
        $date_from = date('Y-m-d', strtotime('-1 year'));
        $date_to = date('Y-m-d');
        break;
    case 'custom':
        $date_from = $custom_date_from;
        $date_to = $custom_date_to;
        break;
    default:
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
}

// Get chart data
$chart_data = [];

// 1. Visit Trends (Last 30 days)
$query = "SELECT 
            DATE(visit_date) as date,
            COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date BETWEEN :date_from AND :date_to 
          GROUP BY DATE(visit_date) 
          ORDER BY date ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$visit_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['visit_trends'] = $visit_trends;

// 2. Incident Types Distribution
$query = "SELECT incident_type, COUNT(*) as count 
          FROM incidents 
          WHERE incident_date BETWEEN :date_from AND :date_to 
          GROUP BY incident_type";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$incident_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['incident_types'] = $incident_types;

// 3. Clearance Status Distribution
$query = "SELECT status, COUNT(*) as count 
          FROM clearance_requests 
          WHERE request_date BETWEEN :date_from AND :date_to 
          GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$clearance_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['clearance_status'] = $clearance_status;

// 4. Medicine Request Status
$query = "SELECT status, COUNT(*) as count 
          FROM medicine_requests 
          WHERE DATE(requested_date) BETWEEN :date_from AND :date_to 
          GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$request_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['request_status'] = $request_status;

// 5. Top 5 Most Common Complaints
$query = "SELECT complaint, COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date BETWEEN :date_from AND :date_to 
          AND complaint IS NOT NULL 
          AND complaint != ''
          GROUP BY complaint 
          ORDER BY count DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$top_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['top_complaints'] = $top_complaints;

// 6. Stock Status
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
$stock_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['stock_status'] = $stock_status;

// 7. Physical Exam Fit Status
$query = "SELECT fit_for_school, COUNT(*) as count 
          FROM physical_exam_records 
          WHERE exam_date BETWEEN :date_from AND :date_to 
          GROUP BY fit_for_school";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$fit_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['fit_status'] = $fit_status;

// 8. Top 5 Most Dispensed Items
$query = "SELECT item_name, SUM(quantity) as total_quantity 
          FROM dispensing_log 
          WHERE DATE(dispensed_date) BETWEEN :date_from AND :date_to 
          GROUP BY item_name 
          ORDER BY total_quantity DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$top_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['top_items'] = $top_items;

// 9. Grade Level Distribution for Visits
$query = "SELECT 
            LEFT(grade_section, LOCATE(' - ', grade_section) - 1) as grade_level,
            COUNT(*) as count 
          FROM visit_history 
          WHERE visit_date BETWEEN :date_from AND :date_to 
          AND grade_section IS NOT NULL 
          GROUP BY grade_level 
          ORDER BY count DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$grade_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['grade_distribution'] = $grade_distribution;

// 10. Clearance Types Distribution
$query = "SELECT clearance_type, COUNT(*) as count 
          FROM clearance_requests 
          WHERE request_date BETWEEN :date_from AND :date_to 
          GROUP BY clearance_type";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$clearance_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
$chart_data['clearance_types'] = $clearance_types;

// Get key metrics for stats cards
$stats = [];

// Total visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total incidents
$query = "SELECT COUNT(*) as total FROM incidents WHERE incident_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_incidents'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total clearance requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE request_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_clearance'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total medicine requests
$query = "SELECT COUNT(*) as total FROM medicine_requests WHERE DATE(requested_date) BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total items dispensed
$query = "SELECT SUM(quantity) as total FROM dispensing_log WHERE DATE(dispensed_date) BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_dispensed'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Low stock count
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Expiring soon (next 30 days)
$expiry_threshold = date('Y-m-d', strtotime('+30 days'));
$query = "SELECT COUNT(*) as total FROM clinic_stock WHERE expiry_date <= :threshold AND expiry_date >= CURDATE()";
$stmt = $db->prepare($query);
$stmt->bindParam(':threshold', $expiry_threshold);
$stmt->execute();
$stats['expiring_soon'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending clearance requests
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
$stmt = $db->query($query);
$stats['pending_clearance'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Pending medicine requests
$query = "SELECT COUNT(*) as total FROM medicine_requests WHERE status = 'pending'";
$stmt = $db->query($query);
$stats['pending_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// 11. Emergency Cases
$query = "SELECT COUNT(*) as total FROM emergency_cases WHERE DATE(created_at) BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_emergency'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// 12. Physical Exams
$query = "SELECT COUNT(*) as total FROM physical_exam_records WHERE exam_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_exams'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// 13. Medical Certificates
$query = "SELECT COUNT(*) as total FROM medical_certificates WHERE issued_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$stats['total_certificates'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Calculate percentage changes (compare with previous period)
$previous_date_from = date('Y-m-d', strtotime($date_from . ' -' . (strtotime($date_to) - strtotime($date_from)) / (60*60*24) . ' days'));
$previous_date_to = date('Y-m-d', strtotime($date_from . ' -1 day'));

// Previous period visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE visit_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $previous_date_from);
$stmt->bindParam(':date_to', $previous_date_to);
$stmt->execute();
$previous_visits = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stats['visits_change'] = $previous_visits > 0 ? round((($stats['total_visits'] - $previous_visits) / $previous_visits) * 100, 1) : 0;

// Previous period incidents
$query = "SELECT COUNT(*) as total FROM incidents WHERE incident_date BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $previous_date_from);
$stmt->bindParam(':date_to', $previous_date_to);
$stmt->execute();
$previous_incidents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stats['incidents_change'] = $previous_incidents > 0 ? round((($stats['total_incidents'] - $previous_incidents) / $previous_incidents) * 100, 1) : 0;

// AI Analytics Functions

// Function to generate AI insights based on data
function generateAIInsights($chart_data, $stats, $date_from, $date_to, $db) {
    $insights = [];
    
    // Insight 1: Peak activity times
    $query = "SELECT 
                HOUR(visit_time) as hour,
                COUNT(*) as count 
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to 
              GROUP BY HOUR(visit_time) 
              ORDER BY count DESC 
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $peak_hour = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($peak_hour && $peak_hour['count'] > 0) {
        $hour = $peak_hour['hour'];
        $period = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        $formatted_hour = $hour . ':00 - ' . ($hour + 1) . ':00';
        $insights[] = [
            'type' => 'positive',
            'title' => 'Peak Clinic Hours',
            'message' => "Most visits occur between {$formatted_hour} ({$period}). Consider scheduling additional staff during this time.",
            'icon' => '🕐'
        ];
    }
    
    // Insight 2: Low stock alert
    if ($stats['low_stock'] > 0) {
        $insights[] = [
            'type' => 'warning',
            'title' => 'Low Stock Alert',
            'message' => "You have {$stats['low_stock']} item(s) with low stock levels. Please reorder soon.",
            'icon' => '⚠️'
        ];
    }
    
    // Insight 3: Expiring items
    if ($stats['expiring_soon'] > 0) {
        $insights[] = [
            'type' => 'warning',
            'title' => 'Expiring Items',
            'message' => "{$stats['expiring_soon']} item(s) will expire within the next 30 days. Plan for usage or disposal.",
            'icon' => '📅'
        ];
    }
    
    // Insight 4: Pending requests
    if ($stats['pending_requests'] > 0) {
        $insights[] = [
            'type' => 'info',
            'title' => 'Pending Medicine Requests',
            'message' => "You have {$stats['pending_requests']} pending medicine request(s) awaiting approval.",
            'icon' => '💊'
        ];
    }
    
    // Insight 5: Pending clearances
    if ($stats['pending_clearance'] > 0) {
        $insights[] = [
            'type' => 'info',
            'title' => 'Pending Clearance Requests',
            'message' => "There are {$stats['pending_clearance']} clearance request(s) pending review.",
            'icon' => '📋'
        ];
    }
    
    // Insight 6: Most common complaint
    if (!empty($chart_data['top_complaints'])) {
        $top = $chart_data['top_complaints'][0];
        $insights[] = [
            'type' => 'neutral',
            'title' => 'Most Common Complaint',
            'message' => "'{$top['complaint']}' is the most frequent complaint with {$top['count']} cases in this period.",
            'icon' => '🔍'
        ];
    }
    
    // Insight 7: Visit trend analysis
    if (!empty($chart_data['visit_trends']) && count($chart_data['visit_trends']) >= 7) {
        $recent = array_slice($chart_data['visit_trends'], -7);
        $avg_recent = array_sum(array_column($recent, 'count')) / 7;
        $older = array_slice($chart_data['visit_trends'], 0, 7);
        $avg_older = array_sum(array_column($older, 'count')) / 7;
        
        $trend = $avg_recent > $avg_older ? 'increasing' : ($avg_recent < $avg_older ? 'decreasing' : 'stable');
        $percent_change = $avg_older > 0 ? round((($avg_recent - $avg_older) / $avg_older) * 100, 1) : 0;
        
        if ($trend == 'increasing' && $percent_change > 10) {
            $insights[] = [
                'type' => 'trend_up',
                'title' => 'Increasing Visit Trend',
                'message' => "Clinic visits are up by {$percent_change}% compared to the previous week.",
                'icon' => '📈'
            ];
        } elseif ($trend == 'decreasing' && $percent_change < -10) {
            $insights[] = [
                'type' => 'trend_down',
                'title' => 'Decreasing Visit Trend',
                'message' => "Clinic visits have decreased by " . abs($percent_change) . "% compared to the previous week.",
                'icon' => '📉'
            ];
        }
    }
    
    // Insight 8: Incident type focus
    if (!empty($chart_data['incident_types'])) {
        $max_incident = null;
        $max_count = 0;
        foreach ($chart_data['incident_types'] as $type) {
            if ($type['count'] > $max_count) {
                $max_count = $type['count'];
                $max_incident = $type['incident_type'];
            }
        }
        if ($max_incident) {
            $insights[] = [
                'type' => 'neutral',
                'title' => 'Most Common Incident Type',
                'message' => "'{$max_incident}' accounts for the majority of incidents ({$max_count} cases).",
                'icon' => '🚨'
            ];
        }
    }
    
    // Insight 9: Grade level with most visits
    if (!empty($chart_data['grade_distribution'])) {
        $top_grade = $chart_data['grade_distribution'][0];
        $insights[] = [
            'type' => 'neutral',
            'title' => 'Most Active Grade Level',
            'message' => "Grade {$top_grade['grade_level']} has the most clinic visits with {$top_grade['count']} visits.",
            'icon' => '🎓'
        ];
    }
    
    // Insight 10: Clearance type analysis
    if (!empty($chart_data['clearance_types'])) {
        $max_clearance = null;
        $max_count = 0;
        foreach ($chart_data['clearance_types'] as $type) {
            if ($type['count'] > $max_count) {
                $max_count = $type['count'];
                $max_clearance = $type['clearance_type'];
            }
        }
        if ($max_clearance) {
            $insights[] = [
                'type' => 'neutral',
                'title' => 'Most Requested Clearance',
                'message' => "'{$max_clearance}' is the most requested clearance type with {$max_count} requests.",
                'icon' => '✅'
            ];
        }
    }
    
    // Insight 11: Most dispensed item
    if (!empty($chart_data['top_items'])) {
        $top_item = $chart_data['top_items'][0];
        $insights[] = [
            'type' => 'neutral',
            'title' => 'Most Used Item',
            'message' => "'{$top_item['item_name']}' is the most dispensed item ({$top_item['total_quantity']} units).",
            'icon' => '💊'
        ];
    }
    
    // Insight 12: Emergency cases percentage
    if ($stats['total_incidents'] > 0) {
        $emergency_percent = round(($stats['total_emergency'] / $stats['total_incidents']) * 100, 1);
        if ($emergency_percent > 20) {
            $insights[] = [
                'type' => 'warning',
                'title' => 'High Emergency Rate',
                'message' => "Emergency cases make up {$emergency_percent}% of all incidents. Review safety protocols.",
                'icon' => '🚑'
            ];
        }
    }
    
    // Insight 13: Fit status analysis
    if (!empty($chart_data['fit_status'])) {
        $not_fit = 0;
        $total = 0;
        foreach ($chart_data['fit_status'] as $status) {
            $total += $status['count'];
            if ($status['fit_for_school'] == 'No' || $status['fit_for_school'] == 'With Restrictions') {
                $not_fit += $status['count'];
            }
        }
        if ($total > 0) {
            $not_fit_percent = round(($not_fit / $total) * 100, 1);
            if ($not_fit_percent > 15) {
                $insights[] = [
                    'type' => 'info',
                    'title' => 'Health Restrictions',
                    'message' => "{$not_fit_percent}% of students have health restrictions or are not fit for school activities.",
                    'icon' => '🏥'
                ];
            }
        }
    }
    
    // Insight 14: Weekend vs weekday comparison
    $query = "SELECT 
                CASE 
                    WHEN DAYOFWEEK(visit_date) IN (1,7) THEN 'Weekend'
                    ELSE 'Weekday'
                END as day_type,
                COUNT(*) as count 
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to 
              GROUP BY day_type";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $day_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $weekday_count = 0;
    $weekend_count = 0;
    foreach ($day_types as $day) {
        if ($day['day_type'] == 'Weekday') {
            $weekday_count = $day['count'];
        } else {
            $weekend_count = $day['count'];
        }
    }
    
    if ($weekend_count > ($weekday_count * 0.3)) { // More than 30% of weekday traffic on weekends
        $insights[] = [
            'type' => 'info',
            'title' => 'Weekend Activity',
            'message' => "Significant clinic activity occurs on weekends. Consider weekend staffing adjustments.",
            'icon' => '📆'
        ];
    }
    
    // Insight 15: Clearance approval rate
    if ($stats['total_clearance'] > 0) {
        $approved = 0;
        foreach ($chart_data['clearance_status'] as $status) {
            if ($status['status'] == 'Approved') {
                $approved = $status['count'];
            }
        }
        $approval_rate = round(($approved / $stats['total_clearance']) * 100, 1);
        if ($approval_rate < 50) {
            $insights[] = [
                'type' => 'warning',
                'title' => 'Low Clearance Approval Rate',
                'message' => "Only {$approval_rate}% of clearance requests are approved. Review rejection reasons.",
                'icon' => '❌'
            ];
        }
    }
    
    // Limit to top 8 insights (to avoid overwhelming)
    return array_slice($insights, 0, 8);
}

// Generate AI insights
$ai_insights = generateAIInsights($chart_data, $stats, $date_from, $date_to, $db);

// Get recent activity for the table
$query = "SELECT 
            'Visit' as type,
            vh.student_id as student_id,
            vh.student_name as student_name,
            vh.visit_date as date,
            vh.complaint as description,
            u.full_name as attended_by
          FROM visit_history vh
          LEFT JOIN users u ON vh.attended_by = u.id
          WHERE vh.visit_date BETWEEN :date_from AND :date_to
          UNION ALL
          SELECT 
            'Incident' as type,
            i.student_id,
            i.student_name,
            i.incident_date,
            i.description,
            i.reporter_name
          FROM incidents i
          WHERE i.incident_date BETWEEN :date_from AND :date_to
          UNION ALL
          SELECT 
            'Clearance' as type,
            cr.student_id,
            cr.student_name,
            cr.request_date,
            cr.purpose,
            cr.approved_by
          FROM clearance_requests cr
          WHERE cr.request_date BETWEEN :date_from AND :date_to
          ORDER BY date DESC
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    .btn-outline {
        background: transparent;
        color: #191970;
        border: 1px solid #191970;
    }

    .btn-outline:hover {
        background: rgba(25, 25, 112, 0.05);
        transform: translateY(-2px);
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

    .filter-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    /* AI Insights Section */
    .ai-insights {
        background: linear-gradient(135deg, #191970 0%, #24248f 100%);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        color: white;
        animation: fadeInUp 0.7s ease;
        box-shadow: 0 8px 24px rgba(25, 25, 112, 0.3);
    }

    .ai-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }

    .ai-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
    }

    .ai-header .badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .insights-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }

    .insight-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        padding: 16px;
        transition: all 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .insight-card:hover {
        transform: translateY(-4px);
        background: rgba(255, 255, 255, 0.15);
    }

    .insight-icon {
        font-size: 24px;
        margin-bottom: 12px;
    }

    .insight-title {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 8px;
        opacity: 0.9;
    }

    .insight-message {
        font-size: 0.8rem;
        line-height: 1.4;
        opacity: 0.8;
    }

    .insight-card.warning {
        background: rgba(255, 152, 0, 0.2);
        border-left: 3px solid #ff9800;
    }

    .insight-card.positive {
        background: rgba(76, 175, 80, 0.2);
        border-left: 3px solid #4caf50;
    }

    .insight-card.info {
        background: rgba(33, 150, 243, 0.2);
        border-left: 3px solid #2196f3;
    }

    .insight-card.trend_up {
        background: rgba(76, 175, 80, 0.2);
        border-left: 3px solid #4caf50;
    }

    .insight-card.trend_down {
        background: rgba(244, 67, 54, 0.2);
        border-left: 3px solid #f44336;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
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

    .stat-trend {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 0.8rem;
        margin-top: 4px;
    }

    .trend-up {
        color: #4caf50;
    }

    .trend-down {
        color: #f44336;
    }

    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.9s ease;
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
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .chart-card h3 span {
        font-size: 0.8rem;
        font-weight: 400;
        color: #78909c;
    }

    .chart-container {
        height: 250px;
        position: relative;
    }

    /* Recent Activity */
    .recent-activity {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 1s ease;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .section-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .badge {
        padding: 4px 12px;
        background: #eceff1;
        border-radius: 20px;
        font-size: 0.8rem;
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

    .type-badge {
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }

    .type-visit {
        background: #e3f2fd;
        color: #1976d2;
    }

    .type-incident {
        background: #ffebee;
        color: #c62828;
    }

    .type-clearance {
        background: #e8f5e9;
        color: #2e7d32;
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
        .insights-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .charts-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
            padding: 20px 15px;
        }
        
        .insights-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .filter-grid {
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
                <div class="page-header">
                    <div>
                        <h1>Analytics Dashboard</h1>
                        <p>AI-powered insights and key metrics for informed decision making</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline" onclick="refreshData()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 4V10H17"/>
                                <path d="M1 20V14H7"/>
                                <path d="M3.51 9C4.01 7.6 4.81 6.3 5.86 5.25C7.38 3.73 9.33 2.75 11.4 2.38C13.47 2.01 15.61 2.27 17.5 3.1C19.4 3.94 20.99 5.29 22.1 6.99"/>
                                <path d="M20.49 15C19.99 16.4 19.19 17.7 18.14 18.75C16.62 20.27 14.67 21.25 12.6 21.62C10.53 21.99 8.39 21.73 6.5 20.9C4.6 20.06 3.01 18.71 1.9 17.01"/>
                            </svg>
                            Refresh
                        </button>
                        <button class="btn btn-secondary" onclick="exportAnalytics()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15"/>
                                <path d="M7 10L12 15L17 10"/>
                                <path d="M12 15V3"/>
                            </svg>
                            Export
                        </button>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h2>Date Range Filter</h2>
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="date_range">Date Range</label>
                                <select name="date_range" id="date_range" onchange="toggleCustomDates()">
                                    <option value="7days" <?php echo $date_range == '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="30days" <?php echo $date_range == '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                                    <option value="90days" <?php echo $date_range == '90days' ? 'selected' : ''; ?>>Last 90 Days</option>
                                    <option value="year" <?php echo $date_range == 'year' ? 'selected' : ''; ?>>Last Year</option>
                                    <option value="custom" <?php echo $date_range == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                </select>
                            </div>
                            <div class="filter-group" id="custom_date_from_group" style="<?php echo $date_range != 'custom' ? 'display: none;' : ''; ?>">
                                <label for="custom_date_from">From Date</label>
                                <input type="date" name="custom_date_from" id="custom_date_from" value="<?php echo $custom_date_from; ?>">
                            </div>
                            <div class="filter-group" id="custom_date_to_group" style="<?php echo $date_range != 'custom' ? 'display: none;' : ''; ?>">
                                <label for="custom_date_to">To Date</label>
                                <input type="date" name="custom_date_to" id="custom_date_to" value="<?php echo $custom_date_to; ?>">
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
                        </div>
                    </form>
                </div>

                <!-- AI Insights Section -->
                <?php if (!empty($ai_insights)): ?>
                <div class="ai-insights">
                    <div class="ai-header">
                        <h2>🤖 AI-Powered Insights</h2>
                        <span class="badge">Real-time Analytics</span>
                    </div>
                    <div class="insights-grid">
                        <?php foreach ($ai_insights as $insight): ?>
                        <div class="insight-card <?php echo $insight['type']; ?>">
                            <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                            <div class="insight-title"><?php echo $insight['title']; ?></div>
                            <div class="insight-message"><?php echo $insight['message']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_visits']; ?></h3>
                            <p>Total Visits</p>
                            <div class="stat-trend">
                                <?php if ($stats['visits_change'] > 0): ?>
                                <span class="trend-up">↑ <?php echo $stats['visits_change']; ?>%</span>
                                <?php elseif ($stats['visits_change'] < 0): ?>
                                <span class="trend-down">↓ <?php echo abs($stats['visits_change']); ?>%</span>
                                <?php else: ?>
                                <span>→ 0%</span>
                                <?php endif; ?>
                                <span>vs previous period</span>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🚨</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_incidents']; ?></h3>
                            <p>Incidents</p>
                            <div class="stat-trend">
                                <?php if ($stats['incidents_change'] > 0): ?>
                                <span class="trend-up">↑ <?php echo $stats['incidents_change']; ?>%</span>
                                <?php elseif ($stats['incidents_change'] < 0): ?>
                                <span class="trend-down">↓ <?php echo abs($stats['incidents_change']); ?>%</span>
                                <?php else: ?>
                                <span>→ 0%</span>
                                <?php endif; ?>
                                <span>vs previous</span>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">💊</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_dispensed']; ?></h3>
                            <p>Items Dispensed</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📋</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_requests']; ?></h3>
                            <p>Pending Requests</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_clearance']; ?></h3>
                            <p>Clearances</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['low_stock']; ?></h3>
                            <p>Low Stock Items</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['expiring_soon']; ?></h3>
                            <p>Expiring Soon</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🏥</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_exams']; ?></h3>
                            <p>Physical Exams</p>
                        </div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Visit Trends <span><?php echo date('M d', strtotime($date_from)); ?> - <?php echo date('M d', strtotime($date_to)); ?></span></h3>
                        <div class="chart-container">
                            <canvas id="visitTrendsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Incident Distribution</h3>
                        <div class="chart-container">
                            <canvas id="incidentChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Clearance Status</h3>
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
                        <h3>Top 5 Complaints</h3>
                        <div class="chart-container">
                            <canvas id="complaintsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Stock Status</h3>
                        <div class="chart-container">
                            <canvas id="stockChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Physical Exam Fit Status</h3>
                        <div class="chart-container">
                            <canvas id="fitStatusChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Top 5 Dispensed Items</h3>
                        <div class="chart-container">
                            <canvas id="topItemsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="recent-activity">
                    <div class="section-header">
                        <h2>Recent Activity</h2>
                        <span class="badge">Last 10 Records</span>
                    </div>
                    <div class="table-wrapper">
                        <?php if (empty($recent_activity)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#546e7a" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8V12L12 16"/>
                                </svg>
                                <p style="margin-top: 16px;">No activity data available for the selected period.</p>
                            </div>
                        <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Attended/Approved By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="type-badge type-<?php echo strtolower($activity['type']); ?>">
                                            <?php echo $activity['type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['student_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($activity['date'])); ?></td>
                                    <td><?php echo htmlspecialchars(substr($activity['description'], 0, 30)) . (strlen($activity['description']) > 30 ? '...' : ''); ?></td>
                                    <td><?php echo htmlspecialchars($activity['attended_by'] ?? 'N/A'); ?></td>
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

        // Toggle custom date inputs
        function toggleCustomDates() {
            const dateRange = document.getElementById('date_range').value;
            const customFrom = document.getElementById('custom_date_from_group');
            const customTo = document.getElementById('custom_date_to_group');
            
            if (dateRange === 'custom') {
                customFrom.style.display = 'block';
                customTo.style.display = 'block';
            } else {
                customFrom.style.display = 'none';
                customTo.style.display = 'none';
            }
        }

        // Refresh data
        function refreshData() {
            location.reload();
        }

        // Export analytics
        function exportAnalytics() {
            // Create a printable version
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Analytics Report - <?php echo date('Y-m-d'); ?></title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 20px; }
                            h1 { color: #191970; }
                            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
                            th { background: #191970; color: white; padding: 10px; text-align: left; }
                            td { padding: 8px; border: 1px solid #ddd; }
                            .section { margin-bottom: 30px; }
                        </style>
                    </head>
                    <body>
                        <h1>Analytics Report</h1>
                        <p>Generated: <?php echo date('F d, Y h:i A'); ?></p>
                        <p>Period: <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></p>
                        
                        <div class="section">
                            <h2>Key Metrics</h2>
                            <table>
                                <tr><th>Metric</th><th>Value</th></tr>
                                <tr><td>Total Visits</td><td><?php echo $stats['total_visits']; ?></td></tr>
                                <tr><td>Total Incidents</td><td><?php echo $stats['total_incidents']; ?></td></tr>
                                <tr><td>Items Dispensed</td><td><?php echo $stats['total_dispensed']; ?></td></tr>
                                <tr><td>Clearance Requests</td><td><?php echo $stats['total_clearance']; ?></td></tr>
                                <tr><td>Low Stock Items</td><td><?php echo $stats['low_stock']; ?></td></tr>
                                <tr><td>Expiring Soon</td><td><?php echo $stats['expiring_soon']; ?></td></tr>
                            </table>
                        </div>
                        
                        <div class="section">
                            <h2>Recent Activity</h2>
                            <table>
                                <tr><th>Type</th><th>Student</th><th>Date</th><th>Description</th></tr>
                                <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <td><?php echo $activity['type']; ?></td>
                                    <td><?php echo $activity['student_name']; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($activity['date'])); ?></td>
                                    <td><?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Visit Trends Chart
            const visitCtx = document.getElementById('visitTrendsChart').getContext('2d');
            const visitDates = <?php echo json_encode(array_column($visit_trends, 'date')); ?>;
            const visitCounts = <?php echo json_encode(array_column($visit_trends, 'count')); ?>;
            
            new Chart(visitCtx, {
                type: 'line',
                data: {
                    labels: visitDates.map(date => {
                        const d = new Date(date);
                        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Visits',
                        data: visitCounts,
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

            // Incident Chart
            const incidentCtx = document.getElementById('incidentChart').getContext('2d');
            new Chart(incidentCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($incident_types, 'incident_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($incident_types, 'count')); ?>,
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
                    labels: <?php echo json_encode(array_column($clearance_status, 'status')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($clearance_status, 'count')); ?>,
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
                    labels: <?php echo json_encode(array_column($request_status, 'status')); ?>,
                    datasets: [{
                        label: 'Number of Requests',
                        data: <?php echo json_encode(array_column($request_status, 'count')); ?>,
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

            // Complaints Chart
            const complaintsCtx = document.getElementById('complaintsChart').getContext('2d');
            new Chart(complaintsCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($top_complaints, 'complaint')); ?>,
                    datasets: [{
                        label: 'Number of Cases',
                        data: <?php echo json_encode(array_column($top_complaints, 'count')); ?>,
                        backgroundColor: '#ff9800',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                display: false
                            }
                        },
                        y: {
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

            // Stock Chart
            const stockCtx = document.getElementById('stockChart').getContext('2d');
            new Chart(stockCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($stock_status, 'status')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($stock_status, 'count')); ?>,
                        backgroundColor: ['#4caf50', '#ff9800', '#f44336'],
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

            // Fit Status Chart
            const fitCtx = document.getElementById('fitStatusChart').getContext('2d');
            new Chart(fitCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($fit_status, 'fit_for_school')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($fit_status, 'count')); ?>,
                        backgroundColor: ['#4caf50', '#ff9800', '#f44336'],
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

            // Top Items Chart
            const itemsCtx = document.getElementById('topItemsChart').getContext('2d');
            new Chart(itemsCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($top_items, 'item_name')); ?>,
                    datasets: [{
                        label: 'Quantity Dispensed',
                        data: <?php echo json_encode(array_column($top_items, 'total_quantity')); ?>,
                        backgroundColor: '#2196f3',
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
        });

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Analytics';
        }

        // Form validation
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const dateRange = document.getElementById('date_range').value;
            
            if (dateRange === 'custom') {
                const dateFrom = document.getElementById('custom_date_from').value;
                const dateTo = document.getElementById('custom_date_to').value;
                
                if (!dateFrom || !dateTo) {
                    e.preventDefault();
                    alert('Please select both from and to dates for custom range');
                } else if (dateFrom > dateTo) {
                    e.preventDefault();
                    alert('From date cannot be later than to date');
                }
            }
        });
    </script>
</body>
</html>