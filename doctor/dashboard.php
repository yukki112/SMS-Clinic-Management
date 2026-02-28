<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get statistics
$stats = [];

// Total patients
$query = "SELECT COUNT(*) as total FROM patients";
$stmt = $db->query($query);
$stats['patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Today's appointments
$query = "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE()";
$stmt = $db->query($query);
$stats['today_appointments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total staff
$query = "SELECT COUNT(*) as total FROM users WHERE role != 'admin'";
$stmt = $db->query($query);
$stats['staff'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total medical records
$query = "SELECT COUNT(*) as total FROM medical_records WHERE DATE(visit_date) = CURDATE()";
$stmt = $db->query($query);
$stats['today_visits'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Recent appointments
$query = "SELECT a.*, p.full_name as patient_name, p.id as patient_id, u.full_name as doctor_name 
          FROM appointments a 
          JOIN patients p ON a.patient_id = p.id 
          JOIN users u ON a.doctor_id = u.id 
          ORDER BY a.appointment_date DESC, a.appointment_time DESC 
          LIMIT 8";
$stmt = $db->query($query);
$recent_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly activity data
$query = "SELECT 
            DAYNAME(appointment_date) as day,
            COUNT(*) as count
          FROM appointments
          WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          GROUP BY DAYNAME(appointment_date)
          ORDER BY FIELD(DAYNAME(appointment_date), 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$stmt = $db->query($query);
$weekly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$days = [];
$counts = [];
$days_map = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue',
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat',
    'Sunday' => 'Sun'
];

foreach ($weekly_data as $data) {
    $days[] = $days_map[$data['day']] ?? substr($data['day'], 0, 3);
    $counts[] = $data['count'];
}

// Fill in missing days with zero
$all_days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$filled_counts = [];
foreach ($all_days as $day) {
    $index = array_search($day, $days);
    $filled_counts[] = $index !== false ? $counts[$index] : 0;
}
$counts = $filled_counts;
$days = $all_days;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MedFlow Clinic Management System</title>
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
    }

    .welcome-section h1 {
        font-size: 2.2rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }

    .welcome-section p {
        color: #546e7a;
        font-size: 1rem;
        font-weight: 400;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.6s ease;
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
        margin-bottom: 8px;
    }

    .stat-trend {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.7rem;
    }

    .trend-up {
        background: #e8f5e9;
        color: #2e7d32;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 600;
    }

    .trend-down {
        background: #ffebee;
        color: #c62828;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 600;
    }

    /* Analytics Section */
    .analytics-section {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.7s ease;
    }

    .chart-card, .activity-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-header, .activity-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
    }

    .chart-header h2, .activity-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .chart-period {
        display: flex;
        gap: 8px;
    }

    .period-btn {
        padding: 6px 14px;
        background: #eceff1;
        border: 1px solid #cfd8dc;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #546e7a;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .period-btn:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .period-btn.active {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .chart-container {
        height: 200px;
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }

    .chart-bar-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }

    .chart-bar {
        width: 100%;
        background: #191970;
        border-radius: 6px 6px 0 0;
        min-height: 4px;
        transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        cursor: pointer;
        opacity: 0.9;
    }

    .chart-bar:hover {
        opacity: 1;
        background: #24248f;
    }

    .chart-bar:hover::after {
        content: attr(data-count) ' visits';
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        background: #191970;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.7rem;
        white-space: nowrap;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .chart-label {
        font-size: 0.7rem;
        color: #546e7a;
        font-weight: 500;
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
        background: #e8f5e9;
        color: #2e7d32;
    }

    /* Recent Appointments */
    .recent-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.8s ease;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
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

    .table-wrapper {
        overflow-x: auto;
        border-radius: 12px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th {
        text-align: left;
        padding: 16px 12px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #78909c;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #cfd8dc;
        background: #eceff1;
    }

    .data-table td {
        padding: 16px 12px;
        font-size: 0.9rem;
        color: #37474f;
        border-bottom: 1px solid #cfd8dc;
    }

    .patient-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .patient-avatar {
        width: 40px;
        height: 40px;
        background: #191970;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.9rem;
        color: white;
    }

    .patient-details {
        display: flex;
        flex-direction: column;
    }

    .patient-name {
        font-weight: 600;
        color: #191970;
        margin-bottom: 2px;
    }

    .patient-id {
        font-size: 0.7rem;
        color: #90a4ae;
    }

    .doctor-info {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .doctor-avatar {
        width: 30px;
        height: 30px;
        background: #b0bec5;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 600;
        color: #37474f;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
    }

    .status-scheduled {
        background: #e3f2fd;
        color: #1565c0;
    }

    .status-completed {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .status-cancelled {
        background: #ffebee;
        color: #c62828;
    }

    .action-btn-small {
        padding: 8px 14px;
        background: #eceff1;
        border: 1px solid #cfd8dc;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #191970;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .action-btn-small:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    /* Quick Actions */
    .quick-actions {
        animation: fadeInUp 0.9s ease;
    }

    .quick-actions h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
        
        .analytics-section {
            grid-template-columns: 1fr;
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
        
        .actions-grid {
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
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                    <p>Here's what's happening with your clinic today.</p>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['patients']; ?></h3>
                            <p>Total Patients</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 12%</span>
                                <span style="color: #546e7a;">vs last month</span>
                            </div>
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
                            <h3><?php echo $stats['today_appointments']; ?></h3>
                            <p>Today's Appointments</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 5%</span>
                                <span style="color: #546e7a;">vs yesterday</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M17 21V19C17 16.7909 15.2091 15 13 15H5C2.79086 15 1 16.7909 1 19V21"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21V19C22.9986 17.1771 21.765 15.5857 20 15.13"/>
                                <path d="M16 3.13C17.7699 3.58317 19.0077 5.17799 19.0077 7.005C19.0077 8.83201 17.7699 10.4268 16 10.88"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['staff']; ?></h3>
                            <p>Active Staff</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 2</span>
                                <span style="color: #546e7a;">new this month</span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                <path d="M2 17L12 22L22 17"/>
                                <path d="M2 12L12 17L22 12"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['today_visits']; ?></h3>
                            <p>Today's Visits</p>
                            <div class="stat-trend">
                                <span class="trend-up">↑ 8%</span>
                                <span style="color: #546e7a;">vs yesterday</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="analytics-section">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h2>Weekly Appointments</h2>
                            <div class="chart-period">
                                <button class="period-btn active">Week</button>
                                <button class="period-btn">Month</button>
                                <button class="period-btn">Year</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <?php 
                            $max_count = !empty($counts) ? max($counts) : 1;
                            foreach ($days as $index => $day): 
                                $count = isset($counts[$index]) ? $counts[$index] : 0;
                                $height = $max_count > 0 ? ($count / $max_count) * 150 : 20;
                                $height = max(20, $height);
                            ?>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar" style="height: <?php echo $height; ?>px;" data-count="<?php echo $count; ?>"></div>
                                <span class="chart-label"><?php echo $day; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="activity-card">
                        <div class="activity-header">
                            <h2>Recent Activity</h2>
                            <a href="#" class="view-all">View All</a>
                        </div>
                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    </svg>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">New patient registered</div>
                                    <div class="activity-time">5 minutes ago</div>
                                </div>
                                <span class="activity-status">New</span>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6V12L16 14"/>
                                    </svg>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Appointment completed</div>
                                    <div class="activity-time">15 minutes ago</div>
                                </div>
                                <span class="activity-status">Done</span>
                            </div>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
                                        <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                                        <path d="M2 17L12 22L22 17"/>
                                        <path d="M2 12L12 17L22 12"/>
                                    </svg>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">Medical record updated</div>
                                    <div class="activity-time">1 hour ago</div>
                                </div>
                                <span class="activity-status">Updated</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Appointments -->
                <div class="recent-section">
                    <div class="section-header">
                        <h2>Recent Appointments</h2>
                        <a href="appointments.php" class="view-all">View All Appointments</a>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_appointments as $appointment): ?>
                                <tr>
                                    <td>
                                        <div class="patient-info">
                                            <div class="patient-avatar">
                                                <?php echo strtoupper(substr($appointment['patient_name'], 0, 2)); ?>
                                            </div>
                                            <div class="patient-details">
                                                <span class="patient-name"><?php echo htmlspecialchars($appointment['patient_name']); ?></span>
                                                <span class="patient-id">ID: #<?php echo str_pad($appointment['patient_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="doctor-info">
                                            <div class="doctor-avatar">
                                                <?php echo strtoupper(substr($appointment['doctor_name'], 0, 2)); ?>
                                            </div>
                                            <span><?php echo htmlspecialchars($appointment['doctor_name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="action-btn-small">View</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="add-patient.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    <path d="M20 4L22 6L20 8"/>
                                    <path d="M22 4L20 6L22 8"/>
                                </svg>
                            </div>
                            <span>Add Patient</span>
                        </a>
                        <a href="schedule-appointment.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                    <path d="M8 2V6"/>
                                    <path d="M16 2V6"/>
                                </svg>
                            </div>
                            <span>Schedule</span>
                        </a>
                        <a href="medical-records.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                                </svg>
                            </div>
                            <span>Records</span>
                        </a>
                        <a href="generate-report.php" class="action-card">
                            <div class="action-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                    <path d="M3 3V21H21"/>
                                    <path d="M7 15L10 11L13 14L20 7"/>
                                </svg>
                            </div>
                            <span>Reports</span>
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

        // Chart animation
        const chartBars = document.querySelectorAll('.chart-bar');
        chartBars.forEach(bar => {
            const originalHeight = bar.style.height;
            bar.style.height = '0';
            setTimeout(() => {
                bar.style.height = originalHeight;
            }, 200);
        });

        // Period buttons
        const periodBtns = document.querySelectorAll('.period-btn');
        periodBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                periodBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Update page title based on current page
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Dashboard';
        }
    </script>
</body>
</html>