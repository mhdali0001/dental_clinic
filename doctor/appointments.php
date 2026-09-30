<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// رسائل قادمة من صفحة الحجز (صفحة السكرتاريا المشتركة) أو من إجراءات هذه الصفحة
$success_message = $_SESSION['appointments_success'] ?? '';
$error_message = $_SESSION['appointments_error'] ?? '';
unset($_SESSION['appointments_success'], $_SESSION['appointments_error']);

$today = date('Y-m-d');

// ---------- تأكيد / إلغاء موعد ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['confirm', 'cancel'], true)) {
    $newStatus = $_POST['action'] === 'confirm' ? 'confirmed' : 'cancelled';
    try {
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND status NOT IN ('completed', 'cancelled')");
        $stmt->execute([$newStatus, (int)($_POST['appointment_id'] ?? 0)]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['appointments_success'] = $newStatus === 'confirmed' ? 'تم تأكيد الموعد' : 'تم إلغاء الموعد';
        } else {
            $_SESSION['appointments_error'] = 'لا يمكن تعديل حالة هذا الموعد';
        }
    } catch (PDOException $e) {
        $_SESSION['appointments_error'] = 'خطأ في تحديث الموعد: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ---------- المعاملات ----------
$selected_date = $_GET['date'] ?? $today;
if (($_GET['filter'] ?? '') === 'today') {
    $selected_date = $today;
}
$dt = DateTime::createFromFormat('!Y-m-d', $selected_date);
if (!$dt || $dt->format('Y-m-d') !== $selected_date) {
    $selected_date = $today;
    $dt = new DateTime($today);
}

$view = ($_GET['view'] ?? 'list') === 'calendar' ? 'calendar' : 'list';
$range = in_array($_GET['range'] ?? 'day', ['day', 'week', 'month', 'upcoming', 'all'], true) ? ($_GET['range'] ?? 'day') : 'day';
$search = trim($_GET['q'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$status_filter = $_GET['status'] ?? '';

$arabicMonths = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
$statusMeta = [
    'scheduled' => ['مجدول', 'scheduled', 'far fa-clock'],
    'confirmed' => ['مؤكد', 'confirmed', 'fas fa-check-circle'],
    'completed' => ['مكتمل', 'completed', 'fas fa-check-double'],
    'cancelled' => ['ملغي', 'cancelled', 'fas fa-times-circle'],
    'no_show'   => ['لم يحضر', 'noshow', 'fas fa-user-slash'],
];
$rangeLabels = ['day' => 'اليوم المحدد', 'week' => 'أسبوع التاريخ المحدد', 'month' => 'شهر التاريخ المحدد', 'upcoming' => 'المواعيد القادمة', 'all' => 'كل المواعيد'];

// رابط يحافظ على المعاملات الحالية مع تغيير بعضها
function appointmentsUrl(array $changes = []) {
    $params = array_merge($_GET, $changes);
    unset($params['filter']);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'appointments.php' . ($params ? '?' . http_build_query($params) : '');
}

// الشهر المعروض في التقويم = شهر التاريخ المحدد
$monthStart = (clone $dt)->modify('first day of this month');
$monthEnd = (clone $dt)->modify('last day of this month');

try {
    // ---------- قائمة المواعيد حسب الفلاتر ----------
    $where = [];
    $params = [];
    switch ($range) {
        case 'day':
            $where[] = 'a.appointment_date = ?';
            $params[] = $selected_date;
            break;
        case 'week':
            $weekStart = (clone $dt)->modify('saturday this week');
            if ($weekStart > $dt) { $weekStart->modify('-7 days'); }
            $where[] = 'a.appointment_date BETWEEN ? AND ?';
            $params[] = $weekStart->format('Y-m-d');
            $params[] = (clone $weekStart)->modify('+6 days')->format('Y-m-d');
            break;
        case 'month':
            $where[] = 'a.appointment_date BETWEEN ? AND ?';
            $params[] = $monthStart->format('Y-m-d');
            $params[] = $monthEnd->format('Y-m-d');
            break;
        case 'upcoming':
            $where[] = 'a.appointment_date >= ?';
            $params[] = $today;
            break;
    }
    if ($search !== '') {
        $where[] = '(p.name LIKE ? OR p.phone LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($type_filter !== '') {
        $where[] = 'a.treatment_type = ?';
        $params[] = $type_filter;
    }
    if (isset($statusMeta[$status_filter])) {
        $where[] = 'a.status = ?';
        $params[] = $status_filter;
    }
    $order = $range === 'all' ? 'a.appointment_date DESC, a.appointment_time DESC' : 'a.appointment_date, a.appointment_time';

    $stmt = $pdo->prepare("
        SELECT a.*, p.name AS patient_name, p.phone, p.medical_history, p.allergies,
               (SELECT t.id FROM treatments t WHERE t.appointment_id = a.id ORDER BY t.id LIMIT 1) AS treatment_id
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY $order
        LIMIT 300
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $status_counts = array_count_values(array_column($appointments, 'status'));

    // ---------- أيام الشهر التي فيها مواعيد (للتقويم الصغير) ----------
    $stmt = $pdo->prepare("
        SELECT appointment_date, COUNT(*) FROM appointments
        WHERE appointment_date BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY appointment_date
    ");
    $stmt->execute([$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);
    $days_with_appointments = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ---------- مواعيد الشهر (عرض التقويم) ----------
    $month_appointments = [];
    if ($view === 'calendar') {
        $stmt = $pdo->prepare("
            SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.treatment_type, p.name AS patient_name
            FROM appointments a JOIN patients p ON a.patient_id = p.id
            WHERE a.appointment_date BETWEEN ? AND ?
            ORDER BY a.appointment_date, a.appointment_time
        ");
        $stmt->execute([$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month_appointments[$row['appointment_date']][] = $row;
        }
    }

    // ---------- مواعيد اليوم القادمة (الشريط الجانبي) ----------
    $stmt = $pdo->prepare("
        SELECT a.id, a.patient_id, a.appointment_date, a.appointment_time, a.treatment_type, a.status, p.name AS patient_name,
               (SELECT t.id FROM treatments t WHERE t.appointment_id = a.id ORDER BY t.id LIMIT 1) AS treatment_id
        FROM appointments a JOIN patients p ON a.patient_id = p.id
        WHERE a.appointment_date = ? AND a.status IN ('scheduled', 'confirmed')
        ORDER BY a.appointment_time
        LIMIT 6
    ");
    $stmt->execute([$today]);
    $today_upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- قوائم النماذج ----------
    $treatment_types = $pdo->query("SELECT DISTINCT treatment_type FROM appointments WHERE treatment_type <> '' ORDER BY treatment_type")->fetchAll(PDO::FETCH_COLUMN);
    $patients_list = $pdo->query("SELECT id, name, phone, age FROM patients WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
    $appointments = $month_appointments = $today_upcoming = $patients_list = $treatment_types = [];
    $days_with_appointments = $status_counts = [];
}

// أنواع الإجراءات في نموذج الحجز (نفس قائمة صفحة الحجز) + أي نوع مستخدم سابقاً
$booking_types = array_values(array_unique(array_merge(
    ['فحص عام', 'تنظيف أسنان', 'حشو أسنان', 'علاج جذور', 'خلع أسنان', 'زراعة أسنان', 'تقويم أسنان', 'تبييض أسنان'],
    $treatment_types,
    ['أخرى']
)));

// شبكة التقويم (الأسبوع يبدأ السبت)
function monthGrid(DateTime $monthStart) {
    $first = clone $monthStart;
    $offset = ((int)$first->format('w') + 1) % 7; // السبت = 0
    $first->modify("-$offset days");
    $cells = [];
    for ($i = 0; $i < 42; $i++) {
        $cells[] = (clone $first)->modify("+$i days");
    }
    // حذف الأسبوع الأخير إن كان كله من الشهر التالي
    if ($cells[35]->format('m') !== $monthStart->format('m')) {
        $cells = array_slice($cells, 0, 35);
    }
    return $cells;
}
$gridCells = monthGrid($monthStart);
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m-d');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m-d');
$weekDaysShort = ['س', 'ح', 'ن', 'ث', 'ر', 'خ', 'ج'];
$weekDaysLong = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

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
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-5 fade-in">
                <i class="fas fa-check-circle ml-1"></i> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-5 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <!-- ================= الشريط الجانبي ================= -->
            <aside class="space-y-6 lg:col-span-1 order-2 lg:order-none">
                <!-- تقويم الشهر -->
                <div class="edsm-card edsm-mini-cal fade-in">
                    <div class="edsm-mini-cal-head">
                        <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $prevMonth])) ?>" class="edsm-mini-cal-nav" title="الشهر السابق"><i class="fas fa-chevron-right"></i></a>
                        <strong><?= $arabicMonths[(int)$monthStart->format('n')] . ' ' . $monthStart->format('Y') ?></strong>
                        <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $nextMonth])) ?>" class="edsm-mini-cal-nav" title="الشهر التالي"><i class="fas fa-chevron-left"></i></a>
                    </div>
                    <div class="edsm-mini-cal-grid">
                        <?php foreach ($weekDaysShort as $i => $d): ?>
                            <span class="edsm-mini-cal-dow" title="<?= $weekDaysLong[$i] ?>"><?= $d ?></span>
                        <?php endforeach; ?>
                        <?php foreach ($gridCells as $cell): ?>
                            <?php
                            $cellDate = $cell->format('Y-m-d');
                            $classes = ['edsm-mini-cal-day'];
                            if ($cell->format('m') !== $monthStart->format('m')) $classes[] = 'is-other';
                            if ($cellDate === $today) $classes[] = 'is-today';
                            if ($cellDate === $selected_date) $classes[] = 'is-selected';
                            if (!empty($days_with_appointments[$cellDate])) $classes[] = 'has-appointments';
                            ?>
                            <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $cellDate, 'range' => $view === 'list' ? 'day' : ($_GET['range'] ?? '')])) ?>"
                               class="<?= implode(' ', $classes) ?>"
                               title="<?= !empty($days_with_appointments[$cellDate]) ? $days_with_appointments[$cellDate] . ' موعد' : '' ?>">
                                <span class="edsm-num"><?= (int)$cell->format('j') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex items-center justify-between mt-3 text-xs text-gray-500">
                        <span><span class="edsm-mini-cal-dot"></span> يوم فيه مواعيد</span>
                        <?php if ($selected_date !== $today): ?>
                            <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $today])) ?>" class="edsm-link text-xs">اليوم</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- إضافة موعد جديد (يُحفظ عبر صفحة الحجز المشتركة ثم يعود إلى هنا) -->
                <div class="edsm-card fade-in">
                    <div class="edsm-card-head" style="margin-bottom: 14px;">
                        <h3 class="edsm-card-title"><i class="fas fa-calendar-plus"></i> إضافة موعد جديد</h3>
                    </div>
                    <form method="POST" action="../nurse/appointments.php?action=add&amp;date=<?= urlencode($selected_date) ?>" class="space-y-3" id="quickBookingForm">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="estimated_duration" value="30">
                        <div>
                            <label for="quickPatientSearch" class="edsm-label">المريض</label>
                            <select name="patient_id" id="quickPatientSelect" required class="edsm-field">
                                <option value="">اختر المريض...</option>
                                <?php foreach ($patients_list as $patient): ?>
                                    <option value="<?= $patient['id'] ?>" data-name="<?= htmlspecialchars($patient['name']) ?>"
                                            data-phone="<?= htmlspecialchars($patient['phone'] ?? '') ?>" data-age="<?= htmlspecialchars($patient['age'] ?? '') ?>">
                                        <?= htmlspecialchars($patient['name']) ?> - <?= htmlspecialchars($patient['phone'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="quickType" class="edsm-label">الإجراء</label>
                            <select name="treatment_type" id="quickType" required class="edsm-field">
                                <option value="">اختر الإجراء</option>
                                <?php foreach ($booking_types as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="quickDate" class="edsm-label">التاريخ</label>
                                <input type="date" name="appointment_date" id="quickDate" required class="edsm-field"
                                       value="<?= $selected_date >= $today ? $selected_date : $today ?>" min="<?= $today ?>">
                            </div>
                            <div>
                                <label for="quickTime" class="edsm-label">الوقت</label>
                                <input type="time" name="appointment_time" id="quickTime" required class="edsm-field" value="09:00">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 pt-1">
                            <button type="submit" class="edsm-btn edsm-btn-lg"><i class="fas fa-check"></i> حجز الموعد</button>
                            <button type="reset" class="edsm-btn edsm-btn-ghost edsm-btn-lg">إلغاء</button>
                        </div>
                    </form>
                </div>

                <!-- مواعيد اليوم القادمة -->
                <div class="edsm-card fade-in">
                    <div class="edsm-card-head" style="margin-bottom: 10px;">
                        <h3 class="edsm-card-title"><i class="far fa-calendar-alt"></i> المواعيد القادمة اليوم</h3>
                    </div>
                    <?php if (empty($today_upcoming)): ?>
                        <div class="edsm-empty" style="padding: 16px 6px;">
                            <div class="edsm-empty-icon"><i class="far fa-calendar-check"></i></div>
                            <p style="margin-bottom: 0;">لا توجد مواعيد متبقية اليوم</p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($today_upcoming as $i => $up): ?>
                                <?php
                                $upUrl = $up['treatment_id'] ? 'treatment_details.php?id=' . $up['treatment_id']
                                       : 'treatment_new.php?appointment_id=' . $up['id'] . '&patient_id=' . $up['patient_id'];
                                $upIcons = ['fas fa-tooth', 'far fa-user', 'far fa-calendar-alt'];
                                ?>
                                <a href="<?= htmlspecialchars($upUrl) ?>" class="edsm-upcoming">
                                    <span class="edsm-avatar-soft <?= $i % 3 === 1 ? 'is-teal' : '' ?>"><i class="<?= $upIcons[$i % 3] ?>"></i></span>
                                    <span class="flex-1 min-w-0">
                                        <span class="edsm-row-title block"><?= htmlspecialchars($up['patient_name']) ?></span>
                                        <span class="edsm-row-meta block">
                                            <span class="edsm-num"><?= date('d/m/Y', strtotime($up['appointment_date'])) ?> · <?= date('H:i', strtotime($up['appointment_time'])) ?></span>
                                            · <?= htmlspecialchars($up['treatment_type']) ?>
                                        </span>
                                    </span>
                                    <i class="fas fa-chevron-left text-gray-400 text-xs"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ================= المحتوى الرئيسي ================= -->
            <section class="edsm-card lg:col-span-2 order-1 lg:order-none fade-in" style="padding: 0;">
                <nav class="edsm-tabs">
                    <a href="<?= htmlspecialchars(appointmentsUrl(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'active' : '' ?>">
                        <i class="fas fa-list-ul"></i> قائمة المواعيد
                    </a>
                    <a href="<?= htmlspecialchars(appointmentsUrl(['view' => 'calendar'])) ?>" class="<?= $view === 'calendar' ? 'active' : '' ?>">
                        <i class="far fa-calendar-alt"></i> تقويم المواعيد
                    </a>
                    <a href="../nurse/appointments.php?action=add&amp;date=<?= urlencode($selected_date >= $today ? $selected_date : $today) ?>" class="edsm-tabs-action" title="حجز موعد">
                        <i class="fas fa-plus"></i> <span class="edsm-tabs-action-text">حجز موعد</span>
                    </a>
                </nav>

                <div style="padding: 20px 22px 22px;">
                <?php if ($view === 'list'): ?>
                    <form method="GET" class="space-y-4" id="appointmentsFilter">
                        <input type="hidden" name="view" value="list">
                        <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                        <div class="relative">
                            <i class="fas fa-search absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="البحث عن مريض بالاسم أو رقم الهاتف"
                                   class="edsm-field" style="padding-right: 42px; height: 48px;">
                        </div>
                        <div>
                            <div class="text-sm font-bold text-gray-700 mb-2">تصفية النتائج</div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
                                <div>
                                    <label class="edsm-label" for="fRange">الفترة</label>
                                    <select name="range" id="fRange" class="edsm-field">
                                        <?php foreach ($rangeLabels as $key => $label): ?>
                                            <option value="<?= $key ?>" <?= $range === $key ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="edsm-label" for="fType">الإجراء</label>
                                    <select name="type" id="fType" class="edsm-field">
                                        <option value="">كل الإجراءات</option>
                                        <?php foreach ($treatment_types as $type): ?>
                                            <option value="<?= htmlspecialchars($type) ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="edsm-label" for="fStatus">الحالة</label>
                                    <select name="status" id="fStatus" class="edsm-field">
                                        <option value="">كل الحالات</option>
                                        <?php foreach ($statusMeta as $key => [$label]): ?>
                                            <option value="<?= $key ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="edsm-btn edsm-btn-lg flex-1"><i class="fas fa-filter"></i> تصفية</button>
                                    <?php if ($search !== '' || $type_filter !== '' || $status_filter !== '' || $range !== 'day'): ?>
                                        <a href="<?= htmlspecialchars(appointmentsUrl(['q' => null, 'type' => null, 'status' => null, 'range' => null])) ?>" class="edsm-btn edsm-btn-ghost edsm-btn-lg" title="مسح الفلاتر"><i class="fas fa-times"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- ملخص النتائج -->
                    <div class="flex flex-wrap items-center justify-between gap-2 mt-5 mb-3">
                        <div class="font-bold text-gray-800">
                            <?php if ($range === 'day'): ?>
                                مواعيد <?= $selected_date === $today ? 'اليوم' : '<span class="edsm-num">' . date('d/m/Y', strtotime($selected_date)) . '</span>' ?>
                            <?php else: ?>
                                <?= $rangeLabels[$range] ?>
                            <?php endif; ?>
                            <span class="text-sm text-gray-500 font-normal">(<span class="edsm-num"><?= count($appointments) ?></span> موعد)</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($statusMeta as $key => [$label, $cls]): ?>
                                <?php if (!empty($status_counts[$key])): ?>
                                    <span class="edsm-status edsm-status-<?= $cls ?>"><?= $label ?> <span class="edsm-num"><?= $status_counts[$key] ?></span></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (empty($appointments)): ?>
                        <div class="edsm-empty">
                            <div class="edsm-empty-icon"><i class="far fa-calendar-alt"></i></div>
                            <p>لا توجد مواعيد مطابقة</p>
                            <a href="../nurse/appointments.php?action=add&amp;date=<?= urlencode($selected_date >= $today ? $selected_date : $today) ?>" class="edsm-btn edsm-btn-lg"><i class="fas fa-plus"></i> حجز موعد جديد</a>
                        </div>
                    <?php else: ?>
                        <div class="edsm-table-wrap">
                            <table class="edsm-table">
                                <thead>
                                    <tr>
                                        <th>تاريخ الموعد</th>
                                        <th>الوقت</th>
                                        <th>المريض</th>
                                        <th>الإجراء</th>
                                        <th>الحالة</th>
                                        <th class="text-center">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                        <?php
                                        [$sLabel, $sCls, $sIcon] = $statusMeta[$appointment['status']] ?? [$appointment['status'], 'noshow', 'far fa-circle'];
                                        $isOpen = in_array($appointment['status'], ['scheduled', 'confirmed'], true);
                                        $hasAlert = !empty($appointment['medical_history']) || !empty($appointment['allergies']);
                                        ?>
                                        <tr>
                                            <td class="edsm-num font-bold"><?= date('d/m/Y', strtotime($appointment['appointment_date'])) ?></td>
                                            <td class="edsm-num"><?= date('H:i', strtotime($appointment['appointment_time'])) ?></td>
                                            <td>
                                                <a href="patient_profile.php?id=<?= $appointment['patient_id'] ?>" class="font-bold text-gray-800 hover:text-blue-600">
                                                    <?= htmlspecialchars($appointment['patient_name']) ?>
                                                </a>
                                                <?php if ($hasAlert): ?>
                                                    <i class="fas fa-exclamation-triangle text-red-500 text-xs mr-1"
                                                       title="<?= htmlspecialchars(trim(($appointment['medical_history'] ? 'التاريخ المرضي: ' . $appointment['medical_history'] . "\n" : '') . ($appointment['allergies'] ? 'الحساسية: ' . $appointment['allergies'] : ''))) ?>"></i>
                                                <?php endif; ?>
                                                <?php if (!empty($appointment['notes'])): ?>
                                                    <i class="far fa-sticky-note text-gray-400 text-xs mr-1" title="<?= htmlspecialchars($appointment['notes']) ?>"></i>
                                                <?php endif; ?>
                                                <div class="text-xs text-gray-500 edsm-num" dir="ltr" style="text-align: right;"><?= htmlspecialchars($appointment['phone'] ?? '') ?></div>
                                            </td>
                                            <td class="font-bold text-gray-700"><?= htmlspecialchars($appointment['treatment_type']) ?></td>
                                            <td>
                                                <span class="edsm-status edsm-status-<?= $sCls ?>"><i class="<?= $sIcon ?>"></i> <?= $sLabel ?></span>
                                                <?php if ($appointment['treatment_id']): ?>
                                                    <div class="text-xs text-green-600 mt-1"><i class="fas fa-tooth"></i> تم العلاج</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="edsm-actions-cell">
                                                    <?php if ($appointment['treatment_id']): ?>
                                                        <a href="treatment_details.php?id=<?= $appointment['treatment_id'] ?>" class="edsm-icon-action is-blue" title="عرض العلاج"><i class="fas fa-eye"></i></a>
                                                    <?php elseif ($appointment['status'] !== 'cancelled'): ?>
                                                        <a href="treatment_new.php?appointment_id=<?= $appointment['id'] ?>&amp;patient_id=<?= $appointment['patient_id'] ?>" class="edsm-icon-action is-blue" title="بدء العلاج"><i class="fas fa-tooth"></i></a>
                                                    <?php endif; ?>
                                                    <?php if ($appointment['status'] === 'scheduled'): ?>
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="action" value="confirm">
                                                            <input type="hidden" name="appointment_id" value="<?= $appointment['id'] ?>">
                                                            <button type="submit" class="edsm-icon-action is-green" title="تأكيد الموعد"><i class="fas fa-check"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($isOpen && !$appointment['treatment_id']): ?>
                                                        <form method="POST" class="inline" onsubmit="return confirm('هل تريد إلغاء هذا الموعد؟');">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="appointment_id" value="<?= $appointment['id'] ?>">
                                                            <button type="submit" class="edsm-icon-action is-red" title="إلغاء الموعد"><i class="far fa-trash-alt"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <a href="patient_profile.php?id=<?= $appointment['patient_id'] ?>" class="edsm-icon-action" title="ملف المريض"><i class="far fa-user"></i></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php else: /* ---------- عرض التقويم ---------- */ ?>
                    <div class="flex items-center justify-between mb-4">
                        <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $prevMonth])) ?>" class="edsm-btn edsm-btn-ghost"><i class="fas fa-chevron-right"></i> الشهر السابق</a>
                        <h3 class="edsm-card-title" style="font-size: 20px;"><?= $arabicMonths[(int)$monthStart->format('n')] . ' ' . $monthStart->format('Y') ?></h3>
                        <a href="<?= htmlspecialchars(appointmentsUrl(['date' => $nextMonth])) ?>" class="edsm-btn edsm-btn-ghost">الشهر التالي <i class="fas fa-chevron-left"></i></a>
                    </div>
                    <div class="edsm-month">
                        <?php foreach ($weekDaysLong as $d): ?>
                            <div class="edsm-month-dow"><?= $d ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($gridCells as $cell): ?>
                            <?php
                            $cellDate = $cell->format('Y-m-d');
                            $dayItems = $month_appointments[$cellDate] ?? [];
                            $cellClasses = ['edsm-month-cell'];
                            if ($cell->format('m') !== $monthStart->format('m')) $cellClasses[] = 'is-other';
                            if ($cellDate === $today) $cellClasses[] = 'is-today';
                            if ($cellDate === $selected_date) $cellClasses[] = 'is-selected';
                            ?>
                            <div class="<?= implode(' ', $cellClasses) ?>">
                                <a href="<?= htmlspecialchars(appointmentsUrl(['view' => 'list', 'range' => 'day', 'date' => $cellDate])) ?>" class="edsm-month-day edsm-num"><?= (int)$cell->format('j') ?></a>
                                <?php foreach (array_slice($dayItems, 0, 3) as $item): ?>
                                    <?php [$iLabel, $iCls] = $statusMeta[$item['status']] ?? ['', 'noshow']; ?>
                                    <a href="<?= htmlspecialchars(appointmentsUrl(['view' => 'list', 'range' => 'day', 'date' => $cellDate])) ?>"
                                       class="edsm-month-chip edsm-status-<?= $iCls ?>" title="<?= htmlspecialchars($item['patient_name'] . ' - ' . $item['treatment_type'] . ' (' . $iLabel . ')') ?>">
                                        <span class="edsm-num"><?= date('H:i', strtotime($item['appointment_time'])) ?></span> <?= htmlspecialchars($item['patient_name']) ?>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (count($dayItems) > 3): ?>
                                    <a href="<?= htmlspecialchars(appointmentsUrl(['view' => 'list', 'range' => 'day', 'date' => $cellDate])) ?>" class="edsm-month-more">+<?= count($dayItems) - 3 ?> أخرى</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <script src="../assets/js/patient-search.js"></script>
    <script>
        // بحث عن المريض في نموذج الحجز السريع
        PatientSearch.attach(document.getElementById('quickPatientSelect'), {
            inputId: 'quickPatientSearch',
            placeholder: 'ابحث باسم المريض أو الهاتف...',
            inputClass: 'edsm-field edsm-field-search'
        });

        // تغيير الفلاتر يطبّقها مباشرة
        document.querySelectorAll('#appointmentsFilter select').forEach(select => {
            select.addEventListener('change', () => select.form.submit());
        });
    </script>
</body>
</html>
