<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$student_id = $_SESSION['student_id'];
$student_data = $_SESSION['student_data'];

// Get appointment types/categories
$appointment_types = [
    'Consultation' => 'General Consultation',
    'Vaccination' => 'Vaccination',
    'Physical Exam' => 'Physical Examination',
    'Deworming' => 'Deworming',
    'Health Screening' => 'Health Screening',
    'Medical Clearance' => 'Medical Clearance',
    'Follow-up' => 'Follow-up Visit',
    'Other' => 'Other'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $appointment_type = $_POST['appointment_type'];
    $reason = $_POST['reason'];
    $doctor_id = !empty($_POST['doctor_id']) ? $_POST['doctor_id'] : null;
    $notes = $_POST['notes'] ?? '';
    
    // First, get or create patient record
    $query = "SELECT id FROM patients WHERE student_id = :student_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        $patient_id = $patient['id'];
    } else {
        // Create patient record
        $query = "INSERT INTO patients (patient_id, student_id, full_name, email, phone) 
                  VALUES (:patient_id, :student_id, :full_name, :email, :phone)";
        $stmt = $db->prepare($query);
        $patient_id_code = 'P' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->bindParam(':patient_id', $patient_id_code);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':full_name', $student_data['full_name']);
        $stmt->bindParam(':email', $student_data['email']);
        $stmt->bindParam(':phone', $student_data['contact_no']);
        $stmt->execute();
        $patient_id = $db->lastInsertId();
    }
    
    // Create appointment with type
    $full_reason = $appointment_type . ': ' . $reason;
    if (!empty($notes)) {
        $full_reason .= ' (Notes: ' . $notes . ')';
    }
    
    $query = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, reason, status) 
              VALUES (:patient_id, :doctor_id, :appointment_date, :appointment_time, :reason, 'scheduled')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':patient_id', $patient_id);
    $stmt->bindParam(':doctor_id', $doctor_id);
    $stmt->bindParam(':appointment_date', $appointment_date);
    $stmt->bindParam(':appointment_time', $appointment_time);
    $stmt->bindParam(':reason', $full_reason);
    
    if ($stmt->execute()) {
        $success_message = "Appointment requested successfully! The clinic staff will review your request.";
    } else {
        $error_message = "Failed to request appointment. Please try again.";
    }
}

