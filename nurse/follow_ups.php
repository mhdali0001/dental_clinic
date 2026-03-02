<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('nurse');

// Set page variables for header
$pageTitle = 'إدارة المتابعات';
$pageIcon = 'fas fa-calendar-check';
$pageSubtitle = 'متابعة حالات المرضى والمواعيد';
$currentPage = 'follow_ups';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$success_message = '';
$error_message = '';

// معالجة تحديث حالة المتابعة
if ($_POST && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'complete_followup':
                $stmt = $pdo->prepare("
                    UPDATE follow_ups
                    SET status = 'completed', completed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['followup_id']]);
                $success_message = "تم إكمال المتابعة بنجاح";
                break;

            case 'reschedule_followup':
                $stmt = $pdo->prepare("
                    UPDATE follow_ups
                    SET follow_up_date = ?, status = 'rescheduled'
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['new_date'], $_POST['followup_id']]);
                $success_message = "تم إعادة جدولة المتابعة بنجاح";
                break;

            case 'cancel_followup':
                $stmt = $pdo->prepare("
                    UPDATE follow_ups
                    SET status = 'cancelled'
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['followup_id']]);
                $success_message = "تم إلغاء المتابعة بنجاح";
                break;

            case 'add_manual_followup':
                $stmt = $pdo->prepare("
                    INSERT INTO follow_ups (patient_id, follow_up_type, follow_up_date, follow_up_reason, priority, notes, created_by)
                    VALUES (?, 'manual', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['patient_id'],
                    $_POST['follow_up_date'],
                    $_POST['reason'],
                    $_POST['priority'],
                    $_POST['notes'],
                    $_SESSION['user_id']
                ]);
                $success_message = "تم إضافة المتابعة بنجاح";
                break;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    }
}

// فلترة النتائج
$filter_status = $_GET['status'] ?? 'pending';
$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';

// بناء استعلام البحث
$where_conditions = ["f.status = ?"];
$params = [$filter_status];

if ($filter_type !== 'all') {
    $where_conditions[] = "f.follow_up_type = ?";
    $params[] = $filter_type;
}

if ($filter_date) {
    $where_conditions[] = "f.follow_up_date = ?";
    $params[] = $filter_date;
}

$where_clause = implode(' AND ', $where_conditions);

