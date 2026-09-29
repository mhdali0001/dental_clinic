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
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hover-scale:hover { transform: scale(1.02); transition: transform 0.2s; }
        .medical-alert { background: linear-gradient(45deg, #fee2e2, #fef2f2); }
        .treatment-card { transition: all 0.3s ease; }
        .treatment-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .analytics-topbar { transition: all 0.3s ease; }
        .analytics-topbar:hover { transform: scale(1.02); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15); }
        .analytics-metric { transition: color 0.2s ease; }
        .analytics-metric:hover { transform: scale(1.1); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <!-- Dashboard-specific Analytics Bar -->
    <div class="bg-blue-50 border-b-2 border-blue-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">
                    <i class="fas fa-calendar-day ml-1"></i>
                    <?= date('d/m/Y - l', strtotime($today)) ?>
                </div>

                <div class="flex items-center space-x-6 space-x-reverse">
                    <div class="text-center">
                        <div class="text-blue-600 font-bold text-lg"><?= $todayAppointments ?></div>
                        <div class="text-gray-600 text-xs">مواعيد اليوم</div>
                    </div>
                    <div class="w-px h-8 bg-blue-200"></div>
                    <div class="text-center">
                        <div class="text-green-600 font-bold text-lg"><?= $treatmentsThisWeek ?></div>
                        <div class="text-gray-600 text-xs">علاجات الأسبوع</div>
                    </div>
                    <div class="w-px h-8 bg-blue-200"></div>
                    <div class="text-center">
                        <div class="text-purple-600 font-bold text-lg"><?= number_format((float)($monthlyRevenue ?? 0), 0) ?></div>
                        <div class="text-gray-600 text-xs">إيرادات الشهر</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مواعيد اليوم</p>
                        <p class="text-3xl font-bold text-blue-600"><?= $todayAppointments ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= date('d/m/Y') ?></p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-calendar-day text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">علاجات هذا الأسبوع</p>
                        <p class="text-3xl font-bold text-green-600"><?= $treatmentsThisWeek ?></p>
                        <p class="text-xs text-gray-500 mt-1">مكتملة</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-tooth text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">يحتاجون متابعة</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $followupNeeded ?></p>
                        <p class="text-xs text-gray-500 mt-1">هذا الأسبوع</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-user-clock text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">إجمالي المرضى</p>
                        <p class="text-3xl font-bold text-purple-600"><?= $totalPatientsTreated ?></p>
                        <p class="text-xs text-gray-500 mt-1">معالجين</p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i class="fas fa-users text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
<div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
    <h3 class="text-xl font-bold text-gray-800 mb-6">
        <i class="fas fa-bolt text-yellow-500 ml-2"></i>
        إجراءات سريعة
    </h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <a href="treatment_new.php" class="bg-green-500 hover:bg-green-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-plus-square text-2xl mb-2"></i>
            <div class="font-semibold">إضافة علاج جديد</div>
        </a>

        <!-- ميزات السكرتاريا المتاحة للطبيب -->
        <a href="../nurse/patients.php?action=add" class="bg-pink-500 hover:bg-pink-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-user-plus text-2xl mb-2"></i>
            <div class="font-semibold">إضافة مريض جديد</div>
        </a>

        <a href="../nurse/appointments.php?action=add" class="bg-blue-700 hover:bg-blue-800 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-calendar-plus text-2xl mb-2"></i>
            <div class="font-semibold">حجز موعد</div>
        </a>

        <a href="../nurse/patient_balance.php" class="bg-green-700 hover:bg-green-800 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-cash-register text-2xl mb-2"></i>
            <div class="font-semibold">تسجيل دفعة</div>
        </a>

        <a href="appointments.php" class="bg-blue-500 hover:bg-blue-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-calendar-check text-2xl mb-2"></i>
            <div class="font-semibold">جدول المواعيد</div>
        </a>
        
        <a href="follow_ups.php" class="bg-yellow-500 hover:bg-yellow-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-user-clock text-2xl mb-2"></i>
            <div class="font-semibold">إدارة المتابعات</div>
        </a>
        
        <a href="treatments.php?filter=recent" class="bg-purple-500 hover:bg-purple-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-history text-2xl mb-2"></i>
            <div class="font-semibold">العلاجات الأخيرة</div>
        </a>
        
        <a href="reports.php" class="bg-indigo-500 hover:bg-indigo-600 text-white p-4 rounded-lg text-center transition hover-scale">
            <i class="fas fa-chart-bar text-2xl mb-2"></i>
            <div class="font-semibold">التقارير الطبية</div>
        </a>
    </div>
</div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
<!-- Today's Appointments - محدث -->
<div class="bg-white rounded-lg shadow-lg p-6 fade-in">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-gray-800">
            <i class="fas fa-calendar-day text-blue-500 ml-2"></i>
            مواعيد اليوم
        </h3>
        <a href="appointments.php" class="text-blue-600 hover:text-blue-800 text-sm">
            عرض الكل <i class="fas fa-arrow-left mr-1"></i>
        </a>
    </div>
    
    <div class="space-y-4 max-h-96 overflow-y-auto">
        <?php if (empty($todayAppointmentsList)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-calendar-day text-4xl mb-4 opacity-50"></i>
                <p>لا توجد مواعيد اليوم</p>
                <a href="treatment_new.php" class="mt-3 inline-block bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition">
                    إضافة علاج جديد
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($todayAppointmentsList as $appointment): ?>
                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-2 rounded-full ml-3">
                            <i class="fas fa-user text-blue-600"></i>
                        </div>
                        <div>
                            <p class="font-medium"><?= htmlspecialchars($appointment['patient_name']) ?></p>
                            <p class="text-sm text-gray-600">
                                <?php
                                $time = '';
                                if (!empty($appointment['appointment_time'])) {
                                    $time = date('H:i', strtotime($appointment['appointment_time']));
                                } elseif (!empty($appointment['appointment_date'])) {
                                    $time = date('H:i', strtotime($appointment['appointment_date']));
                                }
                                echo $time;
                                ?>
                                <?php if (!empty($appointment['treatment_type'])): ?>
                                    - <?= htmlspecialchars($appointment['treatment_type']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <?php if (!$appointment['treatment_id']): ?>
                            <a href="treatment_new.php?appointment_id=<?= $appointment['id'] ?>&patient_id=<?= $appointment['patient_id'] ?>" 
                               class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm transition">
                                <i class="fas fa-plus ml-1"></i>علاج
                            </a>
                        <?php else: ?>
                            <a href="treatment_details.php?id=<?= $appointment['treatment_id'] ?>" 
                               class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition">
                                <i class="fas fa-eye ml-1"></i>عرض
                            </a>
                        <?php endif; ?>
                        <a href="patient_profile.php?id=<?= $appointment['patient_id'] ?>" 
                           class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm transition">
                            <i class="fas fa-user-circle ml-1"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Waiting List Section -->
<div class="bg-white rounded-lg shadow-lg p-6 fade-in">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-gray-800">
            <i class="fas fa-hourglass-half text-orange-500 ml-2"></i>
            قائمة الانتظار
        </h3>
        <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium">
            <?= count($waitingList) ?> مريض
        </span>
    </div>

    <div class="space-y-4 max-h-96 overflow-y-auto">
        <?php if (empty($waitingList)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-user-clock text-4xl mb-4 opacity-50"></i>
                <p>لا يوجد مرضى في قائمة الانتظار</p>
            </div>
        <?php else: ?>
            <?php foreach ($waitingList as $patient): ?>
                <div class="flex items-center justify-between p-4 border rounded-lg <?= $patient['priority'] === 'emergency' ? 'border-red-300 bg-red-50' : ($patient['priority'] === 'urgent' ? 'border-yellow-300 bg-yellow-50' : 'border-gray-200') ?>">
                    <div class="flex items-center">
                        <div class="<?= $patient['priority'] === 'emergency' ? 'bg-red-500' : ($patient['priority'] === 'urgent' ? 'bg-yellow-500' : 'bg-gray-500') ?> p-2 rounded-full ml-3">
                            <i class="fas fa-user text-white"></i>
                        </div>
                        <div>
                            <div class="flex items-center">
                                <p class="font-medium"><?= htmlspecialchars($patient['patient_name']) ?></p>
                                <?php if ($patient['priority'] === 'emergency'): ?>
                                    <span class="bg-red-500 text-white text-xs px-2 py-1 rounded ml-2">طارئ</span>
                                <?php elseif ($patient['priority'] === 'urgent'): ?>
                                    <span class="bg-yellow-500 text-white text-xs px-2 py-1 rounded ml-2">عاجل</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600">
                                الهاتف: <?= htmlspecialchars($patient['phone'] ?? 'غير محدد') ?>
                            </p>
                            <p class="text-xs text-gray-500">
                                وقت الوصول: <?= $patient['arrival_time'] ? date('H:i', strtotime($patient['arrival_time'])) : 'غير محدد' ?>
                                <?php if (!empty($patient['reason'])): ?>
                                    - <?= htmlspecialchars($patient['reason']) ?>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($patient['medical_history'])): ?>
                                <p class="text-xs text-red-600 mt-1">
                                    <i class="fas fa-exclamation-triangle ml-1"></i>
                                    تاريخ مرضي: <?= htmlspecialchars($patient['medical_history']) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($patient['allergies'])): ?>
                                <p class="text-xs text-red-600">
                                    <i class="fas fa-allergies ml-1"></i>
                                    حساسية: <?= htmlspecialchars($patient['allergies']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="treatment_new.php?patient_id=<?= $patient['patient_id'] ?>&from_waiting=1"
                           class="bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded text-sm transition">
                            <i class="fas fa-plus ml-1"></i>بدء العلاج
                        </a>
                        <a href="patient_profile.php?id=<?= $patient['patient_id'] ?>"
                           class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm transition">
                            <i class="fas fa-user-circle ml-1"></i>الملف
                        </a>
                        <?php if (isset($patient['id'])): ?>
                            <button onclick="removeFromWaitingList(<?= $patient['id'] ?>)"
                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded text-sm transition">
                                <i class="fas fa-times ml-1"></i>إزالة
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
        </div>

        <!-- Follow-ups Management Widget -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
            <!-- Follow-up Statistics -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-calendar-check text-green-500 ml-2"></i>
                        إحصائيات المتابعة
                    </h3>
                    <a href="follow_ups.php" class="text-green-600 hover:text-green-800 text-sm">
                        إدارة المتابعات <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-red-600"><?= $followUpStats['overdue'] ?></div>
                        <div class="text-sm text-red-700">متأخرة</div>
                    </div>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-green-600"><?= $followUpStats['today'] ?></div>
                        <div class="text-sm text-green-700">اليوم</div>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-blue-600"><?= $followUpStats['upcoming'] ?></div>
                        <div class="text-sm text-blue-700">الأسبوع القادم</div>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                        <div class="text-2xl font-bold text-gray-600"><?= $followUpStats['total_pending'] ?></div>
                        <div class="text-sm text-gray-700">المجموع</div>
                    </div>
                </div>

                <div class="flex space-x-3 space-x-reverse">
                    <a href="follow_ups.php?filter=today"
                       class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded text-center text-sm transition">
                        <i class="fas fa-calendar-day ml-1"></i>متابعات اليوم
                    </a>
                    <a href="follow_ups.php?filter=overdue"
                       class="flex-1 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-center text-sm transition">
                        <i class="fas fa-exclamation-triangle ml-1"></i>المتأخرة
                    </a>
                </div>
            </div>

            <!-- Upcoming Follow-ups -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-clock text-blue-500 ml-2"></i>
                        المتابعات القادمة
                    </h3>
                    <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                        <?= count($upcomingFollowUps) ?> متابعة
                    </span>
                </div>

                <div class="space-y-3 max-h-80 overflow-y-auto">
                    <?php if (empty($upcomingFollowUps)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-check text-4xl mb-4 opacity-50"></i>
                            <p>لا توجد متابعات مجدولة</p>
                            <a href="follow_ups.php?action=add" class="mt-3 inline-block bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded text-sm transition">
                                إضافة متابعة جديدة
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcomingFollowUps as $followup): ?>
                            <div class="flex items-center justify-between p-3 border rounded-lg
                                        <?= $followup['priority'] === 'urgent' ? 'border-red-200 bg-red-50' :
                                           ($followup['priority'] === 'high' ? 'border-yellow-200 bg-yellow-50' : 'border-gray-200') ?>">
                                <div class="flex items-center">
                                    <div class="<?= $followup['priority'] === 'urgent' ? 'bg-red-500' :
                                                   ($followup['priority'] === 'high' ? 'bg-yellow-500' : 'bg-blue-500') ?> p-2 rounded-full ml-3">
                                        <i class="fas fa-<?= $followup['follow_up_type'] === 'birthday' ? 'birthday-cake' :
                                                          ($followup['follow_up_type'] === 'treatment' ? 'tooth' : 'user-clock') ?> text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <div class="flex items-center">
                                            <p class="font-medium text-sm"><?= htmlspecialchars($followup['patient_name']) ?></p>
                                            <?php if ($followup['priority'] === 'urgent'): ?>
                                                <span class="bg-red-500 text-white text-xs px-2 py-1 rounded mr-2">عاجل</span>
                                            <?php elseif ($followup['priority'] === 'high'): ?>
                                                <span class="bg-yellow-500 text-white text-xs px-2 py-1 rounded mr-2">مهم</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-gray-600">
                                            <?= date('d/m/Y', strtotime($followup['follow_up_date'])) ?>
                                            <?php if ($followup['follow_up_date'] === date('Y-m-d')): ?>
                                                <span class="text-green-600 font-medium">- اليوم</span>
                                            <?php elseif ($followup['follow_up_date'] === date('Y-m-d', strtotime('+1 day'))): ?>
                                                <span class="text-blue-600 font-medium">- غداً</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?= htmlspecialchars($followup['follow_up_reason'] ?? 'متابعة عامة') ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="follow_ups.php?action=complete&id=<?= $followup['id'] ?>"
                                       class="bg-green-500 hover:bg-green-600 text-white px-2 py-1 rounded text-xs transition">
                                        <i class="fas fa-check ml-1"></i>إكمال
                                    </a>
                                    <a href="patient_profile.php?id=<?= $followup['patient_id'] ?>"
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-xs transition">
                                        <i class="fas fa-user ml-1"></i>الملف
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monthly Revenue Summary -->
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-chart-line text-green-500 ml-2"></i>
                        الإيرادات الشهرية
                    </h3>
                    <p class="text-gray-600">شهر <?= date('m/Y') ?></p>
                </div>
                <div class="text-right">
                    <div class="text-3xl font-bold text-green-600">
                        <?= number_format((float)($monthlyRevenue ?? 0), 2) ?> ليرة سورية
                    </div>
                    <p class="text-sm text-gray-500">إجمالي العلاجات</p>
                </div>
            </div>
            
            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="treatments.php?filter=unpaid" class="bg-red-50 border border-red-200 rounded-lg p-4 hover:bg-red-100 transition">
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl mb-2"></i>
                        <div class="font-semibold text-red-800">العلاجات غير المدفوعة</div>
                        <div class="text-sm text-red-600">تحتاج متابعة</div>
                    </div>
                </a>
                
                <a href="patients.php?filter=followup" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 hover:bg-yellow-100 transition">
                    <div class="text-center">
                        <i class="fas fa-user-clock text-yellow-600 text-2xl mb-2"></i>
                        <div class="font-semibold text-yellow-800">مواعيد المتابعة</div>
                        <div class="text-sm text-yellow-600">مجدولة قريباً</div>
                    </div>
                </a>
                
                <a href="reports.php" class="bg-blue-50 border border-blue-200 rounded-lg p-4 hover:bg-blue-100 transition">
                    <div class="text-center">
                        <i class="fas fa-chart-bar text-blue-600 text-2xl mb-2"></i>
                        <div class="font-semibold text-blue-800">التقارير التفصيلية</div>
                        <div class="text-sm text-blue-600">إحصائيات شاملة</div>
                    </div>
                </a>
            </div>
        </div>

        
<!-- Recent Treatments - محدث -->
<div class="bg-white rounded-lg shadow-lg p-6 fade-in">
    <div class="flex justify-between items-center mb-6">
        <h3 class="text-xl font-bold text-gray-800">
            <i class="fas fa-history text-purple-500 ml-2"></i>
            آخر العلاجات
        </h3>
        <a href="treatments.php" class="text-purple-600 hover:text-purple-800 text-sm">
            عرض الكل <i class="fas fa-arrow-left mr-1"></i>
        </a>
    </div>
    
    <div class="space-y-4 max-h-96 overflow-y-auto">
        <?php if (empty($recentTreatments)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-file-medical text-4xl mb-4 opacity-50"></i>
                <p>لا توجد علاجات مسجلة حديثاً</p>
                <a href="treatment_new.php" class="mt-3 inline-block bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition">
                    إضافة أول علاج
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($recentTreatments as $treatment): ?>
                <div class="flex items-center justify-between py-3 border-b last:border-b-0">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-2 rounded-full ml-3">
                            <i class="fas fa-tooth text-purple-600"></i>
                        </div>
                        <div>
                            <p class="font-medium"><?= htmlspecialchars($treatment['patient_name']) ?></p>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($treatment['treatment_type'] ?? 'غير محدد') ?></p>
                            <p class="text-xs text-gray-500">
                                <?php
                                $treatmentDate = $treatment['treatment_date'] ?? $treatment['created_at'] ?? '';
                                if ($treatmentDate) {
                                    echo date('d/m/Y', strtotime($treatmentDate));
                                }
                                $cost = $treatment['cost'] ?? 0;
                                if ($cost > 0) {
                                    echo ' - ' . number_format($cost, 2) . ' ليرة سورية';
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="treatment_details.php?id=<?= $treatment['id'] ?>" 
                           class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition">
                            <i class="fas fa-eye ml-1"></i>عرض
                        </a>
                        <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>" 
                           class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm transition">
                            <i class="fas fa-user-circle ml-1"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
    </div>

    <!-- Auto-refresh script -->
    <script>
        // تحديث الصفحة كل 10 دقائق
        setTimeout(function() {
            location.reload();
        }, 600000);
        
        // إظهار تنبيه للحالات الطارئة في قائمة الانتظار
        const emergencyPatients = <?= count(array_filter($waitingList, fn($p) => $p['priority'] === 'emergency')) ?>;
        if (emergencyPatients > 0) {
            const notification = document.createElement('div');
            notification.className = 'fixed top-4 left-4 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg z-50';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle ml-2"></i>
                    <span>يوجد ${emergencyPatients} حالة طارئة في قائمة الانتظار!</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            // إخفاء التنبيه بعد 5 ثوان
            setTimeout(() => {
                notification.remove();
            }, 5000);
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
            const notification = document.createElement('div');
            notification.className = 'fixed top-20 left-4 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg z-50';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle ml-2"></i>
                    <span>يوجد ${overdueFollowUps} متابعة متأخرة!</span>
                    <a href="follow_ups.php?filter=overdue" class="bg-red-700 hover:bg-red-800 px-3 py-1 rounded text-sm mr-3">عرض</a>
                </div>
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 8000);
        }

        if (todayFollowUps > 0) {
            const notification = document.createElement('div');
            notification.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg z-50';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-calendar-check ml-2"></i>
                    <span>يوجد ${todayFollowUps} متابعة اليوم</span>
                    <a href="follow_ups.php?filter=today" class="bg-green-700 hover:bg-green-800 px-3 py-1 rounded text-sm mr-3">عرض</a>
                </div>
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 6000);
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