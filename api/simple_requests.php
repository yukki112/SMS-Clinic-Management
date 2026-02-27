<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if ($action == 'get_requests') {
        $query = "SELECT * FROM medicine_requests WHERE status IN ('pending', 'approved') ORDER BY requested_date DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $requests]);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($action == 'approve') {
        $id = $input['id'] ?? 0;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        $query = "UPDATE medicine_requests SET status = 'approved', approved_date = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Approved']);
    }
    else if ($action == 'release') {
        $id = $input['id'] ?? 0;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        // Get request details
        $query = "SELECT * FROM medicine_requests WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            echo json_encode(['success' => false, 'error' => 'Request not found']);
            exit;
        }
        
        $db->beginTransaction();
        
        // Update request status
        $query = "UPDATE medicine_requests SET status = 'released', released_date = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        
        // Add to clinic stock
        $query = "INSERT INTO clinic_stock (item_code, item_name, category, quantity, unit, date_received, received_from, request_id) 
                  VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $request['item_code'],
            $request['item_name'],
            $request['category'],
            $request['quantity_requested'],
            $request['unit'] ?? 'piece',
            'Property Custodian',
            $id
        ]);
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Released']);
    }
}
?>