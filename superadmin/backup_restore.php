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

// Handle database backup download
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    $backup_type = isset($_GET['type']) ? $_GET['type'] : 'full';
    $tables = isset($_GET['tables']) ? $_GET['tables'] : 'all';
    
    // Start output buffering
    ob_start();
    
    // Get all tables
    $stmt = $db->query("SHOW TABLES");
    $all_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Filter tables if specific tables requested
    if ($tables !== 'all') {
        $table_list = explode(',', $tables);
    } else {
        $table_list = $all_tables;
    }
    
    // Generate backup content
    $backup_content = "-- Database Backup for Clinic Management System\n";
    $backup_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $backup_content .= "-- Backup Type: " . ucfirst($backup_type) . "\n";
    $backup_content .= "-- PHP Version: " . phpversion() . "\n\n";
    $backup_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    
    foreach ($table_list as $table) {
        // Get create table syntax
        $stmt = $db->query("SHOW CREATE TABLE `$table`");
        $create_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $backup_content .= "-- Table structure for table `$table`\n";
        $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
        $backup_content .= $create_row['Create Table'] . ";\n\n";
        
        // Get data if full backup or table data requested
        if ($backup_type === 'full' || $backup_type === 'data') {
            $backup_content .= "-- Dumping data for table `$table`\n";
            
            $stmt = $db->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $backup_content .= "INSERT INTO `$table` VALUES \n";
                
                $values = [];
                foreach ($rows as $row) {
                    $row_values = [];
                    foreach ($row as $value) {
                        if (is_null($value)) {
                            $row_values[] = 'NULL';
                        } else {
                            $row_values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = "(" . implode(', ', $row_values) . ")";
                }
                
                $backup_content .= implode(",\n", $values) . ";\n\n";
            }
        }
    }
    
    $backup_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    
    // Set headers for download
    $filename = 'clinic_backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($backup_content));
    
    // Clear output buffer
    ob_end_clean();
    
    // Output the backup content
    echo $backup_content;
    
    // Log the backup action
    logAudit($db, $_SESSION['user_id'], 'database_backup', "Downloaded $backup_type backup");
    
    exit();
}

// Handle backup upload for restore
if (isset($_POST['restore_backup']) && isset($_FILES['backup_file'])) {
    $file = $_FILES['backup_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $file['tmp_name'];
        $filename = $file['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if ($file_ext === 'sql') {
            $sql_content = file_get_contents($tmp_name);
            
            // Disable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // Split SQL into individual statements
            $statements = explode(';', $sql_content);
            $success_count = 0;
            $error_count = 0;
            $errors = [];
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    try {
                        $db->exec($statement);
                        $success_count++;
                    } catch (PDOException $e) {
                        $error_count++;
                        $errors[] = substr($statement, 0, 100) . '... - ' . $e->getMessage();
                    }
                }
            }
            
            // Re-enable foreign key checks
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            if ($error_count === 0) {
                $message = "Database restored successfully from $filename";
                $message_type = "success";
                logAudit($db, $_SESSION['user_id'], 'database_restore', "Restored database from $filename");
            } else {
                $message = "Restore completed with $error_count errors. $success_count statements executed successfully.";
                $message_type = "warning";
                logAudit($db, $_SESSION['user_id'], 'database_restore', "Restored database from $filename with $error_count errors");
            }
        } else {
            $message = "Invalid file type. Please upload a .sql file";
            $message_type = "error";
        }
    } else {
        $message = "Error uploading file: " . uploadErrorMessage($file['error']);
        $message_type = "error";
    }
}

// Handle backup deletion
if (isset($_POST['delete_backup'])) {
    $backup_file = $_POST['backup_file'];
    $backup_path = '../backups/' . $backup_file;
    
    if (file_exists($backup_path)) {
        if (unlink($backup_path)) {
            $message = "Backup file deleted successfully";
            $message_type = "success";
            logAudit($db, $_SESSION['user_id'], 'backup_delete', "Deleted backup file: $backup_file");
        } else {
            $message = "Error deleting backup file";
            $message_type = "error";
        }
    } else {
        $message = "Backup file not found";
        $message_type = "error";
    }
}

