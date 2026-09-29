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
        'icon' => 'fas fa-tachometer-alt',
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
?>

<!-- Classic Doctor Header -->
<header class="header-pattern shadow-lg border-b-3">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <!-- Left Side - Page Info -->
            <div class="flex items-center">
                <div class="classic-icon-box bg-white rounded-lg p-3 ml-4 shadow-sm border border-gray-200">
                    <i class="<?= $pageIcon ?> text-2xl text-gray-700"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white"><?= htmlspecialchars($pageTitle) ?></h1>
                    <p class="text-gray-200 text-sm"><?= htmlspecialchars($pageSubtitle) ?></p>
                </div>
            </div>

            <!-- Center - Quick Stats -->
            <div class="hidden lg:flex items-center space-x-6 space-x-reverse">
                <?php if (($navItems['appointments']['badge'] ?? 0) > 0): ?>
                <div class="classic-stat-box text-center">
                    <div class="text-white font-bold text-lg"><?= $navItems['appointments']['badge'] ?></div>
                    <div class="text-gray-200 text-xs">مواعيد اليوم</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Side - User Info & Actions -->
            <div class="flex items-center space-x-3 space-x-reverse">
                <!-- Notifications -->
                <div class="relative">
                    <button class="classic-icon-btn text-white hover:text-gray-200 p-2 transition-all duration-200">
                        <i class="fas fa-bell text-lg"></i>
                        <?php if (($navItems['appointments']['badge'] ?? 0) + ($navItems['treatments']['badge'] ?? 0) + ($navItems['follow_ups']['badge'] ?? 0) + ($navItems['patients']['badge'] ?? 0) > 0): ?>
                            <span class="classic-badge absolute -top-1 -right-1 bg-red-600 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center border border-white">
                                <?= min(99, ($navItems['appointments']['badge'] ?? 0) + ($navItems['treatments']['badge'] ?? 0) + ($navItems['follow_ups']['badge'] ?? 0) + ($navItems['patients']['badge'] ?? 0)) ?>
                            </span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Doctor Profile -->
                <div class="hidden md:flex items-center classic-profile-box bg-white/10 rounded-lg px-3 py-2 border border-white/20">
                    <div class="bg-white rounded-full p-2 ml-2 shadow-sm">
                        <i class="fas fa-user-md text-gray-700"></i>
                    </div>
                    <div class="text-right">
                        <p class="text-white font-medium text-sm">د. <?= htmlspecialchars($doctor_name) ?></p>
                        <p class="text-gray-200 text-xs">طبيب أسنان</p>
                    </div>
                </div>

                <!-- Settings -->
                <a href="<?= $doctorBase ?>settings.php" class="classic-icon-btn text-white hover:text-gray-200 p-2 transition-all duration-200">
                    <i class="fas fa-cogs text-lg"></i>
                </a>

                <!-- Logout -->
                <a href="../logout.php" class="classic-button bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition-all duration-200 border border-red-500">
                    <i class="fas fa-sign-out-alt ml-1"></i>
                    <span class="hidden sm:inline">خروج</span>
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Classic Navigation -->
<nav class="classic-nav bg-white shadow-lg border-b border-gray-200 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex space-x-6 space-x-reverse overflow-x-auto">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= $item['url'] ?>"
                   class="<?= $currentPage === $key ? 'classic-nav-active bg-gray-100 text-gray-800 border-b-3 border-accent' : 'classic-nav-item text-gray-600 hover:text-gray-800 hover:bg-gray-50' ?>
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
                <a href="<?= $doctorBase ?>treatment_new.php" class="classic-quick-btn bg-green-600 hover:bg-green-700 text-white p-2 rounded border border-green-500">
                    <i class="fas fa-plus text-sm"></i>
                </a>
                <a href="<?= $doctorBase ?>appointments.php?filter=today" class="classic-quick-btn bg-yellow-600 hover:bg-yellow-700 text-white p-2 rounded border border-yellow-500">
                    <i class="fas fa-calendar-day text-sm"></i>
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
                    <a href="<?= $doctorBase ?>dashboard.php" class="text-gray-700 hover:text-blue-600">
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

