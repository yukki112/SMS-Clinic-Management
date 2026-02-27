<header class="admin-header">
    <div class="header-container">
        <div class="header-left">
            <div class="page-title">
                <h1 id="pageTitle">Dashboard</h1>
                <span class="page-badge">Admin</span>
            </div>
            
            <div class="header-search">
                <div class="search-wrapper">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 21L16.5 16.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <input type="text" placeholder="Search patients, records, appointments..." id="globalSearch">
                </div>
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

            <div class="header-actions">
                <div class="action-item">
                    <button class="action-btn" id="quickActionBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="12" cy="8" r="4"/>
                            <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                        </svg>
                    </button>
                    <div class="action-dropdown" id="quickActionDropdown">
                        <div class="dropdown-header">
                            <h4>Quick Actions</h4>
                        </div>
                        <a href="add-patient.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20"/>
                            </svg>
                            <span>Add New Patient</span>
                        </a>
                        <a href="schedule-appointment.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6V12L16 14"/>
                            </svg>
                            <span>Schedule Appointment</span>
                        </a>
                        <a href="medical-records.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20"/>
                                <path d="M6.5 2H20V22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2V2Z"/>
                            </svg>
                            <span>Medical Records</span>
                        </a>
                    </div>
                </div>

                <div class="action-item">
                    <button class="action-btn" id="notificationBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M18 8C18 6.4087 17.3679 4.88258 16.2426 3.75736C15.1174 2.63214 13.5913 2 12 2C10.4087 2 8.88258 2.63214 7.75736 3.75736C6.63214 4.88258 6 6.4087 6 8C6 15 3 17 3 17H21C21 17 18 15 18 8Z"/>
                            <path d="M13.73 21C13.5542 21.3031 13.3019 21.5547 12.9982 21.7295C12.6946 21.9044 12.3504 21.9965 12 21.9965C11.6496 21.9965 11.3054 21.9044 11.0018 21.7295C10.6982 21.5547 10.4458 21.3031 10.27 21"/>
                        </svg>
                        <span class="notification-badge">3</span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>Notifications</h4>
                            <button class="mark-all-read">Mark all read</button>
                        </div>
                        <div class="notification-list">
                            <div class="notification-item unread">
                                <div class="notification-icon urgent">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 8V12L12 16"/>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-text">Emergency incident reported</p>
                                    <span class="notification-time">2 minutes ago</span>
                                </div>
                            </div>
                            <div class="notification-item unread">
                                <div class="notification-icon success">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                        <path d="M22 4L12 14.01L9 11.01"/>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-text">Health clearance approved</p>
                                    <span class="notification-time">15 minutes ago</span>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-footer">
                            <a href="#">View all notifications</a>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <button class="profile-btn" id="profileBtn">
                        <div class="profile-avatar">
                            <span class="avatar-text"><?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 2)); ?></span>
                            <span class="avatar-status online"></span>
                        </div>
                        <div class="profile-info">
                            <span class="profile-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span>
                            <span class="profile-role">Administrator</span>
                        </div>
                        <svg class="profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9L12 15L18 9" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-header">
                            <div class="profile-avatar-large">
                                <?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 2)); ?>
                            </div>
                            <div class="profile-details">
                                <h4><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></h4>
                                <p><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@clinic.com'); ?></p>
                            </div>
                        </div>
                        <ul class="profile-menu">
                            <li><a href="profile.php">My Profile</a></li>
                            <li><a href="settings.php">Settings</a></li>
                            <li><a href="help.php">Help & Support</a></li>
                        </ul>
                        <div class="profile-footer">
                            <a href="../logout.php" class="logout-link">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
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
    background: linear-gradient(135deg, #191970, #2a2a8a);
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    color: white;
}

.header-search {
    flex: 1;
    max-width: 400px;
}

.search-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 8px 16px;
    transition: all 0.3s ease;
}

.search-wrapper:focus-within {
    border-color: #191970;
    background: white;
    box-shadow: 0 4px 12px rgba(25, 25, 112, 0.1);
}

