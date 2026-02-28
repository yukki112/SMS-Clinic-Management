<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and has admin or superadmin role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header('Location: ../login.php');
    exit();
}

// Include FPDF library
require_once '../vendor/setasign/fpdf/fpdf.php';

$database = new Database();
$db = $database->getConnection();

// Initialize variables
$show_verification_modal = false;
$verification_error = '';
$export_params = [];

// Check if export was requested and verification is needed
if (isset($_POST['export']) && $_POST['export'] == 'pdf' && !isset($_SESSION['verified_export'])) {
    $show_verification_modal = true;
    // Store export parameters for after verification
    $export_params = [
        'report_type' => $_POST['report_type'] ?? 'summary',
        'date_from' => $_POST['date_from'] ?? date('Y-m-d', strtotime('-30 days')),
        'date_to' => $_POST['date_to'] ?? date('Y-m-d'),
        'export_type' => $_POST['export_type'] ?? 'full',
        'search' => $_POST['search'] ?? ''
    ];
    $_SESSION['pending_export'] = $export_params;
}

// Handle verification submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_export'])) {
    $user_id = $_SESSION['user_id'];
    $password = $_POST['password'];
    
    // Verify password
    $query = "SELECT password FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['verified_export'] = true;
        
        // Get pending export parameters
        if (isset($_SESSION['pending_export'])) {
            $params = $_SESSION['pending_export'];
            exportToPDF(
                $params['report_type'],
                $params['date_from'],
                $params['date_to'],
                $params['export_type'],
                $params['search'],
                $db
            );
            exit();
        }
    } else {
        $verification_error = "Invalid password. Export denied.";
        $show_verification_modal = true;
    }
}

// Clear verification if no export in progress
if (!isset($_SESSION['pending_export']) && isset($_SESSION['verified_export'])) {
    unset($_SESSION['verified_export']);
}

// Get filter parameters for display
$report_type = isset($_REQUEST['report_type']) ? $_REQUEST['report_type'] : 'summary';
$date_from = isset($_REQUEST['date_from']) ? $_REQUEST['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_REQUEST['date_to']) ? $_REQUEST['date_to'] : date('Y-m-d');
$export_type = isset($_REQUEST['export_type']) ? $_REQUEST['export_type'] : 'full';
$search = isset($_REQUEST['search']) ? $_REQUEST['search'] : '';

// AI Insights Functions
function getAIPredictions($db, $date_from, $date_to) {
    $predictions = [];
    
    // Predict future incidents based on historical patterns
    $query = "SELECT 
                DATE(incident_date) as date,
                COUNT(*) as incident_count,
                incident_type
              FROM incidents 
              WHERE incident_date BETWEEN DATE_SUB(:date_to, INTERVAL 30 DAY) AND :date_to
              GROUP BY DATE(incident_date), incident_type
              ORDER BY date DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $incident_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average incidents per day for prediction
    if (count($incident_history) > 0) {
        $total_incidents = count($incident_history);
        $days_analyzed = 30;
        $avg_daily_incidents = $total_incidents / $days_analyzed;
        
        // Predict next 7 days
        $predicted_incidents = round($avg_daily_incidents * 7);
        $predictions['next_week_incidents'] = [
            'value' => $predicted_incidents,
            'confidence' => min(85, 50 + ($total_incidents * 2)), // Higher confidence with more data
            'trend' => $avg_daily_incidents > 1 ? 'increasing' : 'stable'
        ];
    }
    
    // Predict medicine stock depletion
    $query = "SELECT 
                cs.item_name,
                cs.item_code,
                cs.quantity,
                cs.minimum_stock,
                cs.unit,
                COALESCE(SUM(dl.quantity), 0) as monthly_usage
              FROM clinic_stock cs
              LEFT JOIN dispensing_log dl ON cs.item_code = dl.item_code 
                AND dl.dispensed_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              WHERE cs.category = 'Medicine'
              GROUP BY cs.id
              HAVING cs.quantity > 0";
    $stmt = $db->query($query);
    $stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $predictions['stock_alerts'] = [];
    foreach ($stock_items as $item) {
        $monthly_usage = $item['monthly_usage'];
        if ($monthly_usage > 0) {
            $months_remaining = $item['quantity'] / $monthly_usage;
            $days_remaining = round($months_remaining * 30);
            
            if ($days_remaining <= 30) {
                $predictions['stock_alerts'][] = [
                    'item_name' => $item['item_name'],
                    'item_code' => $item['item_code'],
                    'days_remaining' => $days_remaining,
                    'current_quantity' => $item['quantity'],
                    'monthly_usage' => $monthly_usage,
                    'unit' => $item['unit'],
                    'priority' => $days_remaining <= 7 ? 'high' : ($days_remaining <= 14 ? 'medium' : 'low')
                ];
            }
        }
    }
    
    // Predict peak clinic hours
    $query = "SELECT 
                HOUR(visit_time) as hour,
                COUNT(*) as visit_count,
                DAYNAME(visit_date) as day_name
              FROM visit_history 
              WHERE visit_date BETWEEN :date_from AND :date_to
              GROUP BY HOUR(visit_time), DAYNAME(visit_date)
              ORDER BY visit_count DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $predictions['peak_hours'] = $peak_hours;
    
    return $predictions;
}

function detectAnomalies($db, $date_from, $date_to) {
    $anomalies = [];
    
    // Detect unusual incident patterns
    $query = "SELECT 
                incident_type,
                COUNT(*) as type_count,
                (SELECT AVG(cnt) FROM 
                    (SELECT COUNT(*) as cnt 
                     FROM incidents 
                     WHERE incident_date BETWEEN DATE_SUB(:date_to, INTERVAL 90 DAY) AND :date_to
                     GROUP BY incident_type) as avg_counts) as historical_avg
              FROM incidents 
              WHERE incident_date BETWEEN :date_from AND :date_to
              GROUP BY incident_type
              HAVING type_count > historical_avg * 2";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $incident_anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($incident_anomalies as $anomaly) {
        $anomalies[] = [
            'type' => 'incident_pattern',
            'severity' => 'high',
            'message' => "Unusual spike in {$anomaly['incident_type']} incidents detected",
            'details' => "Current: {$anomaly['type_count']} vs Average: " . round($anomaly['historical_avg'])
        ];
    }
    
    // Detect unusual medicine dispensing patterns
    $query = "SELECT 
                item_name,
                COUNT(*) as dispense_count,
                (SELECT AVG(cnt) FROM 
                    (SELECT COUNT(*) as cnt 
                     FROM dispensing_log 
                     WHERE dispensed_date BETWEEN DATE_SUB(:date_to, INTERVAL 90 DAY) AND :date_to
                     GROUP BY item_name) as avg_counts) as historical_avg
              FROM dispensing_log 
              WHERE dispensed_date BETWEEN :date_from AND :date_to
              GROUP BY item_name
              HAVING dispense_count > historical_avg * 2.5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $medicine_anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($medicine_anomalies as $anomaly) {
        $anomalies[] = [
            'type' => 'medicine_usage',
            'severity' => 'medium',
            'message' => "Unusual increase in {$anomaly['item_name']} dispensing",
            'details' => "Current: {$anomaly['dispense_count']} vs Average: " . round($anomaly['historical_avg'])
        ];
    }
    
    return $anomalies;
}

