<?php
session_start();
require_once '../config/database.php';
require_once '../config/student_api.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$studentApi = new StudentAPI();

// Get current student info
$current_user_id = $_SESSION['user_id'];
$student_id = $_SESSION['student_id'];
$student_data = $_SESSION['student_data'];

// Handle profile update for allowed fields only
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_contact'])) {
        // Update only contact information
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $contact_no = filter_var($_POST['contact_no'], FILTER_SANITIZE_STRING);
        $emergency_contact = filter_var($_POST['emergency_contact'], FILTER_SANITIZE_STRING);
        $emergency_phone = filter_var($_POST['emergency_phone'], FILTER_SANITIZE_STRING);
        $emergency_email = filter_var($_POST['emergency_email'], FILTER_SANITIZE_EMAIL);
        
        try {
            // Update students table
            $query = "UPDATE students SET 
                      email = :email,
                      contact_no = :contact_no,
                      emergency_contact = :emergency_contact,
                      emergency_phone = :emergency_phone,
                      emergency_email = :emergency_email
                      WHERE student_id = :student_id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':contact_no', $contact_no);
            $stmt->bindParam(':emergency_contact', $emergency_contact);
            $stmt->bindParam(':emergency_phone', $emergency_phone);
            $stmt->bindParam(':emergency_email', $emergency_email);
            $stmt->bindParam(':student_id', $student_id);
            
            if ($stmt->execute()) {
                // Also update users table email if changed
                $user_query = "UPDATE users SET email = :email WHERE student_id = :student_id";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->bindParam(':email', $email);
                $user_stmt->bindParam(':student_id', $student_id);
                $user_stmt->execute();
                
                // Refresh session data
                $_SESSION['student_data']['email'] = $email;
                $_SESSION['student_data']['contact_no'] = $contact_no;
                $_SESSION['student_data']['emergency_contact'] = $emergency_contact;
                $_SESSION['student_data']['emergency_phone'] = $emergency_phone;
                $_SESSION['student_data']['emergency_email'] = $emergency_email;
                
                $message = 'Contact information updated successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to update contact information.';
                $message_type = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['change_password'])) {
        // Handle password change
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Get current user's password hash
        $query = "SELECT password FROM users WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $current_user_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 8) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $update_query = "UPDATE users SET password = :password WHERE id = :user_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed_password);
                    $update_stmt->bindParam(':user_id', $current_user_id);
                    
                    if ($update_stmt->execute()) {
                        $message = 'Password changed successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to change password.';
                        $message_type = 'error';
                    }
                } else {
                    $message = 'New password must be at least 8 characters long.';
                    $message_type = 'error';
                }
            } else {
                $message = 'New passwords do not match.';
                $message_type = 'error';
            }
        } else {
            $message = 'Current password is incorrect.';
            $message_type = 'error';
        }
    }
}

// Get additional statistics
$stats = [];

// Total clinic visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE student_id = :student_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_visits'] = $result ? $result['total'] : 0;

// Total clearances
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE student_id = :student_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_clearances'] = $result ? $result['total'] : 0;

// Last visit date
$query = "SELECT visit_date FROM visit_history WHERE student_id = :student_id ORDER BY visit_date DESC LIMIT 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['last_visit'] = $result ? $result['visit_date'] : null;

// Format medical conditions and allergies for display
$medical_conditions = !empty($student_data['medical_conditions']) ? 
    (is_array($student_data['medical_conditions']) ? 
        $student_data['medical_conditions'] : 
        explode(',', $student_data['medical_conditions'])) : [];

