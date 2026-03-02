<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('admin');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$success_message = '';
$error_message = '';

// فلترة التقارير
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر الحالي
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي
$doctor_id = $_GET['doctor_id'] ?? 'all';
$report_type = $_GET['report_type'] ?? 'summary';

// جلب قائمة الأطباء
try {
    $doctors = $pdo->query("
        SELECT id, full_name, username
        FROM users
        WHERE role = 'doctor' AND is_active = 1
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $doctors = [];
    $error_message = "خطأ في جلب قائمة الأطباء: " . $e->getMessage();
}

// بناء شروط الاستعلام
$where_conditions = ["1=1"];
$params = [];

if ($date_from) {
    $where_conditions[] = "DATE(t.treatment_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(t.treatment_date) <= ?";
    $params[] = $date_to;
}

if ($doctor_id !== 'all') {
    $where_conditions[] = "t.doctor_id = ?";
    $params[] = $doctor_id;
}

$where_clause = implode(' AND ', $where_conditions);

// جلب البيانات حسب نوع التقرير
$report_data = [];

try {
    switch ($report_type) {
        case 'summary':
            // تقرير ملخص شامل
            $report_data['summary'] = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT t.patient_id) as total_patients,
                    COUNT(t.id) as total_treatments,
                    COALESCE(SUM(t.cost), 0) as total_cost,
                    COALESCE(SUM(p.amount), 0) as total_paid,
                    (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(p.amount), 0)) as unpaid_amount
                FROM treatments t
                LEFT JOIN payments p ON t.patient_id = p.patient_id
                WHERE {$where_clause}
            ");
            $report_data['summary']->execute($params);
            $report_data['summary'] = $report_data['summary']->fetch(PDO::FETCH_ASSOC);

            // تقرير حسب الأطباء
            $doctor_where = str_replace('doctor_id', 't.doctor_id', $where_clause);
            $report_data['by_doctor'] = $pdo->prepare("
                SELECT
                    u.full_name as doctor_name,
                    u.id as doctor_id,
                    COUNT(DISTINCT t.patient_id) as patients_count,
                    COUNT(t.id) as treatments_count,
                    COALESCE(SUM(t.cost), 0) as total_cost,
                    COALESCE(SUM(p.amount), 0) as total_paid,
                    (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(p.amount), 0)) as unpaid_amount
                FROM treatments t
                JOIN users u ON t.doctor_id = u.id
                LEFT JOIN payments p ON t.patient_id = p.patient_id AND DATE(p.payment_date) BETWEEN ? AND ?
                WHERE {$doctor_where}
                GROUP BY u.id, u.full_name
                ORDER BY treatments_count DESC
            ");
            $execute_params = $params;
            array_splice($execute_params, 0, 0, [$date_from, $date_to]); // Add date params at beginning
            $report_data['by_doctor']->execute($execute_params);
            $report_data['by_doctor'] = $report_data['by_doctor']->fetchAll(PDO::FETCH_ASSOC);

            // تقرير حسب نوع العلاج
            $report_data['by_treatment'] = $pdo->prepare("
                SELECT
                    t.treatment_type,
                    COUNT(t.id) as count,
                    COALESCE(SUM(t.cost), 0) as total_cost,
                    COALESCE(AVG(t.cost), 0) as avg_cost
                FROM treatments t
                WHERE {$where_clause}
                GROUP BY t.treatment_type
                ORDER BY count DESC
            ");
            $report_data['by_treatment']->execute($params);
            $report_data['by_treatment'] = $report_data['by_treatment']->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'detailed':
            // تقرير مفصل للعلاجات
            $detailed_where = str_replace('doctor_id', 't.doctor_id', $where_clause);
            $report_data['detailed'] = $pdo->prepare("
                SELECT
                    t.*,
                    p.name as patient_name,
                    p.phone as patient_phone,
                    u.full_name as doctor_name,
                    COALESCE(SUM(pay.amount), 0) as total_paid,
                    (t.cost - COALESCE(SUM(pay.amount), 0)) as remaining_amount
                FROM treatments t
                JOIN patients p ON t.patient_id = p.id
                JOIN users u ON t.doctor_id = u.id
                LEFT JOIN payments pay ON t.id = pay.treatment_id
                WHERE {$detailed_where}
                GROUP BY t.id
                ORDER BY t.treatment_date DESC
            ");
            $report_data['detailed']->execute($params);
            $report_data['detailed'] = $report_data['detailed']->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'financial':
            // تقرير مالي مفصل
            $financial_where = str_replace('DATE(t.treatment_date)', 'DATE(p.payment_date)', $where_clause);
            $financial_where = str_replace('t.doctor_id', 'p.created_by', $financial_where);

            $report_data['payments'] = $pdo->prepare("
                SELECT
                    p.*,
                    pt.name as patient_name,
                    pt.phone as patient_phone,
                    u.full_name as created_by_name,
                    t.treatment_type,
                    t.cost as treatment_cost
                FROM payments p
                JOIN patients pt ON p.patient_id = pt.id
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN treatments t ON p.treatment_id = t.id
                WHERE {$financial_where}
                ORDER BY p.payment_date DESC
            ");
            $report_data['payments']->execute($params);
            $report_data['payments'] = $report_data['payments']->fetchAll(PDO::FETCH_ASSOC);

            // ملخص مالي
            $report_data['financial_summary'] = $pdo->prepare("
                SELECT
                    COUNT(p.id) as total_payments,
                    COALESCE(SUM(p.amount), 0) as total_amount,
                    COALESCE(AVG(p.amount), 0) as avg_payment,
                    COUNT(DISTINCT p.patient_id) as unique_patients
                FROM payments p
                WHERE {$financial_where}
            ");
            $report_data['financial_summary']->execute($params);
            $report_data['financial_summary'] = $report_data['financial_summary']->fetch(PDO::FETCH_ASSOC);
            break;
    }

} catch (PDOException $e) {
    $error_message = "خطأ في جلب البيانات: " . $e->getMessage();
    $report_data = [];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير الشاملة - إدارة العيادة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .table-row:hover {
            background-color: #f8fafc;
        }
        .report-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/admin_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">التقارير الشاملة</h1>
            <p class="mt-2 text-gray-600">تقارير مفصلة لجميع الأطباء والأنشطة</p>
        </div>

        <!-- رسائل النجاح والخطأ -->
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

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-filter text-blue-600 ml-2"></i>
                فلترة التقارير
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">من تاريخ</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">إلى تاريخ</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"
                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">الطبيب</label>
                    <select name="doctor_id" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?= $doctor_id === 'all' ? 'selected' : '' ?>>جميع الأطباء</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= $doctor['id'] ?>" <?= $doctor_id == $doctor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doctor['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">نوع التقرير</label>
                    <select name="report_type" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>تقرير ملخص</option>
                        <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>تقرير مفصل</option>
                        <option value="financial" <?= $report_type === 'financial' ? 'selected' : '' ?>>تقرير مالي</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-search ml-1"></i>
                        تطبيق الفلترة
                    </button>
                </div>
            </form>
        </div>

        <!-- Report Content -->
        <?php if ($report_type === 'summary' && !empty($report_data)): ?>
            <!-- Summary Report -->
            <div class="space-y-6">
                <!-- Overall Summary -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-chart-pie text-green-600 ml-2"></i>
                        الملخص العام
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="report-card bg-blue-50 p-4 rounded-lg border border-blue-200">
                            <div class="text-center">
                                <i class="fas fa-users text-blue-600 text-2xl mb-2"></i>
                                <p class="text-2xl font-bold text-blue-600"><?= $report_data['summary']['total_patients'] ?></p>
                                <p class="text-sm text-gray-600">مجموع المرضى</p>
                            </div>
                        </div>
                        <div class="report-card bg-green-50 p-4 rounded-lg border border-green-200">
                            <div class="text-center">
                                <i class="fas fa-medical-kit text-green-600 text-2xl mb-2"></i>
                                <p class="text-2xl font-bold text-green-600"><?= $report_data['summary']['total_treatments'] ?></p>
                                <p class="text-sm text-gray-600">مجموع العلاجات</p>
                            </div>
                        </div>
                        <div class="report-card bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                            <div class="text-center">
                                <i class="fas fa-dollar-sign text-yellow-600 text-2xl mb-2"></i>
                                <p class="text-xl font-bold text-yellow-600"><?= number_format($report_data['summary']['total_cost']) ?></p>
                                <p class="text-sm text-gray-600">إجمالي التكلفة</p>
                            </div>
                        </div>
                        <div class="report-card bg-purple-50 p-4 rounded-lg border border-purple-200">
                            <div class="text-center">
                                <i class="fas fa-credit-card text-purple-600 text-2xl mb-2"></i>
                                <p class="text-xl font-bold text-purple-600"><?= number_format($report_data['summary']['total_paid']) ?></p>
                                <p class="text-sm text-gray-600">المبلغ المدفوع</p>
                            </div>
                        </div>
                        <div class="report-card bg-red-50 p-4 rounded-lg border border-red-200">
                            <div class="text-center">
                                <i class="fas fa-exclamation-triangle text-red-600 text-2xl mb-2"></i>
                                <p class="text-xl font-bold text-red-600"><?= number_format($report_data['summary']['unpaid_amount']) ?></p>
                                <p class="text-sm text-gray-600">المبلغ المتبقي</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- By Doctor Report -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-user-md text-blue-600 ml-2"></i>
                        تقرير حسب الأطباء
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الطبيب</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المرضى</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العلاجات</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي التكلفة</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المدفوع</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المتبقي</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($report_data['by_doctor'] as $doctor): ?>
                                    <tr class="table-row">
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-md text-white text-sm"></i>
                                                </div>
                                                <div class="mr-3">
                                                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($doctor['doctor_name']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= $doctor['patients_count'] ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= $doctor['treatments_count'] ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($doctor['total_cost']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($doctor['total_paid']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-red-600"><?= number_format($doctor['unpaid_amount']) ?> ل.س</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- By Treatment Type -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-chart-bar text-purple-600 ml-2"></i>
                        تقرير حسب نوع العلاج
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع العلاج</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العدد</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي التكلفة</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">متوسط التكلفة</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($report_data['by_treatment'] as $treatment): ?>
                                    <tr class="table-row">
                                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($treatment['treatment_type']) ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= $treatment['count'] ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($treatment['total_cost']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($treatment['avg_cost']) ?> ل.س</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($report_type === 'detailed' && !empty($report_data['detailed'])): ?>
            <!-- Detailed Report -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-list-alt text-blue-600 ml-2"></i>
                    التقرير المفصل للعلاجات
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المريض</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الطبيب</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع العلاج</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التكلفة</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المدفوع</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المتبقي</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data['detailed'] as $treatment): ?>
                                <tr class="table-row">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= date('Y/m/d', strtotime($treatment['treatment_date'])) ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($treatment['patient_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($treatment['patient_phone']) ?></p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($treatment['doctor_name']) ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($treatment['treatment_type']) ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($treatment['cost']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($treatment['total_paid']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm <?= $treatment['remaining_amount'] > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                        <?= number_format($treatment['remaining_amount']) ?> ل.س
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($report_type === 'financial' && !empty($report_data)): ?>
            <!-- Financial Report -->
            <div class="space-y-6">
                <!-- Financial Summary -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-chart-line text-green-600 ml-2"></i>
                        الملخص المالي
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="report-card bg-green-50 p-4 rounded-lg border border-green-200">
                            <div class="text-center">
                                <i class="fas fa-money-bill text-green-600 text-2xl mb-2"></i>
                                <p class="text-2xl font-bold text-green-600"><?= $report_data['financial_summary']['total_payments'] ?></p>
                                <p class="text-sm text-gray-600">عدد الدفعات</p>
                            </div>
                        </div>
                        <div class="report-card bg-blue-50 p-4 rounded-lg border border-blue-200">
                            <div class="text-center">
                                <i class="fas fa-dollar-sign text-blue-600 text-2xl mb-2"></i>
                                <p class="text-xl font-bold text-blue-600"><?= number_format($report_data['financial_summary']['total_amount']) ?></p>
                                <p class="text-sm text-gray-600">إجمالي المبلغ</p>
                            </div>
                        </div>
                        <div class="report-card bg-purple-50 p-4 rounded-lg border border-purple-200">
                            <div class="text-center">
                                <i class="fas fa-calculator text-purple-600 text-2xl mb-2"></i>
                                <p class="text-xl font-bold text-purple-600"><?= number_format($report_data['financial_summary']['avg_payment']) ?></p>
                                <p class="text-sm text-gray-600">متوسط الدفعة</p>
                            </div>
                        </div>
                        <div class="report-card bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                            <div class="text-center">
                                <i class="fas fa-users text-yellow-600 text-2xl mb-2"></i>
                                <p class="text-2xl font-bold text-yellow-600"><?= $report_data['financial_summary']['unique_patients'] ?></p>
                                <p class="text-sm text-gray-600">مرضى مختلفون</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Payments -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        تفاصيل الدفعات
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الدفع</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المريض</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العلاج</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">طريقة الدفع</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المسؤول</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($report_data['payments'] as $payment): ?>
                                    <tr class="table-row">
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= date('Y/m/d', strtotime($payment['payment_date'])) ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['patient_name']) ?></p>
                                                <p class="text-xs text-gray-500"><?= htmlspecialchars($payment['patient_phone']) ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['treatment_type'] ?? 'غير محدد') ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-green-600"><?= number_format($payment['amount']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['payment_method']) ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['created_by_name'] ?? 'غير محدد') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- No Data -->
            <div class="bg-white rounded-lg shadow-lg p-12 text-center">
                <i class="fas fa-chart-line text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">لا توجد بيانات</h3>
                <p class="text-gray-500">لا توجد بيانات متاحة للفترة والفلاتر المحددة</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>