function getSeasonalTrends($db, $date_from, $date_to) {
    $trends = [];
    
    // Analyze monthly patterns
    $query = "SELECT 
                MONTH(visit_date) as month,
                COUNT(*) as visit_count,
                GROUP_CONCAT(DISTINCT complaint) as common_complaints
              FROM visit_history 
              WHERE visit_date BETWEEN DATE_SUB(:date_to, INTERVAL 12 MONTH) AND :date_to
              GROUP BY MONTH(visit_date)
              ORDER BY month";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find peak months
    $max_visits = 0;
    $peak_month = '';
    foreach ($monthly_trends as $trend) {
        if ($trend['visit_count'] > $max_visits) {
            $max_visits = $trend['visit_count'];
            $peak_month = DateTime::createFromFormat('!m', $trend['month'])->format('F');
        }
    }
    
    if ($max_visits > 0) {
        $trends['peak_season'] = [
            'month' => $peak_month,
            'average_visits' => round($max_visits)
        ];
    }
    
    // Get common seasonal complaints
    $current_month = date('n', strtotime($date_to));
    $query = "SELECT 
                complaint,
                COUNT(*) as frequency
              FROM visit_history 
              WHERE MONTH(visit_date) = :current_month
              AND visit_date BETWEEN DATE_SUB(:date_to, INTERVAL 2 YEAR) AND :date_to
              GROUP BY complaint
              ORDER BY frequency DESC
              LIMIT 3";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':current_month', $current_month);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $seasonal_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $trends['seasonal_complaints'] = $seasonal_complaints;
    
    return $trends;
}

function getSmartRecommendations($db, $predictions, $anomalies, $seasonal_trends) {
    $recommendations = [];
    
    // Stock-related recommendations
    if (!empty($predictions['stock_alerts'])) {
        $high_priority = array_filter($predictions['stock_alerts'], function($alert) {
            return $alert['priority'] === 'high';
        });
        
        if (count($high_priority) > 0) {
            $items = array_column($high_priority, 'item_name');
            $recommendations[] = [
                'type' => 'urgent',
                'icon' => '⚠️',
                'title' => 'Critical Stock Alert',
                'message' => 'Immediate reorder needed for: ' . implode(', ', array_slice($items, 0, 3)) . 
                            (count($items) > 3 ? ' and ' . (count($items) - 3) . ' more items' : ''),
                'action' => 'Review Stock'
            ];
        }
    }
    
    // Incident pattern recommendations
    foreach ($anomalies as $anomaly) {
        if ($anomaly['type'] === 'incident_pattern' && $anomaly['severity'] === 'high') {
            $recommendations[] = [
                'type' => 'preventive',
                'icon' => '🛡️',
                'title' => 'Preventive Action Needed',
                'message' => $anomaly['message'] . '. Consider reviewing safety protocols.',
                'action' => 'Review Incidents'
            ];
            break;
        }
    }
    
    // Seasonal preparation recommendations
    if (!empty($seasonal_trends['peak_season'])) {
        $current_month = date('F');
        $peak_month = $seasonal_trends['peak_season']['month'];
        
        // Check if peak season is approaching (within 2 months)
        $current_month_num = date('n');
        $peak_month_num = date('n', strtotime($peak_month . ' 1'));
        $months_diff = ($peak_month_num - $current_month_num + 12) % 12;
        
        if ($months_diff <= 2 && $months_diff > 0) {
            $recommendations[] = [
                'type' => 'preparation',
                'icon' => '📅',
                'title' => 'Peak Season Preparation',
                'message' => "Prepare for peak clinic visits in {$peak_month} (avg {$seasonal_trends['peak_season']['average_visits']} visits). Stock up on common medications.",
                'action' => 'Prepare Stock'
            ];
        }
    }
    
    // Common seasonal complaints recommendations
    if (!empty($seasonal_trends['seasonal_complaints'])) {
        $complaints = array_column($seasonal_trends['seasonal_complaints'], 'complaint');
        $recommendations[] = [
            'type' => 'health_advisory',
            'icon' => '💊',
            'title' => 'Seasonal Health Advisory',
            'message' => 'Common this month: ' . implode(', ', array_slice($complaints, 0, 3)) . 
                        '. Ensure adequate supplies for these conditions.',
            'action' => 'Check Supplies'
        ];
    }
    
    // Workload prediction
    if (isset($predictions['next_week_incidents'])) {
        $trend = $predictions['next_week_incidents']['trend'];
        if ($trend === 'increasing') {
            $recommendations[] = [
                'type' => 'workload',
                'icon' => '📊',
                'title' => 'Increasing Incident Trend',
                'message' => "Predicted {$predictions['next_week_incidents']['value']} incidents next week. Consider adjusting staff schedule.",
                'action' => 'View Schedule'
            ];
        }
    }
    
    // Peak hours optimization
    if (!empty($predictions['peak_hours'])) {
        $peak = $predictions['peak_hours'][0];
        $hour = $peak['hour'];
        $period = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
        $recommendations[] = [
            'type' => 'optimization',
            'icon' => '⏰',
            'title' => 'Peak Hours Optimization',
            'message' => "Busiest time: {$peak['day_name']}s at " . 
                        date('g A', strtotime("{$hour}:00")) . 
                        ". Consider optimizing staff schedule during these hours.",
            'action' => 'Optimize Schedule'
        ];
    }
    
    return $recommendations;
}

