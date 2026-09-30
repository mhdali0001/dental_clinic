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
$success_message = '';
$error_message = '';
// تاريخ اليوم من MySQL كما في باقي الصفحات (توقيت PHP قد يختلف عنه)
$today = $pdo->query("SELECT CURDATE()")->fetchColumn();

// حقول النص الحرّة في نموذج العلاج
const TEXT_FIELDS = ['symptoms', 'diagnosis', 'treatment_details', 'medications', 'notes'];

// أرقام الأسنان وفق نظام FDI: دائمة 11-48، لبنية 51-85
function validToothNumber($n) {
    $n = (int)$n;
    $q = intdiv($n, 10);
    $p = $n % 10;
    return ($q >= 1 && $q <= 4 && $p >= 1 && $p <= 8) || ($q >= 5 && $q <= 8 && $p >= 1 && $p <= 5);
}

function parseTeeth($value) {
    $list = is_string($value) ? json_decode($value, true) : $value;
    $teeth = [];
    foreach (is_array($list) ? $list : [] as $n) {
        if (is_numeric($n) && validToothNumber($n)) {
            $teeth[] = (int)$n;
        }
    }
    $teeth = array_unique($teeth);
    sort($teeth, SORT_NUMERIC);
    return array_map('strval', $teeth);
}

function validDate($value) {
    $date = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value;
}

// توحيد بيانات المراحل قبل الحفظ؛ تُحفظ المفاتيح بالصيغتين (title و title_ar ...) لتقرأها كل الصفحات
function cleanStages($value) {
    $stages = is_string($value) ? json_decode($value, true) : $value;
    $clean = [];
    foreach (is_array($stages) ? array_values($stages) : [] as $s) {
        if (!is_array($s)) {
            continue;
        }
        $title = trim((string)($s['title_ar'] ?? $s['title'] ?? ''));
        $title = $title !== '' ? $title : 'المرحلة ' . (count($clean) + 1);
        $description = trim((string)($s['description_ar'] ?? $s['description'] ?? ''));
        $duration = trim((string)($s['duration_ar'] ?? $s['duration'] ?? ''));
        $completed = !empty($s['completed']);
        $stage = [
            'index' => count($clean),
            'title' => $title, 'title_ar' => $title,
            'description' => $description, 'description_ar' => $description,
            'duration' => $duration, 'duration_ar' => $duration,
            'completed' => $completed,
            'completedDate' => $completed ? normalizeStageDate($s['completedDate'] ?? null) : null,
            'notes' => mb_substr(trim((string)($s['notes'] ?? '')), 0, 1000),
        ];
        if (!empty($s['option_id'])) {
            $stage['option_id'] = (int)$s['option_id'];
        }
        if (!empty($s['skipped'])) {
            $stage['skipped'] = true;
        }
        $clean[] = $stage;
    }
    return $clean;
}

// متابعة العلاج: بالتاريخ المحدد، أو بعد المدة المختارة من تاريخ العلاج
function createTreatmentFollowUp($pdo, $treatment_id, $patient_id, $treatment_date, $period, $date, $priority, $notes, $doctor_id) {
    if (!$date && $period) {
        $d = new DateTime($treatment_date);
        $d->modify("+{$period} months");
        $date = $d->format('Y-m-d');
    }
    if (!$date) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO follow_ups (patient_id, treatment_id, follow_up_type, follow_up_date, follow_up_reason, priority, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patient_id, $treatment_id, $period ? 'treatment' : 'manual', $date,
        $period ? "متابعة علاج بعد {$period} أشهر" : 'موعد متابعة محدد',
        $priority, $notes, $doctor_id,
    ]);
}

function logTreatmentActivity($pdo, $doctor_id, $action, $treatment_id, $description) {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, table_name, record_id, description) VALUES (?, ?, 'treatments', ?, ?)");
        $stmt->execute([$doctor_id, $action, $treatment_id, $description]);
    } catch (PDOException $e) {
        // سجل النشاط اختياري
    }
}

$post_action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

// ---------- حفظ تقدم علاج قائم (بدون إكمال) ----------
// العلاجات مشتركة بين الأطباء: أي طبيب يتابع علاج زميله (يُسجَّل المنفِّذ في activity_log)
if ($post_action === 'save_progress') {
    $treatment_id = (int)($_POST['treatment_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatments WHERE id = ?");
        $stmt->execute([$treatment_id]);
        if (!$stmt->fetchColumn()) {
            $error_message = 'خطأ في حفظ تقدم العلاج: العلاج غير موجود';
        } else {
            $stmt = $pdo->prepare("UPDATE treatments SET treatment_stages = ?, treatment_details = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([
                json_encode(cleanStages($_POST['treatment_stages'] ?? '[]'), JSON_UNESCAPED_UNICODE),
                $_POST['treatment_details'] ?? '',
                $treatment_id,
            ]);
            $success_message = 'تم حفظ تقدم العلاج بنجاح';
            logTreatmentActivity($pdo, $doctor_id, 'save_progress', $treatment_id, 'تم حفظ تقدم العلاج');
        }
    } catch (PDOException $e) {
        $error_message = 'خطأ في حفظ تقدم العلاج: ' . htmlspecialchars($e->getMessage());
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $error_message === '', 'message' => $error_message ?: $success_message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------- إكمال علاج قائم ----------
if ($post_action === 'complete_treatment') {
    $treatment_id = (int)($_POST['treatment_id'] ?? 0);
    $stages = cleanStages($_POST['treatment_stages'] ?? '[]');
    // الطبيب أكّد إنهاء العلاج رغم وجود مراحل لم تُنفَّذ
    $force_complete = !empty($_POST['force_complete']);
    $pending = array_filter($stages, fn($s) => !$s['completed']);

    if ($pending && !$force_complete) {
        $error_message = 'لا يمكن إكمال العلاج حتى يتم إكمال جميع مراحل العلاج التالية:<br>• '
                       . implode('<br>• ', array_map(fn($s) => htmlspecialchars($s['title']), $pending));
    } else {
        foreach ($stages as &$stage) {
            // مراحل أُغلقت بإنهاء العلاج دون تنفيذها تُعلَّم كذلك
            if (!$stage['completed']) {
                $stage['skipped'] = true;
                if ($stage['notes'] === '') {
                    $stage['notes'] = 'لم تُنفَّذ - أُغلقت عند إنهاء العلاج';
                }
            }
            $stage['completed'] = true;
            $stage['completedDate'] = $stage['completedDate'] ?? $today;
            if ($stage['notes'] === '') {
                $stage['notes'] = 'تم إكمال هذه المرحلة';
            }
        }
        unset($stage);

        try {
            $stmt = $pdo->prepare("
                UPDATE treatments
                SET status = 'completed', completed_at = NOW(), completion_notes = ?, treatment_stages = ?,
                    treatment_details = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                trim($_POST['completion_notes'] ?? ''),
                json_encode($stages, JSON_UNESCAPED_UNICODE),
                $_POST['treatment_details'] ?? '',
                $treatment_id,
            ]);
            logTreatmentActivity($pdo, $doctor_id, 'complete_treatment', $treatment_id, 'تم إكمال العلاج للمريض');

            // "إنهاء وبدء علاج جديد": الانتقال مباشرة لعلاج جديد لنفس المريض
            if (($_POST['after_complete'] ?? '') === 'new_treatment') {
                $stmt = $pdo->prepare("SELECT patient_id FROM treatments WHERE id = ?");
                $stmt->execute([$treatment_id]);
                if ($completed_patient_id = $stmt->fetchColumn()) {
                    header('Location: treatment_new.php?patient_id=' . (int)$completed_patient_id . '&completed=1');
                    exit;
                }
            }
            header('Location: treatments.php?completed=1');
            exit;
        } catch (PDOException $e) {
            $error_message = 'خطأ في إكمال العلاج: ' . htmlspecialchars($e->getMessage());
        }
    }
}

if (isset($_GET['completed']) && !$post_action) {
    $success_message = 'تم إنهاء العلاج السابق بنجاح، يمكنك الآن تسجيل العلاج الجديد';
}

// ---------- حفظ علاج جديد ----------
$form_post = false; // إعادة تعبئة النموذج بالقيم المرسلة عند الخطأ
if ($post_action === 'save_treatment') {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $treatment_type = trim((string)($_POST['treatment_type'] ?? ''));
    $treatment_date = (string)($_POST['treatment_date'] ?? '');
    $teeth = parseTeeth($_POST['selected_teeth'] ?? '[]');
    $stages = cleanStages($_POST['treatment_stages'] ?? '[]');
    $appointment_id = (int)($_POST['appointment_id'] ?? 0) ?: null;
    $cost = trim((string)($_POST['cost'] ?? '')) !== '' ? max(0, round((float)$_POST['cost'], 2)) : null;
    $period = in_array((int)($_POST['follow_up_period'] ?? 0), [3, 6, 9], true) ? (int)$_POST['follow_up_period'] : 0;
    $followup_date = validDate($_POST['next_appointment_date'] ?? '') ? $_POST['next_appointment_date'] : null;
    $priority = array_key_exists($_POST['follow_up_priority'] ?? '', FOLLOWUP_PRIORITY_LABELS) ? $_POST['follow_up_priority'] : 'normal';

    $missing = [];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE id = ? AND status != 'deleted'");
        $stmt->execute([$patient_id]);
        if (!$stmt->fetchColumn()) {
            $missing[] = 'المريض';
        }
        if (!$teeth) {
            $missing[] = 'الأسنان';
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
        $stmt->execute([$treatment_type]);
        if (!$stmt->fetchColumn()) {
            $missing[] = 'نوع العلاج';
        }
        if (!validDate($treatment_date) || $treatment_date > $today) {
            $missing[] = 'تاريخ العلاج (لا يكون في المستقبل)';
        }
        if ($followup_date && $followup_date < $today) {
            $missing[] = 'موعد متابعة لاحق لليوم';
        }
        // الموعد يجب أن يخص المريض نفسه
        if ($appointment_id) {
            $stmt = $pdo->prepare("SELECT patient_id FROM appointments WHERE id = ?");
            $stmt->execute([$appointment_id]);
            if ((int)$stmt->fetchColumn() !== $patient_id) {
                $appointment_id = null;
            }
        }
    } catch (PDOException $e) {
        $missing[] = 'البيانات (' . $e->getMessage() . ')'; // تُهرَّب مع الرسالة
    }

    if ($missing) {
        $error_message = 'أكمل قبل الحفظ: ' . htmlspecialchars(implode('، ', $missing));
        $form_post = true;
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO treatments (patient_id, appointment_id, treatment_date, treatment_type,
                                        symptoms, diagnosis, treatment_details, medications, cost,
                                        next_appointment_date, notes, doctor_id, teeth_numbers, treatment_stages)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $patient_id, $appointment_id, $treatment_date, $treatment_type,
                trim($_POST['symptoms'] ?? ''), trim($_POST['diagnosis'] ?? ''), trim($_POST['treatment_details'] ?? ''),
                trim($_POST['medications'] ?? ''), $cost, $followup_date, trim($_POST['notes'] ?? ''), $doctor_id,
                json_encode($teeth), json_encode($stages, JSON_UNESCAPED_UNICODE),
            ]);
            $treatment_id = $pdo->lastInsertId();
            // الأسنان محفوظة في teeth_numbers (FDI)؛ جدول tooth_treatments يقبل ترقيم 1-32 فقط ولا تقرؤه أي صفحة

            // ربط العلاج بموعد يغلق الموعد
            if ($appointment_id) {
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', doctor_entry_time = NOW() WHERE id = ?");
                $stmt->execute([$appointment_id]);
            }

            $stmt = $pdo->prepare("UPDATE patients SET last_visit_date = ? WHERE id = ?");
            $stmt->execute([$treatment_date, $patient_id]);

            createTreatmentFollowUp($pdo, $treatment_id, $patient_id, $treatment_date, $period, $followup_date,
                                    $priority, trim($_POST['follow_up_notes'] ?? ''), $doctor_id);

            $pdo->commit();
            header('Location: treatments.php?new_treatment=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'خطأ في حفظ العلاج: ' . htmlspecialchars($e->getMessage());
            $form_post = true;
        }
    }
}