// Handle scheduled backup settings
if (isset($_POST['save_schedule'])) {
    $auto_backup = isset($_POST['auto_backup']) ? 1 : 0;
    $backup_frequency = $_POST['backup_frequency'];
    $backup_time = $_POST['backup_time'];
    $retention_days = (int)$_POST['retention_days'];
    $backup_type = $_POST['scheduled_backup_type'];
    
    // Save to config file or database
    $config = [
        'auto_backup' => $auto_backup,
        'backup_frequency' => $backup_frequency,
        'backup_time' => $backup_time,
        'retention_days' => $retention_days,
        'backup_type' => $backup_type
    ];
    
    // In production, save to database or config file
    $_SESSION['backup_config'] = $config;
    
    $message = "Backup schedule saved successfully";
    $message_type = "success";
    logAudit($db, $_SESSION['user_id'], 'backup_schedule', "Updated backup schedule");
}

// Helper function for upload errors
function uploadErrorMessage($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return "The uploaded file exceeds the upload_max_filesize directive in php.ini";
        case UPLOAD_ERR_FORM_SIZE:
            return "The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form";
        case UPLOAD_ERR_PARTIAL:
            return "The uploaded file was only partially uploaded";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing a temporary folder";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk";
        case UPLOAD_ERR_EXTENSION:
            return "A PHP extension stopped the file upload";
        default:
            return "Unknown upload error";
    }
}

// Helper function to log audit
function logAudit($db, $user_id, $action, $notes) {
    $username_query = "SELECT username FROM users WHERE id = :id";
    $username_stmt = $db->prepare($username_query);
    $username_stmt->bindParam(':id', $user_id);
    $username_stmt->execute();
    $user = $username_stmt->fetch(PDO::FETCH_ASSOC);
    
    $query = "INSERT INTO request_audit_log (request_id, action, user, quantity, notes, created_at) 
              VALUES (0, :action, :user, 0, :notes, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':user', $user['username']);
    $stmt->bindParam(':notes', $notes);
    $stmt->execute();
}

// Get database statistics
$stats = [];

// Database size
$query = "SELECT SUM(data_length + index_length) as size 
          FROM information_schema.tables 
          WHERE table_schema = DATABASE()";
$stmt = $db->query($query);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['db_size'] = formatBytes($result['size']);

// Table count
$query = "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE()";
$stmt = $db->query($query);
$stats['table_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Record count across all tables
$total_records = 0;
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $stmt = $db->query("SELECT COUNT(*) as count FROM `$table`");
    $total_records += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}
$stats['total_records'] = number_format($total_records);

// Last backup time (check backups directory)
$backup_dir = '../backups/';
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$backup_files = glob($backup_dir . '*.sql');
$stats['backup_count'] = count($backup_files);

$last_backup_time = 0;
$backups = [];
foreach ($backup_files as $file) {
    $file_time = filemtime($file);
    if ($file_time > $last_backup_time) {
        $last_backup_time = $file_time;
    }
    
    $backups[] = [
        'file' => basename($file),
        'size' => formatBytes(filesize($file)),
        'date' => date('Y-m-d H:i:s', $file_time),
        'age' => time() - $file_time
    ];
}

