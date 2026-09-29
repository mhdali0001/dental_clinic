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

// عدد الأطباء ليس تنبيهاً: يظهر على عنصر التنقل فقط
if (isset($navItems['doctors'])) {
    $navItems['doctors']['notify'] = false;
}

// الترويسة الموحّدة (includes/app_header.php): الشريط العلوي + التنقل + عنوان الصفحة
$appNav = $navItems;
$appCurrent = $current_nav ?: $currentPage;
$appHomeUrl = 'dashboard.php';
$appUser = ['name' => $admin_name, 'role' => 'مدير النظام', 'icon' => 'fas fa-user-shield'];
$appLogoutUrl = '../auth/logout.php';
$appLogoutConfirm = true;
include __DIR__ . '/../../includes/app_header.php';
