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

// Fetch certificate data
$query = "SELECT c.*, u.full_name as clinic_staff 
          FROM medical_certificates c
          LEFT JOIN users u ON c.issuer_id = u.id
          WHERE c.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    die('Certificate not found');
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
        $this->Cell(0, 15, 'MEDICAL CERTIFICATE', 0, 1, 'C');
        
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
        $this->Cell(0, 4, 'This certificate is system-generated and valid only with clinic stamp and authorized signature.', 0, 1, 'C');
        $this->Cell(0, 4, 'Generated on: ' . date('F d, Y') . ' | Certificate No: ' . $this->cert_no, 0, 1, 'C');
    }
    
    function SetCertNo($no) {
        $this->cert_no = $no;
    }
}

$pdf = new PDF();
$pdf->SetCertNo($cert['certificate_code']);
$pdf->AddPage();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 40);

// Certificate Number
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'CERTIFICATE NO: ' . $cert['certificate_code'], 0, 1, 'R');
$pdf->Ln(5);

// Patient Information Box
$pdf->SetFillColor(236, 239, 241); // #eceff1
$pdf->SetDrawColor(25, 25, 112);
$pdf->SetLineWidth(0.3);
$pdf->Rect(15, $pdf->GetY(), 180, 40, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'PATIENT INFORMATION', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(55, 71, 79); // #37474f
$pdf->Cell(45, 7, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(100, 7, $cert['student_name'], 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(20, 7, 'ID:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $cert['student_id'], 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $cert['grade_section'], 0, 1);

$pdf->Ln(10);

// Certificate Details Box
$pdf->SetFillColor(236, 239, 241);
$pdf->SetDrawColor(25, 25, 112);
$pdf->Rect(15, $pdf->GetY(), 180, 35, 'D');
$pdf->SetXY(20, $pdf->GetY() + 2);

$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'CERTIFICATE DETAILS', 0, 1);
$pdf->SetXY(20, $pdf->GetY());

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Certificate Type:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, $cert['certificate_type'], 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Issue Date:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 7, date('F d, Y', strtotime($cert['issued_date'])), 0, 1);

$pdf->SetX(20);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(45, 7, 'Valid Until:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$valid_until = !empty($cert['valid_until']) ? date('F d, Y', strtotime($cert['valid_until'])) : 'Indefinite';
$pdf->Cell(0, 7, $valid_until, 0, 1);

$pdf->Ln(10);

// Medical Findings
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(0, 8, 'MEDICAL FINDINGS', 0, 1);
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(55, 71, 79);
$pdf->MultiCell(0, 7, $cert['findings'], 0, 1);
$pdf->Ln(5);

// Recommendations
if (!empty($cert['recommendations'])) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(25, 25, 112);
    $pdf->Cell(0, 8, 'RECOMMENDATIONS', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(55, 71, 79);
    $pdf->MultiCell(0, 7, $cert['recommendations'], 0, 1);
    $pdf->Ln(5);
}

// Restrictions (if any)
if (!empty($cert['restrictions'])) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(196, 69, 69); // Red for restrictions
    $pdf->Cell(0, 8, 'RESTRICTIONS', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(55, 71, 79);
    $pdf->MultiCell(0, 7, $cert['restrictions'], 0, 1);
    $pdf->Ln(5);
}

// Remarks
if (!empty($cert['remarks'])) {
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(25, 25, 112);
    $pdf->Cell(0, 8, 'REMARKS', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(55, 71, 79);
    $pdf->MultiCell(0, 7, $cert['remarks'], 0, 1);
    $pdf->Ln(5);
}

$pdf->Ln(10);

// Certification Statement
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(84, 110, 122);
$pdf->MultiCell(0, 5, 'This is to certify that the above-named student has been examined by the school clinic and found to be as stated above.', 0, 1, 'C');
$pdf->Ln(10);

// Signature Lines
$pdf->SetFont('Arial', '', 10);

// Left side - Issued By
$pdf->Cell(90, 5, '_________________________', 0, 0, 'C');
$pdf->Cell(90, 5, '_________________________', 0, 1, 'C');

$pdf->Cell(90, 5, 'Issued By: ' . $cert['issued_by'], 0, 0, 'C');
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1, 'C');

$pdf->Ln(5);

// Right side - Clinic Stamp Area
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(25, 25, 112);
$pdf->Cell(90, 5, '', 0, 0);
$pdf->Cell(90, 5, '_________________________', 0, 1, 'C');
$pdf->Cell(90, 5, '', 0, 0);
$pdf->Cell(90, 5, $cert['issued_by'], 0, 1, 'C');
$pdf->Cell(90, 5, '', 0, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 5, 'Clinic Staff / License No.', 0, 1, 'C');

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

$pdf->Output('I', 'Certificate_' . $cert['certificate_code'] . '.pdf');
?>