<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/followup_helpers.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$doctor_id = $_SESSION['user_id'];
$patient_id = (int)($_GET['id'] ?? 0);

if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

// تاريخ اليوم من MySQL كما في لوحة التحكم (توقيت PHP قد يختلف عنه)
$today = $pdo->query("SELECT CURDATE()")->fetchColumn();

$note_types = ['general' => 'عامة', 'medical' => 'طبية', 'behavioral' => 'سلوكية', 'financial' => 'مالية', 'reminder' => 'تذكير'];
$payment_methods = ['cash' => 'نقداً', 'card' => 'بطاقة ائتمانية', 'bank_transfer' => 'تحويل بنكي', 'insurance' => 'تأمين'];
$appointment_statuses = [
    'scheduled' => ['مجدول', 'scheduled'],
    'confirmed' => ['مؤكد', 'confirmed'],
    'completed' => ['مكتمل', 'completed'],
    'cancelled' => ['ملغي', 'cancelled'],
    'no_show'   => ['لم يحضر', 'noshow'],
];

// ---------- إضافة ملاحظة ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_note') {
    $content = trim($_POST['note_content'] ?? '');
    $type = isset($note_types[$_POST['note_type'] ?? '']) ? $_POST['note_type'] : 'general';
    if ($content === '') {
        $_SESSION['patient_profile_error'] = 'يرجى كتابة نص الملاحظة';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO patient_notes (patient_id, note_content, note_type, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$patient_id, $content, $type, $doctor_id]);
            $_SESSION['patient_profile_success'] = 'تمت إضافة الملاحظة';
        } catch (PDOException $e) {
            $_SESSION['patient_profile_error'] = 'خطأ في إضافة الملاحظة: ' . $e->getMessage();
        }
    }
    header('Location: patient_profile.php?id=' . $patient_id . '#notes');
    exit;
}

// رسائل قادمة من صفحات أخرى (تعديل البيانات، المتابعات) أو من إضافة ملاحظة
$profile_success_message = $_SESSION['patient_profile_success'] ?? '';
$profile_error_message = $_SESSION['patient_profile_error'] ?? '';
unset($_SESSION['patient_profile_success'], $_SESSION['patient_profile_error']);

function money($value) {
    $value = (float)$value;
    return number_format($value, fmod($value, 1) ? 2 : 0) . ' ل.س';
}

function ageLabel($years) {
    $years = (int)$years;
    if ($years === 1) return 'عام واحد';
    if ($years === 2) return 'عامان';
    if ($years >= 3 && $years <= 10) return $years . ' أعوام';
    return $years . ' عاماً';
}

// [الحالة، المراحل المنجزة، عدد المراحل]؛ "مكتمل" = أُنهي العلاج ولا توجد مرحلة غير منجزة
function treatmentState(array $t) {
    $stages = json_decode((string)($t['treatment_stages'] ?? ''), true);
    $stages = is_array($stages) ? $stages : [];
    $done = count(array_filter($stages, fn($s) => !empty($s['completed'])));
    if ($t['status'] === 'cancelled') {
        return ['cancelled', $done, count($stages)];
    }
    $pending = count(array_filter($stages, fn($s) => is_array($s) && array_key_exists('completed', $s) && !$s['completed']));
    $finished = ($t['status'] === 'completed' || $t['completed_at'] || trim((string)$t['completion_notes']) !== '') && $pending === 0;
    return [$finished ? 'completed' : 'in_progress', $done, count($stages)];
}

