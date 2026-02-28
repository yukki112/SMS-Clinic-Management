<?php
session_start();
require_once 'config/database.php';
require_once 'vendor/phpmailer/src/Exception.php';
require_once 'vendor/phpmailer/src/PHPMailer.php';
require_once 'vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Redirect if already logged in
if (isset($_SESSION['user_id']) && !isset($_SESSION['otp_verified'])) {
    if ($_SESSION['role'] === 'superadmin') {
        header('Location: superadmin/dashboard.php');
    } elseif ($_SESSION['role'] === 'nurse') {
        header('Location: nurse/dashboard.php');
    } elseif (in_array($_SESSION['role'], ['admin', 'staff'])) {
        header('Location: admin/dashboard.php');
    }
    exit();
}

$error = '';
$step = isset($_SESSION['login_step']) ? $_SESSION['login_step'] : 'credentials';

// Function to send OTP via email
function sendOTPEmail($email, $otp, $full_name) {
    $mail = new PHPMailer(true);
    
    try {
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Use your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'Stephenviray12@gmail.com'; // Your email
        $mail->Password   = 'bubr nckn tgqf lvus'; // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('your-email@gmail.com', 'ICARE Clinic');
        $mail->addAddress($email, $full_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your OTP for ICARE Clinic Login';
        $mail->Body    = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #191970; border-radius: 10px;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                <h2 style='color: #191970;'>ICARE Clinic</h2>
                <p style='color: #666;'>Email Verification</p>
            </div>
            
            <div style='background-color: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center;'>
                <h3 style='color: #191970; margin-bottom: 15px;'>Hello, $full_name!</h3>
                <p style='font-size: 16px; color: #333;'>Your One-Time Password (OTP) for login is:</p>
                <div style='background: #191970; color: white; font-size: 32px; font-weight: bold; padding: 15px; border-radius: 8px; letter-spacing: 8px; margin: 20px 0;'>
                    $otp
                </div>
                <p style='color: #666;'>This OTP will expire in <strong>2 minutes</strong>.</p>
                <p style='color: #999; font-size: 14px; margin-top: 20px;'>If you didn't request this, please ignore this email.</p>
            </div>
            
            <div style='text-align: center; margin-top: 20px; color: #999; font-size: 12px;'>
                <p>&copy; " . date('Y') . " ICARE Clinic. All rights reserved.</p>
            </div>
        </div>
        ";
        
        $mail->AltBody = "Your OTP for ICARE Clinic login is: $otp. This OTP will expire in 2 minutes.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Generate random OTP
function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    if (isset($_POST['login'])) {
        // Step 1: Validate credentials
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter username/email and password';
        } else {
            $query = "SELECT * FROM users WHERE (username = :username OR email = :username) AND role IN ('admin', 'superadmin', 'staff', 'nurse')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (password_verify($password, $user['password'])) {
                    // Generate and save OTP
                    $otp = generateOTP();
                    $expires_at = date('Y-m-d H:i:s', strtotime('+2 minutes'));
                    
                    // Delete any existing OTPs for this user
                    $delete_query = "DELETE FROM otp_verification WHERE user_id = :user_id";
                    $delete_stmt = $db->prepare($delete_query);
                    $delete_stmt->bindParam(':user_id', $user['id']);
                    $delete_stmt->execute();
                    
                    // Insert new OTP
                    $insert_query = "INSERT INTO otp_verification (user_id, otp_code, email, expires_at) 
                                     VALUES (:user_id, :otp_code, :email, :expires_at)";
                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':user_id', $user['id']);
                    $insert_stmt->bindParam(':otp_code', $otp);
                    $insert_stmt->bindParam(':email', $user['email']);
                    $insert_stmt->bindParam(':expires_at', $expires_at);
                    
                    if ($insert_stmt->execute()) {
                        // Send OTP via email
                        if (sendOTPEmail($user['email'], $otp, $user['full_name'])) {
                            // Store user data in session temporarily
                            $_SESSION['temp_user_id'] = $user['id'];
                            $_SESSION['temp_username'] = $user['username'];
                            $_SESSION['temp_full_name'] = $user['full_name'];
                            $_SESSION['temp_email'] = $user['email'];
                            $_SESSION['temp_role'] = $user['role'];
                            $_SESSION['login_step'] = 'otp';
                            $_SESSION['otp_expiry'] = $expires_at;
                            
                            // Redirect to OTP page (or stay on same page with OTP form)
                            header('Location: login.php?otp_sent=1');
                            exit();
                        } else {
                            $error = 'Failed to send OTP email. Please try again.';
                        }
                    } else {
                        $error = 'Failed to generate OTP. Please try again.';
                    }
                } else {
                    $error = 'Invalid password!';
                }
            } else {
                $error = 'User not found!';
            }
        }
    } elseif (isset($_POST['verify_otp'])) {
        // Step 2: Verify OTP
        $otp_code = trim($_POST['otp_code']);
        
        if (empty($otp_code)) {
            $error = 'Please enter the OTP code';
        } else {
            $user_id = $_SESSION['temp_user_id'];
            $current_time = date('Y-m-d H:i:s');
            
            // Check OTP validity
            $query = "SELECT * FROM otp_verification 
                      WHERE user_id = :user_id 
                      AND otp_code = :otp_code 
                      AND expires_at > :current_time 
                      AND verified = 0 
                      ORDER BY id DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':otp_code', $otp_code);
            $stmt->bindParam(':current_time', $current_time);
            $stmt->execute();
            
            if ($otp_record = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Mark OTP as verified
                $update_query = "UPDATE otp_verification SET verified = 1 WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':id', $otp_record['id']);
                $update_stmt->execute();
                
                // Set full session variables
                $_SESSION['user_id'] = $_SESSION['temp_user_id'];
                $_SESSION['username'] = $_SESSION['temp_username'];
                $_SESSION['full_name'] = $_SESSION['temp_full_name'];
                $_SESSION['email'] = $_SESSION['temp_email'];
                $_SESSION['role'] = $_SESSION['temp_role'];
                $_SESSION['login_time'] = time();
                $_SESSION['otp_verified'] = true;
                
                // Clear temporary session data
                unset($_SESSION['temp_user_id']);
                unset($_SESSION['temp_username']);
                unset($_SESSION['temp_full_name']);
                unset($_SESSION['temp_email']);
                unset($_SESSION['temp_role']);
                unset($_SESSION['login_step']);
                unset($_SESSION['otp_expiry']);
                
                // Create session record in database
                $session_token = bin2hex(random_bytes(32));
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $session_query = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                                  VALUES (:user_id, :session_token, :ip_address, :user_agent, :expires_at)";
                $session_stmt = $db->prepare($session_query);
                $session_stmt->bindParam(':user_id', $_SESSION['user_id']);
                $session_stmt->bindParam(':session_token', $session_token);
                $session_stmt->bindParam(':ip_address', $ip_address);
                $session_stmt->bindParam(':user_agent', $user_agent);
                $session_stmt->bindParam(':expires_at', $expires_at);
                $session_stmt->execute();
                
                $_SESSION['session_token'] = $session_token;
                
                // Redirect based on role
                if ($_SESSION['role'] === 'superadmin') {
                    header('Location: superadmin/dashboard.php');
                } elseif ($_SESSION['role'] === 'nurse') {
                    header('Location: nurse/dashboard.php');
                } else {
                    header('Location: admin/dashboard.php');
                }
                exit();
            } else {
                // Check if OTP exists but expired
                $expired_query = "SELECT * FROM otp_verification 
                                 WHERE user_id = :user_id 
                                 AND otp_code = :otp_code 
                                 AND expires_at <= :current_time 
                                 ORDER BY id DESC LIMIT 1";
                $expired_stmt = $db->prepare($expired_query);
                $expired_stmt->bindParam(':user_id', $user_id);
                $expired_stmt->bindParam(':otp_code', $otp_code);
                $expired_stmt->bindParam(':current_time', $current_time);
                $expired_stmt->execute();
                
                if ($expired_stmt->fetch()) {
                    $error = 'OTP has expired. Please request a new one.';
                } else {
                    $error = 'Invalid OTP code. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['resend_otp'])) {
        // Resend OTP
        $user_id = $_SESSION['temp_user_id'];
        
        // Get user details
        $query = "SELECT * FROM users WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Generate new OTP
            $otp = generateOTP();
            $expires_at = date('Y-m-d H:i:s', strtotime('+2 minutes'));
            
            // Delete old OTPs
            $delete_query = "DELETE FROM otp_verification WHERE user_id = :user_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':user_id', $user_id);
            $delete_stmt->execute();
            
            // Insert new OTP
            $insert_query = "INSERT INTO otp_verification (user_id, otp_code, email, expires_at) 
                             VALUES (:user_id, :otp_code, :email, :expires_at)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->bindParam(':otp_code', $otp);
            $insert_stmt->bindParam(':email', $user['email']);
            $insert_stmt->bindParam(':expires_at', $expires_at);
            
            if ($insert_stmt->execute()) {
                if (sendOTPEmail($user['email'], $otp, $user['full_name'])) {
                    $_SESSION['otp_expiry'] = $expires_at;
                    $success = 'A new OTP has been sent to your email.';
                } else {
                    $error = 'Failed to send OTP email. Please try again.';
                }
            } else {
                $error = 'Failed to generate OTP. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ICARE · staff login</title>
    <!-- same fonts & icons as landing page -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #ECEFF1;
            color: #191970;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }

        /* subtle floating shapes (same as landing page) */
        .bg-shape {
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: rgba(25, 25, 112, 0.02);
            bottom: -200px;
            right: -150px;
            border: 2px dashed rgba(25, 25, 112, 0.2);
            animation: slowDrift 28s infinite alternate;
            z-index: 0;
        }

        .bg-shape-two {
            width: 350px;
            height: 350px;
            background: rgba(25, 25, 112, 0.02);
            position: absolute;
            top: -120px;
            left: -80px;
            border-radius: 40% 60% 70% 30% / 40% 50% 60% 50%;
            border: 2px dotted rgba(25, 25, 112, 0.15);
            animation: slowDrift2 22s infinite alternate-reverse;
            z-index: 0;
        }

        @keyframes slowDrift {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-60px, -30px) rotate(12deg); }
        }

        @keyframes slowDrift2 {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(50px, 30px) rotate(-18deg); }
        }

        /* main login card — matches landing card aesthetic */
        .auth-container {
            width: 100%;
            max-width: 480px;
            padding: 1.5rem;
            position: relative;
            z-index: 10;
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 70px;
            padding: 3rem 2.8rem;
            box-shadow: 0 40px 70px -20px rgba(25, 25, 112, 0.4);
            border: 1px solid rgba(25, 25, 112, 0.2);
            transition: transform 0.3s ease;
        }

        .auth-card:hover {
            transform: scale(1.01);
        }

        /* logo + wordmark exactly as landing */
        .logo-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            margin-bottom: 1.8rem;
        }

        .logo-img {
            width: 56px;
            height: 56px;
            border-radius: 40%;
            object-fit: contain;
            filter: drop-shadow(0 6px 12px rgba(25,25,112,0.2));
        }

        .logo-text {
            font-size: 2.4rem;
            font-weight: 800;
            color: #191970;
            letter-spacing: -0.02em;
        }

        .logo-text span {
            font-weight: 400;
            font-size: 1.9rem;
            opacity: 0.8;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .auth-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 0.4rem;
        }

        .auth-header p {
            color: #191970;
            opacity: 0.7;
            font-size: 1rem;
            font-weight: 500;
        }

        /* alert styling */
        .alert {
            padding: 1.1rem 1.5rem;
            border-radius: 100px;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            border: 1px solid transparent;
            background: white;
        }

        .alert-error {
            background: #ffebee;
            color: #b71c1c;
            border-color: #ffcdd2;
        }

        .alert-success {
            background: #e8f5e9;
            color: #1b5e20;
            border-color: #c8e6c9;
        }

        .alert i {
            font-size: 1.3rem;
        }

        /* form */
        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 1.8rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-size: 0.95rem;
            font-weight: 600;
            color: #191970;
            margin-left: 0.5rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 1.4rem;
            font-size: 1.2rem;
            color: #191970;
            opacity: 0.6;
            pointer-events: none;
        }

        .input-wrapper input {
            width: 100%;
            padding: 1.1rem 1.1rem 1.1rem 3.2rem;
            border: 2px solid rgba(25, 25, 112, 0.2);
            border-radius: 60px;
            font-size: 1rem;
            font-weight: 500;
            background: white;
            color: #191970;
            transition: all 0.2s ease;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 10px 20px -12px #191970;
            background: white;
        }

        .input-wrapper input::placeholder {
            color: #191970;
            opacity: 0.4;
            font-weight: 400;
        }

        /* OTP specific styles */
        .otp-input {
            text-align: center;
            font-size: 1.5rem !important;
            letter-spacing: 8px;
            font-weight: 700 !important;
        }

        .timer {
            text-align: center;
            font-size: 1.1rem;
            font-weight: 600;
            color: #191970;
            margin: 1rem 0;
        }

        .timer-warning {
            color: #b71c1c;
        }

        .resend-link {
            text-align: center;
            margin-top: 1rem;
        }

        .resend-link button {
            background: none;
            border: none;
            color: #191970;
            font-weight: 600;
            text-decoration: underline;
            cursor: pointer;
            font-size: 0.95rem;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .resend-link button:hover {
            opacity: 1;
        }

        .resend-link button:disabled {
            opacity: 0.3;
            cursor: not-allowed;
            text-decoration: none;
        }

        .info-text {
            text-align: center;
            color: #191970;
            opacity: 0.6;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            padding: 0.5rem;
            background: rgba(25, 25, 112, 0.05);
            border-radius: 50px;
        }

        .forgot-row {
            display: flex;
            justify-content: flex-end;
            margin-top: -0.8rem;
        }

        .forgot-link {
            color: #191970;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            opacity: 0.7;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .forgot-link:hover {
            opacity: 1;
            text-decoration: underline;
        }

        .btn {
            padding: 1.1rem 1.8rem;
            border: none;
            border-radius: 60px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
        }

        .btn-primary {
            background: #191970;
            color: #ECEFF1;
            box-shadow: 0 15px 30px -10px #191970;
            border: 2px solid transparent;
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-3px);
            box-shadow: 0 25px 35px -12px #191970;
        }

        .btn-secondary {
            background: transparent;
            color: #191970;
            border: 2px solid #191970;
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: rgba(25, 25, 112, 0.05);
            transform: translateY(-3px);
        }

        .btn-block {
            width: 100%;
        }

        /* back link */
        .back-link {
            text-align: center;
            margin-top: 2rem;
        }

        .back-link a {
            color: #191970;
            font-weight: 600;
            text-decoration: none;
            font-size: 1rem;
            opacity: 0.7;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: opacity 0.2s;
        }

        .back-link a:hover {
            opacity: 1;
        }

        /* role badges (discreet, matches design) */
        .role-hint {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(25, 25, 112, 0.1);
            text-align: center;
        }

        .role-hint p {
            font-size: 0.85rem;
            font-weight: 500;
            color: #191970;
            opacity: 0.5;
            margin-bottom: 0.8rem;
            letter-spacing: 0.3px;
        }

        .role-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
        }

        .role-badge {
            background: rgba(25, 25, 112, 0.05);
            border: 1px solid rgba(25, 25, 112, 0.2);
            padding: 0.3rem 1rem;
            border-radius: 60px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #191970;
            backdrop-filter: blur(2px);
        }

        /* responsive */
        @media (max-width: 500px) {
            .auth-card { padding: 2rem 1.5rem; }
            .logo-text { font-size: 2rem; }
            .logo-text span { font-size: 1.6rem; }
        }
    </style>
