<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit();
}

// Include FPDF library
require_once '../vendor/setasign/fpdf/fpdf.php';

$database = new Database();
$db = $database->getConnection();

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'users';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$export_type = isset($_GET['export_type']) ? $_GET['export_type'] : 'full';
$export_format = isset($_GET['export_format']) ? $_GET['export_format'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'pdf' && !empty($export_format)) {
    exportToPDF($report_type, $date_from, $date_to, $export_type, $search, $db);
    exit();
}

// Function to export to PDF
function exportToPDF($report_type, $date_from, $date_to, $export_type, $search, $db) {
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
        case 'appointments':
            generateAppointmentsReport($pdf, $data);
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
        default:
            generateSummaryReport($pdf, $data, $db);
    }
    
    // Output PDF
    $filename = $report_type . '_report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
}

// Function to get report data
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
            
        case 'appointments':
            $query = "SELECT a.*, p.full_name as patient_name, u.full_name as doctor_name 
                      FROM appointments a 
                      LEFT JOIN patients p ON a.patient_id = p.id 
                      LEFT JOIN users u ON a.doctor_id = u.id 
                      WHERE DATE(a.appointment_date) BETWEEN :date_from AND :date_to";
            $params[':date_from'] = $date_from;
            $params[':date_to'] = $date_to;
            
            if ($export_type == 'partial' && !empty($search)) {
                $query .= " AND (p.full_name LIKE :search OR u.full_name LIKE :search OR a.reason LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";
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

// Report generation functions
function generateUsersReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'User Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(30, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Username', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Full Name', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Role', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Created At', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['id'], 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, $row['username'], 1, 0, 'L', $fill);
        $pdf->Cell(60, 6, substr($row['full_name'], 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, ucfirst($row['role']), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, date('Y-m-d', strtotime($row['created_at'])), 1, 1, 'C', $fill);
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
    $pdf->Cell(50, 8, 'Full Name', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Gender', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Blood', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Phone', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Created', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['patient_id'], 1, 0, 'C', $fill);
        $pdf->Cell(50, 6, substr($row['full_name'], 0, 20), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, $row['gender'] ?? 'N/A', 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, $row['blood_group'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, $row['phone'] ?? 'N/A', 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['created_at'])), 1, 1, 'C', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Patients: ' . count($data), 0, 1);
}

function generateAppointmentsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Appointments Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(40, 8, 'Patient', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Doctor', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Time', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Reason', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(40, 6, substr($row['patient_name'] ?? 'N/A', 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, substr($row['doctor_name'] ?? 'N/A', 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, date('Y-m-d', strtotime($row['appointment_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, date('H:i', strtotime($row['appointment_time'])), 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, ucfirst($row['status']), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($row['reason'] ?? '', 0, 15), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    // Status summary
    $scheduled = 0; $completed = 0; $cancelled = 0;
    foreach ($data as $row) {
        switch ($row['status']) {
            case 'scheduled': $scheduled++; break;
            case 'completed': $completed++; break;
            case 'cancelled': $cancelled++; break;
        }
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Appointments: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Scheduled: ' . $scheduled . ' | Completed: ' . $completed . ' | Cancelled: ' . $cancelled, 0, 1);
}

function generateIncidentsReport($pdf, $data) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Incidents Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Headers
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(25, 25, 112);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(30, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Location', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Description', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['incident_code'], 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, substr($row['student_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, date('Y-m-d', strtotime($row['incident_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, substr($row['incident_type'], 0, 10), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($row['location'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, substr($row['description'], 0, 20), 1, 1, 'L', $fill);
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
    $pdf->Cell(40, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Qty', 1, 0, 'C', true);
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
        $pdf->Cell(40, 6, substr($row['item_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['quantity'], 1, 0, 'C', $fill);
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
    $pdf->Cell(30, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Qty Req', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Qty App', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Category', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Urgency', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['request_code'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['item_name'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['quantity_requested'], 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['quantity_approved'] ?? '0', 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['category'], 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, ucfirst($row['urgency']), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, ucfirst($row['status']), 1, 1, 'L', $fill);
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
    $pdf->Cell(30, 8, 'Code', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Student', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Req Date', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Valid Until', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Purpose', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['clearance_code'], 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['student_name'], 0, 12), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, substr($row['clearance_type'], 0, 10), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('Y-m-d', strtotime($row['request_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['status'], 1, 0, 'L', $fill);
        $pdf->Cell(30, 6, $row['valid_until'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, substr($row['purpose'], 0, 15), 1, 1, 'L', $fill);
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
    $pdf->Cell(30, 8, 'Student ID', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Temp', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'BP', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Complaint', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Treatment', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Disposition', 1, 1, 'C', true);
    
    // Data
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(245, 245, 245);
    $fill = false;
    
    foreach ($data as $row) {
        $pdf->Cell(30, 6, $row['student_id'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, date('Y-m-d', strtotime($row['visit_date'])), 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['temperature'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['blood_pressure'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(35, 6, substr($row['complaint'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(35, 6, substr($row['treatment_given'] ?? '', 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, $row['disposition'], 1, 1, 'L', $fill);
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
    $pdf->Cell(30, 8, 'Item Code', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Category', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Unit', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Expiry', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Min Stock', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Status', 1, 1, 'C', true);
    
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
        $status_color = [0, 0, 0];
        
        if ($row['quantity'] <= $row['minimum_stock']) {
            $status = 'Low Stock';
            $low_stock_count++;
        }
        
        if ($row['expiry_date'] && $row['expiry_date'] < $today) {
            $status = 'Expired';
            $expired_count++;
        }
        
        $pdf->Cell(30, 6, $row['item_code'], 1, 0, 'C', $fill);
        $pdf->Cell(40, 6, substr($row['item_name'], 0, 15), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $row['category'], 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $row['quantity'], 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['unit'], 1, 0, 'C', $fill);
        $pdf->Cell(25, 6, $row['expiry_date'] ?? 'N/A', 1, 0, 'C', $fill);
        $pdf->Cell(20, 6, $row['minimum_stock'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $status, 1, 1, 'L', $fill);
        $fill = !$fill;
    }
    
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Total Items: ' . count($data), 0, 1);
    $pdf->Cell(0, 6, 'Low Stock Items: ' . $low_stock_count, 0, 1);
    $pdf->Cell(0, 6, 'Expired Items: ' . $expired_count, 0, 1);
}

function generateSummaryReport($pdf, $data, $db) {
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'System Summary Report', 0, 1, 'L');
    $pdf->Ln(10);
    
    // Get counts
    $counts = [];
    $tables = [
        'users' => 'users',
        'patients' => 'patients',
        'appointments' => 'appointments',
        'incidents' => 'incidents',
        'medicine_requests' => 'medicine_requests',
        'clearance_requests' => 'clearance_requests',
        'clinic_stock' => 'clinic_stock',
        'visit_history' => 'visit_history'
    ];
    
    foreach ($tables as $key => $table) {
        $stmt = $db->query("SELECT COUNT(*) as total FROM $table");
        $counts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
    
    // Display summary
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'System Statistics', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(60, 8, 'Total Users:', 0, 0);
    $pdf->Cell(30, 8, $counts['users'], 0, 1);
    
    $pdf->Cell(60, 8, 'Total Patients:', 0, 0);
    $pdf->Cell(30, 8, $counts['patients'], 0, 1);
    
    $pdf->Cell(60, 8, 'Total Appointments:', 0, 0);
    $pdf->Cell(30, 8, $counts['appointments'], 0, 1);
    
    $pdf->Cell(60, 8, 'Total Incidents:', 0, 0);
    $pdf->Cell(30, 8, $counts['incidents'], 0, 1);
    
    $pdf->Cell(60, 8, 'Medicine Requests:', 0, 0);
    $pdf->Cell(30, 8, $counts['medicine_requests'], 0, 1);
    
    $pdf->Cell(60, 8, 'Clearance Requests:', 0, 0);
    $pdf->Cell(30, 8, $counts['clearance_requests'], 0, 1);
    
    $pdf->Cell(60, 8, 'Clinic Stock Items:', 0, 0);
    $pdf->Cell(30, 8, $counts['clinic_stock'], 0, 1);
    
    $pdf->Cell(60, 8, 'Visit History:', 0, 0);
    $pdf->Cell(30, 8, $counts['visit_history'], 0, 1);
    
    // Date range summary
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Date Range Summary', 0, 1, 'L');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, 'Report Period: ' . date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to)), 0, 1);
}

// Get summary statistics for dashboard
$summary_stats = [];

// User counts by role
$query = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
$stmt = $db->query($query);
$role_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $role_counts[$row['role']] = $row['count'];
}
$summary_stats['users_by_role'] = $role_counts;

// Appointment counts by status
$query = "SELECT status, COUNT(*) as count FROM appointments WHERE appointment_date BETWEEN :date_from AND :date_to GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$appointment_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $appointment_counts[$row['status']] = $row['count'];
}
$summary_stats['appointments_by_status'] = $appointment_counts;

// Incident counts by type
$query = "SELECT incident_type, COUNT(*) as count FROM incidents WHERE incident_date BETWEEN :date_from AND :date_to GROUP BY incident_type";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$incident_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $incident_counts[$row['incident_type']] = $row['count'];
}
$summary_stats['incidents_by_type'] = $incident_counts;

// Medicine request counts by status
$query = "SELECT status, COUNT(*) as count FROM medicine_requests WHERE DATE(requested_date) BETWEEN :date_from AND :date_to GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$request_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $request_counts[$row['status']] = $row['count'];
}
$summary_stats['requests_by_status'] = $request_counts;

// Total medicine dispensed
$query = "SELECT COUNT(*) as total, SUM(quantity) as total_quantity FROM dispensing_log WHERE DATE(dispensed_date) BETWEEN :date_from AND :date_to";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$dispensed_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$summary_stats['dispensed'] = $dispensed_stats;

// Clearance counts by status
$query = "SELECT status, COUNT(*) as count FROM clearance_requests WHERE request_date BETWEEN :date_from AND :date_to GROUP BY status";
$stmt = $db->prepare($query);
$stmt->bindParam(':date_from', $date_from);
$stmt->bindParam(':date_to', $date_to);
$stmt->execute();
$clearance_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clearance_counts[$row['status']] = $row['count'];
}
$summary_stats['clearance_by_status'] = $clearance_counts;

// Low stock items
$query = "SELECT COUNT(*) as count FROM clinic_stock WHERE quantity <= minimum_stock";
$stmt = $db->query($query);
$summary_stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Expiring soon (within 30 days)
$thirty_days = date('Y-m-d', strtotime('+30 days'));
$query = "SELECT COUNT(*) as count FROM clinic_stock WHERE expiry_date IS NOT NULL AND expiry_date <= :thirty_days AND expiry_date >= CURDATE()";
$stmt = $db->prepare($query);
$stmt->bindParam(':thirty_days', $thirty_days);
$stmt->execute();
$summary_stats['expiring_soon'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Super Admin | MedFlow Clinic Management System</title>
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

    .btn-warning {
        background: #ff9800;
        color: white;
    }

    .btn-warning:hover {
        background: #ffa726;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(255, 152, 0, 0.2);
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

    /* Export Options */
    .export-options {
        display: flex;
        gap: 12px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #cfd8dc;
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

    .export-option label {
        font-size: 0.9rem;
        color: #1e293b;
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
    }

    .stat-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.65rem;
        font-weight: 600;
        margin-top: 8px;
    }

    .badge-warning {
        background: #fff3cd;
        color: #ff9800;
    }

    .badge-danger {
        background: #ffebee;
        color: #c62828;
    }

    /* Summary Cards */
    .summary-section {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
    }

    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .summary-card h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .summary-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .summary-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #eceff1;
    }

    .summary-item:last-child {
        border-bottom: none;
    }

    .summary-label {
        font-size: 0.95rem;
        color: #546e7a;
    }

    .summary-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: #191970;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #eceff1;
        border-radius: 4px;
        margin-top: 8px;
    }

    .progress-fill {
        height: 100%;
        background: #191970;
        border-radius: 4px;
        transition: width 0.3s ease;
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

    .badge {
        padding: 4px 10px;
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
        background: #fff3cd;
        color: #ff9800;
    }

    .badge-danger {
        background: #ffebee;
        color: #c62828;
    }

    .badge-info {
        background: #e0f2fe;
        color: #0284c7;
    }

    .badge-primary {
        background: #e8eaf6;
        color: #191970;
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
        
        .summary-section {
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
        
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .export-options {
            flex-direction: column;
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
                        <h1>Reports & Analytics</h1>
                        <p>Generate and export comprehensive system reports</p>
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

                <!-- Filter Section -->
                <div class="filter-section">
                    <h2>Report Filters</h2>
                    <form method="GET" action="" id="reportForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="report_type">Report Type</label>
                                <select name="report_type" id="report_type" onchange="this.form.submit()">
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
                                <label for="full">Full Report (All records)</label>
                            </div>
                            <div class="export-option">
                                <input type="radio" name="export_type" id="partial" value="partial" <?php echo $export_type == 'partial' ? 'checked' : ''; ?>>
                                <label for="partial">Partial Report (Filtered results)</label>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21L16.5 16.5"/>
                                </svg>
                                Apply Filters
                            </button>
                            <button type="submit" name="export" value="pdf" class="btn btn-success" onclick="this.form.target='_blank'">
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

                <!-- Quick Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $summary_stats['dispensed']['total'] ?? 0; ?></h3>
                            <p>Items Dispensed</p>
                            <span class="stat-badge badge-info">Qty: <?php echo $summary_stats['dispensed']['total_quantity'] ?? 0; ?></span>
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
                            <h3><?php echo $summary_stats['low_stock']; ?></h3>
                            <p>Low Stock Items</p>
                            <?php if ($summary_stats['low_stock'] > 0): ?>
                            <span class="stat-badge badge-warning">Needs Restock</span>
                            <?php endif; ?>
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
                            <h3><?php echo $summary_stats['expiring_soon']; ?></h3>
                            <p>Expiring Soon</p>
                            <?php if ($summary_stats['expiring_soon'] > 0): ?>
                            <span class="stat-badge badge-danger">Within 30 days</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($summary_stats['users_by_role'] ?? []); ?></h3>
                            <p>User Roles</p>
                            <span class="stat-badge badge-primary">Active</span>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="summary-section">
                    <div class="summary-card">
                        <h2>Records by Status</h2>
                        <div class="summary-list">
                            <div class="summary-item">
                                <span class="summary-label">Appointments (Scheduled)</span>
                                <span class="summary-value"><?php echo $summary_stats['appointments_by_status']['scheduled'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Appointments (Completed)</span>
                                <span class="summary-value"><?php echo $summary_stats['appointments_by_status']['completed'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Medicine Requests (Pending)</span>
                                <span class="summary-value"><?php echo $summary_stats['requests_by_status']['pending'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Medicine Requests (Approved)</span>
                                <span class="summary-value"><?php echo $summary_stats['requests_by_status']['approved'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Clearances (Pending)</span>
                                <span class="summary-value"><?php echo $summary_stats['clearance_by_status']['Pending'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Clearances (Approved)</span>
                                <span class="summary-value"><?php echo $summary_stats['clearance_by_status']['Approved'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="summary-card">
                        <h2>Incident Distribution</h2>
                        <div class="summary-list">
                            <div class="summary-item">
                                <span class="summary-label">Incidents</span>
                                <span class="summary-value"><?php echo $summary_stats['incidents_by_type']['Incident'] ?? 0; ?></span>
                            </div>
                            <div class="progress-bar">
                                <?php 
                                $total_incidents = array_sum($summary_stats['incidents_by_type'] ?? [0]);
                                $incident_percent = $total_incidents > 0 ? (($summary_stats['incidents_by_type']['Incident'] ?? 0) / $total_incidents) * 100 : 0;
                                ?>
                                <div class="progress-fill" style="width: <?php echo $incident_percent; ?>%"></div>
                            </div>
                            
                            <div class="summary-item">
                                <span class="summary-label">Minor Injuries</span>
                                <span class="summary-value"><?php echo $summary_stats['incidents_by_type']['Minor Injury'] ?? 0; ?></span>
                            </div>
                            <div class="progress-bar">
                                <?php 
                                $minor_percent = $total_incidents > 0 ? (($summary_stats['incidents_by_type']['Minor Injury'] ?? 0) / $total_incidents) * 100 : 0;
                                ?>
                                <div class="progress-fill" style="width: <?php echo $minor_percent; ?>%"></div>
                            </div>
                            
                            <div class="summary-item">
                                <span class="summary-label">Emergencies</span>
                                <span class="summary-value"><?php echo $summary_stats['incidents_by_type']['Emergency'] ?? 0; ?></span>
                            </div>
                            <div class="progress-bar">
                                <?php 
                                $emergency_percent = $total_incidents > 0 ? (($summary_stats['incidents_by_type']['Emergency'] ?? 0) / $total_incidents) * 100 : 0;
                                ?>
                                <div class="progress-fill" style="width: <?php echo $emergency_percent; ?>%"></div>
                            </div>
                        </div>
                        
                        <h2 style="margin-top: 24px;">User Distribution</h2>
                        <div class="summary-list">
                            <div class="summary-item">
                                <span class="summary-label">Super Admins</span>
                                <span class="summary-value"><?php echo $summary_stats['users_by_role']['superadmin'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Admins</span>
                                <span class="summary-value"><?php echo $summary_stats['users_by_role']['admin'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Staff</span>
                                <span class="summary-value"><?php echo $summary_stats['users_by_role']['staff'] ?? 0; ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Doctors</span>
                                <span class="summary-value"><?php echo $summary_stats['users_by_role']['doctor'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Preview -->
                <div class="preview-section">
                    <div class="section-header">
                        <h2>Data Preview - <?php echo ucwords(str_replace('_', ' ', $report_type)); ?></h2>
                        <span class="view-all">Showing filtered results</span>
                    </div>
                    <div class="table-wrapper">
                        <?php
                        // Get preview data based on report type
                        $preview_data = getReportData($report_type, $date_from, $date_to, 'full', $search, $db);
                        $preview_data = array_slice($preview_data, 0, 10); // Show only first 10
                        
                        if (empty($preview_data)): ?>
                            <p style="text-align: center; padding: 40px; color: #546e7a;">No data available for the selected filters.</p>
                        <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php
                                    // Dynamic headers based on first row
                                    if (!empty($preview_data)) {
                                        $first_row = $preview_data[0];
                                        foreach (array_keys($first_row) as $key) {
                                            echo '<th>' . ucwords(str_replace('_', ' ', $key)) . '</th>';
                                        }
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_data as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                    <td>
                                        <?php 
                                        if (is_numeric($value) && strlen($value) > 10 && strpos($value, '-') !== false) {
                                            // Likely a date
                                            echo date('Y-m-d', strtotime($value));
                                        } elseif (strlen($value) > 30) {
                                            echo substr(htmlspecialchars($value), 0, 30) . '...';
                                        } else {
                                            echo htmlspecialchars($value ?? 'N/A');
                                        }
                                        ?>
                                    </td>
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

        // Reset filters function
        function resetFilters() {
            document.getElementById('report_type').value = 'summary';
            document.getElementById('date_from').value = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('search').value = '';
            document.getElementById('full').checked = true;
            document.getElementById('reportForm').submit();
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Reports';
        }

        // Export type change handler
        document.querySelectorAll('input[name="export_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'partial' && document.getElementById('search').value === '') {
                    alert('Please enter search terms for partial export, or use Full Export for all records.');
                }
            });
        });

        // Date validation
        document.getElementById('date_from').addEventListener('change', function() {
            const dateTo = document.getElementById('date_to');
            if (dateTo.value && this.value > dateTo.value) {
                alert('Date From cannot be later than Date To');
                this.value = dateTo.value;
            }
        });

        document.getElementById('date_to').addEventListener('change', function() {
            const dateFrom = document.getElementById('date_from');
            if (dateFrom.value && this.value < dateFrom.value) {
                alert('Date To cannot be earlier than Date From');
                this.value = dateFrom.value;
            }
        });
    </script>
</body>
</html>