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

// Create PDF
class PDF extends FPDF
{
    function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'STUDENT CLEARANCE FORM', 0, 1, 'C');
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
        $this->Cell(0, 5, 'This clearance form is valid only with clinic stamp and signature.', 0, 1, 'C');
        $this->Cell(0, 5, 'Generated on: ' . date('F d, Y'), 0, 1, 'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// Clearance details
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'CLEARANCE REQUEST', 0, 1);
$pdf->Ln(5);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Clearance No:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $clearance['clearance_code'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $clearance['student_name'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $clearance['grade_section'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Clearance Type:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, $clearance['clearance_type'], 0, 1);

$pdf->SetFont('Arial', '', 12);
$pdf->Cell(45, 10, 'Request Date:', 0, 0);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, date('F d, Y', strtotime($clearance['request_date'])), 0, 1);

$pdf->Ln(10);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Purpose:', 0, 1);
$pdf->SetFont('Arial', '', 12);
$pdf->MultiCell(0, 8, $clearance['purpose'], 0, 1);

$pdf->Ln(10);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Status: ', 0, 0);
$status_color = match($clearance['status']) {
    'Approved' => [30, 123, 92],
    'Pending' => [133, 100, 4],
    'Not Cleared' => [196, 69, 69],
    default => [108, 117, 125]
};
$pdf->SetTextColor($status_color[0], $status_color[1], $status_color[2]);
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, $clearance['status'], 0, 1);
$pdf->SetTextColor(0, 0, 0);

if (!empty($clearance['approved_date'])) {
    $pdf->Ln(5);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(45, 10, 'Approved Date:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, date('F d, Y', strtotime($clearance['approved_date'])), 0, 1);
}

if (!empty($clearance['approved_by'])) {
    $pdf->Cell(45, 10, 'Approved By:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, $clearance['approved_by'], 0, 1);
}

if (!empty($clearance['valid_until'])) {
    $pdf->Cell(45, 10, 'Valid Until:', 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, date('F d, Y', strtotime($clearance['valid_until'])), 0, 1);
}

if (!empty($clearance['remarks'])) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Remarks:', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 8, $clearance['remarks'], 0, 1);
}

$pdf->Ln(20);

$pdf->Cell(100, 10, '_________________________', 0, 0);
$pdf->Cell(90, 10, '_________________________', 0, 1);

$pdf->Cell(100, 5, 'Student Signature', 0, 0);
$pdf->Cell(90, 5, 'Clinic Staff Signature', 0, 1);

$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 10);
$pdf->Cell(0, 5, 'Requested by: ' . ($clearance['created_by_name'] ?? 'Clinic Staff'), 0, 1);

$pdf->Output('I', 'Clearance_' . $clearance['clearance_code'] . '.pdf');