// ---------- بيانات المريض ----------
try {
    $stmt = $pdo->prepare("
        SELECT p.*,
               (SELECT MAX(t.treatment_date) FROM treatments t WHERE t.patient_id = p.id) AS last_treatment_date,
               (SELECT COALESCE(SUM(t.cost), 0) FROM treatments t WHERE t.patient_id = p.id) AS total_cost,
               -- كل دفعات المريض، بما فيها الدفعات العامة غير المرتبطة بعلاج
               (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.patient_id = p.id) AS total_paid
        FROM patients p
        WHERE p.id = ?
    ");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profile_error_message = 'خطأ في جلب بيانات المريض: ' . $e->getMessage();
    $patient = null;
}

if ($patient) {
    $patient['remaining_balance'] = $patient['total_cost'] - $patient['total_paid'];
    $last_visit = max((string)$patient['last_treatment_date'], (string)$patient['last_visit_date']) ?: null;
    $age = $patient['date_of_birth'] ? (new DateTime($patient['date_of_birth']))->diff(new DateTime($today))->y : $patient['age'];
    $file_number = str_pad($patient['id'], 6, '0', STR_PAD_LEFT);

    try {
        // العلاجات
        $stmt = $pdo->prepare("
            SELECT t.*, u.full_name AS doctor_name, COALESCE(tt.name_ar, t.treatment_type) AS type_name,
                   (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.treatment_id = t.id) AS paid
            FROM treatments t
            LEFT JOIN users u ON t.doctor_id = u.id
            LEFT JOIN treatment_types tt ON tt.code = t.treatment_type
            WHERE t.patient_id = ?
            ORDER BY t.treatment_date DESC, t.created_at DESC
        ");
        $stmt->execute([$patient_id]);
        $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // أسماء الأسنان (ترقيم FDI)
        $tooth_names = $pdo->query("SELECT tooth_number, name_ar FROM teeth_info")->fetchAll(PDO::FETCH_KEY_PAIR);

        // المدفوعات
        $stmt = $pdo->prepare("
            SELECT pay.*, COALESCE(tt.name_ar, t.treatment_type) AS type_name, u.full_name AS created_by_name
            FROM payments pay
            LEFT JOIN treatments t ON pay.treatment_id = t.id
            LEFT JOIN treatment_types tt ON tt.code = t.treatment_type
            LEFT JOIN users u ON pay.created_by = u.id
            WHERE pay.patient_id = ?
            ORDER BY pay.payment_date DESC, pay.id DESC
        ");
        $stmt->execute([$patient_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // الملاحظات
        $stmt = $pdo->prepare("
            SELECT n.*, u.full_name AS created_by_name, u.role AS created_by_role
            FROM patient_notes n
            LEFT JOIN users u ON n.created_by = u.id
            WHERE n.patient_id = ?
            ORDER BY n.created_at DESC, n.id DESC
        ");
        $stmt->execute([$patient_id]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // المواعيد
        $stmt = $pdo->prepare("
            SELECT id, appointment_date, appointment_time, treatment_type, status, notes, estimated_duration
            FROM appointments
            WHERE patient_id = ?
            ORDER BY appointment_date DESC, appointment_time DESC
        ");
        $stmt->execute([$patient_id]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // المتابعات: النشطة أولاً بالأقرب موعداً، ثم المنتهية بالأحدث
        $stmt = $pdo->prepare("
            SELECT f.*, COALESCE(tt.name_ar, t.treatment_type) AS treatment_type,
                   u.full_name AS doctor_name, u.role AS doctor_role
            FROM follow_ups f
            LEFT JOIN treatments t ON f.treatment_id = t.id
            LEFT JOIN treatment_types tt ON tt.code = t.treatment_type
            LEFT JOIN users u ON f.created_by = u.id
            WHERE f.patient_id = ?
            ORDER BY (f.status IN ('pending', 'rescheduled')) DESC,
                     CASE WHEN f.status IN ('pending', 'rescheduled') THEN f.follow_up_date END ASC,
                     f.follow_up_date DESC, f.id DESC
        ");
        $stmt->execute([$patient_id]);
        $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $profile_error_message = $profile_error_message ?: 'خطأ في جلب بيانات الملف: ' . $e->getMessage();
        $treatments = $payments = $notes = $appointments = $followups = [];
        $tooth_names = [];
    }

    // حالة كل علاج + خريطة الأسنان: سن => العلاجات
    $teeth = [];
    $in_progress_count = 0;
    foreach ($treatments as &$t) {
        [$t['state'], $t['stages_done'], $t['stages_total']] = treatmentState($t);
        $in_progress_count += $t['state'] === 'in_progress' ? 1 : 0;
        $numbers = json_decode((string)$t['teeth_numbers'], true);
        $t['teeth'] = is_array($numbers) ? array_values(array_unique(array_map('strval', $numbers))) : [];
        sort($t['teeth'], SORT_NUMERIC);
        if ($t['state'] !== 'cancelled') {
            foreach ($t['teeth'] as $n) {
                $teeth[$n][] = $t;
            }
        }
    }
    unset($t);
    ksort($teeth, SORT_NUMERIC);
    // حالة السن في المخطط: قيد العلاج إن وُجد علاج غير مكتمل عليه
    $tooth_state = [];
    foreach ($teeth as $n => $list) {
        $tooth_state[$n] = in_array('in_progress', array_column($list, 'state'), true) ? 'progress' : 'treated';
    }

    // القادم للمريض: المواعيد المحجوزة والمتابعات النشطة (المتأخرة أولاً)
    $upcoming = [];
    foreach ($appointments as $a) {
        if (in_array($a['status'], ['scheduled', 'confirmed'], true) && $a['appointment_date'] >= $today) {
            $upcoming[] = ['type' => 'appointment', 'date' => $a['appointment_date'], 'time' => substr($a['appointment_time'], 0, 5),
                           'title' => 'موعد: ' . $a['treatment_type'], 'overdue' => false];
        }
    }
    foreach ($followups as $f) {
        $state = followupState($f, $today);
        if ($state === 'active' || $state === 'overdue') {
            [, $label] = followupKind($f);
            $upcoming[] = ['type' => 'followup', 'date' => $f['follow_up_date'], 'time' => '', 'title' => $label,
                           'overdue' => $state === 'overdue', 'id' => (int)$f['id']];
        }
    }
    usort($upcoming, fn($a, $b) => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);
    $upcoming_total = count($upcoming);
    $upcoming = array_slice($upcoming, 0, 4);

    $medical_notes = array_slice(array_values(array_filter($notes, fn($n) => $n['note_type'] === 'medical')), 0, 2);
    $treatments_with_cost = array_values(array_filter($treatments, fn($t) => (float)$t['cost'] > 0));
}

// ---------- مخطط الأسنان (قوسان على شكل بيضاوي كما في التصميم) ----------
// الفك العلوي أعلى البيضاوي والسفلي أسفله؛ يمين المريض على يسار الناظر (مثل مخطط إضافة العلاج)
function toothChartSvg(array $tooth_state, array $teeth, array $tooth_names) {
    $cx = 105; $cy = 165; $rx = 80; $ry = 138; $gap = 0.13;
    // عرض السن على القوس حسب نوعه (الأضراس أعرض)
    $weight = fn($n) => [1 => 0.82, 2 => 0.82, 3 => 0.95, 4 => 1.0, 5 => 1.0, 6 => 1.22, 7 => 1.18, 8 => 1.1][$n % 10];
    $arches = [
        'upper' => [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28],
        'lower' => [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38],
    ];
    $svg = '';
    foreach ($arches as $arch => $order) {
        $sign = $arch === 'upper' ? -1 : 1;
        // نقاط على نصف البيضاوي من اليسار إلى اليمين، مع طول القوس التراكمي
        $points = [];
        $length = 0;
        $prev = null;
        for ($i = 0; $i <= 400; $i++) {
            $a = M_PI - $gap - (M_PI - 2 * $gap) * $i / 400;
            $p = [$cx + $rx * cos($a), $cy + $sign * $ry * sin($a)];
            if ($prev) {
                $length += hypot($p[0] - $prev[0], $p[1] - $prev[1]);
            }
            $points[] = [$p[0], $p[1], $length];
            $prev = $p;
        }
        $total_weight = array_sum(array_map($weight, $order));
        $offset = 0;
        foreach ($order as $n) {
            $w = $weight($n);
            $target = ($offset + $w / 2) / $total_weight * $length;
            $offset += $w;
            foreach ($points as $pt) {
                if ($pt[2] >= $target) break;
            }
            $r = $w / $total_weight * $length / 2 - 1.2;
            $state = $tooth_state[(string)$n] ?? 'none';
            $title = 'السن ' . $n . (isset($tooth_names[$n]) ? ' — ' . $tooth_names[$n] : '');
            foreach ($teeth[(string)$n] ?? [] as $t) {
                $title .= "\n" . $t['type_name'] . ' (' . ($t['state'] === 'completed' ? 'مكتمل' : 'قيد العلاج') . ') ' . date('d/m/Y', strtotime($t['treatment_date']));
            }
            $svg .= sprintf(
                '<g class="edsm-tooth is-%s"%s><title>%s</title><circle cx="%.1f" cy="%.1f" r="%.1f"/><text x="%.1f" y="%.1f">%d</text></g>',
                $state,
                $state === 'none' ? '' : ' data-tooth="' . $n . '" tabindex="0" role="button"',
                htmlspecialchars($title),
                $pt[0], $pt[1], $r, $pt[0], $pt[1] + 3, $n
            );
        }
    }
    $svg .= '<text class="edsm-tooth-side" x="' . ($cx - $rx + 26) . '" y="' . ($cy + 4) . '">يمين</text>';
    $svg .= '<text class="edsm-tooth-side" x="' . ($cx + $rx - 26) . '" y="' . ($cy + 4) . '">يسار</text>';
    $svg .= '<text class="edsm-tooth-jaw" x="' . $cx . '" y="' . ($cy - 58) . '">الفك العلوي</text>';
    $svg .= '<text class="edsm-tooth-jaw" x="' . $cx . '" y="' . ($cy + 64) . '">الفك السفلي</text>';
    return '<svg class="edsm-tooth-chart" viewBox="0 0 210 330" role="img" aria-label="مخطط الأسنان">' . $svg . '</svg>';
}

function patientAvatarSvg($gender) {
    $hair = $gender === 'female'
        ? '<path d="M24 54c0-20 10-35 24-35s24 15 24 35v30H24z" fill="#3A2A22"/>'
        : '';
    $front = $gender === 'female'
        ? '<path d="M32 46c1-13 8-20 16-20 10 0 17 8 17 19-8-1-15-4-19-10-3 6-8 9-14 11z" fill="#3A2A22"/>'
        : '<path d="M32 45c-1-12 6-20 16-20s17 7 16 19c-3-5-8-8-16-8s-13 4-16 9z" fill="#2F2A26"/>';
    return '<svg viewBox="0 0 96 96" aria-hidden="true"><defs><clipPath id="pfAvatarClip"><circle cx="48" cy="48" r="48"/></clipPath></defs>'
         . '<g clip-path="url(#pfAvatarClip)"><rect width="96" height="96" fill="#D9EAFB"/>' . $hair
         . '<path d="M12 96c3-15 17-23 36-23s33 8 36 23z" fill="#3E86CF"/>'
         . '<rect x="42" y="58" width="12" height="14" rx="4" fill="#EDBB9A"/>'
         . '<ellipse cx="48" cy="46" rx="15" ry="17.5" fill="#F6D2B8"/>' . $front . '</g></svg>';
}

// Header configuration
$pageTitle = $patient ? 'ملف المريض: ' . $patient['name'] : 'ملف المريض';
$pageTitleHtml = $patient
    ? 'ملف المريض: ' . htmlspecialchars($patient['name']) . ' <span class="edsm-title-meta">(رقم الملف: <span class="edsm-num">' . $file_number . '</span>)</span>'
    : null;
$pageIcon = 'fas fa-user-circle';
$pageSubtitle = $patient
    ? 'مسجل منذ ' . date('d/m/Y', strtotime($patient['registration_date'])) . ($last_visit ? ' · آخر زيارة ' . date('d/m/Y', strtotime($last_visit)) : '')
    : 'تفاصيل المريض والتاريخ المرضي';
$pageHeadActions = $patient
    ? '<button type="button" onclick="window.print()" class="edsm-btn edsm-btn-ghost edsm-btn-lg no-print"><i class="fas fa-print"></i> طباعة الملف</button>'
    : '';
$breadcrumbs = null;
$currentPage = 'patients';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            .edsm-topbar-wrap, .edsm-nav, .no-print, .edsm-page-head-actions, .edsm-tabs { display: none !important; }
            .edsm-tab-panel[hidden] { display: block !important; margin-top: 18px; }
            .edsm-card { box-shadow: none !important; break-inside: avoid; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <?php if (!$patient): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="edsm-card edsm-empty">
                <div class="edsm-empty-icon"><i class="fas fa-user-times"></i></div>
                <p><?= htmlspecialchars($profile_error_message ?: 'لم يتم العثور على المريض المطلوب.') ?></p>
                <a href="patients.php" class="edsm-btn edsm-btn-lg">العودة لقائمة المرضى</a>
            </div>
        </div>
    <?php else: ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <?php if ($profile_success_message): ?>
            <div class="no-print bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-5">
                <i class="fas fa-check-circle ml-1"></i> <?= htmlspecialchars($profile_success_message) ?>
            </div>
        <?php endif; ?>
        <?php if ($profile_error_message): ?>
            <div class="no-print bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-5">
                <i class="fas fa-exclamation-triangle ml-1"></i> <?= htmlspecialchars($profile_error_message) ?>
            </div>
        <?php endif; ?>

        <div class="edsm-profile-grid">
            <!-- ================= العمود الأيمن: بيانات المريض + الإجراءات + القادم ================= -->
            <div class="edsm-profile-side">
                <section class="edsm-card edsm-pinfo pf-info fade-in">
                    <div class="edsm-pinfo-avatar"><?= patientAvatarSvg($patient['gender']) ?></div>
                    <h2 class="edsm-pinfo-name"><?= htmlspecialchars($patient['name']) ?></h2>
                    <?php if ($patient['status'] !== 'active'): ?>
                        <span class="edsm-tag edsm-tag-danger">ملف غير نشط</span>
                    <?php endif; ?>

                    <dl class="edsm-pinfo-list">
                        <div><dt>رقم الملف:</dt><dd class="edsm-num"><?= $file_number ?></dd></div>
                        <?php if ($age !== null && $age !== ''): ?>
                            <div><dt>العمر:</dt><dd><?= ageLabel($age) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($patient['date_of_birth']): ?>
                            <div><dt>تاريخ الولادة:</dt><dd class="edsm-num"><?= date('d/m/Y', strtotime($patient['date_of_birth'])) ?></dd></div>
                        <?php endif; ?>
                        <div><dt>الجنس:</dt><dd><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></dd></div>
                        <div><dt>فصيلة الدم:</dt><dd class="edsm-num"><?= $patient['blood_type'] ? htmlspecialchars($patient['blood_type']) : '—' ?></dd></div>
                    </dl>

                    <ul class="edsm-pinfo-contact">
                        <li><i class="fas fa-phone-alt"></i><a href="tel:<?= htmlspecialchars($patient['phone']) ?>" class="edsm-num" dir="ltr"><?= htmlspecialchars($patient['phone']) ?></a></li>
                        <?php if ($patient['email']): ?>
                            <li><i class="fas fa-envelope"></i><a href="mailto:<?= htmlspecialchars($patient['email']) ?>" class="edsm-num break-all"><?= htmlspecialchars($patient['email']) ?></a></li>
                        <?php endif; ?>
                        <?php if ($patient['address']): ?>
                            <li><i class="fas fa-map-marker-alt"></i><span><?= htmlspecialchars($patient['address']) ?></span></li>
                        <?php endif; ?>
                        <?php if ($patient['emergency_contact']): ?>
                            <li class="is-alert"><i class="fas fa-phone-volume"></i><span>طوارئ: <a href="tel:<?= htmlspecialchars($patient['emergency_contact']) ?>" class="edsm-num" dir="ltr"><?= htmlspecialchars($patient['emergency_contact']) ?></a></span></li>
                        <?php endif; ?>
                    </ul>
                </section>

                <div class="edsm-card pf-actions no-print fade-in">
                    <div class="grid grid-cols-2 gap-3">
                        <a href="patient_edit.php?id=<?= $patient_id ?>" class="edsm-btn edsm-btn-navy edsm-btn-lg"><i class="fas fa-user-edit"></i> تعديل البيانات</a>
                        <a href="../nurse/appointments.php?action=add&amp;patient_id=<?= $patient_id ?>" class="edsm-btn edsm-btn-sky edsm-btn-lg"><i class="fas fa-calendar-plus"></i> إضافة موعد</a>
                    </div>
                </div>

                <div class="edsm-card pf-next fade-in">
                    <div class="edsm-card-head" style="margin-bottom: 8px;">
                        <h3 class="edsm-card-title"><i class="far fa-calendar-alt"></i> المواعيد والمتابعات القادمة</h3>
                        <?php if ($upcoming_total > count($upcoming)): ?>
                            <a href="#appointments" data-open-tab="appointments" class="edsm-link text-sm no-print">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($upcoming)): ?>
                        <p class="text-sm text-gray-500 py-3 text-center">لا توجد مواعيد أو متابعات قادمة</p>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($upcoming as $i => $item): ?>
                                <?php $tag = $item['type'] === 'followup' ? 'a' : 'div'; ?>
                                <<?= $tag ?> class="edsm-upcoming"<?= $item['type'] === 'followup' ? ' href="follow_ups.php?edit=' . $item['id'] . '&amp;back=patient" title="تحديث المتابعة"' : '' ?>>
                                    <span class="edsm-avatar-soft <?= $item['overdue'] ? 'is-red' : ($item['type'] === 'followup' ? 'is-violet' : '') ?>">
                                        <i class="<?= $item['type'] === 'followup' ? 'fas fa-user-clock' : 'far fa-calendar-alt' ?>"></i>
                                    </span>
                                    <span class="flex-1 min-w-0">
                                        <span class="edsm-row-title block truncate"><?= htmlspecialchars($item['title']) ?></span>
                                        <span class="edsm-row-meta block">
                                            <span class="edsm-num"><?= date('d/m/Y', strtotime($item['date'])) ?><?= $item['time'] ? ' · ' . $item['time'] : '' ?></span>
                                            <?php if ($item['overdue']): ?>
                                                <span class="edsm-tag edsm-tag-danger">متأخرة</span>
                                            <?php elseif ($item['date'] === $today): ?>
                                                <span class="edsm-tag edsm-tag-success">اليوم</span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span class="edsm-fu-index edsm-num"><?= $i + 1 ?></span>
                                </<?= $tag ?>>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="follow_ups.php?action=add&amp;patient_id=<?= $patient_id ?>&amp;back=patient" class="edsm-btn edsm-btn-pink edsm-btn-lg w-full mt-3 no-print"><i class="fas fa-user-clock"></i> إضافة متابعة</a>
                </div>
            </div>

            <!-- ================= العمود الأيسر: السجل + المخطط + التاريخ الطبي ================= -->
            <div class="edsm-profile-main">
                <section class="edsm-card pf-record fade-in" id="record">
                    <div class="edsm-card-head" style="margin-bottom: 6px;">
                        <h3 class="edsm-card-title"><i class="fas fa-sliders-h"></i> سجل العلاج والحساسية</h3>
                        <a href="treatment_new.php?patient_id=<?= $patient_id ?>" class="edsm-link text-sm no-print"><i class="fas fa-plus text-xs"></i> علاج جديد</a>
                    </div>
                    <?php if ($patient['allergies']): ?>
                        <div class="edsm-allergy"><i class="fas fa-exclamation-triangle"></i><span>حساسية: <?= htmlspecialchars($patient['allergies']) ?></span></div>
                    <?php endif; ?>

                    <nav class="edsm-tabs edsm-ptabs" role="tablist" aria-label="أقسام ملف المريض">
                        <a href="#treatments" data-tab="treatments" role="tab" class="active">سجل العلاج
                            <?php if ($in_progress_count): ?><span class="edsm-tab-count is-warn edsm-num" title="علاجات قيد التنفيذ"><?= $in_progress_count ?></span><?php endif; ?>
                        </a>
                        <a href="#teeth" data-tab="teeth" role="tab">المخطط السني</a>
                        <a href="#notes" data-tab="notes" role="tab">الملاحظات
                            <?php if ($notes): ?><span class="edsm-tab-count edsm-num"><?= count($notes) ?></span><?php endif; ?>
                        </a>
                        <a href="#appointments" data-tab="appointments" role="tab">المواعيد</a>
                        <a href="#accounts" data-tab="accounts" role="tab">الحسابات
                            <?php if ($patient['remaining_balance'] > 0): ?><span class="edsm-tab-count is-due" title="يوجد مبلغ مستحق"><i class="fas fa-exclamation"></i></span><?php endif; ?>
                        </a>
                    </nav>

                    <!-- سجل العلاج -->
                    <div class="edsm-tab-panel" data-panel="treatments" role="tabpanel">
                        <?php if (empty($treatments)): ?>
                            <div class="edsm-empty">
                                <div class="edsm-empty-icon"><i class="fas fa-tooth"></i></div>
                                <p>لم يتم تسجيل أي علاج لهذا المريض بعد</p>
                                <a href="treatment_new.php?patient_id=<?= $patient_id ?>" class="edsm-btn edsm-btn-lg no-print"><i class="fas fa-plus"></i> إضافة أول علاج</a>
                            </div>
                        <?php else: ?>
                            <div class="edsm-table-wrap">
                                <table class="edsm-table">
                                    <thead>
                                        <tr><th>التاريخ</th><th>الإجراء</th><th>السن</th><th>الطبيب المعالج</th><th>الحالة</th><th class="text-center no-print">الإجراءات</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($treatments as $t): ?>
                                            <tr>
                                                <td class="edsm-num whitespace-nowrap"><?= date('d/m/Y', strtotime($t['treatment_date'])) ?></td>
                                                <td class="font-bold text-gray-800"><?= htmlspecialchars($t['type_name']) ?></td>
                                                <td class="edsm-num whitespace-nowrap" <?= count($t['teeth']) > 3 ? 'title="' . implode('، ', $t['teeth']) . '"' : '' ?>>
                                                    <?php if (!$t['teeth']): ?>-
                                                    <?php elseif (count($t['teeth']) > 3): ?><?= implode('، ', array_slice($t['teeth'], 0, 2)) ?> <span class="text-gray-500">+<?= count($t['teeth']) - 2 ?></span>
                                                    <?php else: ?><?= implode('، ', $t['teeth']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="whitespace-nowrap"><?= htmlspecialchars(doctorLabel($t['doctor_name'])) ?></td>
                                                <td class="whitespace-nowrap">
                                                    <?php if ($t['state'] === 'completed'): ?>
                                                        <span class="edsm-state is-treated">مكتملة</span>
                                                    <?php elseif ($t['state'] === 'cancelled'): ?>
                                                        <span class="edsm-state is-cancelled">ملغاة</span>
                                                    <?php else: ?>
                                                        <span class="edsm-state is-progress">قيد العلاج</span>
                                                        <?php if ($t['stages_total']): ?>
                                                            <div class="text-xs text-gray-500 mt-1"><span class="edsm-num"><?= $t['stages_done'] ?>/<?= $t['stages_total'] ?></span> مراحل</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="no-print">
                                                    <div class="edsm-actions-cell">
                                                        <a href="treatments.php?action=edit&amp;id=<?= $t['id'] ?>" class="edsm-icon-action is-blue" title="تعديل العلاج"><i class="far fa-pen-to-square"></i></a>
                                                        <?php if ($t['state'] === 'in_progress'): ?>
                                                            <a href="treatment_new.php?complete_id=<?= $t['id'] ?>" class="edsm-icon-action is-green" title="متابعة العلاج"><i class="fas fa-play"></i></a>
                                                        <?php endif; ?>
                                                        <a href="treatment_details.php?id=<?= $t['id'] ?>" class="edsm-icon-action is-blue" title="عرض التفاصيل"><i class="far fa-eye"></i></a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- المخطط السني: تفاصيل كل سن معالج -->
                    <div class="edsm-tab-panel" data-panel="teeth" role="tabpanel" hidden>
                        <?php if (empty($teeth)): ?>
                            <div class="edsm-empty">
                                <div class="edsm-empty-icon"><i class="fas fa-teeth"></i></div>
                                <p>لا توجد أسنان معالجة مسجلة لهذا المريض</p>
                            </div>
                        <?php else: ?>
                            <div class="edsm-table-wrap">
                                <table class="edsm-table">
                                    <thead><tr><th>السن</th><th>العلاجات</th><th>الحالة</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($teeth as $n => $list): ?>
                                            <tr data-tooth-row="<?= $n ?>">
                                                <td class="whitespace-nowrap">
                                                    <span class="edsm-tooth-num is-<?= $tooth_state[$n] ?> edsm-num"><?= $n ?></span>
                                                    <span class="text-sm text-gray-600"><?= htmlspecialchars($tooth_names[$n] ?? '') ?></span>
                                                </td>
                                                <td>
                                                    <?php foreach ($list as $t): ?>
                                                        <a href="treatment_details.php?id=<?= $t['id'] ?>" class="block hover:text-blue-600">
                                                            <span class="font-bold"><?= htmlspecialchars($t['type_name']) ?></span>
                                                            <span class="text-xs text-gray-500 edsm-num"><?= date('d/m/Y', strtotime($t['treatment_date'])) ?></span>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td class="whitespace-nowrap">
                                                    <span class="edsm-state <?= $tooth_state[$n] === 'progress' ? 'is-progress' : 'is-treated' ?>"><?= $tooth_state[$n] === 'progress' ? 'قيد العلاج' : 'معالج' ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- الملاحظات -->
                    <div class="edsm-tab-panel" data-panel="notes" role="tabpanel" hidden>
                        <div class="flex justify-end mb-3 no-print">
                            <button type="button" class="edsm-btn" data-note-open><i class="fas fa-plus"></i> إضافة ملاحظة</button>
                        </div>
                        <form method="POST" id="noteForm" class="edsm-note-form no-print" hidden>
                            <input type="hidden" name="action" value="add_note">
                            <label for="noteType" class="edsm-label">نوع الملاحظة</label>
                            <select name="note_type" id="noteType" class="edsm-field" style="max-width: 220px;">
                                <?php foreach ($note_types as $value => $label): ?>
                                    <option value="<?= $value ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="noteContent" class="edsm-label mt-3">الملاحظة</label>
                            <textarea name="note_content" id="noteContent" rows="3" required class="edsm-field" style="height: auto; padding: 10px 12px;" placeholder="اكتب الملاحظة هنا..."></textarea>
                            <div class="flex gap-2 mt-3">
                                <button type="submit" class="edsm-btn edsm-btn-navy"><i class="fas fa-save"></i> حفظ الملاحظة</button>
                                <button type="button" class="edsm-btn edsm-btn-ghost" id="noteCancel">إلغاء</button>
                            </div>
                        </form>
                        <?php if (empty($notes)): ?>
                            <p class="text-sm text-gray-500 py-6 text-center">لا توجد ملاحظات مسجلة</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($notes as $note): ?>
                                    <article class="edsm-note">
                                        <div class="edsm-note-head">
                                            <span class="edsm-note-type is-<?= htmlspecialchars($note['note_type'] ?: 'general') ?>"><?= $note_types[$note['note_type']] ?? 'عامة' ?></span>
                                            <span>
                                                <span class="edsm-num"><?= date('d/m/Y H:i', strtotime($note['created_at'])) ?></span>
                                                <?php if ($note['created_by_name']): ?> · <?= htmlspecialchars(doctorLabel($note['created_by_name'], $note['created_by_role'])) ?><?php endif; ?>
                                            </span>
                                        </div>
                                        <p><?= nl2br(htmlspecialchars($note['note_content'])) ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- المواعيد -->
                    <div class="edsm-tab-panel" data-panel="appointments" role="tabpanel" hidden>
                        <?php if (empty($appointments)): ?>
                            <div class="edsm-empty">
                                <div class="edsm-empty-icon"><i class="far fa-calendar"></i></div>
                                <p>لا توجد مواعيد لهذا المريض</p>
                                <a href="../nurse/appointments.php?action=add&amp;patient_id=<?= $patient_id ?>" class="edsm-btn edsm-btn-lg no-print"><i class="fas fa-calendar-plus"></i> حجز موعد</a>
                            </div>
                        <?php else: ?>
                            <div class="edsm-table-wrap">
                                <table class="edsm-table">
                                    <thead><tr><th>التاريخ</th><th>الوقت</th><th>الإجراء</th><th>الحالة</th><th>ملاحظات</th><th class="text-center no-print">عرض</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($appointments as $a): ?>
                                            <?php [$status_label, $status_class] = $appointment_statuses[$a['status']] ?? [$a['status'], 'scheduled']; ?>
                                            <tr>
                                                <td class="edsm-num whitespace-nowrap"><?= date('d/m/Y', strtotime($a['appointment_date'])) ?></td>
                                                <td class="edsm-num font-bold"><?= date('H:i', strtotime($a['appointment_time'])) ?></td>
                                                <td class="font-bold text-gray-800"><?= htmlspecialchars($a['treatment_type']) ?></td>
                                                <td><span class="edsm-status edsm-status-<?= $status_class ?>"><?= $status_label ?></span></td>
                                                <td class="text-sm text-gray-600"><?= htmlspecialchars($a['notes'] ?? '') ?: '—' ?></td>
                                                <td class="text-center no-print"><a href="appointments.php?date=<?= $a['appointment_date'] ?>" class="edsm-icon-action is-blue" title="عرض في جدول المواعيد"><i class="far fa-eye"></i></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- الحسابات -->
                    <div class="edsm-tab-panel" data-panel="accounts" role="tabpanel" hidden>
                        <div class="edsm-money-grid">
                            <div class="edsm-money"><span>إجمالي التكاليف</span><strong class="edsm-num"><?= money($patient['total_cost']) ?></strong></div>
                            <div class="edsm-money is-paid"><span>المدفوع</span><strong class="edsm-num"><?= money($patient['total_paid']) ?></strong></div>
                            <div class="edsm-money <?= $patient['remaining_balance'] > 0 ? 'is-due' : 'is-paid' ?>">
                                <span><?= $patient['remaining_balance'] < 0 ? 'رصيد زائد للمريض' : 'المتبقي' ?></span>
                                <strong class="edsm-num"><?= money(abs($patient['remaining_balance'])) ?></strong>
                            </div>
                        </div>
                        <div class="flex justify-end mt-3 no-print">
                            <a href="../nurse/patient_balance.php?action=add_payment&amp;patient_id=<?= $patient_id ?>" class="edsm-btn"><i class="fas fa-plus"></i> إضافة دفعة</a>
                        </div>

                        <?php if ($treatments_with_cost): ?>
                            <h4 class="edsm-subtitle">تكاليف العلاجات</h4>
                            <div class="edsm-table-wrap">
                                <table class="edsm-table">
                                    <thead><tr><th>العلاج</th><th>التاريخ</th><th>التكلفة</th><th>المدفوع</th><th>المتبقي</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($treatments_with_cost as $t): ?>
                                            <?php $rest = $t['cost'] - $t['paid']; ?>
                                            <tr>
                                                <td class="font-bold text-gray-800"><?= htmlspecialchars($t['type_name']) ?></td>
                                                <td class="edsm-num whitespace-nowrap"><?= date('d/m/Y', strtotime($t['treatment_date'])) ?></td>
                                                <td class="edsm-num whitespace-nowrap"><?= money($t['cost']) ?></td>
                                                <td class="edsm-num whitespace-nowrap text-green-700"><?= money($t['paid']) ?></td>
                                                <td class="edsm-num whitespace-nowrap font-bold <?= $rest > 0 ? 'text-red-600' : 'text-green-700' ?>"><?= money(max(0, $rest)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <h4 class="edsm-subtitle">سجل الدفعات</h4>
                        <?php if (empty($payments)): ?>
                            <p class="text-sm text-gray-500 py-3 text-center">لا يوجد سجل دفعات</p>
                        <?php else: ?>
                            <div class="edsm-table-wrap">
                                <table class="edsm-table">
                                    <thead><tr><th>التاريخ</th><th>المبلغ</th><th>طريقة الدفع</th><th>العلاج</th><th>الإيصال</th><th>بواسطة</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td class="edsm-num whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($pay['payment_date'])) ?></td>
                                                <td class="edsm-num whitespace-nowrap font-bold text-green-700"><?= money($pay['amount']) ?></td>
                                                <td class="whitespace-nowrap"><?= $payment_methods[$pay['payment_method']] ?? htmlspecialchars($pay['payment_method']) ?></td>
                                                <td><?= $pay['type_name'] ? htmlspecialchars($pay['type_name']) : '<span class="text-gray-500">دفعة عامة</span>' ?></td>
                                                <td class="edsm-num"><?= $pay['receipt_number'] ? htmlspecialchars($pay['receipt_number']) : '—' ?></td>
                                                <td class="whitespace-nowrap"><?= $pay['created_by_name'] ? htmlspecialchars($pay['created_by_name']) : '—' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="edsm-profile-pair">
                    <section class="edsm-card pf-chart fade-in">
                        <h3 class="edsm-card-title" style="margin-bottom: 10px;"><i class="fas fa-tooth"></i> المخطط السني</h3>
                        <?= toothChartSvg($tooth_state, $teeth, $tooth_names) ?>
                        <div class="flex flex-wrap justify-center gap-3 mt-2 text-xs text-gray-600">
                            <span><span class="edsm-legend edsm-legend-tooth is-progress"></span> قيد العلاج</span>
                            <span><span class="edsm-legend edsm-legend-tooth is-treated"></span> معالج</span>
                            <span><span class="edsm-legend edsm-legend-tooth"></span> لا علاج مسجل</span>
                        </div>
                    </section>

                    <section class="edsm-card pf-medical fade-in">
                        <h3 class="edsm-card-title" style="margin-bottom: 14px;"><i class="fas fa-file-medical"></i> التاريخ الطبي والحساسية</h3>
                        <dl class="edsm-med-list">
                            <div class="<?= $patient['allergies'] ? 'is-alert' : '' ?>">
                                <dt>الحساسية:</dt>
                                <dd><?= $patient['allergies'] ? nl2br(htmlspecialchars($patient['allergies'])) : 'لا توجد' ?></dd>
                            </div>
                            <div>
                                <dt>الأمراض المزمنة والتاريخ المرضي:</dt>
                                <dd><?= $patient['medical_history'] ? nl2br(htmlspecialchars($patient['medical_history'])) : 'لا يوجد' ?></dd>
                            </div>
                            <?php if ($medical_notes): ?>
                                <div>
                                    <dt>ملاحظات طبية:</dt>
                                    <?php foreach ($medical_notes as $note): ?>
                                        <dd class="edsm-med-note"><?= nl2br(htmlspecialchars($note['note_content'])) ?> <span class="edsm-num text-xs text-gray-500"><?= date('d/m/Y', strtotime($note['created_at'])) ?></span></dd>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </dl>
                        <a href="patient_edit.php?id=<?= $patient_id ?>" class="edsm-link text-sm mt-4 no-print"><i class="fas fa-pen text-xs"></i> تحديث التاريخ الطبي</a>
                    </section>
                </div>
            </div>
        </div>

        <!-- ================= قائمة المتابعات ================= -->
        <section class="edsm-card mt-6 fade-in" id="followups">
            <div class="edsm-card-head">
                <h3 class="edsm-card-title">
                    <i class="fas fa-chart-bar"></i> قائمة المتابعات
                    <span class="text-sm text-gray-500 font-bold">(<span class="edsm-num"><?= count($followups) ?></span>)</span>
                </h3>
                <a href="follow_ups.php?patient=<?= $patient_id ?>&amp;f=1" class="edsm-link text-sm no-print">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
            </div>
            <?php if (empty($followups)): ?>
                <div class="edsm-empty">
                    <div class="edsm-empty-icon"><i class="fas fa-user-clock"></i></div>
                    <p>لا توجد متابعات لهذا المريض</p>
                    <a href="follow_ups.php?action=add&amp;patient_id=<?= $patient_id ?>&amp;back=patient" class="edsm-btn edsm-btn-lg no-print"><i class="fas fa-plus"></i> إضافة متابعة</a>
                </div>
            <?php else: ?>
                <div class="edsm-table-wrap">
                    <table class="edsm-table edsm-table-lg">
                        <thead>
                            <tr><th>تاريخ المتابعة</th><th>نوع المتابعة</th><th>طبيب المتابعة</th><th>ملاحظات</th><th>الحالة</th><th class="no-print">إجراءات</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($followups as $f): ?>
                                <?php
                                [, $kind_label] = followupKind($f);
                                $state = followupState($f, $today);
                                ?>
                                <tr>
                                    <td class="whitespace-nowrap">
                                        <span class="edsm-num font-bold"><?= date('d/m/Y', strtotime($f['follow_up_date'])) ?></span>
                                        <?php if ($f['priority'] === 'urgent' || $f['priority'] === 'high'): ?>
                                            <span class="edsm-tag <?= $f['priority'] === 'urgent' ? 'edsm-tag-danger' : 'edsm-tag-warning' ?>"><?= FOLLOWUP_PRIORITY_LABELS[$f['priority']] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="edsm-fu-kind" title="<?= htmlspecialchars((string)$f['follow_up_reason']) ?>"><?= htmlspecialchars($kind_label) ?></span></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars(doctorLabel($f['doctor_name'], $f['doctor_role'])) ?></td>
                                    <td class="text-sm text-gray-600"><?= $f['notes'] ? htmlspecialchars($f['notes']) : '—' ?></td>
                                    <td>
                                        <span class="edsm-state is-<?= $state ?>" <?= $state === 'completed' && $f['completed_at'] ? 'title="أُكملت ' . date('d/m/Y H:i', strtotime($f['completed_at'])) . '"' : '' ?>><?= FOLLOWUP_STATUS_LABELS[$state] ?></span>
                                    </td>
                                    <td class="no-print">
                                        <a href="follow_ups.php?edit=<?= (int)$f['id'] ?>&amp;back=patient" class="edsm-text-action"><i class="far fa-pen-to-square"></i> تحديث المتابعة</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
    (function () {
        const tabs = document.querySelectorAll('[data-tab]');
        const panels = document.querySelectorAll('[data-panel]');
        const record = document.getElementById('record');

        function openTab(name, scroll) {
            if (![...tabs].some(t => t.dataset.tab === name)) return false;
            tabs.forEach(t => {
                const on = t.dataset.tab === name;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(p => { p.hidden = p.dataset.panel !== name; });
            if (scroll) record.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return true;
        }
        function openAndRemember(name, scroll) {
            if (openTab(name, scroll)) history.replaceState(null, '', '#' + name);
        }
        tabs.forEach(t => t.addEventListener('click', e => { e.preventDefault(); openAndRemember(t.dataset.tab, false); }));
        document.querySelectorAll('[data-open-tab]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); openAndRemember(a.dataset.openTab, true); }));
        // فتح التبويب المذكور في الرابط (مثلاً بعد إضافة ملاحظة)
        if (openTab(location.hash.slice(1), false)) record.scrollIntoView({ block: 'start' });

        // النقر على سن معالج في المخطط يعرض تفاصيله
        function showTooth(el) {
            openAndRemember('teeth', true);
            document.querySelectorAll('tr.is-flash').forEach(r => r.classList.remove('is-flash'));
            const row = document.querySelector('[data-tooth-row="' + el.dataset.tooth + '"]');
            if (row) row.classList.add('is-flash');
        }
        document.querySelectorAll('.edsm-tooth[data-tooth]').forEach(el => {
            el.addEventListener('click', () => showTooth(el));
            el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); showTooth(el); } });
        });

        // نموذج الملاحظة
        const noteForm = document.getElementById('noteForm');
        document.querySelectorAll('[data-note-open]').forEach(b => b.addEventListener('click', () => {
            noteForm.hidden = false;
            document.getElementById('noteContent').focus();
        }));
        document.getElementById('noteCancel').addEventListener('click', () => { noteForm.reset(); noteForm.hidden = true; });
        noteForm.addEventListener('submit', e => {
            const text = document.getElementById('noteContent');
            if (!text.value.trim()) { e.preventDefault(); text.focus(); }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
