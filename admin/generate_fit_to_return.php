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

// Create PDF with improved design
class PDF extends FPDF
{
    function Header()
    {
        // School Logo Placeholder
        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(25, 25, 112); // #191970
        $this->Cell(0, 15, 'ICARE SCHOOL CLINIC', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(84, 110, 122); // #546e7a
        $this->Cell(0, 5, '123 Education Avenue, Quezon City', 0, 1, 'C');
        $this->Cell(0, 5, 'Tel: (02) 8123-4567 | Email: clinic@icare.edu', 0, 1, 'C');
        
        $this->Ln(8);
        
        // Certificate Title
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(25, 25, 112);
        $this->Cell(0, 15, 'FIT-TO-RETURN SLIP', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(161, 74, 118); // #A14A76
        $this->Cell(0, 8, 'OFFICIAL SCHOOL CLINIC DOCUMENT', 0, 1, 'C');
        
        $this->Ln(10);
        
        // Decorative line
        $this->SetDrawColor(161, 74, 118);
        $this->SetLineWidth(1);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(15);
    }
    
    function Footer()
    {
        $this->SetY(-35);
        
        // Decorative line
        $this->SetDrawColor(161, 74, 118);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
        
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(108, 117, 125);
        $this->Cell(0, 4, 'This slip must be presented to the teacher upon returning to class.', 0, 1, 'C');
        $this->Cell(0, 4, 'Generated on: ' . date('F d, Y') . ' | Slip No: ' . $this->slip_no, 0, 1, 'C');
    }
    
    function SetSlipNo($no) {
        $this->slip_no = $no;
    }
}

$pdf = new PDF();
$pdf->SetSlipNo($slip['slip_code']);
$pdf->AddPage();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 40);

// Slip Number
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'SLIP NO: ' . $slip['slip_code'], 0, 1, 'R');
$pdf->Ln(5);

// Student Information Box
$pdf->SetFillColor(236, 239, 241); // #eceff1
$pdf->SetDrawColor(25, 25, 112);
$pdf->SetLineWidth(0.3);
$pdf->Rect(15, $pdf->GetY(), 180, 40, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'STUDENT INFORMATION', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(55, 71, 79); // #37474f
$pdf->Cell(45, 7, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(100, 7, $slip['student_name'], 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(20, 7, 'ID:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $slip['student_id'], 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $slip['grade_section'], 0, 1);

$pdf->Ln(10);

// Assessment Details Box
$pdf->SetFillColor(236, 239, 241);
$pdf->SetDrawColor(25, 25, 112);
$pdf->Rect(15, $pdf->GetY(), 180, 45, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'ASSESSMENT DETAILS', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Assessment Date:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, date('F d, Y', strtotime($slip['assessment_date'])), 0, 1);

$pdf->SetX(20);
if (!empty($slip['absence_days'])) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(45, 7, 'Days Absent:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $slip['absence_days'] . ' day(s)', 0, 1);
    $pdf->SetX(20);
}

if (!empty($slip['absence_reason'])) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(45, 7, 'Reason:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $slip['absence_reason'], 0, 1);
    $pdf->SetX(20);
}

$pdf->Ln(5);

// Vital Signs
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'VITAL SIGNS', 0, 1);
$pdf->SetX(20);

$vitals = [];
if (!empty($slip['temperature'])) $vitals[] = 'Temp: ' . $slip['temperature'] . '°C';
if (!empty($slip['blood_pressure'])) $vitals[] = 'BP: ' . $slip['blood_pressure'];
if (!empty($slip['heart_rate'])) $vitals[] = 'HR: ' . $slip['heart_rate'] . ' bpm';

$pdf->SetFont('Arial', '', 11);
$pdf->Cell(0, 7, implode(' | ', $vitals), 0, 1);
$pdf->Ln(5);

// Findings
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'FINDINGS', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(55, 71, 79);
$pdf->MultiCell(0, 7, $slip['findings'], 0, 1);
$pdf->Ln(5);

// Fit Status - Highlighted
$pdf->SetFont('Arial', 'B', 16);
if ($slip['fit_to_return'] == 'Yes') {
    $pdf->SetTextColor(46, 125, 50); // Green
    $status_text = '✓ FIT TO RETURN';
} elseif ($slip['fit_to_return'] == 'With Restrictions') {
    $pdf->SetTextColor(161, 74, 118); // Purple
    $status_text = '⚠ FIT WITH RESTRICTIONS';
} else {
    $pdf->SetTextColor(196, 69, 69); // Red
    $status_text = '✗ NOT CLEARED TO RETURN';
}
$pdf->Cell(0, 10, $status_text, 0, 1, 'C');

$pdf->SetTextColor(55, 71, 79);
$pdf->Ln(5);

// Restrictions
if (!empty($slip['restrictions'])) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(196, 69, 69);
    $pdf->Cell(0, 8, 'RESTRICTIONS', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(55, 71, 79);
    $pdf->MultiCell(0, 7, $slip['restrictions'], 0, 1);
    $pdf->Ln(5);
}

// Additional Info
if (!empty($slip['recommended_rest_days']) && $slip['recommended_rest_days'] > 0) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(45, 7, 'Recommended rest:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, $slip['recommended_rest_days'] . ' more day(s)', 0, 1);
}

if (!empty($slip['next_checkup_date'])) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(45, 7, 'Next check-up:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 7, date('F d, Y', strtotime($slip['next_checkup_date'])), 0, 1);
}

$pdf->Ln(15);

// Certification Statement
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(84, 110, 122);
$pdf->MultiCell(0, 5, 'This certifies that the above-named student has been assessed by the school clinic and is cleared to return to class based on the findings above.', 0, 1, 'C');
$pdf->Ln(10);

// Signature Lines
$pdf->SetFont('Arial', '', 10);

$pdf->Cell(100, 5, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 5, '_________________________', 0, 1, 'C');

$pdf->Cell(100, 5, 'Issued By: ' . $slip['issued_by'], 0, 0, 'C');
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1, 'C');

$pdf->Ln(5);

// Stamp placeholder
$pdf->SetDrawColor(161, 74, 118);
$pdf->SetLineWidth(0.5);
$pdf->Rect(150, $pdf->GetY() - 5, 40, 20);
$pdf->SetXY(152, $pdf->GetY() - 3);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(161, 74, 118);
$pdf->Cell(36, 5, 'CLINIC STAMP', 0, 1, 'C');
$pdf->SetXY(152, $pdf->GetY() + 5);
$pdf->Cell(36, 5, '(Official Seal)', 0, 1, 'C');

$pdf->Output('I', 'FitToReturn_' . $slip['slip_code'] . '.pdf');
?>