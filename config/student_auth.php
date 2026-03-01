<?php
require_once 'database.php';
require_once 'student_api.php';

class StudentAuth {
    private $db;
    private $api;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->api = new StudentAPI();
        
        // Ensure students table exists with correct structure
        $this->createStudentsTable();
    }
    
    private function createStudentsTable() {
        $query = "CREATE TABLE IF NOT EXISTS `students` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `student_id` varchar(20) NOT NULL,
            `full_name` varchar(100) NOT NULL,
            `section` varchar(50) DEFAULT NULL,
            `year_level` varchar(20) DEFAULT NULL,
            `semester` varchar(20) DEFAULT NULL,
            `email` varchar(100) DEFAULT NULL,
            `contact_no` varchar(20) DEFAULT NULL,
            `allergies` text DEFAULT NULL,
            `medical_conditions` text DEFAULT NULL,
            `blood_type` varchar(5) DEFAULT NULL,
            `emergency_contact` varchar(100) DEFAULT NULL,
            `emergency_phone` varchar(20) DEFAULT NULL,
            `emergency_email` varchar(100) DEFAULT NULL,
            `last_sync` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `student_id` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        try {
            $this->db->exec($query);
        } catch (PDOException $e) {
            error_log("Error creating students table: " . $e->getMessage());
        }
    }
    
    /**
     * Quick login using student_id
     */
    public function quickLogin($student_id, $password) {
        // First check if student exists in users table with role 'student'
        $query = "SELECT u.*, s.*, u.id as user_id 
                  FROM users u 
                  LEFT JOIN students s ON u.student_id = s.student_id 
                  WHERE u.student_id = :student_id AND u.role = 'student'";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (password_verify($password, $user_data['password'])) {
                
                // Prepare student data for session
                $student_data = [
                    'id' => $user_data['id'] ?? null,
                    'student_id' => $user_data['student_id'],
                    'full_name' => $user_data['full_name'],
                    'section' => $user_data['section'] ?? null,
                    'year_level' => $user_data['year_level'] ?? null,
                    'semester' => $user_data['semester'] ?? null,
                    'email' => $user_data['email'] ?? null,
                    'contact_no' => $user_data['contact_no'] ?? null,
                    'allergies' => $user_data['allergies'] ?? null,
                    'medical_conditions' => $user_data['medical_conditions'] ?? null,
                    'blood_type' => $user_data['blood_type'] ?? null,
                    'emergency_contact' => $user_data['emergency_contact'] ?? null,
                    'emergency_phone' => $user_data['emergency_phone'] ?? null,
                    'emergency_email' => $user_data['emergency_email'] ?? null
                ];
                
                // Update last login
                $update_query = "UPDATE users SET last_login = NOW() WHERE id = :user_id";
                $update_stmt = $this->db->prepare($update_query);
                $update_stmt->bindParam(':user_id', $user_data['user_id']);
                $update_stmt->execute();
                
                return [
                    'success' => true,
                    'user' => [
                        'id' => $user_data['user_id'],
                        'student_id' => $user_data['student_id'],
                        'full_name' => $user_data['full_name'],
                        'email' => $user_data['email'],
                        'role' => 'student'
                    ],
                    'student' => $student_data
                ];
            } else {
                // Check if it's the default password
                if ($password === '0000') {
                    // Update to hashed password
                    $hashed = password_hash('0000', PASSWORD_DEFAULT);
                    $update_query = "UPDATE users SET password = :password WHERE student_id = :student_id";
                    $update_stmt = $this->db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed);
                    $update_stmt->bindParam(':student_id', $student_id);
                    $update_stmt->execute();
                    
                    // Try login again
                    return $this->quickLogin($student_id, $password);
                }
                
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }
        } else {
            // Student not found in users table - check if they exist in API
            $student = $this->api->getStudentById($student_id);
            
            if ($student) {
                // Create user account for this student
                $created = $this->createStudentUser($student);
                
                if ($created) {
                    // Try login again
                    return $this->quickLogin($student_id, $password);
                } else {
                    return [
                        'success' => false,
                        'message' => 'Error creating student account'
                    ];
                }
            }
            
            return [
                'success' => false,
                'message' => 'Student not found. Please check your Student ID.'
            ];
        }
    }
    
    /**
     * Create a user account for a student
     */
    private function createStudentUser($student_data) {
        try {
            // Start transaction
            $this->db->beginTransaction();
            
            // Check if student already exists in students table
            $check_query = "SELECT id FROM students WHERE student_id = :student_id";
            $check_stmt = $this->db->prepare($check_query);
            $check_stmt->bindParam(':student_id', $student_data['student_id']);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                // Insert into students table
                $student_query = "INSERT INTO students (
                    student_id, full_name, section, year_level, semester, 
                    email, contact_no, allergies, medical_conditions, blood_type,
                    emergency_contact, emergency_phone, emergency_email, last_sync
                ) VALUES (
                    :student_id, :full_name, :section, :year_level, :semester,
                    :email, :contact_no, :allergies, :medical_conditions, :blood_type,
                    :emergency_contact, :emergency_phone, :emergency_email, NOW()
                )";
                
                $student_stmt = $this->db->prepare($student_query);
                $student_stmt->bindParam(':student_id', $student_data['student_id']);
                $student_stmt->bindParam(':full_name', $student_data['full_name']);
                $student_stmt->bindParam(':section', $student_data['section']);
                $student_stmt->bindParam(':year_level', $student_data['year_level']);
                $student_stmt->bindParam(':semester', $student_data['semester']);
                $student_stmt->bindParam(':email', $student_data['email']);
                $student_stmt->bindParam(':contact_no', $student_data['contact_no']);
                $student_stmt->bindParam(':allergies', $student_data['allergies']);
                $student_stmt->bindParam(':medical_conditions', $student_data['medical_conditions']);
                $student_stmt->bindParam(':blood_type', $student_data['blood_type']);
                $student_stmt->bindParam(':emergency_contact', $student_data['emergency_contact']);
                $student_stmt->bindParam(':emergency_phone', $student_data['emergency_phone']);
                $student_stmt->bindParam(':emergency_email', $student_data['emergency_email']);
                $student_stmt->execute();
            }
            
            // Check if user already exists
            $check_user = "SELECT id FROM users WHERE student_id = :student_id OR username = :username";
            $check_user_stmt = $this->db->prepare($check_user);
            $check_user_stmt->bindParam(':student_id', $student_data['student_id']);
            $check_user_stmt->bindParam(':username', $student_data['student_id']);
            $check_user_stmt->execute();
            
            if ($check_user_stmt->rowCount() == 0) {
                // Create user account
                $default_password = password_hash('0000', PASSWORD_DEFAULT);
                $username = $student_data['student_id'];
                $email = $student_data['email'] ?? $student_data['student_id'] . '@student.bcps4core.com';
                
                $user_query = "INSERT INTO users (
                    student_id, username, email, password, full_name, role, status
                ) VALUES (
                    :student_id, :username, :email, :password, :full_name, 'student', 'active'
                )";
                
                $user_stmt = $this->db->prepare($user_query);
                $user_stmt->bindParam(':student_id', $student_data['student_id']);
                $user_stmt->bindParam(':username', $username);
                $user_stmt->bindParam(':email', $email);
                $user_stmt->bindParam(':password', $default_password);
                $user_stmt->bindParam(':full_name', $student_data['full_name']);
                $user_stmt->execute();
            }
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Error creating student user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Original login method (kept for backward compatibility)
     */
    public function login($student_id, $password) {
        return $this->quickLogin($student_id, $password);
    }
    
    /**
     * Sync all students from API (for admin use)
     */
    public function syncAllStudents() {
        $students = $this->api->getAllStudents();
        $synced = 0;
        $errors = 0;
        
        foreach ($students as $student) {
            $result = $this->createStudentUser($student);
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }
        
        return [
            'success' => true,
            'synced' => $synced,
            'errors' => $errors,
            'total' => count($students)
        ];
    }
    
    /**
     * Change student password
     */
    public function changePassword($user_id, $old_password, $new_password) {
        // Verify old password
        $query = "SELECT password FROM users WHERE id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($old_password, $user['password'])) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                
                $update_query = "UPDATE users SET password = :password WHERE id = :user_id";
                $update_stmt = $this->db->prepare($update_query);
                $update_stmt->bindParam(':password', $hashed);
                $update_stmt->bindParam(':user_id', $user_id);
                
                if ($update_stmt->execute()) {
                    return [
                        'success' => true,
                        'message' => 'Password changed successfully'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'User not found'
        ];
    }
    
    /**
     * Get student data by user ID
     */
    public function getStudentByUserId($user_id) {
        $query = "SELECT s.* FROM students s 
                  JOIN users u ON s.student_id = u.student_id 
                  WHERE u.id = :user_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return null;
    }
    
    /**
     * Update student profile
     */
    public function updateStudentProfile($student_id, $data) {
        $query = "UPDATE students SET 
                  contact_no = :contact_no,
                  email = :email,
                  emergency_contact = :emergency_contact,
                  emergency_phone = :emergency_phone,
                  emergency_email = :emergency_email,
                  last_sync = NOW()
                  WHERE student_id = :student_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':contact_no', $data['contact_no']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':emergency_contact', $data['emergency_contact']);
        $stmt->bindParam(':emergency_phone', $data['emergency_phone']);
        $stmt->bindParam(':emergency_email', $data['emergency_email']);
        $stmt->bindParam(':student_id', $student_id);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Profile updated successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to update profile'
        ];
    }
}
?>