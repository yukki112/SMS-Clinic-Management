<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Session timeout settings
$timeout_duration = 120; // 2 minutes in seconds

// Check if last activity is set
if (isset($_SESSION['last_activity'])) {
    $elapsed_time = time() - $_SESSION['last_activity'];
    
    if ($elapsed_time > $timeout_duration) {
        // Session expired
        session_unset();
        session_destroy();
        header("Location: ../logout.php?timeout=1");
        exit();
    }
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Set session timeout warning at 1.5 minutes (90 seconds)
$warning_time = 90; // Show warning 30 seconds before timeout
$time_left = isset($_SESSION['last_activity']) ? 
    ($timeout_duration - (time() - $_SESSION['last_activity'])) : 
    $timeout_duration;
?>
<aside class="sidebar">
    <div class="sidebar-content">
        <!-- Session Timeout Timer Display -->
        <div class="session-timer" id="sessionTimer">
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <span>Session expires in: <span id="timerDisplay">2:00</span></span>
        </div>

        <div class="logo-area">
            <div class="logo-wrapper">
                <div class="logo-image">
                    <img src="../assets/images/clinic.png" alt="iClinic Logo">
                </div>
                <div class="logo-text">
                    <span class="logo-main">I CARE</span>
                    <span class="logo-sub">Clinic Management</span>
                </div>
            </div>
            <button class="collapse-btn" id="collapseSidebar">
                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                    <path d="M15 18L9 12L15 6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <div class="nav-container">
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Overview</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                        <a href="dashboard.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M3 9L12 3L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M9 22V12H15V22" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Dashboard</span>
                            <span class="nav-badge">9</span>
                        </a>
                    </li>
                 
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Patient Records</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'student_records.php' ? 'active' : ''; ?>">
                        <a href="student_records.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Students Medical Records</span>
                            <span class="nav-count">284</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'clinic_visits.php' ? 'active' : ''; ?>">
                        <a href="clinic_visits.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Clinic Visits & Consultation</span>
                            <span class="nav-count">47</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'medicine_requests.php' ? 'active' : ''; ?>">
                        <a href="medicine_requests.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M10.5 4.5L19.5 9.5L12 14L3 9.5L10.5 4.5Z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M3 14.5L10.5 19L19.5 14.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M3 9.5V19.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M19.5 9.5V19.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Medicine & Supplies Inventory</span>
                            <span class="nav-badge">12</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Health Services</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'incidents.php' ? 'active' : ''; ?>">
                        <a href="incidents.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M2 17L12 22L22 17" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M2 12L12 17L22 12" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Incidents & Emergencies</span>
                            <span class="nav-badge urgent">3</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'health_programs.php' ? 'active' : ''; ?>">
                        <a href="health_programs.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M12 6V12L16 14" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Health Programs</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'health_clearance.php' ? 'active' : ''; ?>">
                        <a href="health_clearance.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M22 4L12 14.01L9 11.01" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Health Clearance</span>
                            <span class="nav-count">28</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Administration</span>
                </div>
                <ul class="nav-menu">
                   
                    <li class="nav-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <a href="reports.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M3 3V21H21" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M7 15L10 11L13 14L20 7" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'user_management.php' ? 'active' : ''; ?>">
                        <a href="user_management.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">User Management</span>
                            <span class="nav-count">14</span>
                        </a>
                    </li>
                  
                </ul>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="user-profile-card">
                <?php
                // Assuming you have session started and user data stored
                // You can modify this based on your actual session variables
                $user_fullname = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Yukki';
                $user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Admin';
                $user_initials = '';
                
                // Generate initials from full name
                $name_parts = explode(' ', $user_fullname);
                foreach ($name_parts as $part) {
                    if (!empty($part)) {
                        $user_initials .= strtoupper(substr($part, 0, 1));
                    }
                }
                // Limit to 2 characters for initials
                $user_initials = substr($user_initials, 0, 2);
                ?>
                <div class="user-info">
                    <div class="user-avatar">
                        <span class="avatar-text"><?php echo $user_initials; ?></span>
                    </div>
                    <div class="user-details">
                        <h4 class="user-name"><?php echo htmlspecialchars($user_fullname); ?></h4>
                        <span class="user-role"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 17L21 12L16 7" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12H9" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Session Timeout Warning Modal -->
    <div id="timeoutModal" class="timeout-modal">
        <div class="timeout-modal-content">
            <div class="timeout-modal-header">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#f39c12" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <h3>Session Timeout Warning</h3>
            </div>
            <div class="timeout-modal-body">
                <p>Your session will expire in <span id="timeoutCountdown">30</span> seconds due to inactivity.</p>
                <p>Click "Stay Logged In" to continue your session.</p>
            </div>
            <div class="timeout-modal-footer">
                <button class="btn-logout" onclick="forceLogout()">Logout Now</button>
                <button class="btn-stay" onclick="resetSession()">Stay Logged In</button>
            </div>
        </div>
    </div>
</aside>

<style>
/* Existing styles remain the same, add these new styles at the end */

.session-timer {
    background: linear-gradient(135deg, #191970 0%, #2a2a8a 100%);
    color: white;
    padding: 12px 16px;
    border-radius: 16px;
    margin-bottom: 20px;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    animation: glow 2s ease-in-out infinite;
}

@keyframes glow {
    0%, 100% { box-shadow: 0 2px 10px rgba(25, 25, 112, 0.3); }
    50% { box-shadow: 0 4px 20px rgba(25, 25, 112, 0.5); }
}

.session-timer svg {
    flex-shrink: 0;
}

.session-timer span {
    flex: 1;
}

#timerDisplay {
    font-weight: 700;
    font-family: monospace;
    font-size: 1rem;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 8px;
    border-radius: 20px;
    margin-left: 4px;
}

.sidebar.collapsed .session-timer {
    padding: 8px;
    justify-content: center;
}

.sidebar.collapsed .session-timer span:not(#timerDisplay) {
    display: none;
}

.sidebar.collapsed #timerDisplay {
    font-size: 0.8rem;
    padding: 2px 4px;
}

