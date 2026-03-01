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

// Generate unique clearance code
function generateClearanceCode() {
    return 'CLR-' . date('Ymd') . '-' . rand(1000, 9999);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clearance_type = $_POST['clearance_type'];
    $purpose = $_POST['purpose'];
    $clearance_code = generateClearanceCode();
    
    // Insert clearance request WITHOUT valid_until field
    $query = "INSERT INTO clearance_requests (
                clearance_code, 
                student_id, 
                student_name, 
                grade_section, 
                clearance_type, 
                purpose, 
                request_date, 
                status, 
                created_by
              ) VALUES (
                :clearance_code,
                :student_id, 
                :student_name, 
                :grade_section, 
                :clearance_type, 
                :purpose, 
                CURDATE(), 
                'Pending',
                :created_by
              )";
    
    $stmt = $db->prepare($query);
    
    // Prepare grade_section
    $grade_section = '';
    if (!empty($student_data['year_level']) && !empty($student_data['section'])) {
        $grade_section = 'Grade ' . $student_data['year_level'] . ' - ' . $student_data['section'];
    }
    
    $stmt->bindParam(':clearance_code', $clearance_code);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->bindParam(':student_name', $student_data['full_name']);
    $stmt->bindParam(':grade_section', $grade_section);
    $stmt->bindParam(':clearance_type', $clearance_type);
    $stmt->bindParam(':purpose', $purpose);
    $stmt->bindParam(':created_by', $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $success_message = "Clearance request submitted successfully! Your request code is: " . $clearance_code;
    } else {
        $error_message = "Failed to submit clearance request. Please try again.";
    }
}

// Get clearance history
$query = "SELECT * FROM clearance_requests 
          WHERE student_id = :student_id 
          ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$clearance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clearance statistics
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'expired' => 0
];