// Get AI insights
$predictions = getAIPredictions($db, $date_from, $date_to);
$anomalies = detectAnomalies($db, $date_from, $date_to);
$seasonal_trends = getSeasonalTrends($db, $date_from, $date_to);
$recommendations = getSmartRecommendations($db, $predictions, $anomalies, $seasonal_trends);

// Function to export to PDF (existing function - keep as is)
function exportToPDF($report_type, $date_from, $date_to, $export_type, $search, $db) {
    // ... (keep your existing exportToPDF function code here)
    // Create new PDF instance
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('Arial', 'B', 16);
    
    // Title
    $pdf->Cell(0, 10, 'Clinic Management System - Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Report Type: ' . ucwords(str_replace('_', ' ', $report_type)), 0, 1, 'C');
    $pdf->Cell(0, 6, 'Date Range: ' . date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to)), 0, 1, 'C');
    if (!empty($search)) {
        $pdf->Cell(0, 6, 'Search: ' . $search, 0, 1, 'C');
    }
    $pdf->Ln(10);

    // Get data based on report type
    $data = getReportData($report_type, $date_from, $date_to, $export_type, $search, $db);
    
    // Generate report based on type
    switch ($report_type) {
        case 'users':
            generateUsersReport($pdf, $data);
            break;
        case 'patients':
            generatePatientsReport($pdf, $data);
            break;
        case 'incidents':
            generateIncidentsReport($pdf, $data);
            break;
        case 'medicine_dispensed':
            generateMedicineDispensedReport($pdf, $data);
            break;
        case 'medicine_requests':
            generateMedicineRequestsReport($pdf, $data);
            break;
        case 'clearance_requests':
            generateClearanceRequestsReport($pdf, $data);
            break;
        case 'visit_history':
            generateVisitHistoryReport($pdf, $data);
            break;
        case 'clinic_stock':
            generateClinicStockReport($pdf, $data);
            break;
        case 'physical_exams':
            generatePhysicalExamsReport($pdf, $data);
            break;
        case 'medical_certificates':
            generateMedicalCertificatesReport($pdf, $data);
            break;
        case 'emergency_cases':
            generateEmergencyCasesReport($pdf, $data);
            break;
        default:
            generateSummaryReport($pdf, $data, $db, $date_from, $date_to);
    }
    
    // Clear verification after export
    unset($_SESSION['verified_export']);
    unset($_SESSION['pending_export']);
    
    // Output PDF
    $filename = $report_type . '_report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
}

