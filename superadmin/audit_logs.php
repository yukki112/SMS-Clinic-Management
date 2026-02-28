<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$user_filter = isset($_GET['user']) ? $_GET['user'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build the query to get audit logs from multiple tables
$query = "SELECT * FROM (
            -- Request Audit Log
            SELECT 
                'request' as source,
                id,
                action,
                user as username,
                notes as description,
                CONCAT('Request ID: ', request_id, ' | Qty: ', quantity) as details,
                created_at
            FROM request_audit_log
            WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- User Activities (login, logout from user_sessions)
            SELECT 
                'session' as source,
                us.id,
                CASE 
                    WHEN us.session_token IS NOT NULL THEN 'login'
                    ELSE 'session_activity'
                END as action,
                u.username,
                CONCAT('IP: ', us.ip_address, ' | User Agent: ', LEFT(us.user_agent, 50)) as description,
                CONCAT('Session active') as details,
                us.created_at
            FROM user_sessions us
            JOIN users u ON us.user_id = u.id
            WHERE DATE(us.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- New User Registrations
            SELECT 
                'user' as source,
                id,
                'user_registration' as action,
                username,
                CONCAT('New user registered: ', full_name, ' (', role, ')') as description,
                email as details,
                created_at
            FROM users
            WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Incidents (as system events)
            SELECT 
                'incident' as source,
                i.id,
                CONCAT('incident_', LOWER(incident_type)) as action,
                u.username,
                CONCAT('Incident: ', i.description) as description,
                CONCAT('Student: ', i.student_name, ' | Location: ', i.location) as details,
                i.created_at
            FROM incidents i
            LEFT JOIN users u ON i.created_by = u.id
            WHERE DATE(i.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Medicine Requests
            SELECT 
                'medicine_request' as source,
                mr.id,
                CONCAT('medicine_', mr.status) as action,
                u.username,
                CONCAT('Medicine request: ', mr.item_name) as description,
                CONCAT('Qty: ', mr.quantity_requested, ' | Status: ', mr.status) as details,
                mr.requested_date as created_at
            FROM medicine_requests mr
            LEFT JOIN users u ON mr.requested_by = u.id
            WHERE DATE(mr.requested_date) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Dispensing Log
            SELECT 
                'dispense' as source,
                dl.id,
                'medicine_dispensed' as action,
                u.username,
                CONCAT('Dispensed: ', dl.item_name, ' to ', dl.student_name) as description,
                CONCAT('Qty: ', dl.quantity, ' ', dl.unit, ' | Reason: ', dl.reason) as details,
                dl.dispensed_date as created_at
            FROM dispensing_log dl
            LEFT JOIN users u ON dl.dispensed_by = u.id
            WHERE DATE(dl.dispensed_date) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Clearance Requests
            SELECT 
                'clearance' as source,
                cr.id,
                CONCAT('clearance_', LOWER(cr.status)) as action,
                u.username,
                CONCAT('Clearance: ', cr.clearance_type) as description,
                CONCAT('Student: ', cr.student_name, ' | Code: ', cr.clearance_code) as details,
                cr.created_at
            FROM clearance_requests cr
            LEFT JOIN users u ON cr.created_by = u.id
            WHERE DATE(cr.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Medical Certificates
            SELECT 
                'certificate' as source,
                mc.id,
                'certificate_issued' as action,
                u.username,
                CONCAT('Certificate issued: ', mc.certificate_type) as description,
                CONCAT('Student: ', mc.student_name, ' | Code: ', mc.certificate_code) as details,
                mc.created_at
            FROM medical_certificates mc
            LEFT JOIN users u ON mc.issuer_id = u.id
            WHERE DATE(mc.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Physical Exams
            SELECT 
                'physical_exam' as source,
                pe.id,
                'physical_exam' as action,
                u.username,
                CONCAT('Physical exam for: ', pe.student_name) as description,
                CONCAT('Height: ', pe.height, 'cm | Weight: ', pe.weight, 'kg | BMI: ', pe.bmi) as details,
                pe.created_at
            FROM physical_exam_records pe
            LEFT JOIN users u ON u.full_name = pe.examined_by
            WHERE DATE(pe.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Clinic Stock Updates
            SELECT 
                'stock' as source,
                cs.id,
                'stock_update' as action,
                'System' as username,
                CONCAT('Stock updated: ', cs.item_name) as description,
                CONCAT('Qty: ', cs.quantity, ' ', cs.unit, ' | Min: ', cs.minimum_stock) as details,
                cs.created_at
            FROM clinic_stock cs
            WHERE DATE(cs.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Visit History
            SELECT 
                'visit' as source,
                vh.id,
                'clinic_visit' as action,
                u.username,
                CONCAT('Visit: ', vh.student_id) as description,
                CONCAT('Complaint: ', vh.complaint, ' | Temp: ', vh.temperature, '°C') as details,
                vh.created_at
            FROM visit_history vh
            LEFT JOIN users u ON vh.attended_by = u.id
            WHERE DATE(vh.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Emergency Cases
            SELECT 
                'emergency' as source,
                ec.id,
                'emergency_case' as action,
                u.username,
                CONCAT('Emergency case for student: ', ec.student_id) as description,
                CONCAT('Response: ', ec.response_time, ' | Ambulance: ', ec.ambulance_called) as details,
                ec.created_at
            FROM emergency_cases ec
            LEFT JOIN users u ON u.username = 'System'
            WHERE DATE(ec.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Password Confirmation Tokens (password changes)
            SELECT 
                'password' as source,
                pct.id,
                'password_change' as action,
                u.username,
                'Password changed or reset' as description,
                CONCAT('Token used: ', CASE WHEN pct.used = 1 THEN 'Yes' ELSE 'No' END) as details,
                pct.created_at
            FROM password_confirmation_tokens pct
            JOIN users u ON pct.user_id = u.id
            WHERE DATE(pct.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Deworming Records
            SELECT 
                'deworming' as source,
                dr.id,
                'deworming_administered' as action,
                dr.administered_by as username,
                CONCAT('Deworming: ', dr.medicine_name, ' for ', dr.student_name) as description,
                CONCAT('Dosage: ', dr.dosage, ' | Next dose: ', dr.next_dose_date) as details,
                dr.created_at
            FROM deworming_records dr
            WHERE DATE(dr.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Health Screening Records
            SELECT 
                'screening' as source,
                hsr.id,
                'health_screening' as action,
                hsr.screened_by as username,
                CONCAT('Screening: ', hsr.screening_type) as description,
                CONCAT('Student: ', hsr.student_name, ' | Cleared: ', hsr.cleared_for_participation) as details,
                hsr.created_at
            FROM health_screening_records hsr
            WHERE DATE(hsr.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Fit to Return Slips
            SELECT 
                'fit_return' as source,
                ftr.id,
                'fit_to_return_issued' as action,
                u.username,
                CONCAT('Fit to return slip for: ', ftr.student_name) as description,
                CONCAT('Absence: ', ftr.absence_days, ' days | Fit: ', ftr.fit_to_return) as details,
                ftr.created_at
            FROM fit_to_return_slips ftr
            LEFT JOIN users u ON ftr.issuer_id = u.id
            WHERE DATE(ftr.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Vaccination Records
            SELECT 
                'vaccination' as source,
                vr.id,
                'vaccination_administered' as action,
                vr.administered_by as username,
                CONCAT('Vaccination: ', vr.vaccine_name, ' for ', vr.student_name) as description,
                CONCAT('Dose: ', vr.dose_number, ' | Batch: ', vr.batch_number) as details,
                vr.created_at
            FROM vaccination_records vr
            WHERE DATE(vr.created_at) BETWEEN :date_from AND :date_to
            
            UNION ALL
            
            -- Parent Notifications
            SELECT 
                'notification' as source,
                pn.id,
                'parent_notification' as action,
                pn.called_by as username,
                CONCAT('Parent notified for incident') as description,
                CONCAT('Parent: ', pn.parent_name, ' | Response: ', pn.response) as details,
                pn.created_at
            FROM parent_notifications pn
            WHERE DATE(pn.created_at) BETWEEN :date_from AND :date_to
            
        ) AS combined_logs
        WHERE 1=1";

$count_query = "SELECT COUNT(*) as total FROM (
            SELECT id FROM request_audit_log WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT us.id FROM user_sessions us WHERE DATE(us.created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM users WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM incidents WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM medicine_requests WHERE DATE(requested_date) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM dispensing_log WHERE DATE(dispensed_date) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM clearance_requests WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM medical_certificates WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM physical_exam_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM clinic_stock WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM visit_history WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM emergency_cases WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM password_confirmation_tokens WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM deworming_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM health_screening_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM fit_to_return_slips WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM vaccination_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
            UNION ALL
            SELECT id FROM parent_notifications WHERE DATE(created_at) BETWEEN :date_from AND :date_to
        ) AS count_table";

// Apply filters
$params = [
    ':date_from' => $date_from,
    ':date_to' => $date_to
];

if (!empty($action_filter)) {
    $query .= " AND action LIKE :action";
    $params[':action'] = "%$action_filter%";
}

if (!empty($user_filter)) {
    $query .= " AND username LIKE :username";
    $params[':username'] = "%$user_filter%";
}

if (!empty($search)) {
    $query .= " AND (description LIKE :search OR details LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY created_at DESC LIMIT :offset, :limit";

// Get total count
$count_stmt = $db->prepare($count_query);
$count_stmt->bindParam(':date_from', $date_from);
$count_stmt->bindParam(':date_to', $date_to);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Get audit logs
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    if ($key == ':offset' || $key == ':limit') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM (
                    SELECT action FROM request_audit_log
                    UNION ALL
                    SELECT 'login' as action FROM user_sessions
                    UNION ALL
                    SELECT 'user_registration' as action FROM users
                    UNION ALL
                    SELECT 'incident_incident' as action FROM incidents
                    UNION ALL
                    SELECT 'incident_minor_injury' as action FROM incidents
                    UNION ALL
                    SELECT 'incident_emergency' as action FROM incidents
                    UNION ALL
                    SELECT 'medicine_pending' as action FROM medicine_requests
                    UNION ALL
                    SELECT 'medicine_approved' as action FROM medicine_requests
                    UNION ALL
                    SELECT 'medicine_released' as action FROM medicine_requests
                    UNION ALL
                    SELECT 'medicine_dispensed' as action FROM dispensing_log
                    UNION ALL
                    SELECT 'clearance_pending' as action FROM clearance_requests
                    UNION ALL
                    SELECT 'clearance_approved' as action FROM clearance_requests
                    UNION ALL
                    SELECT 'certificate_issued' as action FROM medical_certificates
                    UNION ALL
                    SELECT 'physical_exam' as action FROM physical_exam_records
                    UNION ALL
                    SELECT 'stock_update' as action FROM clinic_stock
                    UNION ALL
                    SELECT 'clinic_visit' as action FROM visit_history
                    UNION ALL
                    SELECT 'emergency_case' as action FROM emergency_cases
                    UNION ALL
                    SELECT 'password_change' as action FROM password_confirmation_tokens
                    UNION ALL
                    SELECT 'deworming_administered' as action FROM deworming_records
                    UNION ALL
                    SELECT 'health_screening' as action FROM health_screening_records
                    UNION ALL
                    SELECT 'fit_to_return_issued' as action FROM fit_to_return_slips
                    UNION ALL
                    SELECT 'vaccination_administered' as action FROM vaccination_records
                    UNION ALL
                    SELECT 'parent_notification' as action FROM parent_notifications
                ) AS actions ORDER BY action";
$actions_stmt = $db->query($actions_query);
$actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique users for filter dropdown
$users_query = "SELECT DISTINCT username FROM (
                    SELECT user as username FROM request_audit_log
                    UNION ALL
                    SELECT username FROM user_sessions us JOIN users u ON us.user_id = u.id
                    UNION ALL
                    SELECT username FROM users
                    UNION ALL
                    SELECT username FROM incidents i LEFT JOIN users u ON i.created_by = u.id
                    UNION ALL
                    SELECT u.username FROM medicine_requests mr LEFT JOIN users u ON mr.requested_by = u.id
                    UNION ALL
                    SELECT u.username FROM dispensing_log dl LEFT JOIN users u ON dl.dispensed_by = u.id
                    UNION ALL
                    SELECT u.username FROM clearance_requests cr LEFT JOIN users u ON cr.created_by = u.id
                    UNION ALL
                    SELECT u.username FROM medical_certificates mc LEFT JOIN users u ON mc.issuer_id = u.id
                    UNION ALL
                    SELECT examined_by as username FROM physical_exam_records
                    UNION ALL
                    SELECT 'System' as username
                    UNION ALL
                    SELECT username FROM visit_history vh LEFT JOIN users u ON vh.attended_by = u.id
                    UNION ALL
                    SELECT username FROM password_confirmation_tokens pct JOIN users u ON pct.user_id = u.id
                    UNION ALL
                    SELECT administered_by as username FROM deworming_records
                    UNION ALL
                    SELECT screened_by as username FROM health_screening_records
                    UNION ALL
                    SELECT username FROM fit_to_return_slips ftr LEFT JOIN users u ON ftr.issuer_id = u.id
                    UNION ALL
                    SELECT administered_by as username FROM vaccination_records
                    UNION ALL
                    SELECT called_by as username FROM parent_notifications
                ) AS users WHERE username IS NOT NULL AND username != '' GROUP BY username ORDER BY username";
$users_stmt = $db->query($users_query);
$users = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get summary statistics
$summary = [];

// Total logs today
$today = date('Y-m-d');
$query_today = "SELECT COUNT(*) as total FROM (
                    SELECT id FROM request_audit_log WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT us.id FROM user_sessions us WHERE DATE(us.created_at) = :today
                    UNION ALL
                    SELECT id FROM users WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM incidents WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM medicine_requests WHERE DATE(requested_date) = :today
                    UNION ALL
                    SELECT id FROM dispensing_log WHERE DATE(dispensed_date) = :today
                    UNION ALL
                    SELECT id FROM clearance_requests WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM medical_certificates WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM physical_exam_records WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM clinic_stock WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM visit_history WHERE DATE(created_at) = :today
                    UNION ALL
                    SELECT id FROM emergency_cases WHERE DATE(created_at) = :today
                ) AS today_logs";
$stmt_today = $db->prepare($query_today);
$stmt_today->bindParam(':today', $today);
$stmt_today->execute();
$summary['today'] = $stmt_today->fetch(PDO::FETCH_ASSOC)['total'];

// Logs by source
$query_sources = "SELECT source, COUNT(*) as count FROM (
                    SELECT 'request' as source FROM request_audit_log WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'session' as source FROM user_sessions WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'user' as source FROM users WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'incident' as source FROM incidents WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'medicine_request' as source FROM medicine_requests WHERE DATE(requested_date) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'dispense' as source FROM dispensing_log WHERE DATE(dispensed_date) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'clearance' as source FROM clearance_requests WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'certificate' as source FROM medical_certificates WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'physical_exam' as source FROM physical_exam_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'stock' as source FROM clinic_stock WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'visit' as source FROM visit_history WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT 'emergency' as source FROM emergency_cases WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                ) AS source_counts GROUP BY source ORDER BY count DESC";
$stmt_sources = $db->prepare($query_sources);
$stmt_sources->bindParam(':date_from', $date_from);
$stmt_sources->bindParam(':date_to', $date_to);
$stmt_sources->execute();
$summary['by_source'] = $stmt_sources->fetchAll(PDO::FETCH_ASSOC);

// Most active users
$query_active_users = "SELECT username, COUNT(*) as count FROM (
                    SELECT user as username FROM request_audit_log WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM user_sessions us JOIN users u ON us.user_id = u.id WHERE DATE(us.created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT username FROM users WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM incidents i LEFT JOIN users u ON i.created_by = u.id WHERE DATE(i.created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM medicine_requests mr LEFT JOIN users u ON mr.requested_by = u.id WHERE DATE(mr.requested_date) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM dispensing_log dl LEFT JOIN users u ON dl.dispensed_by = u.id WHERE DATE(dl.dispensed_date) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM clearance_requests cr LEFT JOIN users u ON cr.created_by = u.id WHERE DATE(cr.created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM medical_certificates mc LEFT JOIN users u ON mc.issuer_id = u.id WHERE DATE(mc.created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT examined_by as username FROM physical_exam_records WHERE DATE(created_at) BETWEEN :date_from AND :date_to
                    UNION ALL
                    SELECT u.username FROM visit_history vh LEFT JOIN users u ON vh.attended_by = u.id WHERE DATE(vh.created_at) BETWEEN :date_from AND :date_to
                ) AS user_counts WHERE username IS NOT NULL AND username != '' GROUP BY username ORDER BY count DESC LIMIT 5";
$stmt_active = $db->prepare($query_active_users);
$stmt_active->bindParam(':date_from', $date_from);
$stmt_active->bindParam(':date_to', $date_to);
$stmt_active->execute();
$summary['active_users'] = $stmt_active->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - Super Admin | MedFlow Clinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 30px;
        animation: fadeInUp 0.5s ease;
    }

    .page-header h1 {
        font-size: 2.2rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }

    .page-header p {
        color: #546e7a;
        font-size: 1rem;
        font-weight: 400;
    }

    .header-actions {
        display: flex;
        gap: 12px;
    }

    .btn {
        padding: 12px 24px;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: #191970;
        color: white;
    }

    .btn-primary:hover {
        background: #24248f;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(25, 25, 112, 0.2);
    }

    .btn-success {
        background: #2e7d32;
        color: white;
    }

    .btn-success:hover {
        background: #3a8e3f;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(46, 125, 50, 0.2);
    }

    .btn-danger {
        background: #c62828;
        color: white;
    }

    .btn-danger:hover {
        background: #b71c1c;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(198, 40, 40, 0.2);
    }

    .btn-secondary {
        background: #eceff1;
        color: #191970;
        border: 1px solid #cfd8dc;
    }

    .btn-secondary:hover {
        background: #cfd8dc;
        transform: translateY(-2px);
    }

    /* Filter Section */
    .filter-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 0.6s ease;
    }

    .filter-section h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .filter-group label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
    }

    .filter-group input,
    .filter-group select {
        padding: 12px 16px;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .filter-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
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

    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.8s ease;
    }

    .chart-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .chart-card h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .chart-container {
        height: 200px;
        position: relative;
    }

    /* Activity Summary */
    .activity-summary {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 30px;
        animation: fadeInUp 0.9s ease;
    }

    .summary-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .summary-card h3 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 20px;
    }

    .summary-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .summary-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #eceff1;
    }

    .summary-item:last-child {
        border-bottom: none;
    }

    .summary-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        color: #37474f;
    }

    .source-badge {
        width: 10px;
        height: 10px;
        border-radius: 3px;
    }

    .summary-value {
        font-weight: 600;
        color: #191970;
    }

    .progress-bar {
        width: 100%;
        height: 6px;
        background: #eceff1;
        border-radius: 3px;
        margin-top: 8px;
    }

    .progress-fill {
        height: 100%;
        background: #191970;
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    /* Audit Logs Table */
    .logs-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
        animation: fadeInUp 1s ease;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .section-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .record-count {
        padding: 6px 14px;
        background: #eceff1;
        border-radius: 20px;
        font-size: 0.9rem;
        color: #191970;
        font-weight: 500;
    }

    .table-wrapper {
        overflow-x: auto;
        border-radius: 12px;
        margin-bottom: 20px;
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
        white-space: nowrap;
    }

    .data-table td {
        padding: 16px 12px;
        font-size: 0.9rem;
        color: #37474f;
        border-bottom: 1px solid #cfd8dc;
    }

    .log-source {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .source-request { background: #e8eaf6; color: #191970; }
    .source-session { background: #e0f2fe; color: #0284c7; }
    .source-user { background: #e8f5e9; color: #2e7d32; }
    .source-incident { background: #ffebee; color: #c62828; }
    .source-medicine_request { background: #fff3cd; color: #ff9800; }
    .source-dispense { background: #f3e5f5; color: #7b1fa2; }
    .source-clearance { background: #e0f2fe; color: #0284c7; }
    .source-certificate { background: #fce4ec; color: #c2185b; }
    .source-physical_exam { background: #e8f5e9; color: #2e7d32; }
    .source-stock { background: #fff3e0; color: #f57c00; }
    .source-visit { background: #e1f5fe; color: #0288d1; }
    .source-emergency { background: #ffebee; color: #d32f2f; }

    .action-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #eceff1;
        color: #37474f;
    }

    .timestamp {
        font-size: 0.8rem;
        color: #78909c;
        white-space: nowrap;
    }

    .user-cell {
        font-weight: 600;
        color: #191970;
    }

    .description-cell {
        max-width: 300px;
    }

    .details-cell {
        font-size: 0.8rem;
        color: #78909c;
        max-width: 250px;
    }

    .no-data {
        text-align: center;
        padding: 40px;
        color: #546e7a;
        font-style: italic;
    }

    /* Pagination */
    .pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 20px;
    }

    .page-link {
        padding: 8px 14px;
        border-radius: 8px;
        background: white;
        border: 1px solid #cfd8dc;
        color: #191970;
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .page-link:hover {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .page-link.active {
        background: #191970;
        color: white;
        border-color: #191970;
    }

    .page-link.disabled {
        opacity: 0.5;
        pointer-events: none;
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
        
        .charts-grid {
            grid-template-columns: 1fr;
        }
        
        .activity-summary {
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
        
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .filter-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
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
                <div class="page-header">
                    <div>
                        <h1>Audit Logs</h1>
                        <p>Comprehensive system activity tracking and monitoring</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-secondary" onclick="resetFilters()">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6H21M6 12H18M10 18H14" stroke-linecap="round"/>
                            </svg>
                            Reset Filters
                        </button>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <h2>Filter Audit Logs</h2>
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label for="date_from">Date From</label>
                                <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="filter-group">
                                <label for="date_to">Date To</label>
                                <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="filter-group">
                                <label for="action">Action Type</label>
                                <select name="action" id="action">
                                    <option value="">All Actions</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter == $action ? 'selected' : ''; ?>>
                                            <?php echo ucwords(str_replace('_', ' ', $action)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="user">User</label>
                                <select name="user" id="user">
                                    <option value="">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo htmlspecialchars($user); ?>" <?php echo $user_filter == $user ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="search">Search</label>
                                <input type="text" name="search" id="search" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21L16.5 16.5"/>
                                </svg>
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($total_records); ?></h3>
                            <p>Total Logs</p>
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
                            <h3><?php echo number_format($summary['today']); ?></h3>
                            <p>Today's Logs</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($summary['by_source']); ?></h3>
                            <p>Activity Types</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                <path d="M20 21V19C20 16.7909 18.2091 15 16 15H8C5.79086 15 4 16.7909 4 19V21"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo count($summary['active_users']); ?></h3>
                            <p>Active Users</p>
                        </div>
                    </div>
                </div>

                <!-- Charts and Summary -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>Logs by Source</h3>
                        <div class="chart-container">
                            <canvas id="sourceChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>Most Active Users</h3>
                        <div class="chart-container">
                            <canvas id="usersChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="activity-summary">
                    <div class="summary-card">
                        <h3>Activity Distribution</h3>
                        <div class="summary-list">
                            <?php 
                            $total_source = array_sum(array_column($summary['by_source'], 'count'));
                            foreach ($summary['by_source'] as $index => $source): 
                                if ($index < 5): // Show top 5
                                    $percentage = $total_source > 0 ? round(($source['count'] / $total_source) * 100) : 0;
                            ?>
                            <div class="summary-item">
                                <span class="summary-label">
                                    <span class="source-badge" style="background: <?php 
                                        echo $index == 0 ? '#191970' : 
                                            ($index == 1 ? '#4caf50' : 
                                            ($index == 2 ? '#ff9800' : 
                                            ($index == 3 ? '#f44336' : '#9c27b0'))); ?>">
                                    </span>
                                    <?php echo ucfirst($source['source']); ?>
                                </span>
                                <span class="summary-value"><?php echo $source['count']; ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <div class="summary-card">
                        <h3>Recent Activity by User</h3>
                        <div class="summary-list">
                            <?php foreach ($summary['active_users'] as $user): ?>
                            <div class="summary-item">
                                <span class="summary-label">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#191970" stroke-width="1.5">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                    </svg>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </span>
                                <span class="summary-value"><?php echo $user['count']; ?> activities</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Audit Logs Table -->
                <div class="logs-section">
                    <div class="section-header">
                        <h2>System Audit Trail</h2>
                        <span class="record-count">Showing <?php echo count($audit_logs); ?> of <?php echo number_format($total_records); ?> records</span>
                    </div>
                    <div class="table-wrapper">
                        <?php if (empty($audit_logs)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#546e7a" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8V12L12 16"/>
                                </svg>
                                <p style="margin-top: 16px;">No audit logs found for the selected filters.</p>
                            </div>
                        <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Source</th>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Description</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td class="timestamp">
                                        <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td>
                                        <span class="log-source source-<?php echo $log['source']; ?>">
                                            <?php echo ucfirst($log['source']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="action-badge">
                                            <?php echo ucwords(str_replace('_', ' ', $log['action'])); ?>
                                        </span>
                                    </td>
                                    <td class="user-cell">
                                        <?php echo htmlspecialchars($log['username'] ?? 'System'); ?>
                                    </td>
                                    <td class="description-cell">
                                        <?php echo htmlspecialchars($log['description'] ?? ''); ?>
                                    </td>
                                    <td class="details-cell">
                                        <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=1&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&action=<?php echo urlencode($action_filter); ?>&user=<?php echo urlencode($user_filter); ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 12H5M12 5L5 12L12 19"/>
                            </svg>
                        </a>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <a href="?page=<?php echo $i; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&action=<?php echo urlencode($action_filter); ?>&user=<?php echo urlencode($user_filter); ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <a href="?page=<?php echo $total_pages; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&action=<?php echo urlencode($action_filter); ?>&user=<?php echo urlencode($user_filter); ?>&search=<?php echo urlencode($search); ?>" 
                           class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12H19M12 5L19 12L12 19"/>
                            </svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseSidebar');
        
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            });
        }

        // Reset filters
        function resetFilters() {
            document.getElementById('date_from').value = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
            document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('action').value = '';
            document.getElementById('user').value = '';
            document.getElementById('search').value = '';
            document.getElementById('filterForm').submit();
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Source Chart
            const sourceCtx = document.getElementById('sourceChart').getContext('2d');
            const sourceLabels = <?php echo json_encode(array_slice(array_column($summary['by_source'], 'source'), 0, 5)); ?>;
            const sourceData = <?php echo json_encode(array_slice(array_column($summary['by_source'], 'count'), 0, 5)); ?>;
            
            new Chart(sourceCtx, {
                type: 'doughnut',
                data: {
                    labels: sourceLabels.map(label => label.charAt(0).toUpperCase() + label.slice(1)),
                    datasets: [{
                        data: sourceData,
                        backgroundColor: ['#191970', '#4caf50', '#ff9800', '#f44336', '#9c27b0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        }
                    },
                    cutout: '60%'
                }
            });

            // Users Chart
            const usersCtx = document.getElementById('usersChart').getContext('2d');
            const userLabels = <?php echo json_encode(array_column($summary['active_users'], 'username')); ?>;
            const userData = <?php echo json_encode(array_column($summary['active_users'], 'count')); ?>;
            
            new Chart(usersCtx, {
                type: 'bar',
                data: {
                    labels: userLabels,
                    datasets: [{
                        label: 'Activities',
                        data: userData,
                        backgroundColor: '#191970',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        });

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Audit Logs';
        }

        // Form validation
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (dateFrom && dateTo && dateFrom > dateTo) {
                e.preventDefault();
                alert('Date From cannot be later than Date To');
            }
        });
    </script>
</body>
</html>