foreach ($clearance_history as $clearance) {
    switch ($clearance['status']) {
        case 'Pending':
            $stats['pending']++;
            break;
        case 'Approved':
            $stats['approved']++;
            break;
        case 'Not Cleared':
            $stats['rejected']++;
            break;
        case 'Expired':
            $stats['expired']++;
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Clearance | ICARE Student Portal</title>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
            border-color: #191970;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.pending {
            background: #fff3e0;
            color: #e65100;
        }

        .stat-icon.approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .stat-icon.rejected {
            background: #ffebee;
            color: #c62828;
        }

        .stat-icon.expired {
            background: #eceff1;
            color: #546e7a;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 2px;
        }

        .stat-info p {
            color: #546e7a;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            height: fit-content;
        }

        .form-header {
            margin-bottom: 24px;
        }

        .form-header h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-header p {
            color: #546e7a;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            font-size: 0.95rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            background: white;
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
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: #191970;
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
        }

        .btn-secondary {
            background: #eceff1;
            color: #37474f;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
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
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.95rem;
        }

        .alert-content p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* History Card */
        .history-card {
            background: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .history-header h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #191970;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-count {
            background: #191970;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .history-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .history-item {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
            border: 1px solid #cfd8dc;
        }

        .history-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: #191970;
        }

        .history-item.status-pending {
            border-left-color: #e65100;
        }

        .history-item.status-approved {
            border-left-color: #2e7d32;
        }

        .history-item.status-rejected {
            border-left-color: #c62828;
        }

        .history-item.status-expired {
            border-left-color: #78909c;
        }

        .history-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .history-code {
            font-size: 1rem;
            font-weight: 700;
            color: #191970;
            font-family: monospace;
            background: #eceff1;
            padding: 4px 10px;
            border-radius: 8px;
        }

        .history-status {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fff3e0;
            color: #e65100;
        }

        .status-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-rejected {
            background: #ffebee;
            color: #c62828;
        }

        .status-expired {
            background: #eceff1;
            color: #546e7a;
        }

        .history-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .history-detail {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .detail-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #37474f;
        }

        .detail-value.approved-by {
            color: #2e7d32;
            font-weight: 600;
        }

        .history-purpose {
            background: white;
            padding: 12px;
            border-radius: 10px;
            margin-top: 12px;
            font-size: 0.9rem;
            color: #37474f;
            border: 1px dashed #cfd8dc;
        }

        .history-purpose strong {
            color: #191970;
            display: block;
            margin-bottom: 4px;
            font-size: 0.8rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 16px;
            border: 2px dashed #cfd8dc;
        }

        .empty-state svg {
            width: 60px;
            height: 60px;
            color: #78909c;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            color: #191970;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #78909c;
            font-size: 0.9rem;
        }

        /* Info Card */
        .info-card {
            background: #e3f2fd;
            border-radius: 16px;
            padding: 16px 20px;
            margin-top: 20px;
            border: 1px solid #90caf9;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .info-icon {
            color: #1976d2;
            font-size: 20px;
        }

        .info-content {
            flex: 1;
        }

        .info-content strong {
            display: block;
            color: #1976d2;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }

        .info-content p {
            color: #37474f;
            font-size: 0.85rem;
            line-height: 1.5;
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

        @media (max-width: 1280px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .history-details {
                grid-template-columns: 1fr;
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
                <div class="welcome-section">
                    <div class="welcome-text">
                        <h1>Medical Clearance</h1>
                        <p>Request and track your medical clearances.</p>
                    </div>
                    <div class="student-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        Student Portal
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon pending">⏳</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending']; ?></h3>
                            <p>Pending Requests</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon approved">✅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['approved']; ?></h3>
                            <p>Approved</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon rejected">❌</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['rejected']; ?></h3>
                            <p>Not Cleared</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon expired">📅</div>
                        <div class="stat-info">
                            <h3><?php echo $stats['expired']; ?></h3>
                            <p>Expired</p>
                        </div>
                    </div>
                </div>

                <!-- Main Content Grid -->
                <div class="content-grid">
                    <!-- Request Form -->
                    <div class="form-card">
                        <div class="form-header">
                            <h2>
                                <span>📋</span>
                                New Clearance Request
                            </h2>
                            <p>Fill out the form below to request a medical clearance.</p>
                        </div>

                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                    <path d="M22 4L12 14.01L9 11.01"/>
                                </svg>
                                <div class="alert-content">
                                    <strong>Success!</strong>
                                    <p><?php echo $success_message; ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-error">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <div class="alert-content">
                                    <strong>Error!</strong>
                                    <p><?php echo $error_message; ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="clearance_type">Clearance Type</label>
                                <select class="form-control" id="clearance_type" name="clearance_type" required>
                                    <option value="">Select clearance type</option>
                                    <option value="Sports">🏃 Sports Clearance</option>
                                    <option value="Event">🎪 Event Clearance</option>
                                    <option value="Work Immersion">💼 Work Immersion</option>
                                    <option value="After Illness">🤒 After Illness</option>
                                    <option value="After Hospitalization">🏥 After Hospitalization</option>
                                    <option value="After Injury">⚕️ After Injury</option>
                                    <option value="General">📄 General Medical Clearance</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="purpose">Purpose / Reason</label>
                                <textarea class="form-control" id="purpose" name="purpose" rows="4" 
                                          placeholder="Please state the purpose of this clearance request (e.g., For sports fest 2026, For work immersion, etc.)" required></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <span>📤</span>
                                Submit Clearance Request
                            </button>
                        </form>

                        <!-- Info Card -->
                        <div class="info-card">
                            <div class="info-icon">ℹ️</div>
                            <div class="info-content">
                                <strong>About Medical Clearances</strong>
                                <p>Clearance requests are typically processed within 24-48 hours. You can track the status of your requests in the history section below.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Clearance History -->
                    <div class="history-card">
                        <div class="history-header">
                            <h2>
                                <span>📜</span>
                                Clearance History
                            </h2>
                            <span class="badge-count"><?php echo count($clearance_history); ?> Total</span>
                        </div>

                        <div class="history-list">
                            <?php if (!empty($clearance_history)): ?>
                                <?php foreach ($clearance_history as $clearance): ?>
                                    <div class="history-item status-<?php echo strtolower($clearance['status'] == 'Not Cleared' ? 'rejected' : $clearance['status']); ?>">
                                        <div class="history-header-row">
                                            <span class="history-code"><?php echo htmlspecialchars($clearance['clearance_code']); ?></span>
                                            <span class="history-status status-<?php echo strtolower($clearance['status'] == 'Not Cleared' ? 'rejected' : $clearance['status']); ?>">
                                                <?php 
                                                $status_display = $clearance['status'];
                                                if ($status_display == 'Not Cleared') {
                                                    echo '❌ REJECTED';
                                                } elseif ($status_display == 'Approved') {
                                                    echo '✅ APPROVED';
                                                } elseif ($status_display == 'Pending') {
                                                    echo '⏳ PENDING';
                                                } elseif ($status_display == 'Expired') {
                                                    echo '📅 EXPIRED';
                                                } else {
                                                    echo $status_display;
                                                }
                                                ?>
                                            </span>
                                        </div>

                                        <div class="history-details">
                                            <div class="history-detail">
                                                <span class="detail-label">Type</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($clearance['clearance_type']); ?></span>
                                            </div>
                                            <div class="history-detail">
                                                <span class="detail-label">Request Date</span>
                                                <span class="detail-value"><?php echo date('M d, Y', strtotime($clearance['created_at'])); ?></span>
                                            </div>
                                            <?php if (!empty($clearance['approved_date'])): ?>
                                            <div class="history-detail">
                                                <span class="detail-label">Approved Date</span>
                                                <span class="detail-value"><?php echo date('M d, Y', strtotime($clearance['approved_date'])); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($clearance['approved_by'])): ?>
                                            <div class="history-detail">
                                                <span class="detail-label">Approved By</span>
                                                <span class="detail-value approved-by"><?php echo htmlspecialchars($clearance['approved_by']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="history-purpose">
                                            <strong>PURPOSE:</strong>
                                            <?php echo htmlspecialchars($clearance['purpose']); ?>
                                        </div>

                                        <?php if (!empty($clearance['remarks'])): ?>
                                        <div class="history-purpose" style="background: #fff3e0; border-color: #ffb74d; margin-top: 8px;">
                                            <strong>REMARKS:</strong>
                                            <?php echo htmlspecialchars($clearance['remarks']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                    </svg>
                                    <h3>No clearance requests yet</h3>
                                    <p>Your clearance history will appear here once you submit a request.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div style="margin-top: 24px; display: flex; gap: 15px; justify-content: flex-end;">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <span>←</span>
                        Back to Dashboard
                    </a>
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
            pageTitle.textContent = 'Request Clearance';
        }

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        });
    </script>
</body>
</html>