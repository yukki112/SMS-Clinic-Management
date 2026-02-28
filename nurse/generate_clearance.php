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

// Fetch clearance data with medical evaluation details
$query = "SELECT c.*, 
          u.full_name as created_by_name,
          pe.exam_date, pe.height, pe.weight, pe.bmi, 
          pe.vision_left, pe.vision_right, pe.hearing_left, pe.hearing_right,
          pe.dental_findings, pe.general_assessment, pe.fit_for_school,
          v.visit_date, v.temperature, v.blood_pressure, v.heart_rate, v.complaint,
          v.treatment_given, v.disposition
          FROM clearance_requests c
          LEFT JOIN users u ON c.created_by = u.id
          LEFT JOIN physical_exam_records pe ON pe.student_id = c.student_id 
              AND pe.exam_date <= c.request_date
          LEFT JOIN visit_history v ON v.student_id = c.student_id 
              AND v.visit_date <= c.request_date
          WHERE c.id = :id
          ORDER BY pe.exam_date DESC, v.visit_date DESC
          LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$clearance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$clearance) {
    die('Clearance request not found');
}

// Only approved clearances can be generated
if ($clearance['status'] !== 'Approved') {
    die('Clearance is not approved yet. PDF generation is only available for approved clearances.');
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
        $this->Cell(0, 10, 'STUDENT CLEARANCE FORM', 0, 1, 'C');
        
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
        $this->Cell(0, 3, 'This clearance form is valid only with clinic stamp and authorized signature.', 0, 1, 'C');
        $this->Cell(0, 3, 'Generated on: ' . date('F d, Y') . ' | Control No: ' . $this->control_no, 0, 1, 'C');
    }
    
    function SetControlNo($no) {
        $this->control_no = $no;
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
$pdf->SetControlNo($clearance['clearance_code']);
$pdf->AddPage();
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 30);

// Control Number
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 5, 'CLEARANCE NO: ' . $clearance['clearance_code'], 0, 1, 'R');
$pdf->Ln(2);

// ===== STUDENT INFORMATION =====
$pdf->SectionTitle('STUDENT INFORMATION');

