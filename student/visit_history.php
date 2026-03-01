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

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Get total records count
$count_query = "SELECT COUNT(*) as total FROM visit_history WHERE student_id = :student_id";
$count_stmt = $db->prepare($count_query);
$count_stmt->bindParam(':student_id', $student_id);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get visit history with pagination
$query = "SELECT v.*, u.full_name as attended_by_name 
          FROM visit_history v 
          LEFT JOIN users u ON v.attended_by = u.id 
          WHERE v.student_id = :student_id 
          ORDER BY v.visit_date DESC, v.visit_time DESC 
          LIMIT :offset, :records_per_page";

$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->bindParam(':records_per_page', $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$visit_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stats_query = "SELECT 
                    COUNT(*) as total_visits,
                    COUNT(DISTINCT DATE(visit_date)) as unique_dates,
                    MIN(temperature) as min_temp,
                    MAX(temperature) as max_temp,
                    AVG(temperature) as avg_temp
                FROM visit_history 
                WHERE student_id = :student_id";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':student_id', $student_id);
$stats_stmt->execute();
$summary_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get visits by month for chart
$monthly_query = "SELECT 
                    DATE_FORMAT(visit_date, '%Y-%m') as month,
                    COUNT(*) as visit_count
                  FROM visit_history 
                  WHERE student_id = :student_id 
                    AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                  GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
                  ORDER BY month DESC";
$monthly_stmt = $db->prepare($monthly_query);
$monthly_stmt->bindParam(':student_id', $student_id);
$monthly_stmt->execute();
$monthly_stats = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get disposition breakdown
$disposition_query = "SELECT 
                        disposition,
                        COUNT(*) as count
                      FROM visit_history 
                      WHERE student_id = :student_id 
                      GROUP BY disposition";
$disposition_stmt = $db->prepare($disposition_query);
$disposition_stmt->bindParam(':student_id', $student_id);
$disposition_stmt->execute();
$disposition_stats = $disposition_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get most common complaints
$complaints_query = "SELECT 
                        complaint,
                        COUNT(*) as count
                      FROM visit_history 
                      WHERE student_id = :student_id 
                      GROUP BY complaint
                      ORDER BY count DESC
                      LIMIT 5";
$complaints_stmt = $db->prepare($complaints_query);
$complaints_stmt->bindParam(':student_id', $student_id);
$complaints_stmt->execute();
$common_complaints = $complaints_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit History | ICARE Clinic Management System</title>
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
            padding: 20px;
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

        .stat-info small {
            color: #78909c;
            font-size: 0.7rem;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
            animation: fadeInUp 0.7s ease;
        }

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
        }

        .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .summary-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #191970;
        }

        .summary-header span {
            background: #eceff1;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #191970;
        }

        .summary-list {
            list-style: none;
        }

        .summary-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eceff1;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #37474f;
            font-size: 0.9rem;
        }

        .summary-label .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #191970;
        }

        .summary-value {
            font-weight: 600;
            color: #191970;
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-sent-home {
            background: #fff3e0;
            color: #e65100;
        }

        .badge-referred {
            background: #ffebee;
            color: #c62828;
        }

        .badge-admitted {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-cleared {
            background: #e8f5e9;
            color: #2e7d32;
        }

        /* Visit History Table */
        .visit-history-section {
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
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-header h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #191970;
        }

        .filter-group {
            display: flex;
            gap: 10px;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: #eceff1;
            border-radius: 12px;
            padding: 8px 15px;
            border: 1px solid #cfd8dc;
        }

        .search-box input {
            border: none;
            background: none;
            outline: none;
            font-size: 0.9rem;
            width: 200px;
            font-family: 'Inter', sans-serif;
        }

        .search-box button {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .filter-select {
            padding: 8px 15px;
            border-radius: 12px;
            border: 1px solid #cfd8dc;
            background: #eceff1;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #191970;
            outline: none;
            cursor: pointer;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .visit-table {
            width: 100%;
            border-collapse: collapse;
        }

        .visit-table th {
            text-align: left;
            padding: 15px;
            background: #eceff1;
            color: #191970;
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid #cfd8dc;
        }

        .visit-table td {
            padding: 15px;
            border-bottom: 1px solid #eceff1;
            color: #37474f;
            font-size: 0.9rem;
        }

        .visit-table tr:hover td {
            background: #f5f5f5;
        }

        .visit-table .complaint-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .temperature-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e3f2fd;
            color: #1565c0;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .temperature-high {
            background: #ffebee;
            color: #c62828;
        }

        .disposition-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .disposition-Sent {
            background: #fff3e0;
            color: #e65100;
        }

        .disposition-Referred {
            background: #ffebee;
            color: #c62828;
        }

        .disposition-Admitted {
            background: #e3f2fd;
            color: #1565c0;
        }

        .disposition-Cleared {
            background: #e8f5e9;
            color: #2e7d32;
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 10px;
            background: white;
            border: 1px solid #cfd8dc;
            border-radius: 10px;
            color: #191970;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .page-link:hover,
        .page-link.active {
            background: #191970;
            color: white;
            border-color: #191970;
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 16px;
            border: 2px dashed #cfd8dc;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            stroke: #78909c;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            color: #191970;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #78909c;
            margin-bottom: 20px;
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
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .action-card {
            background: white;
            border: 1px solid #cfd8dc;
            border-radius: 16px;
            padding: 20px;
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
            width: 56px;
            height: 56px;
            background: #191970;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: white;
            font-size: 24px;
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
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .action-card small {
            color: #78909c;
            font-size: 0.75rem;
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
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eceff1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background: white;
            border-radius: 20px 20px 0 0;
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

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #78909c;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: #191970;
        }

        .full-width {
            grid-column: 1/-1;
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
            .stats-grid,
            .summary-grid,
            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
            
            .stats-grid,
            .summary-grid,
            .actions-grid {
                grid-template-columns: 1fr;
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
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                width: 100%;
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .detail-grid {
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
                <!-- Page Header -->
                <div class="page-header">
                    <div class="header-left">
                        <h1>Clinic Visit History</h1>
                        <p>Track all your clinic visits and treatments</p>
                    </div>
                    <div class="header-right">
                        <a href="request_appointment.php" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            New Appointment
                        </a>
                        <a href="dashboard.php" class="btn btn-outline">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                <path d="M19 12H5M12 19l-7-7 7-7"/>
                            </svg>
                            Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🏥</div>
                        <div class="stat-info">
                            <h3><?php echo $summary_stats['total_visits'] ?? 0; ?></h3>
                            <p>Total Visits</p>
                            <small>All time</small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-info">
                            <h3><?php echo $summary_stats['unique_dates'] ?? 0; ?></h3>
                            <p>Visit Days</p>
                            <small>Unique dates</small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🌡️</div>
                        <div class="stat-info">
                            <h3><?php 
                                if ($summary_stats['avg_temp']) {
                                    echo number_format($summary_stats['avg_temp'], 1) . '°C';
                                } else {
                                    echo 'N/A';
                                }
                            ?></h3>
                            <p>Avg Temperature</p>
                            <small>Min: <?php echo $summary_stats['min_temp'] ?? 'N/A'; ?>° | Max: <?php echo $summary_stats['max_temp'] ?? 'N/A'; ?>°</small>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <h3><?php echo count($monthly_stats); ?></h3>
                            <p>Active Months</p>
                            <small>Last 12 months</small>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="summary-grid">
                    <!-- Monthly Summary -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h2>Monthly Summary</h2>
                            <span>Last 12 Months</span>
                        </div>
                        <ul class="summary-list">
                            <?php if (!empty($monthly_stats)): ?>
                                <?php foreach ($monthly_stats as $stat): ?>
                                    <li class="summary-item">
                                        <span class="summary-label">
                                            <span class="dot"></span>
                                            <?php echo date('F Y', strtotime($stat['month'] . '-01')); ?>
                                        </span>
                                        <span class="summary-value"><?php echo $stat['visit_count']; ?> visit<?php echo $stat['visit_count'] > 1 ? 's' : ''; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="summary-item">
                                    <span class="summary-label">No visits in the last 12 months</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Common Complaints -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h2>Common Complaints</h2>
                            <span>Top 5</span>
                        </div>
                        <ul class="summary-list">
                            <?php if (!empty($common_complaints)): ?>
                                <?php foreach ($common_complaints as $complaint): ?>
                                    <li class="summary-item">
                                        <span class="summary-label">
                                            <span class="dot" style="background: #17a2b8;"></span>
                                            <?php echo htmlspecialchars($complaint['complaint'] ?: 'Unspecified'); ?>
                                        </span>
                                        <span class="summary-value"><?php echo $complaint['count']; ?> time<?php echo $complaint['count'] > 1 ? 's' : ''; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="summary-item">
                                    <span class="summary-label">No complaints recorded</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Disposition Summary -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h2>Visit Outcomes</h2>
                            <span>By Disposition</span>
                        </div>
                        <ul class="summary-list">
                            <?php if (!empty($disposition_stats)): ?>
                                <?php foreach ($disposition_stats as $disposition): ?>
                                    <li class="summary-item">
                                        <span class="summary-label">
                                            <span class="dot" style="background: 
                                                <?php 
                                                switch($disposition['disposition']) {
                                                    case 'Sent Home': echo '#e65100'; break;
                                                    case 'Referred': echo '#c62828'; break;
                                                    case 'Admitted': echo '#1565c0'; break;
                                                    case 'Cleared': echo '#2e7d32'; break;
                                                    default: echo '#78909c';
                                                }
                                                ?>;">
                                            </span>
                                            <?php echo $disposition['disposition'] ?: 'Unspecified'; ?>
                                        </span>
                                        <span class="summary-value"><?php echo $disposition['count']; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="summary-item">
                                    <span class="summary-label">No dispositions recorded</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Quick Stats -->
                    <div class="summary-card">
                        <div class="summary-header">
                            <h2>Quick Stats</h2>
                            <span>Overview</span>
                        </div>
                        <ul class="summary-list">
                            <li class="summary-item">
                                <span class="summary-label">Average visits per month</span>
                                <span class="summary-value">
                                    <?php 
                                    if ($summary_stats['total_visits'] > 0 && $summary_stats['unique_dates'] > 0) {
                                        $months = max(1, $summary_stats['unique_dates'] / 30 * 12);
                                        echo number_format($summary_stats['total_visits'] / $months, 1);
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </span>
                            </li>
                            <li class="summary-item">
                                <span class="summary-label">Most common disposition</span>
                                <span class="summary-value">
                                    <?php 
                                    if (!empty($disposition_stats)) {
                                        $max = max(array_column($disposition_stats, 'count'));
                                        foreach ($disposition_stats as $d) {
                                            if ($d['count'] == $max) {
                                                echo $d['disposition'] ?: 'Unspecified';
                                                break;
                                            }
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </li>
                            <li class="summary-item">
                                <span class="summary-label">Last visit</span>
                                <span class="summary-value">
                                    <?php 
                                    if (!empty($visit_history)) {
                                        echo date('M d, Y', strtotime($visit_history[0]['visit_date']));
                                    } else {
                                        echo 'No visits';
                                    }
                                    ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Visit History Table -->
                <div class="visit-history-section">
                    <div class="section-header">
                        <h2>Complete Visit History</h2>
                        <div class="filter-group">
                            <div class="search-box">
                                <input type="text" id="searchInput" placeholder="Search complaints..." onkeyup="filterTable()">
                                <button type="button">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <circle cx="11" cy="11" r="8"/>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                    </svg>
                                </button>
                            </div>
                            <select class="filter-select" id="dispositionFilter" onchange="filterTable()">
                                <option value="">All Dispositions</option>
                                <option value="Sent Home">Sent Home</option>
                                <option value="Referred">Referred</option>
                                <option value="Admitted">Admitted</option>
                                <option value="Cleared">Cleared</option>
                            </select>
                        </div>
                    </div>

                    <?php if (!empty($visit_history)): ?>
                        <div class="table-responsive">
                            <table class="visit-table" id="visitTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Complaint</th>
                                        <th>Temperature</th>
                                        <th>Blood Pressure</th>
                                        <th>Heart Rate</th>
                                        <th>Treatment Given</th>
                                        <th>Attended By</th>
                                        <th>Disposition</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visit_history as $visit): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($visit['visit_date'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($visit['visit_time'])); ?></td>
                                            <td class="complaint-cell" title="<?php echo htmlspecialchars($visit['complaint']); ?>">
                                                <?php echo htmlspecialchars($visit['complaint'] ?: 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['temperature']): ?>
                                                    <span class="temperature-badge <?php echo $visit['temperature'] > 37.5 ? 'temperature-high' : ''; ?>">
                                                        <?php echo $visit['temperature']; ?>°C
                                                    </span>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($visit['blood_pressure'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($visit['heart_rate'] ?: 'N/A'); ?></td>
                                            <td class="complaint-cell" title="<?php echo htmlspecialchars($visit['treatment_given']); ?>">
                                                <?php echo htmlspecialchars($visit['treatment_given'] ?: 'N/A'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($visit['attended_by_name'] ?: 'N/A'); ?></td>
                                            <td>
                                                <span class="disposition-badge disposition-<?php echo str_replace(' ', '', $visit['disposition']); ?>">
                                                    <?php echo $visit['disposition'] ?: 'N/A'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button onclick="viewVisitDetails(<?php echo htmlspecialchars(json_encode($visit)); ?>)" class="btn-outline" style="padding: 6px 12px; font-size: 0.8rem;">
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <a href="?page=1" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">«</a>
                                <a href="?page=<?php echo $page - 1; ?>" class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">‹</a>
                                
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                
                                if ($start > 1) {
                                    echo '<a href="?page=1" class="page-link">1</a>';
                                    if ($start > 2) echo '<span class="page-link">...</span>';
                                }
                                
                                for ($i = $start; $i <= $end; $i++) {
                                    echo '<a href="?page=' . $i . '" class="page-link ' . ($i == $page ? 'active' : '') . '">' . $i . '</a>';
                                }
                                
                                if ($end < $total_pages) {
                                    if ($end < $total_pages - 1) echo '<span class="page-link">...</span>';
                                    echo '<a href="?page=' . $total_pages . '" class="page-link">' . $total_pages . '</a>';
                                }
                                ?>
                                
                                <a href="?page=<?php echo $page + 1; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">›</a>
                                <a href="?page=<?php echo $total_pages; ?>" class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">»</a>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <h3>No Visit History Yet</h3>
                            <p>You haven't visited the clinic yet. When you do, your visit records will appear here.</p>
                            <a href="request_appointment.php" class="btn btn-primary">Schedule a Visit</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="actions-grid">
                        <a href="request_appointment.php" class="action-card">
                            <div class="action-icon">📅</div>
                            <span>Request Appointment</span>
                            <small>Schedule a clinic visit</small>
                        </a>
                        
                        <a href="request_clearance.php" class="action-card">
                            <div class="action-icon">✅</div>
                            <span>Request Clearance</span>
                            <small>Get medical clearance</small>
                        </a>
                        
                        <a href="profile.php" class="action-card">
                            <div class="action-icon">👤</div>
                            <span>My Profile</span>
                            <small>View personal info</small>
                        </a>

                        <a href="medical_records.php" class="action-card">
                            <div class="action-icon">📋</div>
                            <span>Medical Records</span>
                            <small>View health records</small>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Visit Details Modal -->
    <div class="modal" id="visitModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Visit Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be populated by JavaScript -->
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
            pageTitle.textContent = 'Visit History';
        }

        // Table filter function
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const dispositionFilter = document.getElementById('dispositionFilter').value;
            const table = document.getElementById('visitTable');
            
            if (!table) return;
            
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const complaintCell = rows[i].getElementsByTagName('td')[2];
                const dispositionCell = rows[i].getElementsByTagName('td')[8];
                
                if (complaintCell && dispositionCell) {
                    const complaintText = complaintCell.textContent || complaintCell.innerText;
                    const dispositionText = dispositionCell.textContent || dispositionCell.innerText;
                    
                    const matchesSearch = complaintText.toUpperCase().indexOf(filter) > -1;
                    const matchesDisposition = dispositionFilter === '' || dispositionText.trim() === dispositionFilter;
                    
                    if (matchesSearch && matchesDisposition) {
                        rows[i].style.display = '';
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }
        }

        // View visit details modal
        function viewVisitDetails(visit) {
            const modal = document.getElementById('visitModal');
            const modalBody = document.getElementById('modalBody');
            
            const temperatureClass = visit.temperature > 37.5 ? 'temperature-high' : '';
            
            modalBody.innerHTML = `
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Visit Date</div>
                        <div class="detail-value">${new Date(visit.visit_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Visit Time</div>
                        <div class="detail-value">${new Date('1970-01-01T' + visit.visit_time).toLocaleTimeString('en-US', { hour: 'numeric', minute: 'numeric', hour12: true })}</div>
                    </div>
                    <div class="detail-item full-width">
                        <div class="detail-label">Complaint</div>
                        <div class="detail-value">${visit.complaint || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Temperature</div>
                        <div class="detail-value"><span class="temperature-badge ${temperatureClass}">${visit.temperature ? visit.temperature + '°C' : 'N/A'}</span></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Blood Pressure</div>
                        <div class="detail-value">${visit.blood_pressure || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Heart Rate</div>
                        <div class="detail-value">${visit.heart_rate ? visit.heart_rate + ' bpm' : 'N/A'}</div>
                    </div>
                    <div class="detail-item full-width">
                        <div class="detail-label">Treatment Given</div>
                        <div class="detail-value">${visit.treatment_given || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Attended By</div>
                        <div class="detail-value">${visit.attended_by_name || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Disposition</div>
                        <div class="detail-value"><span class="disposition-badge disposition-${visit.disposition ? visit.disposition.replace(' ', '') : ''}">${visit.disposition || 'N/A'}</span></div>
                    </div>
                    <div class="detail-item full-width">
                        <div class="detail-label">Notes</div>
                        <div class="detail-value">${visit.notes || 'No additional notes'}</div>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('visitModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('visitModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Export to CSV function
        function exportToCSV() {
            const table = document.getElementById('visitTable');
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length - 1; j++) { // Exclude Actions column
                    let data = cols[j].innerText.replace(/,/g, ' '); // Remove commas to avoid CSV issues
                    row.push('"' + data + '"');
                }
                
                csv.push(row.join(','));
            }
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'visit_history.csv';
            a.click();
        }
    </script>
</body>
</html>