/* Timeout Modal Styles */
.timeout-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.timeout-modal.show {
    display: flex;
}

.timeout-modal-content {
    background: white;
    border-radius: 28px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 20px 60px rgba(25, 25, 112, 0.3);
    overflow: hidden;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { transform: translateY(50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.timeout-modal-header {
    background: linear-gradient(135deg, #191970 0%, #2a2a8a 100%);
    color: white;
    padding: 24px;
    text-align: center;
}

.timeout-modal-header svg {
    margin-bottom: 12px;
}

.timeout-modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
}

.timeout-modal-body {
    padding: 24px;
    text-align: center;
}

.timeout-modal-body p {
    margin: 8px 0;
    color: #333;
    font-size: 1rem;
    line-height: 1.5;
}

.timeout-modal-body p:first-child {
    font-weight: 600;
    color: #191970;
}

#timeoutCountdown {
    font-size: 1.4rem;
    font-weight: 700;
    color: #c62828;
    font-family: monospace;
    padding: 0 4px;
}

.timeout-modal-footer {
    display: flex;
    padding: 16px 24px 24px;
    gap: 12px;
}

.timeout-modal-footer button {
    flex: 1;
    padding: 12px;
    border: none;
    border-radius: 14px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-logout {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
}

.btn-logout:hover {
    background: #c62828;
    color: white;
    border-color: #c62828;
}

.btn-stay {
    background: #191970;
    color: white;
    border: 1px solid #191970;
}

.btn-stay:hover {
    background: #2a2a8a;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(25, 25, 112, 0.3);
}

/* Warning state for timer */
.session-timer.warning {
    background: linear-gradient(135deg, #c62828 0%, #e53935 100%);
    animation: warningPulse 1s ease-in-out infinite;
}

@keyframes warningPulse {
    0%, 100% { 
        box-shadow: 0 2px 10px rgba(198, 40, 40, 0.3);
        transform: scale(1);
    }
    50% { 
        box-shadow: 0 4px 25px rgba(198, 40, 40, 0.6);
        transform: scale(1.02);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const collapseBtn = document.getElementById('collapseSidebar');
    
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }

    // Session timeout functionality
    let timeoutDuration = 120; // 2 minutes in seconds
    let warningTime = 30; // Show warning 30 seconds before timeout
    let timeLeft = <?php echo $time_left; ?>;
    let timeoutInterval;
    let warningShown = false;
    
    const timerDisplay = document.getElementById('timerDisplay');
    const sessionTimer = document.querySelector('.session-timer');
    const timeoutModal = document.getElementById('timeoutModal');
    const timeoutCountdown = document.getElementById('timeoutCountdown');
    
    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    
    function updateTimer() {
        timeLeft--;
        
        if (timeLeft <= 0) {
            // Time's up - redirect to logout
            clearInterval(timeoutInterval);
            window.location.href = '../logout.php?timeout=1';
            return;
        }
        
        // Update timer display
        timerDisplay.textContent = formatTime(timeLeft);
        
        // Show warning modal when 30 seconds left
        if (timeLeft <= warningTime && !warningShown) {
            showWarningModal();
            warningShown = true;
            sessionTimer.classList.add('warning');
        }
        
        // Change color when less than 1 minute
        if (timeLeft <= 60) {
            sessionTimer.style.background = 'linear-gradient(135deg, #e65100 0%, #f57c00 100%)';
        }
        
        // Reset session activity on user interaction
        resetSessionOnActivity();
    }
    
    // Start the timer
    if (timeLeft > 0) {
        timerDisplay.textContent = formatTime(timeLeft);
        timeoutInterval = setInterval(updateTimer, 1000);
    }
    
    function showWarningModal() {
        timeoutModal.classList.add('show');
        
        // Countdown in modal
        let modalTimeLeft = warningTime;
        const modalInterval = setInterval(() => {
            modalTimeLeft--;
            timeoutCountdown.textContent = modalTimeLeft;
            
            if (modalTimeLeft <= 0) {
                clearInterval(modalInterval);
                timeoutModal.classList.remove('show');
                window.location.href = '../logout.php?timeout=1';
            }
        }, 1000);
        
        // Store interval to clear if user stays logged in
        timeoutModal.dataset.interval = modalInterval;
    }
    
    // Function to reset session
    window.resetSession = function() {
        // Clear the modal interval
        if (timeoutModal.dataset.interval) {
            clearInterval(parseInt(timeoutModal.dataset.interval));
        }
        
        // Hide modal
        timeoutModal.classList.remove('show');
        
        // Reset timer
        timeLeft = timeoutDuration;
        warningShown = false;
        sessionTimer.classList.remove('warning');
        sessionTimer.style.background = 'linear-gradient(135deg, #191970 0%, #2a2a8a 100%)';
        
        // Send AJAX request to reset session on server
        fetch('../reset_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        });
    };
    
    // Force logout function
    window.forceLogout = function() {
        window.location.href = '../logout.php';
    };
    
    // Reset session on user activity
    function resetSessionOnActivity() {
        let activityTimer;
        
        const resetTimer = () => {
            clearTimeout(activityTimer);
            activityTimer = setTimeout(() => {
                // Don't reset automatically, let the main timer handle it
            }, 5000);
        };
        
        // Listen for user activity
        ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(eventType => {
            document.addEventListener(eventType, resetTimer);
        });
    }
});

// Add this to your logout.php to handle timeout parameter
// In logout.php, you can show a message: "Your session has expired due to inactivity."
</script>