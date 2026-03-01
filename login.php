<?php
session_start();
require_once 'config/database.php';
require_once 'config/student_auth.php';
require_once 'config/otp_helper.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'student') {
        header('Location: student/dashboard.php');
        exit();
    } elseif ($_SESSION['role'] === 'superadmin') {
        header('Location: superadmin/dashboard.php');
        exit();
    } elseif ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
        exit();
    } elseif ($_SESSION['role'] === 'nurse') {
        header('Location: nurse/dashboard.php');
        exit();
    } elseif ($_SESSION['role'] === 'staff') {
        header('Location: admin/dashboard.php');
        exit();
    }
    exit();
}

$error = '';
$studentAuth = new StudentAuth();
$database = new Database();
$db = $database->getConnection();
$otpHelper = new OTPHelper($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = trim($_POST['student_id']);
    $password = $_POST['password'];
    
    if (empty($student_id) || empty($password)) {
        $error = 'Please enter student ID and password';
    } else {
        // First try student login (fast check without full sync)
        $result = $studentAuth->quickLogin($student_id, $password);
        
        if ($result['success']) {
            // Student found - proceed to OTP verification
            $_SESSION['otp_user_id'] = $result['user']['id'];
            $_SESSION['otp_username'] = $result['user']['username'];
            $_SESSION['otp_full_name'] = $result['user']['full_name'];
            $_SESSION['otp_email'] = $result['user']['email'];
            $_SESSION['otp_role'] = $result['user']['role'];
            
            // Generate and send OTP
            $otp = $otpHelper->generateOTP($result['user']['id'], $result['user']['email']);
            $otpHelper->sendOTPEmail($result['user']['email'], $result['user']['full_name'], $otp);
            
            header('Location: verify-otp.php');
            exit();
        } else {
            // If not student, try staff/admin login
            try {
                $query = "SELECT * FROM users WHERE (username = :username OR email = :email) AND role != 'student'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $student_id);
                $stmt->bindParam(':email', $student_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (password_verify($password, $user['password'])) {
                        // Staff/Admin found - proceed to OTP verification
                        $_SESSION['otp_user_id'] = $user['id'];
                        $_SESSION['otp_username'] = $user['username'];
                        $_SESSION['otp_full_name'] = $user['full_name'];
                        $_SESSION['otp_email'] = $user['email'];
                        $_SESSION['otp_role'] = $user['role'];
                        
                        // Generate and send OTP
                        $otp = $otpHelper->generateOTP($user['id'], $user['email']);
                        $otpHelper->sendOTPEmail($user['email'], $user['full_name'], $otp);
                        
                        header('Location: verify-otp.php');
                        exit();
                    } else {
                        $error = 'Invalid password';
                    }
                } else {
                    $error = 'User not found';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
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
    <title>Login · ICARE Clinic Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Same styles as before - keeping it compact */
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .auth-card:hover { transform: scale(1.01); }
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
        .alert i { font-size: 1.3rem; }
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
        .info-row {
            display: flex;
            justify-content: center;
            margin-top: -0.8rem;
        }
        .info-text {
            color: #191970;
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0.6;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
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
        .btn-block { width: 100%; }
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
        .back-link a:hover { opacity: 1; }
        .otp-note {
            background: #e3f2fd;
            border-radius: 12px;
            padding: 10px;
            text-align: center;
            font-size: 0.85rem;
            margin-top: 15px;
            color: #1565c0;
            border: 1px solid #bbdefb;
        }
        .otp-note i { margin-right: 5px; }
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
                <h2>Welcome Back!</h2>
                <p>Sign in to your account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Account created! Default password is <strong>0000</strong>. Please change your password.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="auth-form" id="loginForm">
                <div class="form-group">
                    <label for="student_id"><i class="far fa-id-card" style="margin-right: 0.3rem;"></i>Username / Student ID / Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="student_id" name="student_id" placeholder="Enter your username, student ID, or email" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fas fa-lock" style="margin-right: 0.3rem;"></i>Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-key"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-text"><i class="fas fa-info-circle"></i> Students: Default password is 0000</span>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                    <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                </button>
            </form>

            <div class="otp-note">
                <i class="fas fa-shield-alt"></i> For security, we'll send a verification code to your email after login.
            </div>

            <div class="back-link">
                <a href="index.php"><i class="fas fa-chevron-left"></i> back to home</a>
            </div>
        </div>
    </div>

    <script>
        // Add loading state to prevent double submission
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
            btn.disabled = true;
        });
    </script>
</body>
</html>