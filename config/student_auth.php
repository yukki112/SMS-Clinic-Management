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
        
        // Ensure students table exists
        $this->createStudentsTable();
    }
    
    private function createStudentsTable() {
        $query = "CREATE TABLE IF NOT EXISTS `students` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `student_id` varchar(50) NOT NULL,
            `full_name` varchar(255) NOT NULL,
            `section` varchar(100) DEFAULT NULL,
            `year_level` varchar(50) DEFAULT NULL,
            `semester` varchar(50) DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `contact_no` varchar(50) DEFAULT NULL,
            `allergies` text DEFAULT NULL,
            `medical_conditions` text DEFAULT NULL,
            `blood_type` varchar(10) DEFAULT NULL,
            `emergency_contact` varchar(255) DEFAULT NULL,
            `emergency_phone` varchar(50) DEFAULT NULL,
            `emergency_email` varchar(255) DEFAULT NULL,
            `password` varchar(255) NOT NULL DEFAULT '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
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
     * Quick login without full sync - much faster
     */
    public function quickLogin($student_id, $password) {
        // Fast check - only query the database, don't sync
        $query = "SELECT s.*, u.id as user_id, u.password as user_password, u.role, u.email 
                  FROM students s 
                  JOIN users u ON s.student_id = u.username 
                  WHERE s.student_id = :student_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (password_verify($password, $student['user_password'])) {
                // Prepare student data for session
                $student_data = [
                    'id' => $student['id'],
                    'student_id' => $student['student_id'],
                    'full_name' => $student['full_name'],
                    'section' => $student['section'],
                    'year_level' => $student['year_level'],
                    'semester' => $student['semester'],
                    'email' => $student['email'],
                    'contact_no' => $student['contact_no'],
                    'allergies' => $student['allergies'],
                    'medical_conditions' => $student['medical_conditions'],
                    'blood_type' => $student['blood_type'],
                    'emergency_contact' => $student['emergency_contact'],
                    'emergency_phone' => $student['emergency_phone'],
                    'emergency_email' => $student['emergency_email']
                ];
                
                return [
                    'success' => true,
                    'user' => [
                        'id' => $student['user_id'],
                        'username' => $student['student_id'],
                        'full_name' => $student['full_name'],
                        'email' => $student['email'],
                        'role' => 'student'
                    ],
                    'student' => $student_data
                ];
            } else {
                // Check if it's the default password
                if ($password === '0000') {
                    // Update to hashed password
                    $hashed = password_hash('0000', PASSWORD_DEFAULT);
                    $update_query = "UPDATE users SET password = :password WHERE username = :student_id";
                    $update_stmt = $this->db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed);
                    $update_stmt->bindParam(':student_id', $student_id);
                    
                    try {
                        $update_stmt->execute();
                    } catch (PDOException $e) {
                        error_log("Error updating password: " . $e->getMessage());
                    }
                    
                    // Try again
                    return $this->quickLogin($student_id, $password);
                }
                
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }
        } else {
            // Student not found - try to sync just this one student
            $synced = $this->syncSingleStudent($student_id);
            
            if ($synced) {
                // Try login again
                return $this->quickLogin($student_id, $password);
            }
            
            return [
                'success' => false,
                'message' => 'Student not found. Please check your Student ID.'
            ];
        }
    }
    
    /**
     * Sync only one student (faster than syncing all)
     */
    public function syncSingleStudent($student_id) {
        $student = $this->api->getStudentById($student_id);
        
        if ($student) {
            $default_password = password_hash('0000', PASSWORD_DEFAULT);
            
            // Check if student exists
            $check_query = "SELECT id FROM students WHERE student_id = :student_id";
            $check_stmt = $this->db->prepare($check_query);
            $check_stmt->bindParam(':student_id', $student_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                // Insert new student
                $query = "INSERT INTO students (
                    student_id, full_name, section, year_level, semester, 
                    email, contact_no, allergies, medical_conditions, blood_type,
                    emergency_contact, emergency_phone, emergency_email, password
                ) VALUES (
                    :student_id, :full_name, :section, :year_level, :semester,
                    :email, :contact_no, :allergies, :medical_conditions, :blood_type,
                    :emergency_contact, :emergency_phone, :emergency_email, :password
                )";
                
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':student_id', $student['student_id']);
                $stmt->bindParam(':full_name', $student['full_name']);
                $stmt->bindParam(':section', $student['section']);
                $stmt->bindParam(':year_level', $student['year_level']);
                $stmt->bindParam(':semester', $student['semester']);
                $stmt->bindParam(':email', $student['email']);
                $stmt->bindParam(':contact_no', $student['contact_no']);
                $stmt->bindParam(':allergies', $student['allergies']);
                $stmt->bindParam(':medical_conditions', $student['medical_conditions']);
                $stmt->bindParam(':blood_type', $student['blood_type']);
                $stmt->bindParam(':emergency_contact', $student['emergency_contact']);
                $stmt->bindParam(':emergency_phone', $student['emergency_phone']);
                $stmt->bindParam(':emergency_email', $student['emergency_email']);
                $stmt->bindParam(':password', $default_password);
                
                try {
                    $stmt->execute();
                    
                    // Create user
                    $user_query = "INSERT INTO users (username, password, full_name, email, role) 
                                   VALUES (:username, :password, :full_name, :email, 'student')";
                    $user_stmt = $this->db->prepare($user_query);
                    $user_stmt->bindParam(':username', $student['student_id']);
                    $user_stmt->bindParam(':password', $default_password);
                    $user_stmt->bindParam(':full_name', $student['full_name']);
                    $user_stmt->bindParam(':email', $student['email']);
                    $user_stmt->execute();
                    
                    return true;
                } catch (PDOException $e) {
                    error_log("Error syncing student: " . $e->getMessage());
                }
            }
        }
        
        return false;
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
        return $this->api->syncAllStudents($this->db);
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
                  JOIN users u ON s.student_id = u.username 
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
                  emergency_email = :emergency_email
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