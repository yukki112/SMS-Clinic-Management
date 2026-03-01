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

// Get statistics for student
$stats = [];

// Total clinic visits
$query = "SELECT COUNT(*) as total FROM visit_history WHERE student_id = :student_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_visits'] = $result ? $result['total'] : 0;

// Pending appointments - FIXED: patients table doesn't have student_id, so we need to find patient by other means
// For now, set to 0 since we don't have patient records linked
$stats['pending_appointments'] = 0;

// Recent incidents
$query = "SELECT COUNT(*) as total FROM incidents WHERE student_id = :student_id AND DATE(incident_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['recent_incidents'] = $result ? $result['total'] : 0;

// Active clearances
$query = "SELECT COUNT(*) as total FROM clearance_requests WHERE student_id = :student_id AND status = 'Approved' AND (valid_until >= CURDATE() OR valid_until IS NULL)";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['active_clearances'] = $result ? $result['total'] : 0;

// Get recent clinic visits
$query = "SELECT v.*, u.full_name as attended_by_name 
          FROM visit_history v 
          LEFT JOIN users u ON v.attended_by = u.id 
          WHERE v.student_id = :student_id 
          ORDER BY v.visit_date DESC, v.visit_time DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent appointments - FIXED: Using correct query without patients table dependency
// Since we don't have patient records, we'll show empty for now
$recent_appointments = [];

// Get recent clearances
$query = "SELECT * FROM clearance_requests 
          WHERE student_id = :student_id 
          ORDER BY created_at DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$recent_clearances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============ AI INSIGHTS GENERATION FOR STUDENT ============

function generateStudentInsights($db, $stats, $student_data) {
    $insights = [];
    $current_hour = (int)date('H');
    
    // Time-based greeting
    if ($current_hour < 12) {
        $greeting = "Good morning";
    } elseif ($current_hour < 18) {
        $greeting = "Good afternoon";
    } else {
        $greeting = "Good evening";
    }
    
    // Medical condition alerts
    if (!empty($student_data['medical_conditions'])) {
        $conditions = is_array($student_data['medical_conditions']) ? 
            $student_data['medical_conditions'] : 
            explode(',', $student_data['medical_conditions']);
        
        if (count($conditions) > 0 && !empty($conditions[0])) {
            $insights['health'][] = [
                'icon' => '🏥',
                'title' => 'Medical Condition',
                'message' => "You have recorded medical condition(s). Please inform clinic staff during visits.",
                'action' => 'View Details',
                'link' => 'profile.php',
                'priority' => 2
            ];
        }
    }
    
    // Allergy alerts
    if (!empty($student_data['allergies'])) {
        $insights['health'][] = [
            'icon' => '⚠️',
            'title' => 'Allergy Alert',
            'message' => "You have recorded allergies. Please remind clinic staff during consultations.",
            'action' => 'View Allergies',
            'link' => 'profile.php',
            'priority' => 2
        ];
    }
    
    // Clearance alerts
    if ($stats['active_clearances'] > 0) {
        $insights['clearance'][] = [
            'icon' => '✅',
            'title' => 'Active Clearance',
            'message' => "You have {$stats['active_clearances']} active medical clearance(s).",
            'action' => 'View Clearances',
            'link' => 'clearance_history.php',
            'priority' => 3
        ];
    }
    
    // Appointment reminders (disabled for now)
    if ($stats['pending_appointments'] > 0) {
        $insights['appointment'][] = [
            'icon' => '📅',
            'title' => 'Upcoming Appointment',
            'message' => "You have {$stats['pending_appointments']} scheduled appointment(s).",
            'action' => 'View Appointments',
            'link' => 'appointments.php',
            'priority' => 2
        ];
    }
    
    // Health tips based on visit history
    if ($stats['total_visits'] > 5) {
        $insights['tips'][] = [
            'icon' => '💡',
            'title' => 'Health Tip',
            'message' => "Regular check-ups are great! Remember to stay hydrated and get enough rest.",
            'action' => 'Learn More',
            'link' => '#',
            'priority' => 4
        ];
    }
    
    // Positive reinforcement
    if ($stats['total_visits'] == 0) {
        $insights['welcome'][] = [
            'icon' => '👋',
            'title' => 'Welcome to ICARE!',
            'message' => "We're here to support your health needs. Feel free to request appointments or clearances.",
            'action' => 'Get Started',
            'link' => '#',
            'priority' => 1
        ];
    }
    
    return ['greeting' => $greeting, 'insights' => $insights];
}

