<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php';



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

// Get current user full name from database
$user_query = "SELECT full_name FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':user_id', $current_user_id);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$current_user_fullname = $user_data ? $user_data['full_name'] : $current_user_name;

// Initialize variables
$student_data = null;
$search_error = '';
$student_id_search = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$success_message = '';
$error_message = '';
$view_document = isset($_GET['view']) ? $_GET['view'] : null;
$view_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Create clearance tables if not exists
try {
    // Clearance requests table
    $db->exec("CREATE TABLE IF NOT EXISTS `clearance_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `clearance_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `clearance_type` enum('Sports','Event','Work Immersion','After Illness','After Hospitalization','After Injury','General') NOT NULL,
        `purpose` text NOT NULL,
        `request_date` date NOT NULL,
        `status` enum('Pending','Approved','Not Cleared','Expired') DEFAULT 'Pending',
        `approved_date` date DEFAULT NULL,
        `approved_by` varchar(100) DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `vital_signs` text DEFAULT NULL,
        `assessment` text DEFAULT NULL,
        `recommendations` text DEFAULT NULL,
        `restrictions` text DEFAULT NULL,
        `remarks` text DEFAULT NULL,
        `valid_until` date DEFAULT NULL,
        `pdf_path` varchar(255) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `clearance_code` (`clearance_code`),
        KEY `student_id` (`student_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Medical certificates table
    $db->exec("CREATE TABLE IF NOT EXISTS `medical_certificates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `certificate_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `certificate_type` enum('Fit to Return','Fit for PE','Fit for Activities','Medical Leave','General') NOT NULL,
        `issued_date` date NOT NULL,
        `valid_until` date DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `vital_signs` text DEFAULT NULL,
        `assessment` text DEFAULT NULL,
        `recommendations` text DEFAULT NULL,
        `restrictions` text DEFAULT NULL,
        `issued_by` varchar(100) NOT NULL,
        `issuer_id` int(11) NOT NULL,
        `remarks` text DEFAULT NULL,
        `pdf_path` varchar(255) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `certificate_code` (`certificate_code`),
        KEY `student_id` (`student_id`),
        KEY `certificate_type` (`certificate_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Fit-to-return slips table
    $db->exec("CREATE TABLE IF NOT EXISTS `fit_to_return_slips` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `slip_code` varchar(50) NOT NULL,
        `student_id` varchar(20) NOT NULL,
        `student_name` varchar(100) NOT NULL,
        `grade_section` varchar(50) DEFAULT NULL,
        `absence_days` int(11) DEFAULT NULL,
        `absence_reason` text DEFAULT NULL,
        `assessment_date` date NOT NULL,
        `temperature` decimal(4,2) DEFAULT NULL,
        `blood_pressure` varchar(10) DEFAULT NULL,
        `heart_rate` int(11) DEFAULT NULL,
        `findings` text DEFAULT NULL,
        `assessment` text DEFAULT NULL,
        `fit_to_return` enum('Yes','No','With Restrictions') DEFAULT 'Yes',
        `restrictions` text DEFAULT NULL,
        `recommended_rest_days` int(11) DEFAULT NULL,
        `next_checkup_date` date DEFAULT NULL,
        `issued_by` varchar(100) NOT NULL,
        `issuer_id` int(11) NOT NULL,
        `pdf_path` varchar(255) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `slip_code` (`slip_code`),
        KEY `student_id` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}

// Function to generate PDF for clearance
function generateClearancePDF($clearance_id, $db, $student_data, $clearance_data) {
    try {
        // Create PDF directory if not exists
        $pdf_dir = '../uploads/clearances/';
        if (!file_exists($pdf_dir)) {
            mkdir($pdf_dir, 0777, true);
        }
        
        $filename = 'clearance_' . $clearance_data['clearance_code'] . '.pdf';
        $filepath = $pdf_dir . $filename;
        
        class ClearancePDF extends FPDF
        {
            function Header()
            {
                $this->SetFont('Arial', 'B', 16);
                $this->Cell(0, 10, 'SCHOOL HEALTH CLEARANCE', 0, 1, 'C');
                $this->Ln(5);
                
                $this->SetFont('Arial', '', 10);
                $this->Cell(0, 5, 'ICARE School Clinic', 0, 1, 'C');
                $this->Ln(5);
                
                $this->SetDrawColor(161, 74, 118);
                $this->Line(10, 40, 200, 40);
                $this->Ln(10);
            }
            
            function Footer()
            {
                $this->SetY(-30);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 5, 'This is a system-generated clearance document.', 0, 1, 'C');
                $this->Cell(0, 5, 'Generated on: ' . date('F d, Y'), 0, 1, 'C');
            }
        }
        
        $pdf = new ClearancePDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        
        // Clearance details
        $pdf->Cell(45, 10, 'Clearance No:', 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $clearance_data['clearance_code'], 0, 1);
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(45, 10, 'Student Name:', 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $student_data['full_name'], 0, 1);
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(45, 10, 'Student ID:', 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $student_data['student_id'], 0, 1);
        
        $grade_section = "Grade " . ($student_data['year_level'] ?? 'N/A') . " - " . ($student_data['section'] ?? 'N/A');
        $pdf->Cell(45, 10, 'Grade/Section:', 0, 0);
        $pdf->Cell(0, 10, $grade_section, 0, 1);
        
        $pdf->Ln(10);
        
        // Clearance Type and Purpose
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'CLEARANCE DETAILS', 0, 1);
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(45, 8, 'Type:', 0, 0);
        $pdf->Cell(0, 8, $clearance_data['clearance_type'], 0, 1);
        
        $pdf->Cell(45, 8, 'Purpose:', 0, 0);
        $pdf->MultiCell(0, 8, $clearance_data['purpose'], 0, 1);
        
        $pdf->Cell(45, 8, 'Request Date:', 0, 0);
        $pdf->Cell(0, 8, date('F d, Y', strtotime($clearance_data['request_date'])), 0, 1);
        
        $pdf->Ln(5);
        
        // Vital Signs if available
        if (!empty($clearance_data['vital_signs'])) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'VITAL SIGNS', 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, $clearance_data['vital_signs'], 0, 1);
            $pdf->Ln(5);
        }
        
        // Findings/Assessment
        if (!empty($clearance_data['findings'])) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'FINDINGS / ASSESSMENT', 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, $clearance_data['findings'], 0, 1);
            $pdf->Ln(5);
        }
        
        if (!empty($clearance_data['assessment'])) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'NURSE\'S ASSESSMENT', 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, $clearance_data['assessment'], 0, 1);
            $pdf->Ln(5);
        }
        
        // Status
        $pdf->SetFont('Arial', 'B', 14);
        $status_color = $clearance_data['status'] == 'Approved' ? [30, 123, 92] : ($clearance_data['status'] == 'Not Cleared' ? [196, 69, 69] : [107, 43, 94]);
        $pdf->SetTextColor($status_color[0], $status_color[1], $status_color[2]);
        $pdf->Cell(0, 10, 'STATUS: ' . strtoupper($clearance_data['status']), 0, 1);
        
        $pdf->SetTextColor(0);
        
        if (!empty($clearance_data['recommendations'])) {
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'RECOMMENDATIONS', 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, $clearance_data['recommendations'], 0, 1);
        }
        
        if (!empty($clearance_data['restrictions'])) {
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'RESTRICTIONS', 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, $clearance_data['restrictions'], 0, 1);
        }
        
        if (!empty($clearance_data['valid_until'])) {
            $pdf->Ln(5);
            $pdf->Cell(0, 8, 'Valid until: ' . date('F d, Y', strtotime($clearance_data['valid_until'])), 0, 1);
        }
        
        $pdf->Ln(20);
        
        $pdf->Cell(100, 10, '_________________________', 0, 0);
        $pdf->Cell(90, 10, '_________________________', 0, 1);
        
        $pdf->Cell(100, 5, 'Issued By: ' . ($clearance_data['approved_by'] ?? $clearance_data['created_by']), 0, 0);
        $pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1);
        
        $pdf->Output('F', $filepath);
        
        return $filename;
    } catch (Exception $e) {
        error_log("PDF Generation error: " . $e->getMessage());
        return null;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Create clearance request with auto-generation
    if ($_POST['action'] == 'create_clearance') {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Generate clearance code
            $prefix = 'CLR';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $clearance_code = $prefix . '-' . $date . '-' . $random;
            
            // Format vital signs
            $vital_signs = "Temp: " . ($_POST['temperature'] ?? 'N/A') . "°C, " .
                          "BP: " . ($_POST['blood_pressure'] ?? 'N/A') . ", " .
                          "HR: " . ($_POST['heart_rate'] ?? 'N/A') . " bpm";
            
            // Format findings/assessment
            $findings = $_POST['findings'] ?? '';
            $assessment = $_POST['assessment'] ?? '';
            
            $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
            $status = $_POST['status'] ?? 'Approved'; // Default to approved since nurse is issuing
            
            $query = "INSERT INTO clearance_requests (
                clearance_code, student_id, student_name, grade_section,
                clearance_type, purpose, request_date, status, 
                findings, vital_signs, assessment, recommendations, 
                restrictions, valid_until, created_by
            ) VALUES (
                :clearance_code, :student_id, :student_name, :grade_section,
                :clearance_type, :purpose, :request_date, :status,
                :findings, :vital_signs, :assessment, :recommendations,
                :restrictions, :valid_until, :created_by
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':clearance_code', $clearance_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':clearance_type', $_POST['clearance_type']);
            $stmt->bindParam(':purpose', $_POST['purpose']);
            $stmt->bindParam(':request_date', $_POST['request_date']);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':findings', $findings);
            $stmt->bindParam(':vital_signs', $vital_signs);
            $stmt->bindParam(':assessment', $assessment);
            $stmt->bindParam(':recommendations', $_POST['recommendations']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':created_by', $current_user_id);
            
            if ($stmt->execute()) {
                $clearance_id = $db->lastInsertId();
                
                // Update with approved by and date
                $update_query = "UPDATE clearance_requests 
                               SET approved_by = :approved_by, 
                                   approved_date = CURDATE() 
                               WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':approved_by', $current_user_fullname);
                $update_stmt->bindParam(':id', $clearance_id);
                $update_stmt->execute();
                
                // Generate PDF
                $clearance_data = [
                    'clearance_code' => $clearance_code,
                    'clearance_type' => $_POST['clearance_type'],
                    'purpose' => $_POST['purpose'],
                    'request_date' => $_POST['request_date'],
                    'status' => $status,
                    'findings' => $findings,
                    'vital_signs' => $vital_signs,
                    'assessment' => $assessment,
                    'recommendations' => $_POST['recommendations'],
                    'restrictions' => $_POST['restrictions'],
                    'valid_until' => $valid_until,
                    'created_by' => $current_user_fullname,
                    'approved_by' => $current_user_fullname
                ];
                
                $student_info = [
                    'full_name' => $_POST['student_name'],
                    'student_id' => $_POST['student_id'],
                    'year_level' => explode(' - ', $_POST['grade_section'])[0] ?? 'N/A',
                    'section' => explode(' - ', $_POST['grade_section'])[1] ?? 'N/A'
                ];
                
                $pdf_filename = generateClearancePDF($clearance_id, $db, $student_info, $clearance_data);
                
                if ($pdf_filename) {
                    // Update database with PDF path
                    $pdf_query = "UPDATE clearance_requests SET pdf_path = :pdf_path WHERE id = :id";
                    $pdf_stmt = $db->prepare($pdf_query);
                    $pdf_stmt->bindParam(':pdf_path', $pdf_filename);
                    $pdf_stmt->bindParam(':id', $clearance_id);
                    $pdf_stmt->execute();
                }
                
                $db->commit();
                
                $success_message = "Clearance generated successfully! Code: " . $clearance_code;
                
                // Redirect to view the generated clearance
                echo "<script>window.location.href = '?view=clearance&id=" . $clearance_id . "&student_id=" . urlencode($_POST['student_id']) . "';</script>";
                exit();
            } else {
                $db->rollBack();
                $error_message = "Error creating clearance request.";
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
    
    // Issue medical certificate with auto-generation
    if ($_POST['action'] == 'issue_certificate') {
        try {
            $db->beginTransaction();
            
            // Generate certificate code
            $prefix = 'CERT';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $certificate_code = $prefix . '-' . $date . '-' . $random;
            
            // Format vital signs
            $vital_signs = "Temp: " . ($_POST['temperature'] ?? 'N/A') . "°C, " .
                          "BP: " . ($_POST['blood_pressure'] ?? 'N/A') . ", " .
                          "HR: " . ($_POST['heart_rate'] ?? 'N/A') . " bpm";
            
            // Format findings/assessment
            $findings = $_POST['findings'] ?? '';
            $assessment = $_POST['assessment'] ?? '';
            
            $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
            
            $query = "INSERT INTO medical_certificates (
                certificate_code, student_id, student_name, grade_section,
                certificate_type, issued_date, valid_until, findings,
                vital_signs, assessment, recommendations, restrictions,
                issued_by, issuer_id, remarks
            ) VALUES (
                :certificate_code, :student_id, :student_name, :grade_section,
                :certificate_type, :issued_date, :valid_until, :findings,
                :vital_signs, :assessment, :recommendations, :restrictions,
                :issued_by, :issuer_id, :remarks
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':certificate_code', $certificate_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':certificate_type', $_POST['certificate_type']);
            $stmt->bindParam(':issued_date', $_POST['issued_date']);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':findings', $findings);
            $stmt->bindParam(':vital_signs', $vital_signs);
            $stmt->bindParam(':assessment', $assessment);
            $stmt->bindParam(':recommendations', $_POST['recommendations']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':issued_by', $current_user_fullname);
            $stmt->bindParam(':issuer_id', $current_user_id);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            if ($stmt->execute()) {
                $certificate_id = $db->lastInsertId();
                
                // Generate PDF
                require_once 'generate_certificate_pdf.php';
                $pdf_filename = generateCertificatePDF($certificate_id, $db, $_POST, $current_user_fullname);
                
                if ($pdf_filename) {
                    // Update database with PDF path
                    $pdf_query = "UPDATE medical_certificates SET pdf_path = :pdf_path WHERE id = :id";
                    $pdf_stmt = $db->prepare($pdf_query);
                    $pdf_stmt->bindParam(':pdf_path', $pdf_filename);
                    $pdf_stmt->bindParam(':id', $certificate_id);
                    $pdf_stmt->execute();
                }
                
                $db->commit();
                
                $success_message = "Medical certificate issued successfully! Code: " . $certificate_code;
                
                // Redirect to view the generated certificate
                echo "<script>window.location.href = '?view=certificate&id=" . $certificate_id . "&student_id=" . urlencode($_POST['student_id']) . "';</script>";
                exit();
            } else {
                $db->rollBack();
                $error_message = "Error issuing medical certificate.";
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
    
    // Create fit-to-return slip with auto-generation
    if ($_POST['action'] == 'create_fit_to_return') {
        try {
            $db->beginTransaction();
            
            // Generate slip code
            $prefix = 'FTR';
            $date = date('Ymd');
            $random = rand(1000, 9999);
            $slip_code = $prefix . '-' . $date . '-' . $random;
            
            // Format findings/assessment
            $findings = $_POST['findings'] ?? '';
            $assessment = "Temperature: " . ($_POST['temperature'] ?? 'N/A') . "°C\n" .
                         "Blood Pressure: " . ($_POST['blood_pressure'] ?? 'N/A') . "\n" .
                         "Heart Rate: " . ($_POST['heart_rate'] ?? 'N/A') . " bpm\n\n" .
                         "Findings: " . $findings;
            
            $next_checkup = !empty($_POST['next_checkup_date']) ? $_POST['next_checkup_date'] : null;
            
            $query = "INSERT INTO fit_to_return_slips (
                slip_code, student_id, student_name, grade_section,
                absence_days, absence_reason, assessment_date,
                temperature, blood_pressure, heart_rate,
                findings, assessment, fit_to_return, restrictions,
                recommended_rest_days, next_checkup_date, issued_by, issuer_id
            ) VALUES (
                :slip_code, :student_id, :student_name, :grade_section,
                :absence_days, :absence_reason, :assessment_date,
                :temperature, :blood_pressure, :heart_rate,
                :findings, :assessment, :fit_to_return, :restrictions,
                :recommended_rest_days, :next_checkup_date, :issued_by, :issuer_id
            )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':slip_code', $slip_code);
            $stmt->bindParam(':student_id', $_POST['student_id']);
            $stmt->bindParam(':student_name', $_POST['student_name']);
            $stmt->bindParam(':grade_section', $_POST['grade_section']);
            $stmt->bindParam(':absence_days', $_POST['absence_days']);
            $stmt->bindParam(':absence_reason', $_POST['absence_reason']);
            $stmt->bindParam(':assessment_date', $_POST['assessment_date']);
            $stmt->bindParam(':temperature', $_POST['temperature']);
            $stmt->bindParam(':blood_pressure', $_POST['blood_pressure']);
            $stmt->bindParam(':heart_rate', $_POST['heart_rate']);
            $stmt->bindParam(':findings', $findings);
            $stmt->bindParam(':assessment', $assessment);
            $stmt->bindParam(':fit_to_return', $_POST['fit_to_return']);
            $stmt->bindParam(':restrictions', $_POST['restrictions']);
            $stmt->bindParam(':recommended_rest_days', $_POST['recommended_rest_days']);
            $stmt->bindParam(':next_checkup_date', $next_checkup);
            $stmt->bindParam(':issued_by', $current_user_fullname);
            $stmt->bindParam(':issuer_id', $current_user_id);
            
            if ($stmt->execute()) {
                $slip_id = $db->lastInsertId();
                
                // Generate PDF
                require_once 'generate_fit_to_return_pdf.php';
                $pdf_filename = generateFitToReturnPDF($slip_id, $db, $_POST, $current_user_fullname);
                
                if ($pdf_filename) {
                    // Update database with PDF path
                    $pdf_query = "UPDATE fit_to_return_slips SET pdf_path = :pdf_path WHERE id = :id";
                    $pdf_stmt = $db->prepare($pdf_query);
                    $pdf_stmt->bindParam(':pdf_path', $pdf_filename);
                    $pdf_stmt->bindParam(':id', $slip_id);
                    $pdf_stmt->execute();
                }
                
                $db->commit();
                
                $success_message = "Fit-to-return slip created successfully! Code: " . $slip_code;
                
                // Redirect to view the generated slip
                echo "<script>window.location.href = '?view=fitreturn&id=" . $slip_id . "&student_id=" . urlencode($_POST['student_id']) . "';</script>";
                exit();
            } else {
                $db->rollBack();
                $error_message = "Error creating fit-to-return slip.";
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Get statistics
$stats = [];

try {
    // Pending clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests WHERE status = 'Pending'";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_clearances'] = $result ? $result['total'] : 0;
    
    // Approved clearances this month
    $query = "SELECT COUNT(*) as total FROM clearance_requests 
              WHERE status = 'Approved' AND MONTH(approved_date) = MONTH(CURDATE())";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['approved_this_month'] = $result ? $result['total'] : 0;
    
    // Certificates issued today
    $query = "SELECT COUNT(*) as total FROM medical_certificates WHERE DATE(issued_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['certificates_today'] = $result ? $result['total'] : 0;
    
    // Fit-to-return slips today
    $query = "SELECT COUNT(*) as total FROM fit_to_return_slips WHERE DATE(assessment_date) = CURDATE()";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['fit_to_return_today'] = $result ? $result['total'] : 0;
    
    // Expiring soon clearances
    $query = "SELECT COUNT(*) as total FROM clearance_requests 
              WHERE valid_until IS NOT NULL 
              AND valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $stmt = $db->query($query);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['expiring_soon'] = $result ? $result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = [
        'pending_clearances' => 0,
        'approved_this_month' => 0,
        'certificates_today' => 0,
        'fit_to_return_today' => 0,
        'expiring_soon' => 0
    ];
}

// Get all clearance requests
function getClearanceRequests($db, $status = null) {
    try {
        $query = "SELECT * FROM clearance_requests";
        if ($status) {
            $query .= " WHERE status = :status";
        }
        $query .= " ORDER BY request_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Get medical certificates
function getMedicalCertificates($db, $student_id = null) {
    try {
        $query = "SELECT * FROM medical_certificates";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY issued_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($student_id) {
            $stmt->bindParam(':student_id', $student_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Get fit-to-return slips
function getFitToReturnSlips($db, $student_id = null) {
    try {
        $query = "SELECT * FROM fit_to_return_slips";
        if ($student_id) {
            $query .= " WHERE student_id = :student_id";
        }
        $query .= " ORDER BY assessment_date DESC LIMIT 50";
        
        $stmt = $db->prepare($query);
        if ($student_id) {
            $stmt->bindParam(':student_id', $student_id);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$pending_clearances = getClearanceRequests($db, 'Pending');
$approved_clearances = getClearanceRequests($db, 'Approved');
$all_certificates = getMedicalCertificates($db);
$all_fit_to_return = getFitToReturnSlips($db);

// Search for student if ID provided
if (!empty($student_id_search)) {
    $api_url = "https://ttm.qcprotektado.com/api/students.php";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 && $response) {
        $api_response = json_decode($response, true);
        
        if (isset($api_response['records']) && is_array($api_response['records'])) {
            $found = false;
            foreach ($api_response['records'] as $student) {
                if (isset($student['student_id']) && $student['student_id'] == $student_id_search) {
                    $student_data = $student;
                    $found = true;
                    
                    // Get student's clearance history
                    $student_data['clearances'] = getClearanceRequests($db);
                    $student_data['certificates'] = getMedicalCertificates($db, $student_id_search);
                    $student_data['fit_to_return'] = getFitToReturnSlips($db, $student_id_search);
                    break;
                }
            }
            
            if (!$found) {
                $search_error = "Student ID not found in the system.";
            }
        } else {
            $search_error = "Unable to fetch student data.";
        }
    } else {
        $search_error = "Error connecting to student database.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Clearance & Certification | MedFlow Clinic Management System</title>
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
            background: #f8f0f5;
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
            background: #f8f0f5;
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
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-section p {
            color: #7a4b6b;
            font-size: 1rem;
            font-weight: 400;
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
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(107, 43, 94, 0.2), 0 10px 10px -5px rgba(107, 43, 94, 0.1);
            border-color: #a14a76;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
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
            color: #6b2b5e;
            margin-bottom: 2px;
        }

        .stat-info p {
            color: #7a4b6b;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .warning-badge {
            background: #fde7e9;
            color: #c44545;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 4px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: #e0f2e9;
            border: 1px solid #b8e0d2;
            color: #1e7b5c;
        }

        .alert-error {
            background: #fde7e9;
            border: 1px solid #fbc1c6;
            color: #c44545;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Main Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title svg {
            width: 24px;
            height: 24px;
            color: #a14a76;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #1e7b5c;
        }

        .status-not-cleared {
            background: #fde7e9;
            color: #c44545;
        }

        .status-expired {
            background: #e9ecef;
            color: #6c757d;
        }

        .search-form {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #7a4b6b;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.95rem;
            border: 2px solid #e9d0df;
            border-radius: 12px;
            transition: all 0.3s ease;
            background: white;
            color: #4a2e40;
        }

        .form-control:focus {
            outline: none;
            border-color: #a14a76;
            box-shadow: 0 0 0 3px rgba(161, 74, 118, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%237a4b6b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }

        .form-row-4 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(107, 43, 94, 0.3);
        }

        .btn-secondary {
            background: #f0e2ea;
            color: #6b2b5e;
            border: 1px solid #e9d0df;
        }

        .btn-secondary:hover {
            background: #e9d0df;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.8rem;
        }

        .btn-icon {
            padding: 8px 12px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Quick Stats Card */
        .quick-stats-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(107, 43, 94, 0.1), 0 2px 4px -1px rgba(107, 43, 94, 0.06);
            border: 1px solid #e9d0df;
        }

        .pending-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .pending-item {
            padding: 12px 0;
            border-bottom: 1px solid #f0e2ea;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pending-item:last-child {
            border-bottom: none;
        }

        .pending-info {
            flex: 1;
        }

        .pending-name {
            font-weight: 600;
            color: #6b2b5e;
            font-size: 0.9rem;
        }

        .pending-meta {
            font-size: 0.7rem;
            color: #a14a76;
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }

        .pending-badge {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        /* Tabs */
        .tabs-section {
            background: white;
            border-radius: 24px;
            box-shadow: 0 10px 15px -3px rgba(107, 43, 94, 0.1), 0 4px 6px -2px rgba(107, 43, 94, 0.05);
            border: 1px solid #e9d0df;
            overflow: hidden;
            margin-bottom: 30px;
            animation: fadeInUp 0.8s ease;
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid #f0e2ea;
            background: #fdf8fa;
            overflow-x: auto;
            padding: 0 20px;
        }

        .tab-btn {
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 0.95rem;
            font-weight: 600;
            color: #7a4b6b;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: #a14a76;
        }

        .tab-btn.active {
            color: #a14a76;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #a14a76;
        }

        .tab-content {
            padding: 30px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Form Cards */
        .form-card {
            background: #fdf8fa;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            border: 1px solid #e9d0df;
        }

        .form-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card-title svg {
            width: 20px;
            height: 20px;
            color: #a14a76;
        }

        /* Student Info Bar */
        .student-info-bar {
            background: #f0e2ea;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9d0df;
        }

        .student-avatar-sm {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #6b2b5e 0%, #a14a76 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 600;
            color: white;
        }

        .student-details h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #6b2b5e;
            margin-bottom: 6px;
        }

        .student-details p {
            color: #7a4b6b;
            font-size: 0.95rem;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid #e9d0df;
            margin-top: 20px;
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
            color: #7a4b6b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e9d0df;
            background: #f0e2ea;
        }

        .data-table td {
            padding: 16px 12px;
            font-size: 0.9rem;
            color: #4a2e40;
            border-bottom: 1px solid #f0e2ea;
        }

        .data-table tr:hover td {
            background: #fdf8fa;
        }

        .clearance-code {
            font-weight: 600;
            color: #a14a76;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #7a4b6b;
        }

        .empty-state svg {
            width: 60px;
            height: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #a14a76;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 5px;
            color: #6b2b5e;
        }

        .empty-state small {
            font-size: 0.85rem;
            color: #7a4b6b;
        }

        /* Document View Modal */
        .document-modal {
            display: <?php echo $view_document ? 'flex' : 'none'; ?>;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .document-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .document-header {
            padding: 20px;
            border-bottom: 2px solid #f0e2ea;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .document-header h2 {
            color: #6b2b5e;
            font-size: 1.3rem;
        }

        .document-actions {
            display: flex;
            gap: 10px;
        }

        .document-content {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .document-footer {
            padding: 20px;
            border-top: 2px solid #f0e2ea;
            display: flex;
            justify-content: flex-end;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #7a4b6b;
        }

        .close-btn:hover {
            color: #c44545;
        }

        .document-field {
            margin-bottom: 20px;
        }

        .document-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #7a4b6b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .document-value {
            font-size: 1rem;
            color: #4a2e40;
            background: #fdf8fa;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e9d0df;
        }

        .document-value.big {
            font-size: 1.2rem;
            font-weight: 600;
            color: #6b2b5e;
        }

        .status-box {
            padding: 20px;
            border-radius: 16px;
            text-align: center;
            font-weight: 600;
            font-size: 1.2rem;
            margin: 20px 0;
        }

        .status-box.approved {
            background: #d4edda;
            color: #1e7b5c;
        }

        .status-box.not-cleared {
            background: #fde7e9;
            color: #c44545;
        }

        .status-box.pending {
            background: #fff3cd;
            color: #856404;
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
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row,
            .form-row-3,
            .form-row-4 {
                grid-template-columns: 1fr;
            }
            
            .student-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
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
                <div class="welcome-section">
                    <h1>📋 Health Clearance & Certification</h1>
                    <p>Issue clearances, medical certificates, and fit-to-return slips with findings assessment.</p>
                </div>

                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                            <path d="M22 4L12 14.01L9 11.01"/>
                        </svg>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_clearances']; ?></h3>
                            <p>Pending Clearances</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['approved_this_month']; ?></h3>
                            <p>Approved This Month</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📄</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['certificates_today']; ?></h3>
                            <p>Certificates Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🔄</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['fit_to_return_today']; ?></h3>
                            <p>Fit-to-Return Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['expiring_soon']; ?></h3>
                            <p>Expiring Soon</p>
                            <?php if ($stats['expiring_soon'] > 0): ?>
                                <div class="warning-badge">Within 7 days</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Search Card -->
                    <div class="search-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="M21 21L16.65 16.65"/>
                            </svg>
                            Find Student
                        </div>
                        
                        <form method="GET" action="" class="search-form">
                            <div class="form-group">
                                <label for="student_id">Student ID / LRN</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       placeholder="Enter student ID" 
                                       value="<?php echo htmlspecialchars($student_id_search); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Search Student</button>
                        </form>

                        <?php if ($search_error): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #fde7e9; border-radius: 12px; color: #c44545; font-size: 0.9rem;">
                                <?php echo $search_error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Pending Clearances List -->
                        <?php if (!empty($pending_clearances)): ?>
                            <div style="margin-top: 24px;">
                                <div style="font-size: 0.9rem; font-weight: 600; color: #6b2b5e; margin-bottom: 12px;">
                                    ⏳ Pending Clearances
                                </div>
                                <div class="pending-list">
                                    <?php foreach (array_slice($pending_clearances, 0, 5) as $pending): ?>
                                        <div class="pending-item">
                                            <div class="pending-info">
                                                <div class="pending-name"><?php echo htmlspecialchars($pending['student_name']); ?></div>
                                                <div class="pending-meta">
                                                    <span><?php echo $pending['clearance_type']; ?></span>
                                                    <span><?php echo date('M d', strtotime($pending['request_date'])); ?></span>
                                                </div>
                                            </div>
                                            <span class="pending-badge">Pending</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Stats Card -->
                    <div class="quick-stats-card">
                        <div class="card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 3V21H21"/>
                                <path d="M7 15L10 11L13 14L20 7"/>
                            </svg>
                            Clearance Overview
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                            <div style="background: #f0e2ea; padding: 16px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 700; color: #6b2b5e;"><?php echo count($approved_clearances); ?></div>
                                <div style="font-size: 0.8rem; color: #7a4b6b;">Approved</div>
                            </div>
                            <div style="background: #f0e2ea; padding: 16px; border-radius: 16px; text-align: center;">
                                <div style="font-size: 1.8rem; font-weight: 700; color: #6b2b5e;"><?php echo count($all_certificates); ?></div>
                                <div style="font-size: 0.8rem; color: #7a4b6b;">Certificates</div>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <div style="font-size: 0.9rem; font-weight: 600; color: #6b2b5e; margin-bottom: 12px;">
                                Quick Actions
                            </div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"clearance\"]').click()">+ Clearance</button>
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"certificate\"]').click()">+ Certificate</button>
                                <button class="btn btn-sm btn-secondary" onclick="document.querySelector('[data-tab=\"fitreturn\"]').click()">+ Fit-to-Return</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($student_data): ?>
                <!-- Student Info Bar -->
                <div class="student-info-bar">
                    <div class="student-avatar-sm">
                        <?php echo strtoupper(substr($student_data['full_name'] ?? 'NA', 0, 2)); ?>
                    </div>
                    <div class="student-details">
                        <h3><?php echo htmlspecialchars($student_data['full_name'] ?? 'N/A'); ?></h3>
                        <p>
                            Student ID: <?php echo htmlspecialchars($student_data['student_id']); ?> | 
                            Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - 
                            <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>
                        </p>
                        <?php if (!empty($student_data['medical_conditions'])): ?>
                            <p style="margin-top: 8px;">
                                <span class="status-badge status-not-cleared">⚕️ <?php echo htmlspecialchars($student_data['medical_conditions']); ?></span>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div class="tabs-section">
                    <div class="tabs-header">
                        <button class="tab-btn active" data-tab="clearance" onclick="showTab('clearance', event)">📋 Issue Clearance</button>
                        <button class="tab-btn" data-tab="certificate" onclick="showTab('certificate', event)">📄 Issue Certificate</button>
                        <button class="tab-btn" data-tab="fitreturn" onclick="showTab('fitreturn', event)">🔄 Fit-to-Return</button>
                        <button class="tab-btn" data-tab="history" onclick="showTab('history', event)">📚 Clearance History</button>
                    </div>

                    <div class="tab-content">
                        <!-- Issue Clearance Tab (Auto-generates) -->
                        <div class="tab-pane active" id="clearance">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Issue Clearance (Auto-generates PDF)
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_clearance">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Clearance Type</label>
                                            <select name="clearance_type" class="form-control" required>
                                                <option value="">Select Type</option>
                                                <option value="Sports">Sports / Intramurals</option>
                                                <option value="Event">School Event</option>
                                                <option value="Work Immersion">Work Immersion</option>
                                                <option value="After Illness">After Illness</option>
                                                <option value="After Hospitalization">After Hospitalization</option>
                                                <option value="After Injury">After Injury</option>
                                                <option value="General">General Clearance</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Request Date</label>
                                            <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Purpose / Reason</label>
                                        <textarea name="purpose" class="form-control" placeholder="Why is this clearance needed?" required></textarea>
                                    </div>
                                    
                                    <!-- Vital Signs -->
                                    <div class="form-row-4">
                                        <div class="form-group">
                                            <label>Temperature (°C)</label>
                                            <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                        </div>
                                        <div class="form-group">
                                            <label>Blood Pressure</label>
                                            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                        </div>
                                        <div class="form-group">
                                            <label>Heart Rate</label>
                                            <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                        </div>
                                    </div>
                                    
                                    <!-- Findings/Assessment -->
                                    <div class="form-group">
                                        <label>Findings / Assessment (What the nurse observed)</label>
                                        <textarea name="findings" class="form-control" placeholder="e.g., Temperature: 37.2°C, Pulse: 88 bpm, Student feels well, no vomiting or fatigue. No signs of contagious illness." required></textarea>
                                        <small style="color: #7a4b6b;">Be specific: vital signs, observed condition, student's statement</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Nurse's Evaluation</label>
                                        <textarea name="assessment" class="form-control" placeholder="e.g., Fit to return to class / Requires rest / Needs parent to pick up" required></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Recommendations</label>
                                            <input type="text" name="recommendations" class="form-control" placeholder="e.g., Avoid strenuous activities for 24 hours">
                                        </div>
                                        <div class="form-group">
                                            <label>Restrictions</label>
                                            <input type="text" name="restrictions" class="form-control" placeholder="e.g., No PE for 3 days">
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Valid Until</label>
                                            <input type="date" name="valid_until" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Generate Clearance</button>
                                </form>
                            </div>
                        </div>

                        <!-- Issue Certificate Tab -->
                        <div class="tab-pane" id="certificate">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                        <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                    </svg>
                                    Issue Medical Certificate
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="issue_certificate">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Certificate Type</label>
                                            <select name="certificate_type" class="form-control" required>
                                                <option value="">Select Type</option>
                                                <option value="Fit to Return">Fit to Return to Class</option>
                                                <option value="Fit for PE">Fit for PE Participation</option>
                                                <option value="Fit for Activities">Fit for School Activities</option>
                                                <option value="Medical Leave">Medical Leave</option>
                                                <option value="General">General Medical Certificate</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Issue Date</label>
                                            <input type="date" name="issued_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <!-- Vital Signs -->
                                    <div class="form-row-4">
                                        <div class="form-group">
                                            <label>Temperature (°C)</label>
                                            <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                        </div>
                                        <div class="form-group">
                                            <label>Blood Pressure</label>
                                            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                        </div>
                                        <div class="form-group">
                                            <label>Heart Rate</label>
                                            <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Findings / Assessment</label>
                                        <textarea name="findings" class="form-control" placeholder="e.g., Student examined, vital signs normal, no signs of illness" required></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Nurse's Assessment</label>
                                        <textarea name="assessment" class="form-control" placeholder="e.g., Fit to participate in school activities" required></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Recommendations</label>
                                        <textarea name="recommendations" class="form-control" placeholder="Recommendations for student..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Restrictions (if any)</label>
                                        <input type="text" name="restrictions" class="form-control" placeholder="e.g., No strenuous activities for 1 week">
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Valid Until</label>
                                            <input type="date" name="valid_until" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Additional Remarks</label>
                                        <textarea name="remarks" class="form-control" placeholder="Any additional notes..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Issue Certificate</button>
                                </form>
                            </div>
                        </div>

                        <!-- Fit-to-Return Tab -->
                        <div class="tab-pane" id="fitreturn">
                            <div class="form-card">
                                <div class="form-card-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 12H4M12 4v16"/>
                                    </svg>
                                    Create Fit-to-Return Slip
                                </div>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_fit_to_return">
                                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_data['student_id']); ?>">
                                    <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($student_data['full_name']); ?>">
                                    <input type="hidden" name="grade_section" value="Grade <?php echo htmlspecialchars($student_data['year_level'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($student_data['section'] ?? 'N/A'); ?>">
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Assessment Date</label>
                                            <input type="date" name="assessment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Absence Days</label>
                                            <input type="number" name="absence_days" class="form-control" min="0" placeholder="Number of days absent">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Reason for Absence</label>
                                        <textarea name="absence_reason" class="form-control" placeholder="Why was the student absent?"></textarea>
                                    </div>
                                    
                                    <div class="form-row-4">
                                        <div class="form-group">
                                            <label>Temperature (°C)</label>
                                            <input type="number" name="temperature" class="form-control" step="0.1" min="35" max="42" placeholder="36.5">
                                        </div>
                                        <div class="form-group">
                                            <label>Blood Pressure</label>
                                            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                                        </div>
                                        <div class="form-group">
                                            <label>Heart Rate</label>
                                            <input type="number" name="heart_rate" class="form-control" min="40" max="200" placeholder="72">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Findings</label>
                                        <textarea name="findings" class="form-control" placeholder="Assessment findings..." required></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Fit to Return</label>
                                            <select name="fit_to_return" class="form-control" required>
                                                <option value="Yes">Yes - Cleared to return</option>
                                                <option value="With Restrictions">With Restrictions</option>
                                                <option value="No">No - Not cleared</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Recommended Rest Days</label>
                                            <input type="number" name="recommended_rest_days" class="form-control" min="0" placeholder="If not cleared">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Restrictions</label>
                                        <input type="text" name="restrictions" class="form-control" placeholder="e.g., No PE for 3 days">
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Next Check-up Date</label>
                                            <input type="date" name="next_checkup_date" class="form-control">
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Create Fit-to-Return Slip</button>
                                </form>
                            </div>
                        </div>

                        <!-- Clearance History Tab -->
                        <div class="tab-pane" id="history">
                            <div class="form-card-title" style="margin-bottom: 20px;">
                                📚 Clearance & Certificate History for <?php echo htmlspecialchars($student_data['full_name']); ?>
                            </div>
                            
                            <!-- Clearance Requests -->
                            <h3 style="color: #6b2b5e; margin: 20px 0 10px; font-size: 1rem;">Clearance Requests</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Findings</th>
                                            <th>Status</th>
                                            <th>Valid Until</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $student_clearances = array_filter($pending_clearances + $approved_clearances, function($c) use ($student_data) {
                                            return $c['student_id'] == $student_data['student_id'];
                                        });
                                        ?>
                                        <?php if (!empty($student_clearances)): ?>
                                            <?php foreach ($student_clearances as $clearance): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                    <td><?php echo $clearance['clearance_type']; ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($clearance['request_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($clearance['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($clearance['status']); ?>">
                                                            <?php echo $clearance['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo !empty($clearance['valid_until']) ? date('M d, Y', strtotime($clearance['valid_until'])) : 'No expiry'; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-secondary" onclick="viewDocument('clearance', <?php echo $clearance['id']; ?>)">View</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="empty-state">
                                                    <p>No clearance requests found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Medical Certificates -->
                            <h3 style="color: #6b2b5e; margin: 30px 0 10px; font-size: 1rem;">Medical Certificates</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Type</th>
                                            <th>Issue Date</th>
                                            <th>Findings</th>
                                            <th>Valid Until</th>
                                            <th>Issued By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['certificates'])): ?>
                                            <?php foreach ($student_data['certificates'] as $cert): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($cert['certificate_code']); ?></span></td>
                                                    <td><?php echo $cert['certificate_type']; ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($cert['issued_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($cert['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <?php echo !empty($cert['valid_until']) ? date('M d, Y', strtotime($cert['valid_until'])) : 'No expiry'; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($cert['issued_by']); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-secondary" onclick="viewDocument('certificate', <?php echo $cert['id']; ?>)">View</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="empty-state">
                                                    <p>No medical certificates found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Fit-to-Return Slips -->
                            <h3 style="color: #6b2b5e; margin: 30px 0 10px; font-size: 1rem;">Fit-to-Return Slips</h3>
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Assessment Date</th>
                                            <th>Findings</th>
                                            <th>Fit to Return</th>
                                            <th>Restrictions</th>
                                            <th>Issued By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($student_data['fit_to_return'])): ?>
                                            <?php foreach ($student_data['fit_to_return'] as $slip): ?>
                                                <tr>
                                                    <td><span class="clearance-code"><?php echo htmlspecialchars($slip['slip_code']); ?></span></td>
                                                    <td><?php echo date('M d, Y', strtotime($slip['assessment_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($slip['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($slip['fit_to_return']); ?>">
                                                            <?php echo $slip['fit_to_return']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($slip['restrictions'] ?: 'None'); ?></td>
                                                    <td><?php echo htmlspecialchars($slip['issued_by']); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-secondary" onclick="viewDocument('fitreturn', <?php echo $slip['id']; ?>)">View</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="empty-state">
                                                    <p>No fit-to-return slips found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- All Pending Clearances (if no student selected) -->
                <?php if (!$student_data && !empty($pending_clearances)): ?>
                <div class="tabs-section" style="margin-top: 20px;">
                    <div class="tabs-header">
                        <button class="tab-btn active" onclick="showAllTab('pending', event)">⏳ Pending Clearances</button>
                        <button class="tab-btn" onclick="showAllTab('approved', event)">✅ Approved</button>
                        <button class="tab-btn" onclick="showAllTab('certificates', event)">📄 Recent Certificates</button>
                    </div>

                    <div class="tab-content">
                        <!-- Pending Tab -->
                        <div class="tab-pane active" id="all-pending">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Request Date</th>
                                            <th>Findings</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_clearances as $clearance): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($clearance['student_name']); ?></strong><br>
                                                    <small><?php echo $clearance['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $clearance['clearance_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($clearance['request_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($clearance['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                <td>
                                                    <span class="status-badge status-pending">Pending</span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-secondary" onclick="viewDocument('clearance', <?php echo $clearance['id']; ?>)">View</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Approved Tab -->
                        <div class="tab-pane" id="all-approved">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Approved Date</th>
                                            <th>Findings</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approved_clearances as $clearance): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($clearance['student_name']); ?></strong><br>
                                                    <small><?php echo $clearance['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $clearance['clearance_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($clearance['approved_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($clearance['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                <td>
                                                    <span class="status-badge status-approved">Approved</span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-secondary" onclick="viewDocument('clearance', <?php echo $clearance['id']; ?>)">View</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Certificates Tab -->
                        <div class="tab-pane" id="all-certificates">
                            <div class="table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Issue Date</th>
                                            <th>Findings</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($all_certificates, 0, 20) as $cert): ?>
                                            <tr>
                                                <td><span class="clearance-code"><?php echo htmlspecialchars($cert['certificate_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($cert['student_name']); ?></strong><br>
                                                    <small><?php echo $cert['student_id']; ?></small>
                                                </td>
                                                <td><?php echo $cert['certificate_type']; ?></td>
                                                <td><?php echo date('M d, Y', strtotime($cert['issued_date'])); ?></td>
                                                <td><?php echo htmlspecialchars(substr($cert['findings'] ?? '', 0, 30)) . '...'; ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-secondary" onclick="viewDocument('certificate', <?php echo $cert['id']; ?>)">View</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Document View Modal -->
    <?php if ($view_document && $view_id): 
        // Fetch document details
        $doc_data = null;
        if ($view_document == 'clearance') {
            $query = "SELECT c.*, u.full_name as creator_name 
                     FROM clearance_requests c
                     LEFT JOIN users u ON c.created_by = u.id
                     WHERE c.id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $view_id);
            $stmt->execute();
            $doc_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $doc_title = "Clearance Document";
        } elseif ($view_document == 'certificate') {
            $query = "SELECT * FROM medical_certificates WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $view_id);
            $stmt->execute();
            $doc_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $doc_title = "Medical Certificate";
        } elseif ($view_document == 'fitreturn') {
            $query = "SELECT * FROM fit_to_return_slips WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $view_id);
            $stmt->execute();
            $doc_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $doc_title = "Fit-to-Return Slip";
        }
    ?>
    <div class="document-modal" id="documentModal">
        <div class="document-container">
            <div class="document-header">
                <h2><?php echo $doc_title; ?> - <?php echo htmlspecialchars($doc_data[$view_document == 'clearance' ? 'clearance_code' : ($view_document == 'certificate' ? 'certificate_code' : 'slip_code')]); ?></h2>
                <div class="document-actions">
                    <?php if (!empty($doc_data['pdf_path'])): ?>
                        <a href="../uploads/clearances/<?php echo $doc_data['pdf_path']; ?>" target="_blank" class="btn btn-sm btn-primary">Download PDF</a>
                    <?php endif; ?>
                    <button class="close-btn" onclick="closeDocumentModal()">&times;</button>
                </div>
            </div>
            <div class="document-content">
                <!-- Student Information -->
                <div class="document-field">
                    <div class="document-label">Student Information</div>
                    <div class="document-value">
                        <strong><?php echo htmlspecialchars($doc_data['student_name']); ?></strong><br>
                        Student ID: <?php echo htmlspecialchars($doc_data['student_id']); ?><br>
                        Grade/Section: <?php echo htmlspecialchars($doc_data['grade_section']); ?>
                    </div>
                </div>
                
                <!-- Document Details -->
                <div class="document-field">
                    <div class="document-label">Document Details</div>
                    <div class="document-value">
                        <?php if ($view_document == 'clearance'): ?>
                            <strong>Type:</strong> <?php echo $doc_data['clearance_type']; ?><br>
                            <strong>Purpose:</strong> <?php echo nl2br(htmlspecialchars($doc_data['purpose'])); ?><br>
                            <strong>Request Date:</strong> <?php echo date('F d, Y', strtotime($doc_data['request_date'])); ?>
                        <?php elseif ($view_document == 'certificate'): ?>
                            <strong>Type:</strong> <?php echo $doc_data['certificate_type']; ?><br>
                            <strong>Issue Date:</strong> <?php echo date('F d, Y', strtotime($doc_data['issued_date'])); ?>
                        <?php else: ?>
                            <strong>Assessment Date:</strong> <?php echo date('F d, Y', strtotime($doc_data['assessment_date'])); ?><br>
                            <?php if ($doc_data['absence_days']): ?>
                                <strong>Absence Days:</strong> <?php echo $doc_data['absence_days']; ?><br>
                            <?php endif; ?>
                            <?php if ($doc_data['absence_reason']): ?>
                                <strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($doc_data['absence_reason'])); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Vital Signs -->
                <?php if (!empty($doc_data['vital_signs']) || !empty($doc_data['temperature'])): ?>
                <div class="document-field">
                    <div class="document-label">Vital Signs</div>
                    <div class="document-value">
                        <?php if (!empty($doc_data['vital_signs'])): ?>
                            <?php echo nl2br(htmlspecialchars($doc_data['vital_signs'])); ?>
                        <?php else: ?>
                            Temp: <?php echo $doc_data['temperature'] ?? 'N/A'; ?>°C, 
                            BP: <?php echo $doc_data['blood_pressure'] ?? 'N/A'; ?>, 
                            HR: <?php echo $doc_data['heart_rate'] ?? 'N/A'; ?> bpm
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Findings -->
                <?php if (!empty($doc_data['findings'])): ?>
                <div class="document-field">
                    <div class="document-label">Findings / Assessment</div>
                    <div class="document-value">
                        <?php echo nl2br(htmlspecialchars($doc_data['findings'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Assessment -->
                <?php if (!empty($doc_data['assessment'])): ?>
                <div class="document-field">
                    <div class="document-label">Nurse's Assessment</div>
                    <div class="document-value">
                        <?php echo nl2br(htmlspecialchars($doc_data['assessment'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Status -->
                <?php if ($view_document == 'clearance'): ?>
                <div class="status-box <?php echo strtolower($doc_data['status']); ?>">
                    STATUS: <?php echo strtoupper($doc_data['status']); ?>
                </div>
                <?php elseif ($view_document == 'fitreturn'): ?>
                <div class="status-box <?php echo strtolower($doc_data['fit_to_return']); ?>">
                    FIT TO RETURN: <?php echo strtoupper($doc_data['fit_to_return']); ?>
                </div>
                <?php endif; ?>
                
                <!-- Recommendations & Restrictions -->
                <?php if (!empty($doc_data['recommendations'])): ?>
                <div class="document-field">
                    <div class="document-label">Recommendations</div>
                    <div class="document-value"><?php echo nl2br(htmlspecialchars($doc_data['recommendations'])); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($doc_data['restrictions'])): ?>
                <div class="document-field">
                    <div class="document-label">Restrictions</div>
                    <div class="document-value"><?php echo nl2br(htmlspecialchars($doc_data['restrictions'])); ?></div>
                </div>
                <?php endif; ?>
                
                <!-- Validity -->
                <?php if (!empty($doc_data['valid_until'])): ?>
                <div class="document-field">
                    <div class="document-label">Valid Until</div>
                    <div class="document-value"><?php echo date('F d, Y', strtotime($doc_data['valid_until'])); ?></div>
                </div>
                <?php endif; ?>
                
                <!-- Issued By -->
                <div class="document-field">
                    <div class="document-label">Issued By</div>
                    <div class="document-value">
                        <?php echo htmlspecialchars($doc_data['issued_by'] ?? $doc_data['approved_by'] ?? $doc_data['creator_name'] ?? 'Clinic Staff'); ?>
                    </div>
                </div>
            </div>
            <div class="document-footer">
                <button class="btn btn-secondary" onclick="closeDocumentModal()">Close</button>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('documentModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    </script>
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

        // Tab functionality
        function showTab(tabName, event) {
            document.querySelectorAll('.tab-pane').forEach(pane => {
                if (pane.id !== 'all-pending' && pane.id !== 'all-approved' && pane.id !== 'all-certificates') {
                    pane.classList.remove('active');
                }
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // All tabs functionality
        function showAllTab(tabName, event) {
            document.querySelectorAll('#all-pending, #all-approved, #all-certificates').forEach(pane => {
                pane.classList.remove('active');
            });
            document.querySelectorAll('.tabs-section .tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById('all-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // View document function
        function viewDocument(type, id) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', type);
            url.searchParams.set('id', id);
            window.location.href = url.toString();
        }

        // Close document modal
        function closeDocumentModal() {
            const modal = document.getElementById('documentModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                // Remove view parameters from URL
                const url = new URL(window.location.href);
                url.searchParams.delete('view');
                url.searchParams.delete('id');
                window.history.replaceState({}, '', url.toString());
            }
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Health Clearance';
        }
    </script>
</body>
</html>