<?php
// Unified Header for Doctor Pages
// Usage: include 'includes/doctor_header.php';
// Parameters: $pageTitle, $pageIcon, $pageSubtitle (optional), $currentPage

// Default values if not set
$pageTitle = $pageTitle ?? 'عيادة الأسنان';
$pageIcon = $pageIcon ?? 'fas fa-tooth';
$pageSubtitle = $pageSubtitle ?? 'نظام إدارة العيادة';
$currentPage = $currentPage ?? '';

// Get user information
// login.php يحفظ الاسم في full_name؛ "د." تُضاف في العرض فتُحذف إن كانت ضمن الاسم المخزَّن
$doctor_name = preg_replace('/^د\.\s*/u', '', $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '') ?: 'الطبيب';
$doctor_id = $_SESSION['user_id'] ?? 0;

// الترويسة تُعرض أيضاً في صفحات السكرتاريا (nurse/) المشتركة مع الطبيب،
// لذلك تُبنى الروابط نسبةً إلى مجلد الصفحة الحالية
$headerScriptDir = basename(dirname($_SERVER['SCRIPT_NAME']));
$doctorBase = $headerScriptDir === 'doctor' ? '' : '../doctor/';
$nurseBase = $headerScriptDir === 'nurse' ? '' : '../nurse/';

// Define navigation items
$navItems = [
    'dashboard' => [
        'url' => $doctorBase . 'dashboard.php',
        'icon' => 'fas fa-home',
        'label' => 'الرئيسية',
        'badge' => null
    ],
    'appointments' => [
        'url' => $doctorBase . 'appointments.php',
        'icon' => 'fas fa-calendar-check',
        'label' => 'المواعيد',
        'badge' => null
    ],
    'treatments' => [
        'url' => $doctorBase . 'treatments.php',
        'icon' => 'fas fa-file-medical',
        'label' => 'العلاجات',
        'badge' => null
    ],
    'follow_ups' => [
        'url' => $doctorBase . 'follow_ups.php',
        'icon' => 'fas fa-user-clock',
        'label' => 'المتابعات',
        'badge' => null
    ],
    'patients' => [
        'url' => $doctorBase . 'patients.php',
        'icon' => 'fas fa-users',
        'label' => 'المرضى',
        'badge' => null
    ],
    'patient_balance' => [
        'url' => $nurseBase . 'patient_balance.php',
        'icon' => 'fas fa-wallet',
        'label' => 'الحسابات',
        'badge' => null
    ],
    'reports' => [
        'url' => $doctorBase . 'reports.php',
        'icon' => 'fas fa-chart-bar',
        'label' => 'التقارير',
        'badge' => null
    ],
    'analytics' => [
        'url' => $doctorBase . 'analytics.php',
        'icon' => 'fas fa-chart-line',
        'label' => 'التحليلات',
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

        // Incomplete treatments count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM treatments
            WHERE doctor_id = ? AND (status IS NULL OR status != 'completed')
        ");
        $stmt->execute([$doctor_id]);
        $incompleteTreatments = $stmt->fetchColumn();
        if ($incompleteTreatments > 0) {
            $navItems['treatments']['badge'] = $incompleteTreatments;
        }

        // Follow-ups needing attention (overdue + today)
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM follow_ups
                WHERE status = 'pending'
                AND follow_up_date <= CURDATE()
            ");
            $stmt->execute();
            $urgentFollowUps = $stmt->fetchColumn();
            if ($urgentFollowUps > 0) {
                $navItems['follow_ups']['badge'] = $urgentFollowUps;
            }
        } catch (PDOException $e) {
            // Fallback to old logic if follow_ups table doesn't exist
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.patient_id) FROM treatments t
                WHERE t.doctor_id = ?
                AND t.next_appointment_date IS NOT NULL
                AND t.next_appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM appointments a
                    WHERE a.patient_id = t.patient_id
                    AND a.appointment_date >= CURDATE()
                )
            ");
            $stmt->execute([$doctor_id]);
            $followupNeeded = $stmt->fetchColumn();
            if ($followupNeeded > 0) {
                $navItems['patients']['badge'] = $followupNeeded;
            }
        }
    }
} catch (PDOException $e) {
    // Ignore errors, badges just won't appear
}

// الترويسة الموحّدة (includes/app_header.php): الشريط العلوي + التنقل + عنوان الصفحة
$appNav = $navItems;
$appCurrent = $currentPage;
$appHomeUrl = $doctorBase . 'dashboard.php';
$appUser = ['name' => 'د. ' . $doctor_name, 'role' => 'طبيب أسنان', 'icon' => 'fas fa-user-md'];
$appSettingsUrl = $doctorBase . 'settings.php';
$appLogoutUrl = '../logout.php';
$appQuickActions = [
    ['url' => $doctorBase . 'treatment_new.php', 'icon' => 'fas fa-plus', 'title' => 'علاج جديد'],
    ['url' => $doctorBase . 'appointments.php', 'icon' => 'fas fa-calendar-day', 'title' => 'مواعيد اليوم'],
];
include __DIR__ . '/../../includes/app_header.php';
