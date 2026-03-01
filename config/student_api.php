<?php
class StudentAPI {
    private $api_url = "https://ttm.qcprotektado.com/api/students.php";
    
    /**
     * Get a single student by ID
     */
    public function getStudentById($student_id) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Short timeout for quick response
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $api_response = json_decode($response, true);
            
            if (isset($api_response['records']) && is_array($api_response['records'])) {
                foreach ($api_response['records'] as $student) {
                    if (isset($student['student_id']) && $student['student_id'] == $student_id) {
                        return $this->formatStudentData($student);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get all students from API
     */
    public function getAllStudents() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $api_response = json_decode($response, true);
            
            if (isset($api_response['records']) && is_array($api_response['records'])) {
                $formatted_students = [];
                foreach ($api_response['records'] as $student) {
                    $formatted_students[] = $this->formatStudentData($student);
                }
                return $formatted_students;
            }
        }
        
        return [];
    }
    
    /**
     * Format student data to ensure consistent structure
     */
    private function formatStudentData($student) {
        return [
            'id' => $student['id'] ?? null,
            'student_id' => $student['student_id'] ?? '',
            'full_name' => $student['full_name'] ?? '',
            'section' => $student['section'] ?? null,
            'year_level' => $student['year_level'] ?? null,
            'semester' => $student['semester'] ?? null,
            'email' => $student['email'] ?? null,
            'contact_no' => $student['contact_no'] ?? null,
            'allergies' => $student['allergies'] ?? null,
            'medical_conditions' => $student['medical_conditions'] ?? null,
            'blood_type' => $student['blood_type'] ?? null,
            'emergency_contact' => $student['emergency_contact'] ?? null,
            'emergency_phone' => $student['emergency_phone'] ?? null,
            'emergency_email' => $student['emergency_email'] ?? null,
            'created_at' => $student['created_at'] ?? null
        ];
    }
    
    /**
     * Sync a single student to local database
     */
    public function syncStudentToLocal($student_id, $db) {
        $student = $this->getStudentById($student_id);
        
        if ($student) {
            $default_password = password_hash('0000', PASSWORD_DEFAULT);
            
            // Check if student exists
            $query = "SELECT id FROM students WHERE student_id = :student_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
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
                          updated_at = NOW()
                          WHERE student_id = :student_id";
            } else {
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
            }
            
            $stmt = $db->prepare($query);
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
            
            if ($stmt->rowCount() == 0) {
                $stmt->bindParam(':password', $default_password);
            }
            
            try {
                $stmt->execute();
                
                // Also ensure user exists
                $this->ensureUserExists($student, $db, $default_password);
                
                return true;
            } catch (PDOException $e) {
                error_log("Error syncing student: " . $e->getMessage());
            }
        }
        
        return false;
    }
    
    /**
     * Ensure user account exists for student
     */
    private function ensureUserExists($student, $db, $default_password) {
        // Check if user exists
        $query = "SELECT id FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $student['student_id']);
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            // Create user
            $query = "INSERT INTO users (username, password, full_name, email, role) 
                      VALUES (:username, :password, :full_name, :email, 'student')";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $student['student_id']);
            $stmt->bindParam(':password', $default_password);
            $stmt->bindParam(':full_name', $student['full_name']);
            $stmt->bindParam(':email', $student['email']);
            
            try {
                $stmt->execute();
            } catch (PDOException $e) {
                error_log("Error creating user for student: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Sync all students to local database
     */
    public function syncAllStudents($db) {
        $students = $this->getAllStudents();
        $synced = 0;
        $errors = 0;
        
        foreach ($students as $student) {
            $result = $this->syncStudentToLocal($student['student_id'], $db);
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
     * Search students by name or ID
     */
    public function searchStudents($keyword) {
        $all_students = $this->getAllStudents();
        $results = [];
        
        $keyword = strtolower($keyword);
        
        foreach ($all_students as $student) {
            if (strpos(strtolower($student['student_id']), $keyword) !== false ||
                strpos(strtolower($student['full_name']), $keyword) !== false) {
                $results[] = $student;
            }
        }
        
        return $results;
    }
}
?>