<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';

class ClinicRequestsAPI {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
            exit();
        }
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
        
        // Log for debugging
        error_log("Clinic Requests API called - Method: $method, Endpoint: $endpoint");
        
        switch ($method) {
            case 'GET':
                $this->handleGet($endpoint);
                break;
            case 'POST':
                $this->handlePost($endpoint);
                break;
            default:
                $this->sendResponse(405, ['success' => false, 'error' => 'Method not allowed']);
                break;
        }
    }
    
    private function handleGet($endpoint) {
        switch ($endpoint) {
            case 'pending':
                $this->getPendingRequests();
                break;
            case 'request':
                $this->getRequestById();
                break;
            default:
                $this->sendResponse(404, ['success' => false, 'error' => 'Endpoint not found']);
                break;
        }
    }
    
    private function handlePost($endpoint) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->sendResponse(400, ['success' => false, 'error' => 'Invalid JSON data']);
            return;
        }
        
        error_log("POST data received: " . print_r($input, true));
        
        switch ($endpoint) {
            case 'approve':
                $this->approveRequest($input);
                break;
            case 'reject':
                $this->rejectRequest($input);
                break;
            case 'release':
                $this->releaseRequest($input);
                break;
            default:
                $this->sendResponse(404, ['success' => false, 'error' => 'Endpoint not found']);
                break;
        }
    }
    
    private function getPendingRequests() {
        try {
            $query = "SELECT * FROM medicine_requests WHERE status IN ('pending', 'approved') ORDER BY 
                      CASE WHEN urgency = 'urgent' THEN 0 ELSE 1 END, requested_date DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendResponse(200, [
                'success' => true,
                'count' => count($requests),
                'data' => $requests
            ]);
        } catch (PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function getRequestById() {
        try {
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            
            if (!$id) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Request ID is required']);
                return;
            }
            
            $query = "SELECT * FROM medicine_requests WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                $this->sendResponse(200, ['success' => true, 'data' => $request]);
            } else {
                $this->sendResponse(404, ['success' => false, 'error' => 'Request not found']);
            }
        } catch (PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function approveRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            
            if (!$id) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Request not found']);
                return;
            }
            
            $request = $check->fetch(PDO::FETCH_ASSOC);
            
            $approved_by = isset($data['approved_by']) ? $data['approved_by'] : 'Property Custodian';
            $quantity_approved = isset($data['quantity_approved']) ? intval($data['quantity_approved']) : $request['quantity_requested'];
            $notes = isset($data['notes']) ? $data['notes'] : '';
            
            // Update request
            $query = "UPDATE medicine_requests 
                      SET status = 'approved',
                          quantity_approved = :quantity_approved,
                          approved_by = :approved_by,
                          approved_date = NOW(),
                          notes = CONCAT(IFNULL(notes, ''), ' | Approved: ', :notes)
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':quantity_approved', $quantity_approved);
            $stmt->bindParam(':approved_by', $approved_by);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Request approved successfully',
                    'data' => [
                        'request_id' => $id,
                        'status' => 'approved',
                        'quantity_approved' => $quantity_approved
                    ]
                ]);
            } else {
                $this->sendResponse(500, ['success' => false, 'error' => 'Failed to update request']);
            }
        } catch (PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function rejectRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            
            if (!$id) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Request ID is required']);
                return;
            }
            
            $rejected_by = isset($data['rejected_by']) ? $data['rejected_by'] : 'Property Custodian';
            $rejection_reason = isset($data['rejection_reason']) ? $data['rejection_reason'] : '';
            $notes = isset($data['notes']) ? $data['notes'] : '';
            
            if (empty($rejection_reason)) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Rejection reason is required']);
                return;
            }
            
            // Update request
            $query = "UPDATE medicine_requests 
                      SET status = 'rejected',
                          approved_by = :rejected_by,
                          approved_date = NOW(),
                          notes = CONCAT(IFNULL(notes, ''), ' | Rejected: ', :rejection_reason)
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':rejected_by', $rejected_by);
            $stmt->bindParam(':rejection_reason', $rejection_reason);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Request rejected'
                ]);
            } else {
                $this->sendResponse(500, ['success' => false, 'error' => 'Failed to reject request']);
            }
        } catch (PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function releaseRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            
            if (!$id) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is approved
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'approved'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Request not found or not approved']);
                return;
            }
            
            $request = $check->fetch(PDO::FETCH_ASSOC);
            $released_by = isset($data['released_by']) ? $data['released_by'] : 'Property Custodian';
            
            // Start transaction
            $this->conn->beginTransaction();
            
            // Update request status to released
            $query = "UPDATE medicine_requests 
                      SET status = 'released',
                          released_by = :released_by,
                          released_date = NOW()
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':released_by', $released_by);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            // Add to clinic stock
            $stock_query = "INSERT INTO clinic_stock 
                           (item_code, item_name, category, quantity, unit, date_received, received_from, request_id)
                           VALUES 
                           (:item_code, :item_name, :category, :quantity, :unit, CURDATE(), :received_from, :request_id)";
            
            $stock_stmt = $this->conn->prepare($stock_query);
            $unit = $request['unit'] ?? ($request['category'] == 'Medicine' ? 'tablet' : 'piece');
            $stock_stmt->bindParam(':item_code', $request['item_code']);
            $stock_stmt->bindParam(':item_name', $request['item_name']);
            $stock_stmt->bindParam(':category', $request['category']);
            $stock_stmt->bindParam(':quantity', $request['quantity_approved']);
            $stock_stmt->bindParam(':unit', $unit);
            $stock_stmt->bindParam(':received_from', $released_by);
            $stock_stmt->bindParam(':request_id', $id);
            $stock_stmt->execute();
            
            $this->conn->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Items released and added to clinic stock'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to release items: ' . $e->getMessage()]);
        }
    }
    
    private function sendResponse($status_code, $data) {
        http_response_code($status_code);
        echo json_encode($data);
        exit();
    }
}

// Initialize and handle the request
$api = new ClinicRequestsAPI();
$api->handleRequest();
?>