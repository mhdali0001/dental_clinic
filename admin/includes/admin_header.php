<?php
// Professional Admin Header
// Usage: include 'includes/admin_header.php';
// Parameters: $pageTitle, $pageIcon, $pageSubtitle (optional), $currentPage

// Default values if not set
$pageTitle = $pageTitle ?? 'إدارة النظام';
$pageIcon = $pageIcon ?? 'fas fa-shield-alt';
$pageSubtitle = $pageSubtitle ?? 'لوحة تحكم الإدارة';
$currentPage = $currentPage ?? '';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get user information
$admin_name = $_SESSION['full_name'] ?? 'المدير العام';
$admin_id = $_SESSION['user_id'] ?? 0;

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Get admin notification counts
$db = getDB();
$pdo = $db->getConnection();

// Define navigation items with professional admin features
$navItems = [
    'dashboard' => [
        'url' => 'dashboard.php',
        'icon' => 'fas fa-tachometer-alt',
        'label' => 'الرئيسية',
        'badge' => null
    ],
    'analytics' => [
        'url' => 'analytics.php',
        'icon' => 'fas fa-chart-line',
        'label' => 'التحليلات',
        'badge' => null
    ],
    'reports' => [
        'url' => 'reports.php',
        'icon' => 'fas fa-file-alt',
        'label' => 'التقارير',
        'badge' => null
    ],
    'financial' => [
        'url' => 'financial.php',
        'icon' => 'fas fa-dollar-sign',
        'label' => 'المالية',
        'badge' => null
    ],
    'patients_analytics' => [
        'url' => 'patients_analytics.php',
        'icon' => 'fas fa-users',
        'label' => 'إحصائيات المرضى',
        'badge' => null
    ],
    'doctors' => [
        'url' => 'doctors.php',
        'icon' => 'fas fa-user-md',
        'label' => 'إدارة الأطباء',
        'badge' => null
    ]
];

// Try to get notification counts for badges
try {
    // إحصائيات سريعة للإشعارات
    $pending_appointments = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'scheduled' AND appointment_date = CURDATE()")->fetchColumn();
    $waiting_patients = $pdo->query("SELECT COUNT(*) FROM waiting_list WHERE status = 'waiting'")->fetchColumn();
    $unpaid_treatments = $pdo->query("SELECT COUNT(*) FROM treatments WHERE payment_status = 'unpaid'")->fetchColumn();
    $total_doctors = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor' AND is_active = 1")->fetchColumn();
    $overdue_followups = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending' AND follow_up_date < CURDATE()")->fetchColumn();

    // Set badges based on priority
    if ($pending_appointments > 0) {
        $navItems['dashboard']['badge'] = $pending_appointments;
    }

    if ($unpaid_treatments > 0) {
        $navItems['financial']['badge'] = $unpaid_treatments;
    }

    if ($total_doctors > 0) {
        $navItems['doctors']['badge'] = $total_doctors;
    }

    if ($overdue_followups > 0) {
        $navItems['analytics']['badge'] = $overdue_followups;
    }

} catch (PDOException $e) {
    // Ignore errors, badges just won't appear
}

// Get current page for navigation highlighting
$current_file = basename($_SERVER['PHP_SELF']);
$current_nav = '';
foreach ($navItems as $key => $item) {
    if ($item['url'] === $current_file) {
        $current_nav = $key;
        break;
    }
}
?>

