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
// تاريخ اليوم من MySQL كما في لوحة التحكم وشارة القائمة (توقيت PHP قد يختلف عنه)
$today = $pdo->query("SELECT CURDATE()")->fetchColumn();

// رسائل ما بعد إعادة التوجيه (POST ثم GET)
$success_message = $_SESSION['followups_success'] ?? '';
$error_message = $_SESSION['followups_error'] ?? '';
unset($_SESSION['followups_success'], $_SESSION['followups_error']);

$followup_kinds = FOLLOWUP_KINDS;
// تصنيف المتابعات التي لا يطابق سببها الأنواع أعلاه (للتصفية)
$followup_groups = ['treatment' => 'متابعة بعد علاج', 'birthday' => 'عيد الميلاد', 'other' => 'أخرى'];
$status_options = FOLLOWUP_STATUS_LABELS;
$priority_labels = FOLLOWUP_PRIORITY_LABELS;

// نفس التصنيف في SQL (يجب أن يطابق followupKind)
$preset_placeholders = implode(', ', array_fill(0, count($followup_kinds), '?'));
$kind_case_sql = "(CASE WHEN f.follow_up_reason IN ($preset_placeholders) THEN f.follow_up_reason
                        WHEN f.treatment_id IS NOT NULL THEN 'treatment'
                        WHEN f.follow_up_type = 'birthday' THEN 'birthday'
                        ELSE 'other' END)";
$active_sql = "f.status IN ('pending', 'rescheduled')";

// طبيب المتابعة = created_by
try {
    $doctors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $doctors = [];
}

function validDate($value) {
    $date = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return $date && $date->format('Y-m-d') === $value;
}

// بيانات تعبئة نموذج "تحديث المتابعة"
function followupData(array $row) {
    $reason = trim((string)($row['follow_up_reason'] ?? ''));
    $slug = array_search($reason, FOLLOWUP_KINDS, true);
    return [
        'id' => (int)$row['id'],
        'patient' => $row['patient_name'],
        'phone' => $row['phone'] ?? '',
        'kind' => $slug !== false ? $slug : 'other',
        'reason' => $slug !== false ? '' : $reason,
        'doctor' => (int)$row['created_by'],
        'doctorName' => doctorLabel($row['doctor_name'] ?? '', $row['doctor_role'] ?? 'doctor'),
        'priority' => $row['priority'],
        'notes' => (string)($row['notes'] ?? ''),
        'date' => $row['follow_up_date'],
        'active' => in_array($row['status'], ['pending', 'rescheduled'], true),
    ];
}

function followupPayload(array $row) {
    return htmlspecialchars(json_encode(followupData($row), JSON_UNESCAPED_UNICODE));
}

function followupsUrl(array $changes = []) {
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== []);
    return 'follow_ups.php' . ($params ? '?' . http_build_query($params) : '');
}

// روابط لوحة التحكم القديمة (?filter=today|overdue) تتحول إلى فلاتر الصفحة
if (isset($_GET['filter'])) {
    $changes = ['filter' => null];
    if ($_GET['filter'] === 'today') {
        $changes['date'] = $today;
    } elseif ($_GET['filter'] === 'overdue') {
        $changes['status'] = ['overdue'];
    }
    header('Location: ' . followupsUrl($changes));
    exit;
}

