<?php
require_once('../vendor/setasign/fpdf/fpdf.php');
require_once '../config/database.php';



session_start();
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch clearance data
$query = "SELECT c.*, u.full_name as clinic_staff 
          FROM clearance_requests c
          LEFT JOIN users u ON c.created_by = u.id
          WHERE c.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$clearance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$clearance) {
    die('Clearance not found');
}

// Create PDF
class PDF extends FPDF
{
    function Header()
    {
        // School Logo Placeholder
        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(107, 43, 94); // #6b2b5e
        $this->Cell(0, 15, 'ICARE SCHOOL CLINIC', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 12);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Health Clearance Certificate', 0, 1, 'C');
        $this->Ln(5);
        
        // Line
        $this->SetDrawColor(161, 74, 118); // #a14a76
        $this->SetLineWidth(0.5);
        $this->Line(20, 40, 190, 40);
        $this->Ln(15);
    }
    
    function Footer()
    {
        $this->SetY(-30);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'This clearance is valid only with clinic stamp and signature.', 0, 1, 'C');
        $this->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
        $this->Cell(0, 5, 'ICARE Clinic Management System', 0, 1, 'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetMargins(20, 20, 20);

// Clearance Title
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(107, 43, 94);
$pdf->Cell(0, 10, 'MEDICAL CLEARANCE', 0, 1, 'C');
$pdf->Ln(10);

// Clearance Code Box
$pdf->SetFillColor(240, 226, 234); // Light purple background
$pdf->SetTextColor(107, 43, 94);
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 15, 'Clearance No: ' . $clearance['clearance_code'], 0, 1, 'C', true);
$pdf->Ln(10);

// Student Information Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(107, 43, 94);
$pdf->Cell(0, 8, 'STUDENT INFORMATION', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(0, 0, 0);

// Two column layout for student info
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 8, $clearance['student_name'], 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Student ID:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 8, $clearance['student_id'], 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Grade & Section:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 8, $clearance['grade_section'], 0, 1);

$pdf->Ln(10);

// Clearance Details Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(107, 43, 94);
$pdf->Cell(0, 8, 'CLEARANCE DETAILS', 0, 1);
$pdf->SetTextColor(0, 0, 0);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Clearance Type:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 8, $clearance['clearance_type'], 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Purpose:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 8, $clearance['purpose'], 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Request Date:', 0, 0);
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 8, date('F d, Y', strtotime($clearance['request_date'])), 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(50, 8, 'Status:', 0, 0);
$pdf->SetFont('Arial', '', 11);

// Status with color
if ($clearance['status'] == 'Approved') {
    $pdf->SetTextColor(30, 120, 80); // Green
    $status_text = '✓ APPROVED';
} elseif ($clearance['status'] == 'Pending') {
    $pdf->SetTextColor(255, 140, 0); // Orange
    $status_text = '⏳ PENDING';
} elseif ($clearance['status'] == 'Not Cleared') {
    $pdf->SetTextColor(200, 60, 60); // Red
    $status_text = '✗ NOT CLEARED';
} else {
    $pdf->SetTextColor(150, 150, 150); // Gray
    $status_text = $clearance['status'];
}
$pdf->Cell(0, 8, $status_text, 0, 1);
$pdf->SetTextColor(0, 0, 0);

if (!empty($clearance['valid_until'])) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 8, 'Valid Until:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, date('F d, Y', strtotime($clearance['valid_until'])), 0, 1);
}

if (!empty($clearance['remarks'])) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Remarks:', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 8, $clearance['remarks'], 0, 1);
}

$pdf->Ln(20);

// Approval Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(107, 43, 94);
$pdf->Cell(0, 8, 'CLINIC APPROVAL', 0, 1);
$pdf->SetTextColor(0, 0, 0);

$pdf->Ln(5);

// Signature lines
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(100, 10, '_________________________', 0, 0);
$pdf->Cell(90, 10, '_________________________', 0, 1);

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(100, 5, 'Issued By: ' . ($clearance['clinic_staff'] ?? $clearance['approved_by'] ?? 'Clinic Staff'), 0, 0);
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1);

$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'This document is system-generated and valid with official clinic stamp.', 0, 1, 'C');

// Clinic Stamp Placeholder
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(107, 43, 94);
$pdf->Cell(0, 5, '[OFFICIAL CLINIC STAMP]', 0, 1, 'R');

// Output PDF
$filename = 'Clearance_' . $clearance['clearance_code'] . '.pdf';
$pdf->Output('I', $filename);