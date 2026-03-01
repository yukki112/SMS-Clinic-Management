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
     * Format student data to match database structure
     */
    private function formatStudentData($student) {
        return [
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
            'emergency_email' => $student['emergency_email'] ?? null
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