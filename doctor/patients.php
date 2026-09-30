<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$doctor_id = $_SESSION['user_id'];

// ---------- المعاملات ----------
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? '';
if ($filter === 'recent') {
    $filter = 'active'; // الاسم القديم لنفس الفلتر
}
$treating_doctor = (int)($_GET['doctor'] ?? 0);
$sort = $_GET['sort'] ?? 'recent';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$filterLabels = [
    ''          => 'جميع المرضى',
    'active'    => 'النشطة',
    'new'       => 'الجدد',
    'followup'  => 'متابعة',
    'unpaid'    => 'مستحقات',
    'chronic'   => 'حالات مزمنة',
];

// رابط يحافظ على المعاملات الحالية مع تغيير بعضها
function patientsUrl(array $changes = []) {
    $params = array_merge($_GET, $changes);
    unset($params['view']);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'patients.php' . ($params ? '?' . http_build_query($params) : '');
}

// "نشط" = عولج أو كان له موعد خلال آخر 30 يوماً
$activeSql = "(EXISTS (SELECT 1 FROM treatments ta WHERE ta.patient_id = p.id AND ta.treatment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
               OR EXISTS (SELECT 1 FROM appointments aa WHERE aa.patient_id = p.id AND aa.appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()))";
$newSql = "p.registration_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";

// ---------- شروط القائمة ----------
// ملفات المرضى مشتركة بين جميع الأطباء
$where_conditions = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where_conditions[] = "(p.name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.medical_history LIKE ? OR p.id = ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%", (int)ltrim($search, '0'));
}

switch ($filter) {
    case 'active':
        $where_conditions[] = $activeSql;
        break;
    case 'new':
        $where_conditions[] = $newSql;
        break;
    case 'followup':
        $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.next_appointment_date IS NOT NULL AND t.next_appointment_date >= CURDATE())";
        break;
    case 'unpaid':
        $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.payment_status != 'paid' AND t.cost > 0)";
        break;
    case 'chronic':
        $where_conditions[] = "p.medical_history IS NOT NULL AND p.medical_history != ''";
        break;
    case 'high_value':
        $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.cost > 1000)";
        break;
}

if ($treating_doctor) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM treatments td WHERE td.patient_id = p.id AND td.doctor_id = ?)";
    $params[] = $treating_doctor;
}

$where_clause = implode(' AND ', $where_conditions);

// ترتيب بالنقر على عناوين الأعمدة
$sortColumns = [
    'name'   => 'p.name',
    'file'   => 'p.id',
    'age'    => 'p.age',
    'recent' => 'last_visit',
];
if (!isset($sortColumns[$sort])) {
    $sort = 'recent';
}
$order_clause = 'ORDER BY ' . $sortColumns[$sort] . ' ' . strtoupper($dir) . ($sort === 'recent' ? ', p.created_at DESC' : '');

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $where_clause");
    $count_stmt->execute($params);
    $total_patients = $count_stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.*,
               COUNT(t.id) as treatment_count,
               GREATEST(COALESCE(MAX(t.treatment_date), '1000-01-01'), COALESCE(p.last_visit_date, '1000-01-01')) as last_visit,
               (COALESCE(SUM(t.cost), 0) - (SELECT COALESCE(SUM(pay.amount), 0) FROM payments pay WHERE pay.patient_id = p.id)) as outstanding_balance
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id
        WHERE $where_clause
        GROUP BY p.id
        $order_clause
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pages = (int)ceil($total_patients / $per_page);

    // ملخص المرضى (الشريط الجانبي)
    $summary = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM($activeSql) AS active_now,
               SUM($newSql) AS new_this_month
        FROM patients p WHERE p.status = 'active'
    ")->fetch(PDO::FETCH_ASSOC);

    // أحدث المرضى المضافين
    $latest_patients = $pdo->query("
        SELECT id, name, gender, created_at, registration_date
        FROM patients WHERE status = 'active'
        ORDER BY created_at DESC, id DESC LIMIT 4
    ")->fetchAll(PDO::FETCH_ASSOC);

    // الأطباء لفلتر "الطبيب المعالج"
    $doctors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patients = $latest_patients = $doctors = [];
    $total_patients = 0;
    $total_pages = 0;
    $summary = ['total' => 0, 'active_now' => 0, 'new_this_month' => 0];
}

