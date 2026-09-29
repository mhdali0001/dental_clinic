<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin(['nurse', 'doctor']);

// Set page variables for header
$pageTitle = 'إدارة المواعيد';
$pageIcon = 'fas fa-calendar-check';
$pageSubtitle = 'جدولة ومتابعة مواعيد المرضى';
$currentPage = 'appointments';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// إضافة موعد جديد
if ($_POST && $action === 'add') {
    try {
        // التحقق من تعارض الأوقات
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments 
            WHERE appointment_date = ? AND appointment_time = ? AND status != 'cancelled'
        ");
        $stmt->execute([$_POST['appointment_date'], $_POST['appointment_time']]);
        
        if ($stmt->fetchColumn() > 0) {
            $error_message = "يوجد موعد آخر في نفس الوقت!";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO appointments (patient_id, appointment_date, appointment_time, treatment_type,
                                        estimated_duration, notes, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
            ");

            $result = $stmt->execute([
                $_POST['patient_id'],
                $_POST['appointment_date'],
                $_POST['appointment_time'],
                $_POST['treatment_type'],
                $_POST['estimated_duration'] ?? 30,
                $_POST['notes'] ?? '',
                $_SESSION['user_id'],
            ]);

            if ($result) {
                // الطبيب يعود إلى جدول مواعيده على تاريخ الموعد الجديد
                if ($_SESSION['user_role'] === 'doctor') {
                    $_SESSION['appointments_success'] = "تم حجز الموعد بنجاح";
                    header('Location: ../doctor/appointments.php?date=' . urlencode($_POST['appointment_date']));
                    exit;
                }

                $success_message = "تم حجز الموعد بنجاح";
                $action = ''; // إخفاء النموذج
            }
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في حجز الموعد: " . $e->getMessage();
    }
}

// تأكيد موعد
if ($action === 'confirm' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ?");
        $result = $stmt->execute([$_GET['id']]);
        if ($result) {
            $success_message = "تم تأكيد الموعد بنجاح";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في تأكيد الموعد: " . $e->getMessage();
    }
}

// إلغاء موعد
if ($action === 'cancel' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
        $result = $stmt->execute([$_GET['id']]);
        if ($result) {
            $success_message = "تم إلغاء الموعد بنجاح";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إلغاء الموعد: " . $e->getMessage();
    }
}

// تحديد التاريخ المحدد
$selected_date = $_GET['date'] ?? date('Y-m-d');
$today = date('Y-m-d');

// جلب المواعيد
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.name as patient_name, p.phone, p.age, p.gender,
               p.medical_history, p.allergies
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE DATE(a.appointment_date) = ? 
        ORDER BY a.appointment_time
    ");
    $stmt->execute([$selected_date]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
}

// إحصائيات المواعيد
try {
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
    
} catch (PDOException $e) {
    $scheduled_count = $confirmed_count = $completed_count = $cancelled_count = 0;
}

// جلب قائمة المرضى للنموذج
try {
    $patients_stmt = $pdo->query("SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name");
    $patients_list = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients_list = [];
}

// مريض محدد مسبقاً
$preselected_patient = $_GET['patient_id'] ?? null;

// إغلاق نموذج الحجز: الطبيب يعود إلى جدول مواعيده
$form_close_url = ($_SESSION['user_role'] === 'doctor' ? '../doctor/appointments.php' : '') . '?date=' . urlencode($selected_date);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المواعيد - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .time-slot { border-right: 4px solid #10b981; }
        .appointment-card { transition: all 0.3s ease; }
        .appointment-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .status-scheduled { border-left: 4px solid #f59e0b; }
        .status-confirmed { border-left: 4px solid #3b82f6; }
        .status-completed { border-left: 4px solid #10b981; }
        .status-cancelled { border-left: 4px solid #ef4444; }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/role_header.php'; ?>

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

        <!-- Date Filter and Actions -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex flex-col sm:flex-row gap-4">
                    <!-- Date Selection -->
                    <div class="flex items-center gap-2">
                        <label class="text-sm font-medium text-gray-700">التاريخ:</label>
                        <input type="date" 
                               id="appointment_date" 
                               value="<?= $selected_date ?>" 
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                               onchange="window.location.href = '?date=' + this.value">
                    </div>
                    
                    <!-- Quick Date Buttons -->
                    <div class="flex gap-2">
                        <a href="?date=<?= $today ?>" 
                           class="<?= $selected_date === $today ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            اليوم
                        </a>
                        <a href="?date=<?= date('Y-m-d', strtotime('+1 day')) ?>" 
                           class="<?= $selected_date === date('Y-m-d', strtotime('+1 day')) ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            غداً
                        </a>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-4">
                    <a href="?action=add&date=<?= $selected_date ?><?= $preselected_patient ? '&patient_id=' . $preselected_patient : '' ?>" 
                       class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-calendar-plus ml-2"></i>
                        موعد جديد
                    </a>
                    <button onclick="window.print()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-print"></i>
                    </button>
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

        <!-- Add Appointment Form -->
        <?php if ($action === 'add'): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-calendar-plus text-green-600 ml-2"></i>
                        حجز موعد جديد
                    </h3>
                    <a href="<?= $form_close_url ?>" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Patient Selection -->
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 font-semibold mb-2">المريض *</label>
                            <div class="relative">
                                <input type="text"
                                       id="patient_search"
                                       placeholder="ابحث عن المريض بالاسم أو رقم الهاتف..."
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       autocomplete="off">
                                <input type="hidden" name="patient_id" id="selected_patient_id" required>
                                <div id="patient_dropdown" class="absolute z-10 w-full bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                                    <?php foreach ($patients_list as $patient): ?>
                                        <div class="patient-option cursor-pointer p-3 hover:bg-gray-100 border-b border-gray-100"
                                             data-id="<?= $patient['id'] ?>"
                                             data-name="<?= htmlspecialchars($patient['name']) ?>"
                                             data-phone="<?= $patient['phone'] ?>">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($patient['name']) ?></div>
                                            <div class="text-sm text-gray-600"><?= $patient['phone'] ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div id="selected_patient_info" class="mt-2 p-2 bg-green-50 border border-green-200 rounded-lg hidden">
                                <div class="text-sm text-green-800">
                                    <i class="fas fa-user ml-1"></i>
                                    <span id="selected_patient_display"></span>
                                    <button type="button" onclick="clearPatientSelection()" class="mr-2 text-red-600 hover:text-red-800">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if (empty($patients_list)): ?>
                                <p class="text-red-600 text-sm mt-1">
                                    لا توجد مرضى مسجلين.
                                    <a href="patients.php?action=add" class="underline">إضافة مريض جديد</a>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Date -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">التاريخ *</label>
                            <input type="date" name="appointment_date" value="<?= $selected_date ?>" required min="<?= $today ?>"
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                        </div>
                        
                        <!-- Time -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">الوقت *</label>
                            <input type="time" name="appointment_time" value="<?= date('H:i') ?>" required 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                        </div>
                        
                        <!-- Treatment Type -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                            <select name="treatment_type" required 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="">اختر نوع العلاج</option>
                                <option value="فحص عام">فحص عام</option>
                                <option value="تنظيف أسنان">تنظيف أسنان</option>
                                <option value="حشو أسنان">حشو أسنان</option>
                                <option value="علاج جذور">علاج جذور</option>
                                <option value="خلع أسنان">خلع أسنان</option>
                                <option value="زراعة أسنان">زراعة أسنان</option>
                                <option value="تقويم أسنان">تقويم أسنان</option>
                                <option value="تبييض أسنان">تبييض أسنان</option>
                                <option value="أخرى">أخرى</option>
                            </select>
                        </div>
                        
                        <!-- Duration -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">المدة المتوقعة (دقيقة)</label>
                            <select name="estimated_duration" 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="15">15 دقيقة</option>
                                <option value="30" selected>30 دقيقة</option>
                                <option value="45">45 دقيقة</option>
                                <option value="60">60 دقيقة</option>
                                <option value="90">90 دقيقة</option>
                                <option value="120">120 دقيقة</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ملاحظات</label>
                        <textarea name="notes" rows="3"
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                  placeholder="أي ملاحظات خاصة بالموعد..."></textarea>
                    </div>
                    
                    <!-- Suggested Time Slots -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h4 class="font-semibold text-blue-800 mb-3">أوقات مقترحة متاحة:</h4>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $suggested_times = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];
                            foreach ($suggested_times as $time): 
                            ?>
                                <button type="button" onclick="selectTime('<?= $time ?>')" 
                                        class="text-xs bg-blue-100 hover:bg-blue-200 text-blue-700 px-3 py-1 rounded transition">
                                    <?= date('h:i A', strtotime($time)) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex space-x-4 space-x-reverse pt-4">
                        <button type="submit" 
                                class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-calendar-plus ml-2"></i>
                            حجز الموعد
                        </button>
                        <a href="<?= $form_close_url ?>" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Appointments List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-calendar-day ml-2"></i>
                    مواعيد <?= $selected_date === $today ? 'اليوم' : date('d/m/Y', strtotime($selected_date)) ?>
                </h3>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($appointments)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-calendar-day text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد مواعيد</h3>
                        <p class="text-gray-600 mb-6">لا توجد مواعيد محجوزة في هذا التاريخ.</p>
                        <a href="?action=add&date=<?= $selected_date ?>" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-calendar-plus ml-1"></i>
                            حجز أول موعد
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($appointments as $appointment): ?>
                        <div class="appointment-card p-6 hover:bg-gray-50 status-<?= $appointment['status'] ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-user text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                <?= htmlspecialchars($appointment['patient_name']) ?>
                                            </h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-phone ml-1"></i>
                                                <a href="tel:<?= $appointment['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4">
                                                    <?= $appointment['phone'] ?>
                                                </a>
                                                <i class="fas fa-clock ml-1"></i>
                                                <span class="ml-4 font-semibold text-blue-600">
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
                                            <i class="fas fa-clock text-purple-500 ml-1"></i>
                                            <strong>المدة:</strong>
                                            <?= $appointment['estimated_duration'] ?> دقيقة
                                        </div>
                                    </div>
                                    
                                    <?php if ($appointment['notes']): ?>
                                        <div class="mt-4 p-3 bg-blue-50 border-r-4 border-blue-300 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-sticky-note ml-1"></i>
                                                <strong>ملاحظات:</strong>
                                                <?= htmlspecialchars($appointment['notes']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($appointment['medical_history'] || $appointment['allergies']): ?>
                                        <div class="mt-4 p-3 bg-red-50 border-r-4 border-red-300 rounded">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-triangle text-red-600 mt-0.5 ml-2"></i>
                                                <div class="text-sm">
                                                    <?php if ($appointment['medical_history']): ?>
                                                        <div class="text-red-800">
                                                            <strong>التاريخ المرضي (أمراض مزمنة - الأدوية - الحساسية - عمليات جراحية سابقة):</strong>
                                                            <?= htmlspecialchars($appointment['medical_history']) ?>
                                                        </div>
                                                    <?php endif; ?>
 
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 mr-4">
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
                                    
                                    <?php if ($appointment['status'] === 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?= $appointment['id'] ?>&date=<?= $selected_date ?>" 
                                           class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                            <i class="fas fa-check ml-1"></i>
                                            تأكيد
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($appointment['status'] !== 'cancelled' && $appointment['status'] !== 'completed'): ?>
                                        <a href="?action=cancel&id=<?= $appointment['id'] ?>&date=<?= $selected_date ?>" 
                                           onclick="return confirm('هل أنت متأكد من إلغاء هذا الموعد؟')"
                                           class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                            <i class="fas fa-times ml-1"></i>
                                            إلغاء
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="patient_details.php?id=<?= $appointment['patient_id'] ?>" 
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                        <i class="fas fa-eye ml-1"></i>
                                        ملف المريض
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Function to select time
        function selectTime(time) {
            document.querySelector('input[name="appointment_time"]').value = time;
        }

        // Patient search functionality
        const patientSearch = document.getElementById('patient_search');
        const patientDropdown = document.getElementById('patient_dropdown');
        const selectedPatientId = document.getElementById('selected_patient_id');
        const selectedPatientInfo = document.getElementById('selected_patient_info');
        const selectedPatientDisplay = document.getElementById('selected_patient_display');
        const patientOptions = document.querySelectorAll('.patient-option');

        // Auto-focus on patient search if form is visible
        if (patientSearch) {
            patientSearch.focus();

            // Handle preselected patient
            <?php if ($preselected_patient): ?>
                const preselectedOption = document.querySelector(`[data-id="<?= $preselected_patient ?>"]`);
                if (preselectedOption) {
                    selectPatient(preselectedOption);
                }
            <?php endif; ?>
        }

        // Patient search input handler
        patientSearch?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let hasVisibleOptions = false;

            patientOptions.forEach(option => {
                const name = option.dataset.name.toLowerCase();
                const phone = option.dataset.phone.toLowerCase();

                if (name.includes(searchTerm) || phone.includes(searchTerm)) {
                    option.style.display = 'block';
                    hasVisibleOptions = true;
                } else {
                    option.style.display = 'none';
                }
            });

            // Show/hide dropdown
            if (searchTerm && hasVisibleOptions) {
                patientDropdown.classList.remove('hidden');
            } else {
                patientDropdown.classList.add('hidden');
            }
        });

        // Show dropdown on focus
        patientSearch?.addEventListener('focus', function() {
            if (this.value && !selectedPatientId.value) {
                patientDropdown.classList.remove('hidden');
            }
        });

        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#patient_search') && !e.target.closest('#patient_dropdown')) {
                patientDropdown?.classList.add('hidden');
            }
        });

        // Handle patient selection
        patientOptions.forEach(option => {
            option.addEventListener('click', function() {
                selectPatient(this);
            });
        });

        function selectPatient(option) {
            const id = option.dataset.id;
            const name = option.dataset.name;
            const phone = option.dataset.phone;

            selectedPatientId.value = id;
            patientSearch.value = '';
            selectedPatientDisplay.textContent = `${name} - ${phone}`;
            selectedPatientInfo.classList.remove('hidden');
            patientDropdown.classList.add('hidden');
        }

        function clearPatientSelection() {
            selectedPatientId.value = '';
            patientSearch.value = '';
            selectedPatientInfo.classList.add('hidden');
            patientSearch.focus();
        }

        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const patientId = selectedPatientId?.value || this.querySelector('input[name="patient_id"]')?.value;
            const appointmentDate = this.querySelector('input[name="appointment_date"]').value;
            const appointmentTime = this.querySelector('input[name="appointment_time"]').value;
            const treatmentType = this.querySelector('select[name="treatment_type"]').value;

            if (!patientId || !appointmentDate || !appointmentTime || !treatmentType) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                return;
            }

            // Check if appointment is in the past
            const appointmentDateTime = new Date(appointmentDate + 'T' + appointmentTime);
            const now = new Date();

            if (appointmentDateTime < now) {
                e.preventDefault();
                alert('لا يمكن حجز موعد في الماضي');
                return;
            }
        });
    </script>
</body>
</html>