// جلب المتابعات (nurse can see all follow-ups)
try {
    $stmt = $pdo->prepare("
        SELECT f.*, p.name as patient_name, p.phone, p.age, p.gender,
               t.treatment_type, t.treatment_date
        FROM follow_ups f
        JOIN patients p ON f.patient_id = p.id
        LEFT JOIN treatments t ON f.treatment_id = t.id
        WHERE $where_clause
        ORDER BY f.follow_up_date ASC, f.priority DESC, f.created_at DESC
    ");
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $followups = [];
    $error_message = "خطأ في جلب البيانات: " . $e->getMessage();
}

// إحصائيات المتابعة
try {
    $stats = [];

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'pending'");
    $stats['pending'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE follow_up_date = CURDATE() AND status = 'pending'");
    $stats['today'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE follow_up_date < CURDATE() AND status = 'pending'");
    $stats['overdue'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM follow_ups WHERE status = 'completed'");
    $stats['completed'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $stats = ['pending' => 0, 'today' => 0, 'overdue' => 0, 'completed' => 0];
}

// جلب قائمة المرضى للإضافة اليدوية
try {
    $patients_stmt = $pdo->query("SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name");
    $patients = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients = [];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المتابعات - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .priority-urgent { border-right: 4px solid #ef4444; }
        .priority-high { border-right: 4px solid #f97316; }
        .priority-normal { border-right: 4px solid #3b82f6; }
        .priority-low { border-right: 4px solid #6b7280; }

        .status-pending { background-color: #fef3c7; border-color: #f59e0b; }
        .status-completed { background-color: #d1fae5; border-color: #10b981; }
        .status-cancelled { background-color: #fee2e2; border-color: #ef4444; }
        .status-rescheduled { background-color: #e0e7ff; border-color: #6366f1; }

        .followup-card { transition: all 0.3s ease; }
        .followup-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/nurse_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-2"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-2"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-400">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات معلقة</p>
                        <p class="text-2xl font-bold text-yellow-600"><?= $stats['pending'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-400">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-calendar-day text-blue-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات اليوم</p>
                        <p class="text-2xl font-bold text-blue-600"><?= $stats['today'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-red-400">
                <div class="flex items-center">
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات متأخرة</p>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['overdue'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-400">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">متابعات مكتملة</p>
                        <p class="text-2xl font-bold text-green-600"><?= $stats['completed'] ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter and Search Tools -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6 fade-in">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-filter text-green-600 ml-2"></i>
                تصفية المتابعات
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">الحالة</label>
                    <select name="status" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>معلقة</option>
                        <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>مكتملة</option>
                        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>ملغاة</option>
                        <option value="rescheduled" <?= $filter_status === 'rescheduled' ? 'selected' : '' ?>>معاد جدولتها</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">النوع</label>
                    <select name="type" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>جميع الأنواع</option>
                        <option value="birthday" <?= $filter_type === 'birthday' ? 'selected' : '' ?>>عيد ميلاد</option>
                        <option value="treatment" <?= $filter_type === 'treatment' ? 'selected' : '' ?>>متابعة علاج</option>
                        <option value="manual" <?= $filter_type === 'manual' ? 'selected' : '' ?>>متابعة يدوية</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">التاريخ</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>

                <div class="flex items-end space-x-2 space-x-reverse">
                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-search ml-1"></i>
                        تصفية
                    </button>
                    <button type="button" onclick="showAddFollowupModal()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-plus ml-1"></i>
                        إضافة متابعة
                    </button>
                </div>
            </form>
        </div>

        <!-- Follow-ups List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-list text-green-600 ml-2"></i>
                    قائمة المتابعات (<?= count($followups) ?>)
                </h3>
            </div>

            <div class="divide-y divide-gray-200">
                <?php if (empty($followups)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-calendar-times text-gray-300 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد متابعات</h3>
                        <p class="text-gray-600 mb-6">لا توجد متابعات مطابقة للمرشحات المحددة.</p>
                        <button onclick="showAddFollowupModal()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-plus ml-1"></i>
                            إضافة أول متابعة
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($followups as $followup): ?>
                        <?php
                        $priority_class = "priority-{$followup['priority']}";
                        $status_class = "status-{$followup['status']}";
                        $is_overdue = $followup['follow_up_date'] < date('Y-m-d') && $followup['status'] === 'pending';
                        $type_icon = [
                            'birthday' => 'fas fa-birthday-cake',
                            'treatment' => 'fas fa-medical-kit',
                            'manual' => 'fas fa-user-edit'
                        ][$followup['follow_up_type']] ?? 'fas fa-calendar';
                        ?>
                        <div class="followup-card p-6 hover:bg-gray-50 <?= $priority_class ?> <?= $status_class ?> <?= $is_overdue ? 'bg-red-50' : '' ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-full ml-3">
                                            <i class="<?= $type_icon ?> text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                <?= htmlspecialchars($followup['patient_name']) ?>
                                            </h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-phone ml-1"></i>
                                                <a href="tel:<?= $followup['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4">
                                                    <?= $followup['phone'] ?>
                                                </a>
                                                <i class="fas fa-calendar ml-1"></i>
                                                <span class="ml-4 font-semibold <?= $is_overdue ? 'text-red-600' : 'text-blue-600' ?>">
                                                    <?= date('d/m/Y', strtotime($followup['follow_up_date'])) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-info-circle text-blue-500 ml-1"></i>
                                            <strong>النوع:</strong>
                                            <?= ['birthday' => 'عيد ميلاد', 'treatment' => 'متابعة علاج', 'manual' => 'متابعة يدوية'][$followup['follow_up_type']] ?>
                                        </div>
                                        <?php if ($followup['priority'] !== 'normal'): ?>
                                            <div class="text-gray-600">
                                                <i class="fas fa-exclamation-circle text-orange-500 ml-1"></i>
                                                <strong>الأولوية:</strong>
                                                <span class="px-2 py-1 text-xs rounded-full
                                                    <?= $followup['priority'] === 'urgent' ? 'bg-red-100 text-red-800' :
                                                       ($followup['priority'] === 'high' ? 'bg-orange-100 text-orange-800' : 'bg-gray-100 text-gray-800') ?>">
                                                    <?= ['urgent' => 'عاجل', 'high' => 'عالي', 'low' => 'منخفض'][$followup['priority']] ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($is_overdue): ?>
                                            <div class="text-red-600">
                                                <i class="fas fa-exclamation-triangle ml-1"></i>
                                                <strong>الحالة:</strong>
                                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">
                                                    متأخر
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($followup['follow_up_reason']): ?>
                                        <div class="mt-4 p-3 bg-blue-50 border-r-4 border-blue-300 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-comment ml-1"></i>
                                                <strong>السبب:</strong>
                                                <?= htmlspecialchars($followup['follow_up_reason']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($followup['notes']): ?>
                                        <div class="mt-4 p-3 bg-yellow-50 border-r-4 border-yellow-300 rounded">
                                            <p class="text-sm text-yellow-800">
                                                <i class="fas fa-sticky-note ml-1"></i>
                                                <strong>ملاحظات:</strong>
                                                <?= htmlspecialchars($followup['notes']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-col space-y-2 mr-4">
                                    <div class="text-center">
                                        <?php
                                        $statusClasses = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'completed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800',
                                            'rescheduled' => 'bg-blue-100 text-blue-800'
                                        ];
                                        $statusTexts = [
                                            'pending' => 'معلقة',
                                            'completed' => 'مكتملة',
                                            'cancelled' => 'ملغاة',
                                            'rescheduled' => 'معاد جدولتها'
                                        ];
                                        ?>
                                        <span class="<?= $statusClasses[$followup['status']] ?? 'bg-gray-100 text-gray-800' ?> px-3 py-1 rounded-full text-sm font-medium">
                                            <?= $statusTexts[$followup['status']] ?? $followup['status'] ?>
                                        </span>
                                    </div>

                                    <?php if ($followup['status'] === 'pending'): ?>
                                        <button onclick="completeFollowup(<?= $followup['id'] ?>)"
                                                class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                            <i class="fas fa-check ml-1"></i>
                                            إكمال
                                        </button>
                                        <button onclick="showRescheduleModal(<?= $followup['id'] ?>, '<?= $followup['follow_up_date'] ?>')"
                                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                            <i class="fas fa-calendar-alt ml-1"></i>
                                            إعادة جدولة
                                        </button>
                                        <button onclick="cancelFollowup(<?= $followup['id'] ?>)"
                                                class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center justify-center">
                                            <i class="fas fa-times ml-1"></i>
                                            إلغاء
                                        </button>
                                    <?php else: ?>
                                        <div class="text-sm text-gray-500 text-center">
                                            <?php if ($followup['completed_at']): ?>
                                                <div class="text-xs text-gray-400">
                                                    <?= date('d/m/Y H:i', strtotime($followup['completed_at'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <a href="patient_details.php?id=<?= $followup['patient_id'] ?>"
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

    <!-- Modal لإضافة متابعة جديدة -->
    <div id="addFollowupModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="bg-green-600 text-white p-4 rounded-t-lg">
                    <h3 class="text-lg font-semibold">إضافة متابعة جديدة</h3>
                </div>
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="add_manual_followup">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">المريض *</label>
                            <div class="relative">
                                <input type="text"
                                       id="patient_search_modal"
                                       placeholder="ابحث عن المريض بالاسم أو رقم الهاتف..."
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       autocomplete="off">
                                <input type="hidden" name="patient_id" id="selected_patient_id_modal" required>
                                <div id="patient_dropdown_modal" class="absolute z-10 w-full bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                                    <?php foreach ($patients as $patient): ?>
                                        <div class="patient-option-modal cursor-pointer p-3 hover:bg-gray-100 border-b border-gray-100"
                                             data-id="<?= $patient['id'] ?>"
                                             data-name="<?= htmlspecialchars($patient['name']) ?>"
                                             data-phone="<?= $patient['phone'] ?>">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($patient['name']) ?></div>
                                            <div class="text-sm text-gray-600"><?= $patient['phone'] ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div id="selected_patient_info_modal" class="mt-2 p-2 bg-green-50 border border-green-200 rounded-lg hidden">
                                <div class="text-sm text-green-800">
                                    <i class="fas fa-user ml-1"></i>
                                    <span id="selected_patient_display_modal"></span>
                                    <button type="button" onclick="clearPatientSelectionModal()" class="mr-2 text-red-600 hover:text-red-800">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">تاريخ المتابعة *</label>
                            <input type="date" name="follow_up_date" required
                                   min="<?= date('Y-m-d') ?>"
                                   class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">سبب المتابعة *</label>
                            <input type="text" name="reason" required
                                   class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                                   placeholder="سبب المتابعة...">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">الأولوية</label>
                            <select name="priority" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="normal">عادية</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                                <option value="low">منخفضة</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">ملاحظات</label>
                            <textarea name="notes" rows="3"
                                      class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                                      placeholder="ملاحظات إضافية..."></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-2 space-x-reverse mt-6">
                        <button type="button" onclick="hideAddFollowupModal()"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                            إلغاء
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
                            إضافة المتابعة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal لإعادة الجدولة -->
    <div id="rescheduleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="bg-blue-600 text-white p-4 rounded-t-lg">
                    <h3 class="text-lg font-semibold">إعادة جدولة المتابعة</h3>
                </div>
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="reschedule_followup">
                    <input type="hidden" name="followup_id" id="rescheduleFollowupId">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">التاريخ الجديد *</label>
                            <input type="date" name="new_date" id="rescheduleNewDate" required
                                   min="<?= date('Y-m-d') ?>"
                                   class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="flex justify-end space-x-2 space-x-reverse mt-6">
                        <button type="button" onclick="hideRescheduleModal()"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                            إلغاء
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                            إعادة الجدولة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Patient search functionality for modal
        const patientSearchModal = document.getElementById('patient_search_modal');
        const patientDropdownModal = document.getElementById('patient_dropdown_modal');
        const selectedPatientIdModal = document.getElementById('selected_patient_id_modal');
        const selectedPatientInfoModal = document.getElementById('selected_patient_info_modal');
        const selectedPatientDisplayModal = document.getElementById('selected_patient_display_modal');
        const patientOptionsModal = document.querySelectorAll('.patient-option-modal');

        // Patient search input handler for modal
        patientSearchModal?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let hasVisibleOptions = false;

            patientOptionsModal.forEach(option => {
                const name = option.dataset.name.toLowerCase();
                const phone = option.dataset.phone.toLowerCase();

                if (name.includes(searchTerm) || phone.includes(searchTerm)) {
                    option.style.display = 'block';
                    hasVisibleOptions = true;
                } else {
                    option.style.display = 'none';
                }
            });

            if (searchTerm && hasVisibleOptions) {
                patientDropdownModal.classList.remove('hidden');
            } else {
                patientDropdownModal.classList.add('hidden');
            }
        });

        // Show dropdown on focus for modal
        patientSearchModal?.addEventListener('focus', function() {
            if (this.value && !selectedPatientIdModal.value) {
                patientDropdownModal.classList.remove('hidden');
            }
        });

        // Handle patient selection for modal
        patientOptionsModal.forEach(option => {
            option.addEventListener('click', function() {
                selectPatientModal(this);
            });
        });

        function selectPatientModal(option) {
            const id = option.dataset.id;
            const name = option.dataset.name;
            const phone = option.dataset.phone;

            selectedPatientIdModal.value = id;
            patientSearchModal.value = '';
            selectedPatientDisplayModal.textContent = `${name} - ${phone}`;
            selectedPatientInfoModal.classList.remove('hidden');
            patientDropdownModal.classList.add('hidden');
        }

        function clearPatientSelectionModal() {
            selectedPatientIdModal.value = '';
            patientSearchModal.value = '';
            selectedPatientInfoModal.classList.add('hidden');
            patientSearchModal.focus();
        }

        // Modal functions
        function showAddFollowupModal() {
            document.getElementById('addFollowupModal').classList.remove('hidden');
            setTimeout(() => patientSearchModal?.focus(), 100);
        }

        function hideAddFollowupModal() {
            document.getElementById('addFollowupModal').classList.add('hidden');
            clearPatientSelectionModal();
        }

        function showRescheduleModal(followupId, currentDate) {
            document.getElementById('rescheduleFollowupId').value = followupId;
            document.getElementById('rescheduleNewDate').value = currentDate;
            document.getElementById('rescheduleModal').classList.remove('hidden');
        }

        function hideRescheduleModal() {
            document.getElementById('rescheduleModal').classList.add('hidden');
        }

        function completeFollowup(followupId) {
            if (confirm('هل أنت متأكد من إكمال هذه المتابعة؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="complete_followup">
                    <input type="hidden" name="followup_id" value="${followupId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function cancelFollowup(followupId) {
            if (confirm('هل أنت متأكد من إلغاء هذه المتابعة؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="cancel_followup">
                    <input type="hidden" name="followup_id" value="${followupId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#patient_search_modal') && !e.target.closest('#patient_dropdown_modal')) {
                patientDropdownModal?.classList.add('hidden');
            }
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addFollowupModal');
            const rescheduleModal = document.getElementById('rescheduleModal');

            if (event.target === addModal) {
                hideAddFollowupModal();
            }
            if (event.target === rescheduleModal) {
                hideRescheduleModal();
            }
        }
    </script>
</body>
</html>