$allergies = !empty($student_data['allergies']) ? 
    (is_array($student_data['allergies']) ? 
        $student_data['allergies'] : 
        explode(',', $student_data['allergies'])) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | ICARE Clinic Management System</title>
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

        /* Page Header */
        .page-header {
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .header-left p {
            color: #546e7a;
            font-size: 1rem;
            font-weight: 400;
        }

        .header-right {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .btn-primary {
            background: #191970;
            color: white;
            box-shadow: 0 4px 10px rgba(25, 25, 112, 0.2);
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(25, 25, 112, 0.3);
        }

        .btn-outline {
            background: white;
            border-color: #cfd8dc;
            color: #191970;
        }

        .btn-outline:hover {
            background: #eceff1;
            border-color: #191970;
        }

        .btn-outline svg {
            stroke: #191970;
        }

        /* Message Alert */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
        }

        .alert-error {
            background: #ffebee;
            border: 1px solid #ef9a9a;
            color: #c62828;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: inherit;
        }

        /* Profile Layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        /* Profile Sidebar */
        .profile-sidebar {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            text-align: center;
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, #191970 0%, #24248f 100%);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 4rem;
            font-weight: 600;
            color: white;
            box-shadow: 0 10px 20px rgba(25, 25, 112, 0.2);
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 5px;
        }

        .profile-id {
            display: inline-block;
            padding: 5px 15px;
            background: #eceff1;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 15px;
        }

        .profile-badge {
            display: inline-block;
            padding: 5px 15px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eceff1;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #191970;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #78909c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Profile Content */
        .profile-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eceff1;
        }

        .card-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header .badge {
            padding: 4px 12px;
            background: #eceff1;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            color: #191970;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: #eceff1;
            transform: translateX(5px);
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
        }

        .info-value.readonly {
            background: #eceff1;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #546e7a;
        }

        .info-note {
            font-size: 0.7rem;
            color: #78909c;
            margin-top: 5px;
            font-style: italic;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 0 0 3px rgba(25, 25, 112, 0.1);
        }

        .form-control:read-only {
            background: #eceff1;
            cursor: not-allowed;
            border-color: #cfd8dc;
            color: #78909c;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .password-requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            margin-top: 15px;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #78909c;
            margin-bottom: 8px;
        }

        .requirement-item.valid {
            color: #2e7d32;
        }

        .requirement-item svg {
            width: 16px;
            height: 16px;
        }

        /* Tag Containers */
        .tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .tag {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .tag-medical {
            background: #fff3e0;
            color: #e65100;
        }

        .tag-allergy {
            background: #ffebee;
            color: #c62828;
        }

        .tag-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Recent Activity */
        .recent-activity {
            margin-top: 30px;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: #eceff1;
            transform: translateX(5px);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: #191970;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: #191970;
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #78909c;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eceff1;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #78909c;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: #191970;
        }

        .modal-body {
            padding: 24px;
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

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1280px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-right {
                width: 100%;
            }
            
            .btn {
                flex: 1;
                text-align: center;
                justify-content: center;
            }
            
            .profile-avatar-large {
                width: 120px;
                height: 120px;
                font-size: 3rem;
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
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-left">
                        <h1>My Profile</h1>
                        <p>View and manage your personal information</p>
                    </div>
                    <div class="header-right">
                        <button onclick="openPasswordModal()" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            Change Password
                        </button>
                        <a href="dashboard.php" class="btn btn-outline">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M19 12H5M12 19l-7-7 7-7"/>
                            </svg>
                            Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Alert Message -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Profile Layout -->
                <div class="profile-layout">
                    <!-- Profile Sidebar -->
                    <div class="profile-sidebar">
                        <div class="profile-avatar-large">
                            <?php echo strtoupper(substr($student_data['full_name'] ?? $_SESSION['full_name'], 0, 2)); ?>
                        </div>
                        <div class="profile-name">
                            <?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name']); ?>
                        </div>
                        <div class="profile-id">ID: <?php echo htmlspecialchars($student_id); ?></div>
                        <div class="profile-badge">Active Student</div>
                        
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['total_visits']; ?></div>
                                <div class="stat-label">Clinic Visits</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['total_clearances']; ?></div>
                                <div class="stat-label">Clearances</div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Content -->
                    <div class="profile-content">
                        <!-- Personal Information (Read Only) -->
                        <div class="profile-card">
                            <div class="card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    </svg>
                                    Personal Information
                                </h2>
                                <span class="badge">Read Only</span>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Full Name</div>
                                    <div class="info-value readonly"><?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name']); ?></div>
                                    <div class="info-note">Cannot be changed. Contact admin for corrections.</div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Username</div>
                                    <div class="info-value readonly"><?php echo htmlspecialchars($_SESSION['username'] ?? 'N/A'); ?></div>
                                    <div class="info-note">Username is system generated based on Student ID.</div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Student ID</div>
                                    <div class="info-value readonly"><?php echo htmlspecialchars($student_id); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Grade & Section</div>
                                    <div class="info-value readonly">
                                        <?php 
                                        $year = $student_data['year_level'] ?? '';
                                        $section = $student_data['section'] ?? '';
                                        if ($year && $section) {
                                            echo "Grade $year - $section";
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information (Editable) -->
                        <div class="profile-card">
                            <div class="card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                                        <path d="M22 6L12 13 2 6"/>
                                    </svg>
                                    Contact Information
                                </h2>
                                <span class="badge">Editable</span>
                            </div>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="update_contact" value="1">
                                
                                <div class="info-grid">
                                    <div class="form-group">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" name="email" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['email'] ?? ''); ?>" 
                                               placeholder="your.email@example.com">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="contact_no" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['contact_no'] ?? ''); ?>" 
                                               placeholder="09XXXXXXXXX">
                                    </div>
                                </div>
                                
                                <div class="card-header" style="margin-top: 20px; border-bottom: none; padding-bottom: 0;">
                                    <h3 style="font-size: 1rem; color: #191970;">Emergency Contact</h3>
                                </div>
                                
                                <div class="info-grid">
                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact Name</label>
                                        <input type="text" name="emergency_contact" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['emergency_contact'] ?? ''); ?>" 
                                               placeholder="Full name">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact Number</label>
                                        <input type="text" name="emergency_phone" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['emergency_phone'] ?? ''); ?>" 
                                               placeholder="09XXXXXXXXX">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Emergency Email</label>
                                        <input type="email" name="emergency_email" class="form-control" 
                                               value="<?php echo htmlspecialchars($student_data['emergency_email'] ?? ''); ?>" 
                                               placeholder="emergency@example.com">
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%;">
                                    Update Contact Information
                                </button>
                            </form>
                        </div>

                        <!-- Medical Information (Read Only) -->
                        <div class="profile-card">
                            <div class="card-header">
                                <h2>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                                    </svg>
                                    Medical Information
                                </h2>
                                <span class="badge">Read Only</span>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Blood Type</div>
                                    <div class="info-value readonly"><?php echo htmlspecialchars($student_data['blood_type'] ?? 'Not specified'); ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Last Visit</div>
                                    <div class="info-value readonly">
                                        <?php echo $stats['last_visit'] ? date('M d, Y', strtotime($stats['last_visit'])) : 'No visits yet'; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($medical_conditions) && !empty($medical_conditions[0])): ?>
                                <div style="margin-top: 20px;">
                                    <div class="info-label" style="margin-bottom: 10px;">Medical Conditions</div>
                                    <div class="tags-container">
                                        <?php foreach ($medical_conditions as $condition): ?>
                                            <?php if (trim($condition)): ?>
                                                <span class="tag tag-medical">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                    </svg>
                                                    <?php echo htmlspecialchars(trim($condition)); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($allergies) && !empty($allergies[0])): ?>
                                <div style="margin-top: 20px;">
                                    <div class="info-label" style="margin-bottom: 10px;">Allergies</div>
                                    <div class="tags-container">
                                        <?php foreach ($allergies as $allergy): ?>
                                            <?php if (trim($allergy)): ?>
                                                <span class="tag tag-allergy">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                                        <circle cx="12" cy="12" r="10"/>
                                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                    </svg>
                                                    <?php echo htmlspecialchars(trim($allergy)); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (empty($medical_conditions[0]) && empty($allergies[0])): ?>
                                <div class="info-item">
                                    <div class="info-value readonly" style="text-align: center;">No medical information recorded</div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="info-note" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eceff1;">
                                Medical information can only be updated by clinic staff. Please visit the clinic for any corrections.
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="profile-card">
                            <div class="card-header">
                                <h2>Recent Activity</h2>
                            </div>
                            
                            <div class="activity-list">
                                <?php
                                // Get recent visits for activity feed
                                $activity_query = "SELECT 'visit' as type, visit_date as date, visit_time as time, complaint as description 
                                                  FROM visit_history 
                                                  WHERE student_id = :student_id 
                                                  UNION ALL 
                                                  SELECT 'clearance' as type, request_date as date, NULL as time, clearance_type as description 
                                                  FROM clearance_requests 
                                                  WHERE student_id = :student_id 
                                                  ORDER BY date DESC, time DESC 
                                                  LIMIT 5";
                                $activity_stmt = $db->prepare($activity_query);
                                $activity_stmt->bindParam(':student_id', $student_id);
                                $activity_stmt->execute();
                                $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <?php if (!empty($activities)): ?>
                                    <?php foreach ($activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <?php echo $activity['type'] === 'visit' ? '🏥' : '✅'; ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title">
                                                    <?php if ($activity['type'] === 'visit'): ?>
                                                        Clinic Visit - <?php echo htmlspecialchars($activity['description'] ?: 'Check-up'); ?>
                                                    <?php else: ?>
                                                        Clearance Request - <?php echo htmlspecialchars($activity['description']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="activity-time">
                                                    <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                                    <?php if ($activity['time']): ?>
                                                        at <?php echo date('h:i A', strtotime($activity['time'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">ℹ️</div>
                                        <div class="activity-content">
                                            <div class="activity-title">No recent activity</div>
                                            <div class="activity-time">Your activity will appear here</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Change Password</h2>
                <button class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="passwordForm">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" id="newPassword" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" id="confirmPassword" required>
                    </div>
                    
                    <div class="password-requirements">
                        <div class="requirement-item" id="lengthReq">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                            At least 8 characters
                        </div>
                        <div class="requirement-item" id="matchReq">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                            Passwords match
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                        Update Password
                    </button>
                </form>
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
            pageTitle.textContent = 'My Profile';
        }

        // Password Modal
        function openPasswordModal() {
            document.getElementById('passwordModal').classList.add('active');
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.remove('active');
            document.getElementById('passwordForm').reset();
        }

        // Password validation
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const lengthReq = document.getElementById('lengthReq');
        const matchReq = document.getElementById('matchReq');

        if (newPassword && confirmPassword) {
            function validatePassword() {
                // Length check
                if (newPassword.value.length >= 8) {
                    lengthReq.classList.add('valid');
                    lengthReq.querySelector('svg').setAttribute('d', 'M20 6L9 17l-5-5');
                } else {
                    lengthReq.classList.remove('valid');
                    lengthReq.querySelector('svg').setAttribute('d', 'M12 16v-4M12 8h.01');
                }

                // Match check
                if (newPassword.value && newPassword.value === confirmPassword.value) {
                    matchReq.classList.add('valid');
                    matchReq.querySelector('svg').setAttribute('d', 'M20 6L9 17l-5-5');
                } else {
                    matchReq.classList.remove('valid');
                    matchReq.querySelector('svg').setAttribute('d', 'M12 16v-4M12 8h.01');
                }
            }

            newPassword.addEventListener('input', validatePassword);
            confirmPassword.addEventListener('input', validatePassword);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('passwordModal');
            if (event.target == modal) {
                closePasswordModal();
            }
        }

        // Auto-hide alert after 5 seconds
        setTimeout(function() {
            const alert = document.querySelector('.alert');
            if (alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>