<!-- Professional Admin Header -->
<header class="admin-header-pattern shadow-lg border-b-3">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <!-- Left Side - Page Info -->
            <div class="flex items-center">
                <div class="classic-icon-box bg-white rounded-lg p-3 ml-4 shadow-sm border border-gray-200">
                    <i class="<?= $pageIcon ?> text-2xl text-purple-700"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white"><?= htmlspecialchars($pageTitle) ?></h1>
                    <p class="text-gray-200 text-sm"><?= htmlspecialchars($pageSubtitle) ?></p>
                </div>
            </div>

            <!-- Center - Quick Stats -->
            <div class="hidden lg:flex items-center space-x-6 space-x-reverse">
                <?php if ($pending_appointments > 0): ?>
                <div class="classic-stat-box text-center">
                    <div class="text-white font-bold text-lg"><?= $pending_appointments ?></div>
                    <div class="text-gray-200 text-xs">مواعيد اليوم</div>
                </div>
                <?php endif; ?>

                <?php if ($waiting_patients > 0): ?>
                <div class="classic-stat-box text-center">
                    <div class="text-white font-bold text-lg"><?= $waiting_patients ?></div>
                    <div class="text-gray-200 text-xs">في الانتظار</div>
                </div>
                <?php endif; ?>

                <?php if ($unpaid_treatments > 0): ?>
                <div class="classic-stat-box text-center">
                    <div class="text-white font-bold text-lg"><?= $unpaid_treatments ?></div>
                    <div class="text-gray-200 text-xs">معاملات معلقة</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Side - User Info & Actions -->
            <div class="flex items-center space-x-3 space-x-reverse">
                <!-- Notifications -->
                <div class="relative">
                    <button class="classic-icon-btn text-white hover:text-gray-200 p-2 transition-all duration-200">
                        <i class="fas fa-bell text-lg"></i>
                        <?php if (($pending_appointments + $waiting_patients + $unpaid_treatments + $overdue_followups) > 0): ?>
                            <span class="classic-badge absolute -top-1 -right-1 bg-red-600 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center border border-white">
                                <?= min(99, $pending_appointments + $waiting_patients + $unpaid_treatments + $overdue_followups) ?>
                            </span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Admin Profile -->
                <div class="hidden md:flex items-center classic-profile-box bg-white/10 rounded-lg px-3 py-2 border border-white/20">
                    <div class="bg-white rounded-full p-2 ml-2 shadow-sm">
                        <i class="fas fa-user-shield text-purple-700"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-white font-medium text-sm"><?= htmlspecialchars($admin_name) ?></p>
                        <p class="text-gray-200 text-xs">مدير النظام</p>
                    </div>
                </div>

                <!-- Logout -->
                <button onclick="handleLogout()" class="classic-button bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition-all duration-200 border border-red-500">
                    <i class="fas fa-sign-out-alt ml-1"></i>
                    <span class="hidden sm:inline">خروج</span>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Professional Navigation -->
<nav class="classic-nav bg-white shadow-lg border-b border-gray-200 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex space-x-6 space-x-reverse overflow-x-auto">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= $item['url'] ?>"
                   class="<?= $current_nav === $key ? 'classic-nav-active bg-gray-100 text-gray-800 border-b-3 border-accent' : 'classic-nav-item text-gray-600 hover:text-gray-800 hover:bg-gray-50' ?>
                          px-4 py-4 transition-all duration-200 flex items-center space-x-2 space-x-reverse whitespace-nowrap relative border-b-3 border-transparent">
                    <i class="<?= $item['icon'] ?> text-base"></i>
                    <span class="font-medium text-sm"><?= $item['label'] ?></span>
                    <?php if ($item['badge']): ?>
                        <span class="classic-nav-badge bg-red-600 text-white text-xs rounded-full min-w-[18px] h-5 flex items-center justify-center px-1 border border-red-500">
                            <?= min(99, $item['badge']) ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <!-- Mobile Quick Actions -->
            <div class="lg:hidden flex space-x-2 space-x-reverse py-3 mr-3">
                <a href="reports.php" class="classic-quick-btn bg-blue-600 hover:bg-blue-700 text-white p-2 rounded border border-blue-500">
                    <i class="fas fa-chart-bar text-sm"></i>
                </a>
                <a href="analytics.php" class="classic-quick-btn bg-green-600 hover:bg-green-700 text-white p-2 rounded border border-green-500">
                    <i class="fas fa-chart-line text-sm"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Enhanced Breadcrumbs (if needed) -->
