<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit();
}

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
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
                    <span class="logo-sub">Super Admin</span>
                </div>
            </div>
            <button class="collapse-btn" id="collapseSidebar">
                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none">
                    <path d="M15 18L9 12L15 6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        <div class="nav-container">
            <!-- Overview Section -->
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
                            <span class="nav-badge">Super</span>
                        </a>
                    </li>
                   
                </ul>
            </div>

            <!-- System Management Section -->
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>System Management</span>
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
                            <span class="nav-count">12</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'audit_logs.php' ? 'active' : ''; ?>">
                        <a href="audit_logs.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 8V12L15 15" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M12 16H12.01" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Audit Logs</span>
                            <span class="nav-badge urgent">Live</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'system_control.php' ? 'active' : ''; ?>">
                        <a href="system_control.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">System Control</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Access Control Section -->
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>Access Control</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'role_permission.php' ? 'active' : ''; ?>">
                        <a href="role_permission.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M7 11V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V11" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Role & Permission</span>
                            <span class="nav-count">5</span>
                        </a>
                    </li>
                    <li class="nav-item <?php echo $current_page == 'system_config.php' ? 'active' : ''; ?>">
                        <a href="system_config.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M19.4 15C18.9 16 18.1 16.7 17.2 17.2L19 20.6L15.8 19.5C14.9 19.9 13.9 20.1 12.8 20.1C11.7 20.1 10.7 19.9 9.8 19.5L6.6 20.6L8.4 17.2C7.5 16.7 6.7 16 6.2 15L2.8 16.8L4 13.2C3.6 12.3 3.4 11.3 3.4 10.2C3.4 9.1 3.6 8.1 4 7.2L2.8 3.6L6.2 5.4C6.7 4.5 7.5 3.8 8.4 3.3L6.6 0L9.8 1.1C10.7 0.7 11.7 0.5 12.8 0.5C13.9 0.5 14.9 0.7 15.8 1.1L19 0L17.2 3.4C18.1 3.9 18.9 4.6 19.4 5.5L22.8 3.7L21.6 7.3C22 8.2 22.2 9.2 22.2 10.3C22.2 11.4 22 12.4 21.6 13.3L22.8 16.9L19.4 15Z" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">System Configuration</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- User Management Section -->
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>User Management</span>
                </div>
                <ul class="nav-menu">
                    <li class="nav-item <?php echo $current_page == 'all_users.php' ? 'active' : ''; ?>">
                        <a href="all_users.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="8" r="4" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M5.5 20V19C5.5 17.1435 6.2375 15.363 7.55025 14.0503C8.86301 12.7375 10.6435 12 12.5 12C14.3565 12 16.137 12.7375 17.4497 14.0503C18.7625 15.363 19.5 17.1435 19.5 19V20" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">All Users</span>
                            <span class="nav-count">14</span>
                        </a>
                    </li>
                  
                </ul>
            </div>

            <!-- System Health Section -->
            <div class="nav-section">
                <div class="nav-section-title">
                    <span>System Health</span>
                </div>
                <ul class="nav-menu">
                   
                    <li class="nav-item <?php echo $current_page == 'backup_restore.php' ? 'active' : ''; ?>">
                        <a href="backup_restore.php" class="nav-link">
                            <div class="nav-icon-wrapper">
                                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M19 11V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V11" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M12 2V13M12 13L15 10M12 13L9 10" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M3 7H21" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span class="nav-label">Backup & Restore</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="user-profile-card">
                <?php
                $user_fullname = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Super Admin';
                $user_role = 'Super Administrator';
                $user_initials = '';
                
                // Generate initials from full name
                $name_parts = explode(' ', $user_fullname);
                foreach ($name_parts as $part) {
                    if (!empty($part)) {
                        $user_initials .= strtoupper(substr($part, 0, 1));
                    }
                }
                $user_initials = substr($user_initials, 0, 2);
                ?>
                <div class="user-info">
                    <div class="user-avatar">
                        <span class="avatar-text"><?php echo $user_initials; ?></span>
                    </div>
                    <div class="user-details">
                        <h4 class="user-name"><?php echo htmlspecialchars($user_fullname); ?></h4>
                        <span class="user-role"><?php echo $user_role; ?></span>
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
</aside>

<style>
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
.sidebar.collapsed .nav-badge,
.sidebar.collapsed .nav-count,
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

.sidebar.collapsed .logout-btn {
    padding: 10px 0;
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

.nav-badge {
    padding: 4px 8px;
    background: #ffebee;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
    color: #c62828;
    border: 1px solid #ffcdd2;
}

.nav-badge.urgent {
    background: #ffebee;
    color: #c62828;
    border-color: #ffcdd2;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.nav-count {
    padding: 2px 6px;
    background: #eceff1;
    border-radius: 8px;
    font-size: 0.65rem;
    font-weight: 600;
    color: #191970;
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
});
</script>