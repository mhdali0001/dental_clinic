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

// البحث والفلترة المطورة
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$sort = $_GET['sort'] ?? 'recent';
$view = $_GET['view'] ?? 'cards'; // cards or table
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = ($view === 'table') ? 15 : 12;
$offset = ($page - 1) * $per_page;

// بناء الاستعلام المحسن
$where_conditions = ["p.status = 'active'"];
$join_conditions = [];
$params = [];

// إضافة شرط الطبيب للمرضى الذين عالجهم
$where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.doctor_id = ?)";
$params[] = $doctor_id;

// البحث المطور
if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.medical_history LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// الفلاتر المطورة
if ($filter === 'recent') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.doctor_id = ? AND t.treatment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))";
    $params[] = $doctor_id;
} elseif ($filter === 'followup') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.doctor_id = ? AND t.next_appointment_date IS NOT NULL AND t.next_appointment_date >= CURDATE())";
    $params[] = $doctor_id;
} elseif ($filter === 'unpaid') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.doctor_id = ? AND t.payment_status != 'paid' AND t.cost > 0)";
    $params[] = $doctor_id;
} elseif ($filter === 'chronic') {
    $where_conditions[] = "p.medical_history IS NOT NULL AND p.medical_history != ''";
} elseif ($filter === 'high_value') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM treatments t WHERE t.patient_id = p.id AND t.doctor_id = ? AND t.cost > 1000)";
    $params[] = $doctor_id;
}

$where_clause = implode(' AND ', $where_conditions);

// ترتيب محسن
$order_clause = match($sort) {
    'name' => 'ORDER BY p.name ASC',
    'recent' => 'ORDER BY p.last_visit_date DESC, p.created_at DESC',
    'treatments' => 'ORDER BY treatment_count DESC',
    'revenue' => 'ORDER BY total_spent DESC',
    'age' => 'ORDER BY p.age DESC',
    default => 'ORDER BY p.last_visit_date DESC, p.created_at DESC'
};

