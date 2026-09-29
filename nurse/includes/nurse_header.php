<?php
// Unified Header for Nurse Pages
// Usage: include 'includes/nurse_header.php';
// Parameters: $pageTitle, $pageIcon, $pageSubtitle (optional), $currentPage

// Default values if not set
$pageTitle = $pageTitle ?? 'عيادة الأسنان';
$pageIcon = $pageIcon ?? 'fas fa-user-nurse';
$pageSubtitle = $pageSubtitle ?? 'نظام إدارة العيادة';
$currentPage = $currentPage ?? '';

// Get user information
$nurse_name = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'الممرضة';
$nurse_id = $_SESSION['user_id'] ?? 0;

// Define navigation items for nurse
$navItems = [
    'dashboard' => [
        'url' => 'dashboard.php',
        'icon' => 'fas fa-home',
        'label' => 'الرئيسية',
        'badge' => null
    ],
    'appointments' => [
        'url' => 'appointments.php',
        'icon' => 'fas fa-calendar-check',
        'label' => 'المواعيد',
        'badge' => null
    ],
    'follow_ups' => [
        'url' => 'follow_ups.php',
        'icon' => 'fas fa-calendar-alt',
        'label' => 'المتابعات',
        'badge' => null
    ],
    'patients' => [
        'url' => 'patients.php',
        'icon' => 'fas fa-users',
        'label' => 'المرضى',
        'badge' => null
    ],
    'waiting_list' => [
        'url' => 'waiting_list.php',
        'icon' => 'fas fa-clock',
        'label' => 'قائمة الانتظار',
        'badge' => null
    ],
    'patient_balance' => [
        'url' => 'patient_balance.php',
        'icon' => 'fas fa-wallet',
        'label' => 'أرصدة المرضى',
        'badge' => null
    ]
];

// Try to get notification counts for badges
try {
    $pdo = $pdo ?? null;
    if ($pdo) {
        // Today's appointments count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments a
            WHERE DATE(a.appointment_date) = CURDATE()
            AND a.status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute();
        $todayAppointments = $stmt->fetchColumn();
        if ($todayAppointments > 0) {
            $navItems['appointments']['badge'] = $todayAppointments;
        }

        // Pending follow-ups count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM follow_ups
            WHERE status = 'pending' AND follow_up_date <= CURDATE()
        ");
        $stmt->execute();
        $pendingFollowups = $stmt->fetchColumn();
        if ($pendingFollowups > 0) {
            $navItems['follow_ups']['badge'] = $pendingFollowups;
        }

        // Waiting list count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM waiting_list
            WHERE status = 'waiting'
        ");
        $stmt->execute();
        $waitingCount = $stmt->fetchColumn();
        if ($waitingCount > 0) {
            $navItems['waiting_list']['badge'] = $waitingCount;
        }

        // Patients with unpaid balances
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT patient_id) FROM payments
            WHERE status = 'pending' OR amount_due > amount_paid
        ");
        $stmt->execute();
        $unpaidBalances = $stmt->fetchColumn();
        if ($unpaidBalances > 0) {
            $navItems['patient_balance']['badge'] = $unpaidBalances;
        }
    }
} catch (PDOException $e) {
    // Ignore errors, badges just won't appear
}

// الترويسة الموحّدة (includes/app_header.php): الشريط العلوي + التنقل + عنوان الصفحة
$appNav = $navItems;
$appCurrent = $currentPage;
$appHomeUrl = 'dashboard.php';
$appUser = ['name' => $nurse_name, 'role' => 'ممرضة', 'icon' => 'fas fa-user-nurse'];
$appLogoutUrl = '../logout.php';
$appQuickActions = [
    ['url' => 'appointments.php?action=add', 'icon' => 'fas fa-plus', 'title' => 'حجز موعد'],
    ['url' => 'waiting_list.php', 'icon' => 'fas fa-clock', 'title' => 'قائمة الانتظار'],
];
include __DIR__ . '/../../includes/app_header.php';
