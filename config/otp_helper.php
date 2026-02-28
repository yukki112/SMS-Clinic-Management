<?php
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class OTPHelper {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Generate and save OTP for user
     */
    public function generateOTP($user_id, $email) {
        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(1, 999999));
        
        // Set expiry to 2 minutes from now
        $expires_at = date('Y-m-d H:i:s', strtotime('+2 minutes'));
        
        // Delete any existing OTPs for this user
        $delete_query = "DELETE FROM login_otps WHERE user_id = :user_id";
        $delete_stmt = $this->db->prepare($delete_query);
        $delete_stmt->bindParam(':user_id', $user_id);
        $delete_stmt->execute();
        
        // Insert new OTP
        $insert_query = "INSERT INTO login_otps (user_id, otp_code, email, expires_at) 
                         VALUES (:user_id, :otp_code, :email, :expires_at)";
        $insert_stmt = $this->db->prepare($insert_query);
        $insert_stmt->bindParam(':user_id', $user_id);
        $insert_stmt->bindParam(':otp_code', $otp);
        $insert_stmt->bindParam(':email', $email);
        $insert_stmt->bindParam(':expires_at', $expires_at);
        $insert_stmt->execute();
        
        return $otp;
    }
    
    /**
     * Send OTP via email using PHPMailer
     */
    public function sendOTPEmail($email, $full_name, $otp) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; // Change this to your SMTP server
            $mail->SMTPAuth   = true;
            $mail->Username = 'Stephenviray12@gmail.com';
            $mail->Password = 'bubr nckn tgqf lvus';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('noreply@icareclinic.com', 'ICARE Clinic');
            $mail->addAddress($email, $full_name);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your ICARE Login Verification Code';
            $mail->Body    = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Inter', Arial, sans-serif; background-color: #f5f5f5; }
                    .container { max-width: 500px; margin: 0 auto; padding: 30px; background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                    .header { text-align: center; margin-bottom: 30px; }
                    .logo { font-size: 28px; font-weight: 800; color: #191970; }
                    .otp-code { background: #f0f0f7; padding: 20px; text-align: center; font-size: 42px; font-weight: 800; letter-spacing: 8px; color: #191970; border-radius: 15px; margin: 25px 0; }
                    .expiry { color: #666; font-size: 14px; text-align: center; }
                    .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #999; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <div class='logo'>ICARE <span style='font-weight:400;'>clinic</span></div>
                        <p style='color: #666;'>Login Verification</p>
                    </div>
                    
                    <p>Hello <strong>{$full_name}</strong>,</p>
                    
                    <p>You've requested to log in to your ICARE account. Please use the following verification code:</p>
                    
                    <div class='otp-code'>{$otp}</div>
                    
                    <p class='expiry'>This code will expire in <strong>2 minutes</strong>.</p>
                    
                    <p>If you didn't attempt to log in, please ignore this email or contact your system administrator.</p>
                    
                    <div class='footer'>
                        &copy; " . date('Y') . " ICARE Clinic. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->AltBody = "Your ICARE login verification code is: {$otp}\nThis code will expire in 2 minutes.";
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("OTP Email Error: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Verify OTP
     */
    public function verifyOTP($user_id, $otp) {
        $query = "SELECT * FROM login_otps 
                  WHERE user_id = :user_id 
                  AND otp_code = :otp_code 
                  AND verified = 0 
                  AND expires_at > NOW() 
                  ORDER BY id DESC LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':otp_code', $otp);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Mark as verified
            $update_query = "UPDATE login_otps SET verified = 1 WHERE id = :id";
            $update_stmt = $this->db->prepare($update_query);
            $update_stmt->bindParam(':id', $result['id']);
            $update_stmt->execute();
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Clean up expired OTPs
     */
    public function cleanupExpiredOTPs() {
        $query = "DELETE FROM login_otps WHERE expires_at <= NOW()";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
    }
}
?>