try {
    // عدد المرضى الإجمالي
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM patients p
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_patients = $count_stmt->fetchColumn();

    // جلب المرضى مع معلومات تفصيلية
    $stmt = $pdo->prepare("
        SELECT p.*,
               COUNT(t.id) as treatment_count,
               MAX(t.treatment_date) as last_treatment_date,
               COALESCE(SUM(t.cost), 0) as total_spent,
               COALESCE(SUM(pay.amount), 0) as total_paid,
               (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(pay.amount), 0)) as outstanding_balance,
               GROUP_CONCAT(DISTINCT t.treatment_type ORDER BY t.treatment_date DESC) as recent_treatments,
               (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.id AND a.appointment_date >= CURDATE()) as upcoming_appointments,
               (SELECT MIN(next_appointment_date) FROM treatments t2 WHERE t2.patient_id = p.id AND t2.next_appointment_date >= CURDATE()) as next_followup
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id AND t.doctor_id = ?
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE $where_clause
        GROUP BY p.id
        $order_clause
        LIMIT $per_page OFFSET $offset
    ");

    $all_params = array_merge([$doctor_id], $params);
    $stmt->execute($all_params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_pages = ceil($total_patients / $per_page);

    // الإحصائيات المطورة
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT p.id) as total_patients,
            COUNT(DISTINCT CASE WHEN t.treatment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN p.id END) as recent_patients,
            COUNT(DISTINCT CASE WHEN t.next_appointment_date >= CURDATE() THEN p.id END) as followup_patients,
            COUNT(DISTINCT CASE WHEN t.payment_status != 'paid' AND t.cost > 0 THEN p.id END) as unpaid_patients,
            COUNT(DISTINCT CASE WHEN p.medical_history IS NOT NULL AND p.medical_history != '' THEN p.id END) as chronic_patients,
            AVG(p.age) as avg_age,
            SUM(t.cost) as total_revenue,
            SUM(pay.amount) as total_collected
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id AND t.doctor_id = ?
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE p.status = 'active'
        AND EXISTS (SELECT 1 FROM treatments t2 WHERE t2.patient_id = p.id AND t2.doctor_id = ?)
    ");
    $stats_stmt->execute([$doctor_id, $doctor_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patients = [];
    $total_patients = 0;
    $total_pages = 0;
    $stats = ['total_patients' => 0, 'recent_patients' => 0, 'followup_patients' => 0, 'unpaid_patients' => 0, 'chronic_patients' => 0, 'avg_age' => 0, 'total_revenue' => 0, 'total_collected' => 0];
}

// Header configuration
$pageTitle = 'إدارة المرضى';
$pageIcon = 'fas fa-users';
$pageSubtitle = 'إجمالي المرضى: ' . number_format($stats['total_patients'] ?? 0) . ' مريض';
$currentPage = 'patients';
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
        .patient-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-right: 4px solid transparent;
        }
        .patient-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            border-right-color: #3b82f6;
        }
        .medical-alert {
            background: linear-gradient(135deg, #fee2e2 0%, #fef2f2 100%);
            border: 1px solid #fca5a5;
        }
        .high-value { border-right-color: #10b981 !important; }
        .needs-followup { border-right-color: #f59e0b !important; }
        .unpaid-balance { border-right-color: #ef4444 !important; }
        .chronic-condition { border-right-color: #8b5cf6 !important; }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: scale(1.02); }

        .search-container {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            backdrop-filter: blur(10px);
        }

        .filter-btn {
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .filter-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .filter-btn:hover::before { left: 100%; }

        .view-toggle {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            border-radius: 50px;
            padding: 4px;
        }

        .patient-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }

        .treatment-tag {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4338ca;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
            margin: 1px;
        }

        .quick-action-btn {
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .quick-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Enhanced Statistics Dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card text-white rounded-xl p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-blue-100 text-sm">إجمالي المرضى</p>
                        <p class="text-3xl font-bold"><?= number_format($stats['total_patients']) ?></p>
                        <p class="text-blue-200 text-xs mt-1">متوسط العمر: <?= round($stats['avg_age']) ?> سنة</p>
                    </div>
                    <div class="bg-white/20 p-3 rounded-full">
                        <i class="fas fa-users text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى حديثين</p>
                        <p class="text-3xl font-bold text-green-600"><?= $stats['recent_patients'] ?></p>
                        <p class="text-xs text-gray-500 mt-1">آخر 30 يوم</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full relative">
                        <i class="fas fa-user-plus text-green-600 text-xl"></i>
                        <?php if ($stats['recent_patients'] > 0): ?>
                            <span class="notification-badge"><?= min($stats['recent_patients'], 99) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=recent&view=<?= $view ?>" class="text-green-600 hover:text-green-800 text-sm font-medium">
                        عرض المرضى الحديثين <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">يحتاجون متابعة</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $stats['followup_patients'] ?></p>
                        <p class="text-xs text-gray-500 mt-1">مواعيد مجدولة</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full relative">
                        <i class="fas fa-user-clock text-yellow-600 text-xl"></i>
                        <?php if ($stats['followup_patients'] > 0): ?>
                            <span class="notification-badge bg-yellow-500"><?= min($stats['followup_patients'], 99) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=followup&view=<?= $view ?>" class="text-yellow-600 hover:text-yellow-800 text-sm font-medium">
                        عرض المتابعات <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">رصيد مستحق</p>
                        <p class="text-3xl font-bold text-red-600"><?= $stats['unpaid_patients'] ?></p>
                        <p class="text-xs text-gray-500 mt-1">مريض</p>
                    </div>
                    <div class="bg-red-100 p-3 rounded-full relative">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        <?php if ($stats['unpaid_patients'] > 0): ?>
                            <span class="notification-badge"><?= min($stats['unpaid_patients'], 99) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=unpaid&view=<?= $view ?>" class="text-red-600 hover:text-red-800 text-sm font-medium">
                        عرض المستحقات <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Enhanced Search and Filters -->
        <div class="search-container rounded-xl shadow-lg p-6 mb-8">
            <div class="flex flex-col lg:flex-row gap-4 items-center">
                <!-- Search Bar -->
                <div class="flex-1 relative">
                    <form method="GET" class="relative">
                        <input type="text"
                               name="search"
                               value="<?= htmlspecialchars($search) ?>"
                               placeholder="البحث بالاسم، الهاتف، البريد أو التاريخ المرضي..."
                               class="w-full pl-12 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm">
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <?php if ($filter): ?>
                            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <?php endif; ?>
                        <?php if ($sort): ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <?php endif; ?>
                        <?php if ($view): ?>
                            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <?php endif; ?>
                    </form>
                </div>

                <!-- View Toggle -->
                <div class="view-toggle">
                    <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'cards'])) ?>"
                       class="<?= $view === 'cards' ? 'bg-white text-purple-600' : 'text-white' ?> px-4 py-2 rounded-full transition">
                        <i class="fas fa-th-large"></i>
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'table'])) ?>"
                       class="<?= $view === 'table' ? 'bg-white text-purple-600' : 'text-white' ?> px-4 py-2 rounded-full transition">
                        <i class="fas fa-list"></i>
                    </a>
                </div>
            </div>

            <!-- Enhanced Filters -->
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="?" class="filter-btn <?= !$filter ? 'bg-blue-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-2 rounded-full text-sm transition border">
                    <i class="fas fa-list ml-1"></i>
                    الكل (<?= number_format($stats['total_patients']) ?>)
                </a>
                <a href="?filter=recent&view=<?= $view ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="filter-btn <?= $filter === 'recent' ? 'bg-green-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-2 rounded-full text-sm transition border">
                    <i class="fas fa-clock ml-1"></i>
                    حديثين (<?= $stats['recent_patients'] ?>)
                </a>
                <a href="?filter=followup&view=<?= $view ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="filter-btn <?= $filter === 'followup' ? 'bg-yellow-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-2 rounded-full text-sm transition border">
                    <i class="fas fa-calendar-check ml-1"></i>
                    متابعة (<?= $stats['followup_patients'] ?>)
                </a>
                <a href="?filter=unpaid&view=<?= $view ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="filter-btn <?= $filter === 'unpaid' ? 'bg-red-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-2 rounded-full text-sm transition border">
                    <i class="fas fa-exclamation-triangle ml-1"></i>
                    مستحقات (<?= $stats['unpaid_patients'] ?>)
                </a>
                <a href="?filter=chronic&view=<?= $view ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="filter-btn <?= $filter === 'chronic' ? 'bg-purple-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-2 rounded-full text-sm transition border">
                    <i class="fas fa-heartbeat ml-1"></i>
                    حالات مزمنة (<?= $stats['chronic_patients'] ?>)
                </a>
            </div>

            <!-- Sort Options -->
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="text-sm font-medium text-gray-600">ترتيب حسب:</span>
                <a href="?sort=recent&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'sort', ARRAY_FILTER_USE_KEY)) ?>"
                   class="<?= $sort === 'recent' ? 'text-blue-600 font-bold' : 'text-gray-500 hover:text-blue-600' ?> text-sm transition">
                    الأحدث
                </a>
                <span class="text-gray-300">|</span>
                <a href="?sort=name&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'sort', ARRAY_FILTER_USE_KEY)) ?>"
                   class="<?= $sort === 'name' ? 'text-blue-600 font-bold' : 'text-gray-500 hover:text-blue-600' ?> text-sm transition">
                    الاسم
                </a>
                <span class="text-gray-300">|</span>
                <a href="?sort=treatments&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'sort', ARRAY_FILTER_USE_KEY)) ?>"
                   class="<?= $sort === 'treatments' ? 'text-blue-600 font-bold' : 'text-gray-500 hover:text-blue-600' ?> text-sm transition">
                    عدد العلاجات
                </a>
                <span class="text-gray-300">|</span>
                <a href="?sort=revenue&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'sort', ARRAY_FILTER_USE_KEY)) ?>"
                   class="<?= $sort === 'revenue' ? 'text-blue-600 font-bold' : 'text-gray-500 hover:text-blue-600' ?> text-sm transition">
                    الإيرادات
                </a>
            </div>
        </div>

        <!-- Patients Display -->
        <?php if (empty($patients)): ?>
            <div class="text-center py-16">
                <div class="w-32 h-32 mx-auto mb-6 text-gray-300">
                    <i class="fas fa-user-friends text-8xl"></i>
                </div>
                <h3 class="text-xl font-medium text-gray-900 mb-2">لا توجد نتائج</h3>
                <p class="text-gray-600 mb-6">
                    <?php if ($search || $filter): ?>
                        لم يتم العثور على مرضى تطابق معايير البحث.
                    <?php else: ?>
                        لم يتم تسجيل أي مرضى بعد.
                    <?php endif; ?>
                </p>
                <?php if ($search || $filter): ?>
                    <a href="?" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition">
                        <i class="fas fa-undo ml-2"></i>
                        عرض جميع المرضى
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <?php if ($view === 'cards'): ?>
                <!-- Cards View -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <?php foreach ($patients as $patient): ?>
                        <?php
                        $card_class = 'patient-card';
                        if ($patient['outstanding_balance'] > 0) $card_class .= ' unpaid-balance';
                        elseif ($patient['next_followup']) $card_class .= ' needs-followup';
                        elseif ($patient['total_spent'] > 2000) $card_class .= ' high-value';
                        elseif ($patient['medical_history']) $card_class .= ' chronic-condition';
                        ?>
                        <div class="<?= $card_class ?> bg-white rounded-xl shadow-lg p-6">
                            <!-- Patient Header -->
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center">
                                    <div class="patient-avatar">
                                        <?= mb_substr($patient['name'], 0, 2, 'UTF-8') ?>
                                    </div>
                                    <div class="mr-4">
                                        <h3 class="text-lg font-bold text-gray-900">
                                            <?= htmlspecialchars($patient['name']) ?>
                                        </h3>
                                        <div class="flex items-center text-sm text-gray-500 mt-1">
                                            <i class="fas fa-phone text-xs ml-1"></i>
                                            <?= htmlspecialchars($patient['phone']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end">
                                    <span class="text-xs text-gray-500"><?= $patient['age'] ?> سنة</span>
                                    <span class="text-xs text-gray-500"><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                                </div>
                            </div>

                            <!-- Patient Stats -->
                            <div class="grid grid-cols-3 gap-4 mb-4">
                                <div class="text-center">
                                    <div class="text-xl font-bold text-blue-600"><?= $patient['treatment_count'] ?></div>
                                    <div class="text-xs text-gray-500">علاجات</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-xl font-bold text-green-600"><?= number_format($patient['total_spent']) ?></div>
                                    <div class="text-xs text-gray-500">إجمالي</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-xl font-bold <?= $patient['outstanding_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                        <?= number_format($patient['outstanding_balance']) ?>
                                    </div>
                                    <div class="text-xs text-gray-500">متبقي</div>
                                </div>
                            </div>

                            <!-- Recent Treatments -->
                            <?php if ($patient['recent_treatments']): ?>
                                <div class="mb-4">
                                    <p class="text-xs text-gray-500 mb-2">آخر العلاجات:</p>
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach (explode(',', $patient['recent_treatments']) as $treatment): ?>
                                            <span class="treatment-tag"><?= htmlspecialchars(trim($treatment)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Medical Alerts -->
                            <?php if ($patient['medical_history'] || $patient['allergies']): ?>
                                <div class="medical-alert rounded-lg p-3 mb-4">
                                    <div class="flex items-start">
                                        <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 ml-2 text-sm"></i>
                                        <div class="flex-1 text-sm">
                                            <?php if ($patient['medical_history']): ?>
                                                <div class="text-red-800">
                                                    <strong>التاريخ المرضي:</strong>
                                                    <?= htmlspecialchars(mb_substr($patient['medical_history'], 0, 100, 'UTF-8')) ?>
                                                    <?= mb_strlen($patient['medical_history'], 'UTF-8') > 100 ? '...' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($patient['allergies']): ?>
                                                <div class="text-red-800 mt-1">
                                                    <strong>الحساسية:</strong>
                                                    <?= htmlspecialchars($patient['allergies']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Next Follow-up -->
                            <?php if ($patient['next_followup']): ?>
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4">
                                    <div class="flex items-center text-sm text-yellow-800">
                                        <i class="fas fa-calendar-check ml-2"></i>
                                        <strong>موعد المتابعة:</strong>
                                        <span class="mr-2"><?= date('d/m/Y', strtotime($patient['next_followup'])) ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Action Buttons -->
                            <div class="grid grid-cols-2 gap-2">
                                <a href="patient_profile.php?id=<?= $patient['id'] ?>"
                                   class="quick-action-btn bg-blue-500 hover:bg-blue-600 text-white text-center py-2 px-3 rounded-lg text-sm transition">
                                    <i class="fas fa-user-circle ml-1"></i>
                                    الملف الشخصي
                                </a>
                                <a href="treatment_new.php?patient_id=<?= $patient['id'] ?>"
                                   class="quick-action-btn bg-green-500 hover:bg-green-600 text-white text-center py-2 px-3 rounded-lg text-sm transition">
                                    <i class="fas fa-plus ml-1"></i>
                                    علاج جديد
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <!-- Table View -->
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr>
                                    <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المريض</th>
                                    <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">معلومات التواصل</th>
                                    <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإحصائيات</th>
                                    <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                                    <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($patients as $patient): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="patient-avatar w-10 h-10 text-sm">
                                                    <?= mb_substr($patient['name'], 0, 2, 'UTF-8') ?>
                                                </div>
                                                <div class="mr-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($patient['name']) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?= $patient['age'] ?> سنة • <?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?= htmlspecialchars($patient['phone']) ?></div>
                                            <?php if ($patient['email']): ?>
                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($patient['email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?= $patient['treatment_count'] ?> علاج • <?= number_format($patient['total_spent']) ?> ل.س
                                            </div>
                                            <div class="text-sm <?= $patient['outstanding_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                                متبقي: <?= number_format($patient['outstanding_balance']) ?> ل.س
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-col gap-1">
                                                <?php if ($patient['outstanding_balance'] > 0): ?>
                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                        <i class="fas fa-exclamation-triangle ml-1"></i>
                                                        مستحقات
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($patient['next_followup']): ?>
                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                        <i class="fas fa-calendar-check ml-1"></i>
                                                        متابعة
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($patient['medical_history']): ?>
                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                                        <i class="fas fa-heartbeat ml-1"></i>
                                                        مزمن
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex gap-2">
                                                <a href="patient_profile.php?id=<?= $patient['id'] ?>"
                                                   class="text-blue-600 hover:text-blue-900 text-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="treatment_new.php?patient_id=<?= $patient['id'] ?>"
                                                   class="text-green-600 hover:text-green-900 text-sm">
                                                    <i class="fas fa-plus"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- Enhanced Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="flex items-center space-x-2 space-x-reverse bg-white rounded-xl shadow-lg px-4 py-3">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>"
                           class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-lg transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-4 py-2 bg-blue-500 text-white rounded-lg font-medium">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>"
                               class="px-4 py-2 text-gray-700 hover:bg-gray-50 rounded-lg transition">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)) ?>"
                           class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-lg transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>

        <!-- Results Info -->
        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">
                عرض <?= count($patients) ?> من أصل <?= number_format($total_patients) ?> مريض
                <?php if ($search): ?>
                    | نتائج البحث عن: <strong>"<?= htmlspecialchars($search) ?>"</strong>
                <?php endif; ?>
                <?php if ($filter): ?>
                    | الفلتر: <strong><?= $filter ?></strong>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Enhanced JavaScript -->
    <script>
        // Auto-submit search form
        const searchInput = document.querySelector('input[name="search"]');
        let searchTimeout;

        searchInput?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 1000);
        });

        // Smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '0';
                        entry.target.style.transform = 'translateY(20px)';
                        entry.target.style.transition = 'all 0.6s ease';

                        setTimeout(() => {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }, Math.random() * 200);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.patient-card').forEach(card => {
                observer.observe(card);
            });
        });

        // Enhanced filter animations
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.05)';
            });

            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>