// Get available doctors
$query = "SELECT id, full_name FROM users WHERE role IN ('doctor', 'nurse') AND status = 'active' ORDER BY full_name";
$stmt = $db->query($query);
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Appointment | Student Portal | ICARE Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #eceff1;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 20px 30px 30px 30px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: #eceff1;
        }

        .main-content.expanded {
            margin-left: 110px;
        }

        .dashboard-container {
            position: relative;
            z-index: 1;
        }

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-text p {
            color: #546e7a;
            font-size: 1rem;
            font-weight: 400;
        }

        .student-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .student-badge svg {
            width: 20px;
            height: 20px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: #e8f5e9;
            border: 1px solid #81c784;
            color: #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            border: 1px solid #ef5350;
            color: #c62828;
        }

        .alert svg {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.6s ease;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eceff1;
        }

        .form-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }

        .form-title h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 5px;
        }

        .form-title p {
            color: #546e7a;
            font-size: 0.95rem;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            background: white;
            color: #37474f;
        }

        .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23191970' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 40px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Info Card */
        .info-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #cfd8dc;
        }

        .info-title {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-title svg {
            width: 20px;
            height: 20px;
            color: #667eea;
        }

        .appointment-types {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .type-item {
            background: white;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 500;
            color: #37474f;
            transition: all 0.3s ease;
            cursor: default;
        }

        .type-item:hover {
            border-color: #191970;
            background: #f0f0ff;
            transform: translateY(-2px);
        }

        .type-item i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        /* Button Styles */
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-primary {
            background: #191970;
            color: white;
            flex: 1;
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .btn-secondary {
            background: #eceff1;
            color: #37474f;
            border: 1px solid #cfd8dc;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
            transform: translateY(-2px);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        /* Quick Tips */
        .tips-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.7s ease;
        }

        .tips-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tips-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #191970;
        }

        .tips-list {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .tip-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
        }

        .tip-icon {
            width: 36px;
            height: 36px;
            background: #191970;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .tip-text {
            font-size: 0.85rem;
            color: #37474f;
            line-height: 1.4;
        }

        .tip-text strong {
            color: #191970;
            display: block;
            margin-bottom: 2px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1280px) {
            .appointment-types {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .tips-list {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .appointment-types {
                grid-template-columns: 1fr;
            }
            
            .tips-list {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="dashboard-container">
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="welcome-text">
                        <h1>Request Appointment</h1>
                        <p>Schedule your visit to the clinic.</p>
                    </div>
                    <div class="student-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        Student Portal
                    </div>
                </div>

                <!-- Appointment Types Info Card -->
                <div class="info-card">
                    <div class="info-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        Available Appointment Types
                    </div>
                    <div class="appointment-types">
                        <div class="type-item">
                            <i>🩺</i>
                            Consultation
                        </div>
                        <div class="type-item">
                            <i>💉</i>
                            Vaccination
                        </div>
                        <div class="type-item">
                            <i>📋</i>
                            Physical Exam
                        </div>
                        <div class="type-item">
                            <i>💊</i>
                            Deworming
                        </div>
                        <div class="type-item">
                            <i>🏥</i>
                            Health Screening
                        </div>
                        <div class="type-item">
                            <i>✅</i>
                            Medical Clearance
                        </div>
                        <div class="type-item">
                            <i>🔄</i>
                            Follow-up
                        </div>
                        <div class="type-item">
                            <i>📌</i>
                            Other
                        </div>
                    </div>
                </div>

                <!-- Main Form Card -->
                <div class="form-card">
                    <div class="form-header">
                        <div class="form-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="form-title">
                            <h2>Appointment Details</h2>
                            <p>Please fill in the information below to request an appointment.</p>
                        </div>
                    </div>

                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                <path d="M22 4L12 14.01L9 11.01"/>
                            </svg>
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="appointment_date">Preferred Date</label>
                                <input type="date" class="form-control" id="appointment_date" name="appointment_date" 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="appointment_time">Preferred Time</label>
                                <input type="time" class="form-control" id="appointment_time" name="appointment_time" 
                                       value="09:00" min="08:00" max="17:00" required>
                                <small style="color: #78909c; font-size: 0.7rem;">Clinic hours: 8:00 AM - 5:00 PM</small>
                            </div>

                            <div class="form-group">
                                <label for="appointment_type">Appointment Type</label>
                                <select class="form-control" id="appointment_type" name="appointment_type" required>
                                    <option value="">Select type</option>
                                    <?php foreach ($appointment_types as $key => $value): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="doctor_id">Preferred Staff (Optional)</label>
                                <select class="form-control" id="doctor_id" name="doctor_id">
                                    <option value="">Any available staff</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <option value="<?php echo $doctor['id']; ?>"><?php echo htmlspecialchars($doctor['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group full-width">
                                <label for="reason">Reason for Appointment</label>
                                <textarea class="form-control" id="reason" name="reason" rows="3" 
                                          placeholder="Please describe your concern or reason for visiting..." required></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="notes">Additional Notes (Optional)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2" 
                                          placeholder="Any specific requests or information..."></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="dashboard.php" class="btn btn-secondary">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                                </svg>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M5 12h14M12 5l7 7-7 7"/>
                                </svg>
                                Submit Request
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Tips Section -->
                <div class="tips-section">
                    <div class="tips-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="color: #191970;">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        <h3>Appointment Tips</h3>
                    </div>
                    <div class="tips-list">
                        <div class="tip-item">
                            <div class="tip-icon">📅</div>
                            <div class="tip-text">
                                <strong>Book in Advance</strong>
                                Schedule at least 1 day ahead for better availability
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">⏰</div>
                            <div class="tip-text">
                                <strong>Be on Time</strong>
                                Arrive 5-10 minutes before your scheduled time
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📋</div>
                            <div class="tip-text">
                                <strong>Bring Requirements</strong>
                                Bring your student ID and any relevant medical records
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">✅</div>
                            <div class="tip-text">
                                <strong>Check Status</strong>
                                Monitor your appointment status in the dashboard
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📞</div>
                            <div class="tip-text">
                                <strong>Emergency?</strong>
                                For urgent concerns, visit the clinic immediately
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">🔄</div>
                            <div class="tip-text">
                                <strong>Reschedule</strong>
                                Contact the clinic if you need to reschedule
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle sync
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseSidebar');
        
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            });
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Request Appointment';
        }

        // Set minimum time based on clinic hours
        const timeInput = document.getElementById('appointment_time');
        timeInput.addEventListener('change', function() {
            const selectedTime = this.value;
            const hour = parseInt(selectedTime.split(':')[0]);
            
            if (hour < 8 || hour >= 17) {
                alert('Please select a time between 8:00 AM and 5:00 PM (clinic hours).');
                this.value = '09:00';
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>