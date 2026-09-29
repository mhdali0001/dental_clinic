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

// فلترة التحليلات
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر الحالي
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي
$doctor_id = $_GET['doctor_id'] ?? 'all';

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

// جلب البيانات التحليلية
$analytics_data = [];

try {
    // إحصائيات الأداء العامة
    $analytics_data['performance'] = $pdo->prepare("
        SELECT
            u.id as user_id,
            u.full_name,
            u.username,
            COUNT(DISTINCT t.patient_id) as unique_patients,
            COUNT(t.id) as total_treatments,
            COUNT(DISTINCT a.id) as total_appointments,
            COALESCE(SUM(t.cost), 0) as total_revenue,
            COALESCE(AVG(t.cost), 0) as avg_treatment_cost,
            COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_appointments,
            COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) as cancelled_appointments,
            (COUNT(CASE WHEN a.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(DISTINCT a.id), 0)) as completion_rate
        FROM users u
        LEFT JOIN treatments t ON u.id = t.doctor_id
            AND DATE(t.treatment_date) BETWEEN ? AND ?
        LEFT JOIN appointments a ON u.id = a.created_by
            AND DATE(a.appointment_date) BETWEEN ? AND ?
        WHERE u.role = 'doctor' AND u.is_active = 1
        " . ($doctor_id !== 'all' ? "AND u.id = ?" : "") . "
        GROUP BY u.id, u.full_name, u.username
        ORDER BY COUNT(t.id) DESC
    ");

    $params = [$date_from, $date_to, $date_from, $date_to];
    if ($doctor_id !== 'all') {
        $params[] = $doctor_id;
    }
    $analytics_data['performance']->execute($params);
    $analytics_data['performance'] = $analytics_data['performance']->fetchAll(PDO::FETCH_ASSOC);

    // بيانات الأداء اليومي للرسم البياني
    $analytics_data['daily_performance'] = [];
    $date_range = [];
    $current_date = new DateTime($date_from);
    $end_date = new DateTime($date_to);

    while ($current_date <= $end_date) {
        $date = $current_date->format('Y-m-d');
        $date_range[] = $date;

        $daily_query = $pdo->prepare("
            SELECT
                u.id as user_id,
                u.full_name,
                COUNT(t.id) as treatments,
                COUNT(a.id) as appointments,
                COALESCE(SUM(t.cost), 0) as revenue
            FROM users u
            LEFT JOIN treatments t ON u.id = t.doctor_id
                AND DATE(t.treatment_date) = ?
            LEFT JOIN appointments a ON u.id = a.created_by
                AND DATE(a.appointment_date) = ?
            WHERE u.role = 'doctor' AND u.is_active = 1
            " . ($doctor_id !== 'all' ? "AND u.id = ?" : "") . "
            GROUP BY u.id, u.full_name
        ");

        $daily_params = [$date, $date];
        if ($doctor_id !== 'all') {
            $daily_params[] = $doctor_id;
        }
        $daily_query->execute($daily_params);
        $analytics_data['daily_performance'][$date] = $daily_query->fetchAll(PDO::FETCH_ASSOC);

        $current_date->add(new DateInterval('P1D'));
    }

    // تحليل أنواع العلاجات
    $analytics_data['treatment_types'] = $pdo->prepare("
        SELECT
            t.treatment_type,
            u.full_name as doctor_name,
            COUNT(t.id) as count,
            COALESCE(SUM(t.cost), 0) as total_cost,
            COALESCE(AVG(t.cost), 0) as avg_cost
        FROM treatments t
        JOIN users u ON t.doctor_id = u.id
        WHERE DATE(t.treatment_date) BETWEEN ? AND ?
        " . ($doctor_id !== 'all' ? "AND u.id = ?" : "") . "
        GROUP BY t.treatment_type, u.id, u.full_name
        ORDER BY count DESC
    ");

    $treatment_params = [$date_from, $date_to];
    if ($doctor_id !== 'all') {
        $treatment_params[] = $doctor_id;
    }
    $analytics_data['treatment_types']->execute($treatment_params);
    $analytics_data['treatment_types'] = $analytics_data['treatment_types']->fetchAll(PDO::FETCH_ASSOC);

    // تحليل معدلات الدفع
    $analytics_data['payment_analysis'] = $pdo->prepare("
        SELECT
            u.full_name as doctor_name,
            COUNT(t.id) as total_treatments,
            COUNT(CASE WHEN t.payment_status = 'paid' THEN 1 END) as paid_treatments,
            COUNT(CASE WHEN t.payment_status = 'partial' THEN 1 END) as partial_treatments,
            COUNT(CASE WHEN t.payment_status = 'unpaid' THEN 1 END) as unpaid_treatments,
            (COUNT(CASE WHEN t.payment_status = 'paid' THEN 1 END) * 100.0 / NULLIF(COUNT(t.id), 0)) as payment_rate
        FROM treatments t
        JOIN users u ON t.doctor_id = u.id
        WHERE DATE(t.treatment_date) BETWEEN ? AND ?
        " . ($doctor_id !== 'all' ? "AND u.id = ?" : "") . "
        GROUP BY u.id, u.full_name
        ORDER BY payment_rate DESC
    ");

    $payment_params = [$date_from, $date_to];
    if ($doctor_id !== 'all') {
        $payment_params[] = $doctor_id;
    }
    $analytics_data['payment_analysis']->execute($payment_params);
    $analytics_data['payment_analysis'] = $analytics_data['payment_analysis']->fetchAll(PDO::FETCH_ASSOC);

    // إحصائيات المواعيد والحضور
    $analytics_data['appointment_analysis'] = $pdo->prepare("
        SELECT
            u.full_name as doctor_name,
            COUNT(a.id) as total_appointments,
            COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) as cancelled,
            COUNT(CASE WHEN a.status = 'no_show' THEN 1 END) as no_show,
            (COUNT(CASE WHEN a.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0)) as completion_rate,
            (COUNT(CASE WHEN a.status = 'cancelled' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id), 0)) as cancellation_rate
        FROM appointments a
        JOIN users u ON a.created_by = u.id
        WHERE DATE(a.appointment_date) BETWEEN ? AND ?
        " . ($doctor_id !== 'all' ? "AND u.id = ?" : "") . "
        GROUP BY u.id, u.full_name
        ORDER BY completion_rate DESC
    ");

    $appointment_params = [$date_from, $date_to];
    if ($doctor_id !== 'all') {
        $appointment_params[] = $doctor_id;
    }
    $analytics_data['appointment_analysis']->execute($appointment_params);
    $analytics_data['appointment_analysis'] = $analytics_data['appointment_analysis']->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "خطأ في جلب البيانات التحليلية: " . $e->getMessage();
    $analytics_data = [];
}

// تحضير البيانات للرسوم البيانية
$chart_data = [
    'dates' => [],
    'doctors' => [],
    'treatments' => [],
    'appointments' => [],
    'revenue' => []
];

if (!empty($analytics_data['daily_performance'])) {
    $chart_data['dates'] = array_keys($analytics_data['daily_performance']);

    // تجميع البيانات حسب الطبيب
    $doctors_chart_data = [];
    foreach ($analytics_data['daily_performance'] as $date => $doctors_data) {
        foreach ($doctors_data as $doctor) {
            if (!isset($doctors_chart_data[$doctor['full_name']])) {
                $doctors_chart_data[$doctor['full_name']] = [
                    'treatments' => [],
                    'appointments' => [],
                    'revenue' => []
                ];
            }
            $doctors_chart_data[$doctor['full_name']]['treatments'][] = $doctor['treatments'];
            $doctors_chart_data[$doctor['full_name']]['appointments'][] = $doctor['appointments'];
            $doctors_chart_data[$doctor['full_name']]['revenue'][] = $doctor['revenue'];
        }
    }
    $chart_data['doctors_data'] = $doctors_chart_data;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحليلات الأداء - إدارة العيادة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-gradient {
            background: #f7fafc;
        }
        .analytics-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }
        .analytics-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            background: rgba(255, 255, 255, 1);
        }
        .metric-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.05));
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }
        .metric-card:hover {
            background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
            transform: scale(1.05);
        }
        .chart-container {
            position: relative;
            height: 400px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px;
        }
        .floating-card {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .performance-badge {
            background: linear-gradient(45deg, #ff6b6b, #ffa500);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        .fade-slide-in {
            animation: fadeSlideIn 0.8s ease-out;
        }
        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .pulse-glow {
            animation: pulseGlow 2s infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(102, 126, 234, 0.3); }
            50% { box-shadow: 0 0 40px rgba(102, 126, 234, 0.6); }
        }
    </style>
</head>
<body class="analytics-gradient min-h-screen">
    <?php
    $pageTitle = 'تحليلات الأداء المتقدمة';
    $pageIcon = 'fas fa-chart-line';
    $pageSubtitle = 'لوحة تحكم تحليلية شاملة';
    include 'includes/admin_header.php';
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Enhanced Page Header -->
        <div class="text-center mb-12 fade-slide-in">
            <div class="inline-block p-4 bg-blue-500 rounded-full mb-4 pulse-glow">
                <i class="fas fa-chart-line text-4xl text-white"></i>
            </div>
            <h1 class="text-4xl font-bold text-black mb-2 drop-shadow-lg">تحليلات الأداء المتقدمة</h1>
            <p class="text-black text-lg">رؤى عميقة وتحليل شامل لأداء العيادة والأطباء</p>
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
                فلترة التحليلات
            </h3>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
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

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-chart-line ml-1"></i>
                        تحليل البيانات
                    </button>
                </div>
            </form>
        </div>

        <!-- Performance Overview -->
        <?php if (!empty($analytics_data['performance'])): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
                <?php foreach ($analytics_data['performance'] as $doctor): ?>
                    <div class="performance-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                        <div class="flex items-center mb-4">
                            <div class="w-12 h-12 bg-blue-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-md text-white text-lg"></i>
                            </div>
                            <div class="mr-4">
                                <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($doctor['full_name']) ?></h3>
                                <p class="text-sm text-gray-500">@<?= htmlspecialchars($doctor['username']) ?></p>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">المرضى</span>
                                <span class="text-lg font-bold text-blue-600"><?= $doctor['unique_patients'] ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">العلاجات</span>
                                <span class="text-lg font-bold text-green-600"><?= $doctor['total_treatments'] ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">المواعيد</span>
                                <span class="text-lg font-bold text-purple-600"><?= $doctor['total_appointments'] ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">الإيرادات</span>
                                <span class="text-lg font-bold text-yellow-600"><?= number_format($doctor['total_revenue']) ?> ل.س</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">معدل الإنجاز</span>
                                <span class="text-lg font-bold text-green-600"><?= number_format($doctor['completion_rate'] ?? 0, 1) ?>%</span>
                            </div>
                        </div>

                        <!-- Progress bars -->
                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-gray-600 mb-1">
                                <span>معدل الإنجاز</span>
                                <span><?= number_format($doctor['completion_rate'] ?? 0, 1) ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-green-500 h-2 rounded-full" style="width: <?= min($doctor['completion_rate'] ?? 0, 100) ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Daily Performance Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-line text-blue-600 ml-2"></i>
                    الأداء اليومي
                </h3>
                <div class="chart-container">
                    <canvas id="dailyPerformanceChart"></canvas>
                </div>
            </div>

            <!-- Treatment Types Distribution -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie text-green-600 ml-2"></i>
                    توزيع أنواع العلاجات
                </h3>
                <div class="chart-container">
                    <canvas id="treatmentTypesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Enhanced Analysis Tables -->
        <div class="mb-8">
            <div class="flex items-center mb-6">
                <div class="p-3 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-full pulse-glow">
                    <i class="fas fa-table text-white text-xl"></i>
                </div>
                <div class="mr-4">
                    <h2 class="text-2xl font-bold text-black">التحليلات التفصيلية</h2>
                    <p class="text-black">جداول شاملة للمعايير المختلفة</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Enhanced Payment Analysis -->
                <div class="analytics-card rounded-2xl p-6 floating-card">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-purple-500 rounded-lg">
                                <i class="fas fa-credit-card text-white"></i>
                            </div>
                            <div class="mr-3">
                                <h3 class="text-lg font-bold text-gray-800">تحليل معدلات الدفع</h3>
                                <p class="text-sm text-gray-600">حالة المدفوعات لكل طبيب</p>
                            </div>
                        </div>
                        <button onclick="exportTable('payment')" class="px-3 py-1 bg-purple-100 text-purple-600 rounded-lg text-xs hover:bg-purple-200 transition-colors">
                            <i class="fas fa-download ml-1"></i>تصدير
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead>
                                <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">الطبيب</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">مدفوع</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">جزئي</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">غير مدفوع</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">المعدل</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($analytics_data['payment_analysis'] ?? [] as $index => $payment): ?>
                                    <tr class="hover:bg-gray-50 transition-colors" style="animation-delay: <?= $index * 0.05 ?>s">
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center mr-2">
                                                    <i class="fas fa-user-md text-purple-600 text-xs"></i>
                                                </div>
                                                <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($payment['doctor_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i><?= $payment['paid_treatments'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                <i class="fas fa-clock mr-1"></i><?= $payment['partial_treatments'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle mr-1"></i><?= $payment['unpaid_treatments'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                    <div class="bg-blue-600 h-2 rounded-full" style="width: <?= $payment['payment_rate'] ?>%"></div>
                                                </div>
                                                <span class="text-sm font-bold text-blue-600"><?= number_format($payment['payment_rate'], 1) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Enhanced Appointment Analysis -->
                <div class="analytics-card rounded-2xl p-6 floating-card">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <div class="p-2 bg-orange-500 rounded-lg">
                                <i class="fas fa-calendar-check text-white"></i>
                            </div>
                            <div class="mr-3">
                                <h3 class="text-lg font-bold text-gray-800">تحليل المواعيد</h3>
                                <p class="text-sm text-gray-600">إحصائيات المواعيد والحضور</p>
                            </div>
                        </div>
                        <button onclick="exportTable('appointment')" class="px-3 py-1 bg-orange-100 text-orange-600 rounded-lg text-xs hover:bg-orange-200 transition-colors">
                            <i class="fas fa-download ml-1"></i>تصدير
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead>
                                <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">الطبيب</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">مكتمل</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">ملغي</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">لم يحضر</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">معدل الإنجاز</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($analytics_data['appointment_analysis'] ?? [] as $index => $appointment): ?>
                                    <tr class="hover:bg-gray-50 transition-colors" style="animation-delay: <?= $index * 0.05 ?>s">
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center mr-2">
                                                    <i class="fas fa-user-md text-orange-600 text-xs"></i>
                                                </div>
                                                <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($appointment['doctor_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check mr-1"></i><?= $appointment['completed'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-ban mr-1"></i><?= $appointment['cancelled'] ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-user-slash mr-1"></i><?= $appointment['no_show'] ?? 0 ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                    <div class="bg-green-600 h-2 rounded-full" style="width: <?= $appointment['completion_rate'] ?? 0 ?>%"></div>
                                                </div>
                                                <span class="text-sm font-bold text-green-600"><?= number_format($appointment['completion_rate'] ?? 0, 1) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Treatment Types Analysis -->
        <div class="analytics-card rounded-2xl p-6 floating-card">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center">
                    <div class="p-3 bg-gradient-to-r from-teal-500 to-cyan-500 rounded-full">
                        <i class="fas fa-medical-kit text-white text-xl"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-2xl font-bold text-gray-800">تحليل أنواع العلاجات</h2>
                        <p class="text-gray-600">تفصيل شامل للعلاجات المختلفة وأدائها</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="sortTable('treatment', 'count')" class="px-3 py-1 bg-teal-100 text-teal-600 rounded-lg text-xs hover:bg-teal-200 transition-colors">
                        <i class="fas fa-sort-amount-down ml-1"></i>ترتيب بالعدد
                    </button>
                    <button onclick="exportTable('treatment')" class="px-3 py-1 bg-teal-100 text-teal-600 rounded-lg text-xs hover:bg-teal-200 transition-colors">
                        <i class="fas fa-download ml-1"></i>تصدير
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto" id="treatmentTable">
                    <thead>
                        <tr class="bg-gradient-to-r from-teal-50 to-cyan-50">
                            <th class="px-4 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">نوع العلاج</th>
                            <th class="px-4 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">الطبيب</th>
                            <th class="px-4 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-teal-100" onclick="sortTable('treatment', 'count')">
                                العدد <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-4 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-teal-100" onclick="sortTable('treatment', 'total')">
                                إجمالي التكلفة <i class="fas fa-sort ml-1"></i>
                            </th>
                            <th class="px-4 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-teal-100" onclick="sortTable('treatment', 'avg')">
                                متوسط التكلفة <i class="fas fa-sort ml-1"></i>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($analytics_data['treatment_types'] ?? [] as $index => $treatment): ?>
                            <tr class="hover:bg-gradient-to-r hover:from-teal-50 hover:to-cyan-50 transition-all duration-300" style="animation-delay: <?= $index * 0.05 ?>s">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-gradient-to-br from-teal-400 to-cyan-500 rounded-xl flex items-center justify-center mr-3">
                                            <i class="fas fa-tooth text-white"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-900"><?= htmlspecialchars($treatment['treatment_type']) ?></div>
                                            <div class="text-xs text-gray-500">علاج متخصص</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 bg-teal-100 rounded-full flex items-center justify-center mr-2">
                                            <i class="fas fa-user-md text-teal-600 text-xs"></i>
                                        </div>
                                        <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($treatment['doctor_name']) ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 text-blue-800 rounded-full font-bold text-lg">
                                        <?= $treatment['count'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <div class="text-lg font-bold text-green-600"><?= number_format($treatment['total_cost']) ?></div>
                                    <div class="text-xs text-gray-500">ليرة سورية</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <div class="text-lg font-bold text-purple-600"><?= number_format($treatment['avg_cost']) ?></div>
                                    <div class="text-xs text-gray-500">متوسط التكلفة</div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Chart variables
        let dailyChart, treatmentChart, revenueChart;
        let currentChartTypes = {
            daily: 'line',
            treatment: 'doughnut'
        };

        // Chart data
        const chartData = {
            dates: <?= json_encode(array_map(function($date) { return date('m/d', strtotime($date)); }, $chart_data['dates'])) ?>,
            treatments: <?= json_encode(array_map(function($date) use ($analytics_data) {
                return array_sum(array_column($analytics_data['daily_performance'][$date] ?? [], 'treatments'));
            }, $chart_data['dates'])) ?>,
            appointments: <?= json_encode(array_map(function($date) use ($analytics_data) {
                return array_sum(array_column($analytics_data['daily_performance'][$date] ?? [], 'appointments'));
            }, $chart_data['dates'])) ?>,
            revenue: <?= json_encode(array_map(function($date) use ($analytics_data) {
                return array_sum(array_column($analytics_data['daily_performance'][$date] ?? [], 'revenue'));
            }, $chart_data['dates'])) ?>,
            treatmentTypes: <?= json_encode(array_column($analytics_data['treatment_types'] ?? [], 'treatment_type')) ?>,
            treatmentCounts: <?= json_encode(array_column($analytics_data['treatment_types'] ?? [], 'count')) ?>
        };

        // Initialize Charts
        function initializeCharts() {
            // Daily Performance Chart
            const dailyCtx = document.getElementById('dailyPerformanceChart').getContext('2d');
            dailyChart = new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: chartData.dates,
                    datasets: [
                        {
                            label: 'العلاجات',
                            data: chartData.treatments,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: 'rgb(59, 130, 246)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        },
                        {
                            label: 'المواعيد',
                            data: chartData.appointments,
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: 'rgb(16, 185, 129)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: 12,
                                    weight: 'bold'
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });

            // Treatment Types Chart
            const treatmentCtx = document.getElementById('treatmentTypesChart').getContext('2d');
            treatmentChart = new Chart(treatmentCtx, {
                type: 'doughnut',
                data: {
                    labels: chartData.treatmentTypes,
                    datasets: [{
                        data: chartData.treatmentCounts,
                        backgroundColor: [
                            '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                            '#06b6d4', '#84cc16', '#f97316', '#ec4899', '#6b7280'
                        ],
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverBorderWidth: 5,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 2000,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return `${context.label}: ${context.parsed} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Revenue Chart (only when the page has its canvas)
            const revenueCanvas = document.getElementById('revenueChart');
            if (revenueCanvas) revenueChart = new Chart(revenueCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: chartData.dates,
                    datasets: [{
                        label: 'الإيرادات اليومية',
                        data: chartData.revenue,
                        backgroundColor: 'rgba(245, 158, 11, 0.8)',
                        borderColor: 'rgb(245, 158, 11)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return `الإيرادات: ${context.parsed.y.toLocaleString()} ل.س`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + ' ل.س';
                                },
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }

        // Interactive Functions
        function changeChartType(chartId, newType) {
            const chartMap = {
                'dailyPerformanceChart': 'daily',
                'treatmentTypesChart': 'treatment'
            };

            const chartKey = chartMap[chartId];
            currentChartTypes[chartKey] = newType;

            // Update button states
            document.querySelectorAll(`[data-chart="${chartKey}"]`).forEach(btn => {
                btn.classList.remove('bg-blue-100', 'text-blue-600', 'bg-green-100', 'text-green-600');
                btn.classList.add('bg-gray-100', 'text-gray-600');
            });

            document.querySelector(`[data-chart="${chartKey}"][data-type="${newType}"]`).classList.remove('bg-gray-100', 'text-gray-600');

            if (chartKey === 'daily') {
                document.querySelector(`[data-chart="${chartKey}"][data-type="${newType}"]`).classList.add('bg-blue-100', 'text-blue-600');
                dailyChart.config.type = newType;
                dailyChart.update('active');
            } else if (chartKey === 'treatment') {
                document.querySelector(`[data-chart="${chartKey}"][data-type="${newType}"]`).classList.add('bg-green-100', 'text-green-600');
                treatmentChart.config.type = newType;
                treatmentChart.update('active');
            }
        }

        function toggleView(viewType) {
            const gridBtn = document.getElementById('gridViewBtn');
            const listBtn = document.getElementById('listViewBtn');
            const performanceGrid = document.getElementById('performanceGrid');

            if (viewType === 'grid') {
                gridBtn.classList.add('bg-white/30');
                listBtn.classList.remove('bg-white/30');
                performanceGrid.className = 'grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6';
            } else {
                listBtn.classList.add('bg-white/30');
                gridBtn.classList.remove('bg-white/30');
                performanceGrid.className = 'grid grid-cols-1 gap-4';
            }
        }

        function refreshCharts() {
            dailyChart.update('resize');
            treatmentChart.update('resize');
            revenueChart?.update('resize');

            // Show refresh animation
            const refreshBtns = document.querySelectorAll('[onclick="refreshCharts()"]');
            refreshBtns.forEach(btn => {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin ml-1"></i>تحديث...';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-sync-alt ml-1"></i>تحديث';
                }, 1000);
            });
        }

        function resetFilters() {
            document.querySelector('input[name="date_from"]').value = '<?= date('Y-m-01') ?>';
            document.querySelector('input[name="date_to"]').value = '<?= date('Y-m-d') ?>';
            document.querySelector('select[name="doctor_id"]').value = 'all';
        }

        function exportReport() {
            alert('سيتم إضافة ميزة التصدير قريباً');
        }

        function exportTable(tableType) {
            alert(`سيتم تصدير جدول ${tableType} قريباً`);
        }

        function sortTable(tableType, column) {
            alert(`سيتم ترتيب الجدول حسب ${column} قريباً`);
        }

        function fullscreenChart() {
            alert('سيتم إضافة ميزة ملء الشاشة قريباً');
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();

            // Add loading animation effects
            const cards = document.querySelectorAll('.fade-slide-in');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Add smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>