<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('nurse');

// Set page variables for header
$pageTitle = 'لوحة تحكم الممرضة';
$pageIcon = 'fas fa-user-nurse';
$pageSubtitle = 'مرحباً بك في نظام إدارة العيادة';
$currentPage = 'dashboard';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();
// جلب الإحصائيات
try {
    // إحصائيات اليوم
    $today = date('Y-m-d');
    
    // عدد المواعيد اليوم
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date) = ?");
    $stmt->execute([$today]);
    $todayAppointments = $stmt->fetchColumn();
    
    // عدد المرضى الجدد هذا الأسبوع
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(registration_date) >= ?");
    $stmt->execute([$weekStart]);
    $newPatients = $stmt->fetchColumn();
    
    // عدد المرضى في قائمة الانتظار
    $stmt = $pdo->query("SELECT COUNT(*) FROM waiting_list WHERE status = 'waiting'");
    $waitingPatients = $stmt->fetchColumn();
    
    // إجمالي المرضى النشطين
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'active'");
    $totalActivePatients = $stmt->fetchColumn();

    // إحصائيات المتابعات
    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending'");
    $pendingFollowups = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending' AND follow_up_date = CURDATE()");
    $todayFollowups = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending' AND follow_up_date < CURDATE()");
    $overdueFollowups = $stmt->fetchColumn();
    
    // مواعيد اليوم بالتفصيل
    $stmt = $pdo->prepare("
        SELECT a.*, p.name as patient_name, p.phone 
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE DATE(a.appointment_date) = ? 
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$today]);
    $todayAppointmentsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // قائمة الانتظار
    $stmt = $pdo->prepare("
        SELECT wl.*, p.name as patient_name, p.phone 
        FROM waiting_list wl 
        JOIN patients p ON wl.patient_id = p.id 
        WHERE wl.status = 'waiting' 
        ORDER BY wl.priority = 'emergency' DESC, 
                 wl.priority = 'urgent' DESC, 
                 wl.arrival_time ASC
    ");
    $stmt->execute();
    $waitingList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // متابعات اليوم والمتأخرة
    $stmt = $pdo->prepare("
        SELECT f.*, p.name as patient_name, p.phone
        FROM follow_ups f
        JOIN patients p ON f.patient_id = p.id
        WHERE f.status = 'pending' AND f.follow_up_date <= ?
        ORDER BY f.follow_up_date ASC, f.priority DESC
        LIMIT 10
    ");
    $stmt->execute([$today]);
    $todayFollowupsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // آخر النشاطات
    $stmt = $pdo->prepare("
        SELECT 'appointment' as type, a.id, p.name as patient_name, a.appointment_time as time, a.status
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        WHERE DATE(a.appointment_date) = ?
        UNION ALL
        SELECT 'waiting' as type, wl.id, p.name as patient_name, wl.arrival_time as time, wl.status
        FROM waiting_list wl
        JOIN patients p ON wl.patient_id = p.id
        WHERE DATE(wl.arrival_time) = ?
        UNION ALL
        SELECT 'followup' as type, f.id, p.name as patient_name, f.follow_up_date as time, f.status
        FROM follow_ups f
        JOIN patients p ON f.patient_id = p.id
        WHERE DATE(f.follow_up_date) = ? AND f.status = 'pending'
        ORDER BY time DESC
        LIMIT 10
    ");
    $stmt->execute([$today, $today, $today]);
    $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم الممرضة - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hover-scale:hover { transform: scale(1.02); transition: transform 0.2s; }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
        .emergency-blink { animation: blink 1s infinite; }
        @keyframes blink { 0%, 50% { background-color: #ef4444; } 51%, 100% { background-color: #dc2626; } }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/nurse_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-400 hover-scale">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-calendar-check text-blue-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">مواعيد اليوم</p>
                        <p class="text-2xl font-bold text-blue-600"><?= $todayAppointments ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-400 hover-scale">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">في الانتظار</p>
                        <p class="text-2xl font-bold text-yellow-600"><?= $waitingPatients ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-400 hover-scale">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-calendar-alt text-green-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات اليوم</p>
                        <p class="text-2xl font-bold text-green-600"><?= $todayFollowups ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-red-400 hover-scale">
                <div class="flex items-center">
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات متأخرة</p>
                        <p class="text-2xl font-bold text-red-600"><?= $overdueFollowups ?></p>
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
                <a href="patients.php?action=add" class="bg-green-500 hover:bg-green-600 text-white p-4 rounded-lg text-center transition hover-scale">
                    <i class="fas fa-user-plus text-2xl mb-2"></i>
                    <div class="font-semibold">إضافة مريض جديد</div>
                </a>
                
                <a href="appointments.php?action=add" class="bg-blue-500 hover:bg-blue-600 text-white p-4 rounded-lg text-center transition hover-scale">
                    <i class="fas fa-calendar-plus text-2xl mb-2"></i>
                    <div class="font-semibold">حجز موعد</div>
                </a>
                
                <a href="waiting_list.php?action=add" class="bg-yellow-500 hover:bg-yellow-600 text-white p-4 rounded-lg text-center transition hover-scale">
                    <i class="fas fa-plus-circle text-2xl mb-2"></i>
                    <div class="font-semibold">إضافة للانتظار</div>
                </a>

                <a href="follow_ups.php" class="bg-purple-500 hover:bg-purple-600 text-white p-4 rounded-lg text-center transition hover-scale">
                    <i class="fas fa-calendar-check text-2xl mb-2"></i>
                    <div class="font-semibold">إدارة المتابعات</div>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Today's Appointments -->
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
                            <p>لا توجد مواعيد لليوم</p>
                            <a href="appointments.php?action=add" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                                حجز موعد جديد
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($todayAppointmentsList as $appointment): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex-1">
                                        <h6 class="font-semibold text-gray-800">
                                            <i class="fas fa-user text-gray-500 ml-1"></i>
                                            <?= htmlspecialchars($appointment['patient_name']) ?>
                                        </h6>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-tooth text-gray-500 ml-1"></i>
                                            <?= htmlspecialchars($appointment['treatment_type']) ?>
                                        </p>
                                        <?php if ($appointment['phone']): ?>
                                            <p class="text-sm text-gray-500 mt-1">
                                                <i class="fas fa-phone text-gray-500 ml-1"></i>
                                                <a href="tel:<?= $appointment['phone'] ?>" class="text-green-600 hover:text-green-800">
                                                    <?= $appointment['phone'] ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-left">
                                        <div class="text-lg font-bold text-blue-600">
                                            <?= date('H:i', strtotime($appointment['appointment_time'])) ?>
                                        </div>
                                        <?php
                                        $statusClasses = [
                                            'scheduled' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-blue-100 text-blue-800',
                                            'completed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusTexts = [
                                            'scheduled' => 'مجدول',
                                            'confirmed' => 'مؤكد',
                                            'completed' => 'مكتمل',
                                            'cancelled' => 'ملغي'
                                        ];
                                        ?>
                                        <span class="<?= $statusClasses[$appointment['status']] ?? 'bg-gray-100 text-gray-800' ?> text-xs px-2 py-1 rounded-full font-medium">
                                            <?= $statusTexts[$appointment['status']] ?? $appointment['status'] ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($appointment['notes']): ?>
                                    <div class="bg-blue-50 border-r-4 border-blue-300 p-2 mt-2">
                                        <p class="text-sm text-blue-800">
                                            <i class="fas fa-sticky-note ml-1"></i>
                                            <?= htmlspecialchars($appointment['notes']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Waiting List -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-clock text-yellow-500 ml-2"></i>
                        قائمة الانتظار
                    </h3>
                    <a href="waiting_list.php" class="text-yellow-600 hover:text-yellow-800 text-sm">
                        عرض الكل <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
                
                <div class="space-y-4 max-h-96 overflow-y-auto">
                    <?php if (empty($waitingList)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-clock text-4xl mb-4 opacity-50"></i>
                            <p>لا توجد مرضى في قائمة الانتظار</p>
                            <a href="waiting_list.php?action=add" class="text-yellow-600 hover:text-yellow-800 mt-2 inline-block">
                                إضافة مريض للانتظار
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($waitingList as $index => $patient): ?>
                            <?php
                            $priorityClasses = [
                                'emergency' => 'bg-red-50 border-red-200 emergency-blink',
                                'urgent' => 'bg-yellow-50 border-yellow-200',
                                'normal' => 'bg-green-50 border-green-200'
                            ];
                            $priorityBadges = [
                                'emergency' => 'bg-red-500 text-white',
                                'urgent' => 'bg-yellow-500 text-white',
                                'normal' => 'bg-green-500 text-white'
                            ];
                            $priorityTexts = [
                                'emergency' => 'طارئ',
                                'urgent' => 'مستعجل',
                                'normal' => 'عادي'
                            ];
                            ?>
                            <div class="border rounded-lg p-4 <?= $priorityClasses[$patient['priority']] ?? 'bg-gray-50' ?> hover:shadow-md transition">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex-1">
                                        <div class="flex items-center mb-2">
                                            <span class="bg-white text-gray-800 text-xs font-bold px-2 py-1 rounded-full ml-2">
                                                #<?= $index + 1 ?>
                                            </span>
                                            <h6 class="font-semibold text-gray-800">
                                                <?= htmlspecialchars($patient['patient_name']) ?>
                                            </h6>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-1">
                                            <i class="fas fa-tooth text-gray-500 ml-1"></i>
                                            <?= htmlspecialchars($patient['treatment_type'] ?? 'فحص عام') ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <i class="fas fa-clock ml-1"></i>
                                            انتظار: <?= date('H:i', strtotime($patient['arrival_time'])) ?>
                                        </p>
                                    </div>
                                    <div class="text-left">
                                        <span class="<?= $priorityBadges[$patient['priority']] ?? 'bg-gray-500 text-white' ?> text-xs px-2 py-1 rounded-full font-medium">
                                            <?= $priorityTexts[$patient['priority']] ?? $patient['priority'] ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($patient['notes']): ?>
                                    <div class="bg-blue-50 border-r-2 border-blue-300 p-2 mt-2">
                                        <p class="text-xs text-blue-800">
                                            <i class="fas fa-sticky-note ml-1"></i>
                                            <?= htmlspecialchars($patient['notes']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="flex gap-2 mt-3">
                                    <a href="waiting_list.php?action=call&id=<?= $patient['id'] ?>" 
                                       class="flex-1 bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-2 rounded transition text-center">
                                        <i class="fas fa-volume-up ml-1"></i>
                                        استدعاء
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Follow-ups Widget -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-calendar-alt text-green-500 ml-2"></i>
                        متابعات اليوم والمتأخرة
                    </h3>
                    <a href="follow_ups.php" class="text-green-600 hover:text-green-800 text-sm">
                        عرض الكل <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>

                <div class="space-y-4 max-h-96 overflow-y-auto">
                    <?php if (empty($todayFollowupsList)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-alt text-4xl mb-4 opacity-50"></i>
                            <p>لا توجد متابعات لليوم</p>
                            <a href="follow_ups.php" class="text-green-600 hover:text-green-800 mt-2 inline-block">
                                إضافة متابعة جديدة
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($todayFollowupsList as $followup): ?>
                            <?php
                            $is_overdue = $followup['follow_up_date'] < date('Y-m-d');
                            $priority_colors = [
                                'urgent' => 'text-red-600',
                                'high' => 'text-orange-600',
                                'normal' => 'text-blue-600',
                                'low' => 'text-gray-600'
                            ];
                            $type_icons = [
                                'birthday' => 'fas fa-birthday-cake',
                                'treatment' => 'fas fa-medical-kit',
                                'manual' => 'fas fa-user-edit'
                            ];
                            ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition <?= $is_overdue ? 'bg-red-50 border-red-200' : 'hover:bg-gray-50' ?>">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex-1">
                                        <div class="flex items-center mb-2">
                                            <i class="<?= $type_icons[$followup['follow_up_type']] ?? 'fas fa-calendar' ?> text-green-600 ml-2"></i>
                                            <h6 class="font-semibold text-gray-800">
                                                <?= htmlspecialchars($followup['patient_name']) ?>
                                            </h6>
                                            <?php if ($followup['priority'] !== 'normal'): ?>
                                                <span class="mr-2 px-2 py-1 text-xs rounded-full <?= $followup['priority'] === 'urgent' ? 'bg-red-100 text-red-800' : ($followup['priority'] === 'high' ? 'bg-orange-100 text-orange-800' : 'bg-gray-100 text-gray-800') ?>">
                                                    <?= ['urgent' => 'عاجل', 'high' => 'عالي', 'low' => 'منخفض'][$followup['priority']] ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="text-sm text-gray-600 mb-1">
                                            <i class="fas fa-comment text-gray-500 ml-1"></i>
                                            <?= htmlspecialchars($followup['follow_up_reason']) ?>
                                        </p>

                                        <?php if ($followup['phone']): ?>
                                            <p class="text-sm text-gray-500 mb-1">
                                                <i class="fas fa-phone text-gray-500 ml-1"></i>
                                                <a href="tel:<?= $followup['phone'] ?>" class="text-green-600 hover:text-green-800">
                                                    <?= $followup['phone'] ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>

                                        <div class="text-xs text-gray-500 flex items-center">
                                            <i class="fas fa-calendar ml-1"></i>
                                            <span class="<?= $is_overdue ? 'text-red-600 font-semibold' : '' ?>">
                                                <?= date('d/m/Y', strtotime($followup['follow_up_date'])) ?>
                                                <?php if ($is_overdue): ?>
                                                    <span class="mr-1 text-red-600">(متأخر)</span>
                                                <?php elseif ($followup['follow_up_date'] === date('Y-m-d')): ?>
                                                    <span class="mr-1 text-blue-600">(اليوم)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="text-left">
                                        <div class="flex flex-col gap-1">
                                            <a href="follow_ups.php?status=pending"
                                               class="bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-1 rounded transition">
                                                <i class="fas fa-check ml-1"></i>
                                                إكمال
                                            </a>
                                            <a href="patient_details.php?id=<?= $followup['patient_id'] ?>"
                                               class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-3 py-1 rounded transition">
                                                <i class="fas fa-eye ml-1"></i>
                                                ملف المريض
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($followup['notes']): ?>
                                    <div class="bg-yellow-50 border-r-2 border-yellow-300 p-2 mt-2">
                                        <p class="text-xs text-yellow-800">
                                            <i class="fas fa-sticky-note ml-1"></i>
                                            <?= htmlspecialchars($followup['notes']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-history text-gray-500 ml-2"></i>
                آخر النشاطات
            </h3>
            
            <div class="space-y-3 max-h-64 overflow-y-auto">
                <?php if (empty($recentActivities)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-history text-4xl mb-4 opacity-50"></i>
                        <p>لا توجد أنشطة حديثة</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="flex items-center justify-between py-3 border-b last:border-b-0">
                            <div class="flex items-center">
                                <?php if ($activity['type'] === 'appointment'): ?>
                                    <div class="bg-blue-100 p-2 rounded-full ml-3">
                                        <i class="fas fa-calendar-check text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium">موعد مع <?= htmlspecialchars($activity['patient_name']) ?></p>
                                        <p class="text-sm text-gray-600">حالة: <?= $activity['status'] ?></p>
                                    </div>
                                <?php elseif ($activity['type'] === 'followup'): ?>
                                    <div class="bg-green-100 p-2 rounded-full ml-3">
                                        <i class="fas fa-calendar-alt text-green-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium">متابعة مع <?= htmlspecialchars($activity['patient_name']) ?></p>
                                        <p class="text-sm text-gray-600">حالة: معلقة</p>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-yellow-100 p-2 rounded-full ml-3">
                                        <i class="fas fa-clock text-yellow-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium"><?= htmlspecialchars($activity['patient_name']) ?> في الانتظار</p>
                                        <p class="text-sm text-gray-600">حالة: <?= $activity['status'] ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?= date('H:i', strtotime($activity['time'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Auto-refresh script -->
    <script>
        // تحديث الصفحة كل 5 دقائق
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        // إشعار صوتي للحالات الطارئة
        const emergencyPatients = <?= count(array_filter($waitingList, fn($p) => $p['priority'] === 'emergency')) ?>;
        if (emergencyPatients > 0 && !sessionStorage.getItem('emergencyAlerted')) {
            // تشغيل صوت تنبيه
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhCC6Y2v');
            audio.play().catch(() => {});
            
            sessionStorage.setItem('emergencyAlerted', 'true');
            setTimeout(() => sessionStorage.removeItem('emergencyAlerted'), 600000); // إعادة التنبيه بعد 10 دقائق
        }
        
        // Analytics topbar click functionality
        document.querySelector('.analytics-topbar').addEventListener('click', function() {
            // Create a tooltip or modal showing more details
            const tooltip = document.createElement('div');
            tooltip.className = 'fixed top-20 right-4 bg-white border border-gray-200 rounded-lg shadow-lg p-4 z-50 fade-in';
            tooltip.innerHTML = `
                <div class="text-sm">
                    <h4 class="font-bold text-gray-800 mb-2">تفاصيل سريعة</h4>
                    <div class="space-y-1">
                        <div>• مواعيد اليوم: <?= $todayAppointments ?></div>
                        <div>• في الانتظار: <?= $waitingPatients ?></div>
                        <div>• متابعات اليوم: <?= $todayFollowups ?></div>
                        <div>• متابعات متأخرة: <?= $overdueFollowups ?></div>
                        <div>• مرضى جدد هذا الأسبوع: <?= $newPatients ?></div>
                        <div>• إجمالي المرضى النشطين: <?= $totalActivePatients ?></div>
                    </div>
                    <div class="mt-3 text-xs text-gray-500">
                        انقر في أي مكان آخر لإغلاق هذه النافذة
                    </div>
                </div>
            `;
            document.body.appendChild(tooltip);
            
            // Remove tooltip on click outside
            setTimeout(() => {
                document.addEventListener('click', function(e) {
                    if (!tooltip.contains(e.target)) {
                        tooltip.remove();
                    }
                }, { once: true });
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (document.body.contains(tooltip)) {
                    tooltip.remove();
                }
            }, 5000);
        });
    </script>
</body>
</html>