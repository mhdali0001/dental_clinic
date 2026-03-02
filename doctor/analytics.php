<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

try {
    $doctor_id = $_SESSION['user_id'];
    
    // إحصائيات الأشهر الـ 12 الماضية
    $monthlyStats = [];
    for ($i = 11; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $monthName = date('F Y', strtotime("-$i months"));
        
        // علاجات الشهر
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, SUM(COALESCE(cost, 0)) as revenue 
            FROM treatments 
            WHERE doctor_id = ? AND DATE_FORMAT(COALESCE(treatment_date, created_at), '%Y-%m') = ?
        ");
        $stmt->execute([$doctor_id, $month]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $monthlyStats[] = [
            'month' => $monthName,
            'treatments' => $result['count'] ?? 0,
            'revenue' => $result['revenue'] ?? 0
        ];
    }
    
    // إحصائيات أنواع العلاجات
    $stmt = $pdo->prepare("
        SELECT treatment_type, COUNT(*) as count, SUM(COALESCE(cost, 0)) as revenue
        FROM treatments 
        WHERE doctor_id = ? 
        GROUP BY treatment_type 
        ORDER BY count DESC
    ");
    $stmt->execute([$doctor_id]);
    $treatmentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات المرضى حسب العمر
    $stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN p.age < 18 THEN 'أقل من 18'
                WHEN p.age BETWEEN 18 AND 30 THEN '18-30'
                WHEN p.age BETWEEN 31 AND 50 THEN '31-50'
                WHEN p.age > 50 THEN 'أكثر من 50'
                ELSE 'غير محدد'
            END as age_group,
            COUNT(DISTINCT t.patient_id) as patient_count
        FROM treatments t
        JOIN patients p ON t.patient_id = p.id
        WHERE t.doctor_id = ?
        GROUP BY age_group
        ORDER BY patient_count DESC
    ");
    $stmt->execute([$doctor_id]);
    $ageGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الجنس
    $stmt = $pdo->prepare("
        SELECT p.gender, COUNT(DISTINCT t.patient_id) as patient_count
        FROM treatments t
        JOIN patients p ON t.patient_id = p.id
        WHERE t.doctor_id = ?
        GROUP BY p.gender
    ");
    $stmt->execute([$doctor_id]);
    $genderStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // أفضل أيام الأسبوع
    $stmt = $pdo->prepare("
        SELECT DAYNAME(COALESCE(treatment_date, created_at)) as day_name, COUNT(*) as count
        FROM treatments 
        WHERE doctor_id = ?
        GROUP BY DAYOFWEEK(COALESCE(treatment_date, created_at)), DAYNAME(COALESCE(treatment_date, created_at))
        ORDER BY count DESC
    ");
    $stmt->execute([$doctor_id]);
    $weekDays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الساعات
    $stmt = $pdo->prepare("
        SELECT HOUR(COALESCE(treatment_date, created_at)) as hour, COUNT(*) as count
        FROM treatments 
        WHERE doctor_id = ?
        GROUP BY HOUR(COALESCE(treatment_date, created_at))
        ORDER BY hour
    ");
    $stmt->execute([$doctor_id]);
    $hourlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
}

// Header configuration
$pageTitle = 'التحليلات والإحصائيات';
$pageIcon = 'fas fa-analytics';
$pageSubtitle = 'تحليلات مفصلة ومؤشرات الأداء';
$currentPage = 'analytics';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hover-scale:hover { transform: scale(1.02); transition: transform 0.2s; }
        .chart-container { position: relative; height: 300px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .revenue-card { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .treatment-card { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .patient-card { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="stat-card rounded-lg shadow-lg p-6 text-white hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">إجمالي العلاجات</p>
                        <p class="text-3xl font-bold"><?= array_sum(array_column($monthlyStats, 'treatments')) ?></p>
                    </div>
                    <i class="fas fa-tooth text-3xl opacity-80"></i>
                </div>
            </div>

            <div class="revenue-card rounded-lg shadow-lg p-6 text-white hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">إجمالي الإيرادات</p>
                        <p class="text-2xl font-bold"><?= number_format(array_sum(array_column($monthlyStats, 'revenue')), 2) ?> ليرة سورية</p>
                    </div>
                    <i class="fas fa-chart-line text-3xl opacity-80"></i>
                </div>
            </div>

            <div class="treatment-card rounded-lg shadow-lg p-6 text-white hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">أنواع العلاجات</p>
                        <p class="text-3xl font-bold"><?= count($treatmentTypes) ?></p>
                    </div>
                    <i class="fas fa-list text-3xl opacity-80"></i>
                </div>
            </div>

            <div class="patient-card rounded-lg shadow-lg p-6 text-white hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">إجمالي المرضى</p>
                        <p class="text-3xl font-bold"><?= array_sum(array_column($ageGroups, 'patient_count')) ?></p>
                    </div>
                    <i class="fas fa-users text-3xl opacity-80"></i>
                </div>
            </div>
        </div>

        <!-- Monthly Revenue Chart -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-area text-blue-500 ml-2"></i>
                الإيرادات الشهرية
            </h3>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Treatment Types and Age Groups -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Treatment Types -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-tooth text-green-500 ml-2"></i>
                    أنواع العلاجات
                </h3>
                <div class="chart-container">
                    <canvas id="treatmentTypesChart"></canvas>
                </div>
            </div>

            <!-- Age Groups -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-users text-purple-500 ml-2"></i>
                    توزيع المرضى حسب العمر
                </h3>
                <div class="chart-container">
                    <canvas id="ageGroupsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Weekly and Hourly Stats -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Weekly Stats -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-calendar-week text-orange-500 ml-2"></i>
                    توزيع العلاجات أسبوعياً
                </h3>
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <!-- Hourly Stats -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-clock text-indigo-500 ml-2"></i>
                    توزيع العلاجات بالساعة
                </h3>
                <div class="chart-container">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Treatment Types Table -->
        <div class="bg-white rounded-lg shadow-lg p-6 mt-8 fade-in">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-table text-gray-500 ml-2"></i>
                تفاصيل أنواع العلاجات
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع العلاج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عدد الحالات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي الإيرادات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">متوسط التكلفة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($treatmentTypes as $type): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($type['treatment_type'] ?: 'غير محدد') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= $type['count'] ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= number_format($type['revenue'], 2) ?> ليرة سورية
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= number_format($type['revenue'] / $type['count'], 2) ?> ليرة سورية
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Monthly Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueChart = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($monthlyStats, 'month')) ?>,
                datasets: [{
                    label: 'الإيرادات (ليرة سورية)',
                    data: <?= json_encode(array_column($monthlyStats, 'revenue')) ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Treatment Types Chart
        const treatmentCtx = document.getElementById('treatmentTypesChart').getContext('2d');
        const treatmentChart = new Chart(treatmentCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($treatmentTypes, 'treatment_type')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($treatmentTypes, 'count')) ?>,
                    backgroundColor: [
                        '#ef4444', '#f97316', '#eab308', '#22c55e',
                        '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });

        // Age Groups Chart
        const ageCtx = document.getElementById('ageGroupsChart').getContext('2d');
        const ageChart = new Chart(ageCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($ageGroups, 'age_group')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($ageGroups, 'patient_count')) ?>,
                    backgroundColor: ['#8b5cf6', '#06b6d4', '#22c55e', '#eab308']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });

        // Weekly Chart
        const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
        const weeklyChart = new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($weekDays, 'day_name')) ?>,
                datasets: [{
                    label: 'عدد العلاجات',
                    data: <?= json_encode(array_column($weekDays, 'count')) ?>,
                    backgroundColor: 'rgba(249, 115, 22, 0.8)',
                    borderColor: 'rgb(249, 115, 22)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Hourly Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const hourlyChart = new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($h) => $h['hour'] . ':00', $hourlyStats)) ?>,
                datasets: [{
                    label: 'عدد العلاجات',
                    data: <?= json_encode(array_column($hourlyStats, 'count')) ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>