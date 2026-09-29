<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
// رسالة نجاح قادمة من صفحة حجز الموعد (صفحة السكرتاريا المشتركة)
$success_message = $_SESSION['appointments_success'] ?? '';
unset($_SESSION['appointments_success']);
$error_message = '';
$doctor_id = $_SESSION['user_id'];

// تحديد التاريخ المحدد
$selected_date = $_GET['date'] ?? date('Y-m-d');
$today = date('Y-m-d');

// جلب المواعيد - استعلام مبسط ومحسن
try {
    // أولاً: جلب جميع المواعيد للتاريخ المحدد
    $stmt = $pdo->prepare("
        SELECT a.*, p.name as patient_name, p.phone, p.age, p.gender,
               p.medical_history, p.allergies, p.blood_type
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE DATE(a.appointment_date) = ? 
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$selected_date]);
    $appointments_basic = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ثانياً: لكل موعد، جلب معلومات العلاج المرتبط (إن وجد)
    $appointments = [];
    foreach ($appointments_basic as $appointment) {
        // البحث عن علاج مرتبط بهذا الموعد
        $treatment_stmt = $pdo->prepare("
            SELECT id as treatment_id, diagnosis, treatment_details, cost,
                   payment_status, next_appointment_date
            FROM treatments 
            WHERE appointment_id = ?
            LIMIT 1
        ");
        $treatment_stmt->execute([$appointment['id']]);
        $treatment = $treatment_stmt->fetch(PDO::FETCH_ASSOC);
        
        // دمج معلومات العلاج مع معلومات الموعد
        if ($treatment) {
            $appointment = array_merge($appointment, $treatment);
        } else {
            // إذا لم يوجد علاج، تعيين قيم فارغة
            $appointment['treatment_id'] = null;
            $appointment['diagnosis'] = null;
            $appointment['treatment_details'] = null;
            $appointment['cost'] = null;
            $appointment['payment_status'] = null;
            $appointment['next_appointment_date'] = null;
        }
        
        $appointments[] = $appointment;
    }
    
    // تجميع المواعيد حسب الوقت
    $time_slots = [];
    foreach ($appointments as $appointment) {
        $time_slot = substr($appointment['appointment_time'], 0, 5); // HH:MM
        if (!isset($time_slots[$time_slot])) {
            $time_slots[$time_slot] = [];
        }
        $time_slots[$time_slot][] = $appointment;
    }
    ksort($time_slots);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $appointments = [];
    $time_slots = [];
    
    // إضافة تسجيل مفصل للخطأ لأغراض التشخيص
    error_log("خطأ في جلب المواعيد: " . $e->getMessage());
    error_log("التاريخ المحدد: " . $selected_date);
    error_log("معرف الطبيب: " . $doctor_id);
}

// إحصائيات المواعيد - استعلام مبسط
try {
    // مواعيد اليوم
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM appointments 
        WHERE DATE(appointment_date) = ?
        GROUP BY status
    ");
    $stmt->execute([$selected_date]);
    $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $scheduled_count = $status_counts['scheduled'] ?? 0;
    $confirmed_count = $status_counts['confirmed'] ?? 0;
    $completed_count = $status_counts['completed'] ?? 0;
    $cancelled_count = $status_counts['cancelled'] ?? 0;
    
    // إحصائيات الأسبوع
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments 
        WHERE appointment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$weekStart, $weekEnd]);
    $week_appointments = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $scheduled_count = $confirmed_count = $completed_count = $cancelled_count = $week_appointments = 0;
}

