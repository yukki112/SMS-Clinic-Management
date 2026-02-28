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

// Fetch fit-to-return data with additional medical evaluation
$query = "SELECT f.*, 
          u.full_name as clinic_staff,
          pe.exam_date, pe.height, pe.weight, pe.bmi, 
          pe.vision_left, pe.vision_right, pe.hearing_left, pe.hearing_right,
          pe.dental_findings, pe.general_assessment, pe.fit_for_school
          FROM fit_to_return_slips f
          LEFT JOIN users u ON f.issuer_id = u.id
          LEFT JOIN physical_exam_records pe ON pe.student_id = f.student_id 
              AND pe.exam_date <= f.assessment_date
          WHERE f.id = :id
          ORDER BY pe.exam_date DESC
          LIMIT 1";
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
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(25, 25, 112); // #191970
        $this->Cell(0, 8, 'ICARE SCHOOL CLINIC', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(84, 110, 122); // #546e7a
        $this->Cell(0, 4, '123 Education Avenue, Quezon City', 0, 1, 'C');
        $this->Cell(0, 4, 'Tel: (02) 8123-4567 | Email: clinic@icare.edu', 0, 1, 'C');
        
        $this->Ln(5);
        
        // Certificate Title
        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(25, 25, 112);
        $this->Cell(0, 10, 'FIT-TO-RETURN SLIP', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(161, 74, 118); // #A14A76
        $this->Cell(0, 5, 'OFFICIAL SCHOOL CLINIC DOCUMENT', 0, 1, 'C');
        
        $this->Ln(5);
        
        // Decorative line
        $this->SetDrawColor(161, 74, 118);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(8);
    }
    
    function Footer()
    {
        $this->SetY(-25);
        
        // Decorative line
        $this->SetDrawColor(161, 74, 118);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);
        
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(108, 117, 125);
        $this->Cell(0, 3, 'This slip must be presented to the teacher upon returning to class.', 0, 1, 'C');
        $this->Cell(0, 3, 'Generated on: ' . date('F d, Y') . ' | Slip No: ' . $this->slip_no, 0, 1, 'C');
    }
    
    function SetSlipNo($no) {
        $this->slip_no = $no;
    }
    
    // Section title with background
    function SectionTitle($title) {
        $this->SetFillColor(236, 239, 241); // #eceff1
        $this->SetDrawColor(25, 25, 112);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(25, 25, 112);
        $this->Cell(0, 6, $title, 0, 1, 'L', true);
        $this->Ln(2);
    }
    
    // Label-value pair
    function LabelValue($label, $value, $width = 45) {
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(55, 71, 79);
        $this->Cell($width, 5, $label . ':', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(33, 33, 33);
        $this->Cell(0, 5, $value, 0, 1);
    }
    
    // Two-column label-value
    function LabelValue2Col($label1, $value1, $label2, $value2) {
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(55, 71, 79);
        $this->Cell(40, 5, $label1 . ':', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(33, 33, 33);
        $this->Cell(60, 5, $value1, 0, 0);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(55, 71, 79);
        $this->Cell(35, 5, $label2 . ':', 0, 0);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(33, 33, 33);
        $this->Cell(0, 5, $value2, 0, 1);
    }
}

$pdf = new PDF();
$pdf->SetSlipNo($slip['slip_code']);
$pdf->AddPage();
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 30);

// Slip Number
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 5, 'SLIP NO: ' . $slip['slip_code'], 0, 1, 'R');
$pdf->Ln(2);

// ===== STUDENT INFORMATION =====
$pdf->SectionTitle('STUDENT INFORMATION');

