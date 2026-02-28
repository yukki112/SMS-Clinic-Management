<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<header class="admin-header">
    <div class="header-container">
        <div class="header-left">
            <div class="page-title">
                <h1 id="pageTitle">Super Admin Dashboard</h1>
                <span class="page-badge">Super Admin</span>
            </div>
            
            <div class="header-search">
                <div class="search-wrapper">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 21L16.5 16.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <input type="text" placeholder="Search users, logs, configurations..." id="globalSearch">
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
                        <a href="system_control.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z"/>
                            </svg>
                            <span>System Control</span>
                        </a>
                        <a href="role_permission.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11"/>
                            </svg>
                            <span>Manage Roles</span>
                        </a>
                        <a href="backup_restore.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M19 11V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V11"/>
                                <path d="M12 2V13M12 13L15 10M12 13L9 10"/>
                                <path d="M3 7H21"/>
                            </svg>
                            <span>Backup System</span>
                        </a>
                    </div>
                </div>

                <div class="action-item">
                    <button class="action-btn" id="notificationBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M18 8C18 6.4087 17.3679 4.88258 16.2426 3.75736C15.1174 2.63214 13.5913 2 12 2C10.4087 2 8.88258 2.63214 7.75736 3.75736C6.63214 4.88258 6 6.4087 6 8C6 15 3 17 3 17H21C21 17 18 15 18 8Z"/>
                            <path d="M13.73 21C13.5542 21.3031 13.3019 21.5547 12.9982 21.7295C12.6946 21.9044 12.3504 21.9965 12 21.9965C11.6496 21.9965 11.3054 21.9044 11.0018 21.7295C10.6982 21.5547 10.4458 21.3031 10.27 21"/>
                        </svg>
                        <span class="notification-badge">5</span>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            <h4>System Notifications</h4>
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
                                    <p class="notification-text">New user registration pending approval</p>
                                    <span class="notification-time">5 minutes ago</span>
                                </div>
                            </div>
                            <div class="notification-item unread">
                                <div class="notification-icon warning">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M12 9V13M12 17H12.01" stroke-linecap="round" stroke-linejoin="round"/>
                                        <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-text">System backup completed with warnings</p>
                                    <span class="notification-time">25 minutes ago</span>
                                </div>
                            </div>
                            <div class="notification-item">
                                <div class="notification-icon success">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999"/>
                                        <path d="M22 4L12 14.01L9 11.01"/>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-text">Database optimization completed</p>
                                    <span class="notification-time">1 hour ago</span>
                                </div>
                            </div>
                            <div class="notification-item">
                                <div class="notification-icon info">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 16V12M12 8H12.01"/>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-text">New update available for system</p>
                                    <span class="notification-time">2 hours ago</span>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-footer">
                            <a href="system_logs.php">View all system logs</a>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <button class="profile-btn" id="profileBtn">
                        <div class="profile-avatar">
                            <span class="avatar-text"><?php echo strtoupper(substr($_SESSION['username'] ?? 'SA', 0, 2)); ?></span>
                            <span class="avatar-status online"></span>
                        </div>
                        <div class="profile-info">
                            <span class="profile-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Super Admin'); ?></span>
                            <span class="profile-role">Super Administrator</span>
                        </div>
                        <svg class="profile-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9L12 15L18 9" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-header">
                            <div class="profile-avatar-large">
                                <?php echo strtoupper(substr($_SESSION['username'] ?? 'SA', 0, 2)); ?>
                            </div>
                            <div class="profile-details">
                                <h4><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin'); ?></h4>
                                <p><?php echo htmlspecialchars($_SESSION['email'] ?? 'superadmin@clinic.com'); ?></p>
                            </div>
                        </div>
                        <ul class="profile-menu">
                            <li><a href="profile.php">My Profile</a></li>
                            <li><a href="system_config.php">System Settings</a></li>
                            <li><a href="help.php">Documentation</a></li>
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
    background: linear-gradient(135deg, #8b0000, #a52a2a);
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

.notification-icon.warning {
    background: #fff3cd;
    color: #ff9800;
}

.notification-icon.success {
    background: #dcfce7;
    color: #10b981;
}

.notification-icon.info {
    background: #e0f2fe;
    color: #0284c7;
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
    background: linear-gradient(135deg, #8b0000, #a52a2a);
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
    background: linear-gradient(135deg, #8b0000, #a52a2a);
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