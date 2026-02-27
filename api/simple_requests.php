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

// Custodian API to get item details
$CUSTODIAN_API = 'https://qcprotektado.com/api/clinic_requests_handler.php';

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
            
            // Get expiry date from custodian's database
            $expiry_date = null;
            
            // Map item code to custodian's item ID
            $item_code = $request['item_code'];
            
            // Try to fetch from custodian API
            $ch = curl_init();
            if ($request['category'] == 'Medicine') {
                // For medicines, we need to find by medicine_code
                $curl_url = $CUSTODIAN_API . '?action=get_medicines';
            } else {
                // For supplies
                $curl_url = $CUSTODIAN_API . '?action=get_supplies';
            }
            
            curl_setopt($ch, CURLOPT_URL, $curl_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $response) {
                $data = json_decode($response, true);
                if ($data['success'] && isset($data['data'])) {
                    foreach ($data['data'] as $item) {
                        $custodian_code = $request['category'] == 'Medicine' ? 
                            ($item['medicine_code'] ?? '') : 
                            ($item['supply_code'] ?? '');
                        
                        // Match by code or name
                        if ($custodian_code == $item_code || 
                            stripos($item['supply_name'] ?? $item['generic_name'] ?? '', $request['item_name']) !== false) {
                            $expiry_date = $item['expiry_date'] ?? null;
                            break;
                        }
                    }
                }
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
                // Add to clinic stock with expiry date if available
                $insert = "INSERT INTO clinic_stock 
                          (item_code, item_name, category, quantity, unit, expiry_date, date_received, received_from, request_id) 
                          VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)";
                
                $stmt = $db->prepare($insert);
                $stmt->execute([
                    $request['item_code'],
                    $request['item_name'],
                    $request['category'],
                    $request['quantity_requested'],
                    $request['unit'] ?? ($request['category'] == 'Medicine' ? 'tablet' : 'piece'),
                    $expiry_date, // This will now have the expiry date from custodian
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