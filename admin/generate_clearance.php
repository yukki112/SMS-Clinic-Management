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
$query = "SELECT c.*, u.full_name as created_by_name 
          FROM clearance_requests c
          LEFT JOIN users u ON c.created_by = u.id
          WHERE c.id = :id";
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
        $this->Cell(0, 15, 'STUDENT CLEARANCE FORM', 0, 1, 'C');
        
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
        $this->Cell(0, 4, 'This clearance form is valid only with clinic stamp and authorized signature.', 0, 1, 'C');
        $this->Cell(0, 4, 'Generated on: ' . date('F d, Y') . ' | Control No: ' . $this->control_no, 0, 1, 'C');
    }
    
    function SetControlNo($no) {
        $this->control_no = $no;
    }
}

$pdf = new PDF();
$pdf->SetControlNo($clearance['clearance_code']);
$pdf->AddPage();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 40);

// Control Number
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'CLEARANCE NO: ' . $clearance['clearance_code'], 0, 1, 'R');
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

$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(55, 71, 79); // #37474f

// Two-column layout for student info
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(100, 7, $clearance['student_name'], 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(20, 7, 'ID:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $clearance['student_id'], 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $clearance['grade_section'], 0, 1);

$pdf->Ln(5);

// Clearance Details Box
$pdf->SetFillColor(236, 239, 241);
$pdf->SetDrawColor(25, 25, 112);
$pdf->Rect(15, $pdf->GetY(), 180, 45, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'CLEARANCE DETAILS', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Clearance Type:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $clearance['clearance_type'], 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Request Date:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, date('F d, Y', strtotime($clearance['request_date'])), 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Approved Date:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, !empty($clearance['approved_date']) ? date('F d, Y', strtotime($clearance['approved_date'])) : 'N/A', 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Valid Until:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$valid_until = !empty($clearance['valid_until']) ? date('F d, Y', strtotime($clearance['valid_until'])) : 'No Expiry';
$pdf->Cell(0, 7, $valid_until, 0, 1);

$pdf->Ln(10);

// Purpose Section
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'PURPOSE', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(55, 71, 79);
$pdf->MultiCell(0, 7, $clearance['purpose'], 0, 1);
$pdf->Ln(5);

// Approval Box
$pdf->SetFillColor(236, 239, 241);
$pdf->SetDrawColor(25, 25, 112);
$pdf->Rect(15, $pdf->GetY(), 180, 35, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'APPROVAL', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Status:', 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(46, 125, 50); // Green for Approved
$pdf->Cell(0, 7, strtoupper($clearance['status']), 0, 1);

$pdf->SetTextColor(55, 71, 79);
$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Approved By:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $clearance['approved_by'] ?: 'N/A', 0, 1);

if (!empty($clearance['remarks'])) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(25, 25, 112);
    $pdf->Cell(0, 8, 'REMARKS:', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(55, 71, 79);
    $pdf->MultiCell(0, 6, $clearance['remarks'], 0, 1);
}

$pdf->Ln(15);

// Certification Statement
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(84, 110, 122);
$pdf->MultiCell(0, 5, 'This certifies that the above-named student has complied with the school clinic requirements and is cleared for the stated purpose, subject to any restrictions noted above.', 0, 1, 'C');
$pdf->Ln(10);

// Signature Lines
$pdf->SetFont('Arial', '', 10);

// Left side - Student Signature
$pdf->Cell(90, 5, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 5, '_________________________', 0, 1, 'C');

$pdf->Cell(90, 5, 'Student Signature', 0, 0, 'C');
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1, 'C');

$pdf->Ln(5);

// Right side - Clinic Stamp Area
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(90, 5, '', 0, 0);
$pdf->Cell(90, 5, '_________________________', 0, 1, 'C');
$pdf->Cell(90, 5, '', 0, 0);
$pdf->Cell(90, 5, $clearance['approved_by'] ?: 'Clinic Head', 0, 1, 'C');
$pdf->Cell(90, 5, '', 0, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 5, 'Printed Name & License No.', 0, 1, 'C');

$pdf->Ln(10);

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

$pdf->Output('I', 'Clearance_' . $clearance['clearance_code'] . '.pdf');
?>