// مربع البحث بجانب عنوان الصفحة (يحافظ على الفلاتر الحالية)
$hidden = '';
foreach (['filter' => $filter, 'doctor' => $treating_doctor ?: '', 'sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? ''] as $k => $v) {
    if ($v !== '') {
        $hidden .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($v) . '">';
    }
}
$pageHeadActions = '
    <form method="GET" class="edsm-head-search" role="search">' . $hidden . '
        <i class="fas fa-search"></i>
        <input type="text" name="search" value="' . htmlspecialchars($search) . '" placeholder="البحث عن مريض بالاسم أو الرقم أو الهاتف..." aria-label="البحث عن مريض">
    </form>';

// Header configuration
$pageTitle = 'إدارة المرضى';
$pageIcon = 'fas fa-users';
$pageSubtitle = 'ملفات المرضى مشتركة بين جميع الأطباء';
$currentPage = 'patients';

// عنوان عمود قابل للترتيب
function sortHeader($label, $key, $sort, $dir) {
    $isActive = $sort === $key;
    $nextDir = $isActive && $dir === 'desc' ? 'asc' : 'desc';
    $icon = $isActive ? ($dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    return '<a href="' . htmlspecialchars(patientsUrl(['sort' => $key, 'dir' => $nextDir, 'page' => null])) . '" class="edsm-sort' . ($isActive ? ' is-active' : '') . '">'
         . $label . ' <i class="fas ' . $icon . '"></i></a>';
}
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
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-5">
                <i class="fas fa-exclamation-triangle ml-1"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 items-start">
            <!-- ================= الشريط الجانبي ================= -->
            <aside class="space-y-6 order-2 lg:order-none">
                <div class="edsm-card fade-in">
                    <h3 class="edsm-card-title" style="font-size: 17px; margin-bottom: 12px;">ملخص المرضى</h3>
                    <div class="edsm-summary">
                        <a href="<?= htmlspecialchars(patientsUrl(['filter' => null, 'page' => null])) ?>" class="edsm-summary-row">
                            <span>إجمالي الملفات:</span>
                            <strong class="edsm-num"><?= number_format((int)$summary['total']) ?></strong>
                        </a>
                        <a href="<?= htmlspecialchars(patientsUrl(['filter' => 'active', 'page' => null])) ?>" class="edsm-summary-row">
                            <span><i class="fas fa-info-circle text-teal-500" title="عولجوا أو كان لهم موعد خلال آخر 30 يوماً"></i> النشطة حالياً:</span>
                            <strong class="edsm-num"><?= number_format((int)$summary['active_now']) ?></strong>
                        </a>
                        <a href="<?= htmlspecialchars(patientsUrl(['filter' => 'new', 'page' => null])) ?>" class="edsm-summary-row">
                            <span><i class="fas fa-user-friends text-gray-400"></i> مرضى جدد هذا الشهر:</span>
                            <strong class="edsm-num"><?= number_format((int)$summary['new_this_month']) ?></strong>
                        </a>
                    </div>
                </div>

                <div class="edsm-card fade-in">
                    <h3 class="edsm-card-title" style="font-size: 17px; margin-bottom: 8px;">أحدث المرضى المضافين</h3>
                    <?php if (empty($latest_patients)): ?>
                        <p class="text-sm text-gray-500 py-3">لا يوجد مرضى بعد</p>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($latest_patients as $latest): ?>
                                <a href="patient_profile.php?id=<?= $latest['id'] ?>" class="edsm-upcoming">
                                    <span class="flex-1 min-w-0">
                                        <span class="edsm-row-title block truncate"><?= htmlspecialchars($latest['name']) ?></span>
                                        <span class="edsm-row-meta block edsm-num">
                                            <?= $latest['created_at'] ? date('d/m/Y H:i', strtotime($latest['created_at'])) : date('d/m/Y', strtotime($latest['registration_date'])) ?>
                                        </span>
                                    </span>
                                    <span class="edsm-avatar-soft is-violet"><i class="fas fa-tooth"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ================= قائمة المرضى ================= -->
            <section class="edsm-card lg:col-span-3 order-1 lg:order-none fade-in" style="padding: 18px 20px 20px;">
                <div class="flex flex-wrap items-center gap-3 mb-4">
                    <nav class="edsm-pills" aria-label="تصفية المرضى">
                        <?php foreach ($filterLabels as $key => $label): ?>
                            <a href="<?= htmlspecialchars(patientsUrl(['filter' => $key, 'page' => null])) ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <form method="GET" style="width: 200px;">
                        <?php foreach (['search' => $search, 'filter' => $filter, 'sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? ''] as $k => $v): ?>
                            <?php if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
                        <?php endforeach; ?>
                        <select name="doctor" class="edsm-field" onchange="this.form.submit()" aria-label="تصفية حسب الطبيب المعالج">
                            <option value="">حسب الطبيب المعالج</option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?= $doc['id'] ?>" <?= $treating_doctor === (int)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="../nurse/patients.php?action=add" class="edsm-btn edsm-btn-lg" style="margin-inline-start: auto;">
                        <i class="fas fa-plus"></i> إضافة مريض جديد
                    </a>
                </div>

                <?php if ($search !== '' || $filter !== '' || $treating_doctor): ?>
                    <div class="flex flex-wrap items-center gap-2 mb-3 text-sm text-gray-600">
                        <span>عرض <strong class="edsm-num"><?= number_format($total_patients) ?></strong> نتيجة</span>
                        <?php if ($search !== ''): ?><span class="edsm-chip">بحث: <?= htmlspecialchars($search) ?></span><?php endif; ?>
                        <a href="patients.php" class="edsm-link text-sm"><i class="fas fa-times"></i> مسح التصفية</a>
                    </div>
                <?php endif; ?>

                <?php if (empty($patients)): ?>
                    <div class="edsm-empty">
                        <div class="edsm-empty-icon"><i class="fas fa-users"></i></div>
                        <p>لا يوجد مرضى مطابقون</p>
                        <a href="../nurse/patients.php?action=add" class="edsm-btn edsm-btn-lg"><i class="fas fa-plus"></i> إضافة مريض جديد</a>
                    </div>
                <?php else: ?>
                    <div class="edsm-table-wrap">
                        <table class="edsm-table edsm-table-lg">
                            <thead>
                                <tr>
                                    <th><?= sortHeader('اسم المريض', 'name', $sort, $dir) ?></th>
                                    <th><?= sortHeader('رقم الملف', 'file', $sort, $dir) ?></th>
                                    <th><?= sortHeader('العمر', 'age', $sort, $dir) ?></th>
                                    <th>الجنس</th>
                                    <th>رقم الهاتف</th>
                                    <th><?= sortHeader('آخر زيارة', 'recent', $sort, $dir) ?></th>
                                    <th class="text-center">الحالة الطبية</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $patient): ?>
                                    <?php
                                    $lastVisit = $patient['last_visit'] > '1000-01-01' ? $patient['last_visit'] : null;
                                    $hasHistory = !empty($patient['medical_history']);
                                    $hasAllergies = !empty($patient['allergies']);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="patient_profile.php?id=<?= $patient['id'] ?>" class="font-bold text-gray-800 hover:text-blue-600"><?= htmlspecialchars($patient['name']) ?></a>
                                            <?php if ($patient['outstanding_balance'] > 0): ?>
                                                <div class="text-xs text-red-600 mt-0.5" title="الرصيد المتبقي">
                                                    <i class="fas fa-wallet"></i> <span class="edsm-num"><?= number_format($patient['outstanding_balance']) ?></span> ل.س
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="edsm-num"><?= str_pad($patient['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                        <td class="edsm-num"><?= $patient['age'] !== null ? (int)$patient['age'] : '—' ?></td>
                                        <td><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></td>
                                        <td class="edsm-num" dir="ltr" style="text-align: right;">
                                            <a href="tel:<?= htmlspecialchars($patient['phone']) ?>" class="hover:text-blue-600"><?= htmlspecialchars($patient['phone']) ?></a>
                                        </td>
                                        <td class="edsm-num"><?= $lastVisit ? date('d/m/Y', strtotime($lastVisit)) : '<span class="text-gray-400">—</span>' ?></td>
                                        <td>
                                            <div class="flex items-center justify-center gap-1">
                                                <?php if ($hasHistory): ?>
                                                    <span class="edsm-med-icon is-red" title="التاريخ المرضي: <?= htmlspecialchars($patient['medical_history']) ?>"><i class="fas fa-heartbeat"></i></span>
                                                <?php endif; ?>
                                                <?php if ($hasAllergies): ?>
                                                    <span class="edsm-med-icon is-violet" title="الحساسية: <?= htmlspecialchars($patient['allergies']) ?>"><i class="fas fa-allergies"></i></span>
                                                <?php endif; ?>
                                                <?php if (!$hasHistory && !$hasAllergies): ?>
                                                    <span class="edsm-med-icon is-green" title="لا توجد ملاحظات طبية"><i class="fas fa-shield-alt"></i></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="edsm-actions-cell">
                                                <a href="patient_profile.php?id=<?= $patient['id'] ?>" class="edsm-icon-action is-blue" title="ملف المريض"><i class="far fa-eye"></i></a>
                                                <a href="patient_edit.php?id=<?= $patient['id'] ?>" class="edsm-icon-action is-blue" title="تعديل البيانات"><i class="fas fa-pen"></i></a>
                                                <a href="treatment_new.php?patient_id=<?= $patient['id'] ?>" class="edsm-icon-action is-green" title="علاج جديد"><i class="fas fa-tooth"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- الترقيم -->
                    <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                        <div class="text-sm text-gray-500">
                            عرض <span class="edsm-num"><?= $offset + 1 ?>–<?= min($offset + $per_page, $total_patients) ?></span>
                            من أصل <span class="edsm-num"><?= number_format($total_patients) ?></span> مريض
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <nav class="edsm-pagination" aria-label="الصفحات">
                                <a href="<?= htmlspecialchars(patientsUrl(['page' => $page - 1])) ?>" class="<?= $page <= 1 ? 'is-disabled' : '' ?>" aria-label="السابق"><i class="fas fa-chevron-right"></i></a>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="<?= htmlspecialchars(patientsUrl(['page' => $i])) ?>" class="edsm-num <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a href="<?= htmlspecialchars(patientsUrl(['page' => $page + 1])) ?>" class="<?= $page >= $total_pages ? 'is-disabled' : '' ?>" aria-label="التالي"><i class="fas fa-chevron-left"></i></a>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
        // البحث يُطبَّق تلقائياً بعد التوقف عن الكتابة
        const headSearch = document.querySelector('.edsm-head-search input[name="search"]');
        let searchTimer;
        headSearch?.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => this.form.submit(), 800);
        });
        if (headSearch && headSearch.value) {
            headSearch.focus();
            headSearch.setSelectionRange(headSearch.value.length, headSearch.value.length);
        }
    </script>
</body>
</html>
