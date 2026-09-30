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

    // الطبيب يحجز من صفحة مواعيده: يعود إليها مع رسالة الخطأ بدل البقاء هنا
    if ($error_message && $_SESSION['user_role'] === 'doctor' && isset($_GET['date'])) {
        $_SESSION['appointments_error'] = $error_message;
        header('Location: ../doctor/appointments.php?date=' . urlencode($_GET['date']));
        exit;
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

// ---------- بيانات صفحة حجز موعد (design/حجز موعد .jpg) ----------
if ($action === 'add') {
    $pageTitle = 'حجز موعد جديد';
    $pageIcon = 'fas fa-calendar-plus';
    $pageSubtitle = 'اختر المريض والإجراء، ثم اليوم والوقت المتاح';

    // الأوقات المعروضة في شبكة الأوقات (نفس الأوقات المقترحة سابقاً)
    $booking_slots = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];
    $booking_types = ['فحص عام', 'تنظيف أسنان', 'حشو أسنان', 'علاج جذور', 'خلع أسنان', 'زراعة أسنان', 'تقويم أسنان', 'تبييض أسنان', 'أخرى'];
    $booking_date = ($_POST['appointment_date'] ?? $selected_date) < $today ? $today : ($_POST['appointment_date'] ?? $selected_date);
    $list_url = ($_SESSION['user_role'] === 'doctor' ? '../doctor/appointments.php' : 'appointments.php');

    try {
        // عدد المواعيد لكل يوم (لتلوين أيام التقويم)
        $stmt = $pdo->prepare("
            SELECT appointment_date, COUNT(*) FROM appointments
            WHERE status != 'cancelled' AND appointment_date BETWEEN DATE_SUB(?, INTERVAL 40 DAY) AND DATE_ADD(?, INTERVAL 400 DAY)
            GROUP BY appointment_date
        ");
        $stmt->execute([$today, $today]);
        $booking_day_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->query("SELECT id, name, phone, age, medical_history, allergies FROM patients WHERE status = 'active' ORDER BY name");
        $booking_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT a.id, a.patient_id, a.appointment_date, a.appointment_time, a.treatment_type, p.name AS patient_name
            FROM appointments a JOIN patients p ON a.patient_id = p.id
            WHERE a.appointment_date = ? AND a.status IN ('scheduled', 'confirmed')
            ORDER BY a.appointment_time LIMIT 5
        ");
        $stmt->execute([$today]);
        $booking_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT a.id, a.appointment_date, a.appointment_time, a.treatment_type, a.status, a.created_at, p.name AS patient_name
            FROM appointments a JOIN patients p ON a.patient_id = p.id
            ORDER BY a.created_at DESC, a.id DESC LIMIT 5
        ");
        $booking_latest = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = $error_message ?: "خطأ في قاعدة البيانات: " . $e->getMessage();
        $booking_day_counts = [];
        $booking_patients = $booking_today = $booking_latest = [];
    }
}
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

        <?php if ($action === 'add'): ?>
        <!-- ================= حجز موعد جديد (design/حجز موعد .jpg) ================= -->
        <form method="POST" id="bookingForm" novalidate>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="appointment_date" id="bkDate" value="<?= htmlspecialchars($booking_date) ?>">
            <input type="hidden" name="appointment_time" id="bkTime" value="<?= htmlspecialchars($_POST['appointment_time'] ?? '') ?>">

            <div class="edsm-booking-grid">
                <!-- العمود الأيمن: النموذج + مواعيد اليوم -->
                <div class="edsm-booking-col">
                    <div class="edsm-card bk-form fade-in">
                        <div id="bkFormError" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 rounded-lg mb-3"></div>

                        <label for="bkPatientSearch" class="edsm-label">بحث عن مريض</label>
                        <select name="patient_id" id="bkPatient" class="edsm-field">
                            <option value="">اختر المريض...</option>
                            <?php $picked = $_POST['patient_id'] ?? $preselected_patient; ?>
                            <?php foreach ($booking_patients as $patient): ?>
                                <option value="<?= $patient['id'] ?>" data-name="<?= htmlspecialchars($patient['name']) ?>"
                                        data-phone="<?= htmlspecialchars($patient['phone'] ?? '') ?>" data-age="<?= htmlspecialchars($patient['age'] ?? '') ?>"
                                        data-history="<?= htmlspecialchars($patient['medical_history'] ?? '') ?>" data-allergies="<?= htmlspecialchars($patient['allergies'] ?? '') ?>"
                                        <?= (string)$picked === (string)$patient['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($patient['name']) ?> - <?= htmlspecialchars($patient['phone'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="edsm-label mt-4">اسم المريض</div>
                        <div id="bkPatientInfo" class="edsm-picked is-empty">لم يتم اختيار مريض بعد</div>

                        <label for="bkType" class="edsm-label mt-4">اختيار الإجراء</label>
                        <select name="treatment_type" id="bkType" class="edsm-field">
                            <option value="">اختر الإجراء</option>
                            <?php foreach ($booking_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= ($_POST['treatment_type'] ?? '') === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="bkDuration" class="edsm-label mt-4">المدة المتوقعة</label>
                        <select name="estimated_duration" id="bkDuration" class="edsm-field">
                            <?php foreach ([15, 30, 45, 60, 90, 120] as $minutes): ?>
                                <option value="<?= $minutes ?>" <?= (int)($_POST['estimated_duration'] ?? 30) === $minutes ? 'selected' : '' ?>><?= $minutes ?> دقيقة</option>
                            <?php endforeach; ?>
                        </select>

                        <label for="bkNotes" class="edsm-label mt-4">ملاحظات</label>
                        <textarea name="notes" id="bkNotes" rows="3" class="edsm-field" style="height: auto; padding: 10px 12px;" placeholder="ملاحظات (اختياري)"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>

                        <div class="edsm-booking-summary mt-4" id="bkSummary">
                            <i class="far fa-calendar-check"></i>
                            <span>اختر اليوم والوقت من التقويم وشبكة الأوقات</span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 mt-4">
                            <button type="submit" class="edsm-btn edsm-btn-navy edsm-btn-lg"><i class="fas fa-calendar-plus"></i> حجز الموعد</button>
                            <a href="<?= htmlspecialchars($form_close_url) ?>" class="edsm-btn edsm-btn-sky edsm-btn-lg">إلغاء</a>
                        </div>
                    </div>

                    <div class="edsm-card bk-today fade-in">
                        <div class="edsm-card-head" style="margin-bottom: 8px;">
                            <h3 class="edsm-card-title"><i class="far fa-calendar-alt"></i> المواعيد القادمة اليوم</h3>
                            <a href="<?= $list_url ?>?date=<?= $today ?>" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                        </div>
                        <?php if (empty($booking_today)): ?>
                            <p class="text-sm text-gray-500 py-3 text-center">لا توجد مواعيد متبقية اليوم</p>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($booking_today as $row): ?>
                                    <div class="edsm-upcoming">
                                        <span class="edsm-avatar-soft"><i class="far fa-calendar-alt"></i></span>
                                        <span class="flex-1 min-w-0">
                                            <span class="edsm-row-title block"><?= htmlspecialchars($row['patient_name']) ?></span>
                                            <span class="edsm-row-meta block"><span class="edsm-num"><?= date('d/m/Y', strtotime($row['appointment_date'])) ?> · <?= date('H:i', strtotime($row['appointment_time'])) ?></span> · <?= htmlspecialchars($row['treatment_type']) ?></span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <a href="patients.php?action=add" class="edsm-btn edsm-btn-pink edsm-btn-lg w-full mt-3"><i class="fas fa-user-plus"></i> إضافة مريض جديد</a>
                    </div>
                </div>

                <!-- العمود الأيسر: التقويم + شبكة الأوقات -->
                <div class="edsm-booking-col">
                    <div class="edsm-card bk-cal fade-in">
                        <div class="edsm-bcal-head">
                            <button type="button" class="edsm-bcal-nav" id="bkPrevMonth" aria-label="الشهر السابق"><i class="fas fa-chevron-right"></i></button>
                            <strong id="bkMonthTitle"></strong>
                            <button type="button" class="edsm-bcal-nav" id="bkNextMonth" aria-label="الشهر التالي"><i class="fas fa-chevron-left"></i></button>
                        </div>
                        <div class="edsm-bcal-grid" id="bkCalendar"></div>
                        <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-500">
                            <span><span class="edsm-legend is-selected"></span> اليوم المختار</span>
                            <span><span class="edsm-legend is-busy"></span> يوم فيه مواعيد</span>
                        </div>
                    </div>

                    <div class="edsm-card bk-slots fade-in">
                        <div class="edsm-card-head" style="margin-bottom: 12px;">
                            <h3 class="edsm-card-title"><i class="far fa-clock"></i> شبكة الأوقات المتاحة</h3>
                            <a href="#" id="bkDayListLink" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                        </div>
                        <div class="edsm-slot-grid" id="bkSlots"></div>
                        <div class="flex flex-wrap items-center gap-3 mt-4">
                            <label for="bkCustomTime" class="text-sm font-bold text-gray-700">أو وقت آخر:</label>
                            <input type="time" id="bkCustomTime" class="edsm-field" style="width: 150px; height: 38px;">
                            <span class="text-xs text-gray-500"><span class="edsm-legend is-free"></span> متوفر &nbsp; <span class="edsm-legend is-booked"></span> محجوز</span>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- أحدث المواعيد التي تم حجزها -->
        <div class="edsm-card mt-6 fade-in">
            <div class="edsm-card-head">
                <h3 class="edsm-card-title"><i class="fas fa-chart-bar"></i> أحدث المواعيد التي تم حجزها</h3>
                <a href="<?= $list_url ?>" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
            </div>
            <?php if (empty($booking_latest)): ?>
                <p class="text-sm text-gray-500 py-3">لا توجد مواعيد محجوزة بعد</p>
            <?php else: ?>
                <div class="edsm-table-wrap">
                    <table class="edsm-table">
                        <thead>
                            <tr><th>المريض</th><th>الإجراء</th><th>التاريخ</th><th>الوقت</th><th class="text-center">عرض</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($booking_latest as $row): ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="edsm-avatar-soft is-violet"><i class="fas fa-tooth"></i></span>
                                            <div>
                                                <div class="font-bold text-gray-800"><?= htmlspecialchars($row['patient_name']) ?></div>
                                                <?php if ($row['created_at']): ?>
                                                    <div class="text-xs text-gray-500">حُجز <span class="edsm-num"><?= date('d/m/Y H:i', strtotime($row['created_at'])) ?></span></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="font-bold text-gray-700"><?= htmlspecialchars($row['treatment_type']) ?></td>
                                    <td class="edsm-num"><?= date('d/m/Y', strtotime($row['appointment_date'])) ?></td>
                                    <td class="edsm-num font-bold"><?= date('H:i', strtotime($row['appointment_time'])) ?></td>
                                    <td class="text-center">
                                        <a href="<?= $list_url ?>?date=<?= $row['appointment_date'] ?>" class="edsm-btn"><i class="fas fa-eye"></i> عرض</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <?php if ($action === 'add'): ?>
    <script src="../assets/js/patient-search.js"></script>
    <script>
    (function () {
        const SLOTS = <?= json_encode($booking_slots) ?>;
        const DAY_COUNTS = <?= json_encode($booking_day_counts, JSON_FORCE_OBJECT) ?>;
        const LIST_URL = <?= json_encode($list_url) ?>;
        const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        const WEEK_HEAD = ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'];

        const pad = n => String(n).padStart(2, '0');
        const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
        const now = new Date();
        const TODAY = iso(now);
        const nowTime = pad(now.getHours()) + ':' + pad(now.getMinutes());

        const dateInput = document.getElementById('bkDate');
        const timeInput = document.getElementById('bkTime');
        const customTime = document.getElementById('bkCustomTime');
        const calendarEl = document.getElementById('bkCalendar');
        const slotsEl = document.getElementById('bkSlots');
        const summaryEl = document.getElementById('bkSummary');
        const errorEl = document.getElementById('bkFormError');

        if (!dateInput.value || dateInput.value < TODAY) dateInput.value = TODAY;
        let view = parse(dateInput.value);
        view.setDate(1);
        let booked = {}; // HH:MM -> patient name (للتاريخ المختار)

        // ---------- المريض ----------
        const patientSelect = document.getElementById('bkPatient');
        const infoEl = document.getElementById('bkPatientInfo');
        const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        function showPatient() {
            const o = patientSelect.value ? patientSelect.options[patientSelect.selectedIndex] : null;
            if (!o) { infoEl.className = 'edsm-picked is-empty'; infoEl.textContent = 'لم يتم اختيار مريض بعد'; return; }
            let html = '<strong>' + esc(o.dataset.name) + '</strong>';
            const meta = [o.dataset.phone, o.dataset.age ? o.dataset.age + ' سنة' : ''].filter(Boolean).join(' · ');
            if (meta) html += '<span class="edsm-num">' + esc(meta) + '</span>';
            if (o.dataset.history) html += '<em class="is-alert"><i class="fas fa-heartbeat"></i> ' + esc(o.dataset.history) + '</em>';
            if (o.dataset.allergies) html += '<em class="is-alert"><i class="fas fa-allergies"></i> حساسية: ' + esc(o.dataset.allergies) + '</em>';
            infoEl.className = 'edsm-picked';
            infoEl.innerHTML = html;
        }
        patientSelect.addEventListener('change', showPatient);
        const search = PatientSearch.attach(patientSelect, { inputId: 'bkPatientSearch', placeholder: 'بحث عن مريض بالاسم أو الهاتف', inputClass: 'edsm-field edsm-field-search' });
        showPatient();
        // على الشاشات الكبيرة فقط: على الجوال تفتح القائمة ولوحة المفاتيح فوق النموذج
        if (!patientSelect.value && window.matchMedia('(min-width: 1024px)').matches) search.input.focus();

        // ---------- التقويم ----------
        function renderCalendar() {
            document.getElementById('bkMonthTitle').textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
            const first = new Date(view);
            const offset = (first.getDay() + 1) % 7; // السبت أول الأسبوع
            first.setDate(first.getDate() - offset);
            let html = WEEK_HEAD.map(d => '<span class="edsm-bcal-dow">' + d + '</span>').join('');
            for (let i = 0; i < 42; i++) {
                const d = new Date(first); d.setDate(first.getDate() + i);
                if (i === 35 && d.getMonth() !== view.getMonth()) break;
                const key = iso(d);
                const cls = ['edsm-bcal-day'];
                if (d.getMonth() !== view.getMonth()) cls.push('is-other');
                if (key < TODAY) cls.push('is-past');
                if (key === TODAY) cls.push('is-today');
                if (DAY_COUNTS[key]) cls.push('is-busy');
                if (key === dateInput.value) cls.push('is-selected');
                const title = DAY_COUNTS[key] ? DAY_COUNTS[key] + ' موعد' : '';
                html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + key + '"' + (key < TODAY ? ' disabled' : '') + ' title="' + title + '"><span class="edsm-num">' + d.getDate() + '</span></button>';
            }
            calendarEl.innerHTML = html;
            // لا عودة لأشهر ماضية
            document.getElementById('bkPrevMonth').disabled = view.getFullYear() * 12 + view.getMonth() <= now.getFullYear() * 12 + now.getMonth();
        }
        calendarEl.addEventListener('click', e => {
            const btn = e.target.closest('.edsm-bcal-day');
            if (!btn || btn.disabled) return;
            selectDate(btn.dataset.date);
        });
        document.getElementById('bkPrevMonth').addEventListener('click', () => { view.setMonth(view.getMonth() - 1); renderCalendar(); });
        document.getElementById('bkNextMonth').addEventListener('click', () => { view.setMonth(view.getMonth() + 1); renderCalendar(); });

        function selectDate(key) {
            if (dateInput.value !== key) timeInput.value = '';
            dateInput.value = key;
            const d = parse(key);
            if (d.getMonth() !== view.getMonth() || d.getFullYear() !== view.getFullYear()) { view = new Date(d.getFullYear(), d.getMonth(), 1); }
            renderCalendar();
            loadSlots();
        }

        // ---------- شبكة الأوقات ----------
        async function loadSlots() {
            const day = dateInput.value;
            document.getElementById('bkDayListLink').href = LIST_URL + '?date=' + day;
            slotsEl.innerHTML = '<div class="edsm-slot-loading"><i class="fas fa-spinner fa-spin"></i> جاري تحميل الأوقات...</div>';
            booked = {};
            try {
                const res = await fetch('../api/appointments.php?date=' + encodeURIComponent(day));
                const data = await res.json();
                if (dateInput.value !== day) return; // تغيّر اليوم أثناء التحميل
                (data.appointments || []).forEach(a => {
                    if (a.status !== 'cancelled') booked[String(a.appointment_time).slice(0, 5)] = a.patient_name;
                });
            } catch (err) { /* تبقى الشبكة دون حالات الحجز */ }
            renderSlots();
        }

        function renderSlots() {
            const day = dateInput.value;
            const times = Array.from(new Set([...SLOTS, ...Object.keys(booked)])).sort();
            slotsEl.innerHTML = times.map(t => {
                const isBooked = !!booked[t];
                const isPast = day === TODAY && t <= nowTime;
                const cls = ['edsm-slot', isBooked ? 'is-booked' : (isPast ? 'is-past' : 'is-free')];
                if (timeInput.value === t && !isBooked) cls.push('is-selected');
                const label = isBooked ? 'محجوز' : (isPast ? 'انتهى' : 'متوفر');
                const title = isBooked ? ' title="' + esc(booked[t]) + '"' : '';
                return '<button type="button" class="' + cls.join(' ') + '" data-time="' + t + '"' + (isBooked || isPast ? ' disabled' : '') + title + '>' +
                       '<span class="edsm-num">' + t + '</span><small>' + label + '</small></button>';
            }).join('');
            customTime.value = timeInput.value && !SLOTS.includes(timeInput.value) ? timeInput.value : '';
            updateSummary();
        }
        slotsEl.addEventListener('click', e => {
            const btn = e.target.closest('.edsm-slot');
            if (!btn || btn.disabled) return;
            timeInput.value = btn.dataset.time;
            hideError();
            renderSlots();
        });
        customTime.addEventListener('change', () => {
            const t = customTime.value;
            if (booked[t]) {
                timeInput.value = '';
                renderSlots();
                showError('هذا الوقت محجوز لمريض آخر، اختر وقتاً آخر');
                return;
            }
            timeInput.value = t;
            hideError();
            renderSlots();
        });

        function updateSummary() {
            const d = parse(dateInput.value);
            const dateText = DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
            summaryEl.classList.toggle('is-ready', !!timeInput.value);
            summaryEl.querySelector('span').innerHTML = timeInput.value
                ? 'الموعد: <strong>' + dateText + '</strong> الساعة <strong class="edsm-num">' + timeInput.value + '</strong>'
                : 'اليوم: <strong>' + dateText + '</strong> — اختر الوقت من شبكة الأوقات';
        }

        // ---------- التحقق قبل الإرسال ----------
        function showError(msg) { errorEl.textContent = msg; errorEl.classList.remove('hidden'); errorEl.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        function hideError() { errorEl.classList.add('hidden'); }
        document.getElementById('bookingForm').addEventListener('submit', e => {
            let msg = '';
            if (!patientSelect.value) msg = 'يرجى اختيار المريض';
            else if (!document.getElementById('bkType').value) msg = 'يرجى اختيار الإجراء';
            else if (!timeInput.value) msg = 'يرجى اختيار وقت الموعد من شبكة الأوقات';
            else if (booked[timeInput.value]) msg = 'هذا الوقت محجوز لمريض آخر، اختر وقتاً آخر';
            else if (dateInput.value === TODAY && timeInput.value <= nowTime) msg = 'لا يمكن حجز موعد في وقت مضى';
            if (msg) { e.preventDefault(); showError(msg); }
        });

        renderCalendar();
        loadSlots();
    })();
    </script>
    <?php endif; ?>
</body>
</html>