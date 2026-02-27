<?php
require_once '../vendor/autoload.php';
require_once '../config/database.php';

use setasign\Fpdi\Fpdf;

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

// Create PDF
class PDF extends FPDF
{
    function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'MEDICAL CERTIFICATE', 0, 1, 'C');
        $this->Ln(10);
        
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
        $this->Cell(0, 5, 'This is a system-generated certificate. Valid with clinic stamp.', 0, 1, 'C');
        $this->Cell(0, 5, 'Generated on: ' . date('F d, Y'), 0, 1, 'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Certificate details
$pdf->Cell(40, 10, 'Certificate No:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $cert['certificate_code'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(40, 10, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $cert['student_name'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(40, 10, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $cert['grade_section'], 0, 1);

$pdf->Ln(10);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'CERTIFICATION', 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->MultiCell(0, 8, 'This is to certify that the above-named student has been examined and found to be:', 0, 1);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $cert['certificate_type'], 0, 1);

$pdf->Ln(5);

$pdf->SetFont('Arial', '', 12);
$pdf->MultiCell(0, 8, 'Findings: ' . $cert['findings'], 0, 1);

if (!empty($cert['recommendations'])) {
    $pdf->Ln(5);
    $pdf->MultiCell(0, 8, 'Recommendations: ' . $cert['recommendations'], 0, 1);
}

if (!empty($cert['restrictions'])) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Restrictions:', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 8, $cert['restrictions'], 0, 1);
}

if (!empty($cert['valid_until'])) {
    $pdf->Ln(5);
    $pdf->Cell(0, 10, 'Valid until: ' . date('F d, Y', strtotime($cert['valid_until'])), 0, 1);
}

$pdf->Ln(20);

$pdf->Cell(100, 10, '_________________________', 0, 0);
$pdf->Cell(90, 10, '_________________________', 0, 1);

$pdf->Cell(100, 5, 'Issued By: ' . $cert['issued_by'], 0, 0);
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1);

$pdf->Output('I', 'Certificate_' . $cert['certificate_code'] . '.pdf');