// ---------- بيانات الصفحة ----------
$view_mode = isset($_GET['complete_id']);
$view_treatment = null;
if ($view_mode) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, COALESCE(tt.name_ar, t.treatment_type) AS type_name
            FROM treatments t
            LEFT JOIN treatment_types tt ON tt.code = t.treatment_type
            WHERE t.id = ?
        ");
        $stmt->execute([(int)$_GET['complete_id']]);
        $view_treatment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$view_treatment) {
            $error_message = 'العلاج غير موجود';
        }
    } catch (PDOException $e) {
        $error_message = 'خطأ في جلب بيانات العلاج: ' . htmlspecialchars($e->getMessage());
    }
    $view_mode = (bool)$view_treatment;
}

try {
    // المرضى (مع مريض العلاج المفتوح حتى لو لم يعد نشطاً)
    $stmt = $pdo->prepare("
        SELECT id, name, phone, age, date_of_birth, medical_history, allergies
        FROM patients
        WHERE status = 'active' OR id = ?
        ORDER BY name
    ");
    $stmt->execute([$view_treatment['patient_id'] ?? 0]);
    $patients = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ($p['date_of_birth']) {
            $p['age'] = (new DateTime($p['date_of_birth']))->diff(new DateTime($today))->y;
        }
        $patients[] = [
            'id' => (int)$p['id'], 'name' => $p['name'], 'phone' => (string)$p['phone'],
            'age' => $p['age'] !== null ? (int)$p['age'] : null,
            'history' => (string)$p['medical_history'], 'allergies' => (string)$p['allergies'],
        ];
    }

    // مواعيد اليوم التي لم يُسجَّل لها علاج، مجمّعة حسب المريض
    $stmt = $pdo->query("
        SELECT a.id, a.patient_id, a.appointment_time, a.treatment_type
        FROM appointments a
        WHERE a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed')
          AND NOT EXISTS (SELECT 1 FROM treatments t WHERE t.appointment_id = a.id)
        ORDER BY a.appointment_time
    ");
    $appointments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $appointments[$a['patient_id']][] = ['id' => (int)$a['id'], 'label' => 'اليوم ' . substr($a['appointment_time'], 0, 5) . ' — ' . $a['treatment_type']];
    }

    // أنواع العلاج وخياراتها ومراحل كل خيار
    $stage_rows = $pdo->query("
        SELECT treatment_option_id, title_ar, description_ar, duration_ar
        FROM treatment_stages WHERE is_active = 1
        ORDER BY treatment_option_id, stage_order
    ")->fetchAll(PDO::FETCH_ASSOC);
    $stages_by_option = [];
    foreach ($stage_rows as $s) {
        $stages_by_option[$s['treatment_option_id']][] = ['title' => $s['title_ar'], 'desc' => (string)$s['description_ar'], 'duration' => (string)$s['duration_ar']];
    }
    $option_rows = $pdo->query("
        SELECT id, treatment_type_code, name_ar, description_ar, price
        FROM treatment_options WHERE is_active = 1
        ORDER BY treatment_type_code, display_order, name_ar
    ")->fetchAll(PDO::FETCH_ASSOC);
    $options_by_type = [];
    foreach ($option_rows as $o) {
        $options_by_type[$o['treatment_type_code']][] = [
            'id' => (int)$o['id'], 'name' => $o['name_ar'], 'desc' => (string)$o['description_ar'],
            'price' => (float)$o['price'], 'stages' => $stages_by_option[$o['id']] ?? [],
        ];
    }
    $types = [];
    foreach ($pdo->query("SELECT code, name_ar, description_ar, icon_class FROM treatment_types WHERE is_active = 1 ORDER BY display_order, name_ar") as $t) {
        $types[] = [
            'code' => $t['code'], 'name' => $t['name_ar'], 'desc' => (string)$t['description_ar'],
            'icon' => $t['icon_class'] ?: 'fas fa-tooth', 'options' => $options_by_type[$t['code']] ?? [],
        ];
    }
} catch (PDOException $e) {
    $error_message = $error_message ?: 'خطأ في تحميل البيانات: ' . htmlspecialchars($e->getMessage());
    $patients = $types = [];
    $appointments = [];
}

// الحالة الأولى للنموذج
$initial = ['patientId' => null, 'appointmentId' => null, 'teeth' => [], 'dentition' => null, 'type' => null, 'options' => null,
            'stages' => [], 'date' => $today, 'cost' => null, 'discount' => 0, 'fields' => [], 'followup' => null];
if ($view_mode) {
    // إكمال علاج محفوظ
    $saved_options = [];
    foreach ($options_by_type[$view_treatment['treatment_type']] ?? [] as $o) {
        if ($view_treatment['treatment_details'] !== null && mb_strpos($view_treatment['treatment_details'], $o['name']) !== false) {
            $saved_options[] = $o['id'];
        }
    }
    $initial = array_merge($initial, [
        'patientId' => (int)$view_treatment['patient_id'],
        'teeth' => parseTeeth($view_treatment['teeth_numbers'] ?? '[]'),
        'type' => $view_treatment['treatment_type'],
        'typeName' => $view_treatment['type_name'],
        'options' => $saved_options,
        'stages' => cleanStages($view_treatment['treatment_stages'] ?? '[]'),
        'date' => $view_treatment['treatment_date'],
        'cost' => $view_treatment['cost'],
        'fields' => [
            'symptoms' => (string)$view_treatment['symptoms'], 'diagnosis' => (string)$view_treatment['diagnosis'],
            'treatment_details' => (string)$view_treatment['treatment_details'], 'medications' => (string)$view_treatment['medications'],
            'notes' => (string)$view_treatment['notes'],
        ],
    ]);
} elseif ($form_post) {
    // إعادة تعبئة النموذج بعد خطأ في الحفظ
    $option_ids = json_decode($_POST['option_ids'] ?? '[]', true);
    $initial = array_merge($initial, [
        'patientId' => (int)($_POST['patient_id'] ?? 0) ?: null,
        'appointmentId' => (int)($_POST['appointment_id'] ?? 0) ?: null,
        'teeth' => parseTeeth($_POST['selected_teeth'] ?? '[]'),
        'dentition' => in_array($_POST['dentition_type'] ?? '', ['perm', 'mixed', 'prim'], true) ? $_POST['dentition_type'] : null,
        'type' => $_POST['treatment_type'] ?? null,
        'options' => is_array($option_ids) ? array_map('intval', $option_ids) : [],
        'stages' => cleanStages($_POST['treatment_stages'] ?? '[]'),
        'date' => $_POST['treatment_date'] ?? $today,
        'cost' => trim((string)($_POST['cost'] ?? '')) !== '' ? $_POST['cost'] : null,
        'discount' => (float)($_POST['discount'] ?? 0),
        'fields' => array_combine(TEXT_FIELDS, array_map(fn($k) => is_string($_POST[$k] ?? null) ? $_POST[$k] : '', TEXT_FIELDS)),
        'followup' => [
            'period' => (string)($_POST['follow_up_period'] ?? ''), 'date' => (string)($_POST['next_appointment_date'] ?? ''),
            'priority' => (string)($_POST['follow_up_priority'] ?? 'normal'), 'notes' => (string)($_POST['follow_up_notes'] ?? ''),
        ],
    ]);
} else {
    // روابط جاهزة: ?patient_id= أو ?appointment_id= (من المواعيد أو ملف المريض)
    $initial['patientId'] = (int)($_GET['patient_id'] ?? 0) ?: null;
    if ($pre_appointment = (int)($_GET['appointment_id'] ?? 0)) {
        foreach ($appointments as $pid => $list) {
            if (in_array($pre_appointment, array_column($list, 'id'), true)) {
                $initial['patientId'] = (int)$pid;
                $initial['appointmentId'] = $pre_appointment;
            }
        }
    }
}

$page_data = [
    'mode' => $view_mode ? 'complete' : 'new',
    'today' => $today,
    'patients' => $patients,
    'appointments' => $appointments,
    'types' => $types,
    'initial' => $initial,
];

// Header configuration
if ($view_mode) {
    $patient_name = '';
    foreach ($patients as $p) {
        if ($p['id'] === (int)$view_treatment['patient_id']) {
            $patient_name = $p['name'];
        }
    }
    $pageTitle = 'إكمال العلاج';
    $pageIcon = 'fas fa-check-circle';
    $pageSubtitle = 'المريض: ' . $patient_name . ' · ' . $view_treatment['type_name'] . ' — تابع المراحل ثم أنهِ العلاج';
} else {
    $pageTitle = 'علاج جديد';
    $pageIcon = 'fas fa-tooth';
    $pageSubtitle = 'اختر المريض والأسنان ونوع العلاج، ثم تابع المراحل أثناء الجلسة.';
}
$currentPage = 'treatments';
$css_version = @filemtime(__DIR__ . '/../assets/css/treatment-form.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/treatment-form.css?v=<?= $css_version ?>" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-5">
                <i class="fas fa-check-circle ml-1"></i> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-5">
                <i class="fas fa-exclamation-triangle ml-1"></i> <?= $error_message /* مُهرَّب عند إنشائه */ ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="treatmentForm" class="tx" novalidate>
            <input type="hidden" name="action" id="txAction" value="<?= $view_mode ? 'complete_treatment' : 'save_treatment' ?>">
            <?php if ($view_mode): ?>
                <input type="hidden" name="treatment_id" value="<?= (int)$view_treatment['id'] ?>">
                <input type="hidden" name="force_complete" id="forceComplete" value="">
            <?php endif; ?>
            <input type="hidden" name="selected_teeth" id="selectedTeeth" value="[]">
            <input type="hidden" name="treatment_stages" id="stagesJson" value="[]">
            <input type="hidden" name="treatment_type" id="treatmentType" value="">
            <input type="hidden" name="option_ids" id="optionIds" value="[]">

            <!-- ============ المريض والموعد ============ -->
            <section class="card">
                <h2 class="card-title"><i class="fas fa-user"></i>المريض والموعد</h2>
                <div class="patient-row">
                    <div>
                        <label class="lbl" for="patientSearch">المريض <span class="req">*</span></label>
                        <div id="patientPick" class="search">
                            <select name="patient_id" id="patientSelect" class="field">
                                <option value="">اختر المريض...</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-phone="<?= htmlspecialchars($p['phone']) ?>" data-age="<?= $p['age'] ?? '' ?>"><?= htmlspecialchars($p['name']) ?> - <?= htmlspecialchars($p['phone']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="patient-chip" id="patientChip" hidden></div>
                        <div class="medical" id="medicalAlert" hidden></div>
                    </div>
                    <div<?= $view_mode ? ' hidden' : '' ?>>
                        <label class="lbl" for="appointment">الموعد <span class="optional">(اختياري)</span></label>
                        <select class="select" id="appointment" name="appointment_id" disabled>
                            <option value="">اختر المريض أولاً</option>
                        </select>
                        <div class="hint">ربط العلاج بموعد اليوم يغلق الموعد تلقائياً عند الحفظ.</div>
                    </div>
                </div>

                <div class="dent">
                    <label class="lbl">نوع الإطباق</label>
                    <div class="pills" role="radiogroup">
                        <label class="pill"><input type="radio" name="dentition_type" value="perm" checked><span>أسنان دائمة<small>بالغون</small></span></label>
                        <label class="pill"><input type="radio" name="dentition_type" value="mixed"><span>مختلطة<small>6–12 سنة</small></span></label>
                        <label class="pill"><input type="radio" name="dentition_type" value="prim"><span>أسنان لبنية<small>أطفال</small></span></label>
                    </div>
                    <div class="auto-note" id="autoNote"></div>
                </div>
            </section>

            <!-- ============ مخطط الأسنان ============ -->
            <div class="dc" id="dentalChart">
                <div class="dc-head">
                    <div>
                        <h2><?= $view_mode ? 'الأسنان المعالجة في هذا العلاج' : 'اختيار الأسنان المراد علاجها' ?></h2>
                        <p>الترقيم وفق نظام FDI، والمخطط من منظور الطبيب المقابل للمريض.</p>
                    </div>
                </div>
                <div class="dc-body">
                    <div class="chart">
                        <svg id="dcSvg" viewBox="0 0 760 700" role="group" aria-label="مخطط الأسنان"></svg>
                        <div class="legend">
                            <span><i class="sw"></i>سن دائم</span>
                            <span><i class="sw m"></i>سن لبني</span>
                            <span><i class="sw s"></i>محدد للعلاج</span>
                        </div>
                        <div class="quads" id="dcQuads" dir="ltr"></div>
                    </div>
                    <aside class="panel">
                        <h3>الأسنان المحددة <span id="dcCount">0</span></h3>
                        <div class="actions">
                            <button type="button" class="btn primary" data-act="all">تحديد الكل</button>
                            <button type="button" class="btn ghost" data-act="clear">مسح التحديد</button>
                            <button type="button" class="btn" data-act="upper">الفك العلوي</button>
                            <button type="button" class="btn" data-act="lower">الفك السفلي</button>
                        </div>
                        <ul class="list" id="dcList"></ul>
                    </aside>
                </div>
            </div>

            <!-- ============ نوع العلاج / التفاصيل / المراحل ============ -->
            <div class="work">
                <section class="card">
                    <h2 class="card-title"><i class="fas fa-stethoscope"></i>نوع العلاج</h2>
                    <div class="types" id="types"></div>
                </section>

                <div class="col">
                    <section class="card">
                        <h2 class="card-title"><i class="fas fa-list-check"></i><span id="optsTitle">خيارات العلاج</span><span class="aside" id="optsAside"></span></h2>
                        <div class="opts" id="opts"><p class="empty">اختر نوع العلاج لعرض خياراته.</p></div>
                    </section>

                    <section class="card cost">
                        <h2 class="card-title"><i class="fas fa-receipt"></i>ملخص التكلفة</h2>
                        <ul class="cost-lines" id="costLines"></ul>
                        <div class="cost-row"<?= $view_mode ? ' hidden' : '' ?>>
                            <label for="discount">الخصم (%)</label>
                            <input class="field" type="number" id="discount" name="discount" min="0" max="100" value="0" inputmode="numeric">
                        </div>
                        <div class="total">
                            <span>التكلفة الإجمالية</span>
                            <span class="total-box"><input class="field" type="number" id="costInput" name="cost" min="0" step="any" inputmode="decimal" placeholder="0" aria-label="التكلفة الإجمالية"> ل.س</span>
                        </div>
                        <div class="total-foot">
                            <span class="hint" id="costHint"></span>
                            <button type="button" class="link" id="costAuto" hidden></button>
                        </div>
                    </section>

                    <section class="card">
                        <h2 class="card-title"><i class="fas fa-file-medical"></i>معلومات العلاج الأساسية</h2>
                        <div class="grid2">
                            <div>
                                <label class="lbl" for="tDate">تاريخ العلاج <span class="req">*</span></label>
                                <input class="field" type="date" id="tDate" name="treatment_date" max="<?= $today ?>">
                            </div>
                            <div>
                                <span class="lbl">المدة المتوقعة</span>
                                <div class="readout" id="durationOut">—</div>
                                <div class="hint">محسوبة من مجموع مدد المراحل.</div>
                            </div>
                        </div>
                        <details class="more" id="moreDetails">
                            <summary><i class="fas fa-plus"></i>الأعراض والتشخيص والأدوية</summary>
                            <div class="inner">
                                <div><label class="lbl" for="symptoms">الأعراض</label><textarea class="field" id="symptoms" name="symptoms" placeholder="مثال: ألم عند المضغ منذ أسبوع"></textarea></div>
                                <div><label class="lbl" for="diagnosis">التشخيص</label><textarea class="field" id="diagnosis" name="diagnosis"></textarea></div>
                                <div><label class="lbl" for="details">تفاصيل العلاج</label><textarea class="field" id="details" name="treatment_details"></textarea></div>
                                <div><label class="lbl" for="meds">الأدوية الموصوفة</label><textarea class="field" id="meds" name="medications" placeholder="اسم الدواء، الجرعة، المدة"></textarea></div>
                                <div><label class="lbl" for="notes">ملاحظات إضافية</label><textarea class="field" id="notes" name="notes" placeholder="تعليمات للمريض أو ملاحظات أخرى"></textarea></div>
                            </div>
                        </details>
                    </section>

                    <?php if (!$view_mode): ?>
                    <section class="card">
                        <h2 class="card-title"><i class="fas fa-calendar-check"></i>المتابعة</h2>
                        <label class="lbl">متابعة تلقائية بعد</label>
                        <div class="seg2" role="radiogroup">
                            <label class="pill"><input type="radio" name="follow_up_period" value="" checked><span>بدون</span></label>
                            <label class="pill"><input type="radio" name="follow_up_period" value="3"><span>3 أشهر</span></label>
                            <label class="pill"><input type="radio" name="follow_up_period" value="6"><span>6 أشهر</span></label>
                            <label class="pill"><input type="radio" name="follow_up_period" value="9"><span>9 أشهر</span></label>
                        </div>
                        <div class="grid2" style="margin-top: 14px;">
                            <div>
                                <label class="lbl" for="fDate">موعد المتابعة</label>
                                <input class="field" type="date" id="fDate" name="next_appointment_date" min="<?= $today ?>">
                                <div class="hint" id="fHint">اختر مدة أعلاه أو حدد تاريخاً بنفسك.</div>
                            </div>
                            <div>
                                <label class="lbl" for="fPriority">أولوية المتابعة</label>
                                <select class="select" id="fPriority" name="follow_up_priority">
                                    <?php foreach (FOLLOWUP_PRIORITY_LABELS as $value => $label): ?>
                                        <option value="<?= $value ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <label class="lbl" for="fNotes" style="margin-top: 14px;">ملاحظات المتابعة <span class="optional">(اختياري)</span></label>
                        <input class="field" type="text" id="fNotes" name="follow_up_notes" maxlength="500" placeholder="مثال: فحص التئام الجرح">
                    </section>
                    <?php endif; ?>
                </div>

                <section class="card stages-card">
                    <h2 class="card-title"><i class="fas fa-route"></i>مراحل العلاج</h2>
                    <div class="progress" aria-hidden="true"><i id="progBar"></i></div>
                    <div class="prog-meta"><span id="progText"></span><span id="progTime"></span></div>
                    <ol class="stages" id="stages"></ol>
                    <?php if ($view_mode): ?>
                        <label class="lbl" for="completionNotes" style="margin-top: 14px;">ملاحظات إنهاء العلاج <span class="optional">(اختياري)</span></label>
                        <textarea class="field" id="completionNotes" name="completion_notes" placeholder="تُحفظ مع العلاج عند إكماله"></textarea>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ============ شريط الحفظ ============ -->
            <div class="savebar">
                <div class="savebar-in">
                    <div class="sum" id="saveSum" aria-live="polite"></div>
                    <a href="treatments.php" class="btn">إلغاء</a>
                    <?php if ($view_mode): ?>
                        <button type="button" class="btn" id="saveProgressBtn"><i class="fas fa-save"></i> حفظ التقدم</button>
                        <button type="submit" class="btn indigo" name="after_complete" value="new_treatment"><i class="fas fa-forward"></i> إنهاء وبدء علاج جديد</button>
                        <button type="submit" class="btn primary" id="completeBtn"><i class="fas fa-check"></i> إكمال العلاج</button>
                    <?php else: ?>
                        <button type="submit" class="btn primary"><i class="fas fa-floppy-disk"></i> حفظ العلاج</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <script src="../assets/js/patient-search.js"></script>
    <script>
    /* ================= مخطط الأسنان (design/dental-chart.html) ================= */
    (function () {
        const root = document.getElementById('dentalChart');
        const svg = document.getElementById('dcSvg');

        /* عرض التاج بالمليمتر (تقريبي) لكل موضع */
        const WIDTH = {
            perm: { u: { 1: 8.5, 2: 6.5, 3: 7.5, 4: 7, 5: 6.5, 6: 10, 7: 9, 8: 8.5 }, l: { 1: 5.3, 2: 5.9, 3: 6.9, 4: 7, 5: 7.1, 6: 11, 7: 10.5, 8: 10 } },
            prim: { u: { 1: 6.5, 2: 5.1, 3: 7, 4: 7.3, 5: 8.2 }, l: { 1: 4.2, 2: 4.7, 3: 5, 4: 7.7, 5: 9.9 } }
        };
        const TYPE = { perm: { 1: 'inc', 2: 'inc', 3: 'can', 4: 'pre', 5: 'pre', 6: 'mol', 7: 'mol', 8: 'mol' },
                       prim: { 1: 'inc', 2: 'inc', 3: 'can', 4: 'mol', 5: 'mol' } };
        const NAME = {
            perm: { 1: 'القاطعة المركزية', 2: 'القاطعة الجانبية', 3: 'الناب', 4: 'الضاحك الأول', 5: 'الضاحك الثاني', 6: 'الرحى الأولى', 7: 'الرحى الثانية', 8: 'الرحى الثالثة (العقل)' },
            prim: { 1: 'القاطعة المركزية اللبنية', 2: 'القاطعة الجانبية اللبنية', 3: 'الناب اللبني', 4: 'الرحى اللبنية الأولى', 5: 'الرحى اللبنية الثانية' }
        };
        const QNAME = { 1: 'علوي أيمن', 2: 'علوي أيسر', 3: 'سفلي أيسر', 4: 'سفلي أيمن' };
        /* تسلسل الأسنان في كل ربع حسب نوع الإطباق */
        const SEQ = {
            perm: [1, 2, 3, 4, 5, 6, 7, 8].map(p => ({ k: 'perm', p })),
            prim: [1, 2, 3, 4, 5].map(p => ({ k: 'prim', p })),
            mixed: [{ k: 'perm', p: 1 }, { k: 'perm', p: 2 }, { k: 'prim', p: 3 }, { k: 'prim', p: 4 }, { k: 'prim', p: 5 }, { k: 'perm', p: 6 }]
        };
        const ARCH = { perm: { u: [285, 215], l: [272, 205] }, mixed: { u: [258, 188], l: [246, 180] }, prim: { u: [205, 145], l: [196, 138] } };

        let set = 'perm';
        let readonly = false;
        const selected = new Set();
        let teeth = []; // {n, q}

        const fdi = (q, k, p) => (k === 'prim' ? q + 4 : q) * 10 + p;
        const baseQ = n => { const q = Math.floor(n / 10); return q > 4 ? q - 4 : q; };
        const info = n => {
            const q = Math.floor(n / 10), k = q > 4 ? 'prim' : 'perm', p = n % 10;
            return { name: NAME[k][p] || ('السن ' + n), loc: QNAME[baseQ(n)] || '', k };
        };

        /* أشكال التيجان (منظر إطباقي) — المحور y السالب = الجهة الدهليزية */
        const f = v => v.toFixed(1);
        function rrect(hw, hd, r) {
            return `M${f(-hw + r)} ${f(-hd)}H${f(hw - r)}Q${f(hw)} ${f(-hd)} ${f(hw)} ${f(-hd + r)}V${f(hd - r)}Q${f(hw)} ${f(hd)} ${f(hw - r)} ${f(hd)}H${f(-hw + r)}Q${f(-hw)} ${f(hd)} ${f(-hw)} ${f(hd - r)}V${f(-hd + r)}Q${f(-hw)} ${f(-hd)} ${f(-hw + r)} ${f(-hd)}Z`;
        }
        function shape(type, w, d) {
            const hw = w / 2, hd = d / 2;
            if (type === 'inc') return {
                crown: `M${f(-hw)} ${f(-hd * .3)}C${f(-hw)} ${f(-hd * 1.08)} ${f(hw)} ${f(-hd * 1.08)} ${f(hw)} ${f(-hd * .3)}C${f(hw)} ${f(hd * .35)} ${f(hw * .42)} ${f(hd)} 0 ${f(hd)}C${f(-hw * .42)} ${f(hd)} ${f(-hw)} ${f(hd * .35)} ${f(-hw)} ${f(-hd * .3)}Z`,
                groove: `M${f(-hw * .68)} ${f(-hd * .42)}Q0 ${f(-hd * .62)} ${f(hw * .68)} ${f(-hd * .42)}M0 ${f(hd * .25)}v${f(hd * .35)}` };
            if (type === 'can') return {
                crown: `M0 ${f(-hd)}C${f(hw * .8)} ${f(-hd)} ${f(hw)} ${f(-hd * .25)} ${f(hw * .88)} ${f(hd * .25)}C${f(hw * .62)} ${f(hd * .95)} ${f(-hw * .62)} ${f(hd * .95)} ${f(-hw * .88)} ${f(hd * .25)}C${f(-hw)} ${f(-hd * .25)} ${f(-hw * .8)} ${f(-hd)} 0 ${f(-hd)}Z`,
                groove: `M${f(-hw * .55)} ${f(-hd * .05)}L0 ${f(-hd * .55)}L${f(hw * .55)} ${f(-hd * .05)}M0 ${f(-hd * .55)}V${f(hd * .45)}` };
            if (type === 'pre') return {
                crown: rrect(hw, hd, Math.min(hw, hd) * .85),
                groove: `M${f(-hw * .55)} ${f(hd * .02)}Q0 ${f(hd * .16)} ${f(hw * .55)} ${f(hd * .02)}` };
            return { /* mol */
                crown: rrect(hw, hd, Math.min(hw, hd) * .5),
                groove: `M${f(-hw * .66)} ${f(hd * .08)}C${f(-hw * .2)} ${f(-hd * .12)} ${f(hw * .2)} ${f(hd * .18)} ${f(hw * .66)} ${f(-hd * .06)}M${f(-hw * .06)} ${f(-hd * .68)}C${f(hw * .12)} ${f(-hd * .2)} ${f(-hw * .12)} ${f(hd * .22)} ${f(hw * .06)} ${f(hd * .68)}` };
        }
        const DEPTH = { inc: { u: .92, l: 1.15 }, can: { u: 1.1, l: 1.1 }, pre: { u: 1.28, l: 1.12 }, mol: { u: 1.08, l: 1.0 } };

        /* القوس السني: نصف قطع ناقص نوزّع عليه الأسنان حسب عرضها */
        function arcTable(a, b) {
            const N = 360, th = [0], s = [0];
            let px = 0, py = 0;
            for (let i = 1; i <= N; i++) {
                const t = (Math.PI / 2) * i / N, x = a * Math.sin(t), y = b * (1 - Math.cos(t));
                s.push(s[i - 1] + Math.hypot(x - px, y - py)); th.push(t); px = x; py = y;
            }
            return { th, s, L: s[N] };
        }
        function thetaAt(tab, len) {
            let i = 1;
            while (i < tab.s.length - 1 && tab.s[i] < len) i++;
            const r = (len - tab.s[i - 1]) / (tab.s[i] - tab.s[i - 1] || 1);
            return tab.th[i - 1] + r * (tab.th[i] - tab.th[i - 1]);
        }

        function render() {
            const seq = SEQ[set], cx = 380, mid = 350, gap = 38;
            let html = '', idx = 0;
            teeth = [];
            ['u', 'l'].forEach(jaw => {
                const [a, b] = ARCH[set][jaw];
                const tab = arcTable(a, b);
                const up = jaw === 'u';
                const apexY = up ? mid - gap - b : mid + gap + b;
                const widths = seq.map(t => WIDTH[t.k][jaw][t.p]);
                const g = 1.6, sum = widths.reduce((x, y) => x + y, 0);
                const scale = (tab.L * .985 - g * (seq.length - .5)) / sum;

                // اللثة
                const gumPts = [];
                for (let i = 0; i <= 40; i++) { const t = (Math.PI / 2) * i / 40; gumPts.push([a * Math.sin(t), b * (1 - Math.cos(t))]); }
                const pts = [...gumPts.slice().reverse().map(([x, y]) => [cx - x, y]), ...gumPts.slice(1).map(([x, y]) => [cx + x, y])]
                    .map(([x, y]) => `${f(x)},${f(up ? apexY + y : apexY - y)}`).join(' ');
                html += `<polyline class="gum" points="${pts}" stroke-width="${f(Math.min(scale, 7) * 9)}"/>`;

                [-1, 1].forEach(sx => {
                    // sx=-1 : يسار الشاشة = يمين المريض (الربع 1 علوياً، 4 سفلياً)
                    const q = up ? (sx < 0 ? 1 : 2) : (sx < 0 ? 4 : 3);
                    let run = g / 2;
                    seq.forEach((t, i) => {
                        const w = widths[i] * scale, type = TYPE[t.k][t.p];
                        const d = w * DEPTH[type][jaw];
                        const th = thetaAt(tab, run + w / 2);
                        run += w + g;
                        const x = cx + sx * a * Math.sin(th);
                        const y = up ? apexY + b * (1 - Math.cos(th)) : apexY - b * (1 - Math.cos(th));
                        const phi = Math.atan2(b * Math.sin(th), a * Math.cos(th)) * 180 / Math.PI;
                        const sy = up ? 1 : -1;
                        const rad = phi * Math.PI / 180;
                        const nx = sx * Math.sin(rad), ny = -sy * Math.cos(rad);
                        const off = d / 2 + 13;
                        const n = fdi(q, t.k, t.p), sh = shape(type, w, d), inf = info(n);
                        teeth.push({ n, q });
                        html += `<g class="tooth ${t.k === 'prim' ? 'prim' : ''} ${selected.has(n) ? 'on' : ''}" data-n="${n}" tabindex="${readonly ? -1 : 0}" role="checkbox"
                                   aria-checked="${selected.has(n)}" aria-label="${n} ${inf.name} ${inf.loc}" style="--i:${idx++}">
                                   <title>${n} — ${inf.name} (${inf.loc})</title>
                                   <g transform="translate(${f(x)} ${f(y)}) scale(${sx} ${sy}) rotate(${f(phi)})">
                                     <path class="crown" d="${sh.crown}"/><path class="groove" d="${sh.groove}"/>
                                   </g>
                                   <text class="num" x="${f(x + nx * off)}" y="${f(y + ny * off)}">${n}</text>
                                 </g>`;
                    });
                });
            });

            // خط المنتصف والتسميات
            html = `<line class="mid" x1="380" y1="20" x2="380" y2="680"/>
                    <text class="side" x="18" y="${mid + 4}">يمين المريض</text>
                    <text class="side" x="742" y="${mid + 4}" text-anchor="end">يسار المريض</text>
                    <text class="jaw" x="380" y="${mid - 8}">الفك العلوي</text>
                    <text class="jaw" x="380" y="${mid + 20}">الفك السفلي</text>` + html;
            svg.innerHTML = html;

            // إبقاء المحدد الموجود فقط في الإطباق الحالي (عدا عرض علاج محفوظ)
            if (!readonly) {
                const valid = new Set(teeth.map(t => t.n));
                [...selected].forEach(n => { if (!valid.has(n)) selected.delete(n); });
            }
            renderQuads();
            sync();
        }

        function renderQuads() {
            const order = [1, 2, 4, 3]; // يطابق ترتيب المخطط بصرياً
            document.getElementById('dcQuads').innerHTML = order.map(q => {
                const ns = teeth.filter(t => t.q === q).map(t => t.n);
                const sorted = [...ns].sort((x, y) => x - y), runs = [];
                sorted.forEach(n => { const r = runs[runs.length - 1]; (r && n === r[1] + 1) ? r[1] = n : runs.push([n, n]); });
                const range = runs.map(([x, y]) => x === y ? `${x}` : `${x}-${y}`).join(', ');
                return `<button type="button" class="quad" data-q="${q}" dir="rtl">
                          <span><b>الربع ${q} <bdi dir="ltr">(${range})</bdi></b><small>${QNAME[q]}</small></span>
                          <span class="cnt" data-cnt="${q}">0/${ns.length}</span></button>`;
            }).join('');
        }

        function sync() {
            svg.querySelectorAll('.tooth').forEach(g => {
                const on = selected.has(+g.dataset.n);
                g.classList.toggle('on', on);
                g.setAttribute('aria-checked', on);
            });
            [1, 2, 3, 4].forEach(q => {
                const ns = teeth.filter(t => t.q === q).map(t => t.n);
                const c = ns.filter(n => selected.has(n)).length;
                const el = document.querySelector(`[data-cnt="${q}"]`);
                if (el) { el.textContent = `${c}/${ns.length}`; el.parentElement.classList.toggle('full', c === ns.length); }
            });
            const list = [...selected].sort((a, b) => a - b);
            document.getElementById('dcCount').textContent = list.length;
            const ul = document.getElementById('dcList');
            ul.innerHTML = list.length ? list.map(n => {
                const i = info(n);
                return `<li><span class="n ${i.k === 'prim' ? 'm' : ''}">${n}</span>
                        <span class="t">${i.name}<small>${i.loc}${i.k === 'prim' ? '، لبني' : ''}</small></span>
                        <button type="button" class="chip-x" data-rm="${n}" aria-label="إزالة السن ${n}">×</button></li>`;
            }).join('') : `<p class="empty">${readonly ? 'لم تُسجَّل أسنان لهذا العلاج.' : 'اضغط على أي سن في المخطط لإضافته، أو اختر ربعاً كاملاً من الأزرار أسفل المخطط.'}</p>`;
            root.dispatchEvent(new CustomEvent('teeth-change', { detail: { dentition: set, teeth: list } }));
        }

        function toggleGroup(ns) {
            const all = ns.every(n => selected.has(n));
            ns.forEach(n => all ? selected.delete(n) : selected.add(n));
            sync();
        }

        svg.addEventListener('click', e => {
            const g = e.target.closest('.tooth');
            if (!g || readonly) return;
            const n = +g.dataset.n;
            selected.has(n) ? selected.delete(n) : selected.add(n);
            sync();
        });
        svg.addEventListener('keydown', e => {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('tooth')) {
                e.preventDefault();
                e.target.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            }
        });
        document.getElementById('dcQuads').addEventListener('click', e => {
            const b = e.target.closest('.quad');
            if (b && !readonly) toggleGroup(teeth.filter(t => t.q === +b.dataset.q).map(t => t.n));
        });
        root.querySelector('.actions').addEventListener('click', e => {
            const a = e.target.closest('[data-act]')?.dataset.act;
            if (!a || readonly) return;
            if (a === 'clear') { selected.clear(); sync(); return; }
            const pick = a === 'all' ? teeth : teeth.filter(t => a === 'upper' ? t.q <= 2 : t.q >= 3);
            pick.forEach(t => selected.add(t.n));
            sync();
        });
        document.getElementById('dcList').addEventListener('click', e => {
            const n = e.target.dataset.rm;
            if (n && !readonly) { selected.delete(+n); sync(); }
        });

        /* واجهة الدمج مع باقي الصفحة */
        window.DentalChart = {
            get: () => [...selected].sort((a, b) => a - b),
            set: arr => { selected.clear(); arr.forEach(n => selected.add(+n)); render(); },
            dentition: t => { set = t; render(); },
            readonly: v => { readonly = !!v; root.classList.toggle('is-readonly', readonly); render(); }
        };
        render();
    })();

    /* ================= صفحة العلاج (design/new-treatment.html) ================= */
    (function () {
        const DATA = <?= json_encode($page_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const RO = DATA.mode === 'complete'; // إكمال علاج محفوظ: البيانات للعرض والمراحل قابلة للتقدم
        const init = DATA.initial;
        const $ = s => document.querySelector(s);
        const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const money = n => Math.round(n).toLocaleString('en-US') + ' ل.س';
        const pad = n => String(n).padStart(2, '0');
        const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const DENT_NAMES = { perm: 'أسنان دائمة', mixed: 'مختلطة', prim: 'أسنان لبنية' };

        let type = null;
        let stages = [];
        let costManual = false;
        let detailsDirty = false;

        /* ---------- المريض والموعد ---------- */
        const patientSelect = $('#patientSelect');
        const search = PatientSearch.attach(patientSelect, { inputId: 'patientSearch', placeholder: 'ابحث بالاسم أو رقم الهاتف', inputClass: 'field' });
        const patientById = id => DATA.patients.find(p => String(p.id) === String(id));

        function showPatient(autoDentition) {
            const p = patientById(patientSelect.value);
            const chip = $('#patientChip'), alert = $('#medicalAlert');
            $('#patientPick').hidden = !!p;
            chip.hidden = !p;
            if (p) {
                const meta = [p.phone, p.age !== null ? `العمر ${p.age} سنة` : ''].filter(Boolean).join('، ');
                chip.innerHTML = `<div class="av">${esc(p.name.trim()[0] || '؟')}</div>
                    <div class="t"><a href="patient_profile.php?id=${p.id}" target="_blank" rel="noopener">${esc(p.name)}</a><small>${esc(meta)}</small></div>
                    ${RO ? '' : '<button type="button" class="x" aria-label="تغيير المريض"><i class="fas fa-xmark"></i></button>'}`;
                chip.querySelector('.x')?.addEventListener('click', () => {
                    patientSelect.value = '';
                    patientSelect.dispatchEvent(new Event('change'));
                    search.input.focus();
                });
            }
            const alerts = p ? [p.allergies && `<b>الحساسية:</b> ${esc(p.allergies)}`, p.history && `<b>التاريخ المرضي:</b> ${esc(p.history)}`].filter(Boolean) : [];
            alert.hidden = !alerts.length;
            alert.innerHTML = alerts.length ? '<i class="fas fa-triangle-exclamation"></i> تنبيه طبي — ' + alerts.join(' · ') : '';
            fillAppointments(p ? p.id : null);
            if (p && autoDentition && p.age !== null) {
                // اقتراح نوع الإطباق حسب العمر
                const d = p.age < 6 ? 'prim' : p.age <= 12 ? 'mixed' : 'perm';
                setDentition(d);
                const note = $('#autoNote');
                note.textContent = `اختير «${DENT_NAMES[d]}» تلقائياً حسب عمر المريض، ويمكنك تغييره.`;
                note.classList.add('show');
            }
            summary();
        }
        patientSelect.addEventListener('change', () => showPatient(true));

        function fillAppointments(id) {
            const sel = $('#appointment'), list = id ? (DATA.appointments[id] || []) : null;
            sel.disabled = !id || RO;
            sel.innerHTML = !id ? '<option value="">اختر المريض أولاً</option>'
                : `<option value="">${list.length ? 'بدون ربط بموعد' : 'لا توجد مواعيد اليوم لهذا المريض'}</option>` +
                  list.map(a => `<option value="${a.id}">${esc(a.label)}</option>`).join('');
        }

        /* ---------- نوع الإطباق ---------- */
        function setDentition(d) {
            const radio = document.querySelector(`input[name="dentition_type"][value="${d}"]`);
            if (radio) radio.checked = true;
            window.DentalChart.dentition(d);
        }
        document.querySelectorAll('input[name="dentition_type"]').forEach(r => r.addEventListener('change', () => {
            window.DentalChart.dentition(r.value);
            $('#autoNote').classList.remove('show');
        }));
        document.getElementById('dentalChart').addEventListener('teeth-change', e => {
            $('#selectedTeeth').value = JSON.stringify(e.detail.teeth.map(String));
            calc();
        });

        /* ---------- نوع العلاج وخياراته ---------- */
        $('#types').innerHTML = DATA.types.map(t => `<button type="button" class="type" data-t="${esc(t.code)}" aria-pressed="false">
                <span class="ti"><i class="${esc(t.icon)}"></i></span><span><b>${esc(t.name)}</b><small>${esc(t.desc)}</small></span></button>`).join('');
        $('#types').addEventListener('click', e => { const b = e.target.closest('.type'); if (b && !RO) pickType(b.dataset.t); });

        function pickType(code, optionIds) {
            type = DATA.types.find(t => t.code === code) || null;
            $('#treatmentType').value = code || '';
            document.querySelectorAll('.type').forEach(b => b.setAttribute('aria-pressed', b.dataset.t === code));
            if (!type) {
                // نوع محفوظ لم يعد ضمن الأنواع النشطة
                $('#optsTitle').textContent = 'خيارات العلاج';
                $('#opts').innerHTML = `<p class="empty">${esc(init.typeName || code || '')}</p>`;
                $('#optsAside').textContent = '';
                calc();
                return;
            }
            $('#optsTitle').textContent = `خيارات ${type.name}`;
            $('#optsAside').textContent = RO ? '' : 'يمكن اختيار أكثر من خيار';
            const chosen = optionIds || (type.options[0] ? [type.options[0].id] : []);
            $('#opts').innerHTML = type.options.length ? type.options.map(o => `<label class="opt${RO ? ' is-readonly' : ''}">
                    <input type="checkbox" value="${o.id}" ${chosen.includes(o.id) ? 'checked' : ''} ${RO ? 'disabled' : ''}>
                    <span class="t">${esc(o.name)}${o.desc ? `<small>${esc(o.desc)}</small>` : ''}</span>
                    <span class="p">${o.price > 0 ? money(o.price) : '—'}<small>${o.price > 0 ? 'سعر الخيار' : 'حسب الحالة'}</small></span></label>`).join('')
                : '<p class="empty">لا توجد خيارات مسجلة لهذا النوع.</p>';
            if (RO && !chosen.length) $('#opts').innerHTML = '<p class="empty">لم تُحدَّد خيارات لهذا العلاج.</p>';
            onOptionsChange(!optionIds);
        }
        $('#opts').addEventListener('change', () => onOptionsChange(true));

        const selectedOptions = () => type ? [...document.querySelectorAll('#opts input:checked')].map(i => type.options.find(o => o.id === +i.value)).filter(Boolean) : [];

        function onOptionsChange(fromUser) {
            const opts = selectedOptions();
            $('#optionIds').value = JSON.stringify(opts.map(o => o.id));
            // تفاصيل العلاج = أسماء الخيارات المختارة ما لم يعدّلها الطبيب
            if (!RO && !detailsDirty) $('#details').value = opts.map(o => o.name).join('، ');
            if (!RO && fromUser) buildStages();
            calc();
        }
        $('#details').addEventListener('input', () => { detailsDirty = true; });

        /* ---------- التكلفة ---------- */
        const costInput = $('#costInput');
        function autoCost() {
            const sub = selectedOptions().reduce((a, o) => a + o.price, 0);
            const disc = Math.min(100, Math.max(0, +$('#discount').value || 0));
            return { sub, disc, total: sub * (1 - disc / 100) };
        }
        function calc() {
            const lines = $('#costLines'), n = window.DentalChart.get().length;
            const { sub, disc, total } = autoCost();
            if (!type && !RO) {
                lines.innerHTML = '<li class="muted">اختر نوع العلاج لعرض التكلفة.</li>';
            } else {
                const opts = selectedOptions();
                lines.innerHTML = `<li class="muted"><span>عدد الأسنان المحددة</span><span>${n}</span></li>` +
                    (opts.map(o => `<li><span>${esc(o.name)}</span><span>${o.price > 0 ? money(o.price) : '—'}</span></li>`).join('') || (RO ? '' : '<li class="muted">لم يُختر أي خيار بعد.</li>')) +
                    (disc && sub && !RO ? `<li class="muted"><span>الخصم ${disc}%</span><span>− ${money(sub * disc / 100)}</span></li>` : '');
                if (!costManual && !RO) costInput.value = total > 0 ? Math.round(total) : '';
            }
            const autoBtn = $('#costAuto');
            autoBtn.hidden = RO || !costManual || total <= 0;
            autoBtn.textContent = `احتساب تلقائي (${money(total)})`;
            $('#costHint').textContent = RO ? '' : (type && selectedOptions().length && sub === 0 ? 'لا يوجد سعر محدد لهذه الخيارات، أدخل التكلفة يدوياً.' : (costManual ? 'تكلفة معدّلة يدوياً.' : ''));
            summary();
        }
        $('#discount').addEventListener('input', calc);
        costInput.addEventListener('input', () => { costManual = costInput.value !== ''; calc(); });
        $('#costAuto').addEventListener('click', () => { costManual = false; calc(); });

        /* ---------- المراحل ---------- */
        // دقائق تقريبية من نص المدة ("15 دقيقة"، "ساعة"، "1-2 ساعة")؛ المدد بالأيام والأشهر لا تُجمع
        function minutesOf(text) {
            const t = String(text || '').replace(/[٠-٩]/g, d => d.charCodeAt(0) - 0x0660);
            if (/يوم|أيام|أسبوع|أسابيع|شهر|أشهر|جلس/.test(t)) return null;
            const n = parseFloat((t.match(/\d+(\.\d+)?/) || [])[0]);
            if (/ساعت(ان|ين)/.test(t)) return 120;
            if (/ساع/.test(t)) return (n || 1) * 60;
            return n || null;
        }
        function buildStages() {
            const prev = new Map(stages.map(s => [s.key, s]));
            stages = selectedOptions().flatMap(o => o.stages.map((st, i) => {
                const key = o.id + ':' + i, old = prev.get(key);
                return { key, option_id: o.id, optName: o.name, title: st.title, description: st.desc, duration: st.duration,
                         completed: old ? old.completed : false, completedDate: old ? old.completedDate : null, notes: old ? old.notes : '' };
            }));
            renderStages();
        }
        function renderStages() {
            const ol = $('#stages');
            const current = stages.findIndex(s => !s.completed);
            const multi = new Set(stages.map(s => s.option_id)).size > 1;
            if (!stages.length) {
                const opts = selectedOptions();
                ol.innerHTML = `<li class="stages-empty">${RO ? 'لا توجد مراحل مسجلة لهذا العلاج، يمكنك إكماله مباشرة.'
                    : !type || !opts.length ? 'تظهر مراحل العلاج بعد اختيار نوعه وخياراته.'
                    : 'لا توجد مراحل محددة للخيارات المختارة. يمكنك حفظ العلاج بدونها، أو إضافة مراحل من <a href="treatment_stages_management.php">إدارة مراحل العلاج</a>.'}</li>`;
            } else {
                ol.innerHTML = stages.map((s, i) => {
                    const st = s.completed ? 'done' : i === current ? 'current' : '';
                    const badge = s.completed ? (s.skipped ? 'لم تُنفَّذ' : 'مكتملة') : i === current ? 'جارية' : 'بالانتظار';
                    return `<li class="stage ${st}">
                        <div class="stage-top"><span class="no">${s.completed ? '<i class="fas fa-check"></i>' : i + 1}</span>
                          <div style="flex:1"><h4>${esc(s.title)}</h4>${s.description ? `<p>${esc(s.description)}</p>` : ''}${multi && s.optName ? `<span class="opt-tag">${esc(s.optName)}</span>` : ''}</div></div>
                        <div class="meta"><span>${s.duration ? `<i class="far fa-clock"></i> ${esc(s.duration)}` : ''}${s.completed && s.completedDate ? ` · <span class="edsm-num">${esc(s.completedDate)}</span>` : ''}</span><span class="badge">${badge}</span></div>
                        <div class="done-note ${s.notes ? 'has' : ''}">${esc(s.notes)}</div>
                        <div class="work-area">
                          <textarea class="field" data-note="${i}" placeholder="ملاحظات هذه المرحلة (اختياري)">${esc(s.notes)}</textarea>
                          <div class="btn-row">
                            ${i > 0 && stages[i - 1].completed ? `<button type="button" class="btn sm" data-back="${i}">رجوع</button>` : ''}
                            <button type="button" class="btn primary sm" data-done="${i}"><i class="fas fa-check"></i> إكمال المرحلة</button>
                          </div>
                        </div></li>`;
                }).join('');
            }
            const done = stages.filter(s => s.completed).length, total = stages.length;
            $('#progBar').style.width = total ? (done / total * 100) + '%' : 0;
            $('#progText').textContent = total ? (done === total ? 'اكتملت جميع المراحل' : `${done} من ${total} مراحل`) : '';
            const remaining = stages.filter(s => !s.completed).map(s => minutesOf(s.duration)).filter(Boolean).reduce((a, m) => a + m, 0);
            $('#progTime').textContent = remaining ? `المتبقي ≈ ${remaining} دقيقة` : '';
            const all = stages.map(s => minutesOf(s.duration)).filter(Boolean).reduce((a, m) => a + m, 0);
            $('#durationOut').textContent = all ? `≈ ${all} دقيقة` : '—';
            syncStages();
            summary();
        }
        function syncStages() {
            $('#stagesJson').value = JSON.stringify(stages.map(s => ({
                title_ar: s.title, description_ar: s.description, duration_ar: s.duration, option_id: s.option_id || null,
                completed: s.completed, completedDate: s.completedDate, notes: s.notes, skipped: s.skipped || undefined
            })));
        }
        $('#stages').addEventListener('input', e => {
            const i = e.target.dataset.note;
            if (i !== undefined) { stages[i].notes = e.target.value; syncStages(); }
        });
        $('#stages').addEventListener('click', e => {
            const d = e.target.closest('[data-done]'), b = e.target.closest('[data-back]');
            if (d) {
                const s = stages[+d.dataset.done];
                s.completed = true;
                s.completedDate = DATA.today;
                renderStages();
            }
            if (b) {
                const s = stages[+b.dataset.back - 1];
                s.completed = false;
                s.completedDate = null;
                s.skipped = false;
                renderStages();
            }
        });

        /* ---------- التاريخ والمتابعة ---------- */
        const fDate = $('#fDate');
        function applyPeriod() {
            const r = document.querySelector('input[name="follow_up_period"]:checked');
            if (!r || !fDate) return;
            const m = +r.value;
            if (!m) { $('#fHint').textContent = fDate.value ? 'موعد متابعة محدد يدوياً.' : 'اختر مدة أعلاه أو حدد تاريخاً بنفسك.'; return; }
            const d = $('#tDate').value ? new Date($('#tDate').value + 'T00:00') : new Date();
            d.setMonth(d.getMonth() + m);
            fDate.value = iso(d);
            $('#fHint').textContent = `بعد ${m} أشهر من تاريخ العلاج، ويمكنك تعديله.`;
        }
        document.querySelectorAll('input[name="follow_up_period"]').forEach(r => r.addEventListener('change', () => {
            if (!+r.value && fDate) fDate.value = '';
            applyPeriod();
        }));
        $('#tDate').addEventListener('change', applyPeriod);
        fDate?.addEventListener('change', () => { $('#fHint').textContent = fDate.value ? 'موعد متابعة محدد.' : 'اختر مدة أعلاه أو حدد تاريخاً بنفسك.'; });

        /* ---------- الملخص والحفظ ---------- */
        function summary() {
            const p = patientById(patientSelect.value);
            $('#saveSum').innerHTML = `<span>المريض: <b>${p ? esc(p.name) : '—'}</b></span>
                <span>العلاج: <b>${type ? esc(type.name) : esc(init.typeName || '—')}</b></span>
                <span>الأسنان: <b>${window.DentalChart.get().length}</b></span>
                <span>الإجمالي: <b>${costInput.value !== '' ? money(+costInput.value) : '—'}</b></span>`;
        }
        function showError(text) {
            $('#saveSum').innerHTML = `<span class="err"><i class="fas fa-circle-exclamation"></i> ${text}</span>`;
        }

        $('#treatmentForm').addEventListener('submit', e => {
            syncStages();
            if (!RO) {
                const missing = [];
                if (!patientSelect.value) missing.push('المريض');
                if (!window.DentalChart.get().length) missing.push('الأسنان');
                if (!type) missing.push('نوع العلاج');
                if (!$('#tDate').value) missing.push('تاريخ العلاج');
                if (missing.length) { e.preventDefault(); showError('أكمل قبل الحفظ: ' + missing.join('، ')); }
                return;
            }
            // إنهاء العلاج: المراحل غير المنفَّذة تحتاج تأكيد الطبيب
            const pending = stages.filter(s => !s.completed);
            if (pending.length && !confirm('لم تكتمل المراحل التالية:\n' + pending.map(s => '• ' + s.title).join('\n') +
                                            '\n\nهل تريد إنهاء العلاج على أي حال؟\nستُسجَّل هذه المراحل على أنها "لم تُنفَّذ".')) {
                e.preventDefault();
                return;
            }
            $('#forceComplete').value = pending.length ? '1' : '';
        });

        $('#saveProgressBtn')?.addEventListener('click', async () => {
            const btn = $('#saveProgressBtn');
            syncStages();
            const fd = new FormData($('#treatmentForm'));
            fd.set('action', 'save_progress');
            fd.set('ajax', '1');
            btn.disabled = true;
            try {
                const res = await (await fetch(location.href, { method: 'POST', body: fd })).json();
                $('#saveSum').innerHTML = res.ok ? `<span style="color: var(--accent); font-weight: 700;"><i class="fas fa-check-circle"></i> ${esc(res.message)}</span>` : '';
                if (!res.ok) showError(esc(res.message));
            } catch (err) {
                showError('تعذّر حفظ التقدم، تحقق من الاتصال وحاول مجدداً');
            }
            btn.disabled = false;
            setTimeout(summary, 4000);
        });

        /* ---------- الحالة الأولى ---------- */
        $('#tDate').value = init.date || DATA.today;
        Object.entries(init.fields || {}).forEach(([name, value]) => {
            const el = document.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        });
        detailsDirty = !!(init.fields && init.fields.treatment_details);
        if (['symptoms', 'diagnosis', 'medications', 'notes'].some(k => (init.fields || {})[k])) $('#moreDetails').open = true;

        if (init.patientId && patientById(init.patientId)) {
            patientSelect.value = String(init.patientId);
            search.sync();
            showPatient(!RO && !init.dentition && !init.teeth.length);
            if (init.appointmentId) $('#appointment').value = String(init.appointmentId);
        } else {
            showPatient(false);
        }

        // نوع الإطباق: المُرسل، أو المستنتج من الأسنان المحفوظة
        let dent = init.dentition;
        if (!dent && init.teeth.length) {
            const nums = init.teeth.map(Number), prim = nums.filter(n => n >= 51).length;
            dent = !prim ? 'perm' : prim === nums.length ? 'prim' : 'mixed';
        }
        if (dent) setDentition(dent);
        if (RO) window.DentalChart.readonly(true);
        window.DentalChart.set(init.teeth);

        if (init.cost !== null && init.cost !== '') { costInput.value = Math.round(+init.cost * 100) / 100; costManual = true; }
        $('#discount').value = init.discount || 0;
        if (init.type) pickType(init.type, init.options || []);
        if (init.stages && init.stages.length) {
            stages = init.stages.map((s, i) => ({ key: (s.option_id || 'saved') + ':' + i, option_id: s.option_id || null,
                optName: (type?.options.find(o => o.id === s.option_id) || {}).name || '',
                title: s.title_ar || s.title, description: s.description_ar || s.description || '', duration: s.duration_ar || s.duration || '',
                completed: !!s.completed, completedDate: s.completedDate || null, notes: s.notes || '', skipped: !!s.skipped }));
        }
        renderStages();

        if (init.followup && fDate) {
            const r = document.querySelector(`input[name="follow_up_period"][value="${init.followup.period}"]`);
            if (r) r.checked = true;
            fDate.value = init.followup.date || '';
            $('#fPriority').value = init.followup.priority || 'normal';
            $('#fNotes').value = init.followup.notes || '';
        }

        if (RO) {
            // البيانات الأساسية للعرض فقط؛ تُحفظ المراحل وتفاصيل العلاج وملاحظات الإنهاء
            document.querySelectorAll('.type, input[name="dentition_type"], #discount, #costInput, #tDate, #symptoms, #diagnosis, #meds, #notes')
                .forEach(el => { el.disabled = true; });
        }
        calc();
    })();
    </script>
</body>
</html>