// ---------- الإجراءات ----------
$form_state = null;
$form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $followup_id = (int)($_POST['followup_id'] ?? 0);
    $back_url = followupsUrl(['action' => null, 'patient_id' => null, 'edit' => null, 'back' => null]);
    $flash_key = 'followups';

    // القادم من ملف المريض (back=patient) يعود إليه بعد الحفظ
    if (($_GET['back'] ?? '') === 'patient') {
        $stmt = $pdo->prepare("SELECT patient_id FROM follow_ups WHERE id = ?");
        $stmt->execute([$followup_id]);
        $return_patient = (int)($stmt->fetchColumn() ?: ($_POST['patient_id'] ?? 0));
        if ($return_patient) {
            $back_url = 'patient_profile.php?id=' . $return_patient . '#followups';
            $flash_key = 'patient_profile';
        }
    }

    try {
        switch ($action) {
            case 'complete_followup':
            case 'cancel_followup':
                $completing = $action === 'complete_followup';
                $stmt = $pdo->prepare($completing
                    ? "UPDATE follow_ups SET status = 'completed', completed_at = NOW() WHERE id = ? AND status IN ('pending', 'rescheduled')"
                    : "UPDATE follow_ups SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'rescheduled')");
                $stmt->execute([$followup_id]);
                if ($stmt->rowCount()) {
                    $_SESSION[$flash_key . '_success'] = $completing ? 'تم إكمال المتابعة بنجاح' : 'تم إلغاء المتابعة بنجاح';
                } else {
                    $_SESSION[$flash_key . '_error'] = 'لا يمكن تعديل هذه المتابعة (غير موجودة أو ليست نشطة)';
                }
                header('Location: ' . $back_url);
                exit;

            case 'add_manual_followup':
            case 'update_followup':
                $is_update = $action === 'update_followup';
                $kind = $_POST['kind'] ?? '';
                $reason = $followup_kinds[$kind] ?? mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
                $date = $_POST['follow_up_date'] ?? '';
                $priority = isset($priority_labels[$_POST['priority'] ?? '']) ? $_POST['priority'] : 'normal';
                $notes = trim($_POST['notes'] ?? '');
                $assigned = (int)($_POST['doctor_id'] ?? 0);
                $patient_id = (int)($_POST['patient_id'] ?? 0);

                $current = null;
                if ($is_update) {
                    $stmt = $pdo->prepare("
                        SELECT f.id, f.follow_up_date, f.created_by, f.status, p.name AS patient_name, p.phone
                        FROM follow_ups f JOIN patients p ON f.patient_id = p.id
                        WHERE f.id = ?
                    ");
                    $stmt->execute([$followup_id]);
                    $current = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$current) {
                        $form_error = 'المتابعة غير موجودة';
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE id = ? AND status != 'deleted'");
                    $stmt->execute([$patient_id]);
                    if (!$stmt->fetchColumn()) {
                        $form_error = 'يرجى اختيار المريض';
                    }
                }

                if ($form_error === '') {
                    if ($reason === '') {
                        $form_error = $kind === 'other' ? 'يرجى كتابة سبب المتابعة' : 'يرجى اختيار نوع المتابعة';
                    } elseif (!validDate($date)) {
                        $form_error = 'يرجى اختيار تاريخ المتابعة من التقويم';
                    } elseif ($date < $today && !($current && $current['follow_up_date'] === $date)) {
                        $form_error = 'لا يمكن تحديد تاريخ متابعة في الماضي';
                    } elseif (!isset($doctors[$assigned]) && !($current && (int)$current['created_by'] === $assigned)) {
                        $form_error = 'يرجى اختيار طبيب المتابعة';
                    }
                }

                if ($form_error === '') {
                    if ($is_update) {
                        // المعاد جدولتها تعود "معلّقة" لتظهر في تنبيهات لوحة التحكم
                        $stmt = $pdo->prepare("
                            UPDATE follow_ups
                            SET follow_up_date = ?, follow_up_reason = ?, priority = ?, notes = ?, created_by = ?,
                                status = IF(status = 'rescheduled', 'pending', status)
                            WHERE id = ?
                        ");
                        $stmt->execute([$date, $reason, $priority, $notes, $assigned, $followup_id]);
                        $_SESSION[$flash_key . '_success'] = 'تم تحديث المتابعة بنجاح';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO follow_ups (patient_id, follow_up_type, follow_up_date, follow_up_reason, priority, notes, created_by)
                            VALUES (?, 'manual', ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$patient_id, $date, $reason, $priority, $notes, $assigned]);
                        $_SESSION[$flash_key . '_success'] = 'تم إضافة المتابعة بنجاح';
                    }
                    header('Location: ' . $back_url);
                    exit;
                }

                // إعادة عرض النموذج بالقيم المُدخلة
                $form_state = [
                    'mode' => $is_update && $current ? 'edit' : 'add',
                    'id' => $followup_id,
                    'patientId' => $patient_id,
                    'patient' => $current['patient_name'] ?? '',
                    'phone' => $current['phone'] ?? '',
                    'kind' => $kind,
                    'reason' => $_POST['reason'] ?? '',
                    'doctor' => $assigned,
                    'doctorName' => '',
                    'priority' => $priority,
                    'notes' => $notes,
                    'date' => validDate($date) ? $date : '',
                    'active' => $current ? in_array($current['status'], ['pending', 'rescheduled'], true) : false,
                ];
                break;
        }
    } catch (PDOException $e) {
        $error_message = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
    }
}

