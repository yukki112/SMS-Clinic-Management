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

// Fetch fit-to-return data
$query = "SELECT f.*, u.full_name as clinic_staff 
          FROM fit_to_return_slips f
          LEFT JOIN users u ON f.issuer_id = u.id
          WHERE f.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$slip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$slip) {
    die('Record not found');
}

// Create PDF
class PDF extends FPDF
{
    function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'FIT-TO-RETURN SLIP', 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'School Clinic - ICARE', 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetDrawColor(161, 74, 118);
        $this->Line(10, 40, 200, 40);
        $this->Ln(10);
    }
    
    function Footer()
    {
        $this->SetY(-30);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, 'This slip must be presented to the teacher upon returning to class.', 0, 1, 'C');
        $this->Cell(0, 5, 'Generated on: ' . date('F d, Y'), 0, 1, 'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Slip details
$pdf->Cell(45, 10, 'Slip No:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $slip['slip_code'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $slip['student_name'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $slip['grade_section'], 0, 1);

$pdf->Ln(10);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'ASSESSMENT RESULT', 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 8, 'Assessment Date:', 0, 0);
$pdf->Cell(0, 8, date('F d, Y', strtotime($slip['assessment_date'])), 0, 1);

if (!empty($slip['absence_days'])) {
    $pdf->Cell(45, 8, 'Days Absent:', 0, 0);
    $pdf->Cell(0, 8, $slip['absence_days'] . ' day(s)', 0, 1);
}

if (!empty($slip['absence_reason'])) {
    $pdf->Cell(45, 8, 'Reason:', 0, 0);
    $pdf->MultiCell(0, 8, $slip['absence_reason'], 0, 1);
}

$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Vital Signs', 0, 1);

$pdf->SetFont('Arial', '', 12);
$vitals = [];
if (!empty($slip['temperature'])) $vitals[] = 'Temp: ' . $slip['temperature'] . '°C';
if (!empty($slip['blood_pressure'])) $vitals[] = 'BP: ' . $slip['blood_pressure'];
if (!empty($slip['heart_rate'])) $vitals[] = 'HR: ' . $slip['heart_rate'] . ' bpm';

$pdf->Cell(0, 8, implode(' | ', $vitals), 0, 1);

$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Findings', 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->MultiCell(0, 8, $slip['findings'], 0, 1);

$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 14);
$fit_status = $slip['fit_to_return'] == 'Yes' ? '✓ FIT TO RETURN' : ($slip['fit_to_return'] == 'With Restrictions' ? '⚠ FIT WITH RESTRICTIONS' : '✗ NOT CLEARED TO RETURN');
$pdf->SetTextColor($slip['fit_to_return'] == 'Yes' ? 30 : ($slip['fit_to_return'] == 'With Restrictions' ? 161 : 196));
$pdf->Cell(0, 10, $fit_status, 0, 1);

$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 12);

if (!empty($slip['restrictions'])) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Restrictions:', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 8, $slip['restrictions'], 0, 1);
}

if (!empty($slip['recommended_rest_days']) && $slip['recommended_rest_days'] > 0) {
    $pdf->Ln(5);
    $pdf->Cell(0, 8, 'Recommended rest: ' . $slip['recommended_rest_days'] . ' more day(s)', 0, 1);
}

if (!empty($slip['next_checkup_date'])) {
    $pdf->Cell(0, 8, 'Next check-up: ' . date('F d, Y', strtotime($slip['next_checkup_date'])), 0, 1);
}

$pdf->Ln(20);

$pdf->Cell(100, 10, '_________________________', 0, 0);
$pdf->Cell(90, 10, '_________________________', 0, 1);

$pdf->Cell(100, 5, 'Issued By: ' . $slip['issued_by'], 0, 0);
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1);

$pdf->Output('I', 'FitToReturn_' . $slip['slip_code'] . '.pdf');