<header class="admin-header">
    <div class="header-container">
        <div class="header-left">
            <div class="page-title">
                <h1 id="pageTitle">Student Dashboard</h1>
                <span class="page-badge">Student</span>
            </div>
        </div>

        <div class="header-right">
            <div class="header-date">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6V12L16 14"/>
                </svg>
                <span id="currentDateTime"></span>
            </div>

            <div class="profile-wrapper">
                <button class="profile-btn" id="profileBtn">
                    <div class="profile-avatar">
                        <span class="avatar-text">
                            <?php 
                            $student_data = $_SESSION['student_data'] ?? [];
                            $student_name = $student_data['full_name'] ?? $_SESSION['full_name'] ?? 'S';
                            $name_parts = explode(' ', $student_name);
                            $initials = '';
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            echo substr($initials, 0, 2);
                            ?>
                        </span>
                        <span class="avatar-status online"></span>
                    </div>
                    <div class="profile-info">
                        <span class="profile-name"><?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name'] ?? 'Student'); ?></span>
                        <span class="profile-role">Student</span>
                    </div>
                    <svg class="profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 9L12 15L18 9" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="profile-header">
                        <div class="profile-avatar-large">
                            <?php echo substr($initials, 0, 2); ?>
                        </div>
                        <div class="profile-details">
                            <h4><?php echo htmlspecialchars($student_data['full_name'] ?? $_SESSION['full_name'] ?? 'Student'); ?></h4>
                            <p><?php echo htmlspecialchars($student_data['email'] ?? $_SESSION['student_id'] ?? 'student@clinic.com'); ?></p>
                        </div>
                    </div>
                    <ul class="profile-menu">
                        <li><a href="profile.php">My Profile</a></li>
                        <li><a href="change_password.php">Change Password</a></li>
                        <li><a href="help.php">Help & Support</a></li>
                    </ul>
                    <div class="profile-footer">
                        <a href="../logout.php" class="logout-link">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
    /* Same header styles as admin header */
    .admin-header {
        background: white;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .header-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 24px;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 32px;
        flex: 1;
    }

    .page-title {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .page-title h1 {
        font-size: 1.5rem;
        font-weight: 600;
        color: #1e293b;
    }

    .page-badge {
        padding: 4px 10px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        color: white;
    }

    .header-right {
        display: flex;
        align-items: center;
    }

    .header-date {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #f8fafc;
        border-radius: 12px;
        color: #1e293b;
        font-size: 0.9rem;
        font-weight: 500;
        margin-right: 20px;
    }

    .header-date svg {
        width: 18px;
        height: 18px;
        color: #191970;
    }

    .profile-wrapper {
        position: relative;
        margin-left: 4px;
    }

    .profile-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 4px 12px 4px 4px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 40px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .profile-btn:hover {
        background: white;
        border-color: #191970;
    }

    .profile-avatar {
        position: relative;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .avatar-status {
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        border: 2px solid white;
    }

    .avatar-status.online {
        background: #10b981;
    }

    .profile-info {
        display: flex;
        flex-direction: column;
    }

    .profile-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1e293b;
    }

    .profile-role {
        font-size: 0.7rem;
        color: #64748b;
    }

    .profile-chevron {
        width: 16px;
        height: 16px;
        color: #94a3b8;
    }

    .profile-dropdown {
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        width: 250px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(0, 0, 0, 0.05);
        display: none;
        z-index: 1000;
    }

    .profile-dropdown.show {
        display: block;
        animation: slideDown 0.2s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .profile-header {
        padding: 20px;
        display: flex;
        gap: 14px;
        border-bottom: 1px solid #f1f5f9;
    }

    .profile-avatar-large {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 1.2rem;
    }

    .profile-details h4 {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }

    .profile-details p {
        font-size: 0.75rem;
        color: #64748b;
    }

    .profile-menu {
        padding: 8px;
        list-style: none;
    }

    .profile-menu li a {
        display: block;
        padding: 10px 16px;
        color: #1e293b;
        text-decoration: none;
        font-size: 0.9rem;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .profile-menu li a:hover {
        background: #f8fafc;
    }

    .profile-footer {
        padding: 12px;
        border-top: 1px solid #f1f5f9;
    }

    .logout-link {
        display: block;
        padding: 10px 16px;
        color: #ef4444;
        text-decoration: none;
        font-size: 0.9rem;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .logout-link:hover {
        background: #fef2f2;
    }

    @media (max-width: 1024px) {
        .profile-info {
            display: none;
        }
        
        .profile-chevron {
            display: none;
        }
        
        .header-date {
            display: none;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update date and time
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'short', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        const dateTimeElement = document.getElementById('currentDateTime');
        if (dateTimeElement) {
            dateTimeElement.textContent = now.toLocaleDateString('en-US', options);
        }
    }
    updateDateTime();
    setInterval(updateDateTime, 60000);

    // Toggle profile dropdown
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileBtn) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
        profileDropdown?.classList.remove('show');
    });

    // Prevent closing when clicking inside dropdowns
    if (profileDropdown) {
        profileDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
});
</script>