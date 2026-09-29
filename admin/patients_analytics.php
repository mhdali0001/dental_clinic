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

// فلترة تحليلات المرضى
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر الحالي
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي
$doctor_id = $_GET['doctor_id'] ?? 'all';
$age_group = $_GET['age_group'] ?? 'all';
$gender = $_GET['gender'] ?? 'all';

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
    $where_conditions[] = "DATE(p.registration_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(p.registration_date) <= ?";
    $params[] = $date_to;
}

if ($doctor_id !== 'all') {
    $where_conditions[] = "p.created_by = ?";
    $params[] = $doctor_id;
}

if ($gender !== 'all') {
    $where_conditions[] = "p.gender = ?";
    $params[] = $gender;
}

// إضافة شرط العمر
if ($age_group !== 'all') {
    switch ($age_group) {
        case 'child': // 0-12
            $where_conditions[] = "p.age BETWEEN 0 AND 12";
            break;
        case 'teen': // 13-19
            $where_conditions[] = "p.age BETWEEN 13 AND 19";
            break;
        case 'adult': // 20-59
            $where_conditions[] = "p.age BETWEEN 20 AND 59";
            break;
        case 'senior': // 60+
            $where_conditions[] = "p.age >= 60";
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// جلب البيانات التحليلية للمرضى
$patient_analytics = [];

try {
    // إحصائيات عامة للمرضى
    $patient_analytics['overview'] = $pdo->prepare("
        SELECT
            COUNT(p.id) as total_patients,
            COUNT(CASE WHEN p.gender = 'male' THEN 1 END) as male_count,
            COUNT(CASE WHEN p.gender = 'female' THEN 1 END) as female_count,
            COALESCE(AVG(p.age), 0) as avg_age,
            COUNT(CASE WHEN p.age BETWEEN 0 AND 12 THEN 1 END) as children,
            COUNT(CASE WHEN p.age BETWEEN 13 AND 19 THEN 1 END) as teens,
            COUNT(CASE WHEN p.age BETWEEN 20 AND 59 THEN 1 END) as adults,
            COUNT(CASE WHEN p.age >= 60 THEN 1 END) as seniors,
            COUNT(DISTINCT p.created_by) as registered_by_count
        FROM patients p
        WHERE {$where_clause}
    ");
    $patient_analytics['overview']->execute($params);
    $patient_analytics['overview'] = $patient_analytics['overview']->fetch(PDO::FETCH_ASSOC);

    // إحصائيات المرضى حسب الطبيب
    $patient_analytics['by_doctor'] = $pdo->prepare("
        SELECT
            u.full_name as doctor_name,
            u.id as doctor_id,
            COUNT(p.id) as patients_count,
            COUNT(CASE WHEN p.gender = 'male' THEN 1 END) as male_patients,
            COUNT(CASE WHEN p.gender = 'female' THEN 1 END) as female_patients,
            COALESCE(AVG(p.age), 0) as avg_age,
            COUNT(DISTINCT t.id) as total_treatments,
            COALESCE(SUM(t.cost), 0) as total_revenue
        FROM patients p
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN treatments t ON p.id = t.patient_id
        WHERE {$where_clause}
        GROUP BY u.id, u.full_name
        ORDER BY patients_count DESC
    ");
    $patient_analytics['by_doctor']->execute($params);
    $patient_analytics['by_doctor'] = $patient_analytics['by_doctor']->fetchAll(PDO::FETCH_ASSOC);

    // تحليل الحالات الطبية والحساسيات
    $patient_analytics['medical_conditions'] = $pdo->prepare("
        SELECT
            p.medical_history,
            p.allergies,
            p.blood_type,
            COUNT(p.id) as count
        FROM patients p
        WHERE {$where_clause} AND (p.medical_history IS NOT NULL AND p.medical_history != '')
        GROUP BY p.medical_history, p.allergies, p.blood_type
        ORDER BY count DESC
        LIMIT 20
    ");
    $patient_analytics['medical_conditions']->execute($params);
    $patient_analytics['medical_conditions'] = $patient_analytics['medical_conditions']->fetchAll(PDO::FETCH_ASSOC);

    // توزيع فصائل الدم
    $patient_analytics['blood_types'] = $pdo->prepare("
        SELECT
            p.blood_type,
            COUNT(p.id) as count,
            (COUNT(p.id) * 100.0 / (SELECT COUNT(*) FROM patients WHERE blood_type IS NOT NULL AND blood_type != '')) as percentage
        FROM patients p
        WHERE {$where_clause} AND p.blood_type IS NOT NULL AND p.blood_type != ''
        GROUP BY p.blood_type
        ORDER BY count DESC
    ");
    $patient_analytics['blood_types']->execute($params);
    $patient_analytics['blood_types'] = $patient_analytics['blood_types']->fetchAll(PDO::FETCH_ASSOC);

    // تحليل أنشطة المرضى (العلاجات والمواعيد)
    $patient_analytics['patient_activity'] = $pdo->prepare("
        SELECT
            p.id as patient_id,
            p.name as patient_name,
            p.phone,
            p.age,
            p.gender,
            p.registration_date,
            COUNT(DISTINCT t.id) as treatments_count,
            COUNT(DISTINCT a.id) as appointments_count,
            COALESCE(SUM(t.cost), 0) as total_spent,
            COALESCE(SUM(pay.amount), 0) as total_paid,
            MAX(t.treatment_date) as last_treatment_date,
            MAX(a.appointment_date) as last_appointment_date
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id
        LEFT JOIN appointments a ON p.id = a.patient_id
        LEFT JOIN payments pay ON p.id = pay.patient_id
        WHERE {$where_clause}
        GROUP BY p.id, p.name, p.phone, p.age, p.gender, p.registration_date
        ORDER BY treatments_count DESC, total_spent DESC
        LIMIT 50
    ");
    $patient_analytics['patient_activity']->execute($params);
    $patient_analytics['patient_activity'] = $patient_analytics['patient_activity']->fetchAll(PDO::FETCH_ASSOC);

    // تحليل تطور التسجيل الشهري
    $patient_analytics['monthly_registration'] = $pdo->prepare("
        SELECT
            DATE_FORMAT(p.registration_date, '%Y-%m') as month,
            COUNT(p.id) as new_patients,
            COUNT(CASE WHEN p.gender = 'male' THEN 1 END) as male_registrations,
            COUNT(CASE WHEN p.gender = 'female' THEN 1 END) as female_registrations
        FROM patients p
        WHERE DATE(p.registration_date) BETWEEN ? AND ?
        " . ($doctor_id !== 'all' ? "AND p.created_by = ?" : "") . "
        " . ($gender !== 'all' ? "AND p.gender = ?" : "") . "
        GROUP BY DATE_FORMAT(p.registration_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");

    $monthly_params = [$date_from, $date_to];
    if ($doctor_id !== 'all') $monthly_params[] = $doctor_id;
    if ($gender !== 'all') $monthly_params[] = $gender;

    $patient_analytics['monthly_registration']->execute($monthly_params);
    $patient_analytics['monthly_registration'] = $patient_analytics['monthly_registration']->fetchAll(PDO::FETCH_ASSOC);

    // أفضل المرضى (حسب الإنفاق)
    $patient_analytics['top_patients'] = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.phone,
            p.age,
            p.gender,
            COUNT(DISTINCT t.id) as treatments_count,
            COALESCE(SUM(t.cost), 0) as total_spent,
            COALESCE(SUM(pay.amount), 0) as total_paid,
            (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(pay.amount), 0)) as balance
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id
        LEFT JOIN payments pay ON p.id = pay.patient_id
        WHERE {$where_clause}
        GROUP BY p.id, p.name, p.phone, p.age, p.gender
        HAVING total_spent > 0
        ORDER BY total_spent DESC
        LIMIT 20
    ");
    $patient_analytics['top_patients']->execute($params);
    $patient_analytics['top_patients'] = $patient_analytics['top_patients']->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "خطأ في جلب البيانات التحليلية: " . $e->getMessage();
    $patient_analytics = [];
}

// تحضير البيانات للرسوم البيانية
$chart_data = [
    'age_groups' => ['أطفال (0-12)', 'مراهقين (13-19)', 'بالغين (20-59)', 'كبار السن (60+)'],
    'age_counts' => [
        $patient_analytics['overview']['children'] ?? 0,
        $patient_analytics['overview']['teens'] ?? 0,
        $patient_analytics['overview']['adults'] ?? 0,
        $patient_analytics['overview']['seniors'] ?? 0
    ],
    'gender_labels' => ['ذكور', 'إناث'],
    'gender_counts' => [
        $patient_analytics['overview']['male_count'] ?? 0,
        $patient_analytics['overview']['female_count'] ?? 0
    ],
    'monthly_labels' => array_reverse(array_column($patient_analytics['monthly_registration'] ?? [], 'month')),
    'monthly_counts' => array_reverse(array_column($patient_analytics['monthly_registration'] ?? [], 'new_patients'))
];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحليلات المرضى - إدارة العيادة</title>
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
        .analytics-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .analytics-card:hover {
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
    <?php
    $pageTitle = 'إحصائيات المرضى';
    $pageIcon = 'fas fa-users';
    $pageSubtitle = 'تحليل بيانات المرضى';
    include 'includes/admin_header.php';
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">تحليلات المرضى</h1>
            <p class="mt-2 text-gray-600">إحصائيات شاملة حول المرضى والديموغرافيا</p>
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
                فلترة تحليلات المرضى
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
                    <label class="block text-gray-700 font-medium mb-2">الفئة العمرية</label>
                    <select name="age_group" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?= $age_group === 'all' ? 'selected' : '' ?>>جميع الأعمار</option>
                        <option value="child" <?= $age_group === 'child' ? 'selected' : '' ?>>أطفال (0-12)</option>
                        <option value="teen" <?= $age_group === 'teen' ? 'selected' : '' ?>>مراهقين (13-19)</option>
                        <option value="adult" <?= $age_group === 'adult' ? 'selected' : '' ?>>بالغين (20-59)</option>
                        <option value="senior" <?= $age_group === 'senior' ? 'selected' : '' ?>>كبار السن (60+)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">الجنس</label>
                    <select name="gender" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?= $gender === 'all' ? 'selected' : '' ?>>الجميع</option>
                        <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>ذكور</option>
                        <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>إناث</option>
                    </select>
                </div>

                <div class="md:col-span-5 flex justify-end">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                        <i class="fas fa-chart-bar ml-1"></i>
                        تطبيق الفلترة
                    </button>
                </div>
            </form>
        </div>

        <!-- Overview Statistics -->
        <?php if (!empty($patient_analytics['overview'])): ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                    <div class="flex items-center">
                        <div class="bg-blue-100 p-3 rounded-full">
                            <i class="fas fa-users text-blue-600 text-2xl"></i>
                        </div>
                        <div class="mr-4 flex-1">
                            <p class="text-sm font-medium text-gray-600">إجمالي المرضى</p>
                            <p class="text-3xl font-bold text-blue-600"><?= $patient_analytics['overview']['total_patients'] ?></p>
                        </div>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                    <div class="flex items-center">
                        <div class="bg-green-100 p-3 rounded-full">
                            <i class="fas fa-male text-green-600 text-2xl"></i>
                        </div>
                        <div class="mr-4 flex-1">
                            <p class="text-sm font-medium text-gray-600">ذكور</p>
                            <p class="text-3xl font-bold text-green-600"><?= $patient_analytics['overview']['male_count'] ?></p>
                            <p class="text-xs text-gray-500"><?= $patient_analytics['overview']['total_patients'] > 0 ? round(($patient_analytics['overview']['male_count'] / $patient_analytics['overview']['total_patients']) * 100, 1) : 0 ?>%</p>
                        </div>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-pink-500">
                    <div class="flex items-center">
                        <div class="bg-pink-100 p-3 rounded-full">
                            <i class="fas fa-female text-pink-600 text-2xl"></i>
                        </div>
                        <div class="mr-4 flex-1">
                            <p class="text-sm font-medium text-gray-600">إناث</p>
                            <p class="text-3xl font-bold text-pink-600"><?= $patient_analytics['overview']['female_count'] ?></p>
                            <p class="text-xs text-gray-500"><?= $patient_analytics['overview']['total_patients'] > 0 ? round(($patient_analytics['overview']['female_count'] / $patient_analytics['overview']['total_patients']) * 100, 1) : 0 ?>%</p>
                        </div>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="bg-purple-100 p-3 rounded-full">
                            <i class="fas fa-birthday-cake text-purple-600 text-2xl"></i>
                        </div>
                        <div class="mr-4 flex-1">
                            <p class="text-sm font-medium text-gray-600">متوسط العمر</p>
                            <p class="text-3xl font-bold text-purple-600"><?= number_format($patient_analytics['overview']['avg_age'], 1) ?></p>
                            <p class="text-xs text-gray-500">سنة</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Age Groups Distribution -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500">
                    <div class="text-center">
                        <i class="fas fa-child text-yellow-600 text-3xl mb-3"></i>
                        <p class="text-2xl font-bold text-yellow-600"><?= $patient_analytics['overview']['children'] ?></p>
                        <p class="text-sm text-gray-600">أطفال (0-12)</p>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-orange-500">
                    <div class="text-center">
                        <i class="fas fa-running text-orange-600 text-3xl mb-3"></i>
                        <p class="text-2xl font-bold text-orange-600"><?= $patient_analytics['overview']['teens'] ?></p>
                        <p class="text-sm text-gray-600">مراهقين (13-19)</p>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-indigo-500">
                    <div class="text-center">
                        <i class="fas fa-user text-indigo-600 text-3xl mb-3"></i>
                        <p class="text-2xl font-bold text-indigo-600"><?= $patient_analytics['overview']['adults'] ?></p>
                        <p class="text-sm text-gray-600">بالغين (20-59)</p>
                    </div>
                </div>

                <div class="analytics-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-gray-500">
                    <div class="text-center">
                        <i class="fas fa-user-alt text-gray-600 text-3xl mb-3"></i>
                        <p class="text-2xl font-bold text-gray-600"><?= $patient_analytics['overview']['seniors'] ?></p>
                        <p class="text-sm text-gray-600">كبار السن (60+)</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Age Distribution Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie text-blue-600 ml-2"></i>
                    توزيع الفئات العمرية
                </h3>
                <div class="chart-container">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>

            <!-- Gender Distribution Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-donut text-green-600 ml-2"></i>
                    توزيع الجنس
                </h3>
                <div class="chart-container">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>

            <!-- Monthly Registration Chart -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-line text-purple-600 ml-2"></i>
                    التسجيل الشهري
                </h3>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Patients by Doctor -->
        <?php if (!empty($patient_analytics['by_doctor'])): ?>
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-user-md text-blue-600 ml-2"></i>
                    المرضى حسب الطبيب
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">الطبيب</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد المرضى</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ذكور</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">إناث</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">متوسط العمر</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">العلاجات</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإيرادات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($patient_analytics['by_doctor'] as $doctor): ?>
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
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-bold text-blue-600"><?= $doctor['patients_count'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= $doctor['male_patients'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-pink-600"><?= $doctor['female_patients'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($doctor['avg_age'], 1) ?> سنة</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-purple-600"><?= $doctor['total_treatments'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($doctor['total_revenue']) ?> ل.س</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Top Patients and Blood Types -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Top Patients by Spending -->
            <?php if (!empty($patient_analytics['top_patients'])): ?>
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-crown text-yellow-600 ml-2"></i>
                        أفضل المرضى (حسب الإنفاق)
                    </h3>
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach (array_slice($patient_analytics['top_patients'], 0, 10) as $index => $patient): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-gradient-to-r from-yellow-400 to-yellow-600 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="mr-3">
                                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($patient['name']) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($patient['phone']) ?> • <?= $patient['age'] ?> سنة</p>
                                    </div>
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-bold text-green-600"><?= number_format($patient['total_spent']) ?> ل.س</p>
                                    <p class="text-xs text-gray-500"><?= $patient['treatments_count'] ?> علاج</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Blood Types Distribution -->
            <?php if (!empty($patient_analytics['blood_types'])): ?>
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-tint text-red-600 ml-2"></i>
                        توزيع فصائل الدم
                    </h3>
                    <div class="space-y-3">
                        <?php foreach ($patient_analytics['blood_types'] as $blood_type): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center text-white font-bold text-sm">
                                        <?= htmlspecialchars($blood_type['blood_type']) ?>
                                    </div>
                                    <div class="mr-3">
                                        <p class="text-sm font-medium text-gray-900">فصيلة <?= htmlspecialchars($blood_type['blood_type']) ?></p>
                                    </div>
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-bold text-red-600"><?= $blood_type['count'] ?> مريض</p>
                                    <p class="text-xs text-gray-500"><?= number_format($blood_type['percentage'], 1) ?>%</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Patient Activity Table -->
        <?php if (!empty($patient_analytics['patient_activity'])): ?>
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-activity text-blue-600 ml-2"></i>
                    نشاط المرضى
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المريض</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">العمر/الجنس</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">تاريخ التسجيل</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">العلاجات</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المواعيد</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ المستحق</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">المدفوع</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">آخر زيارة</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach (array_slice($patient_analytics['patient_activity'], 0, 20) as $patient): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($patient['patient_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($patient['phone']) ?></p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= $patient['age'] ?> سنة
                                        <br>
                                        <span class="text-xs <?= $patient['gender'] === 'male' ? 'text-blue-600' : 'text-pink-600' ?>">
                                            <?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= date('Y/m/d', strtotime($patient['registration_date'])) ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-purple-600 font-medium"><?= $patient['treatments_count'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600"><?= $patient['appointments_count'] ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($patient['total_spent']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600"><?= number_format($patient['total_paid']) ?> ل.س</td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $patient['last_treatment_date'] ? date('Y/m/d', strtotime($patient['last_treatment_date'])) : 'لا يوجد' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Age Distribution Chart
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        new Chart(ageCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chart_data['age_groups']) ?>,
                datasets: [{
                    data: <?= json_encode($chart_data['age_counts']) ?>,
                    backgroundColor: ['#fbbf24', '#f97316', '#6366f1', '#6b7280']
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

        // Gender Distribution Chart
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode($chart_data['gender_labels']) ?>,
                datasets: [{
                    data: <?= json_encode($chart_data['gender_counts']) ?>,
                    backgroundColor: ['#10b981', '#ec4899']
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

        // Monthly Registration Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_data['monthly_labels']) ?>,
                datasets: [{
                    label: 'مرضى جدد',
                    data: <?= json_encode($chart_data['monthly_counts']) ?>,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
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
    </script>
</body>
</html>