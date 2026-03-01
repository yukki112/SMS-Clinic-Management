<?php
require_once 'config/database.php';

class StudentSync {
    private $conn;
    private $api_url = "https://ttm.qcprotektado.com/api/students.php";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function syncStudents() {
        // Fetch students from API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $data = json_decode($response, true);
            
            if (isset($data['records']) && is_array($data['records'])) {
                $synced = 0;
                $updated = 0;
                
                foreach ($data['records'] as $student_data) {
                    // Check if student exists in our database
                    $query = "SELECT id FROM students WHERE student_id = :student_id";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':student_id', $student_data['student_id']);
                    $stmt->execute();
                    
                    if ($stmt->rowCount() > 0) {
                        // Update existing student
                        $query = "UPDATE students SET 
                                  full_name = :full_name,
                                  section = :section,
                                  year_level = :year_level,
                                  semester = :semester,
                                  email = :email,
                                  contact_no = :contact_no,
                                  allergies = :allergies,
                                  medical_conditions = :medical_conditions,
                                  blood_type = :blood_type,
                                  emergency_contact = :emergency_contact,
                                  emergency_phone = :emergency_phone,
                                  emergency_email = :emergency_email,
                                  last_sync = NOW()
                                  WHERE student_id = :student_id";
                        $updated++;
                    } else {
                        // Insert new student
                        $query = "INSERT INTO students 
                                  (student_id, full_name, section, year_level, semester, email, contact_no, 
                                   allergies, medical_conditions, blood_type, emergency_contact, emergency_phone, 
                                   emergency_email, last_sync) 
                                  VALUES 
                                  (:student_id, :full_name, :section, :year_level, :semester, :email, :contact_no,
                                   :allergies, :medical_conditions, :blood_type, :emergency_contact, :emergency_phone,
                                   :emergency_email, NOW())";
                        $synced++;
                    }
                    
                    $stmt = $this->conn->prepare($query);
                    
                    // Bind parameters
                    $stmt->bindParam(':student_id', $student_data['student_id']);
                    $stmt->bindParam(':full_name', $student_data['full_name']);
                    $stmt->bindParam(':section', $student_data['section']);
                    $stmt->bindParam(':year_level', $student_data['year_level']);
                    $stmt->bindParam(':semester', $student_data['semester']);
                    $stmt->bindParam(':email', $student_data['email']);
                    $stmt->bindParam(':contact_no', $student_data['contact_no']);
                    $stmt->bindParam(':allergies', $student_data['allergies']);
                    $stmt->bindParam(':medical_conditions', $student_data['medical_conditions']);
                    $stmt->bindParam(':blood_type', $student_data['blood_type']);
                    $stmt->bindParam(':emergency_contact', $student_data['emergency_contact']);
                    $stmt->bindParam(':emergency_phone', $student_data['emergency_phone']);
                    $stmt->bindParam(':emergency_email', $student_data['emergency_email']);
                    
                    $stmt->execute();
                    
                    // Create or update user account for student
                    $this->createOrUpdateStudentAccount($student_data['student_id'], $student_data['full_name'], $student_data['email']);
                }
                
                return [
                    'success' => true,
                    'synced' => $synced,
                    'updated' => $updated,
                    'total' => count($data['records'])
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Failed to fetch students from API'
        ];
    }
    
    private function createOrUpdateStudentAccount($student_id, $full_name, $email) {
        // Check if user account exists
        $query = "SELECT id FROM users WHERE student_id = :student_id OR username = :username";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':username', $student_id);
        $stmt->execute();
        
        $default_password = password_hash('0000', PASSWORD_DEFAULT);
        
        if ($stmt->rowCount() == 0) {
            // Create new user account
            $query = "INSERT INTO users (student_id, username, email, password, full_name, role) 
                      VALUES (:student_id, :username, :email, :password, :full_name, 'student')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':username', $student_id);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $default_password);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->execute();
            
            $user_id = $this->conn->lastInsertId();
            
            // Record in student_accounts
            $query = "INSERT INTO student_accounts (student_id, user_id, default_password) 
                      VALUES (:student_id, :user_id, :default_password)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':default_password', $default_password);
            $stmt->execute();
        }
    }
}

// Run sync if accessed directly
if (basename($_SERVER['PHP_SELF']) == 'sync_students.php') {
    $database = new Database();
    $db = $database->getConnection();
    
    $sync = new StudentSync($db);
    $result = $sync->syncStudents();
    
    header('Content-Type: application/json');
    echo json_encode($result);
}
?>