<?php
session_start();
require_once 'config/database.php';
require_once 'config/otp_helper.php';

// Check if user is in OTP verification stage
if (!isset($_SESSION['otp_user_id']) || !isset($_SESSION['otp_email']) || !isset($_SESSION['otp_full_name'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$otpHelper = new OTPHelper($db);

$error = '';
$success = '';

// Resend OTP
if (isset($_POST['resend'])) {
    $otp = $otpHelper->generateOTP($_SESSION['otp_user_id'], $_SESSION['otp_email']);
    
    if ($otpHelper->sendOTPEmail($_SESSION['otp_email'], $_SESSION['otp_full_name'], $otp)) {
        $success = 'A new verification code has been sent to your email.';
    } else {
        $error = 'Failed to send verification code. Please try again.';
    }
}

// Verify OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify'])) {
    $otp = trim($_POST['otp']);
    
    if (empty($otp)) {
        $error = 'Please enter the verification code.';
    } elseif (!preg_match('/^[0-9]{6}$/', $otp)) {
        $error = 'Please enter a valid 6-digit code.';
    } else {
        if ($otpHelper->verifyOTP($_SESSION['otp_user_id'], $otp)) {
            // OTP verified - complete login
            $_SESSION['user_id'] = $_SESSION['otp_user_id'];
            $_SESSION['username'] = $_SESSION['otp_username'];
            $_SESSION['full_name'] = $_SESSION['otp_full_name'];
            $_SESSION['email'] = $_SESSION['otp_email'];
            $_SESSION['role'] = $_SESSION['otp_role'];
            $_SESSION['login_time'] = time();
            
            // Create session record
            $session_token = bin2hex(random_bytes(32));
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $session_query = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                              VALUES (:user_id, :session_token, :ip_address, :user_agent, :expires_at)";
            $session_stmt = $db->prepare($session_query);
            $session_stmt->bindParam(':user_id', $_SESSION['otp_user_id']);
            $session_stmt->bindParam(':session_token', $session_token);
            $session_stmt->bindParam(':ip_address', $ip_address);
            $session_stmt->bindParam(':user_agent', $user_agent);
            $session_stmt->bindParam(':expires_at', $expires_at);
            $session_stmt->execute();
            
            $_SESSION['session_token'] = $session_token;
            
            // Clean up OTP session data
            unset($_SESSION['otp_user_id']);
            unset($_SESSION['otp_username']);
            unset($_SESSION['otp_full_name']);
            unset($_SESSION['otp_email']);
            unset($_SESSION['otp_role']);
            
            // Clean up expired OTPs
            $otpHelper->cleanupExpiredOTPs();
            
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
            $error = 'Invalid or expired verification code. Please try again.';
        }
    }
}

// Auto cleanup on page load
$otpHelper->cleanupExpiredOTPs();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP · ICARE</title>
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

        .auth-container {
            width: 100%;
            max-width: 500px;
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
            margin-bottom: 1.5rem;
        }

        .auth-header h2 {
            font-size: 2rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 0.4rem;
        }

        .auth-header p {
            color: #191970;
            opacity: 0.7;
            font-size: 0.95rem;
        }

        .email-info {
            background: rgba(25, 25, 112, 0.05);
            padding: 1rem;
            border-radius: 50px;
            text-align: center;
            margin-bottom: 1.5rem;
            font-weight: 500;
            border: 1px solid rgba(25, 25, 112, 0.1);
        }

        .email-info i {
            margin-right: 0.5rem;
            color: #191970;
        }

        .email-info span {
            font-weight: 600;
            color: #191970;
        }

        .alert {
            padding: 1.1rem 1.5rem;
            border-radius: 100px;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
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
            text-align: center;
            letter-spacing: 4px;
            font-size: 1.5rem;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 10px 20px -12px #191970;
            background: white;
        }

        .input-wrapper input::placeholder {
            color: #191970;
            opacity: 0.3;
            font-weight: 400;
            letter-spacing: normal;
            font-size: 1rem;
        }

        .timer {
            text-align: center;
            font-size: 0.95rem;
            color: #191970;
            opacity: 0.7;
            margin-top: -0.5rem;
        }

        .timer i {
            margin-right: 0.3rem;
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

        .btn-outline {
            background: transparent;
            color: #191970;
            border: 2px solid rgba(25, 25, 112, 0.3);
            box-shadow: none;
        }

        .btn-outline:hover {
            border-color: #191970;
            background: rgba(25, 25, 112, 0.05);
            transform: translateY(-2px);
        }

        .btn-block {
            width: 100%;
        }

        .resend-link {
            text-align: center;
            margin-top: 1rem;
        }

        .resend-link form {
            display: inline;
        }

        .resend-btn {
            background: none;
            border: none;
            color: #191970;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 3px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .resend-btn:hover {
            opacity: 1;
        }

        .back-link {
            text-align: center;
            margin-top: 2rem;
        }

        .back-link a {
            color: #191970;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.95rem;
            opacity: 0.7;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: opacity 0.2s;
        }

        .back-link a:hover {
            opacity: 1;
        }

        @media (max-width: 500px) {
            .auth-card { padding: 2rem 1.5rem; }
            .logo-text { font-size: 2rem; }
            .logo-text span { font-size: 1.6rem; }
        }
    </style>
</head>
<body>
    <div class="bg-shape"></div>
    <div class="bg-shape-two"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="logo-wrapper">
                <img src="assets/images/clinic.png" alt="ICARE" class="logo-img" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'56\' height=\'56\' viewBox=\'0 0 24 24\' fill=\'%23191970\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E';">
                <span class="logo-text">ICARE<span>clinic</span></span>
            </div>

            <div class="auth-header">
                <h2>Verification</h2>
                <p>Enter the 6-digit code sent to your email</p>
            </div>

            <div class="email-info">
                <i class="fas fa-envelope"></i>
                Code sent to: <span><?php echo htmlspecialchars($_SESSION['otp_email']); ?></span>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="auth-form">
                <div class="form-group">
                    <label for="otp"><i class="fas fa-shield-halved"></i> Verification Code</label>
                    <div class="input-wrapper">
                        <i class="fas fa-key"></i>
                        <input type="text" id="otp" name="otp" placeholder="000000" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required>
                    </div>
                </div>

                <div class="timer" id="timer">
                    <i class="far fa-clock"></i>
                    Code expires in <span id="countdown">02:00</span>
                </div>

                <button type="submit" name="verify" class="btn btn-primary btn-block">
                    <i class="fas fa-check-circle"></i> verify & continue
                </button>
            </form>

            <div class="resend-link">
                <form method="POST" action="">
                    <button type="submit" name="resend" class="resend-btn">
                        <i class="fas fa-rotate-right"></i> resend code
                    </button>
                </form>
            </div>

            <div class="back-link">
                <a href="login.php"><i class="fas fa-chevron-left"></i> back to login</a>
            </div>
        </div>
    </div>

    <script>
        // Countdown timer for OTP expiry (2 minutes = 120 seconds)
        let timeLeft = 120;
        const countdownEl = document.getElementById('countdown');
        
        function updateCountdown() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            countdownEl.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft > 0) {
                timeLeft--;
            } else {
                countdownEl.textContent = '00:00';
                document.querySelector('.timer').style.color = '#b71c1c';
            }
        }
        
        updateCountdown();
        setInterval(updateCountdown, 1000);
    </script>
</body>
</html>