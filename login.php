<?php
session_start();
require_once 'config/database.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
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
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                // Create session record in database (optional)
                $session_token = bin2hex(random_bytes(32));
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $session_query = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                                  VALUES (:user_id, :session_token, :ip_address, :user_agent, :expires_at)";
                $session_stmt = $db->prepare($session_query);
                $session_stmt->bindParam(':user_id', $user['id']);
                $session_stmt->bindParam(':session_token', $session_token);
                $session_stmt->bindParam(':ip_address', $ip_address);
                $session_stmt->bindParam(':user_agent', $user_agent);
                $session_stmt->bindParam(':expires_at', $expires_at);
                $session_stmt->execute();
                
                $_SESSION['session_token'] = $session_token;
                
                // Redirect based on role
                if ($user['role'] === 'superadmin') {
                    header('Location: superadmin/dashboard.php');
                } elseif ($user['role'] === 'nurse') {
                    header('Location: nurse/dashboard.php');
                } else {
                    header('Location: admin/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid password!';
            }
        } else {
            $error = 'User not found!';
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
                <h2>Sign In</h2>
                <p><i class="fas fa-shield-alt" style="margin-right: 0.3rem;"></i></p>
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
                    Registration successful! Please login.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['session_expired'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-clock"></i>
                    Your session has expired. Please login again.
                </div>
            <?php endif; ?>
            
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

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-arrow-right-to-bracket"></i> sign in
                </button>
            </form>

            <div class="back-link">
                <a href="index.php"><i class="fas fa-chevron-left"></i> back to home</a>
            </div>

           
        </div>
    </div>
</body>
</html>