</head>
<body>
    <!-- floating background shapes (same as landing) -->
    <div class="bg-shape"></div>
    <div class="bg-shape-two"></div>

    <div class="auth-container">
        <div class="auth-card">
            <!-- logo identical to landing page -->
            <div class="logo-wrapper">
                <img src="assets/images/clinic.png" alt="ICARE" class="logo-img" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'56\' height=\'56\' viewBox=\'0 0 24 24\' fill=\'%23191970\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E';">
                <span class="logo-text">ICARE<span>clinic</span></span>
            </div>

            <div class="auth-header">
                <?php if (isset($_SESSION['login_step']) && $_SESSION['login_step'] == 'otp'): ?>
                    <h2>Verify OTP</h2>
                    <p>Enter the code sent to your email</p>
                <?php else: ?>
                    <h2>Sign In</h2>
                <?php endif; ?>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['otp_sent'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-envelope"></i>
                    An OTP has been sent to your email. Valid for 2 minutes.
                </div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Registration successful! Please login.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['session_expired'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-clock"></i>
                    Your session has expired. Please login again.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['login_step']) && $_SESSION['login_step'] == 'otp'): ?>
                <!-- OTP Verification Form -->
                <div class="info-text">
                    <i class="fas fa-envelope"></i> 
                    OTP sent to: <?php echo isset($_SESSION['temp_email']) ? htmlspecialchars(maskEmail($_SESSION['temp_email'])) : ''; ?>
                </div>
                
                <form method="POST" action="" class="auth-form" id="otpForm">
                    <div class="form-group">
                        <label for="otp_code"><i class="fas fa-lock" style="margin-right: 0.3rem;"></i>6-Digit OTP Code</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key"></i>
                            <input type="text" 
                                   id="otp_code" 
                                   name="otp_code" 
                                   class="otp-input"
                                   placeholder="••••••" 
                                   maxlength="6" 
                                   pattern="[0-9]{6}" 
                                   inputmode="numeric"
                                   autocomplete="off"
                                   required>
                        </div>
                    </div>

                    <div class="timer" id="timer">
                        <i class="fas fa-hourglass-half"></i> 
                        Time remaining: <span id="timeRemaining">02:00</span>
                    </div>

                    <button type="submit" name="verify_otp" class="btn btn-primary btn-block">
                        <i class="fas fa-check-circle"></i> verify & continue
                    </button>
                </form>

                <div class="resend-link">
                    <form method="POST" action="" style="display: inline;">
                        <button type="submit" name="resend_otp" id="resendBtn" disabled>
                            <i class="fas fa-redo-alt"></i> resend OTP
                        </button>
                    </form>
                </div>

                <div class="back-link">
                    <a href="login.php"><i class="fas fa-chevron-left"></i> back to login</a>
                </div>

                <script>
                    // Timer functionality
                    function startTimer(duration, display) {
                        var timer = duration, minutes, seconds;
                        var endTime = new Date().getTime() + duration * 1000;
                        
                        // Save end time in localStorage to persist across page reloads
                        localStorage.setItem('otp_end_time', endTime);
                        
                        var interval = setInterval(function () {
                            var now = new Date().getTime();
                            var distance = endTime - now;
                            
                            if (distance <= 0) {
                                clearInterval(interval);
                                display.textContent = "00:00";
                                document.getElementById('timer').classList.add('timer-warning');
                                document.getElementById('resendBtn').disabled = false;
                                localStorage.removeItem('otp_end_time');
                                return;
                            }
                            
                            minutes = parseInt((distance % (1000 * 60 * 60)) / (1000 * 60), 10);
                            seconds = parseInt((distance % (1000 * 60)) / 1000, 10);
                            
                            minutes = minutes < 10 ? "0" + minutes : minutes;
                            seconds = seconds < 10 ? "0" + seconds : seconds;
                            
                            display.textContent = minutes + ":" + seconds;
                            
                            if (distance <= 30000) { // Less than 30 seconds
                                document.getElementById('timer').classList.add('timer-warning');
                            }
                        }, 1000);
                    }

                    window.onload = function () {
                        var display = document.querySelector('#timeRemaining');
                        var endTime = localStorage.getItem('otp_end_time');
                        
                        if (endTime) {
                            var now = new Date().getTime();
                            var remaining = Math.max(0, Math.floor((endTime - now) / 1000));
                            
                            if (remaining > 0) {
                                startTimer(remaining, display);
                                document.getElementById('resendBtn').disabled = true;
                            } else {
                                display.textContent = "00:00";
                                document.getElementById('timer').classList.add('timer-warning');
                                document.getElementById('resendBtn').disabled = false;
                                localStorage.removeItem('otp_end_time');
                            }
                        } else {
                            // If no end time in storage, assume 2 minutes from now
                            startTimer(120, display);
                        }
                    };

                    // Auto-submit when 6 digits are entered
                    document.getElementById('otp_code').addEventListener('input', function(e) {
                        if (this.value.length === 6) {
                            document.getElementById('otpForm').submit();
                        }
                    });

                    // Prevent non-numeric input
                    document.getElementById('otp_code').addEventListener('keypress', function(e) {
                        var charCode = (e.which) ? e.which : e.keyCode;
                        if (charCode > 31 && (charCode < 48 || charCode > 57)) {
                            e.preventDefault();
                        }
                    });
                </script>

            <?php else: ?>
                <!-- Login Form -->
                <form method="POST" action="" class="auth-form">
                    <div class="form-group">
                        <label for="username"><i class="far fa-user" style="margin-right: 0.3rem;"></i>Username or email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-id-card"></i>
                            <input type="text" id="username" name="username" placeholder="e.g., nurse_maria" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock" style="margin-right: 0.3rem;"></i>Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key"></i>
                            <input type="password" id="password" name="password" placeholder="··········" required>
                        </div>
                    </div>

                    <div class="forgot-row">
                        <a href="forgot-password.php" class="forgot-link"><i class="fas fa-chevron-right"></i> forgot password?</a>
                    </div>

                    <button type="submit" name="login" class="btn btn-primary btn-block">
                        <i class="fas fa-arrow-right-to-bracket"></i> sign in
                    </button>
                </form>

                <div class="back-link">
                    <a href="index.php"><i class="fas fa-chevron-left"></i> back to home</a>
                </div>
            <?php endif; ?>

            <?php
            // Helper function to mask email
            function maskEmail($email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
                
                $parts = explode('@', $email);
                $name = $parts[0];
                $domain = $parts[1];
                
                $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
                
                return $maskedName . '@' . $domain;
            }
            ?>

            <div class="role-hint">
                <p>authorized personnel only</p>
                <div class="role-badges">
                    <span class="role-badge"><i class="fas fa-shield-alt"></i> superadmin</span>
                    <span class="role-badge"><i class="fas fa-user-nurse"></i> nurse</span>
                    <span class="role-badge"><i class="fas fa-user-tie"></i> admin</span>
                    <span class="role-badge"><i class="fas fa-user"></i> staff</span>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['login_step']) && $_SESSION['login_step'] == 'otp'): ?>
    <script>
        // Clear temporary session data when leaving the page
        window.addEventListener('beforeunload', function() {
            // Optionally clear OTP timer from localStorage
            // localStorage.removeItem('otp_end_time');
        });
    </script>
    <?php endif; ?>
</body>
</html>