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

// جلب الإحصائيات العامة
try {
    // إحصائيات المرضى
    $patient_stats = [];
    $patient_stats['total'] = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $patient_stats['new_today'] = $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(registration_date) = CURDATE()")->fetchColumn();
    $patient_stats['new_this_month'] = $pdo->query("SELECT COUNT(*) FROM patients WHERE MONTH(registration_date) = MONTH(CURDATE()) AND YEAR(registration_date) = YEAR(CURDATE())")->fetchColumn();

    // إحصائيات المواعيد
    $appointment_stats = [];
    $appointment_stats['total_today'] = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
    $appointment_stats['completed_today'] = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'completed'")->fetchColumn();
    $appointment_stats['pending_today'] = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('scheduled', 'confirmed')")->fetchColumn();
    $appointment_stats['cancelled_today'] = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'cancelled'")->fetchColumn();

    // إحصائيات العلاجات
    $treatment_stats = [];
    $treatment_stats['total'] = $pdo->query("SELECT COUNT(*) FROM treatments")->fetchColumn();
    $treatment_stats['today'] = $pdo->query("SELECT COUNT(*) FROM treatments WHERE DATE(treatment_date) = CURDATE()")->fetchColumn();
    $treatment_stats['this_month'] = $pdo->query("SELECT COUNT(*) FROM treatments WHERE MONTH(treatment_date) = MONTH(CURDATE()) AND YEAR(treatment_date) = YEAR(CURDATE())")->fetchColumn();

    // إحصائيات مالية
    $financial_stats = [];
    $financial_stats['total_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
    $financial_stats['today_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();
    $financial_stats['month_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn();
    $financial_stats['unpaid_amount'] = $pdo->query("SELECT COALESCE(SUM(t.cost), 0) - COALESCE(SUM(p.amount), 0) as unpaid FROM treatments t LEFT JOIN payments p ON t.patient_id = p.patient_id")->fetchColumn();

    // إحصائيات الأطباء
    $doctor_stats = [];
    $doctor_stats['total'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor' AND is_active = 1")->fetchColumn();
    $doctor_stats_details = $pdo->query("
        SELECT u.full_name, u.id,
               COUNT(DISTINCT a.id) as appointments_today,
               COUNT(DISTINCT t.id) as treatments_today,
               COALESCE(SUM(p.amount), 0) as revenue_today
        FROM users u
        LEFT JOIN appointments a ON u.id = a.created_by AND DATE(a.appointment_date) = CURDATE()
        LEFT JOIN treatments t ON u.id = t.doctor_id AND DATE(t.treatment_date) = CURDATE()
        LEFT JOIN payments p ON t.patient_id = p.patient_id AND DATE(p.payment_date) = CURDATE()
        WHERE u.role = 'doctor' AND u.is_active = 1
        GROUP BY u.id, u.full_name
        ORDER BY appointments_today DESC, treatments_today DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // بيانات للرسوم البيانية - آخر 7 أيام
    $chart_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_data['dates'][] = date('m/d', strtotime($date));

        $patients_count = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(registration_date) = ?");
        $patients_count->execute([$date]);
        $chart_data['patients'][] = $patients_count->fetchColumn();

        $appointments_count = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ?");
        $appointments_count->execute([$date]);
        $chart_data['appointments'][] = $appointments_count->fetchColumn();

        $revenue = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = ?");
        $revenue->execute([$date]);
        $chart_data['revenue'][] = $revenue->fetchColumn();
    }

    // قائمة الانتظار الحالية
    $waiting_list = $pdo->query("
        SELECT w.*, p.name as patient_name, p.phone, a.treatment_type, u.full_name as doctor_name
        FROM waiting_list w
        JOIN patients p ON w.patient_id = p.id
        LEFT JOIN appointments a ON w.appointment_id = a.id
        LEFT JOIN users u ON a.created_by = u.id
        WHERE w.status = 'waiting'
        ORDER BY w.priority DESC, w.arrival_time ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // أحدث الأنشطة
    $recent_activities = $pdo->query("
        SELECT al.*, u.full_name as user_name
        FROM activity_log al
        JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "خطأ في جلب البيانات: " . $e->getMessage();
    // Initialize empty arrays to prevent errors
    $patient_stats = $appointment_stats = $treatment_stats = $financial_stats = $doctor_stats = [];
    $doctor_stats_details = $waiting_list = $recent_activities = [];
    $chart_data = ['dates' => [], 'patients' => [], 'appointments' => [], 'revenue' => []];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم الإدارة - عيادة الأسنان</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .activity-item {
            border-right: 3px solid transparent;
            transition: all 0.2s ease;
        }
        .activity-item:hover {
            border-right-color: #3b82f6;
            background-color: #f8fafc;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/admin_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">لوحة تحكم الإدارة</h1>
            <p class="mt-2 text-gray-600">نظرة شاملة على أداء العيادة والإحصائيات</p>
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

        <!-- Main Statistics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- المرضى -->
            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                    </div>
                    <div class="mr-4 flex-1">
                        <p class="text-sm font-medium text-gray-600">إجمالي المرضى</p>
                        <p class="text-3xl font-bold text-blue-600"><?= $patient_stats['total'] ?? 0 ?></p>
                        <p class="text-xs text-gray-500">جديد اليوم: <?= $patient_stats['new_today'] ?? 0 ?></p>
                    </div>
                </div>
            </div>

            <!-- المواعيد -->
            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center">
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-calendar-check text-green-600 text-2xl"></i>
                    </div>
                    <div class="mr-4 flex-1">
                        <p class="text-sm font-medium text-gray-600">مواعيد اليوم</p>
                        <p class="text-3xl font-bold text-green-600"><?= $appointment_stats['total_today'] ?? 0 ?></p>
                        <p class="text-xs text-gray-500">مكتمل: <?= $appointment_stats['completed_today'] ?? 0 ?></p>
                    </div>
                </div>
            </div>

            <!-- العلاجات -->
            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500">
                <div class="flex items-center">
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i class="fas fa-medical-kit text-purple-600 text-2xl"></i>
                    </div>
                    <div class="mr-4 flex-1">
                        <p class="text-sm font-medium text-gray-600">إجمالي العلاجات</p>
                        <p class="text-3xl font-bold text-purple-600"><?= $treatment_stats['total'] ?? 0 ?></p>
                        <p class="text-xs text-gray-500">اليوم: <?= $treatment_stats['today'] ?? 0 ?></p>
                    </div>
                </div>
            </div>

            <!-- الإيرادات -->
            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-dollar-sign text-yellow-600 text-2xl"></i>
                    </div>
                    <div class="mr-4 flex-1">
                        <p class="text-sm font-medium text-gray-600">إيرادات اليوم</p>
                        <p class="text-2xl font-bold text-yellow-600"><?= number_format($financial_stats['today_revenue'] ?? 0) ?></p>
                        <p class="text-xs text-gray-500">ليرة سورية</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Analytics Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Chart - Weekly Statistics -->
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-indigo-500">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center">
                        <div class="bg-indigo-100 p-3 rounded-full ml-3">
                            <i class="fas fa-chart-line text-indigo-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">إحصائيات الأسبوع الماضي</h3>
                            <p class="text-sm text-gray-500">تحليل الأداء خلال آخر 7 أيام</p>
                        </div>
                    </div>
                    <div class="flex space-x-1 space-x-reverse">
                        <button onclick="toggleChartType()" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-3 py-1 rounded-lg text-sm transition-all duration-200">
                            <i class="fas fa-exchange-alt ml-1"></i>
                            تبديل العرض
                        </button>
                    </div>
                </div>

                <!-- Chart Summary Cards -->
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 p-3 rounded-lg border border-blue-200">
                        <div class="flex items-center">
                            <i class="fas fa-users text-blue-600 text-lg ml-2"></i>
                            <div>
                                <p class="text-xs text-blue-600 font-medium">مرضى جدد</p>
                                <p class="text-lg font-bold text-blue-700"><?= array_sum($chart_data['patients']) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-green-50 to-green-100 p-3 rounded-lg border border-green-200">
                        <div class="flex items-center">
                            <i class="fas fa-calendar-check text-green-600 text-lg ml-2"></i>
                            <div>
                                <p class="text-xs text-green-600 font-medium">المواعيد</p>
                                <p class="text-lg font-bold text-green-700"><?= array_sum($chart_data['appointments']) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-amber-50 to-amber-100 p-3 rounded-lg border border-amber-200">
                        <div class="flex items-center">
                            <i class="fas fa-dollar-sign text-amber-600 text-lg ml-2"></i>
                            <div>
                                <p class="text-xs text-amber-600 font-medium">الإيرادات</p>
                                <p class="text-lg font-bold text-amber-700"><?= number_format(array_sum($chart_data['revenue'])/1000, 1) ?>ك</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <canvas id="weeklyChart" class="w-full" style="height: 300px;"></canvas>
                    <div class="absolute top-2 left-2">
                        <div class="bg-white/90 backdrop-blur-sm rounded-lg px-2 py-1 shadow-sm">
                            <p class="text-xs text-gray-600">آخر تحديث: <?= date('H:i') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Doctor Performance Today -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">أداء الأطباء اليوم</h3>
                <div class="space-y-3 max-h-80 overflow-y-auto">
                    <?php foreach ($doctor_stats_details as $doctor): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user-md text-white"></i>
                                </div>
                                <div class="mr-3">
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($doctor['full_name']) ?></p>
                                    <p class="text-sm text-gray-500">مواعيد: <?= $doctor['appointments_today'] ?> | علاجات: <?= $doctor['treatments_today'] ?></p>
                                </div>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-green-600"><?= number_format($doctor['revenue_today']) ?> ل.س</p>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($doctor_stats_details)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-user-md text-4xl mb-3"></i>
                            <p>لا توجد بيانات أطباء اليوم</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bottom Row - Waiting List and Recent Activities -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Current Waiting List -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-clock text-orange-600 ml-2"></i>
                    قائمة الانتظار الحالية
                </h3>
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php foreach ($waiting_list as $waiting): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-orange-400 rounded-full ml-3"></div>
                                <div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($waiting['patient_name']) ?></p>
                                    <p class="text-sm text-gray-500"><?= htmlspecialchars($waiting['treatment_type'] ?? 'غير محدد') ?></p>
                                </div>
                            </div>
                            <div class="text-left">
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($waiting['doctor_name'] ?? 'غير محدد') ?></p>
                                <p class="text-xs text-gray-400"><?= date('H:i', strtotime($waiting['arrival_time'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($waiting_list)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
                            <p>لا توجد مرضى في قائمة الانتظار</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-history text-blue-600 ml-2"></i>
                    آخر الأنشطة
                </h3>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item p-3 rounded-lg">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-circle text-blue-400 text-xs mt-2"></i>
                                </div>
                                <div class="mr-3 flex-1">
                                    <p class="text-sm text-gray-900"><?= htmlspecialchars($activity['description']) ?></p>
                                    <div class="flex items-center mt-1">
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($activity['user_name']) ?></p>
                                        <span class="mx-2 text-gray-300">•</span>
                                        <p class="text-xs text-gray-400"><?= date('H:i m/d', strtotime($activity['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-history text-4xl mb-3"></i>
                            <p>لا توجد أنشطة حديثة</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced Weekly Statistics Chart
        const ctx = document.getElementById('weeklyChart').getContext('2d');
        let currentChartType = 'line';

        const chartData = {
            labels: <?= json_encode($chart_data['dates']) ?>,
            datasets: [
                {
                    label: 'مرضى جدد',
                    data: <?= json_encode($chart_data['patients']) ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgb(59, 130, 246)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8
                },
                {
                    label: 'المواعيد',
                    data: <?= json_encode($chart_data['appointments']) ?>,
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgb(16, 185, 129)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8
                },
                {
                    label: 'الإيرادات (بالآلاف)',
                    data: <?= json_encode(array_map(function($x) { return round($x/1000, 1); }, $chart_data['revenue'])) ?>,
                    borderColor: 'rgb(245, 158, 11)',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgb(245, 158, 11)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8
                }
            ]
        };

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 20,
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    titleColor: '#374151',
                    bodyColor: '#6b7280',
                    borderColor: '#d1d5db',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: true,
                    callbacks: {
                        title: function(context) {
                            return 'يوم ' + context[0].label;
                        },
                        label: function(context) {
                            if (context.datasetIndex === 2) {
                                return context.dataset.label + ': ' + context.parsed.y + ' ألف ل.س';
                            }
                            return context.dataset.label + ': ' + context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(156, 163, 175, 0.1)',
                        borderColor: 'rgba(156, 163, 175, 0.2)'
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(156, 163, 175, 0.1)',
                        borderColor: 'rgba(156, 163, 175, 0.2)'
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 11
                        }
                    }
                }
            },
            elements: {
                line: {
                    borderWidth: 3
                }
            },
            animation: {
                duration: 2000,
                easing: 'easeInOutQuart'
            }
        };

        let weeklyChart = new Chart(ctx, {
            type: currentChartType,
            data: chartData,
            options: chartOptions
        });

        // Toggle chart type function
        function toggleChartType() {
            const types = ['line', 'bar', 'radar'];
            const currentIndex = types.indexOf(currentChartType);
            currentChartType = types[(currentIndex + 1) % types.length];

            // Update chart type with animation
            weeklyChart.destroy();

            // Adjust options for different chart types
            if (currentChartType === 'radar') {
                chartOptions.scales = {
                    r: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(156, 163, 175, 0.2)'
                        },
                        pointLabels: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            }
                        },
                        ticks: {
                            color: '#6b7280',
                            backdropColor: 'transparent'
                        }
                    }
                };
            } else {
                chartOptions.scales = {
                    x: {
                        grid: {
                            color: 'rgba(156, 163, 175, 0.1)',
                            borderColor: 'rgba(156, 163, 175, 0.2)'
                        },
                        ticks: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(156, 163, 175, 0.1)',
                            borderColor: 'rgba(156, 163, 175, 0.2)'
                        },
                        ticks: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            }
                        }
                    }
                };
            }

            weeklyChart = new Chart(ctx, {
                type: currentChartType,
                data: chartData,
                options: chartOptions
            });
        }

        // Add chart refresh functionality
        function refreshChart() {
            weeklyChart.update('active');
        }

        // Auto-refresh chart every 5 minutes
        setInterval(refreshChart, 300000);

        // Add smooth hover effects
        ctx.canvas.addEventListener('mousemove', function(e) {
            ctx.canvas.style.cursor = 'pointer';
        });

        ctx.canvas.addEventListener('mouseleave', function(e) {
            ctx.canvas.style.cursor = 'default';
        });
    </script>
</body>
</html>