$ai_insights = generateStudentInsights($db, $stats, $student_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | ICARE Clinic Management System</title>
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

        /* AI Insights Section */
        .ai-insights-section {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.6s ease;
        }

        .ai-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .ai-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
        }

        .ai-header span {
            background: #e3f2fd;
            color: #1565c0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .insight-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
        }

        .insight-card.health {
            border-left-color: #17a2b8;
            background: #e8f7fa;
        }

        .insight-card.clearance {
            border-left-color: #28a745;
            background: #e6ffe9;
        }

        .insight-card.appointment {
            border-left-color: #fd7e14;
            background: #fff8e7;
        }

        .insight-card.tips {
            border-left-color: #6f42c1;
            background: #f3e8ff;
        }

        .insight-card.welcome {
            border-left-color: #191970;
            background: #e6e6ff;
        }

        .insight-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .insight-icon {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .insight-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .insight-message {
            font-size: 0.9rem;
            color: #4a5568;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .insight-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #191970;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            padding: 6px 12px;
            background: white;
            border-radius: 30px;
            border: 1px solid #cfd8dc;
            transition: all 0.3s ease;
        }

        .insight-action:hover {
            background: #191970;
            color: white;
            border-color: #191970;
        }

        .insight-action svg {
            width: 16px;
            height: 16px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
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
            width: 60px;
            height: 60px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }

        .stat-info {
            flex: 1;
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: #546e7a;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.8s ease;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            background: #191970;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            color: white;
        }

        .profile-title h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 5px;
        }

        .profile-title p {
            color: #546e7a;
            font-size: 0.9rem;
        }

        .profile-badge {
            padding: 4px 12px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .profile-item {
            padding: 12px;
            background: #eceff1;
            border-radius: 12px;
        }

        .profile-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #78909c;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .profile-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #191970;
        }

        .profile-value.small {
            font-size: 0.85rem;
            font-weight: 400;
            color: #37474f;
        }

        .medical-tag {
            display: inline-block;
            padding: 4px 12px;
            background: #fff3e0;
            color: #e65100;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .allergy-tag {
            display: inline-block;
            padding: 4px 12px;
            background: #ffebee;
            color: #c62828;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        /* Recent Sections */
        .recent-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            animation: fadeInUp 0.9s ease;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

        .view-all {
            color: #191970;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 6px 14px;
            background: #eceff1;
            border-radius: 20px;
            border: 1px solid #cfd8dc;
            transition: all 0.3s ease;
        }

        .view-all:hover {
            background: #191970;
            color: white;
            border-color: #191970;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            background: #eceff1;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .activity-item:hover {
            background: white;
            border-color: #191970;
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(25, 25, 112, 0.1);
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
            font-size: 0.9rem;
            color: #191970;
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 0.7rem;
            color: #78909c;
        }

        .activity-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-scheduled {
            background: #e3f2fd;
            color: #1565c0;
        }

        .status-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-pending {
            background: #fff3e0;
            color: #e65100;
        }

        /* Quick Actions */
        .quick-actions {
            animation: fadeInUp 1s ease;
        }

        .quick-actions h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
            margin-bottom: 20px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .action-card {
            background: white;
            border: 1px solid #cfd8dc;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .action-card:hover {
            transform: translateY(-4px);
            border-color: #191970;
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.1);
        }

        .action-icon {
            width: 64px;
            height: 64px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            font-size: 28px;
            transition: all 0.3s ease;
        }

        .action-card:hover .action-icon {
            background: #24248f;
            transform: scale(1.05);
        }

        .action-card span {
            display: block;
            font-weight: 600;
            color: #191970;
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .action-card small {
            color: #78909c;
            font-size: 0.8rem;
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
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .profile-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
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
                        <h1><?php echo $ai_insights['greeting']; ?>, <?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name']); ?>! 👋</h1>
                        <p>Welcome to your student health portal.</p>
                    </div>
                    <div class="student-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 16v-4M12 8h.01"/>
                        </svg>
                        Student Portal
                    </div>
                </div>

                <!-- AI Insights Section -->
                <div class="ai-insights-section">
                    <div class="ai-header">
                        <h2>🧠 Your Health Insights</h2>
                        <span>Personalized for you</span>
                    </div>
                    
                    <div class="insights-grid">
                        <?php 
                        // Health insights
                        if (!empty($ai_insights['insights']['health'])): 
                            foreach ($ai_insights['insights']['health'] as $insight): ?>
                                <div class="insight-card health">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Clearance insights
                        if (!empty($ai_insights['insights']['clearance'])): 
                            foreach ($ai_insights['insights']['clearance'] as $insight): ?>
                                <div class="insight-card clearance">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Appointment insights (disabled for now)
                        if (!empty($ai_insights['insights']['appointment'])): 
                            foreach ($ai_insights['insights']['appointment'] as $insight): ?>
                                <div class="insight-card appointment">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Tips insights
                        if (!empty($ai_insights['insights']['tips'])): 
                            foreach ($ai_insights['insights']['tips'] as $insight): ?>
                                <div class="insight-card tips">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php 
                        // Welcome insights
                        if (!empty($ai_insights['insights']['welcome'])): 
                            foreach ($ai_insights['insights']['welcome'] as $insight): ?>
                                <div class="insight-card welcome">
                                    <div class="insight-icon"><?php echo $insight['icon']; ?></div>
                                    <div class="insight-title"><?php echo $insight['title']; ?></div>
                                    <div class="insight-message"><?php echo $insight['message']; ?></div>
                                    <a href="<?php echo $insight['link']; ?>" class="insight-action">
                                        <?php echo $insight['action']; ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14M12 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                </div>
                            <?php endforeach; 
                        endif; ?>

                        <?php if (empty($ai_insights['insights']['health']) && empty($ai_insights['insights']['clearance']) && empty($ai_insights['insights']['appointment']) && empty($ai_insights['insights']['tips']) && empty($ai_insights['insights']['welcome'])): ?>
                            <div class="insight-card welcome" style="grid-column: 1/-1;">
                                <div class="insight-icon">✨</div>
                                <div class="insight-title">All Good!</div>
                                <div class="insight-message">No pending health concerns. Stay healthy!</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_visits']; ?></h3>
                            <p>Total Clinic Visits</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending_appointments']; ?></h3>
                            <p>Pending Appointments</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['recent_incidents']; ?></h3>
                            <p>Recent Incidents (30d)</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                <path d="M22 4L12 14.01L9 11.01"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['active_clearances']; ?></h3>
                            <p>Active Clearances</p>
                        </div>
                    </div>
                </div>

                <!-- Student Profile Card -->
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($student_data['full_name'] ?? $_SESSION['full_name'], 0, 2)); ?>
                        </div>
                        <div class="profile-title">
                            <h2><?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name']); ?></h2>
                            <p>Student ID: <?php echo htmlspecialchars($student_id); ?> <span class="profile-badge">Active</span></p>
                        </div>
                    </div>

                    <div class="profile-grid">
                        <div class="profile-item">
                            <div class="profile-label">Grade & Section</div>
                            <div class="profile-value">
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

                        <div class="profile-item">
                            <div class="profile-label">Blood Type</div>
                            <div class="profile-value">
                                <?php echo htmlspecialchars($student_data['blood_type'] ?? 'Not specified'); ?>
                            </div>
                        </div>

                        <div class="profile-item">
                            <div class="profile-label">Email</div>
                            <div class="profile-value small">
                                <?php echo htmlspecialchars($student_data['email'] ?? 'No email'); ?>
                            </div>
                        </div>

                        <div class="profile-item">
                            <div class="profile-label">Contact</div>
                            <div class="profile-value small">
                                <?php echo htmlspecialchars($student_data['contact_no'] ?? 'No contact'); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($student_data['medical_conditions']) || !empty($student_data['allergies'])): ?>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #cfd8dc;">
                        <?php if (!empty($student_data['medical_conditions'])): ?>
                            <div style="margin-bottom: 10px;">
                                <span style="font-size: 0.8rem; font-weight: 600; color: #78909c; margin-right: 10px;">Medical Conditions:</span>
                                <?php 
                                $conditions = is_array($student_data['medical_conditions']) ? 
                                    $student_data['medical_conditions'] : 
                                    explode(',', $student_data['medical_conditions']);
                                foreach ($conditions as $condition): 
                                    if (trim($condition)): 
                                ?>
                                    <span class="medical-tag"><?php echo htmlspecialchars(trim($condition)); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($student_data['allergies'])): ?>
                            <div>
                                <span style="font-size: 0.8rem; font-weight: 600; color: #78909c; margin-right: 10px;">Allergies:</span>
                                <?php 
                                $allergies = is_array($student_data['allergies']) ? 
                                    $student_data['allergies'] : 
                                    explode(',', $student_data['allergies']);
                                foreach ($allergies as $allergy): 
                                    if (trim($allergy)): 
                                ?>
                                    <span class="allergy-tag"><?php echo htmlspecialchars(trim($allergy)); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activities -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
                    <!-- Recent Clinic Visits -->
                    <div class="recent-section">
                        <div class="section-header">
                            <h2>Recent Clinic Visits</h2>
                            <a href="visit_history.php" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <?php if (!empty($recent_visits)): ?>
                                <?php foreach ($recent_visits as $visit): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">🏥</div>
                                        <div class="activity-content">
                                            <div class="activity-title">Clinic Visit - <?php echo htmlspecialchars($visit['complaint']); ?></div>
                                            <div class="activity-time">
                                                <?php echo date('M d, Y', strtotime($visit['visit_date'])); ?> at <?php echo date('h:i A', strtotime($visit['visit_time'])); ?>
                                            </div>
                                        </div>
                                        <span class="activity-status status-<?php echo strtolower($visit['disposition']); ?>">
                                            <?php echo $visit['disposition']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="activity-item">
                                    <div class="activity-icon">ℹ️</div>
                                    <div class="activity-content">
                                        <div class="activity-title">No clinic visits yet</div>
                                        <div class="activity-time">Your visit history will appear here</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Appointments (Currently Disabled) -->
                    <div class="recent-section">
                        <div class="section-header">
                            <h2>Recent Appointments</h2>
                            <a href="appointments.php" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">ℹ️</div>
                                <div class="activity-content">
                                    <div class="activity-title">Appointment feature coming soon</div>
                                    <div class="activity-time">You can request appointments using Quick Actions</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Clearances -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>Recent Clearances</h2>
                        <a href="clearance_history.php" class="view-all">View All</a>
                    </div>
                    <div class="activity-list">
                        <?php if (!empty($recent_clearances)): ?>
                            <?php foreach ($recent_clearances as $clearance): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">✅</div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($clearance['clearance_type']); ?> Clearance</div>
                                        <div class="activity-time">
                                            Requested: <?php echo date('M d, Y', strtotime($clearance['created_at'])); ?>
                                            <?php if (!empty($clearance['valid_until'])): ?>
                                                • Valid until: <?php echo date('M d, Y', strtotime($clearance['valid_until'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="activity-status status-<?php echo strtolower($clearance['status']); ?>">
                                        <?php echo $clearance['status']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="activity-item">
                                <div class="activity-icon">ℹ️</div>
                                <div class="activity-content">
                                    <div class="activity-title">No clearance requests yet</div>
                                    <div class="activity-time">Request a clearance using Quick Actions</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="request_appointment.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                </svg>
                            </div>
                            <span>Request Appointment</span>
                            <small>Schedule a clinic visit</small>
                        </a>
                        
                        <a href="request_clearance.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                    <path d="M22 4L12 14.01L9 11.01"/>
                                </svg>
                            </div>
                            <span>Request Clearance</span>
                            <small>Get medical clearance</small>
                        </a>
                        
                        <a href="profile.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                </svg>
                            </div>
                            <span>My Profile</span>
                            <small>View personal info</small>
                        </a>
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
            pageTitle.textContent = 'Student Dashboard';
        }
    </script>
</body>
</html>