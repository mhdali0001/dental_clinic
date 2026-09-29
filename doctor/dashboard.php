<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// جلب الإحصائيات
try {
    $today = date('Y-m-d');
    $doctor_id = $_SESSION['user_id'];
    
    // Initialize default values
    $todayAppointments = 0;
    $treatmentsThisWeek = 0;
    $followupNeeded = 0;
    $totalPatientsTreated = 0;
    $todayAppointmentsList = [];
    $waitingList = [];
    $recentTreatments = [];
    $monthlyRevenue = 0;
    
    // مواعيد اليوم للطبيب - عرض جميع المواعيد المجدولة لليوم
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a 
        WHERE DATE(a.appointment_date) = ? 
        AND a.status IN ('scheduled', 'confirmed')
    ");
    $stmt->execute([$today]);
    $todayAppointments = $stmt->fetchColumn();
    
    // العلاجات المكتملة هذا الأسبوع
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM treatments 
        WHERE doctor_id = ? AND treatment_date >= ?
    ");
    $stmt->execute([$doctor_id, $weekStart]);
    $treatmentsThisWeek = $stmt->fetchColumn();
    
    // المرضى الذين يحتاجون متابعة
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
    
    // إجمالي المرضى المعالجين
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT patient_id) FROM treatments WHERE doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $totalPatientsTreated = $stmt->fetchColumn();
    
    // مواعيد اليوم بالتفصيل - عرض جميع المواعيد المجدولة لليوم
    $stmt = $pdo->prepare("
        SELECT a.*, 
               p.name as patient_name,
               p.phone, p.age, p.gender, p.medical_history, p.allergies,
               t.id as treatment_id, t.diagnosis, t.treatment_details
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        LEFT JOIN treatments t ON a.id = t.appointment_id
        WHERE DATE(a.appointment_date) = ?
        AND a.status IN ('scheduled', 'confirmed')
        ORDER BY COALESCE(a.appointment_time, a.appointment_date)
    ");
    $stmt->execute([$today]);
    $todayAppointmentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // المرضى في قائمة الانتظار (with fallback if table doesn't exist)
    try {
        $stmt = $pdo->prepare("
            SELECT wl.*, 
                   p.name as patient_name,
                   p.phone, p.medical_history, p.allergies
            FROM waiting_list wl 
            JOIN patients p ON wl.patient_id = p.id 
            ORDER BY COALESCE(wl.priority = 'emergency', 0) DESC, 
                     COALESCE(wl.priority = 'urgent', 0) DESC, 
                     COALESCE(wl.arrival_time, wl.created_at) ASC
            LIMIT 10
        ");
        $stmt->execute();
        $waitingList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $waitingList = []; // Fallback if waiting_list table doesn't exist
    }
    
    // آخر العلاجات المسجلة
    $stmt = $pdo->prepare("
        SELECT t.*, 
               p.name as patient_name,
               p.phone
        FROM treatments t 
        JOIN patients p ON t.patient_id = p.id 
        WHERE t.doctor_id = ? 
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$doctor_id]);
    $recentTreatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // الإيرادات الشهرية
    $stmt = $pdo->prepare("
        SELECT SUM(COALESCE(t.cost, 0)) FROM treatments t
        WHERE t.doctor_id = ?
        AND MONTH(COALESCE(t.treatment_date, t.created_at)) = MONTH(CURDATE())
        AND YEAR(COALESCE(t.treatment_date, t.created_at)) = YEAR(CURDATE())
    ");
    $stmt->execute([$doctor_id]);
    $monthlyRevenue = $stmt->fetchColumn() ?: 0;

    // Follow-up system statistics (with fallback if table doesn't exist)
    $followUpStats = [
        'today' => 0,
        'overdue' => 0,
        'upcoming' => 0,
        'total_pending' => 0
    ];
    $upcomingFollowUps = [];

    try {
        // Today's follow-ups
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM follow_ups
            WHERE follow_up_date = CURDATE() AND status = 'pending'
        ");
        $stmt->execute();
        $followUpStats['today'] = $stmt->fetchColumn();

        // Overdue follow-ups
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM follow_ups
            WHERE follow_up_date < CURDATE() AND status = 'pending'
        ");
        $stmt->execute();
        $followUpStats['overdue'] = $stmt->fetchColumn();

        // Upcoming follow-ups (next 7 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM follow_ups
            WHERE follow_up_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status = 'pending'
        ");
        $stmt->execute();
        $followUpStats['upcoming'] = $stmt->fetchColumn();

        // Total pending follow-ups
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM follow_ups
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $followUpStats['total_pending'] = $stmt->fetchColumn();

        // Get upcoming follow-ups details (next 5)
        $stmt = $pdo->prepare("
            SELECT fu.*, p.name as patient_name, p.phone
            FROM follow_ups fu
            JOIN patients p ON fu.patient_id = p.id
            WHERE fu.status = 'pending' AND fu.follow_up_date >= CURDATE()
            ORDER BY fu.follow_up_date ASC, fu.priority = 'urgent' DESC, fu.priority = 'high' DESC
            LIMIT 5
        ");
        $stmt->execute();
        $upcomingFollowUps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Fallback if follow_ups table doesn't exist
        $followUpStats = ['today' => 0, 'overdue' => 0, 'upcoming' => 0, 'total_pending' => 0];
        $upcomingFollowUps = [];
    }
    
} catch (PDOException $e) {
    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
}

// Header configuration
$pageTitle = 'لوحة التحكم';
$pageIcon = 'fas fa-tachometer-alt';
$pageSubtitle = 'مرحباً د. ' . (preg_replace('/^د\.\s*/u', '', $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '') ?: 'الطبيب');
$currentPage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <?php
    $arabicMonths = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 fade-in">
            <div class="edsm-card edsm-stat edsm-tone-blue">
                <div>
                    <div class="edsm-stat-label">مواعيد اليوم</div>
                    <div class="edsm-stat-value"><?= (int)$todayAppointments ?></div>
                    <div class="edsm-stat-note">إجمالي مواعيد اليوم</div>
                </div>
                <div class="edsm-stat-icon"><i class="fas fa-calendar-check"></i></div>
            </div>
            <div class="edsm-card edsm-stat edsm-tone-teal">
                <div>
                    <div class="edsm-stat-label">علاجات هذا الأسبوع</div>
                    <div class="edsm-stat-value"><?= (int)$treatmentsThisWeek ?></div>
                    <div class="edsm-stat-note">منذ بداية الأسبوع</div>
                </div>
                <div class="edsm-stat-icon"><i class="fas fa-tooth"></i></div>
            </div>
            <div class="edsm-card edsm-stat edsm-tone-amber">
                <div>
                    <div class="edsm-stat-label">يحتاجون متابعة</div>
                    <div class="edsm-stat-value"><?= (int)$followupNeeded ?></div>
                    <div class="edsm-stat-note">خلال 7 أيام</div>
                </div>
                <div class="edsm-stat-icon"><i class="fas fa-user-clock"></i></div>
            </div>
            <div class="edsm-card edsm-stat edsm-tone-violet">
                <div>
                    <div class="edsm-stat-label">مرضى عالجتهم</div>
                    <div class="edsm-stat-value"><?= (int)$totalPatientsTreated ?></div>
                    <div class="edsm-stat-note">إجمالي المرضى</div>
                </div>
                <div class="edsm-stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="edsm-card fade-in">
            <div class="edsm-card-head">
                <h3 class="edsm-card-title"><i class="fas fa-bolt"></i> إجراءات سريعة</h3>
            </div>
            <div class="edsm-quick-grid">
                <a href="treatment_new.php" class="edsm-quick edsm-grad-teal"><i class="fas fa-plus-square"></i><span>إضافة علاج جديد</span></a>
                <!-- ميزات السكرتاريا المتاحة للطبيب -->
                <a href="../nurse/patients.php?action=add" class="edsm-quick edsm-grad-pink"><i class="fas fa-user-plus"></i><span>إضافة مريض جديد</span></a>
                <a href="../nurse/appointments.php?action=add" class="edsm-quick edsm-grad-blue"><i class="fas fa-calendar-plus"></i><span>حجز موعد</span></a>
                <a href="../nurse/patient_balance.php" class="edsm-quick edsm-grad-green"><i class="fas fa-wallet"></i><span>تسجيل دفعة</span></a>
                <a href="appointments.php" class="edsm-quick edsm-grad-sky"><i class="fas fa-calendar-check"></i><span>جدول المواعيد</span></a>
                <a href="follow_ups.php" class="edsm-quick edsm-grad-amber"><i class="fas fa-user-clock"></i><span>إدارة المتابعات</span></a>
                <a href="treatments.php?filter=recent" class="edsm-quick edsm-grad-violet"><i class="fas fa-history"></i><span>العلاجات الأخيرة</span></a>
                <a href="reports.php" class="edsm-quick edsm-grad-indigo"><i class="fas fa-file-medical-alt"></i><span>التقارير الطبية</span></a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Today's Appointments -->
            <div class="edsm-card edsm-watermark fade-in">
                <div class="edsm-card-head">
                    <h3 class="edsm-card-title"><i class="fas fa-calendar-day"></i> مواعيد اليوم</h3>
                    <a href="appointments.php" class="edsm-link">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                </div>
                <?php if (empty($todayAppointmentsList)): ?>
                    <div class="edsm-empty">
                        <div class="edsm-empty-icon"><i class="far fa-calendar-alt"></i></div>
                        <p>لا توجد مواعيد لليوم</p>
                        <a href="../nurse/appointments.php?action=add" class="edsm-btn edsm-btn-lg"><i class="fas fa-plus"></i> إنشاء موعد جديد</a>
                    </div>
                <?php else: ?>
                    <div class="edsm-list">
                        <?php foreach ($todayAppointmentsList as $appointment): ?>
                            <?php
                            $time = '';
                            if (!empty($appointment['appointment_time'])) {
                                $time = date('H:i', strtotime($appointment['appointment_time']));
                            } elseif (!empty($appointment['appointment_date'])) {
                                $time = date('H:i', strtotime($appointment['appointment_date']));
                            }
                            ?>
                            <div class="edsm-row">
                                <div class="edsm-row-main">
                                    <div class="edsm-avatar-soft"><i class="fas fa-user"></i></div>
                                    <div class="min-w-0">
                                        <div class="edsm-row-title"><?= htmlspecialchars($appointment['patient_name']) ?></div>
                                        <div class="edsm-row-meta">
                                            <span class="edsm-num"><?= $time ?></span>
                                            <?php if (!empty($appointment['treatment_type'])): ?>
                                                · <?= htmlspecialchars($appointment['treatment_type']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="edsm-row-actions">
                                    <?php if (!$appointment['treatment_id']): ?>
                                        <a href="treatment_new.php?appointment_id=<?= $appointment['id'] ?>&patient_id=<?= $appointment['patient_id'] ?>" class="edsm-btn edsm-btn-teal">
                                            <i class="fas fa-plus"></i> علاج
                                        </a>
                                    <?php else: ?>
                                        <a href="treatment_details.php?id=<?= $appointment['treatment_id'] ?>" class="edsm-btn">
                                            <i class="fas fa-eye"></i> عرض
                                        </a>
                                    <?php endif; ?>
                                    <a href="patient_profile.php?id=<?= $appointment['patient_id'] ?>" class="edsm-btn edsm-btn-ghost" title="ملف المريض">
                                        <i class="fas fa-user-circle"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Waiting List -->
            <div class="edsm-card fade-in">
                <div class="edsm-card-head">
                    <h3 class="edsm-card-title"><i class="fas fa-hourglass-half"></i> قائمة الانتظار</h3>
                    <span class="edsm-chip"><span class="edsm-num"><?= count($waitingList) ?></span> مريض</span>
                </div>
                <?php if (empty($waitingList)): ?>
                    <div class="edsm-empty">
                        <div class="edsm-empty-icon"><i class="fas fa-users"></i></div>
                        <p>لا يوجد مرضى في قائمة الانتظار</p>
                    </div>
                <?php else: ?>
                    <div class="edsm-list">
                        <?php foreach ($waitingList as $patient): ?>
                            <div class="edsm-row <?= $patient['priority'] === 'emergency' ? 'is-danger' : ($patient['priority'] === 'urgent' ? 'is-warning' : '') ?>">
                                <div class="edsm-row-main">
                                    <div class="edsm-avatar-soft"><i class="fas fa-user"></i></div>
                                    <div class="min-w-0">
                                        <div class="edsm-row-title flex items-center gap-2">
                                            <?= htmlspecialchars($patient['patient_name']) ?>
                                            <?php if ($patient['priority'] === 'emergency'): ?>
                                                <span class="edsm-tag edsm-tag-danger">طارئ</span>
                                            <?php elseif ($patient['priority'] === 'urgent'): ?>
                                                <span class="edsm-tag edsm-tag-warning">عاجل</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="edsm-row-meta">
                                            <i class="fas fa-phone-alt text-xs ml-1"></i><span class="edsm-num"><?= htmlspecialchars($patient['phone'] ?? 'غير محدد') ?></span>
                                            · وصل: <span class="edsm-num"><?= $patient['arrival_time'] ? date('H:i', strtotime($patient['arrival_time'])) : 'غير محدد' ?></span>
                                            <?php if (!empty($patient['reason'])): ?>
                                                · <?= htmlspecialchars($patient['reason']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($patient['medical_history'])): ?>
                                            <div class="text-xs text-red-600 mt-1">
                                                <i class="fas fa-exclamation-triangle ml-1"></i>تاريخ مرضي: <?= htmlspecialchars($patient['medical_history']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($patient['allergies'])): ?>
                                            <div class="text-xs text-red-600">
                                                <i class="fas fa-allergies ml-1"></i>حساسية: <?= htmlspecialchars($patient['allergies']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="edsm-row-actions">
                                    <a href="treatment_new.php?patient_id=<?= $patient['patient_id'] ?>&from_waiting=1" class="edsm-btn edsm-btn-teal">
                                        <i class="fas fa-play"></i> بدء العلاج
                                    </a>
                                    <a href="patient_profile.php?id=<?= $patient['patient_id'] ?>" class="edsm-btn edsm-btn-ghost" title="ملف المريض">
                                        <i class="fas fa-user-circle"></i>
                                    </a>
                                    <?php if (isset($patient['id'])): ?>
                                        <button type="button" onclick="removeFromWaitingList(<?= $patient['id'] ?>)" class="edsm-btn edsm-btn-danger" title="إزالة من القائمة">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Follow-up Statistics -->
            <div class="edsm-card fade-in">
                <div class="edsm-card-head">
                    <h3 class="edsm-card-title"><i class="fas fa-chart-bar"></i> إحصائيات المتابعة</h3>
                    <a href="follow_ups.php" class="edsm-link">إدارة المتابعات <i class="fas fa-chevron-left text-xs"></i></a>
                </div>
                <div class="edsm-mini-grid mb-4">
                    <a href="follow_ups.php?filter=overdue" class="edsm-mini edsm-mini-danger">
                        <div><strong><?= (int)$followUpStats['overdue'] ?></strong><small>متأخرة</small></div>
                        <i class="fas fa-exclamation-triangle"></i>
                    </a>
                    <a href="follow_ups.php?filter=today" class="edsm-mini edsm-mini-teal">
                        <div><strong><?= (int)$followUpStats['today'] ?></strong><small>اليوم</small></div>
                        <i class="far fa-calendar-check"></i>
                    </a>
                    <div class="edsm-mini edsm-mini-blue">
                        <div><strong><?= (int)$followUpStats['upcoming'] ?></strong><small>الأسبوع القادم</small></div>
                        <i class="far fa-clock"></i>
                    </div>
                    <div class="edsm-mini edsm-mini-gray">
                        <div><strong><?= (int)$followUpStats['total_pending'] ?></strong><small>المجموع</small></div>
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <a href="follow_ups.php?filter=today" class="edsm-btn edsm-btn-teal edsm-btn-lg"><i class="fas fa-calendar-day"></i> متابعات اليوم</a>
                    <a href="follow_ups.php?filter=overdue" class="edsm-btn edsm-btn-danger edsm-btn-lg"><i class="fas fa-exclamation-triangle"></i> المتأخرة</a>
                </div>
            </div>

            <!-- Upcoming Follow-ups -->
            <div class="edsm-card edsm-watermark fade-in">
                <div class="edsm-card-head">
                    <h3 class="edsm-card-title"><i class="far fa-clock"></i> المتابعات القادمة</h3>
                    <span class="edsm-chip"><span class="edsm-num"><?= count($upcomingFollowUps) ?></span> متابعة</span>
                </div>
                <?php if (empty($upcomingFollowUps)): ?>
                    <div class="edsm-empty">
                        <div class="edsm-empty-icon"><i class="far fa-calendar-plus"></i></div>
                        <p>لا توجد متابعات مجدولة</p>
                        <a href="follow_ups.php?action=add" class="edsm-btn edsm-btn-teal edsm-btn-lg"><i class="fas fa-plus"></i> إضافة متابعة جديدة</a>
                    </div>
                <?php else: ?>
                    <div class="edsm-list">
                        <?php foreach ($upcomingFollowUps as $followup): ?>
                            <div class="edsm-row <?= $followup['priority'] === 'urgent' ? 'is-danger' : ($followup['priority'] === 'high' ? 'is-warning' : '') ?>">
                                <div class="edsm-row-main">
                                    <div class="edsm-avatar-soft">
                                        <i class="fas fa-<?= $followup['follow_up_type'] === 'birthday' ? 'birthday-cake' : ($followup['follow_up_type'] === 'treatment' ? 'tooth' : 'user-clock') ?>"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="edsm-row-title flex items-center gap-2">
                                            <?= htmlspecialchars($followup['patient_name']) ?>
                                            <?php if ($followup['priority'] === 'urgent'): ?>
                                                <span class="edsm-tag edsm-tag-danger">عاجل</span>
                                            <?php elseif ($followup['priority'] === 'high'): ?>
                                                <span class="edsm-tag edsm-tag-warning">مهم</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="edsm-row-meta">
                                            <span class="edsm-num"><?= date('d/m/Y', strtotime($followup['follow_up_date'])) ?></span>
                                            <?php if ($followup['follow_up_date'] === date('Y-m-d')): ?>
                                                <span class="edsm-tag edsm-tag-success">اليوم</span>
                                            <?php elseif ($followup['follow_up_date'] === date('Y-m-d', strtotime('+1 day'))): ?>
                                                <span class="edsm-tag edsm-tag-success">غداً</span>
                                            <?php endif; ?>
                                            · <?= htmlspecialchars($followup['follow_up_reason'] ?? 'متابعة عامة') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="edsm-row-actions">
                                    <!-- إكمال المتابعة مباشرة (نفس إجراء صفحة المتابعات) -->
                                    <form method="POST" action="follow_ups.php">
                                        <input type="hidden" name="action" value="complete_followup">
                                        <input type="hidden" name="followup_id" value="<?= (int)$followup['id'] ?>">
                                        <button type="submit" class="edsm-btn edsm-btn-teal"><i class="fas fa-check"></i> إكمال</button>
                                    </form>
                                    <a href="patient_profile.php?id=<?= $followup['patient_id'] ?>" class="edsm-btn edsm-btn-ghost" title="ملف المريض">
                                        <i class="fas fa-user-circle"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Monthly Revenue + Recent Treatments -->
        <div class="edsm-card fade-in">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
                <div>
                    <h3 class="edsm-card-title"><i class="fas fa-chart-line"></i> الإيرادات الشهرية</h3>
                    <p class="text-sm text-gray-500 mt-1"><?= $arabicMonths[(int)date('n')] . ' ' . date('Y') ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <img src="../assets/img/edsm-icon.png" alt="" class="w-12 h-auto">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-800">
                            <span class="edsm-num"><?= number_format((float)($monthlyRevenue ?? 0), 2) ?></span> ليرة سورية
                        </div>
                        <div class="text-sm text-gray-500">إجمالي تكلفة علاجاتك هذا الشهر</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <a href="treatments.php?filter=unpaid" class="edsm-tile edsm-tile-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>العلاجات غير المدفوعة</strong>
                    <small>تحتاج متابعة</small>
                </a>
                <a href="patients.php?filter=followup" class="edsm-tile edsm-tile-amber">
                    <i class="fas fa-user-clock"></i>
                    <strong>مواعيد المتابعة</strong>
                    <small>مجدولة للمتابعة</small>
                </a>
                <a href="reports.php" class="edsm-tile edsm-tile-blue">
                    <i class="fas fa-chart-bar"></i>
                    <strong>التقارير التفصيلية</strong>
                    <small>إحصائيات شاملة</small>
                </a>
            </div>

            <div class="edsm-card-head" style="margin-bottom: 10px;">
                <h3 class="edsm-card-title"><i class="fas fa-history"></i> آخر العلاجات</h3>
                <a href="treatments.php" class="edsm-link">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
            </div>
            <?php if (empty($recentTreatments)): ?>
                <div class="edsm-empty">
                    <div class="edsm-empty-icon"><i class="fas fa-file-medical"></i></div>
                    <p>لا توجد علاجات مسجلة حديثاً</p>
                    <a href="treatment_new.php" class="edsm-btn edsm-btn-teal edsm-btn-lg"><i class="fas fa-plus"></i> إضافة أول علاج</a>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($recentTreatments as $treatment): ?>
                        <div class="flex items-center justify-between gap-3 py-3">
                            <div class="edsm-row-main">
                                <div class="edsm-avatar-soft"><i class="fas fa-tooth"></i></div>
                                <div class="min-w-0">
                                    <div class="edsm-row-title"><?= htmlspecialchars($treatment['patient_name']) ?></div>
                                    <div class="edsm-row-meta">
                                        <?= htmlspecialchars($treatment['treatment_details'] ?: ($treatment['treatment_type'] ?? 'غير محدد')) ?>
                                        <?php
                                        $treatmentDate = $treatment['treatment_date'] ?? $treatment['created_at'] ?? '';
                                        if ($treatmentDate) {
                                            echo ' · <span class="edsm-num">' . date('d/m/Y', strtotime($treatmentDate)) . '</span>';
                                        }
                                        $cost = $treatment['cost'] ?? 0;
                                        if ($cost > 0) {
                                            echo ' · <span class="edsm-num">' . number_format($cost, 2) . '</span> ليرة سورية';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="edsm-row-actions">
                                <a href="treatment_details.php?id=<?= $treatment['id'] ?>" class="edsm-btn"><i class="fas fa-eye"></i> عرض</a>
                                <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>" class="edsm-btn edsm-btn-ghost" title="ملف المريض">
                                    <i class="fas fa-user-circle"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Auto-refresh script -->
    <script>
        // تحديث الصفحة كل 10 دقائق
        setTimeout(function() {
            location.reload();
        }, 600000);
        
        // تنبيهات لوحة التحكم تُعرض في زاوية واحدة فوق بعضها
        function dashboardToast(html, tone, ms) {
            let box = document.querySelector('.edsm-toasts');
            if (!box) { box = document.createElement('div'); box.className = 'edsm-toasts'; document.body.appendChild(box); }
            const toast = document.createElement('div');
            toast.className = 'edsm-toast edsm-toast-' + tone;
            toast.innerHTML = html;
            box.appendChild(toast);
            setTimeout(() => toast.remove(), ms);
        }

        // إظهار تنبيه للحالات الطارئة في قائمة الانتظار
        const emergencyPatients = <?= count(array_filter($waitingList, fn($p) => $p['priority'] === 'emergency')) ?>;
        if (emergencyPatients > 0) {
            dashboardToast(`<i class="fas fa-exclamation-triangle"></i><span>يوجد ${emergencyPatients} حالة طارئة في قائمة الانتظار!</span>`, 'danger', 5000);
        }
        
        // تمييز العلاجات التي تحتاج متابعة
        const followupCount = <?= $followupNeeded ?>;
        if (followupCount > 0) {
            console.log(`تذكير: يوجد ${followupCount} مريض يحتاج متابعة هذا الأسبوع`);
        }

        // إظهار تنبيهات المتابعة
        const overdueFollowUps = <?= $followUpStats['overdue'] ?>;
        const todayFollowUps = <?= $followUpStats['today'] ?>;

        if (overdueFollowUps > 0) {
            dashboardToast(`<i class="fas fa-exclamation-triangle"></i><span>يوجد ${overdueFollowUps} متابعة متأخرة!</span><a href="follow_ups.php?filter=overdue">عرض</a>`, 'danger', 8000);
        }

        if (todayFollowUps > 0) {
            dashboardToast(`<i class="fas fa-calendar-check"></i><span>يوجد ${todayFollowUps} متابعة اليوم</span><a href="follow_ups.php?filter=today">عرض</a>`, 'teal', 6000);
        }

        // إزالة مريض من قائمة الانتظار
        function removeFromWaitingList(waitingListId) {
            if (confirm('هل أنت متأكد من إزالة هذا المريض من قائمة الانتظار؟')) {
                fetch('includes/remove_from_waiting_list.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        waiting_list_id: waitingListId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('حدث خطأ أثناء إزالة المريض من قائمة الانتظار');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('حدث خطأ أثناء إزالة المريض من قائمة الانتظار');
                });
            }
        }
    </script>
</body>
</html>  