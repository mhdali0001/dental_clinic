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

// فلترة التقارير المالية
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر الحالي
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي
$doctor_id = $_GET['doctor_id'] ?? 'all';
$payment_method = $_GET['payment_method'] ?? 'all';
$view_type = $_GET['view_type'] ?? 'overview';

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
    $where_conditions[] = "DATE(p.payment_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(p.payment_date) <= ?";
    $params[] = $date_to;
}

if ($doctor_id !== 'all') {
    $where_conditions[] = "p.created_by = ?";
    $params[] = $doctor_id;
}

if ($payment_method !== 'all') {
    $where_conditions[] = "p.payment_method = ?";
    $params[] = $payment_method;
}

$where_clause = implode(' AND ', $where_conditions);

// جلب البيانات المالية
$financial_data = [];

try {
    switch ($view_type) {
        case 'overview':
            // نظرة عامة مالية
            $financial_data['overview'] = $pdo->prepare("
                SELECT
                    COUNT(p.id) as total_payments,
                    COALESCE(SUM(p.amount), 0) as total_revenue,
                    COALESCE(AVG(p.amount), 0) as avg_payment,
                    COUNT(DISTINCT p.patient_id) as unique_patients,
                    COUNT(DISTINCT p.created_by) as active_staff,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END), 0) as cash_revenue,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'card' THEN p.amount ELSE 0 END), 0) as card_revenue,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'transfer' THEN p.amount ELSE 0 END), 0) as transfer_revenue
                FROM payments p
                WHERE {$where_clause}
            ");
            $financial_data['overview']->execute($params);
            $financial_data['overview'] = $financial_data['overview']->fetch(PDO::FETCH_ASSOC);

            // إحصائيات التكاليف والأرباح
            $financial_data['profit_analysis'] = $pdo->prepare("
                SELECT
                    COALESCE(SUM(t.cost), 0) as total_treatments_cost,
                    COALESCE(SUM(p.amount), 0) as total_payments,
                    (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(p.amount), 0)) as outstanding_amount,
                    COUNT(DISTINCT t.id) as total_treatments,
                    COUNT(DISTINCT CASE WHEN t.payment_status = 'paid' THEN t.id END) as paid_treatments,
                    COUNT(DISTINCT CASE WHEN t.payment_status = 'partial' THEN t.id END) as partial_treatments,
                    COUNT(DISTINCT CASE WHEN t.payment_status = 'unpaid' THEN t.id END) as unpaid_treatments
                FROM treatments t
                LEFT JOIN payments p ON t.patient_id = p.patient_id AND DATE(p.payment_date) BETWEEN ? AND ?
                WHERE DATE(t.treatment_date) BETWEEN ? AND ?
                " . ($doctor_id !== 'all' ? "AND t.doctor_id = ?" : "")
            );

            $profit_params = [$date_from, $date_to, $date_from, $date_to];
            if ($doctor_id !== 'all') {
                $profit_params[] = $doctor_id;
            }
            $financial_data['profit_analysis']->execute($profit_params);
            $financial_data['profit_analysis'] = $financial_data['profit_analysis']->fetch(PDO::FETCH_ASSOC);

            // إيرادات حسب الطبيب
            $financial_data['by_doctor'] = $pdo->prepare("
                SELECT
                    u.full_name as doctor_name,
                    u.id as doctor_id,
                    COUNT(p.id) as payments_count,
                    COALESCE(SUM(p.amount), 0) as total_revenue,
                    COALESCE(AVG(p.amount), 0) as avg_payment,
                    COUNT(DISTINCT p.patient_id) as unique_patients,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END), 0) as cash_revenue,
                    COALESCE(SUM(CASE WHEN p.payment_method = 'card' THEN p.amount ELSE 0 END), 0) as card_revenue
                FROM payments p
                LEFT JOIN users u ON p.created_by = u.id
                WHERE {$where_clause} AND u.role = 'doctor'
                GROUP BY u.id, u.full_name
                ORDER BY total_revenue DESC
            ");
            $financial_data['by_doctor']->execute($params);
            $financial_data['by_doctor'] = $financial_data['by_doctor']->fetchAll(PDO::FETCH_ASSOC);

            // إيرادات حسب طريقة الدفع
            $financial_data['by_payment_method'] = $pdo->prepare("
                SELECT
                    p.payment_method,
                    COUNT(p.id) as count,
                    COALESCE(SUM(p.amount), 0) as total_amount,
                    COALESCE(AVG(p.amount), 0) as avg_amount
                FROM payments p
                WHERE {$where_clause}
                GROUP BY p.payment_method
                ORDER BY total_amount DESC
            ");
            $financial_data['by_payment_method']->execute($params);
            $financial_data['by_payment_method'] = $financial_data['by_payment_method']->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'daily':
            // تقرير إيرادات يومية
            $financial_data['daily_revenue'] = [];
            $current_date = new DateTime($date_from);
            $end_date = new DateTime($date_to);

            while ($current_date <= $end_date) {
                $date = $current_date->format('Y-m-d');

                $daily_query = $pdo->prepare("
                    SELECT
                        DATE(p.payment_date) as payment_date,
                        COUNT(p.id) as payments_count,
                        COALESCE(SUM(p.amount), 0) as total_revenue,
                        COUNT(DISTINCT p.patient_id) as unique_patients,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END), 0) as cash_revenue,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'card' THEN p.amount ELSE 0 END), 0) as card_revenue,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'transfer' THEN p.amount ELSE 0 END), 0) as transfer_revenue
                    FROM payments p
                    WHERE DATE(p.payment_date) = ?
                    " . ($doctor_id !== 'all' ? "AND p.created_by = ?" : "") . "
                    " . ($payment_method !== 'all' ? "AND p.payment_method = ?" : "") . "
                    GROUP BY DATE(p.payment_date)
                ");

                $daily_params = [$date];
                if ($doctor_id !== 'all') $daily_params[] = $doctor_id;
                if ($payment_method !== 'all') $daily_params[] = $payment_method;

                $daily_query->execute($daily_params);
                $daily_result = $daily_query->fetch(PDO::FETCH_ASSOC);

                if (!$daily_result) {
                    $daily_result = [
                        'payment_date' => $date,
                        'payments_count' => 0,
                        'total_revenue' => 0,
                        'unique_patients' => 0,
                        'cash_revenue' => 0,
                        'card_revenue' => 0,
                        'transfer_revenue' => 0
                    ];
                }

                $financial_data['daily_revenue'][] = $daily_result;
                $current_date->add(new DateInterval('P1D'));
            }
            break;

        case 'detailed':
            // تقرير مفصل للدفعات
            $financial_data['detailed_payments'] = $pdo->prepare("
                SELECT
                    p.*,
                    pt.name as patient_name,
                    pt.phone as patient_phone,
                    u.full_name as staff_name,
                    t.treatment_type,
                    t.cost as treatment_cost,
                    t.treatment_date
                FROM payments p
                JOIN patients pt ON p.patient_id = pt.id
                LEFT JOIN users u ON p.created_by = u.id
                LEFT JOIN treatments t ON p.treatment_id = t.id
                WHERE {$where_clause}
                ORDER BY p.payment_date DESC, p.created_at DESC
            ");
            $financial_data['detailed_payments']->execute($params);
            $financial_data['detailed_payments'] = $financial_data['detailed_payments']->fetchAll(PDO::FETCH_ASSOC);
            break;
    }

    // بيانات للرسوم البيانية - آخر 30 يوم
    $chart_data = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_data['dates'][] = date('m/d', strtotime($date));

        $revenue_query = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as revenue, COUNT(id) as payments
            FROM payments
            WHERE DATE(payment_date) = ?
            " . ($doctor_id !== 'all' ? "AND created_by = ?" : "")
        );

        $chart_params = [$date];
        if ($doctor_id !== 'all') $chart_params[] = $doctor_id;

        $revenue_query->execute($chart_params);
        $result = $revenue_query->fetch(PDO::FETCH_ASSOC);

        $chart_data['revenue'][] = $result['revenue'];
        $chart_data['payments'][] = $result['payments'];
    }

} catch (PDOException $e) {
    $error_message = "خطأ في جلب البيانات المالية: " . $e->getMessage();
    $financial_data = [];
    $chart_data = ['dates' => [], 'revenue' => [], 'payments' => []];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير المالية - إدارة العيادة</title>
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
        .financial-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .financial-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/admin_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">التقارير المالية</h1>
            <p class="mt-2 text-gray-600">إدارة ومتابعة الإيرادات والمدفوعات</p>
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
                فلترة التقارير المالية
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
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
                    <label class="block text-gray-700 font-medium mb-2">طريقة الدفع</label>
                    <select name="payment_method" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?= $payment_method === 'all' ? 'selected' : '' ?>>جميع الطرق</option>
                        <option value="cash" <?= $payment_method === 'cash' ? 'selected' : '' ?>>نقدي</option>
                        <option value="card" <?= $payment_method === 'card' ? 'selected' : '' ?>>بطاقة</option>
                        <option value="transfer" <?= $payment_method === 'transfer' ? 'selected' : '' ?>>تحويل</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">نوع العرض</label>
                    <select name="view_type" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="overview" <?= $view_type === 'overview' ? 'selected' : '' ?>>نظرة عامة</option>
                        <option value="daily" <?= $view_type === 'daily' ? 'selected' : '' ?>>تقرير يومي</option>
                        <option value="detailed" <?= $view_type === 'detailed' ? 'selected' : '' ?>>تقرير مفصل</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-chart-bar ml-1"></i>
                        عرض التقرير
                    </button>
                </div>
            </form>
        </div>

        <!-- Revenue Chart -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-chart-line text-green-600 ml-2"></i>
                منحنى الإيرادات - آخر 30 يوم
            </h3>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Financial Content based on view type -->
        <?php if ($view_type === 'overview' && !empty($financial_data)): ?>
            <!-- Overview Report -->
            <div class="space-y-6">
                <!-- Financial Overview Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="financial-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                        <div class="flex items-center">
                            <div class="bg-green-100 p-3 rounded-full">
                                <i class="fas fa-dollar-sign text-green-600 text-2xl"></i>
                            </div>
                            <div class="mr-4 flex-1">
                                <p class="text-sm font-medium text-gray-600">إجمالي الإيرادات</p>
                                <p class="text-2xl font-bold text-green-600"><?= number_format($financial_data['overview']['total_revenue']) ?></p>
                                <p class="text-xs text-gray-500">ليرة سورية</p>
                            </div>
                        </div>
                    </div>

                    <div class="financial-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                        <div class="flex items-center">
                            <div class="bg-blue-100 p-3 rounded-full">
                                <i class="fas fa-credit-card text-blue-600 text-2xl"></i>
                            </div>
                            <div class="mr-4 flex-1">
                                <p class="text-sm font-medium text-gray-600">عدد الدفعات</p>
                                <p class="text-2xl font-bold text-blue-600"><?= $financial_data['overview']['total_payments'] ?></p>
                                <p class="text-xs text-gray-500">دفعة</p>
                            </div>
                        </div>
                    </div>

                    <div class="financial-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="bg-purple-100 p-3 rounded-full">
                                <i class="fas fa-calculator text-purple-600 text-2xl"></i>
                            </div>
                            <div class="mr-4 flex-1">
                                <p class="text-sm font-medium text-gray-600">متوسط الدفعة</p>
                                <p class="text-2xl font-bold text-purple-600"><?= number_format($financial_data['overview']['avg_payment']) ?></p>
                                <p class="text-xs text-gray-500">ليرة سورية</p>
                            </div>
                        </div>
                    </div>

                    <div class="financial-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500">
                        <div class="flex items-center">
                            <div class="bg-yellow-100 p-3 rounded-full">
                                <i class="fas fa-users text-yellow-600 text-2xl"></i>
                            </div>
                            <div class="mr-4 flex-1">
                                <p class="text-sm font-medium text-gray-600">مرضى مختلفون</p>
                                <p class="text-2xl font-bold text-yellow-600"><?= $financial_data['overview']['unique_patients'] ?></p>
                                <p class="text-xs text-gray-500">مريض</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Distribution -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">
                            <i class="fas fa-chart-pie text-blue-600 ml-2"></i>
                            توزيع طرق الدفع
                        </h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-money-bill text-green-600 ml-3"></i>
                                    <span class="font-medium">نقدي</span>
                                </div>
                                <span class="text-green-600 font-bold"><?= number_format($financial_data['overview']['cash_revenue']) ?> ل.س</span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-credit-card text-blue-600 ml-3"></i>
                                    <span class="font-medium">بطاقة</span>
                                </div>
                                <span class="text-blue-600 font-bold"><?= number_format($financial_data['overview']['card_revenue']) ?> ل.س</span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-purple-50 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-exchange-alt text-purple-600 ml-3"></i>
                                    <span class="font-medium">تحويل</span>
                                </div>
                                <span class="text-purple-600 font-bold"><?= number_format($financial_data['overview']['transfer_revenue']) ?> ل.س</span>
                            </div>
                        </div>
                    </div>

                    <!-- Profit Analysis -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">
                            <i class="fas fa-chart-area text-green-600 ml-2"></i>
                            تحليل الأرباح
                        </h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">إجمالي تكلفة العلاجات</span>
                                <span class="font-bold text-gray-900"><?= number_format($financial_data['profit_analysis']['total_treatments_cost']) ?> ل.س</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">إجمالي المدفوعات</span>
                                <span class="font-bold text-green-600"><?= number_format($financial_data['profit_analysis']['total_payments']) ?> ل.س</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">المبلغ المتبقي</span>
                                <span class="font-bold text-red-600"><?= number_format($financial_data['profit_analysis']['outstanding_amount']) ?> ل.س</span>
                            </div>
                            <div class="border-t pt-3">
                                <div class="grid grid-cols-3 gap-3 text-center">
                                    <div>
                                        <p class="text-sm text-gray-500">مدفوع</p>
                                        <p class="font-bold text-green-600"><?= $financial_data['profit_analysis']['paid_treatments'] ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">جزئي</p>
                                        <p class="font-bold text-yellow-600"><?= $financial_data['profit_analysis']['partial_treatments'] ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">غير مدفوع</p>
                                        <p class="font-bold text-red-600"><?= $financial_data['profit_analysis']['unpaid_treatments'] ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Revenue by Doctor -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-user-md text-blue-600 ml-2"></i>
                        الإيرادات حسب الطبيب
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">الطبيب</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الدفعات</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي الإيرادات</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">متوسط الدفعة</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المرضى</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">نقدي</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">بطاقة</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($financial_data['by_doctor'] as $doctor): ?>
                                    <tr class="hover:bg-gray-50">
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
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= $doctor['payments_count'] ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm font-bold text-green-600"><?= number_format($doctor['total_revenue']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($doctor['avg_payment']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600"><?= $doctor['unique_patients'] ?></td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($doctor['cash_revenue']) ?> ل.س</td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600"><?= number_format($doctor['card_revenue']) ?> ل.س</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($view_type === 'daily' && !empty($financial_data['daily_revenue'])): ?>
            <!-- Daily Revenue Report -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-calendar-day text-blue-600 ml-2"></i>
                    تقرير الإيرادات اليومية
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الدفعات</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي الإيرادات</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المرضى</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">نقدي</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">بطاقة</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">تحويل</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($financial_data['daily_revenue'] as $day): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= date('Y/m/d', strtotime($day['payment_date'])) ?>
                                        <br>
                                        <span class="text-xs text-gray-500"><?= date('l', strtotime($day['payment_date'])) ?></span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= $day['payments_count'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-bold text-green-600"><?= number_format($day['total_revenue']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600"><?= $day['unique_patients'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($day['cash_revenue']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600"><?= number_format($day['card_revenue']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-purple-600"><?= number_format($day['transfer_revenue']) ?> ل.س</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($view_type === 'detailed' && !empty($financial_data['detailed_payments'])): ?>
            <!-- Detailed Payments Report -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-list text-blue-600 ml-2"></i>
                    تفاصيل الدفعات
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">تاريخ الدفع</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المريض</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">العلاج</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">طريقة الدفع</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الإيصال</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المسؤول</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($financial_data['detailed_payments'] as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= date('Y/m/d', strtotime($payment['payment_date'])) ?>
                                        <br>
                                        <span class="text-xs text-gray-500"><?= date('H:i', strtotime($payment['created_at'])) ?></span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['patient_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($payment['patient_phone']) ?></p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($payment['treatment_type'] ?? 'غير محدد') ?>
                                        <?php if ($payment['treatment_cost']): ?>
                                            <br><span class="text-xs text-gray-500">تكلفة: <?= number_format($payment['treatment_cost']) ?> ل.س</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-bold text-green-600"><?= number_format($payment['amount']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?= $payment['payment_method'] === 'cash' ? 'bg-green-100 text-green-800' :
                                               ($payment['payment_method'] === 'card' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800') ?>">
                                            <?= $payment['payment_method'] === 'cash' ? 'نقدي' :
                                               ($payment['payment_method'] === 'card' ? 'بطاقة' : 'تحويل') ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['receipt_number'] ?? '-') ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['staff_name'] ?? 'غير محدد') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- No Data -->
            <div class="bg-white rounded-lg shadow-lg p-12 text-center">
                <i class="fas fa-chart-bar text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">لا توجد بيانات مالية</h3>
                <p class="text-gray-500">لا توجد بيانات متاحة للفترة والفلاتر المحددة</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['dates']) ?>,
                datasets: [
                    {
                        label: 'الإيرادات (ليرة سورية)',
                        data: <?= json_encode($chart_data['revenue']) ?>,
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'عدد الدفعات',
                        data: <?= json_encode($chart_data['payments']) ?>,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'الإيرادات (ليرة سورية)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'عدد الدفعات'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });
    </script>
</body>
</html>