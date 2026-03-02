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
$selected_period = $_GET['period'] ?? 'month';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';

// تحديد الفترة الزمنية
switch ($selected_period) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'quarter':
        $current_month = date('n');
        $quarter_start_month = (ceil($current_month / 3) - 1) * 3 + 1;
        $start_date = date('Y-' . sprintf('%02d', $quarter_start_month) . '-01');
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'custom':
        $start_date = $custom_start ?: date('Y-m-01');
        $end_date = $custom_end ?: date('Y-m-d');
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Helper function to safely format numbers
function safe_number_format($value, $decimals = 0) {
    return number_format((float)($value ?? 0), $decimals);
}

// جلب البيانات الإحصائية
try {
    // إحصائيات العلاجات
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_treatments,
            COUNT(DISTINCT patient_id) as unique_patients,
            COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN cost ELSE 0 END), 0) as total_revenue,
            COALESCE(AVG(CASE WHEN cost IS NOT NULL AND cost > 0 THEN cost ELSE NULL END), 0) as avg_cost,
            COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_treatments,
            COUNT(CASE WHEN payment_status = 'unpaid' AND cost > 0 THEN 1 END) as unpaid_treatments,
            COUNT(CASE WHEN payment_status = 'partial' THEN 1 END) as partial_treatments
        FROM treatments 
        WHERE doctor_id = ? AND treatment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $treatment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure all values are not null
    $treatment_stats = array_map(function($value) {
        return $value ?? 0;
    }, $treatment_stats);
    
    // أكثر أنواع العلاجات
    $stmt = $pdo->prepare("
        SELECT treatment_type, 
               COUNT(*) as count, 
               COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN cost ELSE 0 END), 0) as revenue
        FROM treatments 
        WHERE doctor_id = ? AND treatment_date BETWEEN ? AND ?
        GROUP BY treatment_type 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $treatment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure all revenue values are not null
    $treatment_types = array_map(function($row) {
        $row['revenue'] = $row['revenue'] ?? 0;
        $row['count'] = $row['count'] ?? 0;
        return $row;
    }, $treatment_types);
    
    // إحصائيات يومية للفترة
    $stmt = $pdo->prepare("
        SELECT treatment_date, 
               COUNT(*) as treatments_count,
               COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN cost ELSE 0 END), 0) as daily_revenue
        FROM treatments 
        WHERE doctor_id = ? AND treatment_date BETWEEN ? AND ?
        GROUP BY treatment_date 
        ORDER BY treatment_date
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $daily_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure daily revenue values are not null
    $daily_stats = array_map(function($row) {
        $row['daily_revenue'] = $row['daily_revenue'] ?? 0;
        $row['treatments_count'] = $row['treatments_count'] ?? 0;
        return $row;
    }, $daily_stats);
    
    // المرضى الجدد في الفترة
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT t.patient_id) as new_patients
        FROM treatments t
        WHERE t.doctor_id = ? 
        AND t.treatment_date BETWEEN ? AND ?
        AND NOT EXISTS (
            SELECT 1 FROM treatments t2 
            WHERE t2.patient_id = t.patient_id 
            AND t2.doctor_id = ? 
            AND t2.treatment_date < ?
        )
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date, $doctor_id, $start_date]);
    $new_patients_count = (int)($stmt->fetchColumn() ?? 0);
    
    // أعلى المرضى من حيث التكلفة
    $stmt = $pdo->prepare("
        SELECT p.name, p.phone, 
               COUNT(t.id) as treatments_count,
               COALESCE(SUM(CASE WHEN t.cost IS NOT NULL THEN t.cost ELSE 0 END), 0) as total_cost
        FROM treatments t
        JOIN patients p ON t.patient_id = p.id
        WHERE t.doctor_id = ? AND t.treatment_date BETWEEN ? AND ?
        GROUP BY t.patient_id, p.name, p.phone
        HAVING total_cost > 0
        ORDER BY total_cost DESC
        LIMIT 10
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $top_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure patient cost values are not null
    $top_patients = array_map(function($row) {
        $row['total_cost'] = $row['total_cost'] ?? 0;
        $row['treatments_count'] = $row['treatments_count'] ?? 0;
        return $row;
    }, $top_patients);
    
    // إحصائيات الدفع
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_collected,
            COUNT(*) as payment_count,
            payment_method,
            COUNT(*) as method_count
        FROM payments pay
        JOIN treatments t ON pay.treatment_id = t.id
        WHERE t.doctor_id = ? AND pay.payment_date BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY method_count DESC
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure payment method values are not null
    $payment_methods = array_map(function($row) {
        $row['total_collected'] = $row['total_collected'] ?? 0;
        $row['method_count'] = $row['method_count'] ?? 0;
        return $row;
    }, $payment_methods);
    
    // إجمالي المحصل
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_collected
        FROM payments pay
        JOIN treatments t ON pay.treatment_id = t.id
        WHERE t.doctor_id = ? AND pay.payment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$doctor_id, $start_date, $end_date]);
    $total_collected = (float)($stmt->fetchColumn() ?? 0);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
}