.search-icon {
    width: 18px;
    height: 18px;
    color: #64748b;
}

.search-wrapper input {
    border: none;
    background: none;
    outline: none;
    font-size: 0.9rem;
    color: #1e293b;
    width: 100%;
}

.search-wrapper input::placeholder {
    color: #94a3b8;
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

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.action-item {
    position: relative;
}

.action-btn {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: white;
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: relative;
}

.action-btn:hover {
    background: #f8fafc;
    color: #191970;
    border-color: #191970;
}

.notification-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    background: #ef4444;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.6rem;
    font-weight: 600;
    border: 2px solid white;
}

.action-dropdown,
.notification-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 280px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    border: 1px solid rgba(0, 0, 0, 0.05);
    display: none;
    z-index: 1000;
}

.notification-dropdown {
    width: 320px;
}

.action-dropdown.show,
.notification-dropdown.show {
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

.dropdown-header {
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.dropdown-header h4 {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
}

.mark-all-read {
    padding: 4px 10px;
    background: #f1f5f9;
    border: none;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 500;
    color: #191970;
    cursor: pointer;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #1e293b;
    text-decoration: none;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.dropdown-item:hover {
    background: #f8fafc;
}

.dropdown-item svg {
    width: 18px;
    height: 18px;
    color: #64748b;
}

.notification-list {
    max-height: 300px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: all 0.3s ease;
}

.notification-item:hover {
    background: #f8fafc;
}

.notification-item.unread {
    background: #f0f9ff;
}

.notification-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon.urgent {
    background: #fee2e2;
    color: #ef4444;
}

.notification-icon.success {
    background: #dcfce7;
    color: #10b981;
}

.notification-content {
    flex: 1;
}

.notification-text {
    font-size: 0.85rem;
    font-weight: 500;
    color: #1e293b;
    margin-bottom: 4px;
}

.notification-time {
    font-size: 0.7rem;
    color: #64748b;
}

.dropdown-footer {
    padding: 14px 16px;
    text-align: center;
    border-top: 1px solid #f1f5f9;
}

.dropdown-footer a {
    color: #191970;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
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
    background: linear-gradient(135deg, #191970, #2a2a8a);
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
    background: linear-gradient(135deg, #191970, #2a2a8a);
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

@media (max-width: 768px) {
    .header-search {
        display: none;
    }
    
    .page-title h1 {
        font-size: 1.2rem;
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

    // Toggle quick action dropdown
    const quickActionBtn = document.getElementById('quickActionBtn');
    const quickActionDropdown = document.getElementById('quickActionDropdown');
    
    if (quickActionBtn) {
        quickActionBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            quickActionDropdown.classList.toggle('show');
            document.getElementById('notificationDropdown')?.classList.remove('show');
            document.getElementById('profileDropdown')?.classList.remove('show');
        });
    }

    // Toggle notifications
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn) {
        notificationBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationDropdown.classList.toggle('show');
            quickActionDropdown?.classList.remove('show');
            document.getElementById('profileDropdown')?.classList.remove('show');
        });
    }

    // Toggle profile dropdown
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileBtn) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
            quickActionDropdown?.classList.remove('show');
            notificationDropdown?.classList.remove('show');
        });
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
        quickActionDropdown?.classList.remove('show');
        notificationDropdown?.classList.remove('show');
        profileDropdown?.classList.remove('show');
    });

    // Prevent closing when clicking inside dropdowns
    if (quickActionDropdown) {
        quickActionDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
    if (notificationDropdown) {
        notificationDropdown.addEventListener('click', (e) => e.stopPropagation());
    }
    if (profileDropdown) {
        profileDropdown.addEventListener('click', (e) => e.stopPropagation());
    }

    // Mark all notifications as read
    const markAllRead = document.querySelector('.mark-all-read');
    if (markAllRead) {
        markAllRead.addEventListener('click', () => {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
        });
    }
});
</script>