// Student info in two columns
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(35, 5, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(80, 5, $slip['student_name'], 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(15, 5, 'ID:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(0, 5, $slip['student_id'], 0, 1);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(35, 5, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(0, 5, $slip['grade_section'], 0, 1);

$pdf->Ln(3);

// ===== ASSESSMENT DETAILS =====
$pdf->SectionTitle('ASSESSMENT DETAILS');

$pdf->LabelValue2Col('Assessment Date', date('F d, Y', strtotime($slip['assessment_date'])), 
                     'Days Absent', $slip['absence_days'] ? $slip['absence_days'] . ' day(s)' : 'N/A');

if (!empty($slip['absence_reason'])) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(40, 5, 'Reason:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4, $slip['absence_reason'], 0, 1);
}

$pdf->Ln(2);

// ===== VITAL SIGNS =====
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 5, 'VITAL SIGNS', 0, 1);
$pdf->Ln(1);

// Vital signs in a table-like format
$pdf->SetFillColor(236, 239, 241);
$pdf->SetDrawColor(161, 74, 118);

// Header
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(60, 5, 'Temperature', 1, 0, 'C', true);
$pdf->Cell(60, 5, 'Blood Pressure', 1, 0, 'C', true);
$pdf->Cell(60, 5, 'Heart Rate', 1, 1, 'C', true);

// Values
$temp = $slip['temperature'] ? $slip['temperature'] . ' °C' : 'N/A';
$bp = $slip['blood_pressure'] ?? 'N/A';
$hr = $slip['heart_rate'] ? $slip['heart_rate'] . ' bpm' : 'N/A';

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(60, 5, $temp, 1, 0, 'C');
$pdf->Cell(60, 5, $bp, 1, 0, 'C');
$pdf->Cell(60, 5, $hr, 1, 1, 'C');

$pdf->Ln(3);

// ===== MEDICAL EVALUATION DETAILS =====
$pdf->SectionTitle('MEDICAL EVALUATION DETAILS');

// Check if we have physical exam data
if (!empty($slip['exam_date'])) {
    
    // Physical Exam Section
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(25, 25, 112);
    $pdf->Cell(0, 5, 'Physical Examination:', 0, 1);
    $pdf->Ln(1);
    
    // Anthropometrics
    $pdf->LabelValue2Col('Exam Date', date('F d, Y', strtotime($slip['exam_date'])), 
                         'Fit for School', $slip['fit_for_school'] ?? 'N/A');
    
    $height = $slip['height'] ? $slip['height'] . ' cm' : 'N/A';
    $weight = $slip['weight'] ? $slip['weight'] . ' kg' : 'N/A';
    $bmi = $slip['bmi'] ? $slip['bmi'] : 'N/A';
    
    $pdf->LabelValue2Col('Height', $height, 'Weight', $weight);
    $pdf->LabelValue2Col('BMI', $bmi, '', '');
    
    // Vision
    $pdf->LabelValue2Col('Vision (Left)', $slip['vision_left'] ?? 'N/A', 
                         'Vision (Right)', $slip['vision_right'] ?? 'N/A');
    
    // Hearing
    $pdf->LabelValue2Col('Hearing (Left)', $slip['hearing_left'] ?? 'N/A', 
                         'Hearing (Right)', $slip['hearing_right'] ?? 'N/A');
    
    // Dental
    if (!empty($slip['dental_findings'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(40, 4, 'Dental:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 4, $slip['dental_findings'], 0, 1);
    }
    
    // General Assessment
    if (!empty($slip['general_assessment'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(40, 4, 'Assessment:', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 4, $slip['general_assessment'], 0, 1);
    }
    
    $pdf->Ln(2);
    
} else {
    // No physical exam data
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 5, 'No recent physical examination on record.', 0, 1);
    $pdf->Ln(2);
}

// ===== FINDINGS =====
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 5, 'FINDINGS', 0, 1);
$pdf->Ln(1);

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->MultiCell(0, 4, $slip['findings'], 0, 1);
$pdf->Ln(3);

// ===== FIT STATUS =====
$pdf->SetFont('Arial', 'B', 14);
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
$pdf->Cell(0, 8, $status_text, 0, 1, 'C');

$pdf->SetTextColor(33, 33, 33);
$pdf->Ln(3);

// ===== RESTRICTIONS =====
if (!empty($slip['restrictions'])) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(196, 69, 69);
    $pdf->Cell(0, 5, 'RESTRICTIONS', 0, 1);
    $pdf->Ln(1);
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(33, 33, 33);
    $pdf->MultiCell(0, 4, $slip['restrictions'], 0, 1);
    $pdf->Ln(3);
}

// ===== ADDITIONAL INFO =====
if (!empty($slip['recommended_rest_days']) && $slip['recommended_rest_days'] > 0) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(45, 5, 'Recommended rest:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $slip['recommended_rest_days'] . ' more day(s)', 0, 1);
}

if (!empty($slip['next_checkup_date'])) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(45, 5, 'Next check-up:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, date('F d, Y', strtotime($slip['next_checkup_date'])), 0, 1);
}

$pdf->Ln(5);

// ===== CERTIFICATION STATEMENT =====
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(84, 110, 122);
$pdf->MultiCell(0, 3.5, 'This certifies that the above-named student has been assessed by the school clinic and is cleared to return to class based on the findings and medical evaluation above.', 0, 1, 'C');
$pdf->Ln(5);

// ===== SIGNATURE LINES =====
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(90, 4, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 4, '_________________________', 0, 1, 'C');

$pdf->Cell(90, 4, 'Issued By: ' . $slip['issued_by'], 0, 0, 'C');
$pdf->Cell(90, 4, 'Clinic Staff Signature', 0, 1, 'C');

$pdf->Ln(4);

// Right side - Printed Name
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 3, '', 0, 0);
$pdf->Cell(90, 3, $slip['issued_by'], 0, 1, 'C');
$pdf->Cell(90, 3, '', 0, 0);
$pdf->SetFont('Arial', 'I', 7);
$pdf->Cell(90, 3, 'Clinic Staff / License No.', 0, 1, 'C');

$pdf->Ln(3);

// ===== STAMP AREA =====
$stamp_y = $pdf->GetY() - 5;
$pdf->SetDrawColor(161, 74, 118);
$pdf->SetLineWidth(0.3);
$pdf->Rect(150, $stamp_y, 40, 15);

$pdf->SetXY(152, $stamp_y + 2);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetTextColor(161, 74, 118);
$pdf->Cell(36, 3, 'CLINIC STAMP', 0, 1, 'C');

$pdf->SetXY(152, $stamp_y + 6);
$pdf->SetFont('Arial', 'I', 6);
$pdf->Cell(36, 3, '(Official Seal)', 0, 1, 'C');

$pdf->Output('I', 'FitToReturn_' . $slip['slip_code'] . '.pdf');
?>