// Header configuration
$pageTitle = 'التقارير الطبية';
$pageIcon = 'fas fa-chart-bar';
$pageSubtitle = 'تقارير مفصلة عن العلاجات والإيرادات';
$currentPage = 'reports';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .report-card { transition: all 0.3s ease; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .chart-container { position: relative; height: 300px; }
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

        <!-- Period Selection -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-calendar-alt text-green-600 ml-2"></i>
                فترة التقرير
            </h3>
            
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="submit" name="period" value="today" 
                            class="<?= $selected_period === 'today' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                        اليوم
                    </button>
                    <button type="submit" name="period" value="week" 
                            class="<?= $selected_period === 'week' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                        هذا الأسبوع
                    </button>
                    <button type="submit" name="period" value="month" 
                            class="<?= $selected_period === 'month' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                        هذا الشهر
                    </button>
                    <button type="submit" name="period" value="quarter" 
                            class="<?= $selected_period === 'quarter' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                        هذا الربع
                    </button>
                    <button type="submit" name="period" value="year" 
                            class="<?= $selected_period === 'year' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                        هذا العام
                    </button>
                </div>
                
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-700">من:</label>
                    <input type="date" name="start_date" value="<?= $custom_start ?>" 
                           class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    <label class="text-sm text-gray-700">إلى:</label>
                    <input type="date" name="end_date" value="<?= $custom_end ?>" 
                           class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                    <button type="submit" name="period" value="custom" 
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition">
                        تطبيق
                    </button>
                </div>
                
                <button type="button" onclick="window.print()" 
                        class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm transition">
                    <i class="fas fa-print ml-1"></i>
                    طباعة
                </button>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500 report-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">إجمالي العلاجات</p>
                        <p class="text-3xl font-bold text-blue-600"><?= safe_number_format($treatment_stats['total_treatments']) ?></p>
                        <p class="text-xs text-gray-500 mt-1">علاج مكتمل</p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-tooth text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500 report-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">إجمالي الإيرادات</p>
                        <p class="text-3xl font-bold text-green-600"><?= safe_number_format($treatment_stats['total_revenue'], 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500 report-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المرضى المعالجين</p>
                        <p class="text-3xl font-bold text-purple-600"><?= safe_number_format($treatment_stats['unique_patients']) ?></p>
                        <p class="text-xs text-gray-500 mt-1">مريض مختلف</p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i class="fas fa-users text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-orange-500 report-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">متوسط التكلفة</p>
                        <p class="text-3xl font-bold text-orange-600"><?= safe_number_format($treatment_stats['avg_cost'], 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية / علاج</p>
                    </div>
                    <div class="bg-orange-100 p-3 rounded-full">
                        <i class="fas fa-calculator text-orange-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Daily Revenue Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h3 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-line text-blue-600 ml-2"></i>
                    الإيرادات اليومية
                </h3>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Treatment Types Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h3 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie text-green-600 ml-2"></i>
                    أنواع العلاجات
                </h3>
                <div class="chart-container">
                    <canvas id="treatmentsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Detailed Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Payment Status -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-credit-card text-purple-600 ml-2"></i>
                    حالة الدفعات
                </h3>
                
                <div class="space-y-4">
                    <div class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-green-600 ml-3"></i>
                            <span class="font-medium text-green-800">مدفوعة بالكامل</span>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-green-600"><?= safe_number_format($treatment_stats['paid_treatments']) ?></div>
                            <div class="text-sm text-green-600">
                                <?= $treatment_stats['total_treatments'] > 0 ? round(($treatment_stats['paid_treatments'] / $treatment_stats['total_treatments']) * 100, 1) : 0 ?>%
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-clock text-yellow-600 ml-3"></i>
                            <span class="font-medium text-yellow-800">مدفوعة جزئياً</span>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-yellow-600"><?= safe_number_format($treatment_stats['partial_treatments']) ?></div>
                            <div class="text-sm text-yellow-600">
                                <?= $treatment_stats['total_treatments'] > 0 ? round(($treatment_stats['partial_treatments'] / $treatment_stats['total_treatments']) * 100, 1) : 0 ?>%
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between p-4 bg-red-50 border border-red-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-times-circle text-red-600 ml-3"></i>
                            <span class="font-medium text-red-800">غير مدفوعة</span>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-red-600"><?= safe_number_format($treatment_stats['unpaid_treatments']) ?></div>
                            <div class="text-sm text-red-600">
                                <?= $treatment_stats['total_treatments'] > 0 ? round(($treatment_stats['unpaid_treatments'] / $treatment_stats['total_treatments']) * 100, 1) : 0 ?>%
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 pt-4 border-t">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">المبلغ المحصل الفعلي:</span>
                        <span class="font-bold text-green-600"><?= safe_number_format($total_collected, 2) ?> ليرة سورية</span>
                    </div>
                    <?php 
                    $outstanding = $treatment_stats['total_revenue'] - $total_collected;
                    if ($outstanding > 0): 
                    ?>
                    <div class="flex justify-between items-center mt-2">
                        <span class="text-gray-600">المبلغ المستحق:</span>
                        <span class="font-bold text-red-600"><?= safe_number_format($outstanding, 2) ?> ليرة سورية</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Patients -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-star text-yellow-600 ml-2"></i>
                    أعلى المرضى (التكلفة)
                </h3>
                
                <div class="space-y-3 max-h-80 overflow-y-auto">
                    <?php if (empty($top_patients)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-users text-4xl mb-4 opacity-50"></i>
                            <p>لا توجد بيانات للمرضى في هذه الفترة</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($top_patients as $index => $patient): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition">
                                <div class="flex items-center">
                                    <span class="bg-blue-500 text-white text-xs font-bold px-2 py-1 rounded-full ml-3">
                                        #<?= $index + 1 ?>
                                    </span>
                                    <div>
                                        <div class="font-medium text-gray-900"><?= htmlspecialchars($patient['name']) ?></div>
                                        <div class="text-sm text-gray-600"><?= htmlspecialchars($patient['phone']) ?></div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-blue-600"><?= safe_number_format($patient['total_cost'], 2) ?> ليرة سورية</div>
                                    <div class="text-sm text-gray-500"><?= safe_number_format($patient['treatments_count']) ?> علاج</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Treatment Types Table -->
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in report-card">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list-alt text-indigo-600 ml-2"></i>
                تفاصيل أنواع العلاجات
            </h3>
                        <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع العلاج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عدد العلاجات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي الإيرادات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">متوسط التكلفة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">النسبة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($treatment_types)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                    <i class="fas fa-tooth text-4xl mb-4 opacity-50"></i>
                                    <p>لا توجد علاجات في هذه الفترة</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($treatment_types as $type): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($type['treatment_type']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                            <?= number_format($type['count'] ?? 0) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <span class="font-semibold text-green-600">
                                            <?= number_format($type['revenue'] ?? 0, 2) ?> ليرة سورية
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= ($type['count'] ?? 0) > 0 ? number_format(($type['revenue'] ?? 0) / ($type['count'] ?? 1), 2) : '0.00' ?> ليرة سورية
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div class="flex items-center">
                                            <div class="w-16 bg-gray-200 rounded-full h-2 ml-2">
                                                <div class="bg-blue-600 h-2 rounded-full" 
                                                     style="width: <?= ($treatment_stats['total_treatments'] ?? 0) > 0 ? (($type['count'] ?? 0) / ($treatment_stats['total_treatments'] ?? 1)) * 100 : 0 ?>%"></div>
                                            </div>
                                            <span class="text-xs">
                                                <?= ($treatment_stats['total_treatments'] ?? 0) > 0 ? round((($type['count'] ?? 0) / ($treatment_stats['total_treatments'] ?? 1)) * 100, 1) : 0 ?>%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment Methods -->
        <?php if (!empty($payment_methods)): ?>
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in report-card">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-credit-card text-teal-600 ml-2"></i>
                طرق الدفع المستخدمة
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php 
                $payment_method_names = [
                    'cash' => ['name' => 'نقدي', 'icon' => 'fas fa-money-bill-wave', 'color' => 'green'],
                    'card' => ['name' => 'بطاقة ائتمان', 'icon' => 'fas fa-credit-card', 'color' => 'blue'],
                    'bank_transfer' => ['name' => 'تحويل بنكي', 'icon' => 'fas fa-university', 'color' => 'purple'],
                    'insurance' => ['name' => 'تأمين', 'icon' => 'fas fa-shield-alt', 'color' => 'orange']
                ];
                
                foreach ($payment_methods as $method): 
                    $method_info = $payment_method_names[$method['payment_method']] ?? ['name' => $method['payment_method'], 'icon' => 'fas fa-money-check', 'color' => 'gray'];
                ?>
                    <div class="bg-<?= $method_info['color'] ?>-50 border border-<?= $method_info['color'] ?>-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <i class="<?= $method_info['icon'] ?> text-<?= $method_info['color'] ?>-600 text-xl"></i>
                            <span class="bg-<?= $method_info['color'] ?>-100 text-<?= $method_info['color'] ?>-800 text-xs px-2 py-1 rounded-full">
                                <?= $method['method_count'] ?? 0 ?> دفعة
                            </span>
                        </div>
                        <div class="text-sm font-medium text-<?= $method_info['color'] ?>-800 mb-1">
                            <?= $method_info['name'] ?>
                        </div>
                        <div class="font-bold text-<?= $method_info['color'] ?>-600">
                            <?= number_format($method['total_collected'] ?? 0, 2) ?> ليرة سورية
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Additional Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
            <!-- New Patients -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-user-plus text-green-600 ml-2"></i>
                    المرضى الجدد
                </h4>
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-600 mb-2"><?= $new_patients_count ?? 0 ?></div>
                    <p class="text-sm text-gray-600">مريض جديد في هذه الفترة</p>
                </div>
            </div>

            <!-- Average Revenue per Day -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-chart-line text-blue-600 ml-2"></i>
                    متوسط الإيرادات اليومية
                </h4>
                <div class="text-center">
                    <?php 
                    $days_count = count($daily_stats ?? []);
                    $avg_daily_revenue = $days_count > 0 ? (($treatment_stats['total_revenue'] ?? 0) / $days_count) : 0;
                    ?>
                    <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($avg_daily_revenue, 2) ?></div>
                    <p class="text-sm text-gray-600">ليرة سورية / يوم</p>
                </div>
            </div>

            <!-- Treatment Success Rate -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in report-card">
                <h4 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-check-circle text-purple-600 ml-2"></i>
                    معدل نجاح العلاجات
                </h4>
                <div class="text-center">
                    <?php 
                    $success_rate = ($treatment_stats['total_treatments'] ?? 0) > 0 
                        ? ((($treatment_stats['paid_treatments'] ?? 0) + ($treatment_stats['partial_treatments'] ?? 0)) / ($treatment_stats['total_treatments'] ?? 1)) * 100 
                        : 0;
                    ?>
                    <div class="text-3xl font-bold text-purple-600 mb-2"><?= round($success_rate, 1) ?>%</div>
                    <p class="text-sm text-gray-600">علاجات مكتملة أو جارية</p>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-download text-gray-600 ml-2"></i>
                تصدير التقرير
            </h3>
            <div class="flex flex-wrap gap-4">
                <button onclick="exportToPDF()" class="bg-red-500 hover:bg-red-600 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-file-pdf ml-1"></i>
                    تصدير PDF
                </button>
                <button onclick="exportToExcel()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-file-excel ml-1"></i>
                    تصدير Excel
                </button>
                <button onclick="emailReport()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-envelope ml-1"></i>
                    إرسال بالإيميل
                </button>
            </div>
        </div>
    </div>

    <script>
        // رسم بياني للإيرادات اليومية
        document.addEventListener('DOMContentLoaded', function() {
            // بيانات الإيرادات اليومية
            const dailyData = <?= json_encode($daily_stats ?? []) ?>;
            const dates = dailyData.map(item => {
                const date = new Date(item.treatment_date);
                return date.toLocaleDateString('ar-SA', {month: 'short', day: 'numeric'});
            });
            const revenues = dailyData.map(item => parseFloat(item.daily_revenue || 0));

            // رسم بياني للإيرادات
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [{
                        label: 'الإيرادات اليومية (ليرة سورية)',
                        data: revenues,
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return Math.round(value).toLocaleString('ar-SA') + ' ليرة سورية';
                                }
                            }
                        }
                    }
                }
            });

            // بيانات أنواع العلاجات
            const treatmentData = <?= json_encode($treatment_types ?? []) ?>;
            const treatmentLabels = treatmentData.map(item => item.treatment_type || 'غير محدد');
            const treatmentCounts = treatmentData.map(item => parseInt(item.count || 0));

            // رسم بياني دائري لأنواع العلاجات
            const treatmentsCtx = document.getElementById('treatmentsChart').getContext('2d');
            new Chart(treatmentsCtx, {
                type: 'doughnut',
                data: {
                    labels: treatmentLabels,
                    datasets: [{
                        data: treatmentCounts,
                        backgroundColor: [
                            '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6',
                            '#06B6D4', '#84CC16', '#F97316', '#EC4899', '#6B7280'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    }
                }
            });
        });

        // دوال التصدير
        function exportToPDF() {
            alert('سيتم تطوير وظيفة تصدير PDF قريباً');
        }

        function exportToExcel() {
            alert('سيتم تطوير وظيفة تصدير Excel قريباً');
        }

        function emailReport() {
            alert('سيتم تطوير وظيفة الإرسال بالإيميل قريباً');
        }

        // إعدادات الطباعة
        window.addEventListener('beforeprint', function() {
            // إخفاء عناصر غير مرغوب في طباعتها
            document.querySelectorAll('nav, .no-print').forEach(el => {
                el.style.display = 'none';
            });
        });

        window.addEventListener('afterprint', function() {
            // إعادة إظهار العناصر
            document.querySelectorAll('nav, .no-print').forEach(el => {
                el.style.display = '';
            });
        });
    </script>

    <!-- تخصيصات للطباعة -->
    <style media="print">
        @page {
            margin: 1cm;
            size: A4;
        }
        
        body {
            font-size: 12pt;
            line-height: 1.4;
        }
        
        .no-print {
            display: none !important;
        }
        
        .report-card {
            break-inside: avoid;
            margin-bottom: 10pt;
        }
        
        .chart-container {
            height: 200px !important;
        }
        
        nav, header .no-print {
            display: none !important;
        }
        
        .fade-in {
            animation: none;
        }
        
        .shadow-lg {
            box-shadow: none !important;
            border: 1pt solid #ccc !important;
        }
    </style>
</body>
</html>