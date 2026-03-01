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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clearance_type = $_POST['clearance_type'];
    $purpose = $_POST['purpose'];
    $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
    
    $query = "INSERT INTO clearance_requests (student_id, student_name, clearance_type, purpose, valid_until, status) 
              VALUES (:student_id, :student_name, :clearance_type, :purpose, :valid_until, 'Pending')";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->bindParam(':student_name', $student_data['full_name']);
    $stmt->bindParam(':clearance_type', $clearance_type);
    $stmt->bindParam(':purpose', $purpose);
    $stmt->bindParam(':valid_until', $valid_until);
    
    if ($stmt->execute()) {
        $success_message = "Clearance request submitted successfully!";
    } else {
        $error_message = "Failed to submit clearance request.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Clearance | Student Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    <style>
        /* Copy same styles as request_appointment.php */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #eceff1;
            min-height: 100vh;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 20px 30px 30px 30px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
        }

        .welcome-section h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 8px;
        }

        .welcome-section p {
            color: #546e7a;
            font-size: 1rem;
        }

        .form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #cfd8dc;
            max-width: 600px;
            margin: 0 auto;
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 10px;
        }

        .form-subtitle {
            color: #546e7a;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 20px;
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
            padding: 12px 16px;
            font-size: 1rem;
            border: 2px solid #cfd8dc;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
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
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
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
            margin-right: 10px;
        }

        .btn-secondary:hover {
            background: #cfd8dc;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
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

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
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
                    <h1>Request Medical Clearance</h1>
                    <p>Apply for medical clearance for your activities.</p>
                </div>

                <div class="form-card">
                    <h2 class="form-title">Clearance Details</h2>
                    <p class="form-subtitle">Please provide the information below to request a clearance.</p>

                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                <path d="M22 4L12 14.01L9 11.01"/>
                            </svg>
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="clearance_type">Clearance Type</label>
                            <select class="form-control" id="clearance_type" name="clearance_type" required>
                                <option value="">Select type</option>
                                <option value="Sports">Sports Clearance</option>
                                <option value="Event">Event Clearance</option>
                                <option value="Work Immersion">Work Immersion Clearance</option>
                                <option value="After Illness">After Illness Clearance</option>
                                <option value="General">General Medical Clearance</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="purpose">Purpose / Reason</label>
                            <textarea class="form-control" id="purpose" name="purpose" rows="4" 
                                      placeholder="Please state the purpose of this clearance request..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label for="valid_until">Valid Until (Optional)</label>
                            <input type="date" class="form-control" id="valid_until" name="valid_until" 
                                   min="<?php echo date('Y-m-d'); ?>">
                            <small style="color: #78909c; font-size: 0.8rem;">Leave blank if not applicable</small>
                        </div>

                        <div class="form-actions">
                            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.getElementById('mainContent');
        const collapseBtn = document.getElementById('collapseSidebar');
        
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            });
        }

        const pageTitle = document.getElementById('pageTitle');
        if (pageTitle) {
            pageTitle.textContent = 'Request Clearance';
        }
    </script>
</body>
</html>