// Function to get report data (keep your existing function)
function getReportData($report_type, $date_from, $date_to, $export_type, $search, $db) {
    $data = [];
    $params = [];
    
    switch ($report_type) {
        case 'users':
            $query = "SELECT id, username, email, full_name, role, created_at 
                      FROM users 
                      WHERE 1=1";
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (username LIKE :search OR email LIKE :search OR full_name LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY created_at DESC";
            break;
            
        case 'patients':
            $query = "SELECT id, patient_id, full_name, email, phone, date_of_birth, gender, blood_group, created_at 
                      FROM patients 
                      WHERE 1=1";
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (patient_id LIKE :search OR full_name LIKE :search OR email LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY created_at DESC";
            break;
            
        case 'incidents':
            $query = "SELECT i.*, u.username as reporter_username 
                      FROM incidents i 
                      LEFT JOIN users u ON i.created_by = u.id 
                      WHERE DATE(i.incident_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (i.student_name LIKE :search OR i.incident_code LIKE :search OR i.description LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY i.incident_date DESC, i.incident_time DESC";
            break;
            
        case 'medicine_dispensed':
            $query = "SELECT d.*, u.full_name as dispensed_by_name, vh.complaint as visit_reason 
                      FROM dispensing_log d 
                      LEFT JOIN users u ON d.dispensed_by = u.id 
                      LEFT JOIN visit_history vh ON d.visit_id = vh.id 
                      WHERE DATE(d.dispensed_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (d.student_name LIKE :search OR d.item_name LIKE :search OR d.item_code LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY d.dispensed_date DESC";
            break;
            
        case 'medicine_requests':
            $query = "SELECT mr.* 
                      FROM medicine_requests mr 
                      WHERE DATE(mr.requested_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (mr.item_name LIKE :search OR mr.item_code LIKE :search OR mr.requested_by_name LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY mr.requested_date DESC";
            break;
            
        case 'clearance_requests':
            $query = "SELECT cr.* 
                      FROM clearance_requests cr 
                      WHERE DATE(cr.request_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (cr.student_name LIKE :search OR cr.clearance_code LIKE :search OR cr.purpose LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY cr.request_date DESC";
            break;
            
        case 'visit_history':
            $query = "SELECT vh.*, u.full_name as attended_by_name 
                      FROM visit_history vh 
                      LEFT JOIN users u ON vh.attended_by = u.id 
                      WHERE DATE(vh.visit_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (vh.student_id LIKE :search OR vh.complaint LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY vh.visit_date DESC, vh.visit_time DESC";
            break;
            
        case 'clinic_stock':
            $query = "SELECT cs.* 
                      FROM clinic_stock cs 
                      WHERE 1=1";
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (cs.item_name LIKE :search OR cs.item_code LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY cs.item_name ASC";
            break;
            
        case 'physical_exams':
            $query = "SELECT pe.* 
                      FROM physical_exam_records pe 
                      WHERE DATE(pe.exam_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (pe.student_name LIKE :search OR pe.student_id LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY pe.exam_date DESC";
            break;
            
        case 'medical_certificates':
            $query = "SELECT mc.* 
                      FROM medical_certificates mc 
                      WHERE DATE(mc.issued_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (mc.student_name LIKE :search OR mc.certificate_code LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY mc.issued_date DESC";
            break;
            
        case 'emergency_cases':
            $query = "SELECT ec.*, i.incident_code, i.student_name, i.incident_type 
                      FROM emergency_cases ec 
                      LEFT JOIN incidents i ON ec.incident_id = i.id 
                      WHERE DATE(ec.created_at) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (ec.student_id LIKE :search OR i.incident_code LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY ec.created_at DESC";
            break;
            
        default:
            return [];
    }
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Report generation functions (keep all your existing functions)
function generateUsersReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'User Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(20, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Username', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Full Name', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Role', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Email', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Created', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(20, 6, $row['id'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, $row['username'], 1, 0, 'L', $fill);
        $pdf->Cell(50, 6, substr($row['full_name'], 0, 20), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, ucfirst($row['role']), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, substr($row['email'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, date('Y-m-d', strtotime($row['created_at'])), 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    
    // Summary
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Users: ' . count($data), 0, 1);
}

function generatePatientsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Patients Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(30, 8, 'Patient ID', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Full Name', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Gender', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Blood', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Phone', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Birth Date', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Created', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['patient_id'], 1, 0, 'C', $fill);
        $pdf->Cell(45, 6, substr($row['full_name'], 0, 18), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['gender'] ?? 'N/A', 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['blood_group'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, $row['phone'] ?? 'N/A', 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, $row['date_of_birth'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, date('Y-m-d', strtotime($row['created_at'])), 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Patients: ' . count($data), 0, 1);
}

function generateIncidentsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Incidents Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Location', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Description', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['incident_code'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['incident_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, substr($row['incident_type'], 0, 10), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, substr($row['location'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(50, 6, substr($row['description'], 0, 25), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    // Type summary
    $incident = 0; $minor = 0; $emergency = 0;
    foreach ($data as $row) {
        switch ($row['incident_type']) {
            case 'Incident': $incident++; break;
            case 'Minor Injury': $minor++; break;
            case 'Emergency': $emergency++; break;
        }
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Incidents: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Incidents: ' . $incident . ' | Minor: ' . $minor . ' | Emergency: ' . $emergency, 0, 1);
}

function generateMedicineDispensedReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Medicine Dispensed Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(35, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Unit', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Dispensed By', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Date/Time', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    $total_quantity = 0;
    
    foreach ($data as $row) {
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($row['item_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $row['quantity'], 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['unit'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['dispensed_by_name'] ?? 'N/A', 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, date('Y-m-d H:i', strtotime($row['dispensed_date'])), 1, 1, 'C', $fill);
        $fill = !$fill;
        $total_quantity += $row['quantity'];
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Dispensed Items: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Total Quantity Dispensed: ' . $total_quantity, 0, 1);
}

function generateMedicineRequestsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Medicine Requests Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Req Qty', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'App Qty', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Category', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Urgency', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Requested By', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['request_code'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, substr($row['item_name'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $row['quantity_requested'], 1, 0, 'C', $fill);
        $pdf->Cell(15, 6, $row['quantity_approved'] ?? '0', 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['category'], 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, ucfirst($row['urgency']), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, ucfirst($row['status']), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, substr($row['requested_by_name'], 0, 10), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    // Status summary
    $pending = 0; $approved = 0; $released = 0; $rejected = 0;
    foreach ($data as $row) {
        switch ($row['status']) {
            case 'pending': $pending++; break;
            case 'approved': $approved++; break;
            case 'released': $released++; break;
            case 'rejected': $rejected++; break;
        }
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Requests: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Pending: ' . $pending . ' | Approved: ' . $approved . ' | Released: ' . $released . ' | Rejected: ' . $rejected, 0, 1);
}

function generateClearanceRequestsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Health Clearance Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Req Date', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Valid Until', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Purpose', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['clearance_code'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, substr($row['clearance_type'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['request_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['status'], 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, $row['valid_until'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, substr($row['purpose'], 0, 20), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Clearances: ' . count($data), 0, 1);
}

function generateVisitHistoryReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Visit History Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Student ID', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Temp', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'BP', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Complaint', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Treatment', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Disposition', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['student_id'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['visit_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['temperature'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['blood_pressure'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, substr($row['complaint'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($row['treatment_given'] ?? '', 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, $row['disposition'], 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Visits: ' . count($data), 0, 1);
}

function generateClinicStockReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Clinic Stock Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Item Code', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Category', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Unit', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Expiry', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Min Stock', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    $low_stock_count = 0;
    $expired_count = 0;
    $today = date('Y-m-d');
    
    foreach ($data as $row) {
        $status = 'Normal';
        
        if ($row['quantity'] <= $row['minimum_stock']) {
            $status = 'Low Stock';
            $low_stock_count++;
        }
        
        if ($row['expiry_date'] && $row['expiry_date'] < $today) {
            $status = 'Expired';
            $expired_count++;
        }
        
        $pdf->Cell(25, 6, $row['item_code'], 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, substr($row['item_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['category'], 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $row['quantity'], 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['unit'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['expiry_date'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['minimum_stock'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $status, 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Items: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Low Stock Items: ' . $low_stock_count, 0, 1);
    $pdf->Cell(0, 6, 'Expired Items: ' . $expired_count, 0, 1);
}

function generatePhysicalExamsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Physical Exam Records Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Student ID', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Student Name', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Exam Date', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Height', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Weight', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'BMI', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Vision', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Fit Status', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['student_id'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['exam_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(15, 6, $row['height'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(15, 6, $row['weight'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(15, 6, $row['bmi'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, ($row['vision_left'] ?? 'N/A') . '/' . ($row['vision_right'] ?? 'N/A'), 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $row['fit_for_school'] ?? 'N/A', 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Exams: ' . count($data), 0, 1);
}

function generateMedicalCertificatesReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Medical Certificates Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Certificate', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Issued', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Valid Until', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Issued By', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['certificate_code'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, substr($row['certificate_type'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['issued_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['valid_until'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['issued_by'], 0, 15), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Certificates: ' . count($data), 0, 1);
}

function generateEmergencyCasesReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Emergency Cases Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 8, 'Incident ID', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Student ID', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Response Time', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Ambulance', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Hospital', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Outcome', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(25, 6, $row['incident_id'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $row['student_id'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $row['response_time'], 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['ambulance_called'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['hospital_referred'] ?? 'N/A', 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, substr($row['outcome'] ?? 'N/A', 0, 20), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Emergency Cases: ' . count($data), 0, 1);
}

function generateSummaryReport($pdf, $data, $db, $date_from, $date_to) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'System Summary Report', 0, 1, 'L');
    $pdf->Ln(10);
    
    // Get counts for all tables
    $tables = [
        'users' => 'users',
        'patients' => 'patients',
        'incidents' => 'incidents',
        'medicine_requests' => 'medicine_requests',
        'clearance_requests' => 'clearance_requests',
        'clinic_stock' => 'clinic_stock',
        'visit_history' => 'visit_history',
        'physical_exam_records' => 'physical_exam_records',
        'medical_certificates' => 'medical_certificates',
        'emergency_cases' => 'emergency_cases',
        'dispensing_log' => 'dispensing_log'
    ];
    
    $counts = [];
    foreach ($tables as $key => $table) {
        $stmt = $db->query("SELECT COUNT(*) as total FROM $table");
        $counts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    // Get date range counts
    $date_counts = [];
    $date_queries = [
        'incidents' => "SELECT COUNT(*) as total FROM incidents WHERE DATE(incident_date) BETWEEN '$date_from' AND '$date_to'",
        'medicine_requests' => "SELECT COUNT(*) as total FROM medicine_requests WHERE DATE(requested_date) BETWEEN '$date_from' AND '$date_to'",
        'clearance_requests' => "SELECT COUNT(*) as total FROM clearance_requests WHERE DATE(request_date) BETWEEN '$date_from' AND '$date_to'",
        'visit_history' => "SELECT COUNT(*) as total FROM visit_history WHERE DATE(visit_date) BETWEEN '$date_from' AND '$date_to'",
        'dispensing_log' => "SELECT COUNT(*) as total FROM dispensing_log WHERE DATE(dispensed_date) BETWEEN '$date_from' AND '$date_to'"
    ];
    
    foreach ($date_queries as $key => $query) {
        $stmt = $db->query($query);
        $date_counts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    // Display summary
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Overall System Statistics', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(70, 8, 'Total Users:', 0, 0);
    $pdf->Cell(30, 8, $counts['users'], 0, 1);
    
    $pdf->Cell(70, 8, 'Total Patients:', 0, 0);
    $pdf->Cell(30, 8, $counts['patients'], 0, 1);
    
    $pdf->Cell(70, 8, 'Total Incidents:', 0, 0);
    $pdf->Cell(30, 8, $counts['incidents'], 0, 1);
    
    $pdf->Cell(70, 8, 'Medicine Requests:', 0, 0);
    $pdf->Cell(30, 8, $counts['medicine_requests'], 0, 1);
    
    $pdf->Cell(70, 8, 'Clearance Requests:', 0, 0);
    $pdf->Cell(30, 8, $counts['clearance_requests'], 0, 1);
    
    $pdf->Cell(70, 8, 'Clinic Stock Items:', 0, 0);
    $pdf->Cell(30, 8, $counts['clinic_stock'], 0, 1);
    
    $pdf->Cell(70, 8, 'Visit History:', 0, 0);
    $pdf->Cell(30, 8, $counts['visit_history'], 0, 1);
    
    $pdf->Cell(70, 8, 'Physical Exams:', 0, 0);
    $pdf->Cell(30, 8, $counts['physical_exam_records'], 0, 1);
    
    $pdf->Cell(70, 8, 'Medical Certificates:', 0, 0);
    $pdf->Cell(30, 8, $counts['medical_certificates'], 0, 1);
    
    $pdf->Cell(70, 8, 'Emergency Cases:', 0, 0);
    $pdf->Cell(30, 8, $counts['emergency_cases'], 0, 1);
    
    $pdf->Cell(70, 8, 'Medicine Dispensed:', 0, 0);
    $pdf->Cell(30, 8, $counts['dispensing_log'], 0, 1);
    
    // Date range summary
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Selected Period Statistics', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, 'Report Period: ' . date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to)), 0, 1);
    $pdf->Ln(5);
    
    $pdf->Cell(70, 8, 'Incidents:', 0, 0);
    $pdf->Cell(30, 8, $date_counts['incidents'], 0, 1);
    
    $pdf->Cell(70, 8, 'Medicine Requests:', 0, 0);
    $pdf->Cell(30, 8, $date_counts['medicine_requests'], 0, 1);
    
    $pdf->Cell(70, 8, 'Clearance Requests:', 0, 0);
    $pdf->Cell(30, 8, $date_counts['clearance_requests'], 0, 1);
    
    $pdf->Cell(70, 8, 'Visit History:', 0, 0);
    $pdf->Cell(30, 8, $date_counts['visit_history'], 0, 1);
    
    $pdf->Cell(70, 8, 'Medicine Dispensed:', 0, 0);
    $pdf->Cell(30, 8, $date_counts['dispensing_log'], 0, 1);
}

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
    <title>Reports & AI Analytics - Super Admin | MedFlow Clinic Management System</title>
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

    /* AI Insights Section */
    .ai-insights-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        padding: 30px;
        margin-bottom: 30px;
        color: white;
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.5s ease;
    }

    .ai-insights-section::before {
        content: '✨';
        position: absolute;
        top: 10px;
        right: 20px;
        font-size: 40px;
        opacity: 0.2;
    }

    .ai-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 25px;
    }

    .ai-icon {
        width: 50px;
        height: 50px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        backdrop-filter: blur(5px);
    }

    .ai-header h2 {
        font-size: 1.8rem;
        font-weight: 600;
    }

    .ai-header p {
        opacity: 0.9;
        font-size: 1rem;
    }

    .ai-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-top: 20px;
    }

    .ai-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 20px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
    }

    .ai-card:hover {
        transform: translateY(-5px);
        background: rgba(255, 255, 255, 0.15);
    }

    .ai-card-title {
        font-size: 1rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.8;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ai-card-value {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .ai-card-label {
        font-size: 0.9rem;
        opacity: 0.7;
    }

    .confidence-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        display: inline-block;
        margin-top: 10px;
    }

    .trend-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        margin-left: 10px;
    }

    .trend-up { background: rgba(76, 175, 80, 0.3); color: #c8e6c9; }
    .trend-down { background: rgba(244, 67, 54, 0.3); color: #ffcdd2; }
    .trend-stable { background: rgba(255, 152, 0, 0.3); color: #ffe0b2; }

    /* Anomaly Alerts */
    .anomaly-alert {
        background: rgba(244, 67, 54, 0.15);
        border-left: 4px solid #f44336;
        padding: 15px;
        border-radius: 12px;
        margin-top: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .anomaly-icon {
        font-size: 24px;
    }

    .anomaly-content {
        flex: 1;
    }

    .anomaly-title {
        font-weight: 600;
        margin-bottom: 5px;
    }

    .anomaly-details {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    /* Recommendations */
    .recommendations-section {
        margin-top: 20px;
    }

    .recommendation-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .recommendation-item:last-child {
        border-bottom: none;
    }

    .rec-icon {
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }

    .rec-content {
        flex: 1;
    }

    .rec-title {
        font-weight: 600;
        margin-bottom: 3px;
    }

    .rec-message {
        font-size: 0.85rem;
        opacity: 0.8;
    }

    .rec-action {
        padding: 6px 12px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .rec-action:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    /* Peak Hours Tags */
    .peak-hours-tag {
        display: inline-block;
        padding: 4px 12px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        font-size: 0.85rem;
        margin: 5px;
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

    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        animation: fadeIn 0.3s ease;
    }

    .modal-container {
        background: white;
        border-radius: 24px;
        width: 90%;
        max-width: 450px;
        padding: 30px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        animation: slideUp 0.3s ease;
    }

    .modal-icon {
        width: 70px;
        height: 70px;
        background: #191970;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        color: white;
    }

    .modal-icon svg {
        width: 35px;
        height: 35px;
    }

    .modal-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #191970;
        text-align: center;
        margin-bottom: 10px;
    }

    .modal-subtitle {
        color: #546e7a;
        text-align: center;
        margin-bottom: 25px;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .modal-form {
        margin-top: 20px;
    }

    .modal-form .form-group {
        margin-bottom: 20px;
    }

    .modal-form label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: #546e7a;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .modal-form .form-control {
        width: 100%;
        padding: 14px 16px;
        font-size: 1rem;
        border: 2px solid #cfd8dc;
        border-radius: 12px;
        transition: all 0.3s ease;
    }

    .modal-form .form-control:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 25px;
    }

    .modal-btn {
        flex: 1;
        padding: 14px;
        border: none;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .modal-btn.primary {
        background: #191970;
        color: white;
    }

    .modal-btn.primary:hover {
        background: #24248f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
    }

    .modal-btn.secondary {
        background: #eceff1;
        color: #37474f;
    }

    .modal-btn.secondary:hover {
        background: #cfd8dc;
    }

    .modal-error {
        background: #ffebee;
        border: 1px solid #ffcdd2;
        border-radius: 12px;
        padding: 12px 16px;
        color: #c62828;
        font-size: 0.9rem;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
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
        .charts-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .ai-grid {
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
        
        .ai-grid {
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
                        <h1>Reports & AI Analytics</h1>
                        <p>Intelligent insights and predictive analytics for your clinic</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-secondary" onclick="resetFilters()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6H21M6 12H18M10 18H14" stroke-linecap="round"/>
                            </svg>
                            Reset Filters
                        </button>
                    </div>
                </div>

                <!-- AI Insights Section -->
                <div class="ai-insights-section">
                    <div class="ai-header">
                        <div class="ai-icon">🤖</div>
                        <div>
                            <h2>AI-Powered Insights</h2>
                            <p>Real-time analysis and predictions based on your clinic data</p>
                        </div>
                    </div>

                    <!-- Prediction Cards -->
                    <div class="ai-grid">
                        <?php if (isset($predictions['next_week_incidents'])): ?>
                        <div class="ai-card">
                            <div class="ai-card-title">
                                <span>📊</span> Incident Forecast
                            </div>
                            <div class="ai-card-value">
                                <?php echo $predictions['next_week_incidents']['value']; ?>
                                <span style="font-size: 1rem; opacity: 0.7;">next 7 days</span>
                            </div>
                            <div class="ai-card-label">Predicted incidents</div>
                            <div class="confidence-badge">
                                🤖 AI Confidence: <?php echo $predictions['next_week_incidents']['confidence']; ?>%
                            </div>
                            <span class="trend-indicator trend-<?php echo $predictions['next_week_incidents']['trend']; ?>">
                                <?php if ($predictions['next_week_incidents']['trend'] == 'increasing'): ?>
                                    📈 Increasing trend
                                <?php elseif ($predictions['next_week_incidents']['trend'] == 'decreasing'): ?>
                                    📉 Decreasing trend
                                <?php else: ?>
                                    ➡️ Stable trend
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Stock Alerts Summary -->
                        <div class="ai-card">
                            <div class="ai-card-title">
                                <span>💊</span> Stock Alerts
                            </div>
                            <div class="ai-card-value">
                                <?php echo count($predictions['stock_alerts'] ?? []); ?>
                            </div>
                            <div class="ai-card-label">Items need attention</div>
                            <?php if (!empty($predictions['stock_alerts'])): ?>
                                <?php 
                                $urgent = array_filter($predictions['stock_alerts'], function($item) {
                                    return $item['priority'] == 'high';
                                });
                                ?>
                                <?php if (count($urgent) > 0): ?>
                                    <div class="confidence-badge" style="background: rgba(244, 67, 54, 0.3);">
                                        ⚠️ <?php echo count($urgent); ?> urgent reorder needed
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Peak Season -->
                        <?php if (!empty($seasonal_trends['peak_season'])): ?>
                        <div class="ai-card">
                            <div class="ai-card-title">
                                <span>📅</span> Peak Season
                            </div>
                            <div class="ai-card-value">
                                <?php echo $seasonal_trends['peak_season']['month']; ?>
                            </div>
                            <div class="ai-card-label">
                                Busiest month (avg <?php echo $seasonal_trends['peak_season']['average_visits']; ?> visits)
                            </div>
                            <?php 
                            $current_month_num = date('n');
                            $peak_month_num = date('n', strtotime($seasonal_trends['peak_season']['month'] . ' 1'));
                            $months_until_peak = ($peak_month_num - $current_month_num + 12) % 12;
                            if ($months_until_peak <= 2 && $months_until_peak > 0):
                            ?>
                            <div class="confidence-badge" style="background: rgba(255, 193, 7, 0.3);">
                                ⏰ Peak season in <?php echo $months_until_peak; ?> month(s)
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Peak Hours -->
                        <?php if (!empty($predictions['peak_hours'])): ?>
                        <div class="ai-card">
                            <div class="ai-card-title">
                                <span>⏰</span> Peak Hours
                            </div>
                            <div>
                                <?php foreach ($predictions['peak_hours'] as $peak): ?>
                                    <span class="peak-hours-tag">
                                        <?php echo substr($peak['day_name'], 0, 3); ?> 
                                        <?php echo date('gA', strtotime($peak['hour'] . ':00')); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <div class="ai-card-label" style="margin-top: 10px;">
                                Busiest times - Consider staff optimization
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Anomaly Alerts -->
                    <?php if (!empty($anomalies)): ?>
                        <?php foreach ($anomalies as $anomaly): ?>
                        <div class="anomaly-alert">
                            <div class="anomaly-icon">⚠️</div>
                            <div class="anomaly-content">
                                <div class="anomaly-title"><?php echo $anomaly['message']; ?></div>
                                <div class="anomaly-details"><?php echo $anomaly['details']; ?></div>
                            </div>
                            <div class="rec-action">Investigate</div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Smart Recommendations -->
                    <?php if (!empty($recommendations)): ?>
                    <div class="recommendations-section">
                        <h3 style="margin-bottom: 15px;">🤖 Smart Recommendations</h3>
                        <?php foreach ($recommendations as $rec): ?>
                        <div class="recommendation-item">
                            <div class="rec-icon"><?php echo $rec['icon']; ?></div>
                            <div class="rec-content">
                                <div class="rec-title"><?php echo $rec['title']; ?></div>
                                <div class="rec-message"><?php echo $rec['message']; ?></div>
                            </div>
                            <div class="rec-action"><?php echo $rec['action']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Seasonal Complaints -->
                    <?php if (!empty($seasonal_trends['seasonal_complaints'])): ?>
                    <div style="margin-top: 20px;">
                        <h4 style="margin-bottom: 10px; opacity: 0.9;">🌡️ Common Seasonal Complaints</h4>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <?php foreach ($seasonal_trends['seasonal_complaints'] as $complaint): ?>
                                <span class="peak-hours-tag">
                                    <?php echo $complaint['complaint']; ?> 
                                    (<?php echo $complaint['frequency']; ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h2>Report Filters</h2>
                    <form method="POST" action="" id="reportForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="report_type">Report Type</label>
                                <select name="report_type" id="report_type">
                                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>System Summary</option>
                                    <option value="users" <?php echo $report_type == 'users' ? 'selected' : ''; ?>>Users Report</option>
                                    <option value="patients" <?php echo $report_type == 'patients' ? 'selected' : ''; ?>>Patients Report</option>
                                    <option value="incidents" <?php echo $report_type == 'incidents' ? 'selected' : ''; ?>>Incidents Report</option>
                                    <option value="medicine_dispensed" <?php echo $report_type == 'medicine_dispensed' ? 'selected' : ''; ?>>Medicine Dispensed</option>
                                    <option value="medicine_requests" <?php echo $report_type == 'medicine_requests' ? 'selected' : ''; ?>>Medicine Requests</option>
                                    <option value="clearance_requests" <?php echo $report_type == 'clearance_requests' ? 'selected' : ''; ?>>Health Clearance</option>
                                    <option value="visit_history" <?php echo $report_type == 'visit_history' ? 'selected' : ''; ?>>Visit History</option>
                                    <option value="clinic_stock" <?php echo $report_type == 'clinic_stock' ? 'selected' : ''; ?>>Clinic Stock</option>
                                    <option value="physical_exams" <?php echo $report_type == 'physical_exams' ? 'selected' : ''; ?>>Physical Exams</option>
                                    <option value="medical_certificates" <?php echo $report_type == 'medical_certificates' ? 'selected' : ''; ?>>Medical Certificates</option>
                                    <option value="emergency_cases" <?php echo $report_type == 'emergency_cases' ? 'selected' : ''; ?>>Emergency Cases</option>
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
                        <h3>Daily Visits (Last 7 Days)</h3>
                        <div class="chart-container">
                            <canvas id="visitsChart"></canvas>
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
                                            if (!in_array($key, ['id', 'password', 'token'])) { // Skip sensitive fields
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

    <!-- Security Verification Modal -->
    <?php if ($show_verification_modal): ?>
    <div class="modal-overlay" id="verificationModal">
        <div class="modal-container">
            <div class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <h2 class="modal-title">Secure Export Required</h2>
            <p class="modal-subtitle">
                You are about to export sensitive report data.<br>
                Please verify your identity to continue.
            </p>
            
            <?php if (isset($verification_error)): ?>
                <div class="modal-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?php echo $verification_error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="modal-form">
                <div class="form-group">
                    <label for="password">Enter Your Password to Continue</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="••••••••" required autofocus>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn secondary" onclick="cancelExport()">Cancel</button>
                    <button type="submit" name="verify_export" class="modal-btn primary">Verify & Export</button>
                </div>
            </form>
            <p style="text-align: center; margin-top: 20px; font-size: 0.8rem; color: #78909c;">
                This helps us maintain confidentiality of sensitive data
            </p>
        </div>
    </div>
    <?php endif; ?>

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
            document.getElementById('report_type').value = 'summary';
            document.getElementById('date_from').value = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('search').value = '';
            document.getElementById('full').checked = true;
            document.getElementById('reportForm').submit();
        }

        // Cancel export
        function cancelExport() {
            window.location.href = window.location.pathname;
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

            // Visits Chart
            const visitsCtx = document.getElementById('visitsChart').getContext('2d');
            const visitDates = <?php echo json_encode(array_column($daily_visits, 'date')); ?>;
            const visitCounts = <?php echo json_encode(array_column($daily_visits, 'count')); ?>;
            
            new Chart(visitsCtx, {
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
        });

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Reports';
        }

        // Form validation
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (dateFrom && dateTo && dateFrom > dateTo) {
                e.preventDefault();
                alert('Date From cannot be later than Date To');
            }
        });

        // Prevent background scrolling when modal is open
        <?php if ($show_verification_modal): ?>
        document.body.style.overflow = 'hidden';
        <?php endif; ?>
    </script>
</body>
</html>