// جلب الأيام القادمة مع عدد المواعيد
try {
    $stmt = $pdo->prepare("
        SELECT DATE(appointment_date) as date, COUNT(*) as count
        FROM appointments 
        WHERE appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(appointment_date)
        ORDER BY appointment_date
    ");
    $stmt->execute();
    $upcoming_days = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
} catch (PDOException $e) {
    $upcoming_days = [];
}

// إضافة تشخيص إضافي
if (empty($appointments)) {
    // فحص وجود مواعيد في قاعدة البيانات للتاريخ المحدد
    try {
        $debug_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM appointments WHERE DATE(appointment_date) = ?");
        $debug_stmt->execute([$selected_date]);
        $debug_count = $debug_stmt->fetchColumn();
        
        if ($debug_count == 0) {
            $error_message = "لا توجد مواعيد محجوزة في هذا التاريخ.";
        } else {
            $error_message = "توجد $debug_count موعد/مواعيد في قاعدة البيانات لكن حدث خطأ في جلبها.";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage();
    }
}

// Header configuration
$pageTitle = 'إدارة المواعيد';
$pageIcon = 'fas fa-calendar-check';
$pageSubtitle = 'جدولة ومتابعة مواعيد المرضى';
$currentPage = 'appointments';
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
        .appointment-card { transition: all 0.3s ease; border-left: 4px solid #e5e7eb; }
        .appointment-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .patient-info-link { text-decoration: none; }
        .patient-info-link:hover .patient-name { color: #2563eb !important; }
        .status-scheduled { border-left-color: #f59e0b; }
        .status-confirmed { border-left-color: #3b82f6; }
        .status-completed { border-left-color: #10b981; }
        .status-cancelled { border-left-color: #ef4444; }
        .medical-alert { background: linear-gradient(45deg, #fee2e2, #fef2f2); }
        .treatment-completed { background: linear-gradient(45deg, #d1fae5, #ecfdf5); }
        .debug-info { background: #fef3c7; border: 1px solid #f59e0b; padding: 10px; margin: 10px 0; border-radius: 5px; }
    </style>
</head>
<body class="bg-gray-50">


    <?php include 'includes/doctor_header.php'; ?>
 

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-1"></i>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Debug Info (يمكن إزالتها لاحقاً) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="debug-info">
                <strong>معلومات التشخيص:</strong><br>
                التاريخ المحدد: <?= $selected_date ?><br>
                معرف الطبيب: <?= $doctor_id ?><br>
                عدد المواعيد المجلبة: <?= count($appointments) ?><br>
                <?php if (!empty($appointments)): ?>
                    أول موعد: <?= print_r($appointments[0], true) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Date Navigation -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex items-center gap-2">
                        <label class="text-sm font-medium text-gray-700">التاريخ:</label>
                        <input type="date" 
                               id="appointment_date" 
                               value="<?= $selected_date ?>" 
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                               onchange="window.location.href = '?date=' + this.value">
                    </div>
                    
                    <div class="flex gap-2">
                        <a href="?date=<?= $today ?>" 
                           class="<?= $selected_date === $today ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            اليوم
                        </a>
                        <a href="?date=<?= date('Y-m-d', strtotime('+1 day')) ?>" 
                           class="<?= $selected_date === date('Y-m-d', strtotime('+1 day')) ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            غداً
                        </a>
                    </div>
                </div>
                
                <div class="flex gap-4">
                    <a href="../nurse/appointments.php?action=add&date=<?= urlencode($selected_date < $today ? $today : $selected_date) ?>"
                       class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-calendar-plus ml-2"></i>
                        حجز موعد
                    </a>
                    <a href="treatment_new.php"
                       class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة علاج
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 fade-in">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600"><?= $scheduled_count ?></div>
                <div class="text-sm text-yellow-600">مجدول</div>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-blue-600"><?= $confirmed_count ?></div>
                <div class="text-sm text-blue-600">مؤكد</div>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-green-600"><?= $completed_count ?></div>
                <div class="text-sm text-green-600">مكتمل</div>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-red-600"><?= $cancelled_count ?></div>
                <div class="text-sm text-red-600">ملغي</div>
            </div>
        </div>

        <!-- Appointments List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-calendar-day ml-2"></i>
                    مواعيد <?= $selected_date === $today ? 'اليوم' : date('d/m/Y', strtotime($selected_date)) ?>
                    <span class="text-sm text-gray-500 mr-2">(<?= count($appointments) ?> موعد)</span>
                </h3>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($appointments)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-calendar-day text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد مواعيد</h3>
                        <p class="text-gray-600">لا توجد مواعيد محجوزة في هذا التاريخ.</p>
                        <a href="?debug=1" class="mt-4 inline-block text-blue-600 hover:text-blue-800">عرض معلومات التشخيص</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $has_treatment = !empty($appointment['treatment_id']);
                        $card_class = $has_treatment ? 'treatment-completed' : '';
                        ?>
                        <div class="appointment-card p-6 hover:bg-blue-50 status-<?= $appointment['status'] ?> <?= $card_class ?> cursor-pointer transition-colors" onclick="window.location.href='patient_profile.php?id=<?= $appointment['patient_id'] ?>'">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-blue-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-<?= $has_treatment ? 'check-circle' : 'user' ?> text-<?= $has_treatment ? 'green' : 'blue' ?>-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="patient-name text-lg font-semibold text-gray-900 transition-colors">
                                                <?= htmlspecialchars($appointment['patient_name']) ?>
                                                <?php if ($has_treatment): ?>
                                                    <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full mr-2">تم العلاج</span>
                                                <?php endif; ?>
                                            </h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-phone ml-1"></i>
                                                <a href="tel:<?= $appointment['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4" onclick="event.stopPropagation();">
                                                    <?= $appointment['phone'] ?>
                                                </a>
                                                <i class="fas fa-user ml-4"></i>
                                                <span class="ml-1"><?= $appointment['age'] ?> سنة - <?= $appointment['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                                                <i class="fas fa-clock ml-4"></i>
                                                <span class="ml-1 font-semibold text-blue-600">
                                                    <?= date('h:i A', strtotime($appointment['appointment_time'])) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-tooth text-blue-500 ml-1"></i>
                                            <strong>نوع العلاج:</strong>
                                            <?= htmlspecialchars($appointment['treatment_type']) ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-hourglass-half text-purple-500 ml-1"></i>
                                            <strong>المدة المتوقعة:</strong>
                                            <?= $appointment['estimated_duration'] ?> دقيقة
                                        </div>
                                    </div>
                                    
                                    <!-- Treatment Information -->
                                    <?php if ($has_treatment): ?>
                                        <div class="mt-4 p-3 bg-green-50 border-r-4 border-green-300 rounded">
                                            <h5 class="font-semibold text-green-800 mb-2">
                                                <i class="fas fa-check-circle ml-1"></i>
                                                العلاج المقدم
                                            </h5>
                                            <p class="text-sm text-green-700">
                                                <strong>التشخيص:</strong> <?= htmlspecialchars($appointment['diagnosis']) ?>
                                            </p>
                                            <?php if ($appointment['cost']): ?>
                                                <div class="flex items-center justify-between mt-2">
                                                    <span class="text-sm text-green-700">
                                                        <strong>التكلفة:</strong> <?= number_format($appointment['cost'], 2) ?> ليرة سورية
                                                    </span>
                                                    <span class="text-xs px-2 py-1 rounded-full <?php
                                                        echo match($appointment['payment_status']) {
                                                            'paid' => 'bg-green-100 text-green-800',
                                                            'partial' => 'bg-yellow-100 text-yellow-800',
                                                            'unpaid' => 'bg-red-100 text-red-800',
                                                            default => 'bg-gray-100 text-gray-800'
                                                        };
                                                    ?>">
                                                        <?php
                                                        echo match($appointment['payment_status']) {
                                                            'paid' => 'مدفوع',
                                                            'partial' => 'جزئي',
                                                            'unpaid' => 'غير مدفوع',
                                                            default => 'غير محدد'
                                                        };
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Medical Alerts -->
                                    <?php if ($appointment['medical_history'] || $appointment['allergies']): ?>
                                        <div class="mt-4 p-3 medical-alert border-r-4 border-red-300 rounded">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-triangle text-red-600 mt-0.5 ml-2"></i>
                                                <div class="text-sm">
                                                    <h5 class="font-semibold text-red-800 mb-1">تنبيه طبي مهم</h5>
                                                    <?php if ($appointment['medical_history']): ?>
                                                        <div class="text-red-800 mb-1">
                                                            <strong>التاريخ المرضي:</strong>
                                                            <?= htmlspecialchars($appointment['medical_history']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($appointment['allergies']): ?>
                                                        <div class="text-red-800">
                                                            <strong>الحساسية:</strong>
                                                            <?= htmlspecialchars($appointment['allergies']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($appointment['notes']): ?>
                                        <div class="mt-4 p-3 bg-blue-50 border-r-4 border-blue-300 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-sticky-note ml-1"></i>
                                                <strong>ملاحظات:</strong>
                                                <?= htmlspecialchars($appointment['notes']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 mr-4">
                                    <!-- حالة الموعد -->
                                    <div class="text-center">
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
                                        <span class="<?= $statusClasses[$appointment['status']] ?? 'bg-gray-100 text-gray-800' ?> px-3 py-1 rounded-full text-sm font-medium">
                                            <?= $statusTexts[$appointment['status']] ?? $appointment['status'] ?>
                                        </span>
                                    </div>
                                    
                                    <!-- زر إضافة علاج جديد -->
                                    <?php if (!$has_treatment && $appointment['status'] !== 'cancelled'): ?>
                                        <a href="treatment_new.php?appointment_id=<?= $appointment['id'] ?>&patient_id=<?= $appointment['patient_id'] ?>" 
                                           class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center"
                                           onclick="event.stopPropagation();">
                                            <i class="fas fa-plus ml-1"></i>
                                            إضافة علاج
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- زر عرض العلاج -->
                                    <?php if ($has_treatment): ?>
                                        <a href="treatment_details.php?id=<?= $appointment['treatment_id'] ?>" 
                                           class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center"
                                           onclick="event.stopPropagation();">
                                            <i class="fas fa-eye ml-1"></i>
                                            عرض العلاج
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- زر الملف الشخصي -->
                                    <a href="patient_profile.php?id=<?= $appointment['patient_id'] ?>" 
                                       class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center"
                                       onclick="event.stopPropagation();">
                                        <i class="fas fa-user-circle ml-1"></i>
                                        الملف الشخصي
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>