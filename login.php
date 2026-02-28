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
    <title>Login - Clinic Management System</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #191970 100%);
        }

        .auth-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .auth-box {
            background: white;
            border-radius: 28px;
            padding: 40px 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: #191970;
            letter-spacing: -0.5px;
        }

        .auth-box h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #191970;
            margin-bottom: 10px;
            text-align: center;
        }

        .auth-box p {
            color: #546e7a;
            text-align: center;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #191970;
        }

        .form-group input {
            padding: 14px 16px;
            border: 1px solid #cfd8dc;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #191970;
            box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
        }

        .btn {
            padding: 14px;
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
        }

        .btn-primary:hover {
            background: #24248f;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(25, 25, 112, 0.2);
        }

        .btn-block {
            width: 100%;
        }

        .alert {
            padding: 14px;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background: #e8f5e8;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .auth-link {
            margin-top: 25px;
            font-size: 0.9rem;
            color: #546e7a;
            text-align: center;
        }

        .auth-link a {
            color: #191970;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-link a:hover {
            text-decoration: underline;
        }

        .forgot-password {
            text-align: right;
            margin-top: -10px;
        }

        .forgot-password a {
            color: #191970;
            font-size: 0.85rem;
            text-decoration: none;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        /* Role badges for demo purposes (optional) */
        .role-demo {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #cfd8dc;
            text-align: center;
            font-size: 0.8rem;
            color: #90a4ae;
        }

        .role-demo span {
            display: inline-block;
            padding: 4px 8px;
            margin: 0 4px;
            border-radius: 4px;
            background: #eceff1;
            color: #37474f;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="logo">
                <h1>⚕️ CMS</h1>
            </div>
            <h2>Welcome Back</h2>
            <p>Sign in to access the Clinic Management System</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">Registration successful! Please login.</div>
            <?php endif; ?>
            
            <?php if (isset($_GET['session_expired'])): ?>
                <div class="alert alert-error">Your session has expired. Please login again.</div>
            <?php endif; ?>
            
            <form method="POST" action="" class="auth-form">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username or email" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <div class="forgot-password">
                    <a href="forgot-password.php">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </form>
            <div class="auth-link">
                <p><a href="index.php">← Back to Home</a></p>
            </div>
            
            <!-- Demo role information - you can remove this in production -->
            <div class="role-demo">
                <p>Available roles:</p>
                <div style="margin-top: 8px;">
                    <span>Admin</span>
                    <span>Superadmin</span>
                    <span>Nurse</span>
                    <span>Staff</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>