<!-- Classic Styles -->
<style>
    /* Import classic fonts */
    * {
        font-family: 'Noto Kufi Arabic', sans-serif;
    }

    h1, h2 {
        font-family: 'Amiri', serif;
    }

    /* Classic header pattern */
    .header-pattern {
        background-color: #1e3a5f;
        background-image:
            repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,.05) 10px, rgba(255,255,255,.05) 20px),
            repeating-linear-gradient(-45deg, transparent, transparent 10px, rgba(255,255,255,.03) 10px, rgba(255,255,255,.03) 20px);
        position: relative;
        overflow: hidden;
    }

    .header-pattern::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #c9a961, #f4e4c1, #c9a961);
    }

    /* Classic icon box */
    .classic-icon-box {
        box-shadow:
            0 2px 4px rgba(0,0,0,0.02),
            0 4px 8px rgba(0,0,0,0.03),
            0 8px 16px rgba(0,0,0,0.04);
    }

    /* Classic stat box */
    .classic-stat-box {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
    }

    /* Classic button */
    .classic-button {
        background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%);
        border: 1px solid #991b1b;
        font-weight: 500;
        letter-spacing: 0.5px;
        transition: all 0.2s ease;
        box-shadow:
            0 2px 4px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    .classic-button:hover {
        background: linear-gradient(180deg, #b91c1c 0%, #991b1b 100%);
        transform: translateY(-1px);
        box-shadow:
            0 4px 8px rgba(0,0,0,0.15),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    /* Classic icon button */
    .classic-icon-btn {
        border-radius: 6px;
        padding: 0.5rem;
        transition: all 0.2s ease;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .classic-icon-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: translateY(-1px);
    }

    /* Classic profile box */
    .classic-profile-box {
        border-radius: 8px;
        transition: all 0.2s ease;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
    }

    .classic-profile-box:hover {
        background: rgba(255, 255, 255, 0.15);
    }

    /* Classic badge */
    .classic-badge {
        box-shadow:
            0 2px 4px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    /* Classic navigation */
    .classic-nav {
        box-shadow:
            0 2px 4px rgba(0,0,0,0.02),
            0 4px 8px rgba(0,0,0,0.03),
            0 8px 16px rgba(0,0,0,0.04);
    }

    .classic-nav-item {
        transition: all 0.2s ease;
        border-bottom: 3px solid transparent;
    }

    .classic-nav-item:hover {
        background-color: #f8f9fa;
        border-bottom-color: #dee2e6;
    }

    .classic-nav-active {
        background-color: #f1f5f9;
        border-bottom-color: #c9a961;
        color: #374151;
    }

    .classic-nav-badge {
        box-shadow:
            0 1px 2px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    /* Classic quick button */
    .classic-quick-btn {
        transition: all 0.2s ease;
        box-shadow:
            0 2px 4px rgba(0,0,0,0.1),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    .classic-quick-btn:hover {
        transform: translateY(-1px);
        box-shadow:
            0 4px 8px rgba(0,0,0,0.15),
            inset 0 1px 0 rgba(255,255,255,0.1);
    }

    /* Accent color */
    .border-accent {
        border-color: #c9a961;
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

    /* Classic fade in animation */
    .fade-in {
        animation: fadeIn 0.5s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<!-- Enhanced JavaScript for header functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Notification click handler
    const notificationBtn = document.querySelector('[data-notification]');
    if (notificationBtn) {
        notificationBtn.addEventListener('click', function() {
            // Add notification panel logic here
            console.log('Show notifications');
        });
    }

    // Add active state to current page
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('nav a[href*=".php"]');
    navLinks.forEach(link => {
        if (currentPath.includes(link.getAttribute('href'))) {
            link.classList.add('bg-blue-50', 'text-blue-600', 'border-b-2', 'border-blue-600');
            link.classList.remove('text-gray-600');
        }
    });

    // Smooth scroll for internal links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});

// Auto-refresh notification badges every 5 minutes
setInterval(function() {
    // Add AJAX call to refresh badge counts
    fetch('<?= $doctorBase ?>includes/get_notification_counts.php')
        .then(response => response.json())
        .then(data => {
            // Update badge counts
            Object.keys(data).forEach(key => {
                const badge = document.querySelector(`[data-badge="${key}"]`);
                if (badge) {
                    badge.textContent = data[key] > 99 ? '99+' : data[key];
                    badge.style.display = data[key] > 0 ? 'flex' : 'none';
                }
            });
        })
        .catch(error => console.log('Badge refresh failed:', error));
}, 300000); // 5 minutes
</script>