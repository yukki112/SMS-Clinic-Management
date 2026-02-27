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
        // Get all pending and approved requests
        $query = "SELECT * FROM medicine_requests WHERE status IN ('pending', 'approved') ORDER BY requested_date DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $requests]);
    }
} 
else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';
    
    if ($action == 'approve') {
        $id = $input['id'] ?? 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        // Update status to approved
        $query = "UPDATE medicine_requests SET status = 'approved', approved_date = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $result = $stmt->execute([$id]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Request approved']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to approve']);
        }
    }
    else if ($action == 'release') {
        $id = $input['id'] ?? 0;
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Get the request details
            $query = "SELECT * FROM medicine_requests WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                throw new Exception('Request not found');
            }
            
            // Update request status to released
            $query = "UPDATE medicine_requests SET status = 'released', released_date = NOW() WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$id]);
            
            // Check if item already exists in clinic_stock from this request
            $check = "SELECT id FROM clinic_stock WHERE request_id = ?";
            $stmt = $db->prepare($check);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() == 0) {
                // Add to clinic stock
                $insert = "INSERT INTO clinic_stock 
                          (item_code, item_name, category, quantity, unit, date_received, received_from, request_id) 
                          VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?)";
                
                $stmt = $db->prepare($insert);
                $stmt->execute([
                    $request['item_code'],
                    $request['item_name'],
                    $request['category'],
                    $request['quantity_requested'], // Use the quantity
                    $request['unit'] ?? ($request['category'] == 'Medicine' ? 'tablet' : 'piece'),
                    'Property Custodian',
                    $id
                ]);
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Items released and added to stock']);
            
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
?>