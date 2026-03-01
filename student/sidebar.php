<?php
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Get student data from session
$student_data = $_SESSION['student_data'] ?? [];
$student_name = $student_data['full_name'] ?? $_SESSION['full_name'] ?? 'Student';
$student_id = $_SESSION['student_id'] ?? '';

// Generate initials
$name_parts = explode(' ', $student_name);
$initials = '';
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials, 0, 2);
?>
<aside class="sidebar">
    <div class="sidebar-content">
        <div class="logo-area">
            <div class="logo-wrapper">
                <div class="logo-image">
                    <img src="../assets/images/clinic.png" alt="iClinic Logo">
                </div>
                <div class="logo-text">
                    <span class="logo-main">I CARE</span>
                    <span class="logo-sub">Student Portal</span>
                </div>
            </div>
            <button class="collapse-btn" id="collapseSidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 18L9 12L15 6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <div class="nav-container">
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Student Dashboard</span>
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
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Health Services</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'request_appointment.php' ? 'active' : ''; ?>">
                        <a href="request_appointment.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6V12L16 14"/>
                                </svg>
                            </div>
                            <span class="nav-label">Request Appointment</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'request_clearance.php' ? 'active' : ''; ?>">
                        <a href="request_clearance.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                    <path d="M22 4L12 14.01L9 11.01"/>
                                </svg>
                            </div>
                            <span class="nav-label">Request Clearance</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>My Records</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'visit_history.php' ? 'active' : ''; ?>">
                        <a href="visit_history.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Visit History</span>
                        </a>
                    </li>
                  
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Account</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                        <a href="profile.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                                </svg>
                            </div>
                            <span class="nav-label">My Profile</span>
                        </a>
                    </li>
                   
                </ul>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="user-profile-card">
                <div class="user-info">
                    <div class="user-avatar">
                        <span class="avatar-text"><?php echo $initials; ?></span>
                    </div>
                    <div class="user-details">
                        <h4 class="user-name"><?php echo htmlspecialchars($student_name); ?></h4>
                        <span class="user-role">Student</span>
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

    <!-- Session Timeout Modal (same as admin sidebar) -->
    <div class="session-modal-overlay" id="sessionModalOverlay">
        <div class="session-modal">
            <div class="session-modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 6V12L16 14" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h3 class="session-modal-title">Session Timeout Warning</h3>
            <p class="session-modal-message">Your session will expire in</p>
            <div class="session-timer" id="sessionTimer">02:00</div>
            <p class="session-modal-message">due to inactivity.</p>
            <div class="session-modal-actions">
                <button class="session-btn session-btn-primary" id="continueSession">Continue Session</button>
                <button class="session-btn session-btn-danger" id="logoutNow">Logout Now</button>
            </div>
        </div>
    </div>
</aside>