// ---------- الفلاتر ----------
$filter_status = array_values(array_intersect((array)($_GET['status'] ?? []), array_keys($status_options)));
if (!$filter_status) {
    // f=1 يعني أن نموذج التصفية أُرسل دون أي حالة = كل الحالات
    $filter_status = isset($_GET['f']) ? array_keys($status_options) : ['active', 'overdue'];
}
$filter_kind = array_values(array_intersect((array)($_GET['kind'] ?? []), array_merge(array_keys($followup_kinds), array_keys($followup_groups))));
$filter_doctor = (int)($_GET['doctor'] ?? 0);
$filter_date = validDate($_GET['date'] ?? '') ? $_GET['date'] : '';
$filter_patient = (int)($_GET['patient'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

$where = [];
$params = [];
$status_sql = [
    'active' => "($active_sql AND f.follow_up_date >= ?)",
    'overdue' => "($active_sql AND f.follow_up_date < ?)",
    'completed' => "f.status = 'completed'",
    'cancelled' => "f.status = 'cancelled'",
];
$status_parts = [];
foreach ($filter_status as $status) {
    $status_parts[] = $status_sql[$status];
    if ($status === 'active' || $status === 'overdue') {
        $params[] = $today;
    }
}
$where[] = '(' . implode(' OR ', $status_parts) . ')';

if ($filter_kind) {
    $kind_values = array_map(fn($k) => $followup_kinds[$k] ?? $k, $filter_kind);
    $where[] = "$kind_case_sql IN (" . implode(', ', array_fill(0, count($kind_values), '?')) . ")";
    array_push($params, ...array_values($followup_kinds), ...$kind_values);
}
if ($filter_doctor) {
    $where[] = 'f.created_by = ?';
    $params[] = $filter_doctor;
}
if ($filter_date) {
    $where[] = 'f.follow_up_date = ?';
    $params[] = $filter_date;
}
if ($filter_patient) {
    $where[] = 'f.patient_id = ?';
    $params[] = $filter_patient;
}
$where_sql = implode(' AND ', $where);

$select_sql = "
    SELECT f.*, p.name AS patient_name, p.phone, COALESCE(tt.name_ar, t.treatment_type) AS treatment_type,
           u.full_name AS doctor_name, u.role AS doctor_role,
           NULLIF(GREATEST(COALESCE(p.last_visit_date, '1000-01-01'),
                           COALESCE((SELECT MAX(tv.treatment_date) FROM treatments tv WHERE tv.patient_id = p.id), '1000-01-01')),
                  '1000-01-01') AS last_visit
    FROM follow_ups f
    JOIN patients p ON f.patient_id = p.id
    LEFT JOIN treatments t ON f.treatment_id = t.id
    LEFT JOIN treatment_types tt ON tt.code = t.treatment_type
    LEFT JOIN users u ON f.created_by = u.id";

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow_ups f WHERE $where_sql");
    $stmt->execute($params);
    $total_followups = (int)$stmt->fetchColumn();
    $total_pages = (int)ceil($total_followups / $per_page);
    $page = min($page, max(1, $total_pages));
    $offset = ($page - 1) * $per_page;

    // النشطة أولاً بالأقرب موعداً، ثم المنتهية بالأحدث
    $stmt = $pdo->prepare("$select_sql
        WHERE $where_sql
        ORDER BY ($active_sql) DESC,
                 CASE WHEN $active_sql THEN f.follow_up_date END ASC,
                 f.follow_up_date DESC, f.priority DESC, f.id DESC
        LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // متابعات اليوم
    $stmt = $pdo->prepare("$select_sql WHERE $active_sql AND f.follow_up_date = ? ORDER BY f.priority DESC, f.id LIMIT 6");
    $stmt->execute([$today]);
    $today_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow_ups f WHERE $active_sql AND f.follow_up_date = ?");
    $stmt->execute([$today]);
    $today_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow_ups f WHERE $active_sql AND f.follow_up_date < ?");
    $stmt->execute([$today]);
    $overdue_total = (int)$stmt->fetchColumn();

    // عدد المتابعات النشطة لكل يوم (لتلوين التقويم)
    $stmt = $pdo->prepare("
        SELECT f.follow_up_date, COUNT(*) FROM follow_ups f
        WHERE $active_sql AND f.follow_up_date BETWEEN DATE_SUB(?, INTERVAL 40 DAY) AND DATE_ADD(?, INTERVAL 400 DAY)
        GROUP BY f.follow_up_date
    ");
    $stmt->execute([$today, $today]);
    $calendar_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $patients = $pdo->query("SELECT id, name, phone, age FROM patients WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = $error_message ?: 'خطأ في جلب البيانات: ' . $e->getMessage();
    $followups = $today_followups = $patients = [];
    $calendar_counts = [];
    $total_followups = $total_pages = $today_total = $overdue_total = $offset = 0;
}

$preselected_patient = (int)($_GET['patient_id'] ?? 0);

try {
    // اسم المريض لشارة تصفية المريض
    $filter_patient_name = '';
    if ($filter_patient) {
        $stmt = $pdo->prepare("SELECT name FROM patients WHERE id = ?");
        $stmt->execute([$filter_patient]);
        $filter_patient_name = (string)$stmt->fetchColumn();
    }

    // فتح متابعة للتحديث مباشرة (?edit=ID، مثلاً من ملف المريض)
    $edit_id = (int)($_GET['edit'] ?? 0);
    if (!$form_state && $edit_id) {
        $stmt = $pdo->prepare("$select_sql WHERE f.id = ?");
        $stmt->execute([$edit_id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $form_state = ['mode' => 'edit'] + followupData($row);
        }
    }
} catch (PDOException $e) {
    $error_message = $error_message ?: 'خطأ في جلب البيانات: ' . $e->getMessage();
}

// Header configuration
$pageTitle = 'إدارة متابعة مريض';
$pageIcon = 'fas fa-user-clock';
$pageSubtitle = 'جدولة متابعات المرضى وتحديثها';
$currentPage = 'follow_ups';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المتابعات - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                <i class="fas fa-exclamation-triangle ml-1"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="edsm-booking-grid">
            <!-- العمود الأيمن: نموذج المتابعة + متابعات اليوم -->
            <div class="edsm-booking-col">
                <form method="POST" id="fuForm" class="edsm-card bk-form fade-in" novalidate>
                    <input type="hidden" name="action" id="fuAction" value="add_manual_followup">
                    <input type="hidden" name="followup_id" id="fuId" value="">
                    <input type="hidden" name="follow_up_date" id="fuDate" value="">

                    <div id="fuEditBanner" class="edsm-fu-editing" hidden>
                        <i class="fas fa-pen-to-square"></i>
                        <span>تحديث متابعة: <strong id="fuEditName"></strong></span>
                        <button type="button" id="fuEditClose" aria-label="إغلاق التحديث"><i class="fas fa-times"></i></button>
                    </div>
                    <div id="fuFormError" class="<?= $form_error ? '' : 'hidden ' ?>bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 rounded-lg mb-3"><?= htmlspecialchars($form_error) ?></div>

                    <div id="fuSearchBlock">
                        <label for="fuPatientSearch" class="edsm-label">بحث عن مريض</label>
                        <div class="edsm-search-navy">
                            <select name="patient_id" id="fuPatient" class="edsm-field">
                                <option value="">اختر المريض...</option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?= $patient['id'] ?>" data-name="<?= htmlspecialchars($patient['name']) ?>"
                                            data-phone="<?= htmlspecialchars($patient['phone'] ?? '') ?>" data-age="<?= htmlspecialchars($patient['age'] ?? '') ?>">
                                        <?= htmlspecialchars($patient['name']) ?> - <?= htmlspecialchars($patient['phone'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="edsm-label mt-4">اسم المريض</div>
                    <div id="fuPatientInfo" class="edsm-picked is-empty">لم يتم اختيار مريض بعد</div>

                    <label for="fuKind" class="edsm-label mt-4">نوع المتابعة</label>
                    <select name="kind" id="fuKind" class="edsm-field">
                        <option value="">اختر نوع المتابعة</option>
                        <?php foreach ($followup_kinds as $slug => $label): ?>
                            <option value="<?= $slug ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                        <option value="other">سبب آخر...</option>
                    </select>
                    <input type="text" name="reason" id="fuReason" class="edsm-field mt-2 hidden" maxlength="255" placeholder="اكتب سبب المتابعة">

                    <label for="fuDoctor" class="edsm-label mt-4">طبيب المتابعة</label>
                    <select name="doctor_id" id="fuDoctor" class="edsm-field">
                        <option value="">اختر الطبيب</option>
                        <?php foreach ($doctors as $id => $name): ?>
                            <option value="<?= $id ?>"><?= htmlspecialchars(doctorLabel($name)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fuPriority" class="edsm-label mt-4">الأولوية</label>
                    <select name="priority" id="fuPriority" class="edsm-field">
                        <?php foreach ($priority_labels as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fuNotes" class="edsm-label mt-4">ملاحظات المتابعة</label>
                    <textarea name="notes" id="fuNotes" rows="3" class="edsm-field" style="height: auto; padding: 10px 12px;" placeholder="ملاحظات (اختياري)"></textarea>

                    <div class="edsm-booking-summary mt-4" id="fuSummary">
                        <i class="far fa-calendar-check"></i>
                        <span>اختر تاريخ المتابعة من التقويم</span>
                    </div>
                    <div class="edsm-quick-dates mt-2">
                        <span>تحديد سريع:</span>
                        <button type="button" data-add="7d">بعد أسبوع</button>
                        <button type="button" data-add="1m">بعد شهر</button>
                        <button type="button" data-add="3m">بعد 3 أشهر</button>
                        <button type="button" data-add="6m">بعد 6 أشهر</button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-4">
                        <button type="submit" class="edsm-btn edsm-btn-navy edsm-btn-lg"><i class="fas fa-plus" id="fuSubmitIcon"></i> <span id="fuSubmitText">إضافة المتابعة</span></button>
                        <button type="button" id="fuReset" class="edsm-btn edsm-btn-sky edsm-btn-lg">إلغاء</button>
                    </div>
                    <div id="fuStatusActions" class="grid grid-cols-2 gap-3 mt-3 hidden">
                        <button type="button" class="edsm-btn edsm-btn-outline-green" data-status-action="complete_followup"><i class="fas fa-check"></i> إكمال المتابعة</button>
                        <button type="button" class="edsm-btn edsm-btn-outline-red" data-status-action="cancel_followup"><i class="fas fa-ban"></i> إلغاء هذه المتابعة</button>
                    </div>
                </form>

                <div class="edsm-card bk-today fade-in">
                    <div class="edsm-card-head" style="margin-bottom: 8px;">
                        <h3 class="edsm-card-title"><i class="far fa-calendar-alt"></i> متابعات اليوم</h3>
                        <a href="follow_ups.php?date=<?= $today ?>" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                    </div>
                    <?php if (empty($today_followups)): ?>
                        <p class="text-sm text-gray-500 py-3 text-center">لا توجد متابعات مجدولة اليوم</p>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($today_followups as $i => $row): ?>
                                <?php [, $kind_label] = followupKind($row); ?>
                                <button type="button" class="edsm-upcoming edsm-fu-item" data-followup-id="<?= (int)$row['id'] ?>" data-followup="<?= followupPayload($row) ?>" title="تحديث المتابعة">
                                    <span class="edsm-avatar-soft"><i class="far fa-calendar-alt"></i></span>
                                    <span class="flex-1 min-w-0">
                                        <span class="edsm-row-title block"><?= htmlspecialchars($row['patient_name']) ?></span>
                                        <span class="edsm-row-meta block truncate"><?= htmlspecialchars($kind_label) ?> · <?= htmlspecialchars(doctorLabel($row['doctor_name'], $row['doctor_role'])) ?></span>
                                    </span>
                                    <?php if ($row['priority'] === 'urgent' || $row['priority'] === 'high'): ?>
                                        <span class="edsm-tag <?= $row['priority'] === 'urgent' ? 'edsm-tag-danger' : 'edsm-tag-warning' ?>"><?= $priority_labels[$row['priority']] ?></span>
                                    <?php endif; ?>
                                    <span class="edsm-fu-index edsm-num"><?= $i + 1 ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($today_total > count($today_followups)): ?>
                            <a href="follow_ups.php?date=<?= $today ?>" class="edsm-link text-sm mt-1">و<span class="edsm-num"><?= $today_total - count($today_followups) ?></span> متابعات أخرى اليوم</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($overdue_total): ?>
                        <a href="follow_ups.php?status[]=overdue" class="edsm-fu-alert mt-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>يوجد <strong class="edsm-num"><?= $overdue_total ?></strong> متابعة متأخرة</span>
                            <i class="fas fa-chevron-left text-xs mr-auto"></i>
                        </a>
                    <?php endif; ?>
                    <a href="../nurse/patients.php?action=add" class="edsm-btn edsm-btn-pink edsm-btn-lg w-full mt-3"><i class="fas fa-user-plus"></i> إضافة مريض جديد</a>
                </div>
            </div>

            <!-- العمود الأيسر: التقويم + أدوات التصفية -->
            <div class="edsm-booking-col">
                <div class="edsm-card bk-cal fade-in">
                    <div class="edsm-bcal-head">
                        <button type="button" class="edsm-bcal-nav" id="fuPrevMonth" aria-label="الشهر السابق"><i class="fas fa-chevron-right"></i></button>
                        <strong id="fuMonthTitle"></strong>
                        <button type="button" class="edsm-bcal-nav" id="fuNextMonth" aria-label="الشهر التالي"><i class="fas fa-chevron-left"></i></button>
                    </div>
                    <div class="edsm-bcal-grid" id="fuCalendar"></div>
                    <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-500">
                        <span><span class="edsm-legend is-selected"></span> تاريخ المتابعة</span>
                        <span><span class="edsm-legend is-busy"></span> يوم فيه متابعات</span>
                    </div>
                </div>

                <div class="edsm-card bk-filters fade-in">
                    <h3 class="edsm-card-title" style="margin-bottom: 14px;"><i class="fas fa-sliders-h"></i> أدوات تصفية المتابعات</h3>
                    <form method="GET" id="fuFilters">
                        <input type="hidden" name="f" value="1">
                        <?php if ($filter_date): ?>
                            <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                        <?php endif; ?>
                        <?php if ($filter_patient): ?>
                            <input type="hidden" name="patient" value="<?= $filter_patient ?>">
                        <?php endif; ?>

                        <div class="edsm-label">الحالة</div>
                        <div class="edsm-multi" data-multi data-empty="كل الحالات">
                            <button type="button" class="edsm-field edsm-multi-toggle" aria-haspopup="true" aria-expanded="false"><span class="edsm-multi-text"></span></button>
                            <div class="edsm-multi-panel" hidden>
                                <?php foreach ($status_options as $value => $label): ?>
                                    <label class="edsm-multi-option">
                                        <input type="checkbox" name="status[]" value="<?= $value ?>" data-label="<?= $label ?>" <?= in_array($value, $filter_status, true) ? 'checked' : '' ?>>
                                        <?= $label ?>
                                    </label>
                                <?php endforeach; ?>
                                <button type="button" class="edsm-btn edsm-btn-navy w-full mt-1" data-apply>تطبيق</button>
                            </div>
                        </div>

                        <div class="edsm-label mt-4">نوع المتابعة</div>
                        <div class="edsm-multi" data-multi data-empty="كل الأنواع">
                            <button type="button" class="edsm-field edsm-multi-toggle" aria-haspopup="true" aria-expanded="false"><span class="edsm-multi-text"></span></button>
                            <div class="edsm-multi-panel" hidden>
                                <?php foreach (array_merge(array_map(fn($l) => preg_replace('/^متابعة\s+/u', '', $l), $followup_kinds), $followup_groups) as $value => $label): ?>
                                    <label class="edsm-multi-option">
                                        <input type="checkbox" name="kind[]" value="<?= $value ?>" data-label="<?= htmlspecialchars($label) ?>" <?= in_array($value, $filter_kind, true) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </label>
                                <?php endforeach; ?>
                                <button type="button" class="edsm-btn edsm-btn-navy w-full mt-1" data-apply>تطبيق</button>
                            </div>
                        </div>

                        <label for="fuDoctorFilter" class="edsm-label mt-4">الطبيب</label>
                        <select name="doctor" id="fuDoctorFilter" class="edsm-field">
                            <option value="">كل الأطباء</option>
                            <?php foreach ($doctors as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $filter_doctor === (int)$id ? 'selected' : '' ?>><?= htmlspecialchars(doctorLabel($name)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <!-- قائمة المتابعات -->
        <section class="edsm-card mt-6 fade-in">
            <div class="edsm-card-head">
                <h3 class="edsm-card-title">
                    <i class="fas fa-chart-bar"></i> قائمة المتابعات
                    <span class="text-sm text-gray-500 font-bold">(<span class="edsm-num"><?= number_format($total_followups) ?></span>)</span>
                </h3>
                <div class="flex flex-wrap items-center gap-3">
                    <?php if ($filter_patient): ?>
                        <a href="<?= htmlspecialchars(followupsUrl(['patient' => null, 'page' => null])) ?>" class="edsm-chip" title="إزالة تصفية المريض">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($filter_patient_name ?: 'مريض') ?>
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($filter_date): ?>
                        <a href="<?= htmlspecialchars(followupsUrl(['date' => null, 'page' => null])) ?>" class="edsm-chip" title="إزالة تصفية التاريخ">
                            <?= $filter_date === $today ? 'اليوم' : '<span class="edsm-num">' . date('d/m/Y', strtotime($filter_date)) . '</span>' ?>
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                    <a href="follow_ups.php?f=1" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                </div>
            </div>

            <?php if (empty($followups)): ?>
                <div class="edsm-empty">
                    <div class="edsm-empty-icon"><i class="fas fa-calendar-times"></i></div>
                    <p>لا توجد متابعات مطابقة للتصفية المحددة</p>
                </div>
            <?php else: ?>
                <div class="edsm-table-wrap">
                    <table class="edsm-table edsm-table-lg">
                        <thead>
                            <tr>
                                <th>المريض</th>
                                <th>تاريخ آخر زيارة</th>
                                <th>تاريخ المتابعة القادمة</th>
                                <th>نوع المتابعة</th>
                                <th>طبيب المتابعة</th>
                                <th>الحالة</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($followups as $row): ?>
                                <?php
                                [, $kind_label] = followupKind($row);
                                $state = followupState($row, $today);
                                ?>
                                <tr data-followup-id="<?= (int)$row['id'] ?>">
                                    <td class="whitespace-nowrap">
                                        <a href="patient_profile.php?id=<?= (int)$row['patient_id'] ?>" class="font-bold text-gray-800 hover:text-blue-600"><?= htmlspecialchars($row['patient_name']) ?></a>
                                        <?php if ($row['priority'] === 'urgent' || $row['priority'] === 'high'): ?>
                                            <span class="edsm-tag <?= $row['priority'] === 'urgent' ? 'edsm-tag-danger' : 'edsm-tag-warning' ?>"><?= $priority_labels[$row['priority']] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="edsm-num"><?= $row['last_visit'] ? date('d/m/Y', strtotime($row['last_visit'])) : '—' ?></td>
                                    <td class="edsm-num font-bold"><?= date('d/m/Y', strtotime($row['follow_up_date'])) ?></td>
                                    <td>
                                        <span class="edsm-fu-kind" title="<?= htmlspecialchars(trim(($row['follow_up_reason'] ?? '') . ($row['notes'] ? ' — ' . $row['notes'] : ''))) ?>"><?= htmlspecialchars($kind_label) ?></span>
                                    </td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars(doctorLabel($row['doctor_name'], $row['doctor_role'])) ?></td>
                                    <td>
                                        <span class="edsm-state is-<?= $state ?>" <?= $state === 'completed' && $row['completed_at'] ? 'title="أُكملت ' . date('d/m/Y H:i', strtotime($row['completed_at'])) . '"' : '' ?>><?= $status_options[$state] ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="edsm-text-action" data-followup="<?= followupPayload($row) ?>">
                                            <i class="far fa-pen-to-square"></i> تحديث المتابعة
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- الترقيم -->
                <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                    <div class="text-sm text-gray-500">
                        عرض <span class="edsm-num"><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_followups) ?></span>
                        من أصل <span class="edsm-num"><?= number_format($total_followups) ?></span> متابعة
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <nav class="edsm-pagination" aria-label="الصفحات">
                            <a href="<?= htmlspecialchars(followupsUrl(['page' => $page - 1])) ?>" class="<?= $page <= 1 ? 'is-disabled' : '' ?>" aria-label="السابق"><i class="fas fa-chevron-right"></i></a>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="<?= htmlspecialchars(followupsUrl(['page' => $i])) ?>" class="edsm-num <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <a href="<?= htmlspecialchars(followupsUrl(['page' => $page + 1])) ?>" class="<?= $page >= $total_pages ? 'is-disabled' : '' ?>" aria-label="التالي"><i class="fas fa-chevron-left"></i></a>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- إكمال / إلغاء المتابعة المفتوحة في النموذج -->
    <form method="POST" id="fuStatusForm" class="hidden">
        <input type="hidden" name="action" id="fuStatusAction" value="">
        <input type="hidden" name="followup_id" id="fuStatusId" value="">
    </form>

    <script src="../assets/js/patient-search.js"></script>
    <script>
    (function () {
        const DAY_COUNTS = <?= json_encode($calendar_counts, JSON_FORCE_OBJECT) ?>;
        const FORM_STATE = <?= json_encode($form_state, JSON_UNESCAPED_UNICODE) ?>;
        const MY_ID = <?= (int)$doctor_id ?>;
        const PRESELECT = <?= $preselected_patient ?>;
        const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        const WEEK_HEAD = ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'];

        const pad = n => String(n).padStart(2, '0');
        const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
        const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const now = new Date();
        const TODAY = iso(now);
        const $ = id => document.getElementById(id);

        const form = $('fuForm');
        const actionInput = $('fuAction');
        const idInput = $('fuId');
        const dateInput = $('fuDate');
        const patientSelect = $('fuPatient');
        const infoEl = $('fuPatientInfo');
        const kindSelect = $('fuKind');
        const reasonInput = $('fuReason');
        const doctorSelect = $('fuDoctor');
        const prioritySelect = $('fuPriority');
        const notesInput = $('fuNotes');
        const summaryEl = $('fuSummary');
        const errorEl = $('fuFormError');
        const calendarEl = $('fuCalendar');
        const statusActions = $('fuStatusActions');

        let view = new Date(now.getFullYear(), now.getMonth(), 1);
        let lockedDate = ''; // تاريخ المتابعة المفتوحة للتحديث (قد يكون في الماضي)

        // ---------- المريض ----------
        function showPatient() {
            const o = patientSelect.value ? patientSelect.options[patientSelect.selectedIndex] : null;
            if (!o) { infoEl.className = 'edsm-picked is-empty'; infoEl.textContent = 'لم يتم اختيار مريض بعد'; return; }
            showPicked(o.dataset.name, [o.dataset.phone, o.dataset.age ? o.dataset.age + ' سنة' : ''].filter(Boolean).join(' · '));
        }
        function showPicked(name, meta) {
            infoEl.className = 'edsm-picked';
            infoEl.innerHTML = '<strong>' + esc(name) + '</strong>' + (meta ? '<span class="edsm-num">' + esc(meta) + '</span>' : '');
        }
        patientSelect.addEventListener('change', () => { showPatient(); hideError(); });
        const search = PatientSearch.attach(patientSelect, { inputId: 'fuPatientSearch', placeholder: 'بحث عن مريض', inputClass: 'edsm-field' });
        // زر البحث الداكن كما في التصميم
        const goBtn = document.createElement('button');
        goBtn.type = 'button';
        goBtn.className = 'edsm-search-go';
        goBtn.setAttribute('aria-label', 'بحث عن مريض');
        goBtn.innerHTML = '<i class="fas fa-search"></i>';
        goBtn.addEventListener('click', () => search.input.focus());
        search.input.parentNode.appendChild(goBtn);

        // ---------- نوع المتابعة ----------
        function syncReason() { reasonInput.classList.toggle('hidden', kindSelect.value !== 'other'); }
        kindSelect.addEventListener('change', () => { syncReason(); hideError(); if (kindSelect.value === 'other') reasonInput.focus(); });

        // ---------- التقويم ----------
        function renderCalendar() {
            $('fuMonthTitle').textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
            const first = new Date(view);
            first.setDate(first.getDate() - (first.getDay() + 1) % 7); // السبت أول الأسبوع
            let html = WEEK_HEAD.map(d => '<span class="edsm-bcal-dow">' + d + '</span>').join('');
            for (let i = 0; i < 42; i++) {
                const d = new Date(first); d.setDate(first.getDate() + i);
                if (i === 35 && d.getMonth() !== view.getMonth()) break;
                const key = iso(d);
                const past = key < TODAY && key !== lockedDate;
                const cls = ['edsm-bcal-day'];
                if (d.getMonth() !== view.getMonth()) cls.push('is-other');
                if (past) cls.push('is-past');
                if (key === TODAY) cls.push('is-today');
                if (DAY_COUNTS[key]) cls.push('is-busy');
                if (key === dateInput.value) cls.push('is-selected');
                const title = DAY_COUNTS[key] ? DAY_COUNTS[key] + ' متابعة' : '';
                html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + key + '"' + (past ? ' disabled' : '') + ' title="' + title + '"><span class="edsm-num">' + d.getDate() + '</span></button>';
            }
            calendarEl.innerHTML = html;
            // لا عودة لأشهر ماضية (إلا شهر المتابعة المفتوحة)
            const minDate = lockedDate && lockedDate < TODAY ? parse(lockedDate) : now;
            $('fuPrevMonth').disabled = view.getFullYear() * 12 + view.getMonth() <= minDate.getFullYear() * 12 + minDate.getMonth();
        }
        calendarEl.addEventListener('click', e => {
            const btn = e.target.closest('.edsm-bcal-day');
            if (btn && !btn.disabled) selectDate(btn.dataset.date);
        });
        $('fuPrevMonth').addEventListener('click', () => { view.setMonth(view.getMonth() - 1); renderCalendar(); });
        $('fuNextMonth').addEventListener('click', () => { view.setMonth(view.getMonth() + 1); renderCalendar(); });

        function selectDate(key) {
            dateInput.value = key;
            if (key) {
                const d = parse(key);
                view = new Date(d.getFullYear(), d.getMonth(), 1);
            }
            renderCalendar();
            updateSummary();
            hideError();
        }

        function updateSummary() {
            const span = summaryEl.querySelector('span');
            summaryEl.classList.toggle('is-ready', !!dateInput.value);
            if (!dateInput.value) { span.textContent = 'اختر تاريخ المتابعة من التقويم'; return; }
            const d = parse(dateInput.value);
            const diff = Math.round((d - parse(TODAY)) / 86400000);
            const rel = diff === 0 ? 'اليوم' : diff === 1 ? 'غداً' : diff > 1 ? 'بعد ' + diff + ' يوم' : 'متأخرة ' + (-diff) + ' يوم';
            const others = DAY_COUNTS[dateInput.value] ? ' · <span class="edsm-num">' + DAY_COUNTS[dateInput.value] + '</span> متابعة في هذا اليوم' : '';
            span.innerHTML = 'تاريخ المتابعة: <strong>' + DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() + '</strong> (' + rel + ')' + others;
        }

        document.querySelectorAll('[data-add]').forEach(btn => btn.addEventListener('click', () => {
            const d = parse(TODAY);
            const n = parseInt(btn.dataset.add, 10);
            if (btn.dataset.add.endsWith('d')) {
                d.setDate(d.getDate() + n);
            } else {
                const day = d.getDate();
                d.setDate(1);
                d.setMonth(d.getMonth() + n);
                d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
            }
            selectDate(iso(d));
        }));

        // ---------- إضافة / تحديث ----------
        function setDoctor(id, name) {
            doctorSelect.querySelectorAll('option[data-temp]').forEach(o => o.remove());
            if (id && !doctorSelect.querySelector('option[value="' + id + '"]')) {
                // طبيب المتابعة الحالي غير نشط: يبقى خياراً لهذه المتابعة فقط
                doctorSelect.add(new Option(name || '—', id));
                doctorSelect.options[doctorSelect.options.length - 1].dataset.temp = '1';
            }
            doctorSelect.value = id ? String(id) : '';
        }

        function fillFields(f) {
            kindSelect.value = f.kind || '';
            reasonInput.value = f.reason || '';
            syncReason();
            setDoctor(f.doctor, f.doctorName);
            prioritySelect.value = f.priority || 'normal';
            notesInput.value = f.notes || '';
            selectDate(f.date || '');
        }

        function markEditing(id) {
            document.querySelectorAll('[data-followup-id]').forEach(el => el.classList.toggle('is-editing', String(id) === el.dataset.followupId));
        }

        function setAddMode() {
            actionInput.value = 'add_manual_followup';
            idInput.value = '';
            lockedDate = '';
            $('fuEditBanner').hidden = true;
            $('fuSearchBlock').classList.remove('hidden');
            statusActions.classList.add('hidden');
            $('fuSubmitText').textContent = 'إضافة المتابعة';
            $('fuSubmitIcon').className = 'fas fa-plus';
            patientSelect.value = '';
            search.sync();
            showPatient();
            fillFields({ doctor: MY_ID, priority: 'normal' });
            view = new Date(now.getFullYear(), now.getMonth(), 1);
            renderCalendar();
            markEditing(null);
            hideError();
        }

        function setEditMode(f, scroll) {
            actionInput.value = 'update_followup';
            idInput.value = f.id;
            lockedDate = f.date;
            $('fuEditBanner').hidden = false;
            $('fuEditName').textContent = f.patient;
            $('fuSearchBlock').classList.add('hidden');
            statusActions.classList.toggle('hidden', !f.active);
            $('fuSubmitText').textContent = 'تحديث المتابعة';
            $('fuSubmitIcon').className = 'fas fa-check';
            showPicked(f.patient, f.phone);
            fillFields(f);
            markEditing(f.id);
            hideError();
            if (scroll) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        document.addEventListener('click', e => {
            const el = e.target.closest('[data-followup]');
            if (el) setEditMode(JSON.parse(el.dataset.followup), true);
        });
        $('fuReset').addEventListener('click', setAddMode);
        $('fuEditClose').addEventListener('click', setAddMode);

        statusActions.addEventListener('click', e => {
            const btn = e.target.closest('[data-status-action]');
            if (!btn) return;
            const completing = btn.dataset.statusAction === 'complete_followup';
            if (!confirm(completing ? 'هل تريد تسجيل هذه المتابعة كمكتملة؟' : 'هل أنت متأكد من إلغاء هذه المتابعة؟')) return;
            $('fuStatusAction').value = btn.dataset.statusAction;
            $('fuStatusId').value = idInput.value;
            $('fuStatusForm').submit();
        });

        // ---------- التحقق قبل الإرسال ----------
        function showError(msg) { errorEl.textContent = msg; errorEl.classList.remove('hidden'); errorEl.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        function hideError() { errorEl.classList.add('hidden'); }
        form.addEventListener('submit', e => {
            let msg = '';
            if (actionInput.value === 'add_manual_followup' && !patientSelect.value) msg = 'يرجى اختيار المريض';
            else if (!kindSelect.value) msg = 'يرجى اختيار نوع المتابعة';
            else if (kindSelect.value === 'other' && !reasonInput.value.trim()) msg = 'يرجى كتابة سبب المتابعة';
            else if (!doctorSelect.value) msg = 'يرجى اختيار طبيب المتابعة';
            else if (!dateInput.value) msg = 'يرجى اختيار تاريخ المتابعة من التقويم';
            else if (dateInput.value < TODAY && dateInput.value !== lockedDate) msg = 'لا يمكن تحديد تاريخ متابعة في الماضي';
            if (msg) { e.preventDefault(); showError(msg); }
        });

        // ---------- أدوات التصفية ----------
        const filtersForm = $('fuFilters');
        document.querySelectorAll('[data-multi]').forEach(box => {
            const toggle = box.querySelector('.edsm-multi-toggle');
            const panel = box.querySelector('.edsm-multi-panel');
            const text = box.querySelector('.edsm-multi-text');
            let dirty = false;
            const sync = () => {
                const labels = Array.from(panel.querySelectorAll('input:checked')).map(i => i.dataset.label);
                text.textContent = labels.length ? labels.join('، ') : box.dataset.empty;
                text.classList.toggle('is-placeholder', !labels.length);
            };
            const close = () => {
                if (panel.hidden) return;
                panel.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
                if (dirty) filtersForm.submit();
            };
            toggle.addEventListener('click', () => {
                if (!panel.hidden) { close(); return; }
                panel.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
            });
            panel.addEventListener('change', () => { dirty = true; sync(); });
            panel.querySelector('[data-apply]').addEventListener('click', close);
            document.addEventListener('click', e => { if (!box.contains(e.target)) close(); });
            box.addEventListener('keydown', e => { if (e.key === 'Escape') { close(); toggle.focus(); } });
            sync();
        });
        $('fuDoctorFilter').addEventListener('change', () => filtersForm.submit());

        // ---------- الحالة الأولى ----------
        setAddMode();
        if (FORM_STATE && FORM_STATE.mode === 'edit') {
            setEditMode(FORM_STATE, false);
        } else if (FORM_STATE) {
            patientSelect.value = FORM_STATE.patientId ? String(FORM_STATE.patientId) : '';
            search.sync();
            showPatient();
            fillFields(FORM_STATE);
        } else if (PRESELECT) {
            patientSelect.value = String(PRESELECT);
            search.sync();
            showPatient();
        }
        if (FORM_STATE) errorEl.classList.toggle('hidden', !errorEl.textContent.trim());
        if (new URLSearchParams(location.search).get('action') === 'add' && !patientSelect.value && window.innerWidth >= 1024) search.input.focus();
    })();
    </script>
</body>
</html>