// Student info in two columns
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(35, 5, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(80, 5, $clearance['student_name'], 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(15, 5, 'ID:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(0, 5, $clearance['student_id'], 0, 1);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(55, 71, 79);
$pdf->Cell(35, 5, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->Cell(0, 5, $clearance['grade_section'], 0, 1);

$pdf->Ln(3);

// ===== CLEARANCE DETAILS =====
$pdf->SectionTitle('CLEARANCE DETAILS');

$pdf->LabelValue2Col('Clearance Type', $clearance['clearance_type'], 
                     'Request Date', date('F d, Y', strtotime($clearance['request_date'])));

$pdf->LabelValue2Col('Approved Date', !empty($clearance['approved_date']) ? date('F d, Y', strtotime($clearance['approved_date'])) : 'N/A', 
                     'Valid Until', !empty($clearance['valid_until']) ? date('F d, Y', strtotime($clearance['valid_until'])) : 'No Expiry');

$pdf->Ln(3);

// ===== PURPOSE =====
$pdf->SectionTitle('PURPOSE');

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(33, 33, 33);
$pdf->MultiCell(0, 4, $clearance['purpose'], 0, 1);
$pdf->Ln(3);

// ===== MEDICAL EVALUATION DETAILS =====
$pdf->SectionTitle('MEDICAL EVALUATION DETAILS');

// Check if we have medical data
$hasMedicalData = !empty($clearance['exam_date']) || !empty($clearance['visit_date']);

if ($hasMedicalData) {
    
    // Physical Exam Section
    if (!empty($clearance['exam_date'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(25, 25, 112);
        $pdf->Cell(0, 5, 'Physical Examination:', 0, 1);
        $pdf->Ln(1);
        
        // Anthropometrics
        $pdf->LabelValue2Col('Exam Date', date('F d, Y', strtotime($clearance['exam_date'])), 
                             'Fit for School', $clearance['fit_for_school'] ?? 'N/A');
        
        $height = $clearance['height'] ? $clearance['height'] . ' cm' : 'N/A';
        $weight = $clearance['weight'] ? $clearance['weight'] . ' kg' : 'N/A';
        $bmi = $clearance['bmi'] ? $clearance['bmi'] : 'N/A';
        
        $pdf->LabelValue2Col('Height', $height, 'Weight', $weight);
        $pdf->LabelValue2Col('BMI', $bmi, '', '');
        
        // Vision
        $pdf->LabelValue2Col('Vision (Left)', $clearance['vision_left'] ?? 'N/A', 
                             'Vision (Right)', $clearance['vision_right'] ?? 'N/A');
        
        // Hearing
        $pdf->LabelValue2Col('Hearing (Left)', $clearance['hearing_left'] ?? 'N/A', 
                             'Hearing (Right)', $clearance['hearing_right'] ?? 'N/A');
        
        // Dental
        if (!empty($clearance['dental_findings'])) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 4, 'Dental:', 0, 0);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 4, $clearance['dental_findings'], 0, 1);
        }
        
        // General Assessment
        if (!empty($clearance['general_assessment'])) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 4, 'Assessment:', 0, 0);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 4, $clearance['general_assessment'], 0, 1);
        }
        
        $pdf->Ln(2);
    }
    
    // Visit/Consultation Section
    if (!empty($clearance['visit_date'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(25, 25, 112);
        $pdf->Cell(0, 5, 'Recent Consultation:', 0, 1);
        $pdf->Ln(1);
        
        $pdf->LabelValue2Col('Visit Date', date('F d, Y', strtotime($clearance['visit_date'])), 
                             'Complaint', $clearance['complaint'] ?? 'N/A');
        
        // Vital Signs
        $temp = $clearance['temperature'] ? $clearance['temperature'] . ' °C' : 'N/A';
        $bp = $clearance['blood_pressure'] ?? 'N/A';
        $hr = $clearance['heart_rate'] ? $clearance['heart_rate'] . ' bpm' : 'N/A';
        
        $pdf->LabelValue('Temperature', $temp);
        $pdf->LabelValue('Blood Pressure', $bp);
        $pdf->LabelValue('Heart Rate', $hr);
        
        // Treatment
        if (!empty($clearance['treatment_given'])) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 4, 'Treatment:', 0, 0);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 4, $clearance['treatment_given'], 0, 1);
        }
        
        // Disposition
        if (!empty($clearance['disposition'])) {
            $pdf->LabelValue('Disposition', $clearance['disposition']);
        }
        
        $pdf->Ln(2);
    }
    
} else {
    // No medical data available
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 5, 'No recent medical evaluation on record.', 0, 1);
    $pdf->Ln(2);
}

$pdf->Ln(2);

// ===== APPROVAL =====
$pdf->SectionTitle('APPROVAL');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(46, 125, 50); // Green for Approved
$pdf->Cell(30, 6, 'Status:', 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, strtoupper($clearance['status']), 0, 1);

$pdf->SetTextColor(55, 71, 79);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 5, 'Approved By:', 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, $clearance['approved_by'] ?: 'N/A', 0, 1);

if (!empty($clearance['remarks'])) {
    $pdf->Ln(1);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(30, 5, 'Remarks:', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4, $clearance['remarks'], 0, 1);
}

$pdf->Ln(5);

// ===== CERTIFICATION STATEMENT =====
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(84, 110, 122);
$pdf->MultiCell(0, 3.5, 'This certifies that the above-named student has complied with the school clinic requirements and is cleared for the stated purpose, based on the medical evaluation findings above.', 0, 1, 'C');
$pdf->Ln(5);

// ===== SIGNATURE LINES =====
$y_before = $pdf->GetY();

// Left side - Student Signature
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(90, 4, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 4, '_________________________', 0, 1, 'C');

$pdf->Cell(90, 4, 'Student Signature', 0, 0, 'C');
$pdf->Cell(90, 4, 'Clinic Staff Signature', 0, 1, 'C');

$pdf->Ln(4);

// Right side - Printed Name
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 3, '', 0, 0);
$pdf->Cell(90, 3, $clearance['approved_by'] ?: 'Clinic Head', 0, 1, 'C');
$pdf->Cell(90, 3, '', 0, 0);
$pdf->SetFont('Arial', 'I', 7);
$pdf->Cell(90, 3, 'Printed Name & License No.', 0, 1, 'C');

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

$pdf->Output('I', 'Clearance_' . $clearance['clearance_code'] . '.pdf');
?>