<?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
<div class="bg-gray-50 border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-3 space-x-reverse">
                <li class="inline-flex items-center">
                    <a href="dashboard.php" class="text-gray-700 hover:text-blue-600">
                        <i class="fas fa-home ml-2"></i>
                        الرئيسية
                    </a>
                </li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-left text-gray-400"></i>
                            <?php if (isset($crumb['url'])): ?>
                                <a href="<?= $crumb['url'] ?>" class="mr-1 text-sm font-medium text-gray-700 hover:text-blue-600">
                                    <?= htmlspecialchars($crumb['title']) ?>
                                </a>
                            <?php else: ?>
                                <span class="mr-1 text-sm font-medium text-gray-500">
                                    <?= htmlspecialchars($crumb['title']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
</div>
<?php endif; ?>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Kufi+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Professional Admin Styles -->
<style>
    /* Import classic fonts */
    * {
        font-family: 'Noto Kufi Arabic', sans-serif;
    }

    h1, h2 {
        font-family: 'Amiri', serif;
    }

    /* Admin header pattern with purple theme */
    .admin-header-pattern {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 25%, #6d28d9 50%, #7c3aed 75%, #8b5cf6 100%);
        background-size: 400% 400%;
        animation: gradientShift 15s ease infinite;
        position: relative;
        overflow: hidden;
    }

    .admin-header-pattern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image:
            repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,.03) 10px, rgba(255,255,255,.03) 20px),
            repeating-linear-gradient(-45deg, transparent, transparent 10px, rgba(255,255,255,.02) 10px, rgba(255,255,255,.02) 20px);
    }

    .admin-header-pattern::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b);
        box-shadow: 0 0 10px rgba(245, 158, 11, 0.5);
    }

    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    /* Classic icon box */
    .classic-icon-box {
        box-shadow:
            0 4px 6px -1px rgba(0, 0, 0, 0.1),
            0 2px 4px -1px rgba(0, 0, 0, 0.06);
        backdrop-filter: blur(10px);
    }

    /* Classic stat box */
    .classic-stat-box {
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 10px;
        padding: 0.75rem 1rem;
        box-shadow:
            inset 0 1px 2px rgba(255,255,255,0.2),
            0 4px 6px rgba(0,0,0,0.1);
        backdrop-filter: blur(10px);
    }

    /* Classic button with enhanced design */
    .classic-button {
        background: linear-gradient(145deg, #dc2626 0%, #b91c1c 100%);
        border: 1px solid #991b1b;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
        box-shadow:
            0 4px 6px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.2);
    }

    .classic-button:hover {
        background: linear-gradient(145deg, #b91c1c 0%, #991b1b 100%);
        transform: translateY(-2px);
        box-shadow:
            0 6px 12px rgba(0,0,0,0.2),
            inset 0 1px 0 rgba(255,255,255,0.2);
    }

    /* Classic icon button */
    .classic-icon-btn {
        border-radius: 8px;
        padding: 0.6rem;
        transition: all 0.3s ease;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
    }

    .classic-icon-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    /* Classic profile box */
    .classic-profile-box {
        border-radius: 10px;
        transition: all 0.3s ease;
        box-shadow:
            inset 0 1px 2px rgba(255,255,255,0.1),
            0 2px 4px rgba(0,0,0,0.1);
        backdrop-filter: blur(10px);
    }

    .classic-profile-box:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-1px);
    }

    /* Classic badge with enhanced design */
    .classic-badge {
        box-shadow:
            0 2px 4px rgba(0,0,0,0.2),
            inset 0 1px 0 rgba(255,255,255,0.3);
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }

    /* Classic navigation with enhanced shadow */
    .classic-nav {
        box-shadow:
            0 4px 6px -1px rgba(0, 0, 0, 0.1),
            0 2px 4px -1px rgba(0, 0, 0, 0.06);
        backdrop-filter: blur(10px);
    }

    .classic-nav-item {
        transition: all 0.3s ease;
        border-bottom: 3px solid transparent;
        position: relative;
    }

    .classic-nav-item:hover {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom-color: #e2e8f0;
        transform: translateY(-1px);
    }

    .classic-nav-active {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        border-bottom-color: #f59e0b;
        color: #374151;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
    }

    .classic-nav-badge {
        box-shadow:
            0 2px 4px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.2);
        animation: bounce 1s infinite;
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
        40% { transform: translateY(-3px); }
        60% { transform: translateY(-2px); }
    }

    /* Classic quick button */
    .classic-quick-btn {
        transition: all 0.3s ease;
        box-shadow:
            0 2px 4px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.2);
    }

    .classic-quick-btn:hover {
        transform: translateY(-2px) scale(1.05);
        box-shadow:
            0 4px 8px rgba(0,0,0,0.2),
            inset 0 1px 0 rgba(255,255,255,0.2);
    }

    /* Accent color */
    .border-accent {
        border-color: #f59e0b;
    }

    .border-b-3 {
        border-bottom-width: 3px;
    }

    /* Mobile responsive */
    nav > div > div {
        scrollbar-width: none;
        -ms-overflow-style: none;
        scroll-behavior: smooth;
    }
    nav > div > div::-webkit-scrollbar {
        display: none;
    }

    /* Enhanced fade in animation */
    .fade-in {
        animation: fadeIn 0.6s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Enhanced hover effects */
    .admin-header-pattern .classic-icon-box:hover {
        transform: scale(1.05);
        box-shadow: 0 8px 15px rgba(0,0,0,0.2);
    }

    /* Professional glassmorphism effect */
    .classic-stat-box {
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .classic-profile-box {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
    }

    /* Smooth transitions for all interactive elements */
    * {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
</style>

<!-- Enhanced JavaScript for admin header functionality -->
<script>
// Handle logout with confirmation
function handleLogout() {
    if (confirm('هل تريد تسجيل الخروج؟')) {
        // Clear any cached data
        if (window.sessionStorage) {
            sessionStorage.clear();
        }
        if (window.localStorage) {
            // Clear only app-specific items if needed
        }

        // Redirect to logout
        window.location.href = '../auth/logout.php';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Enhanced notification click handler
    const notificationBtn = document.querySelector('.classic-icon-btn');
    if (notificationBtn) {
        notificationBtn.addEventListener('click', function() {
            console.log('Show admin notifications');
        });
    }

    // Enhanced active state management
    const currentPath = window.location.pathname;
    const currentFile = currentPath.split('/').pop();
    const navLinks = document.querySelectorAll('nav a[href*=".php"]');

    navLinks.forEach(link => {
        const linkFile = link.getAttribute('href');
        if (linkFile === currentFile) {
            link.classList.add('classic-nav-active');
            link.classList.remove('classic-nav-item');
        }
    });

    // Add smooth animations on page load
    const animatedElements = document.querySelectorAll('.classic-icon-box, .classic-stat-box, .classic-profile-box');
    animatedElements.forEach((el, index) => {
        el.style.animationDelay = `${index * 0.1}s`;
        el.classList.add('fade-in');
    });

    // Enhanced badge pulse animation on hover
    const badges = document.querySelectorAll('.classic-badge, .classic-nav-badge');
    badges.forEach(badge => {
        badge.addEventListener('mouseenter', function() {
            this.style.animationDuration = '0.5s';
        });
        badge.addEventListener('mouseleave', function() {
            this.style.animationDuration = '2s';
        });
    });

    // Smooth scroll for internal links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Add keyboard navigation support
    document.addEventListener('keydown', function(e) {
        if (e.altKey && e.key >= '1' && e.key <= '7') {
            e.preventDefault();
            const navIndex = parseInt(e.key) - 1;
            const navLinks = document.querySelectorAll('nav a[href*=".php"]');
            if (navLinks[navIndex]) {
                navLinks[navIndex].click();
            }
        }
    });
});

// Auto-refresh admin notification badges every 3 minutes
setInterval(function() {
    fetch('includes/get_admin_notification_counts.php')
        .then(response => response.json())
        .then(data => {
            // Update badge counts with smooth animation
            Object.keys(data).forEach(key => {
                const badge = document.querySelector(`[data-badge="${key}"]`);
                if (badge) {
                    const newCount = data[key] > 99 ? '99+' : data[key];
                    if (badge.textContent !== newCount) {
                        badge.style.transform = 'scale(1.2)';
                        setTimeout(() => {
                            badge.textContent = newCount;
                            badge.style.transform = 'scale(1)';
                        }, 150);
                    }
                    badge.style.display = data[key] > 0 ? 'flex' : 'none';
                }
            });
        })
        .catch(error => console.log('Admin badge refresh failed:', error));
}, 180000); // 3 minutes

// Add tooltips for navigation items
const navItems = document.querySelectorAll('nav a[href*=".php"]');
navItems.forEach(item => {
    const label = item.querySelector('span').textContent;
    item.setAttribute('title', `انتقل إلى ${label}`);
});
</script>