<style>
    /* Same sidebar styles as admin sidebar */
    .sidebar {
        position: fixed;
        left: 20px;
        top: 20px;
        bottom: 20px;
        width: 300px;
        background: white;
        border-radius: 28px;
        border: 1px solid rgba(25, 25, 112, 0.1);
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.08);
        overflow: hidden;
        z-index: 1000;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .sidebar.collapsed {
        width: 90px;
    }

    .sidebar.collapsed .logo-text,
    .sidebar.collapsed .nav-label,
    .sidebar.collapsed .nav-section-title span,
    .sidebar.collapsed .user-details {
        display: none;
    }

    .sidebar.collapsed .nav-icon-wrapper {
        margin: 0 auto;
    }

    .sidebar.collapsed .logo-wrapper {
        justify-content: center;
    }

    .sidebar.collapsed .collapse-btn svg {
        transform: rotate(180deg);
    }

    .sidebar.collapsed .user-info {
        justify-content: center;
        margin-bottom: 0;
    }

    .sidebar.collapsed .user-avatar {
        margin-bottom: 8px;
    }

    .sidebar.collapsed .logout-btn span {
        display: none;
    }

    .sidebar-content {
        position: relative;
        height: 100%;
        display: flex;
        flex-direction: column;
        padding: 24px 16px;
        color: #191970;
    }

    .logo-area {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 32px;
        padding: 0 8px;
    }

    .logo-wrapper {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-image {
        width: 42px;
        height: 42px;
        background: #191970;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .logo-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .logo-text {
        display: flex;
        flex-direction: column;
    }

    .logo-main {
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: -0.5px;
        color: #191970;
        line-height: 1.2;
    }

    .logo-sub {
        font-size: 0.7rem;
        color: #546e7a;
        letter-spacing: 0.5px;
    }

    .collapse-btn {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: 1px solid rgba(25, 25, 112, 0.1);
        background: #eceff1;
        color: #191970;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .collapse-btn:hover {
        background: #191970;
        color: white;
        transform: scale(1.05);
    }

    .nav-container {
        flex: 1;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #191970 #eceff1;
        padding: 0 4px;
    }

    .nav-container::-webkit-scrollbar {
        width: 4px;
    }

    .nav-container::-webkit-scrollbar-track {
        background: #eceff1;
    }

    .nav-container::-webkit-scrollbar-thumb {
        background: #191970;
        border-radius: 20px;
    }

    .nav-section {
        margin-bottom: 28px;
    }

    .nav-section-title {
        padding: 0 16px;
        margin-bottom: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #546e7a;
    }

    .nav-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .nav-item {
        margin-bottom: 4px;
    }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        border-radius: 14px;
        color: #546e7a;
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
    }

    .nav-item.active .nav-link {
        background: #191970;
        color: white;
    }

    .nav-link:hover {
        background: #eceff1;
        color: #191970;
    }

    .nav-icon-wrapper {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #eceff1;
        transition: all 0.3s ease;
    }

    .nav-item.active .nav-icon-wrapper {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .nav-link:hover .nav-icon-wrapper {
        background: white;
        color: #191970;
    }

    .nav-icon {
        width: 18px;
        height: 18px;
    }

    .nav-label {
        flex: 1;
        font-size: 0.9rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .sidebar-footer {
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #eceff1;
    }

    .user-profile-card {
        background: #eceff1;
        border-radius: 20px;
        padding: 16px;
        border: 1px solid rgba(25, 25, 112, 0.1);
        transition: all 0.3s ease;
    }

    .sidebar.collapsed .user-profile-card {
        padding: 12px 8px;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }

    .user-avatar {
        width: 48px;
        height: 48px;
        background: #191970;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        flex-shrink: 0;
    }

    .avatar-text {
        font-size: 1.1rem;
        font-weight: 600;
        letter-spacing: 1px;
    }

    .user-details {
        flex: 1;
        overflow: hidden;
    }

    .user-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-role {
        font-size: 0.7rem;
        color: #546e7a;
        display: inline-block;
        padding: 2px 8px;
        background: white;
        border-radius: 20px;
        border: 1px solid rgba(25, 25, 112, 0.1);
    }

    .logout-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 10px;
        background: white;
        border-radius: 14px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #c62828;
        text-decoration: none;
        transition: all 0.3s ease;
        border: 1px solid rgba(198, 40, 40, 0.2);
    }

    .logout-btn svg {
        width: 18px;
        height: 18px;
        transition: all 0.3s ease;
    }

    .logout-btn:hover {
        background: #c62828;
        color: white;
        border-color: #c62828;
    }

    .logout-btn:hover svg {
        transform: translateX(3px);
    }

    /* Session Modal Styles */
    .session-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        backdrop-filter: blur(5px);
        animation: fadeIn 0.3s ease;
    }

    .session-modal-overlay.active {
        display: flex;
    }

    .session-modal {
        background: white;
        border-radius: 28px;
        padding: 32px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(25, 25, 112, 0.3);
        animation: slideUp 0.3s ease;
        border: 1px solid rgba(25, 25, 112, 0.1);
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
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

    .session-modal-icon {
        width: 80px;
        height: 80px;
        background: #191970;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        color: white;
        animation: pulse 2s infinite;
    }

    .session-modal-icon svg {
        width: 40px;
        height: 40px;
    }

    .session-modal-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #191970;
        margin-bottom: 12px;
    }

    .session-modal-message {
        font-size: 1rem;
        color: #546e7a;
        margin-bottom: 8px;
    }

    .session-timer {
        font-size: 3rem;
        font-weight: 700;
        color: #c62828;
        margin: 15px 0;
        font-family: monospace;
        text-shadow: 0 2px 4px rgba(198, 40, 40, 0.2);
    }

    .session-modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }

    .session-btn {
        flex: 1;
        padding: 12px;
        border-radius: 14px;
        font-size: 1rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
    }

    .session-btn-primary {
        background: #191970;
        color: white;
    }

    .session-btn-primary:hover {
        background: #0f0f4b;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(25, 25, 112, 0.3);
    }

    .session-btn-danger {
        background: #ffebee;
        color: #c62828;
        border: 1px solid #ffcdd2;
    }

    .session-btn-danger:hover {
        background: #c62828;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(198, 40, 40, 0.3);
    }

    @media (max-width: 768px) {
        .sidebar {
            left: 10px;
            top: 10px;
            bottom: 10px;
        }
        
        .session-modal {
            padding: 24px;
            margin: 16px;
        }
        
        .session-timer {
            font-size: 2.5rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar collapse functionality
    const sidebar = document.querySelector('.sidebar');
    const collapseBtn = document.getElementById('collapseSidebar');
    
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }

    // Session Timeout Functionality (same as admin sidebar)
    const SESSION_TIMEOUT = 2 * 60; // 2 minutes in seconds
    const WARNING_TIME = 30;
    let timeLeft = SESSION_TIMEOUT;
    let warningShown = false;
    let countdownInterval;
    
    const modalOverlay = document.getElementById('sessionModalOverlay');
    const sessionTimer = document.getElementById('sessionTimer');
    const continueBtn = document.getElementById('continueSession');
    const logoutBtn = document.getElementById('logoutNow');
    
    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    
    function updateCountdown() {
        timeLeft--;
        
        if (timeLeft <= 0) {
            window.location.href = '../logout.php';
        } else if (timeLeft <= WARNING_TIME && !warningShown) {
            showWarningModal();
        }
    }
    
    function showWarningModal() {
        warningShown = true;
        modalOverlay.classList.add('active');
        
        let modalTimeLeft = WARNING_TIME;
        sessionTimer.textContent = formatTime(modalTimeLeft);
        
        if (countdownInterval) {
            clearInterval(countdownInterval);
        }
        
        const modalInterval = setInterval(() => {
            modalTimeLeft--;
            sessionTimer.textContent = formatTime(modalTimeLeft);
            
            if (modalTimeLeft <= 0) {
                clearInterval(modalInterval);
                window.location.href = '../logout.php';
            }
        }, 1000);
        
        window.modalInterval = modalInterval;
    }
    
    function resetSession() {
        timeLeft = SESSION_TIMEOUT;
        warningShown = false;
        modalOverlay.classList.remove('active');
        
        if (window.modalInterval) {
            clearInterval(window.modalInterval);
        }
        
        if (countdownInterval) {
            clearInterval(countdownInterval);
        }
        countdownInterval = setInterval(updateCountdown, 1000);
    }
    
    continueBtn.addEventListener('click', function() {
        resetSession();
    });
    
    logoutBtn.addEventListener('click', function() {
        window.location.href = '../logout.php';
    });
    
    function handleUserActivity() {
        resetSession();
    }
    
    ['mousedown', 'keydown', 'scroll', 'mousemove'].forEach(eventType => {
        document.addEventListener(eventType, handleUserActivity);
    });
    
    resetSession();
    
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            resetSession();
        }
    });
    
    window.addEventListener('beforeunload', function() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
        }
        if (window.modalInterval) {
            clearInterval(window.modalInterval);
        }
    });
});
</script>