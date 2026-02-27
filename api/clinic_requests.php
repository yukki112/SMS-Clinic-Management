<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

class ClinicRequestsAPI {
    private $db;
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // Main handler
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
        
        switch ($method) {
            case 'GET':
                $this->handleGet($endpoint);
                break;
            case 'PUT':
                $this->handlePut($endpoint);
                break;
            case 'DELETE':
                $this->handleDelete($endpoint);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed']);
                break;
        }
    }
    
    // Handle GET requests
    private function handleGet($endpoint) {
        switch ($endpoint) {
            case 'pending':
                $this->getPendingRequests();
                break;
            case 'all':
                $this->getAllRequests();
                break;
            case 'stats':
                $this->getRequestStats();
                break;
            case 'request':
                $this->getRequestById();
                break;
            default:
                $this->getPendingRequests(); // Default to pending
                break;
        }
    }
    
    // Handle PUT requests (approve/reject/release)
    private function handlePut($endpoint) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            $this->sendResponse(400, ['error' => 'Invalid JSON data']);
            return;
        }
        
        switch ($endpoint) {
            case 'approve':
                $this->approveRequest($data);
                break;
            case 'reject':
                $this->rejectRequest($data);
                break;
            case 'release':
                $this->releaseRequest($data);
                break;
            case 'receive':
                $this->receiveRequest($data);
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
                break;
        }
    }
    
    // Handle DELETE requests (cancel)
    private function handleDelete($endpoint) {
        if ($endpoint == 'cancel') {
            $data = json_decode(file_get_contents('php://input'), true);
            $this->cancelRequest($data);
        } else {
            $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    // Get all pending requests
    private function getPendingRequests() {
        try {
            $query = "SELECT 
                        mr.*,
                        u.full_name as requested_by_fullname,
                        u.email as requested_by_email
                      FROM medicine_requests mr
                      LEFT JOIN users u ON mr.requested_by = u.id
                      WHERE mr.status IN ('pending', 'approved')
                      ORDER BY 
                        CASE WHEN mr.urgency = 'urgent' THEN 0 ELSE 1 END,
                        mr.requested_date ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data for property custodian
            foreach ($requests as &$request) {
                $request['request_id'] = $request['id'];
                $request['date_requested'] = date('Y-m-d H:i:s', strtotime($request['requested_date']));
                $request['requested_by'] = $request['requested_by_fullname'] ?? 'Unknown';
                $request['item_details'] = [
                    'code' => $request['item_code'],
                    'name' => $request['item_name'],
                    'category' => $request['category'],
                    'quantity' => $request['quantity_requested']
                ];
                
                // Remove redundant fields
                unset($request['id']);
                unset($request['requested_by_fullname']);
                unset($request['requested_by_email']);
            }
            
            $this->sendResponse(200, [
                'success' => true,
                'count' => count($requests),
                'data' => $requests,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (PDOException $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    // Get all requests with filters
    private function getAllRequests() {
        try {
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
            $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
            
            $query = "SELECT 
                        mr.*,
                        u.full_name as requested_by_fullname,
                        u.email as requested_by_email
                      FROM medicine_requests mr
                      LEFT JOIN users u ON mr.requested_by = u.id
                      WHERE 1=1";
            
            $params = [];
            
            if (!empty($status)) {
                $query .= " AND mr.status = :status";
                $params[':status'] = $status;
            }
            
            if (!empty($from_date)) {
                $query .= " AND DATE(mr.requested_date) >= :from_date";
                $params[':from_date'] = $from_date;
            }
            
            if (!empty($to_date)) {
                $query .= " AND DATE(mr.requested_date) <= :to_date";
                $params[':to_date'] = $to_date;
            }
            
            $query .= " ORDER BY mr.requested_date DESC LIMIT 100";
            
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendResponse(200, [
                'success' => true,
                'count' => count($requests),
                'data' => $requests,
                'filters' => [
                    'status' => $status,
                    'from_date' => $from_date,
                    'to_date' => $to_date
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (PDOException $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    // Get single request by ID
    private function getRequestById() {
        try {
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            $query = "SELECT 
                        mr.*,
                        u.full_name as requested_by_fullname,
                        u.email as requested_by_email,
                        u2.full_name as approved_by_fullname,
                        u3.full_name as received_by_fullname
                      FROM medicine_requests mr
                      LEFT JOIN users u ON mr.requested_by = u.id
                      LEFT JOIN users u2 ON mr.approved_by = u2.id
                      LEFT JOIN users u3 ON mr.received_by = u3.id
                      WHERE mr.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                $this->sendResponse(200, [
                    'success' => true,
                    'data' => $request,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->sendResponse(404, [
                    'success' => false,
                    'error' => 'Request not found'
                ]);
            }
            
        } catch (PDOException $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    // Get request statistics
    private function getRequestStats() {
        try {
            $stats = [];
            
            // Count by status
            $query = "SELECT 
                        status,
                        COUNT(*) as count,
                        SUM(CASE WHEN urgency = 'urgent' THEN 1 ELSE 0 END) as urgent_count
                      FROM medicine_requests
                      GROUP BY status";
            $stmt = $this->conn->query($query);
            $status_counts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status_counts[$row['status']] = [
                    'total' => $row['count'],
                    'urgent' => $row['urgent_count']
                ];
            }
            $stats['by_status'] = $status_counts;
            
            // Monthly requests
            $query = "SELECT 
                        DATE_FORMAT(requested_date, '%Y-%m') as month,
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as released
                      FROM medicine_requests
                      WHERE requested_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                      GROUP BY DATE_FORMAT(requested_date, '%Y-%m')
                      ORDER BY month DESC";
            $stmt = $this->conn->query($query);
            $stats['monthly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Most requested items
            $query = "SELECT 
                        item_name,
                        COUNT(*) as request_count,
                        SUM(quantity_requested) as total_quantity
                      FROM medicine_requests
                      GROUP BY item_name
                      ORDER BY request_count DESC
                      LIMIT 10";
            $stmt = $this->conn->query($query);
            $stats['top_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Average processing time
            $query = "SELECT 
                        AVG(TIMESTAMPDIFF(HOUR, requested_date, approved_date)) as avg_approval_hours,
                        AVG(TIMESTAMPDIFF(HOUR, approved_date, released_date)) as avg_release_hours
                      FROM medicine_requests
                      WHERE approved_date IS NOT NULL";
            $stmt = $this->conn->query($query);
            $stats['processing_times'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (PDOException $e) {
            $this->sendResponse(500, [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }
    
    // Approve a request
    private function approveRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            $approved_by = isset($data['approved_by']) ? $data['approved_by'] : 'Property Custodian';
            $quantity_approved = isset($data['quantity_approved']) ? intval($data['quantity_approved']) : null;
            $notes = isset($data['notes']) ? $data['notes'] : '';
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is pending
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'pending'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['error' => 'Request not found or already processed']);
                return;
            }
            
            $request = $check->fetch(PDO::FETCH_ASSOC);
            
            // If quantity_approved not set, use requested quantity
            if (!$quantity_approved) {
                $quantity_approved = $request['quantity_requested'];
            }
            
            // Update request
            $query = "UPDATE medicine_requests 
                      SET status = 'approved',
                          quantity_approved = :quantity_approved,
                          approved_by = :approved_by,
                          approved_date = NOW(),
                          notes = CONCAT(IFNULL(notes, ''), ' | Approved notes: ', :notes)
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':quantity_approved', $quantity_approved);
            $stmt->bindParam(':approved_by', $approved_by);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                // Log the action
                $this->logAction('approve', $id, $approved_by, $quantity_approved);
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Request approved successfully',
                    'data' => [
                        'request_id' => $id,
                        'status' => 'approved',
                        'quantity_approved' => $quantity_approved,
                        'approved_by' => $approved_by,
                        'approved_date' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                $this->sendResponse(500, ['error' => 'Failed to approve request']);
            }
            
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    // Reject a request
    private function rejectRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            $rejected_by = isset($data['rejected_by']) ? $data['rejected_by'] : 'Property Custodian';
            $rejection_reason = isset($data['rejection_reason']) ? $data['rejection_reason'] : 'No reason provided';
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is pending
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'pending'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['error' => 'Request not found or already processed']);
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
                // Log the action
                $this->logAction('reject', $id, $rejected_by, 0, $rejection_reason);
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Request rejected',
                    'data' => [
                        'request_id' => $id,
                        'status' => 'rejected',
                        'rejection_reason' => $rejection_reason,
                        'rejected_by' => $rejected_by,
                        'rejected_date' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                $this->sendResponse(500, ['error' => 'Failed to reject request']);
            }
            
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    // Mark as released (custodian has given the items)
    private function releaseRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            $released_by = isset($data['released_by']) ? $data['released_by'] : 'Property Custodian';
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is approved
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'approved'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['error' => 'Request not found or not approved']);
                return;
            }
            
            $request = $check->fetch(PDO::FETCH_ASSOC);
            
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
                           (item_code, item_name, category, quantity, unit, date_received, minimum_stock, received_from, request_id)
                           VALUES 
                           (:item_code, :item_name, :category, :quantity, :unit, CURDATE(), 10, :received_from, :request_id)";
            
            $stock_stmt = $this->conn->prepare($stock_query);
            $stock_stmt->bindParam(':item_code', $request['item_code']);
            $stock_stmt->bindParam(':item_name', $request['item_name']);
            $stock_stmt->bindParam(':category', $request['category']);
            $stock_stmt->bindParam(':quantity', $request['quantity_approved']);
            $stock_stmt->bindParam(':unit', $request['unit']);
            $stock_stmt->bindParam(':received_from', $released_by);
            $stock_stmt->bindParam(':request_id', $id);
            $stock_stmt->execute();
            
            $this->conn->commit();
            
            // Log the action
            $this->logAction('release', $id, $released_by, $request['quantity_approved']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Items released and added to clinic stock',
                'data' => [
                    'request_id' => $id,
                    'status' => 'released',
                    'items_added' => [
                        'item_name' => $request['item_name'],
                        'quantity' => $request['quantity_approved'],
                        'unit' => $request['unit']
                    ],
                    'released_by' => $released_by,
                    'released_date' => date('Y-m-d H:i:s')
                ]
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to release items: ' . $e->getMessage()]);
        }
    }
    
    // Clinic receives the items (confirmation)
    private function receiveRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            $received_by = isset($data['received_by']) ? $data['received_by'] : 'Clinic Staff';
            $received_date = isset($data['received_date']) ? $data['received_date'] : date('Y-m-d H:i:s');
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is released
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'released'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['error' => 'Request not found or not released']);
                return;
            }
            
            // Update request status to received
            $query = "UPDATE medicine_requests 
                      SET status = 'received',
                          received_by = :received_by,
                          received_date = :received_date
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':received_by', $received_by);
            $stmt->bindParam(':received_date', $received_date);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                // Log the action
                $this->logAction('receive', $id, $received_by);
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Items received by clinic',
                    'data' => [
                        'request_id' => $id,
                        'status' => 'received',
                        'received_by' => $received_by,
                        'received_date' => $received_date
                    ]
                ]);
            } else {
                $this->sendResponse(500, ['error' => 'Failed to confirm receipt']);
            }
            
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    // Cancel a request (clinic cancels before approval)
    private function cancelRequest($data) {
        try {
            $id = isset($data['request_id']) ? intval($data['request_id']) : 0;
            $cancelled_by = isset($data['cancelled_by']) ? $data['cancelled_by'] : 'Clinic Staff';
            $cancel_reason = isset($data['cancel_reason']) ? $data['cancel_reason'] : 'Cancelled by requestor';
            
            if (!$id) {
                $this->sendResponse(400, ['error' => 'Request ID is required']);
                return;
            }
            
            // Check if request exists and is pending
            $check = $this->conn->prepare("SELECT * FROM medicine_requests WHERE id = :id AND status = 'pending'");
            $check->bindParam(':id', $id);
            $check->execute();
            
            if ($check->rowCount() == 0) {
                $this->sendResponse(404, ['error' => 'Request not found or cannot be cancelled']);
                return;
            }
            
            // Update request
            $query = "UPDATE medicine_requests 
                      SET status = 'cancelled',
                          notes = CONCAT(IFNULL(notes, ''), ' | Cancelled: ', :cancel_reason)
                      WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':cancel_reason', $cancel_reason);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                // Log the action
                $this->logAction('cancel', $id, $cancelled_by, 0, $cancel_reason);
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Request cancelled',
                    'data' => [
                        'request_id' => $id,
                        'status' => 'cancelled',
                        'cancel_reason' => $cancel_reason,
                        'cancelled_by' => $cancelled_by
                    ]
                ]);
            } else {
                $this->sendResponse(500, ['error' => 'Failed to cancel request']);
            }
            
        } catch (PDOException $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    // Log actions for audit trail
    private function logAction($action, $request_id, $user, $quantity = null, $notes = null) {
        try {
            // Create audit log table if not exists
            $this->conn->exec("CREATE TABLE IF NOT EXISTS `request_audit_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `request_id` int(11) NOT NULL,
                `action` varchar(50) NOT NULL,
                `user` varchar(100) NOT NULL,
                `quantity` int(11) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `request_id` (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            
            $query = "INSERT INTO request_audit_log (request_id, action, user, quantity, notes) 
                      VALUES (:request_id, :action, :user, :quantity, :notes)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':request_id', $request_id);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':user', $user);
            $stmt->bindParam(':quantity', $quantity);
            $stmt->bindParam(':notes', $notes);
            $stmt->execute();
            
        } catch (Exception $e) {
            // Silently fail - logging shouldn't break main functionality
            error_log("Failed to log action: " . $e->getMessage());
        }
    }
    
    // Send JSON response
    private function sendResponse($status_code, $data) {
        http_response_code($status_code);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
}

// Initialize and handle the request
$api = new ClinicRequestsAPI();
$api->handleRequest();
?>