// Sort backups by date (newest first)
usort($backups, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$stats['last_backup'] = $last_backup_time > 0 ? date('Y-m-d H:i:s', $last_backup_time) : 'Never';

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Get all tables for selection
$all_tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// Table sizes
$table_sizes = [];
foreach ($all_tables as $table) {
    $stmt = $db->query("SELECT 
                        COUNT(*) as record_count,
                        data_length + index_length as total_size
                        FROM information_schema.tables 
                        WHERE table_schema = DATABASE() AND table_name = '$table'");
    $table_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM `$table`");
    $record_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $table_sizes[$table] = [
        'records' => $record_count,
        'size' => $table_info['total_size'] ?? 0
    ];
}

// Load saved config
$backup_config = isset($_SESSION['backup_config']) ? $_SESSION['backup_config'] : [
    'auto_backup' => 0,
    'backup_frequency' => 'daily',
    'backup_time' => '02:00',
    'retention_days' => 30,
    'backup_type' => 'full'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Restore - Super Admin | MedFlow Clinic Management System</title>
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

    /* Alert Messages */
    .alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideIn 0.3s ease;
    }

    .alert-success {
        background: #e8f5e9;
        border: 1px solid #a5d6a7;
        color: #2e7d32;
    }

    .alert-error {
        background: #ffebee;
        border: 1px solid #ffcdd2;
        color: #c62828;
    }

    .alert-warning {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
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
        width: 50px;
        height: 50px;
        background: #191970;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
    }

    .stat-info {
        flex: 1;
    }

    .stat-info h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #191970;
        margin-bottom: 4px;
    }

    .stat-info p {
        color: #546e7a;
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Tab Navigation */
    .config-tabs {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        border-bottom: 2px solid #cfd8dc;
        padding-bottom: 12px;
    }

    .tab-btn {
        padding: 12px 24px;
        border-radius: 40px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        background: transparent;
        color: #546e7a;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tab-btn:hover {
        color: #191970;
        background: #eceff1;
    }

    .tab-btn.active {
        background: #191970;
        color: white;
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.5s ease;
    }

    .tab-content.active {
        display: block;
    }

    /* Config Cards */
    .config-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 24px;
        margin-bottom: 30px;
    }

    .config-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #cfd8dc;
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #191970;
    }

    .card-header h2 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 8px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        background: white;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #191970;
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 10px 0;
    }

    .checkbox-group input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: #191970;
    }

    /* Table Selection */
    .table-selection {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #cfd8dc;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 16px;
    }

    .table-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        border-bottom: 1px solid #eceff1;
    }

    .table-checkbox:last-child {
        border-bottom: none;
    }

    .table-checkbox input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #191970;
    }

    .table-name {
        flex: 1;
        font-size: 0.9rem;
        color: #37474f;
    }

    .table-info {
        font-size: 0.75rem;
        color: #78909c;
    }

    /* Backup List */
    .backup-list {
        margin-top: 20px;
    }

    .backup-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px;
        background: #f8fafc;
        border-radius: 12px;
        margin-bottom: 10px;
        transition: all 0.3s ease;
        border: 1px solid transparent;
    }

    .backup-item:hover {
        background: white;
        border-color: #191970;
        transform: translateX(5px);
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
    }

    .backup-info {
        display: flex;
        align-items: center;
        gap: 16px;
        flex: 1;
    }

    .backup-icon {
        width: 48px;
        height: 48px;
        background: #191970;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
    }

    .backup-details h4 {
        font-size: 1rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 4px;
    }

    .backup-details p {
        font-size: 0.8rem;
        color: #78909c;
    }

    .backup-meta {
        display: flex;
        gap: 20px;
        margin-left: 20px;
    }

    .backup-meta-item {
        text-align: center;
    }

    .backup-meta-value {
        font-weight: 600;
        color: #191970;
        font-size: 1rem;
    }

    .backup-meta-label {
        font-size: 0.7rem;
        color: #78909c;
    }

    .backup-actions {
        display: flex;
        gap: 8px;
    }

    /* Progress Bar */
    .progress-bar {
        width: 100%;
        height: 8px;
        background: #eceff1;
        border-radius: 4px;
        margin: 10px 0;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: #191970;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    /* Action Buttons */
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

    .btn-warning {
        background: #ff9800;
        color: white;
    }

    .btn-warning:hover {
        background: #f57c00;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(255, 152, 0, 0.2);
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
    }

    .btn-small {
        padding: 8px 16px;
        font-size: 0.8rem;
    }

    /* Upload Area */
    .upload-area {
        border: 2px dashed #cfd8dc;
        border-radius: 16px;
        padding: 40px 20px;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 20px;
    }

    .upload-area:hover {
        border-color: #191970;
        background: #eceff1;
    }

    .upload-area.dragover {
        border-color: #191970;
        background: #e8eaf6;
    }

    .upload-icon {
        font-size: 48px;
        color: #191970;
        margin-bottom: 16px;
    }

    .upload-text {
        font-size: 1rem;
        color: #546e7a;
        margin-bottom: 8px;
    }

    .upload-hint {
        font-size: 0.8rem;
        color: #78909c;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
        animation: slideUp 0.3s ease;
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .modal-header h3 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #191970;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #546e7a;
    }

    .modal-body {
        margin-bottom: 20px;
    }

    .modal-footer {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    /* Warning Box */
    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #856404;
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

    @keyframes fadeIn {
        from { background: rgba(0, 0, 0, 0); }
        to { background: rgba(0, 0, 0, 0.5); }
    }

    @keyframes slideUp {
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
        
        .config-grid {
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
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .config-tabs {
            flex-direction: column;
        }
        
        .tab-btn {
            width: 100%;
            justify-content: center;
        }
        
        .backup-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .backup-info {
            width: 100%;
        }
        
        .backup-actions {
            width: 100%;
            justify-content: flex-end;
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
                        <h1>Backup & Restore</h1>
                        <p>Create database backups, restore from backups, and manage backup schedules</p>
                    </div>
                </div>

                <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                        <?php if ($message_type === 'success'): ?>
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M8 12L11 15L16 9"/>
                        <?php elseif ($message_type === 'warning'): ?>
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 8V12L12 16"/>
                        <?php else: ?>
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 8V12L12 16"/>
                        <?php endif; ?>
                    </svg>
                    <?php echo $message; ?>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                <line x1="3" y1="9" x2="21" y2="9"/>
                                <line x1="9" y1="21" x2="9" y2="9"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['db_size']; ?></h3>
                            <p>Database Size</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                <line x1="8" y1="9" x2="16" y2="9"/>
                                <line x1="8" y1="13" x2="16" y2="13"/>
                                <line x1="8" y1="17" x2="12" y2="17"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['table_count']; ?></h3>
                            <p>Tables</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="9" r="2"/>
                                <circle cx="15" cy="15" r="2"/>
                                <path d="M9 15L15 9"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_records']; ?></h3>
                            <p>Total Records</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['last_backup']; ?></h3>
                            <p>Last Backup</p>
                        </div>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="config-tabs">
                    <button class="tab-btn active" onclick="showTab('backup')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                            <path d="M16 21V15H8V21"/>
                            <path d="M8 3V8H15"/>
                        </svg>
                        Create Backup
                    </button>
                    <button class="tab-btn" onclick="showTab('restore')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                            <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                            <path d="M15 13L19 9L22 13"/>
                        </svg>
                        Restore Backup
                    </button>
                    <button class="tab-btn" onclick="showTab('schedule')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6V12L16 14"/>
                        </svg>
                        Schedule
                    </button>
                    <button class="tab-btn" onclick="showTab('history')">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="2" y1="8" x2="22" y2="8"/>
                        </svg>
                        Backup History
                    </button>
                </div>

                <!-- Create Backup Tab -->
                <div id="tab-backup" class="tab-content active">
                    <div class="config-grid">
                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M16 21V15H8V21"/>
                                    <path d="M8 3V8H15"/>
                                </svg>
                                <h2>Create New Backup</h2>
                            </div>
                            
                            <div class="form-group">
                                <label for="backup_type">Backup Type</label>
                                <select id="backup_type">
                                    <option value="full">Full Backup (Structure + Data)</option>
                                    <option value="structure">Structure Only (No data)</option>
                                    <option value="data">Data Only (No structure)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Select Tables</label>
                                <div class="table-selection">
                                    <div class="table-checkbox">
                                        <input type="checkbox" id="select_all_tables" checked onchange="toggleAllTables()">
                                        <label for="select_all_tables" class="table-name"><strong>Select All Tables</strong></label>
                                        <span class="table-info"><?php echo count($all_tables); ?> tables</span>
                                    </div>
                                    <?php foreach ($all_tables as $table): ?>
                                    <div class="table-checkbox">
                                        <input type="checkbox" name="tables[]" value="<?php echo $table; ?>" class="table-checkbox-item" checked>
                                        <span class="table-name"><?php echo $table; ?></span>
                                        <span class="table-info">
                                            <?php echo number_format($table_sizes[$table]['records']); ?> records • 
                                            <?php echo formatBytes($table_sizes[$table]['size']); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="backup_note">Backup Note (Optional)</label>
                                <input type="text" id="backup_note" placeholder="e.g., Before semester start, Weekly backup">
                            </div>
                            
                            <button onclick="createBackup()" class="btn btn-success" style="width: 100%;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M16 21V15H8V21"/>
                                    <path d="M8 3V8H15"/>
                                </svg>
                                Download Backup Now
                            </button>
                        </div>

                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8V12L16 14"/>
                                </svg>
                                <h2>Quick Actions</h2>
                            </div>
                            
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <button onclick="createBackup('full', 'all')" class="btn btn-secondary" style="justify-content: flex-start;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8V12L16 14"/>
                                    </svg>
                                    Full Backup (All Tables)
                                </button>
                                
                                <button onclick="createBackup('structure', 'all')" class="btn btn-secondary" style="justify-content: flex-start;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="3" y1="9" x2="21" y2="9"/>
                                    </svg>
                                    Structure Only
                                </button>
                                
                                <button onclick="createBackup('data', 'all')" class="btn btn-secondary" style="justify-content: flex-start;">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6V12L16 14"/>
                                    </svg>
                                    Data Only
                                </button>
                                
                                <div class="progress-bar" style="margin-top: 20px;">
                                    <div class="progress-fill" style="width: <?php echo min(100, round(($stats['backup_count'] / 10) * 100)); ?>%"></div>
                                </div>
                                <p style="font-size: 0.85rem; color: #546e7a; text-align: center;">
                                    <?php echo $stats['backup_count']; ?> backups created • 
                                    Last: <?php echo $stats['last_backup']; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Restore Backup Tab -->
                <div id="tab-restore" class="tab-content">
                    <div class="config-grid">
                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                                    <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                                    <path d="M15 13L19 9L22 13"/>
                                </svg>
                                <h2>Upload Backup File</h2>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" id="restoreForm">
                                <div class="upload-area" id="uploadArea" onclick="document.getElementById('backup_file').click()">
                                    <div class="upload-icon">
                                        <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15"/>
                                            <path d="M7 10L12 15L17 10"/>
                                            <path d="M12 15V3"/>
                                        </svg>
                                    </div>
                                    <div class="upload-text">Click or drag SQL file to upload</div>
                                    <div class="upload-hint">Supported format: .sql (Max size: <?php echo ini_get('upload_max_filesize'); ?>)</div>
                                </div>
                                
                                <input type="file" name="backup_file" id="backup_file" accept=".sql" style="display: none;" onchange="updateFileName(this)">
                                <div id="fileInfo" style="display: none; margin-bottom: 16px;"></div>
                                
                                <div class="warning-box">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8V12L12 16"/>
                                    </svg>
                                    <div>
                                        <strong>Warning:</strong> Restoring will overwrite existing data. Make sure you have a current backup.
                                    </div>
                                </div>
                                
                                <button type="submit" name="restore_backup" class="btn btn-warning" style="width: 100%;" onclick="return confirmRestore()">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                                        <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                                        <path d="M15 13L19 9L22 13"/>
                                    </svg>
                                    Restore Database
                                </button>
                            </form>
                        </div>

                        <div class="config-card">
                            <div class="card-header">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                    <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                    <line x1="8" y1="9" x2="16" y2="9"/>
                                    <line x1="8" y1="13" x2="16" y2="13"/>
                                    <line x1="8" y1="17" x2="12" y2="17"/>
                                </svg>
                                <h2>Restore Tips</h2>
                            </div>
                            
                            <ul style="list-style: none; padding: 0;">
                                <li style="display: flex; gap: 12px; margin-bottom: 16px;">
                                    <div style="width: 24px; height: 24px; background: #e8eaf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #191970; font-weight: 600;">1</div>
                                    <div>
                                        <strong style="color: #191970;">Always backup first</strong>
                                        <p style="font-size: 0.85rem; color: #546e7a;">Create a current backup before restoring any previous version.</p>
                                    </div>
                                </li>
                                <li style="display: flex; gap: 12px; margin-bottom: 16px;">
                                    <div style="width: 24px; height: 24px; background: #e8eaf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #191970; font-weight: 600;">2</div>
                                    <div>
                                        <strong style="color: #191970;">Check file compatibility</strong>
                                        <p style="font-size: 0.85rem; color: #546e7a;">Ensure the backup file is from the same database version.</p>
                                    </div>
                                </li>
                                <li style="display: flex; gap: 12px; margin-bottom: 16px;">
                                    <div style="width: 24px; height: 24px; background: #e8eaf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #191970; font-weight: 600;">3</div>
                                    <div>
                                        <strong style="color: #191970;">Schedule maintenance</strong>
                                        <p style="font-size: 0.85rem; color: #546e7a;">Perform restores during low-usage periods to minimize disruption.</p>
                                    </div>
                                </li>
                            </ul>
                            
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 75%"></div>
                            </div>
                            <p style="font-size: 0.8rem; color: #78909c; text-align: center; margin-top: 10px;">
                                Database health: 75% optimized
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Schedule Tab -->
                <div id="tab-schedule" class="tab-content">
                    <div class="config-card">
                        <div class="card-header">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                            <h2>Automated Backup Schedule</h2>
                        </div>
                        
                        <form method="POST" action="">
                            <div class="checkbox-group">
                                <input type="checkbox" id="auto_backup" name="auto_backup" <?php echo $backup_config['auto_backup'] ? 'checked' : ''; ?>>
                                <label for="auto_backup">Enable automatic backups</label>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="backup_frequency">Frequency</label>
                                    <select id="backup_frequency" name="backup_frequency">
                                        <option value="hourly" <?php echo $backup_config['backup_frequency'] == 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                        <option value="daily" <?php echo $backup_config['backup_frequency'] == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo $backup_config['backup_frequency'] == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo $backup_config['backup_frequency'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="backup_time">Time</label>
                                    <input type="time" id="backup_time" name="backup_time" value="<?php echo $backup_config['backup_time']; ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="scheduled_backup_type">Backup Type</label>
                                    <select id="scheduled_backup_type" name="scheduled_backup_type">
                                        <option value="full" <?php echo $backup_config['backup_type'] == 'full' ? 'selected' : ''; ?>>Full Backup</option>
                                        <option value="incremental" <?php echo $backup_config['backup_type'] == 'incremental' ? 'selected' : ''; ?>>Incremental</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="retention_days">Keep backups for (days)</label>
                                    <input type="number" id="retention_days" name="retention_days" value="<?php echo $backup_config['retention_days']; ?>" min="1" max="365">
                                </div>
                            </div>
                            
                            <div style="background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                                <h4 style="font-size: 0.95rem; color: #191970; margin-bottom: 10px;">Next scheduled backups:</h4>
                                <ul style="list-style: none; padding: 0;">
                                    <li style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eceff1;">
                                        <span>Full Backup</span>
                                        <span style="font-weight: 600; color: #191970;">Tomorrow, 02:00 AM</span>
                                    </li>
                                    <li style="display: flex; justify-content: space-between; padding: 8px 0;">
                                        <span>Incremental Backup</span>
                                        <span style="font-weight: 600; color: #191970;">Today, 14:00 PM</span>
                                    </li>
                                </ul>
                            </div>
                            
                            <button type="submit" name="save_schedule" class="btn btn-primary" style="width: 100%;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z"/>
                                    <path d="M17 21V15H7V21"/>
                                    <path d="M7 3V8H15"/>
                                </svg>
                                Save Schedule
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Backup History Tab -->
                <div id="tab-history" class="tab-content">
                    <div class="config-card">
                        <div class="card-header">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#191970" stroke-width="2">
                                <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="2" y1="8" x2="22" y2="8"/>
                            </svg>
                            <h2>Backup History</h2>
                        </div>
                        
                        <?php if (empty($backups)): ?>
                        <div style="text-align: center; padding: 40px;">
                            <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="#b0bec5" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8V12L12 16"/>
                            </svg>
                            <p style="margin-top: 16px; color: #78909c;">No backups found. Create your first backup.</p>
                        </div>
                        <?php else: ?>
                        <div class="backup-list">
                            <?php foreach ($backups as $backup): ?>
                            <div class="backup-item">
                                <div class="backup-info">
                                    <div class="backup-icon">
                                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="white" stroke-width="2">
                                            <rect x="2" y="3" width="20" height="18" rx="2" ry="2"/>
                                            <line x1="8" y1="9" x2="16" y2="9"/>
                                            <line x1="8" y1="13" x2="16" y2="13"/>
                                            <line x1="8" y1="17" x2="12" y2="17"/>
                                        </svg>
                                    </div>
                                    <div class="backup-details">
                                        <h4><?php echo $backup['file']; ?></h4>
                                        <p>Created: <?php echo $backup['date']; ?></p>
                                    </div>
                                </div>
                                
                                <div class="backup-meta">
                                    <div class="backup-meta-item">
                                        <div class="backup-meta-value"><?php echo $backup['size']; ?></div>
                                        <div class="backup-meta-label">Size</div>
                                    </div>
                                    <div class="backup-meta-item">
                                        <div class="backup-meta-value">
                                            <?php 
                                            $hours = floor($backup['age'] / 3600);
                                            $days = floor($hours / 24);
                                            echo $days > 0 ? $days . 'd' : $hours . 'h';
                                            ?>
                                        </div>
                                        <div class="backup-meta-label">Age</div>
                                    </div>
                                </div>
                                
                                <div class="backup-actions">
                                    <a href="../backups/<?php echo $backup['file']; ?>" download class="btn btn-small btn-secondary" title="Download">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15"/>
                                            <path d="M7 10L12 15L17 10"/>
                                            <path d="M12 15V3"/>
                                        </svg>
                                    </a>
                                    <button class="btn btn-small btn-warning" onclick="restoreBackup('<?php echo $backup['file']; ?>')">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 12C21 13.2 20.5 14.2 19.7 15.1C18.9 15.9 17.8 16.5 16.5 16.8C15.2 17.1 13.8 17.1 12.1 16.9"/>
                                            <path d="M9 17C7.5 16.5 6.2 15.7 5.1 14.9C4 14.1 3.3 13.1 3 12C2.7 10.9 3 9.8 3.7 8.8C4.4 7.8 5.4 7 6.7 6.4C8 5.8 9.4 5.5 11 5.5C12.6 5.5 14 5.8 15.3 6.4C16.6 7 17.6 7.8 18.3 8.8"/>
                                            <path d="M15 13L19 9L22 13"/>
                                        </svg>
                                    </button>
                                    <button class="btn btn-small btn-danger" onclick="deleteBackup('<?php echo $backup['file']; ?>')">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M3 6H21"/>
                                            <path d="M19 6V20C19 20.5304 18.7893 20.0391 18.4142 20.4142C18.0391 20.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6"/>
                                            <path d="M8 4V4C8 2.89543 8.89543 2 10 2H14C15.1046 2 16 2.89543 16 4V4"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Restore Backup</h3>
                <button class="modal-close" onclick="closeModal('restoreModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="warning-box" style="margin-bottom: 16px;">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 8V12L12 16"/>
                        </svg>
                        <div>
                            <strong>Warning:</strong> This will overwrite current data.
                        </div>
                    </div>
                    <p>Are you sure you want to restore from: <strong id="restoreFileName"></strong>?</p>
                    <input type="hidden" name="backup_file" id="restoreFileInput">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
                    <button type="submit" name="restore_backup" class="btn btn-warning">Restore</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Backup Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Backup</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Are you sure you want to delete: <strong id="deleteFileName"></strong>?</p>
                    <p style="color: #c62828; font-size: 0.9rem; margin-top: 10px;">This action cannot be undone.</p>
                    <input type="hidden" name="backup_file" id="deleteFileInput">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_backup" class="btn btn-danger">Delete</button>
                </div>
            </form>
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

        // Tab switching
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Create backup function - triggers download
        function createBackup(type = null, tables = null) {
            const backupType = type || document.getElementById('backup_type').value;
            
            // Get selected tables
            let selectedTables = [];
            if (tables === 'all') {
                selectedTables = ['all'];
            } else {
                document.querySelectorAll('.table-checkbox-item:checked').forEach(cb => {
                    selectedTables.push(cb.value);
                });
            }
            
            if (selectedTables.length === 0 && tables !== 'all') {
                alert('Please select at least one table to backup');
                return;
            }
            
            // Build URL with parameters
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'backup');
            url.searchParams.set('type', backupType);
            url.searchParams.set('tables', selectedTables.join(','));
            
            // Get note if any
            const note = document.getElementById('backup_note').value;
            if (note) {
                url.searchParams.set('note', encodeURIComponent(note));
            }
            
            // Redirect to trigger download
            window.location.href = url.toString();
        }

        // Select/deselect all tables
        function toggleAllTables() {
            const selectAll = document.getElementById('select_all_tables');
            document.querySelectorAll('.table-checkbox-item').forEach(cb => {
                cb.checked = selectAll.checked;
            });
        }

        // Update select all based on individual checkboxes
        document.querySelectorAll('.table-checkbox-item').forEach(cb => {
            cb.addEventListener('change', function() {
                const allChecked = document.querySelectorAll('.table-checkbox-item:checked').length;
                const total = document.querySelectorAll('.table-checkbox-item').length;
                document.getElementById('select_all_tables').checked = allChecked === total;
            });
        });

        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('backup_file').files = files;
                updateFileName(files[0]);
            }
        });

        function updateFileName(input) {
            const fileInfo = document.getElementById('fileInfo');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const size = (file.size / 1024 / 1024).toFixed(2);
                fileInfo.innerHTML = `
                    <div style="background: #e8eaf6; padding: 10px; border-radius: 8px;">
                        <strong>Selected:</strong> ${file.name} (${size} MB)
                    </div>
                `;
                fileInfo.style.display = 'block';
            }
        }

        function confirmRestore() {
            return confirm('WARNING: This will completely replace your current database. Make sure you have a current backup. Continue?');
        }

        // Modal functions
        function restoreBackup(fileName) {
            document.getElementById('restoreFileName').textContent = fileName;
            document.getElementById('restoreFileInput').value = fileName;
            document.getElementById('restoreModal').classList.add('show');
        }

        function deleteBackup(fileName) {
            document.getElementById('deleteFileName').textContent = fileName;
            document.getElementById('deleteFileInput').value = fileName;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // Update page title
        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Backup & Restore';
        }
    </script>
</body>
</html>